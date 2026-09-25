<?php declare(strict_types = 1);

namespace Modules\Reporter\Lib\Section\Builtin;

use Modules\Reporter\Lib\Core\Context;
use Modules\Reporter\Lib\Core\SectionResult;
use Modules\Reporter\Lib\Data\Problems;
use Modules\Reporter\Lib\Section\AbstractSection;

final class TopTriggers extends AbstractSection {

	public function type(): string {
		return 'top_triggers';
	}

	public function label(): string {
		return 'Most frequent problems';
	}

	public function description(): string {
		return 'The problems that fired most often in the period. Frequent short problems are usually thresholds worth tuning.';
	}

	public function options(): array {
		return array_merge([
			self::sevOption(),
			['name' => 'group_by', 'label' => 'Group by', 'type' => 'select', 'default' => 'trigger',
				'choices' => ['trigger' => 'Trigger, so each device is its own row',
					'name' => 'Problem name, across every device']],
			['name' => 'display_limit', 'label' => 'Problems shown', 'type' => 'int', 'default' => 20, 'min' => 5,
				'max' => 200]
		], self::commonOptions());
	}

	public function run(Context $ctx, array $o): SectionResult {
		$by_trigger = [];

		foreach ($this->problems($ctx, $o) as $p) {
			$key = $o['group_by'] === 'name' ? mb_strtolower($p['name']) : $p['objectid'];
			$t = &$by_trigger[$key];
			$t ??= ['name' => $p['name'], 'hosts' => [], 'severity' => $p['severity'], 'count' => 0, 'open_time' => 0,
				'longest' => 0, 'last' => 0];
			$open = Problems::openSeconds($p, $ctx->period->from, $ctx->period->till);
			$t['count']++;
			$t['open_time'] += $open;
			$t['longest'] = max($t['longest'], $open);

			if ($p['clock'] >= $t['last']) {
				$t['last'] = $p['clock'];
				$t['name'] = $p['name'];
				$t['severity'] = $p['severity'];
			}

			foreach ($p['hostids'] as $h) {
				$t['hosts'][$h] = true;
			}

			unset($t);
		}

		$rows = [];

		foreach ($by_trigger as $t) {
			$hosts = array_keys($t['hosts']);
			$rows[] = [
				'name' => $t['name'],
				'host' => count($hosts) === 1 ? $ctx->hostName((string) $hosts[0]) : sprintf('%d devices', count($hosts)),
				'severity' => $t['severity'],
				'count' => $t['count'],
				'open_time' => $t['open_time'],
				'longest' => $t['longest'],
				'last' => $t['last']
			];
		}

		usort($rows, static fn($a, $b) => [$b['count'], $b['open_time']] <=> [$a['count'], $a['open_time']]);

		return (new SectionResult())->table('Problems', [
			['key' => 'name', 'label' => 'Problem'],
			['key' => 'host', 'label' => 'Device'],
			['key' => 'severity', 'label' => 'Severity', 'format' => 'severity'],
			['key' => 'count', 'label' => 'Times raised', 'format' => 'int'],
			['key' => 'open_time', 'label' => 'Total time open', 'format' => 'duration'],
			['key' => 'longest', 'label' => 'Longest', 'format' => 'duration'],
			['key' => 'last', 'label' => 'Last raised', 'format' => 'datetime']
		], $rows, ['display_limit' => $o['display_limit'], 'sheet' => 'Most frequent problems']);
	}
}
