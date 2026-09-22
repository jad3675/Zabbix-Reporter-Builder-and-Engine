<?php declare(strict_types = 1);

use Modules\Reporter\Lib\Api\ApiClient;
use Modules\Reporter\Lib\Api\ApiException;

/**
 * An in-memory Zabbix API good enough to run every built-in section: host groups, hosts
 * with tags, problem and recovery events, alerts, numeric items with tags and hourly
 * trends, maintenance and media types. Deterministic (seeded) so tests can assert.
 */
final class FakeApi implements ApiClient {

	public array $calls = [];
	private array $groups = [];
	private array $hosts = [];
	private array $events = [];
	private array $alerts = [];
	private array $items = [];
	private array $trends = [];
	private array $maintenances = [];
	private int $from;
	private int $till;

	public function __construct(int $from, int $till, int $n_hosts = 60, int $seed = 7) {
		mt_srand($seed);
		$this->from = $from;
		$this->till = $till;
		$sites = ['Burnet', 'Liberty', 'Mason', 'Anderson'];
		$this->groups = [
			'10' => ['groupid' => '10', 'name' => 'CCH/Network'],
			'11' => ['groupid' => '11', 'name' => 'CCH/Servers'],
			'12' => ['groupid' => '12', 'name' => 'Lab']
		];

		for ($i = 1; $i <= $n_hosts; $i++) {
			$id = (string) (10000 + $i);
			$is_server = $i % 3 === 0;
			$this->hosts[$id] = [
				'hostid' => $id,
				'host' => sprintf('%s-%03d', $is_server ? 'srv' : 'sw', $i),
				'name' => sprintf('%s-%s-%03d', $is_server ? 'srv' : 'core-sw', strtolower($sites[$i % 4]), $i),
				'groupid' => $is_server ? '11' : '10',
				'tags' => [['tag' => 'site', 'value' => $sites[$i % 4]]]
			];
		}

		$this->hosts['99999'] = ['hostid' => '99999', 'host' => 'lab1', 'name' => 'lab1', 'groupid' => '12', 'tags' => []];

		// Problems: noisy hosts get more; a few span the whole period; a burst shares one second.
		$eid = 1000;
		$aid = 1;
		$burst = $from + 5 * 86400;

		foreach ($this->hosts as $hid => $h) {
			if ($h['groupid'] === '12') {
				continue;
			}

			$n = (int) (($hid % 7 === 0 ? 25 : 3) * mt_rand(0, 100) / 100);

			for ($k = 0; $k < $n; $k++) {
				$clock = ($k === 0 && $hid % 11 === 0) ? $burst : mt_rand($from, $till);
				$sev = [1, 2, 2, 3, 3, 4, 5][mt_rand(0, 6)];
				$trigger = (string) (500 + ($hid % 9) * 10 + $k % 3);
				$eid++;
				$r = mt_rand(0, 10) > 1 ? min($clock + mt_rand(60, 6 * 3600), $till + 86400) : 0;
				$rid = 0;

				if ($r) {
					$rid = ++$eid;
					$this->events[$rid] = ['eventid' => (string) $rid, 'clock' => $r, 'value' => 0, 'r_eventid' => '0',
						'severity' => 0, 'name' => '', 'objectid' => $trigger, 'hosts' => [$hid]];
				}

				$pid = $eid + 100000;
				$this->events[$pid] = ['eventid' => (string) $pid, 'clock' => $clock, 'value' => 1,
					'r_eventid' => (string) $rid, 'severity' => $sev,
					'name' => ['High CPU utilization', 'Interface Gi1/0/1: link down', 'Unavailable by ICMP ping'][$k % 3],
					'objectid' => $trigger, 'hosts' => [$hid]];

				foreach ([0, 1] as $m) {
					if (mt_rand(0, 3) === 0) {
						continue;
					}

					$this->alerts[] = ['alertid' => (string) $aid++, 'eventid' => (string) $pid, 'clock' => $clock + 30,
						'status' => mt_rand(0, 30) === 0 ? 2 : 1, 'mediatypeid' => (string) (1 + $m), 'alerttype' => 0,
						'hosts' => [$hid]];
				}
			}
		}

		// Items and hourly trends.
		$iid = 50000;

		foreach ($this->hosts as $hid => $h) {
			$defs = [
				['CPU utilization', 'system.cpu.util', '%', [['tag' => 'component', 'value' => 'cpu']], 'cpu'],
				['ICMP ping', 'icmpping', '', [['tag' => 'component', 'value' => 'health']], 'ping'],
				['Interface Gi1/0/1: Bits received', 'net.if.in[ifHCInOctets.1]', 'bps',
					[['tag' => 'component', 'value' => 'network']], 'net']
			];

			if ($h['groupid'] === '11') {
				$defs[] = ['FS [/var]: Space: Used, in %', 'vfs.fs.dependent.size[/var,pused]', '%',
					[['tag' => 'component', 'value' => 'storage']], 'fs'];
				$defs[] = ['FS [/]: Space: Used, in %', 'vfs.fs.dependent.size[/,pused]', '%',
					[['tag' => 'component', 'value' => 'storage']], 'fs_flat'];
			}

			foreach ($defs as [$name, $key, $units, $tags, $kind]) {
				$id = (string) ++$iid;
				$this->items[$id] = ['itemid' => $id, 'hostid' => $hid, 'name' => $name, 'key_' => $key, 'units' => $units,
					'value_type' => $kind === 'ping' ? 3 : 0, 'status' => 0, 'tags' => $tags];

				$this->trends[$id] = [$kind, mt_rand(5, 60), mt_rand(0, 100) / 100,
					$hid % 13 === 0 ? mt_rand(0, 500) : -1];
			}
		}

		$this->maintenances = [
			['maintenanceid' => '1', 'name' => 'Core switch firmware', 'maintenance_type' => 0,
				'active_since' => $from + 3 * 86400, 'active_till' => $from + 4 * 86400,
				'hosts' => [['hostid' => '10002'], ['hostid' => '10004']], 'hostgroups' => [],
				'timeperiods' => [['timeperiod_type' => 0, 'period' => 7200]]],
			['maintenanceid' => '2', 'name' => 'Server patching', 'maintenance_type' => 1,
				'active_since' => $from - 90 * 86400, 'active_till' => $till + 90 * 86400,
				'hosts' => [], 'hostgroups' => [['groupid' => '11']],
				'timeperiods' => [['timeperiod_type' => 3, 'period' => 14400]]],
			['maintenanceid' => '3', 'name' => 'Old window', 'maintenance_type' => 0,
				'active_since' => $from - 90 * 86400, 'active_till' => $from - 80 * 86400,
				'hosts' => [['hostid' => '10002']], 'hostgroups' => [], 'timeperiods' => []]
		];
	}

