<?php declare(strict_types = 1);

namespace Modules\Reporter\Lib\Section\Builtin;

use Modules\Reporter\Lib\Core\Context;
use Modules\Reporter\Lib\Core\SectionResult;
use Modules\Reporter\Lib\Section\AbstractSection;

final class Maintenance extends AbstractSection {

	private const RECURRENCE = [0 => 'One time', 2 => 'Daily', 3 => 'Weekly', 4 => 'Monthly'];

	public function type(): string {
		return 'maintenance';
	}

	public function label(): string {
		return 'Maintenance windows';
	}

	public function description(): string {
		return 'Maintenance active during the period on devices in scope. Explains quiet devices and suppressed alerts.';
	}

	public function options(): array {
		return array_merge([
			['name' => 'display_limit', 'label' => 'Rows', 'type' => 'int', 'default' => 50, 'min' => 5, 'max' => 500],
			['name' => 'name_filter', 'label' => 'Only windows named', 'type' => 'text', 'default' => '',
				'hint' => 'Comma-separated patterns. Leave empty for all.']
		], self::commonOptions(false));
	}

	public function run(Context $ctx, array $o): SectionResult {
		$from = $ctx->period->from;
		$till = $ctx->period->till;
		$found = [];

		$params = [
			'output' => ['maintenanceid', 'name', 'maintenance_type', 'active_since', 'active_till'],
			'selectHosts' => ['hostid'],
			'selectHostGroups' => ['groupid'],
			'selectTimeperiods' => ['timeperiod_type', 'period']
		];

		foreach ($ctx->chunks($ctx->hostids(), $ctx->limit('chunk_hosts', 500)) as $hostids) {
			foreach ($ctx->api->call('maintenance.get', $params + ['hostids' => $hostids]) as $m) {
				$found[$m['maintenanceid']] = $m;
			}
		}

		if ($ctx->groups) {
			foreach ($ctx->api->call('maintenance.get', $params + ['groupids' => array_map('strval', array_keys($ctx->groups))]) as $m) {
				$found[$m['maintenanceid']] = $m;
			}
		}

		$host_groups = [];

		foreach ($this->hosts($ctx, $o) as $hostid => $h) {
			foreach ($h['groupids'] as $g) {
				$host_groups[$g][] = $hostid;
			}
		}

		$rows = [];

		$name_patterns = \Modules\Reporter\Lib\Core\Filters::patterns((string) $o['name_filter']);
		$scope_hosts = $this->hosts($ctx, $o);

		foreach ($found as $m) {
			if ((int) $m['active_since'] > $till || (int) $m['active_till'] < $from) {
				continue;
			}

			if ($name_patterns && !\Modules\Reporter\Lib\Core\Filters::matches((string) $m['name'], $name_patterns)) {
				continue;
			}

			$affected = [];

			foreach ($m['hosts'] ?? [] as $h) {
				if (isset($scope_hosts[$h['hostid']])) {
					$affected[$h['hostid']] = true;
				}
			}

			foreach ($m['hostgroups'] ?? $m['groups'] ?? [] as $g) {
				foreach ($host_groups[$g['groupid']] ?? [] as $hostid) {
					$affected[$hostid] = true;
				}
			}

			if (!$affected) {
				continue;
			}

			$kinds = [];

			foreach ($m['timeperiods'] ?? [] as $tp) {
				$kinds[] = self::RECURRENCE[(int) $tp['timeperiod_type']] ?? 'Other';
			}

			$rows[] = [
				'name' => $m['name'],
				'type' => (int) $m['maintenance_type'] === 0 ? 'With data collection' : 'No data collection',
				'recurrence' => implode(', ', array_unique($kinds)),
				'since' => (int) $m['active_since'],
				'till' => (int) $m['active_till'],
				'devices' => count($affected),
				'device_names' => count($affected) <= 3
					? implode(', ', array_map(fn($h) => $ctx->hostName((string) $h), array_keys($affected)))
					: ''
			];
		}

		usort($rows, static fn($a, $b) => [$b['devices'], $a['since']] <=> [$a['devices'], $b['since']]);

		return (new SectionResult())->table('Maintenance', [
			['key' => 'name', 'label' => 'Maintenance'],
			['key' => 'type', 'label' => 'Type'],
			['key' => 'recurrence', 'label' => 'Windows'],
			['key' => 'since', 'label' => 'Active from', 'format' => 'date'],
			['key' => 'till', 'label' => 'Active until', 'format' => 'date'],
			['key' => 'devices', 'label' => 'Devices', 'format' => 'int'],
			['key' => 'device_names', 'label' => 'Which', 'screen' => true]
		], $rows, [
			'display_limit' => $o['display_limit'],
			'sheet' => 'Maintenance',
			'empty' => 'No maintenance was active on these devices during the period.'
		]);
	}
}
