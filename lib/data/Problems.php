<?php declare(strict_types = 1);

namespace Modules\Reporter\Lib\Data;

use Modules\Reporter\Lib\Core\Context;

/**
 * Problem events raised during the period for hosts in scope, with their recovery
 * times. Fetched once per report and shared by every section that needs it.
 *
 * Each problem: eventid, clock, r_clock (null if unresolved at fetch time), severity,
 * name, objectid (trigger), hostids (in-scope hosts only).
 */
final class Problems {

	public static function fetch(Context $ctx): array {
		$period = $ctx->period;
		$problems = [];
		$scope = array_flip($ctx->hostids());

		foreach ($ctx->chunks($ctx->hostids(), $ctx->limit('chunk_hosts', 500)) as $hostids) {
			Paginator::byClock($ctx->api, 'event.get', [
				'output' => ['eventid', 'clock', 'r_eventid', 'severity', 'name', 'objectid'],
				'selectHosts' => ['hostid'],
				'source' => 0,
				'object' => 0,
				'value' => 1,
				'hostids' => $hostids
			], 'eventid', $period->from, $period->till, $ctx->limit('page_limit', 10000),
				static function (array $e) use (&$problems, $scope): void {
					$hostids = [];

					foreach ($e['hosts'] ?? [] as $h) {
						if (isset($scope[$h['hostid']])) {
							$hostids[] = (string) $h['hostid'];
						}
					}

					$id = (string) $e['eventid'];

					if (isset($problems[$id])) {
						$problems[$id]['hostids'] = array_values(array_unique(array_merge($problems[$id]['hostids'], $hostids)));

						return;
					}

					$problems[$id] = [
						'eventid' => $id,
						'clock' => (int) $e['clock'],
						'r_eventid' => (string) $e['r_eventid'],
						'r_clock' => null,
						'severity' => (int) $e['severity'],
						'name' => (string) $e['name'],
						'objectid' => (string) $e['objectid'],
						'hostids' => $hostids
					];
				}
			);
		}

		// Recovery times.
		$r_ids = [];

		foreach ($problems as $p) {
			if ($p['r_eventid'] !== '0' && $p['r_eventid'] !== '') {
				$r_ids[$p['r_eventid']] = $p['eventid'];
			}
		}

		foreach ($ctx->chunks(array_keys($r_ids), $ctx->limit('chunk_ids', 1000)) as $ids) {
			foreach ($ctx->api->call('event.get', ['output' => ['eventid', 'clock'], 'eventids' => $ids]) as $r) {
				$pid = $r_ids[(string) $r['eventid']] ?? null;

				if ($pid !== null) {
					$problems[$pid]['r_clock'] = (int) $r['clock'];
				}
			}
		}

		return $problems;
	}

	/** Seconds the problem was open inside the period. */
	public static function openSeconds(array $p, int $from, int $till): int {
		$end = $p['r_clock'] ?? ($till + 1);
		$end = min($end, $till + 1);

		return max(0, $end - max($p['clock'], $from));
	}
}
