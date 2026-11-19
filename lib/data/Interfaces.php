<?php declare(strict_types = 1);

namespace Modules\Reporter\Lib\Data;

use Modules\Reporter\Lib\Core\Context;
use Modules\Reporter\Lib\Core\Filters;

/**
 * Network interfaces, rebuilt from the items Zabbix's interface discovery creates.
 *
 * The API has no "interface" object for SNMP ports: what exists is a family of items per
 * port, net.if.in[ifHCInOctets.5], net.if.status[ifOperStatus.5] and so on, named
 * "Interface Gi1/0/5(Uplink to core): Bits received". Items are grouped back into ports
 * by host and the index in the key (5 here; for agent keys such as net.if.in["eth0"],
 * the interface name). The port's name and description (ifName, ifAlias) come from the
 * interface and description tags the official templates set, or failing that from the
 * item name.
 *
 * The API cannot see ifDescr: discovery uses it, then it is gone unless a template puts
 * it in the item name or a tag. A tag called ifdescr is read when present.
 *
 * The API search is narrowed by the filter patterns (each port's item names carry its
 * name and description), so a 48-port stack is not read whole to find its two uplinks.
 */
final class Interfaces {

	/** Metric slot => key prefix. Order matters: the longer prefixes first. */
	private const KEYS = [
		'in_errors' => 'net.if.in.errors',
		'out_errors' => 'net.if.out.errors',
		'in_discards' => 'net.if.in.discards',
		'out_discards' => 'net.if.out.discards',
		'in' => 'net.if.in',
		'out' => 'net.if.out',
		'status' => 'net.if.status',
		'speed' => 'net.if.speed'
	];

	/** Agent keys pick the counter with a second parameter: net.if.in[eth0,errors]. */
	private const AGENT_MODES = ['' => '', 'bytes' => '', 'errors' => '_errors', 'dropped' => '_discards'];

	/**
	 * @param array $filter  alias, name, exclude (comma-separated patterns), logic (and|or)
	 * @param ?string[] $slots  the items the caller will read (in, out, status...); only those
	 *     count against the item limit. Null counts every item a port has.
	 * @return array<string, array>  "hostid:index" => [hostid, index, name, alias, descr, items => slot => item]
	 */
	public static function find(Context $ctx, array $filter, ?array $slots = null): array {
		$alias = Filters::patterns((string) ($filter['alias'] ?? ''));
		$name = Filters::patterns((string) ($filter['name'] ?? ''));
		$exclude = Filters::patterns((string) ($filter['exclude'] ?? ''));
		$logic = ($filter['logic'] ?? 'and') === 'or' ? 'or' : 'and';

		if (!$alias && !$name) {
			throw new \InvalidArgumentException('Filter interfaces by description or name. Refusing to read every port on every device.');
		}

		// With "and", every port kept matches the description patterns, so those alone
		// narrow the search. With "or", either list can bring a port in.
		$search = ($logic === 'and' && $alias && $name) ? $alias : array_merge($alias, $name);
		// Status alone needs only status items from the API, not all eight per port.
		$key = $slots === ['status'] ? 'net.if.status*' : 'net.if.*';
		$ports = self::candidates($ctx, $search, $key);
		$out = [];
		$kept = 0;

		foreach ($ports as $id => $port) {
			if (self::verdict($port, $alias, $name, $logic, $exclude) !== 'ok') {
				continue;
			}

			$out[$id] = $port;
			$kept += $slots === null ? count($port['items']) : count(array_intersect_key($port['items'], array_flip($slots)));
		}

		$max = $ctx->limit('max_items', 20000);

		if ($kept > $max) {
			throw new SelectorTooBroad(sprintf(
				'%d interfaces match, %d items to read, over the limit of %d. Narrow the description or name patterns, or the devices, or raise "Items per selector" in the report builder settings.',
				count($out), $kept, $max
			));
		}

		return $out;
	}

