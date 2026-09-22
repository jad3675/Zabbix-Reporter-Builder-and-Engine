<?php declare(strict_types = 1);

namespace Modules\Reporter\Lib\Core;

use Modules\Reporter\Lib\Api\Guard;
use Modules\Reporter\Lib\Data;

/**
 * Everything a section may use: the budgeted API client, the period, the hosts in scope,
 * limits, and shared fetchers whose results are memoized for the whole report.
 */
final class Context {

	public Guard $api;
	public Period $period;
	public array $definition;
	/** @var array<string, array> */
	public array $hosts;
	public array $groups;

	private array $limits;
	private array $memo = [];
	private array $warnings = [];

	public function __construct(Guard $api, Period $period, array $definition, array $scope, array $limits) {
		$this->api = $api;
		$this->period = $period;
		$this->definition = $definition;
		$this->hosts = $scope['hosts'];
		$this->groups = $scope['groups'];
		$this->limits = $limits;
	}

	public function hostids(): array {
		return array_map('strval', array_keys($this->hosts));
	}

	/** @param string|int $hostid  numeric-string array keys come back as ints in PHP */
	public function hostName($hostid): string {
		return $this->hosts[(string) $hostid]['name'] ?? ('#'.$hostid);
	}

	/** @param string|int $hostid */
	public function hostTag($hostid, string $tag): string {
		$values = $this->hosts[(string) $hostid]['tags'][$tag] ?? [];

		return $values ? implode(', ', array_unique($values)) : '';
	}

	public function limit(string $key, int $default): int {
		return (int) ($this->limits[$key] ?? $default);
	}

	public function timezone(): string {
		return $this->period->timezone;
	}

	/** Compute once per report, share between sections. */
	public function memo(string $key, callable $fn) {
		if (!array_key_exists($key, $this->memo)) {
			$this->memo[$key] = $fn($this);
		}

		return $this->memo[$key];
	}

	/** @return array<string, array> problems raised in the period */
	public function problems(): array {
		return $this->memo('problems', static fn(Context $c) => Data\Problems::fetch($c));
	}

	/** @return array<string, array> notifications sent in the period */
	public function alerts(): array {
		return $this->memo('alerts', static fn(Context $c) => Data\Alerts::fetch($c));
	}

	public function items(array $selector): array {
		return $this->memo('items:'.sha1(json_encode($selector)), static fn(Context $c) => Data\Items::find($c, $selector));
	}

	public function trendSummary(array $itemids): array {
		sort($itemids);

		return $this->memo('tsum:'.sha1(implode(',', $itemids)), static fn(Context $c) => Data\Trends::summary($c, $itemids));
	}

	public function trendDaily(array $itemids): array {
		sort($itemids);

		return $this->memo('tday:'.sha1(implode(',', $itemids)), static fn(Context $c) => Data\Trends::daily($c, $itemids));
	}

	/** @param array $list  @return array[] */
	public function chunks(array $list, int $size): array {
		return $list ? array_chunk(array_values($list), max(1, $size)) : [];
	}

	/** A data-quality caveat that belongs to the section currently running. */
	public function warn(string $message): void {
		$this->warnings[] = $message;
	}

	public function takeWarnings(): array {
		$w = array_values(array_unique($this->warnings));
		$this->warnings = [];

		return $w;
	}
}
