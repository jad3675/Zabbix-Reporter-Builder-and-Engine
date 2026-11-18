<?php declare(strict_types = 1);

namespace Modules\Reporter\Lib\Section\Builtin;

use Modules\Reporter\Lib\Core\Context;
use Modules\Reporter\Lib\Core\Format;
use Modules\Reporter\Lib\Core\SectionResult;
use Modules\Reporter\Lib\Data\Interfaces;
use Modules\Reporter\Lib\Data\Trends;
use Modules\Reporter\Lib\Section\AbstractSection;

/**
 * Traffic, utilization, errors and link state for the ports a customer cares about,
 * picked by description (ifAlias) and name: every port described as an uplink, every
 * Te1/1/* on the core, the WAN handoffs. Built from hourly trends of the items Zabbix's
 * interface discovery creates; see Data\Interfaces for how ports are recognised.
 */
final class NetworkInterfaces extends AbstractSection {

	private const OPER_STATUS = [1 => 'Up', 2 => 'Down', 3 => 'Testing', 4 => 'Unknown', 5 => 'Dormant',
		6 => 'Not present', 7 => 'Lower layer down'];

	private const STATES = [
		'all' => 'Any link state',
		'down_now' => 'Not up right now (latest value)',
		'not_up_any' => 'Not up at some point in the period',
		'not_up_end' => 'Not up at the end of the period',
		'never_up' => 'Not up at any time in the period',
		'up_all' => 'Up the whole period'
	];

	private const SORTS = [
		'peak_util' => 'Peak utilization',
		'avg_util' => 'Average utilization',
		'peak_traffic' => 'Peak traffic',
		'avg_traffic' => 'Average traffic',
		'errors' => 'Errors',
		'discards' => 'Discards',
		'down_hours' => 'Time not up',
		'uptime' => 'Uptime, lowest first',
		'name' => 'Device and interface name'
	];

	public function type(): string {
		return 'network_interfaces';
	}

	public function label(): string {
		return 'Network interfaces';
	}

	public function description(): string {
		return 'Interfaces picked by description and name: traffic, utilization, errors and link state over the period, or only the ones not up right now.';
	}

	public function options(): array {
		return array_merge([
			['name' => 'alias', 'label' => 'Description (ifAlias) matches', 'type' => 'text', 'default' => '*uplink*',
				'hint' => 'Comma-separated, case-insensitive. A word with no wildcard matches anywhere: uplink, wan, *core*'],
			['name' => 'name', 'label' => 'Interface name matches', 'type' => 'text', 'default' => '',
				'hint' => 'ifName (or ifDescr where the template exposes it), e.g. Te1/1/*, Gi1/0/48, Port-channel*'],
			['name' => 'logic', 'label' => 'When both are set', 'type' => 'select', 'default' => 'and',
				'choices' => ['and' => 'An interface must match both', 'or' => 'Either one is enough']],
			['name' => 'exclude', 'label' => 'Skip interfaces', 'type' => 'text', 'default' => '',
				'hint' => 'Names or descriptions to leave out, e.g. Vlan*, *spare*, Null0'],
			['name' => 'state', 'label' => 'Link state', 'type' => 'select', 'default' => 'all',
				'choices' => self::STATES,
				'hint' => 'From the operational status item (ifOperStatus): 1 is up; 2 down, 7 lower layer down and the rest count as not up. "Right now" reads the latest value and lists only those interfaces, with when they went down: the one for a morning down report. The others read the period\'s hourly trends.'],
			['name' => 'down_lookback', 'label' => 'Look back for when it went down (days)', 'type' => 'int', 'default' => 7,
				'min' => 1, 'max' => 90,
				'hint' => 'With "Not up right now": how far back to look for the last time each down interface was up, whatever the report period. Only the down ones are read.'],
			['name' => 'sort', 'label' => 'Sort by', 'type' => 'select', 'default' => 'peak_util',
				'choices' => self::SORTS],
			['name' => 'limit', 'label' => 'Rows', 'type' => 'int', 'default' => 50, 'min' => 1, 'max' => 1000],
			['name' => 'util_threshold', 'label' => 'Busy above (%)', 'type' => 'float', 'default' => 80, 'min' => 1,
				'max' => 100, 'hint' => 'Interfaces whose peak utilization reached this are counted in the headline.'],
			['name' => 'hide_idle', 'label' => 'Leave out interfaces with no traffic', 'type' => 'bool', 'default' => true,
				'hint' => 'Unused ports: no traffic, and up the whole period or down the whole period. A port whose link went down during the period is always kept, and so is every port when a link state is picked above. Counted in a note.'],
			['name' => 'show_errors', 'label' => 'Errors and discards', 'type' => 'bool', 'default' => true],
			['name' => 'show_status', 'label' => 'Link state columns', 'type' => 'bool', 'default' => true,
				'hint' => 'Status at the end of the period, uptime, and time not up. Always shown when a link state is picked above.'],
			['name' => 'sparkline', 'label' => 'Daily trend line', 'type' => 'bool', 'default' => true],
			['name' => 'chart', 'label' => 'Bar chart', 'type' => 'bool', 'default' => true]
		], self::commonOptions(false));
	}