	public function identity(): string {
		return 'fake';
	}

	public function call(string $method, array $p): array {
		$this->calls[] = $method;

		switch ($method) {
			case 'hostgroup.get': return $this->hostgroups($p);
			case 'host.get': return $this->hostGet($p);
			case 'event.get': return $this->eventGet($p);
			case 'alert.get':
				// Mirror 7.4's validation: filter only accepts these fields.
				foreach (array_keys($p['filter'] ?? []) as $f) {
					if (!in_array($f, ['alertid', 'actionid', 'eventid', 'userid', 'mediatypeid', 'status', 'acknowledgeid'], true)) {
						throw new ApiException('Invalid parameter "/filter": unexpected parameter "'.$f.'".');
					}
				}

				return $this->page($this->filterByHostTime($this->alerts, $p), $p, 'alertid');
			case 'item.get': return $this->itemGet($p);
			case 'trend.get': return $this->trendGet($p);
			case 'mediatype.get': return $this->mediatypeGet($p);
			case 'maintenance.get': return $this->maintenanceGet($p);
		}

		throw new ApiException('FakeApi does not implement '.$method);
	}

	private function hostgroups(array $p): array {
		$out = [];

		foreach ($this->groups as $g) {
			if (isset($p['filter']['name']) && $g['name'] !== $p['filter']['name']) {
				continue;
			}

			if (isset($p['search']['name']) && !fnmatch('*'.$p['search']['name'].'*', $g['name'])) {
				continue;
			}

			$out[] = $g;
		}

		return $out;
	}

	private function hostGet(array $p): array {
		$out = [];

		foreach ($this->hosts as $h) {

			if (isset($p['groupids']) && !in_array($h['groupid'], $p['groupids'], true)) {
				continue;
			}

			if (isset($p['tags'])) {
				$ok = true;

				foreach ($p['tags'] as $t) {
					$has = false;

					foreach ($h['tags'] as $ht) {
						if ($ht['tag'] === $t['tag'] && ($t['operator'] === 4 || $ht['value'] === $t['value'])) {
							$has = true;
						}
					}

					$ok = $ok && $has;
				}

				if (!$ok) {
					continue;
				}
			}

			$out[] = ['hostid' => $h['hostid'], 'host' => $h['host'], 'name' => $h['name'], 'tags' => $h['tags'],
				'hostgroups' => [['groupid' => $h['groupid']]]];
		}

		return array_slice($out, 0, $p['limit'] ?? PHP_INT_MAX);
	}

	private function eventGet(array $p): array {
		if (isset($p['eventids'])) {
			$ids = array_flip(array_map('strval', $p['eventids']));

			return array_values(array_map(static fn($e) => ['eventid' => $e['eventid'], 'clock' => $e['clock']],
				array_filter($this->events, static fn($e) => isset($ids[$e['eventid']]))));
		}

		$rows = array_filter($this->events, static fn($e) => $e['value'] === ($p['value'] ?? 1));

		return $this->page($this->filterByHostTime(array_values($rows), $p), $p, 'eventid');
	}