	/** Ports built from the interface items whose names match any of the patterns. */
	private static function candidates(Context $ctx, array $search, string $key = 'net.if.*'): array {
		$ports = [];

		// Rows are folded into ports as they arrive and then dropped, so the candidates
		// cost a few fields each, not a full item with tags.
		self::items($ctx, $search, $key, static function (array $item) use (&$ports): void {
			$parsed = self::parseKey((string) $item['key_']);

			if ($parsed === null) {
				return;
			}

			[$slot, $index] = $parsed;
			$id = $item['hostid'].':'.$index;

			if (!isset($ports[$id])) {
				[$if_name, $if_alias] = self::labels((string) $item['name'], $item['tags'] ?? []);
				$ports[$id] = [
					'hostid' => (string) $item['hostid'],
					'index' => $index,
					'name' => $if_name,
					'alias' => $if_alias,
					'descr' => self::tag($item['tags'] ?? [], ['ifdescr', 'ifDescr', 'IFDESCR']) ?? '',
					'items' => []
				];
			}

			// Where a template has both the 32-bit and the 64-bit counter, keep the 64-bit
			// one: the 32-bit counter wraps every 34 seconds at 1 Gbps.
			$existing = $ports[$id]['items'][$slot] ?? null;

			if ($existing === null || (strpos($item['key_'], 'HC') !== false && strpos($existing['key'], 'HC') === false)) {
				$ports[$id]['items'][$slot] = [
					'itemid' => (string) $item['itemid'],
					'key' => (string) $item['key_'],
					'units' => (string) $item['units']
				];
			}
		});

		return $ports;
	}

	/**
	 * Why a port is or is not reported: 'ok', 'no_match' (its description and name do
	 * not match), 'excluded', or 'no_items' (matched, but has neither traffic nor status).
	 */
	private static function verdict(array $port, array $alias, array $name, string $logic, array $exclude): string {
		$names = array_values(array_filter([$port['name'], $port['descr']], 'strlen'));
		$hit_alias = $alias && Filters::matches($port['alias'], $alias);
		$hit_name = $name && (bool) array_filter($names, static fn($n) => Filters::matches($n, $name));

		if ($alias && $name) {
			$keep = $logic === 'and' ? ($hit_alias && $hit_name) : ($hit_alias || $hit_name);
		}
		else {
			$keep = $alias ? $hit_alias : $hit_name;
		}

		if (!$keep) {
			return 'no_match';
		}

		if ($exclude && (Filters::matches($port['alias'], $exclude)
				|| array_filter($names, static fn($n) => Filters::matches($n, $exclude)))) {
			return 'excluded';
		}

		// A port with only a status item is still a port: fabric links and other ports
		// whose templates keep no traffic counters.
		if (!isset($port['items']['in']) && !isset($port['items']['out']) && !isset($port['items']['status'])) {
			return 'no_items';
		}

		return 'ok';
	}

	/**
	 * When nothing matches, say why, in terms of what is actually on the devices: no
	 * item names contain the pattern at all; they do, but on keys that are not interface
	 * keys; interfaces were found, but the text is in their name, not their description;
	 * or everything matching was excluded.
	 *
	 * @return string[]
	 */
	public static function explain(Context $ctx, array $filter): array {
		$alias = Filters::patterns((string) ($filter['alias'] ?? ''));
		$name = Filters::patterns((string) ($filter['name'] ?? ''));
		$exclude = Filters::patterns((string) ($filter['exclude'] ?? ''));
		$logic = ($filter['logic'] ?? 'and') === 'or' ? 'or' : 'and';
		$search = array_merge($alias, $name);
		$shown = implode(', ', array_map(static fn($p) => '"'.$p.'"', $search));
		$ports = self::candidates($ctx, $search);

		if (!$ports) {
			$probe = [];

			foreach ($ctx->chunks($ctx->hostids(), $ctx->limit('chunk_hosts', 500)) as $hostids) {
				foreach (array_unique(array_map([self::class, 'apiPattern'], $search)) as $pattern) {
					foreach ($ctx->api->call('item.get', [
						'output' => ['itemid', 'name', 'key_'],
						'hostids' => $hostids,
						'search' => ['name' => $pattern],
						'searchWildcardsEnabled' => true,
						'limit' => 200
					]) as $item) {
						$probe[(string) $item['itemid']] = $item;
					}
				}

				if (count($probe) >= 200) {
					break;
				}
			}

			if (!$probe) {
				return [sprintf('No item on the devices in scope has a name containing %s. The patterns are matched against what Zabbix shows in item names (for example "Interface Gi1/0/1(Uplink): Bits received"); text that is only in the device\'s own config is not visible. Check the spelling, and that the devices are in the groups this report covers.', $shown)];
			}

			$keys = [];

			foreach ($probe as $item) {
				$keys[preg_replace('/\[.*$/s', '[...]', (string) $item['key_'])] = true;
			}

			$sample = array_slice(array_values($probe), 0, 3);

			return [sprintf('%s%d item(s) have names containing %s, but none uses an interface key (net.if.in, net.if.out, net.if.status...), so this section cannot treat them as ports. Their keys look like %s. For example: %s. A section that reads these keys needs to know what "up" looks like in them; send the key and a sample value and it can be added.',
				count($probe) >= 200 ? 'At least ' : '', count($probe), $shown,
				implode(', ', array_slice(array_keys($keys), 0, 5)),
				implode('; ', array_map(static fn($i) => '"'.$i['name'].'" ('.$i['key_'].')', $sample)))];
		}

		$verdicts = [];

		foreach ($ports as $port) {
			$verdicts[self::verdict($port, $alias, $name, $logic, $exclude)][] = $port;
		}

		$lines = [];
		$miss = $verdicts['no_match'] ?? [];

		if ($miss) {
			$sample = array_slice($miss, 0, 4);
			$read = implode('; ', array_map(static fn($p) => sprintf('name "%s", description "%s"', $p['name'], $p['alias']),
				$sample));
			$hint = '';

			// The pattern found the items, so it is somewhere in the item name. If it is in
			// the part read as the interface name, say so.
			if ($alias && !$name && array_filter($miss, static fn($p) => Filters::matches($p['name'], $alias))) {
				$hint = ' The text is in the interface name, not its description: put the pattern in "Interface name matches" instead.';
			}
			elseif ($name && !$alias && array_filter($miss, static fn($p) => Filters::matches($p['alias'], $name))) {
				$hint = ' The text is in the description, not the interface name: put the pattern in "Description (ifAlias) matches" instead.';
			}
			elseif ($alias && $name && $logic === 'and') {
				$hint = ' Both patterns must match one interface; "Either one is enough" may be what is wanted.';
			}
			else {
				$hint = ' The pattern appears elsewhere in the item name, not in the part read as the interface name or description.';
			}

			$lines[] = sprintf('%d interface(s) have item names containing %s, but none matched as configured. They read as: %s.%s',
				count($miss), $shown, $read, $hint);
		}

		if ($verdicts['excluded'] ?? []) {
			$lines[] = sprintf('%d matching interface(s) were left out by "Skip interfaces".', count($verdicts['excluded']));
		}

		if ($verdicts['no_items'] ?? []) {
			$lines[] = sprintf('%d matching interface(s) have no traffic or operational status items to report on.',
				count($verdicts['no_items']));
		}

		return $lines ?: ['Interfaces match, but none on the devices in scope.'];
	}

