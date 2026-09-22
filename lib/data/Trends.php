<?php declare(strict_types = 1);

namespace Modules\Reporter\Lib\Data;

use Modules\Reporter\Lib\Core\Context;
use Modules\Reporter\Lib\Core\Period;

/**
 * Reads hourly trends, never raw history. A month of trends is ~720 rows per item that
 * Zabbix has already aggregated; the same month of history can be hundreds of times that.
 * Rows are folded into running aggregates as each chunk arrives and then discarded, so
 * memory scales with the number of items, not the number of rows.
 */
final class Trends {

	/**
	 * Period totals per item.
	 *
	 * @return array<string, array{avg: float, max: float, min: float, num: int, hours: int, last: float, last_clock: int}>
	 */
	public static function summary(Context $ctx, array $itemids): array {
		$acc = [];

		self::each($ctx, $itemids, static function (array $t) use (&$acc): void {
			$id = (string) $t['itemid'];
			$num = max(1, (int) $t['num']);
			$avg = (float) $t['value_avg'];
			$clock = (int) $t['clock'];

			if (!isset($acc[$id])) {
				$acc[$id] = ['sum' => 0.0, 'num' => 0, 'max' => -INF, 'min' => INF, 'hours' => 0, 'last' => $avg,
					'last_clock' => $clock];
			}

			$a = &$acc[$id];
			$a['sum'] += $avg * $num;
			$a['num'] += $num;
			$a['max'] = max($a['max'], (float) $t['value_max']);
			$a['min'] = min($a['min'], (float) $t['value_min']);
			$a['hours']++;

			if ($clock >= $a['last_clock']) {
				$a['last'] = $avg;
				$a['last_clock'] = $clock;
			}
		});

		$out = [];

		foreach ($acc as $id => $a) {
			$out[$id] = [
				'avg' => $a['num'] > 0 ? $a['sum'] / $a['num'] : 0.0,
				'max' => $a['max'],
				'min' => $a['min'],
				'num' => $a['num'],
				'hours' => $a['hours'],
				'last' => $a['last'],
				'last_clock' => $a['last_clock']
			];
		}

		return $out;
	}

	/**
	 * Daily averages per item, indexed by day in the period (report timezone).
	 *
	 * @return array<string, array<int, float>>
	 */
	public static function daily(Context $ctx, array $itemids): array {
		$starts = $ctx->period->dayStarts();
		$acc = [];

		self::each($ctx, $itemids, static function (array $t) use (&$acc, $starts): void {
			$id = (string) $t['itemid'];
			$day = Period::dayIndex($starts, (int) $t['clock']);
			$num = max(1, (int) $t['num']);

			$acc[$id][$day][0] = ($acc[$id][$day][0] ?? 0.0) + (float) $t['value_avg'] * $num;
			$acc[$id][$day][1] = ($acc[$id][$day][1] ?? 0) + $num;
		});

		$out = [];

		foreach ($acc as $id => $days) {
			ksort($days);

			foreach ($days as $day => [$sum, $num]) {
				$out[$id][$day] = $sum / $num;
			}
		}

		return $out;
	}

	/**
	 * Items per call shrink as the period grows, so one response stays near
	 * trend_rows_per_call rows whether the report covers a day or a year.
	 */
	private static function each(Context $ctx, array $itemids, callable $fn): void {
		$hours = max(1, (int) ceil($ctx->period->seconds() / 3600));
		$per_call = max(1, min($ctx->limit('chunk_trend_items', 50),
			intdiv($ctx->limit('trend_rows_per_call', 50000), $hours)));

		foreach ($ctx->chunks(array_values($itemids), $per_call) as $chunk) {
			$rows = $ctx->api->call('trend.get', [
				'output' => ['itemid', 'clock', 'num', 'value_min', 'value_avg', 'value_max'],
				'itemids' => $chunk,
				'time_from' => $ctx->period->from,
				'time_till' => $ctx->period->till
			]);

			foreach ($rows as $row) {
				$fn($row);
			}

			unset($rows);
		}
	}
}