	public function validateOptions(array $o): array {
		return ($o['alias'] === '' && $o['name'] === '')
			? ['Set a description or interface name pattern. Use * to report every interface on the devices in scope.']
			: [];
	}

	public function run(Context $ctx, array $o): SectionResult {
		$result = new SectionResult();
		$filter = ['alias' => $o['alias'], 'name' => $o['name'], 'exclude' => $o['exclude'], 'logic' => $o['logic']];
		$ports = $ctx->memo('ifaces:'.sha1(json_encode($filter)), static fn(Context $c) => Interfaces::find($c, $filter));
		$hosts = $this->hosts($ctx, $o);
		$ports = array_filter($ports, static fn($p) => isset($hosts[$p['hostid']]));

		if (!$ports) {
			$result->text('No interfaces on the devices in scope match these patterns.');

			foreach (Interfaces::explain($ctx, $filter) as $line) {
				$result->note($line);
			}

			return $result;
		}

		if ($o['state'] === 'down_now') {
			return $this->downNow($ctx, $o, $ports, $result);
		}

		// Only what the table will show. Link state is read once, by hoursAbove() below,
		// which also returns its last value; errors and discards only when asked for.
		$slots = array_merge(['in', 'out', 'speed'],
			$o['show_errors'] ? ['in_errors', 'out_errors', 'in_discards', 'out_discards'] : []);
		$ids = [];

		foreach ($ports as $port) {
			foreach ($slots as $slot) {
				if (isset($port['items'][$slot])) {
					$ids[] = $port['items'][$slot]['itemid'];
				}
			}
		}

		$sum = $ctx->trendSummary($ids);
		$status_ids = array_values(array_filter(array_map(
			static fn($p) => $p['items']['status']['itemid'] ?? null, $ports
		)));
		$filter_state = $o['state'] !== 'all';
		$show_state = $o['show_status'] || $filter_state;
		$links = $status_ids ? Trends::linkState($ctx, $status_ids) : [];

		$rows = [];
		$idle = 0;
		$idle_down = 0;
		$no_status = 0;

		foreach ($ports as $port) {
			$in = self::stat($sum, $port, 'in');
			$out = self::stat($sum, $port, 'out');
			$status_id = $port['items']['status']['itemid'] ?? null;
			$link = $status_id !== null ? ($links[$status_id] ?? null) : null;
			$state = $link !== null ? self::state($link) : null;

			if ($filter_state) {
				if ($state === null) {
					$no_status++;

					continue;
				}

				if (!self::stateMatches($state, $o['state'])) {
					continue;
				}
			}

			$in_avg = $in['avg'] ?? 0.0;
			$in_max = $in['max'] ?? 0.0;
			$out_avg = $out['avg'] ?? 0.0;
			$out_max = $out['max'] ?? 0.0;
			$quiet = $in_max <= 0 && $out_max <= 0;
			$has_traffic = isset($port['items']['in']) || isset($port['items']['out']);

			// No traffic at all and no link state either: nothing to say about this port.
			if ($in === null && $out === null && $state === null) {
				$idle++;

				continue;
			}

			// An unused port is quiet and steady: always up or always down. One whose link
			// dropped during the period is news even with no traffic, so it stays.
			if (!$filter_state && $o['hide_idle'] && $quiet && ($state === null || !$state['changed'])) {
				$idle++;
				$idle_down += ($state !== null && $state['never_up']) ? 1 : 0;

				continue;
			}

			$units = $port['items']['in']['units'] ?? $port['items']['out']['units'] ?? 'bps';
			$units = $units !== '' ? $units : 'bps';
			$speed = self::stat($sum, $port, 'speed');
			// Speed is reported by the device and changes when a link renegotiates; the
			// latest value is the one the port runs at now.
			$speed_bps = $speed !== null && $speed['last'] > 0 ? (float) $speed['last'] : null;
			$to_bits = $units === 'Bps' ? 8 : 1;

			$row = [
				'hostid' => $port['hostid'],
				'host' => $ctx->hostName($port['hostid']),
				'interface' => $port['name'] !== '' ? $port['name'] : '#'.$port['index'],
				'alias' => $port['alias'],
				'speed' => $speed_bps,
				'speed_units' => 'bps',
				// A port whose template keeps no traffic counters shows blanks, not zeros.
				'in_avg' => $has_traffic ? $in_avg : null,
				'in_max' => $has_traffic ? $in_max : null,
				'out_avg' => $has_traffic ? $out_avg : null,
				'out_max' => $has_traffic ? $out_max : null,
				'avg_util' => $has_traffic && $speed_bps ? max($in_avg, $out_avg) * $to_bits / $speed_bps * 100 : null,
				'peak_util' => $has_traffic && $speed_bps ? max($in_max, $out_max) * $to_bits / $speed_bps * 100 : null,
				'units' => $units,
				'in_id' => $port['items']['in']['itemid'] ?? null,
				'out_id' => $port['items']['out']['itemid'] ?? null
			];

			if ($o['show_errors']) {
				$row['errors'] = self::total($sum, $port, ['in_errors', 'out_errors']);
				$row['discards'] = self::total($sum, $port, ['in_discards', 'out_discards']);
			}

			if ($show_state) {
				$row['status'] = $state['label'] ?? '';
				$row['uptime'] = $state['uptime'] ?? null;
				$row['down_seconds'] = $state['down_seconds'] ?? null;
				$row['not_up_end'] = $state['not_up_end'] ?? false;
				$row['changed'] = $state['changed'] ?? false;
			}

			$rows[] = $row;
		}

		$matched = count($rows);
		$threshold = (float) $o['util_threshold'];
		$busy = count(array_filter($rows, static fn($r) => $r['peak_util'] !== null && $r['peak_util'] >= $threshold));
		$no_speed = count(array_filter($rows, static fn($r) => $r['speed'] === null && $r['in_max'] !== null));

		$kpis = [
			['label' => 'Interfaces', 'value' => $matched, 'format' => 'int'],
			['label' => 'Devices', 'value' => count(array_unique(array_column($rows, 'hostid'))), 'format' => 'int'],
			['label' => sprintf('Peaked at %s%% or more', rtrim(rtrim(number_format($threshold, 1), '0'), '.')),
				'value' => $busy, 'format' => 'int']
		];

		if ($o['show_errors']) {
			$kpis[] = ['label' => 'With errors', 'value' => count(array_filter($rows, static fn($r) => ($r['errors'] ?? 0) >= 1)),
				'format' => 'int'];
		}

		if ($show_state) {
			$kpis[] = ['label' => 'Not up at some point',
				'value' => count(array_filter($rows, static fn($r) => ($r['down_seconds'] ?? 0) > 0)), 'format' => 'int'];
			$kpis[] = ['label' => 'Not up at the end',
				'value' => count(array_filter($rows, static fn($r) => !empty($r['not_up_end']))), 'format' => 'int'];
		}

		$result->kpis($kpis);

		usort($rows, self::comparator($o['sort']));
		$top = array_slice($rows, 0, (int) $o['limit']);

		if ($o['sparkline'] && $top) {
			$daily = $ctx->trendDaily(array_values(array_filter(array_merge(
				array_column($top, 'in_id'), array_column($top, 'out_id')
			))));
			$days = count($ctx->period->dayStarts());

			foreach ($top as &$row) {
				$series = array_fill(0, $days, null);

				// The busier direction each day.
				foreach ([$row['in_id'], $row['out_id']] as $id) {
					foreach ($daily[$id] ?? [] as $d => $v) {
						$series[$d] = $series[$d] === null ? $v : max($series[$d], $v);
					}
				}

				$row['trend'] = $series;
			}
			unset($row);
		}

		if ($o['chart'] && $top && $o['sort'] !== 'name') {
			$chart = [];

			foreach (array_slice($top, 0, 10) as $r) {
				[$value, $units] = self::chartValue($r, $o['sort']);

				if ($value !== null) {
					$chart[] = ['label' => $r['host'].' '.$r['interface'], 'value' => $value, 'units' => $units];
				}
			}

			if ($chart) {
				$result->chart('hbar', self::SORTS[$o['sort']], $chart, ['format' => 'units']);
			}
		}

		$columns = [
			['key' => 'host', 'label' => 'Device'],
			['key' => 'interface', 'label' => 'Interface'],
			['key' => 'alias', 'label' => 'Description'],
			['key' => 'speed_display', 'label' => 'Speed', 'format' => 'text', 'export' => false, 'nowrap' => true],
			['key' => 'speed', 'label' => 'Speed (bps)', 'format' => 'number', 'screen' => false],
			['key' => 'in_avg', 'label' => 'In avg', 'format' => 'units'],
			['key' => 'in_max', 'label' => 'In peak', 'format' => 'units'],
			['key' => 'out_avg', 'label' => 'Out avg', 'format' => 'units'],
			['key' => 'out_max', 'label' => 'Out peak', 'format' => 'units'],
			['key' => 'avg_util', 'label' => 'Avg util', 'format' => 'pct1', 'screen' => false],
			['key' => 'peak_util', 'label' => 'Peak util', 'format' => 'pct1']
		];

		if ($o['show_errors']) {
			$columns[] = ['key' => 'errors', 'label' => 'Errors', 'format' => 'int'];
			$columns[] = ['key' => 'discards', 'label' => 'Discards', 'format' => 'int'];
		}

		if ($show_state) {
			$columns[] = ['key' => 'status', 'label' => 'Status', 'nowrap' => true];
			$columns[] = ['key' => 'uptime', 'label' => 'Uptime', 'format' => 'pct'];
			$columns[] = ['key' => 'down_seconds', 'label' => 'Not up', 'format' => 'duration'];
		}

		if ($o['sparkline']) {
			$columns[] = ['key' => 'trend', 'label' => 'Daily average', 'format' => 'spark', 'export' => false];
		}

		$columns[] = ['key' => 'units', 'label' => 'Traffic units', 'screen' => false];

		foreach ($top as &$row) {
			$row['speed_display'] = $row['speed'] !== null ? self::speedLabel($row['speed']) : '';
		}
		unset($row);

		$result->table('Interfaces', $columns, $top, [
			'sheet' => 'Interfaces',
			'empty' => $filter_state
				? sprintf('No matching interface: %s.', strtolower(self::STATES[$o['state']]))
				: 'Every matching interface was idle in this period.'
		]);

		if ($matched > count($top)) {
			$result->note(sprintf('%d interfaces matched; the first %d by %s are listed.', $matched, count($top),
				strtolower(self::SORTS[$o['sort']])));
		}

		if ($idle > 0) {
			$result->note(sprintf('%d matching interface(s) left out: no traffic in the period%s.', $idle,
				$idle_down > 0 ? sprintf(', %d of them not up at any time', $idle_down) : ''));
		}

		if ($no_status > 0) {
			$result->note(sprintf('%d matching interface(s) have no operational status item, so their link state is unknown and they are not listed.', $no_status));
		}

		if ($show_state) {
			$result->note('Uptime and time not up come from hourly trends of the operational status: an hour where the link changed is split by its average, which is exact for a single change in that hour. Hours with no data are left out of the uptime.');
		}

		if ($no_speed > 0) {
			$result->note(sprintf('%d interface(s) report no speed, so their utilization is not shown.', $no_speed));
		}

		$over = count(array_filter($rows, static fn($r) => $r['peak_util'] !== null && $r['peak_util'] > 100.5));

		if ($over > 0) {
			$result->note(sprintf('%d interface(s) peaked above 100%%. That usually means a counter reset or a speed item that does not match the link (a port channel reporting one member\'s speed, for example).', $over));
		}

		if ($o['show_errors']) {
			$result->note('Errors and discards are totals estimated from hourly averages of the per-second rates, in and out combined.');
		}

		return $result;
	}