	/**
	 * Latest value of each item, as Zabbix holds it now: no history or trend read.
	 *
	 * @return array<string, array{value: ?float, clock: int, supported: bool}>
	 */
	public static function latest(Context $ctx, array $itemids): array {
		$out = [];

		foreach ($ctx->chunks(array_values(array_unique($itemids)), $ctx->limit('chunk_ids', 1000)) as $ids) {
			foreach ($ctx->api->call('item.get', [
				'output' => ['itemid', 'lastvalue', 'lastclock', 'state'],
				'itemids' => $ids
			]) as $item) {
				$clock = (int) ($item['lastclock'] ?? 0);
				$out[(string) $item['itemid']] = [
					'value' => $clock > 0 && is_numeric($item['lastvalue'] ?? null) ? (float) $item['lastvalue'] : null,
					'clock' => $clock,
					'supported' => (int) ($item['state'] ?? 0) === 0
				];
			}
		}

		return $out;
	}

	/**
	 * Interface items on in-scope hosts whose names match any of the patterns, handed to
	 * $fn one at a time. The ceiling here is looser than max_items: with "match both",
	 * the search can only narrow on one side, and most candidates are dropped once the
	 * other side is checked. max_items applies to what is kept.
	 */
	private static function items(Context $ctx, array $patterns, string $key, callable $fn): void {
		$ceiling = $ctx->limit('max_items', 20000) * 5;
		$seen = [];

		foreach ($ctx->chunks($ctx->hostids(), $ctx->limit('chunk_hosts', 500)) as $hostids) {
			foreach (array_unique(array_map([self::class, 'apiPattern'], $patterns)) as $pattern) {
				$rows = $ctx->api->call('item.get', [
					'output' => ['itemid', 'hostid', 'name', 'key_', 'units', 'value_type'],
					'selectTags' => ['tag', 'value'],
					'hostids' => $hostids,
					'monitored' => true,
					'filter' => ['value_type' => [0, 3], 'status' => 0],
					'search' => ['key_' => $key, 'name' => $pattern],
					'searchWildcardsEnabled' => true,
					'limit' => $ceiling + 1
				]);

				foreach ($rows as $item) {
					if (!isset($seen[$item['itemid']])) {
						$seen[$item['itemid']] = true;
						$fn($item);
					}
				}

				unset($rows);

				if (count($seen) > $ceiling) {
					throw new SelectorTooBroad(sprintf(
						'More than %d interface items match these patterns before filtering. Narrow the description or name patterns, or the devices, or raise "Items per selector" in the report builder settings (this allows five times that).',
						$ceiling
					));
				}
			}
		}
	}

