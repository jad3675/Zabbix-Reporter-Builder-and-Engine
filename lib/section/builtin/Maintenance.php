<?php declare(strict_types = 1);

namespace Modules\Reporter\Lib\Section\Builtin;

use Modules\Reporter\Lib\Api\ApiException;
use Modules\Reporter\Lib\Core\Context;
use Modules\Reporter\Lib\Core\Filters;
use Modules\Reporter\Lib\Core\Format;
use Modules\Reporter\Lib\Core\SectionResult;
use Modules\Reporter\Lib\Data\MaintenancePeriods;
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
		return 'Maintenance on devices in scope: the windows in effect during the period, the devices in maintenance now and the ones nobody took out, time spent without data collection, and the problems maintenance kept quiet.';
	}

	public function options(): array {
		return array_merge([
			['name' => 'show_windows', 'label' => 'Windows in effect during the period', 'type' => 'bool', 'default' => true],
			['name' => 'show_now', 'label' => 'Devices in maintenance now', 'type' => 'bool', 'default' => true,
				'hint' => 'As of when the report runs, whatever period it covers.'],
			['name' => 'show_time', 'label' => 'Time each device spent in maintenance', 'type' => 'bool', 'default' => true,
				'hint' => 'Split into with and without data collection. Hours without data are gaps in graphs and in availability.'],
			['name' => 'show_hidden', 'label' => 'Problems during maintenance', 'type' => 'bool', 'default' => true,
				'hint' => 'Problems that started while their device was in maintenance with data collection, so nobody was notified.'],
			['name' => 'stale_days', 'label' => 'Flag devices in maintenance longer than (days)', 'type' => 'int',
				'default' => 7, 'min' => 1, 'max' => 3650,
				'hint' => 'Also flagged: a device not due out for more than a year.'],
			['name' => 'stale_only', 'label' => 'List only flagged devices', 'type' => 'bool', 'default' => false,
				'hint' => 'In "Devices in maintenance now". With the other parts switched off, a report of devices someone forgot to take out.'],
			['name' => 'who', 'label' => 'Who added them', 'type' => 'bool', 'default' => true,
				'hint' => 'From the audit log, which needs Super admin; without it the column stays empty and a note says why.'],
			['name' => 'display_limit', 'label' => 'Rows', 'type' => 'int', 'default' => 50, 'min' => 5, 'max' => 500],
			['name' => 'name_filter', 'label' => 'Only windows named', 'type' => 'text', 'default' => '',
				'hint' => 'Comma-separated patterns. Leave empty for all.']
		], self::commonOptions(false));
	}

	/** 1.8.2 had one "Show" select; carry a saved choice over to the switches. */
	public function normalizeOptions(array $options): array {
		$legacy = [
			'windows' => ['show_windows' => true, 'show_now' => false, 'show_time' => false, 'show_hidden' => false],
			'devices' => ['show_windows' => false, 'show_now' => true, 'show_time' => false, 'show_hidden' => false]
		];

		if (isset($options['show'], $legacy[$options['show']]) && !isset($options['show_windows'])) {
			$options = $legacy[$options['show']] + $options;
		}

		return parent::normalizeOptions($options);
	}

	public function validateOptions(array $o): array {
		return ($o['show_windows'] || $o['show_now'] || $o['show_time'] || $o['show_hidden'])
			? []
			: ['Switch on at least one part of the maintenance section.'];
	}

	public function run(Context $ctx, array $o): SectionResult {
		$result = new SectionResult();
		$active = ($o['show_windows'] || $o['show_time'] || $o['show_hidden']) ? $this->inEffect($ctx, $o) : [];

		if ($o['show_windows']) {
			$this->windows($ctx, $o, $active, $result);
		}

		if ($o['show_now']) {
			$this->devices($ctx, $o, $result);
		}

		if ($o['show_time']) {
			$this->timeIn($ctx, $o, $active, $result);
		}

		if ($o['show_hidden']) {
			$this->hidden($ctx, $o, $active, $result);
		}

		if ($active && ($o['show_time'] || $o['show_hidden'] || $o['show_windows'])) {
			$result->note(sprintf('When each window was in effect is worked out from its schedule, read in %s. Zabbix reads it in the server\'s time zone; if that differs, recurring windows shift by the difference.',
				$ctx->timezone()));
		}

		return $result;
	}

	/**
	 * Windows that were in effect at some point in the period on devices in scope, with
	 * the devices they covered and when.
	 *
	 * @return array<string, array{m: array, hosts: array<string, true>, intervals: array}>
	 */
	private function inEffect(Context $ctx, array $o): array {
		$from = $ctx->period->from;
		$till = $ctx->period->till;
		$found = [];

		$params = [
			'output' => ['maintenanceid', 'name', 'maintenance_type', 'active_since', 'active_till', 'tags_evaltype'],
			'selectHosts' => ['hostid'],
			'selectHostGroups' => ['groupid'],
			'selectTags' => ['tag', 'operator', 'value'],
			'selectTimeperiods' => ['timeperiod_type', 'every', 'month', 'dayofweek', 'day', 'start_time', 'period',
				'start_date']
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

		$scope_hosts = $this->hosts($ctx, $o);
		$host_groups = [];

		foreach ($scope_hosts as $hostid => $h) {
			foreach ($h['groupids'] as $g) {
				$host_groups[$g][] = (string) $hostid;
			}
		}

		$name_patterns = Filters::patterns((string) $o['name_filter']);
		$out = [];

		foreach ($found as $id => $m) {
			if ($name_patterns && !Filters::matches((string) $m['name'], $name_patterns)) {
				continue;
			}

			// The period's till is its last second; intervals end exclusively.
			$intervals = MaintenancePeriods::intervals($m, $from, $till + 1, $ctx->timezone());

			if (!$intervals) {
				continue;
			}

			$affected = [];

			foreach ($m['hosts'] ?? [] as $h) {
				if (isset($scope_hosts[$h['hostid']])) {
					$affected[(string) $h['hostid']] = true;
				}
			}

			foreach ($m['hostgroups'] ?? $m['groups'] ?? [] as $g) {
				foreach ($host_groups[$g['groupid']] ?? [] as $hostid) {
					$affected[$hostid] = true;
				}
			}

			if ($affected) {
				$out[(string) $id] = ['m' => $m, 'hosts' => $affected, 'intervals' => $intervals];
			}
		}

		return $out;
	}

	private function windows(Context $ctx, array $o, array $active, SectionResult $result): void {
		$rows = [];

		foreach ($active as $a) {
			$m = $a['m'];
			$affected = $a['hosts'];

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
				'hours' => MaintenancePeriods::seconds($a['intervals']),
				'device_names' => count($affected) <= 3
					? implode(', ', array_map(fn($h) => $ctx->hostName((string) $h), array_keys($affected)))
					: ''
			];
		}

		usort($rows, static fn($a, $b) => [$b['devices'], $a['since']] <=> [$a['devices'], $b['since']]);

		$result->table('Windows in effect during the period', [
			['key' => 'name', 'label' => 'Maintenance'],
			['key' => 'type', 'label' => 'Type'],
			['key' => 'recurrence', 'label' => 'Windows'],
			['key' => 'since', 'label' => 'Active from', 'format' => 'date'],
			['key' => 'till', 'label' => 'Active until', 'format' => 'date'],
			['key' => 'hours', 'label' => 'In effect', 'format' => 'duration'],
			['key' => 'devices', 'label' => 'Devices', 'format' => 'int'],
			['key' => 'device_names', 'label' => 'Which', 'screen' => true]
		], $rows, [
			'display_limit' => $o['display_limit'],
			'sheet' => 'Maintenance',
			'empty' => 'No maintenance was in effect on these devices during the period.'
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
		$result->kpis($kpis, 'Devices in maintenance now');

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
			'under_kpis' => true,
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
	 * Per device, how long it was in maintenance during the period, and how much of that
	 * without data collection. No data means no values at all: gaps in graphs, holes in
	 * availability, and problems that could not be raised in the first place.
	 */
	private function timeIn(Context $ctx, array $o, array $active, SectionResult $result): void {
		$with = [];
		$without = [];
		$names = [];

		foreach ($active as $a) {
			$nodata = (int) $a['m']['maintenance_type'] === 1;

			foreach ($a['hosts'] as $hostid => $_) {
				if ($nodata) {
					$without[$hostid][] = $a['intervals'];
				}
				else {
					$with[$hostid][] = $a['intervals'];
				}

				$names[$hostid][(string) $a['m']['name']] = true;
			}
		}

		$period = max(1, $ctx->period->seconds());
		$rows = [];

		foreach ($names as $hostid => $windows) {
			$off = MaintenancePeriods::union($without[$hostid] ?? []);
			$all = MaintenancePeriods::union(array_merge($with[$hostid] ?? [], [$off]));
			$off_s = MaintenancePeriods::seconds($off);
			$rows[] = [
				'host' => $ctx->hostName($hostid),
				'windows' => implode(', ', array_keys($windows)),
				'total' => MaintenancePeriods::seconds($all),
				'nodata' => $off_s,
				'nodata_pct' => $off_s / $period * 100
			];
		}

		usort($rows, static fn($a, $b) => [$b['nodata'], $b['total'], $a['host']] <=> [$a['nodata'], $a['total'], $b['host']]);
		$nodata_rows = array_filter($rows, static fn($r) => $r['nodata'] > 0);

		$result->kpis([
			['label' => 'Devices in maintenance during the period', 'value' => count($rows), 'format' => 'int'],
			['label' => 'Of them with data collection off', 'value' => count($nodata_rows), 'format' => 'int'],
			['label' => 'Device-hours without data', 'value' => (int) round(array_sum(array_column($rows, 'nodata')) / 3600),
				'format' => 'int']
		], 'Time in maintenance during the period');

		$result->table('Time in maintenance during the period', [
			['key' => 'host', 'label' => 'Device'],
			['key' => 'windows', 'label' => 'Maintenance'],
			['key' => 'total', 'label' => 'In maintenance', 'format' => 'duration'],
			['key' => 'nodata', 'label' => 'Without data', 'format' => 'duration'],
			['key' => 'nodata_pct', 'label' => 'Of the period without data', 'format' => 'pct1']
		], $rows, [
			'display_limit' => $o['display_limit'],
			'sheet' => 'Time in maintenance',
			'under_kpis' => true,
			'empty' => 'No device in scope was in maintenance during the period.'
		]);

		if ($nodata_rows) {
			$result->note('Hours without data collection hold no values: graphs show gaps, availability leaves those hours out, and no problem could be raised in them. A device that looks unusually quiet or has a low availability figure may be one of these.');
		}
	}

	/**
	 * Problems that started while their device was in a maintenance with data
	 * collection: Zabbix raised them and suppressed the notifications. A maintenance with
	 * tag conditions only covers problems whose tags match. Problems still open when the
	 * maintenance ended carried on alerting afterwards; they are counted separately.
	 */
	private function hidden(Context $ctx, array $o, array $active, SectionResult $result): void {
		$by_host = [];
		$tagged = [];

		foreach ($active as $id => $a) {
			if ((int) $a['m']['maintenance_type'] !== 0) {
				continue;
			}

			foreach ($a['hosts'] as $hostid => $_) {
				$by_host[$hostid][$id] = $a;
			}

			if (!empty($a['m']['tags'])) {
				$tagged[$id] = true;
			}
		}

		$candidates = [];

		foreach ($by_host ? $ctx->problems() : [] as $p) {
			foreach ($p['hostids'] as $hostid) {
				foreach ($by_host[$hostid] ?? [] as $id => $a) {
					if (MaintenancePeriods::contains($a['intervals'], $p['clock'])) {
						$candidates[$p['eventid']][] = [$hostid, $id];
					}
				}
			}
		}

		// Tags only where some window that matched needs them.
		$tags = [];
		$need = array_keys(array_filter($candidates,
			static fn($hits) => (bool) array_filter($hits, static fn($h) => isset($tagged[$h[1]]))));

		foreach ($ctx->chunks(array_map('strval', $need), $ctx->limit('chunk_ids', 1000)) as $ids) {
			foreach ($ctx->api->call('event.get', ['output' => ['eventid'], 'eventids' => $ids,
					'selectTags' => ['tag', 'value']]) as $e) {
				$tags[(string) $e['eventid']] = $e['tags'] ?? [];
			}
		}

		$problems = $ctx->problems();
		$rows = [];
		$total = 0;
		$outlasted = 0;

		foreach ($candidates as $eventid => $hits) {
			$p = $problems[$eventid];
			$counted = false;

			foreach ($hits as [$hostid, $id]) {
				$a = $active[$id];

				if (isset($tagged[$id]) && !MaintenancePeriods::tagsMatch($a['m'], $tags[$eventid] ?? [])) {
					continue;
				}

				// Once per device, even under two overlapping windows.
				if (isset($rows[$hostid]['events'][$eventid])) {
					continue;
				}

				$end = self::intervalEnd($a, $p['clock'], $ctx);
				$over = $p['r_clock'] === null || $p['r_clock'] >= $end;

				$rows[$hostid] ??= ['host' => $ctx->hostName($hostid), 'problems' => 0, 'outlasted' => 0,
					'sev' => [0, 0, 0, 0, 0, 0], 'worst' => 0, 'names' => [], 'windows' => [], 'events' => []];
				$r = &$rows[$hostid];
				$r['events'][$eventid] = true;
				$r['problems']++;
				$r['outlasted'] += $over ? 1 : 0;
				$r['sev'][$p['severity']]++;
				$r['worst'] = max($r['worst'], $p['severity']);
				$r['names'][$p['name']] = ($r['names'][$p['name']] ?? 0) + 1;
				$r['windows'][(string) $a['m']['name']] = true;
				unset($r);

				if (!$counted) {
					$total++;
					$outlasted += $over ? 1 : 0;
					$counted = true;
				}
			}
		}

		foreach ($rows as &$r) {
			arsort($r['names']);
			$top = array_key_first($r['names']);
			$r['most'] = $top.($r['names'][$top] > 1 ? ' ('.$r['names'][$top].')' : '');
			$r['window'] = implode(', ', array_keys($r['windows']));
			unset($r['names'], $r['windows'], $r['events']);
		}
		unset($r);

		$rows = array_values($rows);
		usort($rows, static fn($a, $b) => [$b['worst'], $b['problems'], $a['host']] <=> [$a['worst'], $a['problems'], $b['host']]);

		$result->kpis([
			['label' => 'Problems', 'value' => $total, 'format' => 'int'],
			['label' => 'Devices', 'value' => count($rows), 'format' => 'int'],
			['label' => 'Still open when it ended', 'value' => $outlasted, 'format' => 'int']
		], 'Problems during maintenance');

		$result->table('Problems during maintenance', [
			['key' => 'host', 'label' => 'Device'],
			['key' => 'window', 'label' => 'Maintenance'],
			['key' => 'problems', 'label' => 'Problems', 'format' => 'int'],
			['key' => 'sev', 'label' => 'Severity mix', 'format' => 'sevstrip', 'export' => false],
			['key' => 'worst', 'label' => 'Worst', 'format' => 'severity'],
			['key' => 'outlasted', 'label' => 'Still open when it ended', 'format' => 'int'],
			['key' => 'most', 'label' => 'Most frequent']
		], $rows, [
			'display_limit' => $o['display_limit'],
			'sheet' => 'Problems in maintenance',
			'under_kpis' => true,
			'empty' => 'No problems started on these devices while they were in maintenance.'
		]);

		$result->note('Problems that started while their device was in maintenance with data collection, so their notifications were held back. "Still open when it ended" counts the ones still open when the maintenance ended, which alerted from then on. Maintenance without data collection raises no problems at all, so it hides more than this can count.');
	}

	/**
	 * When the maintenance period holding $clock really ended. Intervals stop at the end
	 * of the report; one still running then is followed past it.
	 */
	private static function intervalEnd(array $a, int $clock, Context $ctx): int {
		foreach ($a['intervals'] as [$start, $end]) {
			if ($clock >= $start && $clock < $end) {
				if ($end > $ctx->period->till) {
					foreach (MaintenancePeriods::intervals($a['m'], $end - 1, $end + 400 * 86400, $ctx->timezone()) as [$x, $y]) {
						if ($x <= $end - 1 && $y > $end - 1) {
							return $y;
						}
					}
				}

				return $end;
			}
		}

		return $clock;
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