	/** Link speeds read as people say them: 1 Gbps, 2.5 Gbps, 100 Mbps. */
	private static function speedLabel(float $bps): string {
		foreach ([1e12 => 'Tbps', 1e9 => 'Gbps', 1e6 => 'Mbps', 1e3 => 'Kbps'] as $div => $unit) {
			if ($bps >= $div) {
				$v = $bps / $div;

				return rtrim(rtrim(number_format($v, $v < 10 ? 2 : 1, '.', ''), '0'), '.').' '.$unit;
			}
		}

		return Format::int($bps).' bps';
	}

	/**
	 * The interfaces whose link is not up right now, from each status item's latest value
	 * as Zabbix holds it: no trends, so nothing lags behind. When each went down comes
	 * from the period's hourly trends: the last hour the link was seen up.
	 */
	private function downNow(Context $ctx, array $o, array $ports, SectionResult $result): SectionResult {
		$now = time();
		$tz = $ctx->timezone();
		$with_status = array_filter($ports, static fn($p) => isset($p['items']['status']));
		$no_status = count($ports) - count($with_status);
		$latest = Interfaces::latest($ctx, array_map(static fn($p) => $p['items']['status']['itemid'], $with_status));
		$down = [];
		$stale = 0;
		$unknown = 0;

		foreach ($with_status as $id => $port) {
			$v = $latest[$port['items']['status']['itemid']] ?? null;

			if ($v === null || $v['value'] === null || !$v['supported']) {
				$unknown++;

				continue;
			}

			// A status item that has not reported for a day may be telling an old story.
			if ($now - $v['clock'] > 86400) {
				$stale++;
			}

			if ((int) round($v['value']) !== 1) {
				$down[$id] = $v;
			}
		}

		// When each went down: the last hour it was up, looking back far enough to find
		// links down for days, and up to now rather than the end of the period.
		$look_from = min($ctx->period->from, $now - (int) $o['down_lookback'] * 86400);
		$links = $down ? Trends::linkState($ctx, array_map(static fn($id) => $ports[$id]['items']['status']['itemid'],
			array_keys($down)), $look_from, max($ctx->period->till, $now)) : [];
		$rows = [];

		foreach ($down as $id => $v) {
			$port = $ports[$id];
			$link = $links[$port['items']['status']['itemid']] ?? null;

			if ($link === null) {
				$since = null;
				$since_text = 'No trend data in the period';
			}
			elseif ($link['last_up_end'] === null) {
				// Never up in the look-back: down since before it began.
				$since = null;
				$since_text = 'Before '.Format::datetime($look_from, $tz, 'j M Y H:i');
			}
			else {
				$since = (int) $link['last_up_end'];
				$since_text = Format::datetime($since, $tz, 'j M Y H:i');
			}

			$rows[] = [
				'hostid' => $port['hostid'],
				'host' => $ctx->hostName($port['hostid']),
				'interface' => $port['name'] !== '' ? $port['name'] : '#'.$port['index'],
				'alias' => $port['alias'],
				'status' => self::statusLabel($v['value']),
				'since' => $since,
				'since_text' => $since_text,
				'for' => $since !== null ? max(0, $now - $since) : null,
				'for_text' => $since !== null
					? Format::duration(max(0, $now - $since))
					: ($link !== null ? 'Over '.Format::duration($now - $look_from) : ''),
				'checked' => $v['clock'],
				'checked_text' => Format::datetime($v['clock'], $tz, 'j M H:i')
					.($now - $v['clock'] > 86400 ? ' (stale)' : '')
			];
		}

		// Longest down first, the ones down before the period at the top, unless the report
		// asks for names.
		usort($rows, $o['sort'] === 'name'
			? static fn($a, $b) => strnatcasecmp($a['host'], $b['host']) ?: strnatcasecmp($a['interface'], $b['interface'])
			: static fn($a, $b) => [$a['since'] !== null, $a['since'] ?? 0, $a['host'], $a['interface']]
				<=> [$b['since'] !== null, $b['since'] ?? 0, $b['host'], $b['interface']]);

		$result->kpis([
			['label' => 'Not up now', 'value' => count($rows), 'format' => 'int'],
			['label' => 'Devices affected', 'value' => count(array_unique(array_column($rows, 'hostid'))), 'format' => 'int'],
			['label' => 'Interfaces checked', 'value' => count($with_status) - $unknown, 'format' => 'int']
		]);

		$result->table('Interfaces not up now', [
			['key' => 'host', 'label' => 'Device'],
			['key' => 'interface', 'label' => 'Interface', 'nowrap' => true],
			['key' => 'alias', 'label' => 'Description'],
			['key' => 'status', 'label' => 'Status', 'nowrap' => true],
			['key' => 'since_text', 'label' => 'Down since', 'nowrap' => true, 'export' => false],
			['key' => 'since', 'label' => 'Down since', 'format' => 'datetime', 'screen' => false],
			['key' => 'for_text', 'label' => 'For', 'nowrap' => true, 'export' => false],
			['key' => 'for', 'label' => 'For (seconds)', 'format' => 'int', 'screen' => false],
			['key' => 'checked_text', 'label' => 'Last checked', 'nowrap' => true, 'export' => false],
			['key' => 'checked', 'label' => 'Last checked', 'format' => 'datetime', 'screen' => false]
		], array_slice($rows, 0, (int) $o['limit']), [
			'sheet' => 'Not up now',
			'empty' => sprintf('All %d matching interfaces with a status item are up.', count($with_status) - $unknown)
		]);

		if (count($rows) > (int) $o['limit']) {
			$result->note(sprintf('%d interfaces are not up; the first %d are listed.', count($rows), (int) $o['limit']));
		}

		$result->note(sprintf('Status as of %s, from each interface\'s latest operational status value: 1 is up, anything else is not. "Down since" is the end of the last hour the link was seen up, from hourly trends going back %d days, so it is accurate to the hour.',
			Format::datetime($now, $tz, 'j M Y H:i'), (int) round(($now - $look_from) / 86400)));

		if ($no_status > 0) {
			$result->note(sprintf('%d matching interface(s) have no operational status item, so they cannot be checked.', $no_status));
		}

		if ($unknown > 0) {
			$result->note(sprintf('%d matching interface(s) have a status item with no value yet, or one Zabbix marks as not supported.', $unknown));
		}

		if ($stale > 0) {
			$result->note(sprintf('%d matching interface(s) have not reported status for more than a day; their last value is used and marked stale.', $stale));
		}

		return $result;
	}

