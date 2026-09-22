<?php declare(strict_types = 1);

namespace Modules\Reporter\Lib\Data;

use Modules\Reporter\Lib\Core\Context;
use Modules\Reporter\Lib\Core\TagFilter;

/**
 * Numeric, enabled items on in-scope hosts matching a selector. Selection by item tags is
 * the portable way: the official 7.x templates tag items with component:cpu,
 * component:storage and so on, for SNMP and agent alike. Key and name patterns take
 * comma-separated wildcards and are matched exactly against the whole key or name after
 * the API narrows the candidates.
 */
final class Items {

	/**
	 * @param array $selector  tags (TagFilter array), keys (string), names (string)
	 * @return array<string, array>  itemid => item
	 */
	public static function find(Context $ctx, array $selector): array {
		$tags = TagFilter::toApi($selector['tags'] ?? []);
		$key_patterns = self::split((string) ($selector['keys'] ?? ''));
		$name_patterns = self::split((string) ($selector['names'] ?? ''));

		if (!$tags && !$key_patterns && !$name_patterns) {
			throw new \InvalidArgumentException('Select items by tag, key or name. Refusing to read every item on every host.');
		}

		$max = $ctx->limit('max_items', 20000);
		$items = [];

		// One query per key/name pattern keeps each API search a simple LIKE; with no
		// patterns, a single tag-only query.
		$searches = [];

		foreach ($key_patterns as $p) {
			$searches[] = ['key_' => $p];
		}

		if (!$searches) {
			foreach ($name_patterns as $p) {
				$searches[] = ['name' => $p];
			}
		}

		if (!$searches) {
			$searches[] = null;
		}

		foreach ($ctx->chunks($ctx->hostids(), $ctx->limit('chunk_hosts', 500)) as $hostids) {
			foreach ($searches as $search) {
				$params = [
					'output' => ['itemid', 'hostid', 'name', 'key_', 'units', 'value_type'],
					'hostids' => $hostids,
					'monitored' => true,
					'filter' => ['value_type' => [0, 3], 'status' => 0],
					'limit' => $max + 1
				];

				if ($tags) {
					$params['tags'] = $tags;
					$params['evaltype'] = 0;
				}

				if ($search !== null) {
					$params['search'] = $search;
					$params['searchWildcardsEnabled'] = true;
				}

				foreach ($ctx->api->call('item.get', $params) as $item) {
					if ($key_patterns && !self::matchesAny($item['key_'], $key_patterns)) {
						continue;
					}

					if ($name_patterns && !self::matchesAny($item['name'], $name_patterns)) {
						continue;
					}

					$items[(string) $item['itemid']] = [
						'itemid' => (string) $item['itemid'],
						'hostid' => (string) $item['hostid'],
						'name' => (string) $item['name'],
						'key' => (string) $item['key_'],
						'units' => (string) $item['units'],
						'value_type' => (int) $item['value_type']
					];
				}

				if (count($items) > $max) {
					throw new \Modules\Reporter\Lib\Api\BudgetExceeded(sprintf(
						'More than %d items match this selector. Tighten the tags or patterns.', $max
					));
				}
			}
		}

		return $items;
	}

	private static function split(string $list): array {
		return array_values(array_filter(array_map('trim', explode(',', $list)), 'strlen'));
	}

	private static function matchesAny(string $subject, array $patterns): bool {
		foreach ($patterns as $pattern) {
			if (fnmatch($pattern, $subject, FNM_NOESCAPE)) {
				return true;
			}
		}

		return false;
	}
}