	private function filterByHostTime(array $rows, array $p): array {
		$hostids = isset($p['hostids']) ? array_flip($p['hostids']) : null;
		$out = [];

		foreach ($rows as $r) {
			if ($r['clock'] < ($p['time_from'] ?? 0) || $r['clock'] > ($p['time_till'] ?? PHP_INT_MAX)) {
				continue;
			}

			$hosts = array_values(array_filter($r['hosts'], static fn($h) => $hostids === null || isset($hostids[$h])));

			if (!$hosts) {
				continue;
			}

			$row = $r;
			$row['hosts'] = array_map(static fn($h) => ['hostid' => $h], $r['hosts']);
			$out[] = $row;
		}

		return $out;
	}

	private function page(array $rows, array $p, string $id): array {
		usort($rows, static fn($a, $b) => [$a['clock'], (int) $a[$id]] <=> [$b['clock'], (int) $b[$id]]);

		return array_slice($rows, 0, $p['limit'] ?? PHP_INT_MAX);
	}

	private function itemGet(array $p): array {
		$hostids = array_flip($p['hostids'] ?? []);
		$out = [];

		foreach ($this->items as $i) {
			if (!isset($hostids[$i['hostid']])) {
				continue;
			}

			if (isset($p['search']['key_']) && !fnmatch('*'.$p['search']['key_'].'*', $i['key_'], FNM_NOESCAPE)) {
				continue;
			}

			if (isset($p['search']['name']) && !fnmatch('*'.$p['search']['name'].'*', $i['name'], FNM_NOESCAPE)) {
				continue;
			}

			foreach ($p['tags'] ?? [] as $t) {
				$has = false;

				foreach ($i['tags'] as $it) {
					$has = $has || ($it['tag'] === $t['tag'] && ($t['operator'] === 4 || $it['value'] === $t['value']));
				}

				if (!$has) {
					continue 2;
				}
			}

			$out[] = $i;
		}

		return array_slice($out, 0, $p['limit'] ?? PHP_INT_MAX);
	}

	/** Hourly trends generated on demand, so large fleets do not live in the fake's memory. */
	private function trendGet(array $p): array {
		$out = [];

		foreach ($p['itemids'] as $id) {
			if (!isset($this->trends[$id])) {
				continue;
			}

			[$kind, $base, $growth, $outage_hour] = $this->trends[$id];
			$start = $this->from - ($this->from % 3600);

			for ($t = $start, $hour = 0; $t <= $this->till; $t += 3600, $hour++) {
				if ($t < $p['time_from'] || $t > $p['time_till']) {
					continue;
				}

				$noise = (crc32($id.':'.$hour) % 1000) / 100;

				switch ($kind) {
					case 'cpu': $v = $base + 15 * sin($hour / 24 * M_PI * 2) + $noise; break;
					case 'ping': $v = ($outage_hour >= 0 && $hour >= $outage_hour && $hour < $outage_hour + 5) ? 0.2 : 1.0; break;
					case 'net': $v = $base * 1e7 * (1 + sin($hour / 24 * M_PI * 2) / 2); break;
					case 'fs': $v = min(99, 40 + $base * 0.6 + $growth * $hour / 24); break;
					default: $v = 35.0;
				}

				$v = max(0.0, (float) $v);
				$out[] = ['itemid' => $id, 'clock' => $t, 'num' => 60, 'value_min' => $v * 0.9, 'value_avg' => $v,
					'value_max' => $kind === 'ping' ? 1 : $v * 1.2];
			}
		}

		return $out;
	}

	private function mediatypeGet(array $p): array {
		$smtp = ['smtp_server' => 'smtp.encore.example', 'smtp_port' => '587', 'smtp_helo' => '',
			'smtp_email' => 'Zabbix <zabbix@encore.example>', 'smtp_security' => '1', 'smtp_verify_peer' => '1',
			'smtp_verify_host' => '1', 'username' => 'zabbix', 'passwd' => 'media-secret', 'status' => '0'];
		$all = [
			['mediatypeid' => '1', 'name' => 'Email (HTML)', 'type' => '0', 'smtp_authentication' => '1'] + $smtp,
			['mediatypeid' => '2', 'name' => 'HaloPSA webhook', 'type' => '4', 'smtp_authentication' => '0'] + $smtp,
			['mediatypeid' => '3', 'name' => 'Office 365', 'type' => '0', 'smtp_authentication' => '2'] + $smtp
		];
		$out = [];

		foreach ($all as $m) {
			if (isset($p['mediatypeids']) && !in_array($m['mediatypeid'], array_map('strval', (array) $p['mediatypeids']), true)) {
				continue;
			}

			if (isset($p['filter']['type']) && (string) $m['type'] !== (string) $p['filter']['type']) {
				continue;
			}

			$out[] = $m;
		}

		return $out;
	}

	private function maintenanceGet(array $p): array {
		$out = [];

		foreach ($this->maintenances as $m) {
			$hit = false;

			foreach ($m['hosts'] as $h) {
				$hit = $hit || in_array($h['hostid'], $p['hostids'] ?? [], true);
			}

			foreach ($m['hostgroups'] as $g) {
				$hit = $hit || in_array($g['groupid'], $p['groupids'] ?? [], true);
			}

			if ($hit) {
				$out[] = $m;
			}
		}

		return $out;
	}
}