	/** Trend summary of one of a port's items, or null when it has none in the period. */
	private static function stat(array $sum, array $port, string $slot): ?array {
		$id = $port['items'][$slot]['itemid'] ?? null;

		return $id !== null ? ($sum[$id] ?? null) : null;
	}

	/** Approximate count over the period from rate items: average per second times seconds. */
	private static function total(array $sum, array $port, array $slots): ?float {
		$total = null;

		foreach ($slots as $slot) {
			$s = self::stat($sum, $port, $slot);

			if ($s !== null) {
				$total = ($total ?? 0.0) + $s['avg'] * $s['hours'] * 3600;
			}
		}

		return $total !== null ? round($total) : null;
	}

	/**
	 * What a port's link did over the period, from Trends::linkState().
	 *
	 * @return array{label: string, uptime: ?float, down_seconds: float, not_up_end: bool, never_up: bool,
	 *     changed: bool}
	 */
	private static function state(array $link): array {
		$seconds = $link['hours'] * 3600;
		$down = max(0.0, $seconds - $link['up_seconds']);
		// The whole last hour not up: the period ended with the link not up. A last hour
		// that held both is labelled as changing and not counted either way.
		$not_up_end = $link['last_min'] > 1.0 || $link['last_max'] < 1.0;
		$steady = abs($link['last_max'] - $link['last_min']) < 0.001;

		return [
			'label' => $steady ? self::statusLabel($link['last_min']) : 'Changing',
			'uptime' => $seconds > 0 ? $link['up_seconds'] / $seconds * 100 : null,
			'down_seconds' => round($down),
			'not_up_end' => $not_up_end,
			'never_up' => $link['up_hours'] === 0,
			'changed' => $link['touched'] > 0 && $link['up_hours'] > 0
		];
	}