	/**
	 * Patterns are matched exactly against the port's name and description afterwards;
	 * this only has to find a superset. Item names wrap the port name ("Interface X(Y):
	 * Bits received"), so the API pattern floats, and the API knows no "?".
	 */
	public static function apiPattern(string $pattern): string {
		$p = trim(str_replace('?', '*', $pattern), '*');

		return $p === '' ? '*' : '*'.$p.'*';
	}

	/** @return array{0: string, 1: string}|null  metric slot and interface index */
	public static function parseKey(string $key): ?array {
		foreach (self::KEYS as $slot => $prefix) {
			$open = strlen($prefix);

			if (strncmp($key, $prefix.'[', $open + 1) !== 0 || substr($key, -1) !== ']') {
				continue;
			}

			$params = array_map(static fn($p) => trim($p, " \t\""), self::splitParams(substr($key, $open + 1, -1)));
			$first = $params[0] ?? '';

			if ($first === '') {
				return null;
			}

			// SNMP: ifHCInOctets.5, ifOperStatus.10101, or an OID ending in the index.
			if (preg_match('/^[A-Za-z][A-Za-z0-9]*\.(\d+(?:\.\d+)*)$/', $first, $m)) {
				return [$slot, $m[1]];
			}

			if (preg_match('/^[.\d]+\.(\d+)$/', $first, $m) && substr_count($first, '.') > 4) {
				return [$slot, $m[1]];
			}

			// Agent: net.if.in[eth0] / net.if.in[eth0,errors]. Only the plain in and out
			// keys carry a mode; anything else (packets, overruns) is not reported here.
			if ($slot === 'in' || $slot === 'out') {
				$mode = strtolower($params[1] ?? '');

				if (!array_key_exists($mode, self::AGENT_MODES)) {
					return null;
				}

				return [$slot.self::AGENT_MODES[$mode], $first];
			}

			return [$slot, $first];
		}

		return null;
	}

	/** Split item key parameters on commas outside quotes. */
	private static function splitParams(string $s): array {
		$params = [];
		$cur = '';
		$quoted = false;
		$len = strlen($s);

		for ($i = 0; $i < $len; $i++) {
			$c = $s[$i];

			if ($c === '"' && ($i === 0 || $s[$i - 1] !== '\\')) {
				$quoted = !$quoted;
			}

			if ($c === ',' && !$quoted) {
				$params[] = $cur;
				$cur = '';

				continue;
			}

			$cur .= $c;
		}

		$params[] = $cur;

		return $params;
	}

	/**
	 * ifName and ifAlias: from the tags when the template sets them, otherwise from
	 * "Interface Gi1/0/1(Uplink to core): Bits received".
	 *
	 * @return array{0: string, 1: string}
	 */
	public static function labels(string $item_name, array $tags): array {
		$name = '';
		$alias = '';

		// 6.x and older: "Interface Gi1/0/1(Uplink): ...". 7.x walk-based templates:
		// "Interface [Gi1/0/1(Uplink)]: ...".
		if (preg_match('/^Interface\s+\[?(.+?)(?:\((.*)\))?\]?:\s/u', $item_name, $m)) {
			$name = trim($m[1]);
			$alias = trim($m[2] ?? '');
		}
		elseif (($pos = strpos($item_name, ':')) !== false) {
			$name = trim(substr($item_name, 0, $pos));
		}
		else {
			$name = $item_name;
		}

		$tag_name = self::tag($tags, ['interface']);
		$tag_alias = self::tag($tags, ['description']);

		return [self::resolved($tag_name ?? $name), self::resolved($tag_alias ?? $alias)];
	}

	/**
	 * Some stock templates name {#IFALIAS} in the item name and the description tag but
	 * never discover it, so the literal macro is what the API returns. That is no alias.
	 */
	private static function resolved(string $value): string {
		return preg_match('/^\{#[A-Z0-9_.]+\}$/', $value) ? '' : $value;
	}

	private static function tag(array $tags, array $names): ?string {
		foreach ($tags as $t) {
			$value = trim((string) ($t['value'] ?? ''));

			if (in_array($t['tag'] ?? '', $names, true) && $value !== '' && self::resolved($value) !== '') {
				return $value;
			}
		}

		return null;
	}
}
