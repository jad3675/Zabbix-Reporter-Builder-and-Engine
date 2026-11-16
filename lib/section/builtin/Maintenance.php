<?php declare(strict_types = 1);

namespace Modules\Reporter\Lib\Section\Builtin;

use Modules\Reporter\Lib\Api\ApiException;
use Modules\Reporter\Lib\Core\Context;
use Modules\Reporter\Lib\Core\Filters;
use Modules\Reporter\Lib\Core\Format;
use Modules\Reporter\Lib\Core\SectionResult;
use Modules\Reporter\Lib\Section\AbstractSection;

final class Maintenance extends AbstractSection {

	private const RECURRENCE = [0 => 'One time', 2 => 'Daily', 3 => 'Weekly', 4 => 'Monthly'];

	/** Zabbix audit log resource type for maintenance. */
	private const AUDIT_MAINTENANCE = 27;

	public function type(): string {
		return 'maintenance';
	}

	public function label(): string {
		return 'Maintenance windows';
	}

	public function description(): string {
		return 'Maintenance on devices in scope: the windows active during the period, and the devices in maintenance now, with how long they have been in it. Explains quiet devices and suppressed alerts, and finds the ones nobody took out.';
	}

	public function options(): array {
		return array_merge([
			['name' => 'show', 'label' => 'Show', 'type' => 'select', 'default' => 'both',
				'choices' => ['both' => 'Windows in the period, and devices in maintenance now',
					'windows' => 'Windows in the period only', 'devices' => 'Devices in maintenance now only']],
			['name' => 'stale_days', 'label' => 'Flag devices in maintenance longer than (days)', 'type' => 'int',
				'default' => 7, 'min' => 1, 'max' => 3650,
				'hint' => 'Also flagged: a device not due out for more than a year.'],
			['name' => 'stale_only', 'label' => 'List only flagged devices', 'type' => 'bool', 'default' => false,
				'hint' => 'For a report of devices someone forgot to take out of maintenance.'],
			['name' => 'who', 'label' => 'Who added them', 'type' => 'bool', 'default' => true,
				'hint' => 'From the audit log, which needs Super admin; without it the column stays empty and a note says why.'],
			['name' => 'display_limit', 'label' => 'Rows', 'type' => 'int', 'default' => 50, 'min' => 5, 'max' => 500],
			['name' => 'name_filter', 'label' => 'Only windows named', 'type' => 'text', 'default' => '',
				'hint' => 'Comma-separated patterns. Leave empty for all.']
		], self::commonOptions(false));
	}

	public function run(Context $ctx, array $o): SectionResult {
		$result = new SectionResult();

		if ($o['show'] !== 'devices') {
			$this->windows($ctx, $o, $result);
		}

		if ($o['show'] !== 'windows') {
			$this->devices($ctx, $o, $result);
		}

		return $result;
	}