	private static function stateMatches(array $state, string $filter): bool {
		switch ($filter) {
			case 'not_up_any': return $state['down_seconds'] > 0;
			case 'not_up_end': return $state['not_up_end'];
			case 'never_up': return $state['never_up'];
			case 'up_all': return $state['down_seconds'] <= 0;
			default: return true;
		}
	}

	private static function statusLabel(float $value): string {
		$rounded = (int) round($value);

		return self::OPER_STATUS[$rounded] ?? 'Unknown ('.$rounded.')';
	}

	private static function comparator(string $sort): callable {
		if ($sort === 'name') {
			return static fn($a, $b) => strnatcasecmp($a['host'], $b['host']) ?: strnatcasecmp($a['interface'], $b['interface']);
		}

		$key = static function (array $r) use ($sort): ?float {
			switch ($sort) {
				case 'avg_util': return $r['avg_util'];
				case 'peak_traffic': return $r['in_max'] === null ? null : max($r['in_max'], $r['out_max']) * ($r['units'] === 'Bps' ? 8 : 1);
				case 'avg_traffic': return $r['in_avg'] === null ? null : max($r['in_avg'], $r['out_avg']) * ($r['units'] === 'Bps' ? 8 : 1);
				case 'errors': return $r['errors'] ?? null;
				case 'discards': return $r['discards'] ?? null;
				case 'down_hours': return isset($r['down_seconds']) ? (float) $r['down_seconds'] : null;
				case 'uptime': return isset($r['uptime']) ? 100 - $r['uptime'] : null;
				default: return $r['peak_util'];
			}
		};

		// Highest first; interfaces without the value (no speed, no error items) last.
		return static function ($a, $b) use ($key) {
			$x = $key($a);
			$y = $key($b);

			if ($x === null || $y === null) {
				return ($x === null) <=> ($y === null) ?: strnatcasecmp($a['host'], $b['host']);
			}

			return $y <=> $x ?: strnatcasecmp($a['host'].$a['interface'], $b['host'].$b['interface']);
		};
	}

	/** @return array{0: ?float, 1: string} */
	private static function chartValue(array $r, string $sort): array {
		switch ($sort) {
			case 'peak_util': return [$r['peak_util'], '%'];
			case 'avg_util': return [$r['avg_util'], '%'];
			case 'peak_traffic': return [$r['in_max'] === null ? null : max($r['in_max'], $r['out_max']), $r['units']];
			case 'avg_traffic': return [$r['in_avg'] === null ? null : max($r['in_avg'], $r['out_avg']), $r['units']];
			case 'errors': return [$r['errors'] ?? null, ''];
			case 'discards': return [$r['discards'] ?? null, ''];
			case 'down_hours': return [isset($r['down_seconds']) ? $r['down_seconds'] / 3600 : null, 'h'];
			case 'uptime': return [$r['uptime'] ?? null, '%'];
		}

		return [null, ''];
	}
}
