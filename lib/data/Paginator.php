<?php declare(strict_types = 1);

namespace Modules\Reporter\Lib\Data;

use Modules\Reporter\Lib\Api\ApiClient;

/**
 * Walks a time range in fixed-size pages ordered by clock, so no single call asks the
 * database for an unbounded result set and nothing is lost to a "limit" silently
 * truncating it.
 *
 * Each page resumes at the last clock seen; rows already delivered at that boundary
 * second are skipped when they come back. If a full page is entirely one second (more
 * rows in one second than a page holds), that single second is fetched on its own
 * without a limit and the cursor moves past it. Progress is guaranteed and no row is
 * dropped.
 */
final class Paginator {

	/**
	 * @return array{rows: int, pages: int}
	 */
	public static function byClock(ApiClient $api, string $method, array $params, string $id_field, int $from,
			int $till, int $page_limit, callable $on_row): array {
		$cursor = $from;
		$seen = [];
		$rows = 0;
		$pages = 0;
		$order = ['sortfield' => ['clock', $id_field], 'sortorder' => 'ASC'];

		$emit = static function (array $page) use (&$seen, &$rows, $id_field, $on_row): void {
			foreach ($page as $row) {
				$id = (string) $row[$id_field];

				if (isset($seen[$id])) {
					continue;
				}

				$seen[$id] = true;
				$rows++;
				$on_row($row);
			}
		};

		while ($cursor <= $till) {
			$page = $api->call($method, $params + $order + [
				'time_from' => $cursor,
				'time_till' => $till,
				'limit' => $page_limit
			]);
			$pages++;

			if (count($page) < $page_limit) {
				$emit($page);

				break;
			}

			$first = (int) $page[0]['clock'];
			$last = (int) $page[count($page) - 1]['clock'];

			if ($first === $last) {
				$single = $api->call($method, $params + $order + ['time_from' => $last, 'time_till' => $last]);
				$pages++;
				$emit($single);
				$cursor = $last + 1;
			}
			else {
				$emit($page);
				$cursor = $last;
			}

			// Only ids at or after the cursor can come back; forget the rest.
			foreach ($page as $row) {
				if ((int) $row['clock'] < $cursor) {
					unset($seen[(string) $row[$id_field]]);
				}
			}
		}

		return ['rows' => $rows, 'pages' => $pages];
	}
}
