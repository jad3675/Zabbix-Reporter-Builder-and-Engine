<?php declare(strict_types = 1);

namespace Modules\Reporter\Lib\Section;

use Modules\Reporter\Lib\Core\Context;
use Modules\Reporter\Lib\Core\Filters;
use Modules\Reporter\Lib\Core\TagFilter;

abstract class AbstractSection implements SectionInterface {

	public const SEVERITIES = [
		0 => 'Not classified',
		1 => 'Information',
		2 => 'Warning',
		3 => 'Average',
		4 => 'High',
		5 => 'Disaster'
	];

	public function description(): string {
		return '';
	}

	public function validateOptions(array $options): array {
		return [];
	}

	public function normalizeOptions(array $options): array {
		$out = [];

		foreach ($this->options() as $spec) {
			$name = $spec['name'];
			$default = $spec['default'] ?? null;
			$value = array_key_exists($name, $options) ? $options[$name] : $default;

			switch ($spec['type']) {
				case 'int':
					$value = is_numeric($value) ? (int) $value : (int) $default;
					$value = max((int) ($spec['min'] ?? PHP_INT_MIN), min((int) ($spec['max'] ?? PHP_INT_MAX), $value));
					break;

				case 'float':
					$value = is_numeric($value) ? (float) $value : (float) $default;
					$value = max((float) ($spec['min'] ?? -INF), min((float) ($spec['max'] ?? INF), $value));
					break;

				case 'bool':
					$value = is_string($value)
						? in_array(strtolower($value), ['1', 'true', 'yes', 'on'], true)
						: (bool) $value;
					break;

				case 'select':
					$choices = array_map('strval', array_keys($spec['choices'] ?? []));
					$value = in_array((string) $value, $choices, true) ? (string) $value : (string) $default;
					break;

				case 'textarea':
					$value = mb_substr(trim((string) ($value ?? '')), 0, (int) ($spec['maxlength'] ?? 4000));
					break;

				case 'tags':
					$value = is_string($value) ? TagFilter::parse($value) : TagFilter::normalize($value);
					break;

				default:
					$value = mb_substr(trim((string) ($value ?? '')), 0, (int) ($spec['maxlength'] ?? 255));
			}

			$out[$name] = $value;
		}

		return $out;
	}

	/**
	 * Options every section offers: an intro in the customer's own words, and exclusions
	 * for the noise that would otherwise dominate the numbers.
	 */
	protected static function commonOptions(bool $problems = true): array {
		$options = [
			['name' => 'intro', 'label' => 'Intro paragraph', 'type' => 'textarea', 'default' => '', 'maxlength' => 2000,
				'hint' => 'Shown under the heading, in place of the built-in description.'],
			['name' => 'exclude_hosts', 'label' => 'Skip devices', 'type' => 'text', 'default' => '',
				'hint' => 'Comma-separated name patterns, e.g. *-lab-*, test-*']
		];

		if ($problems) {
			$options[] = ['name' => 'exclude_problems', 'label' => 'Skip problems named', 'type' => 'text',
				'default' => '', 'hint' => 'Comma-separated patterns, e.g. *agent is not available*, *unreachable*'];
		}

		return $options;
	}

	/** Hosts in scope minus the ones this section skips. @return array<string, array> */
	protected function hosts(Context $ctx, array $o): array {
		$patterns = Filters::patterns((string) ($o['exclude_hosts'] ?? ''));

		if (!$patterns) {
			return $ctx->hosts;
		}

		return array_filter($ctx->hosts, static fn($h) => !Filters::matches($h['name'], $patterns));
	}

	/**
	 * Problems in the period after the section's filters: minimum severity, excluded
	 * problem names, and excluded devices (both from the list and from each problem's
	 * host list).
	 *
	 * @return array<string, array>
	 */
	protected function problems(Context $ctx, array $o, ?Context $from = null): array {
		$min = (int) ($o['min_severity'] ?? 0);
		$names = Filters::patterns((string) ($o['exclude_problems'] ?? ''));
		$allowed = array_flip(array_keys($this->hosts($ctx, $o)));
		$out = [];

		foreach (($from ?? $ctx)->problems() as $id => $p) {
			if ($p['severity'] < $min || ($names && Filters::matches($p['name'], $names))) {
				continue;
			}

			$hostids = array_values(array_filter($p['hostids'], static fn($h) => isset($allowed[$h])));

			if (!$hostids) {
				continue;
			}

			$p['hostids'] = $hostids;
			$out[$id] = $p;
		}

		return $out;
	}

	/** Notifications in the period, for the devices this section keeps. */
	protected function alerts(Context $ctx, array $o, ?Context $from = null): array {
		$allowed = array_flip(array_keys($this->hosts($ctx, $o)));
		$out = [];

		foreach (($from ?? $ctx)->alerts() as $id => $a) {
			$hostids = array_values(array_filter($a['hostids'], static fn($h) => isset($allowed[$h])));

			if ($hostids) {
				$a['hostids'] = $hostids;
				$out[$id] = $a;
			}
		}

		return $out;
	}

	/** "up 4" / "down 12" / "unchanged" against the previous period. */
	protected static function change(?int $now, ?int $before): string {
		if ($before === null || $now === null) {
			return '';
		}

		$delta = $now - $before;

		if ($delta === 0) {
			return 'unchanged from the previous period';
		}

		return sprintf('%s %s from the previous period', $delta > 0 ? 'up' : 'down', abs($delta));
	}

	/** Split a comma-separated wildcard pattern list ("icmpping, icmpping[*]"). */
	protected static function patterns(string $list): array {
		return array_values(array_filter(array_map('trim', explode(',', $list)), 'strlen'));
	}

	protected static function sevOption(string $name = 'min_severity', string $label = 'Minimum severity'): array {
		return ['name' => $name, 'label' => $label, 'type' => 'select', 'default' => '0',
			'choices' => array_map('strval', self::SEVERITIES)];
	}
}