	private function windows(Context $ctx, array $o, SectionResult $result): void {
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

		$result->table('Windows active in the period', [
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

	/**
	 * Devices in maintenance right now, how long they have been in it, when it is due to
	 * end, and who put them there. The host object carries maintenance_status,
	 * maintenanceid, maintenance_type and maintenance_from (when the current maintenance
	 * period began), so "never taken out" is simply "in maintenance longer than it
	 * should be". A recurring window resets maintenance_from every time it opens, so a
	 * nightly 02:00 window does not look stuck; a one-time period of 400 days does.
	 */
	private function devices(Context $ctx, array $o, SectionResult $result): void {
		$now = time();
		$scope = $this->hosts($ctx, $o);
		$in = [];

		foreach ($ctx->chunks(array_map('strval', array_keys($scope)), $ctx->limit('chunk_hosts', 500)) as $hostids) {
			foreach ($ctx->api->call('host.get', [
				'output' => ['hostid', 'maintenance_status', 'maintenanceid', 'maintenance_type', 'maintenance_from'],
				'hostids' => $hostids,
				'filter' => ['maintenance_status' => 1]
			]) as $h) {
				if ((int) $h['maintenance_status'] === 1 && isset($scope[(string) $h['hostid']])) {
					$in[(string) $h['hostid']] = $h;
				}
			}
		}

		$name_patterns = Filters::patterns((string) $o['name_filter']);
		$maint_ids = array_values(array_unique(array_map(static fn($h) => (string) $h['maintenanceid'], $in)));
		$windows = [];

		if ($maint_ids) {
			foreach ($ctx->api->call('maintenance.get', [
				'output' => ['maintenanceid', 'name', 'maintenance_type', 'active_since', 'active_till'],
				'maintenanceids' => $maint_ids,
				'selectTimeperiods' => ['timeperiod_type', 'period', 'start_date']
			]) as $m) {
				$windows[(string) $m['maintenanceid']] = $m;
			}
		}

		[$who, $who_note] = $o['who'] && $in ? self::whoAdded($ctx, array_keys($windows)) : [[], null];
		$stale = (int) $o['stale_days'] * 86400;
		$rows = [];

		foreach ($in as $hostid => $h) {
			$m = $windows[(string) $h['maintenanceid']] ?? null;
			$name = $m !== null ? (string) $m['name'] : '#'.$h['maintenanceid'];

			if ($name_patterns && !Filters::matches($name, $name_patterns)) {
				continue;
			}

			$since = (int) $h['maintenance_from'];
			$ends = $m !== null ? self::scheduledEnd($m, $since) : null;
			$flags = [];

			if ($since > 0 && $now - $since > $stale) {
				$flags[] = sprintf('Over %d days', (int) $o['stale_days']);
			}

			if ($ends !== null && $ends - $now > 365 * 86400) {
				$flags[] = sprintf('Out in %s', self::years($ends - $now));
			}

			if ($o['stale_only'] && !$flags) {
				continue;
			}

			$added = $who[(string) $h['maintenanceid']]['hosts'][$hostid]
				?? $who[(string) $h['maintenanceid']]['created']
				?? null;

			$tz = $ctx->timezone();
			$rows[] = [
				'since_text' => $since > 0 ? Format::datetime($since, $tz, 'j M Y H:i') : '',
				'ends_text' => $ends !== null ? Format::datetime($ends, $tz, 'j M Y') : '',
				'added_text' => isset($added['clock']) ? Format::datetime($added['clock'], $tz, 'j M Y') : '',
				'host' => $ctx->hostName($hostid),
				'window' => $name,
				'type' => (int) $h['maintenance_type'] === 0 ? 'With data' : 'No data',
				'since' => $since > 0 ? $since : null,
				'for' => $since > 0 ? $now - $since : null,
				'ends' => $ends,
				'added_by' => $added['user'] ?? '',
				'added_on' => $added['clock'] ?? null,
				'flag' => implode('; ', $flags),
				'flagged' => (bool) $flags
			];
		}

		usort($rows, static fn($a, $b) => [$b['flagged'], $b['for'] ?? 0] <=> [$a['flagged'], $a['for'] ?? 0]);

		$all = count($in);
		$kpis = [
			['label' => 'In maintenance now', 'value' => $all, 'format' => 'int'],
			['label' => sprintf('In it over %d days', (int) $o['stale_days']),
				'value' => count(array_filter($in, static fn($h) => (int) $h['maintenance_from'] > 0
					&& $now - (int) $h['maintenance_from'] > $stale)), 'format' => 'int'],
			['label' => 'Without data collection',
				'value' => count(array_filter($in, static fn($h) => (int) $h['maintenance_type'] === 1)), 'format' => 'int']
		];
		$result->kpis($kpis);

		$columns = [
			['key' => 'host', 'label' => 'Device'],
			['key' => 'window', 'label' => 'Maintenance'],
			['key' => 'type', 'label' => 'Type', 'nowrap' => true],
			// Dates with the year on screen: the rows that matter are the old ones.
			['key' => 'since_text', 'label' => 'In since', 'nowrap' => true, 'export' => false],
			['key' => 'since', 'label' => 'In since', 'format' => 'datetime', 'screen' => false],
			['key' => 'for', 'label' => 'For', 'format' => 'duration'],
			['key' => 'ends_text', 'label' => 'Due out', 'nowrap' => true, 'export' => false],
			['key' => 'ends', 'label' => 'Due out', 'format' => 'date', 'screen' => false]
		];

		if ($o['who']) {
			$columns[] = ['key' => 'added_by', 'label' => 'Added by'];
			$columns[] = ['key' => 'added_text', 'label' => 'Added on', 'nowrap' => true, 'export' => false];
			$columns[] = ['key' => 'added_on', 'label' => 'Added on', 'format' => 'date', 'screen' => false];
		}

		$columns[] = ['key' => 'flag', 'label' => 'Flag'];

		$result->table('Devices in maintenance now', $columns, $rows, [
			'display_limit' => $o['display_limit'],
			'sheet' => 'In maintenance',
			'empty' => $o['stale_only']
				? sprintf('No device has been in maintenance for more than %d days.', (int) $o['stale_days'])
				: 'No device in scope is in maintenance.'
		]);

		$result->note(sprintf('Devices in maintenance as of %s, when the report ran, whatever period it covers.',
			Format::datetime($now, $ctx->timezone(), 'j M Y H:i')));

		if ($who_note !== null) {
			$result->note($who_note);
		}
		elseif ($o['who'] && array_filter($rows, static fn($r) => $r['added_by'] === '')) {
			$result->note('Added by is empty where the audit log has no record: the window was created before the log was kept, or the device came in through a host group.');
		}
	}

	/**
	 * When the current maintenance period ends: its start plus the length of the time
	 * period that opened it, but never after the window's own end.
	 */
	private static function scheduledEnd(array $m, int $since): ?int {
		$till = (int) $m['active_till'];
		$best = null;

		foreach ($m['timeperiods'] ?? [] as $tp) {
			$period = (int) $tp['period'];

			// A one-time period is the one that opened it if it started then.
			if ((int) $tp['timeperiod_type'] === 0 && isset($tp['start_date'])
					&& abs((int) $tp['start_date'] - $since) > 120 && $since > 0) {
				continue;
			}

			$best = max($best ?? 0, $since + $period);
		}

		if ($best === null) {
			return $till > 0 ? $till : null;
		}

		return $till > 0 ? min($best, $till) : $best;
	}

	private static function years(int $seconds): string {
		$y = $seconds / (365.25 * 86400);

		return $y >= 2 ? sprintf('%d years', (int) floor($y)) : sprintf('%d months', (int) floor($seconds / (30.44 * 86400)));
	}

	/**
	 * Who created each window, and who added each device to it, from the audit log.
	 * Devices added through a host group have no entry of their own; they fall back to
	 * the window's creation. Needs Super admin; anyone else gets the table without it.
	 *
	 * @return array{0: array, 1: ?string}
	 */
	private static function whoAdded(Context $ctx, array $maint_ids): array {
		if (!$maint_ids) {
			return [[], null];
		}

		try {
			$entries = $ctx->api->call('auditlog.get', [
				'output' => ['userid', 'username', 'clock', 'action', 'resourceid', 'details'],
				'filter' => ['resourcetype' => self::AUDIT_MAINTENANCE, 'resourceid' => $maint_ids, 'action' => [0, 1]],
				'sortfield' => 'clock',
				'sortorder' => 'ASC',
				'limit' => 5000
			]);
		}
		catch (ApiException $e) {
			return [[], 'Who added the devices needs the audit log, which only a Super admin can read.'];
		}

		$out = [];
		$seen = false;

		foreach ($entries as $e) {
			$id = (string) $e['resourceid'];
			$who = ['user' => (string) $e['username'] !== '' ? (string) $e['username'] : 'System', 'clock' => (int) $e['clock']];
			$seen = true;

			if ((int) $e['action'] === 0) {
				$out[$id]['created'] = $who;
			}

			$details = is_string($e['details']) ? json_decode($e['details'], true) : $e['details'];

			foreach (is_array($details) ? $details : [] as $field => $change) {
				// maintenance.hosts[12].hostid => ["add", "10084"]; the latest add wins.
				if (preg_match('/^maintenance\.hosts\[\d+\]\.hostid$/', (string) $field)
						&& is_array($change) && ($change[0] ?? '') === 'add' && isset($change[1])) {
					$out[$id]['hosts'][(string) $change[1]] = $who;
				}
			}
		}

		return [$out, $seen ? null
			: 'The audit log holds nothing about these windows; it may have been cleared by housekeeping.'];
	}
}
