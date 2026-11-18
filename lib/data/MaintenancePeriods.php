<?php declare(strict_types = 1);

namespace Modules\Reporter\Lib\Data;

use DateTimeImmutable;
use DateTimeZone;

/**
 * When a maintenance was actually in effect. Zabbix keeps no history of this: a host
 * knows only the maintenance it is in now. What the API does keep is the schedule, so
 * the intervals are worked out from it the way the server does: one-time periods, and
 * daily, weekly and monthly ones repeating from the window's "active since", each
 * starting at a local time of day, all cut to the window's own active since and till.
 *
 * The server reads those local times in its own time zone. The report's time zone is
 * used here; where the two differ, recurring windows shift by the difference.
 */
final class MaintenancePeriods {

	public const ONE_TIME = 0;
	public const DAILY = 2;
	public const WEEKLY = 3;
	public const MONTHLY = 4;

	/** Never look further back than this for a period that began before the report. */
	private const MAX_LOOKBACK = 400 * 86400;

	/**
	 * Intervals during which $m was in effect and which overlap [$from, $till], merged and
	 * clipped to that range.
	 *
	 * @return array<int, array{0: int, 1: int}>
	 */
	public static function intervals(array $m, int $from, int $till, string $timezone): array {
		$since = (int) ($m['active_since'] ?? 0);
		$until = (int) ($m['active_till'] ?? 0);
		$lo = max($from, $since);
		$hi = $until > 0 ? min($till, $until) : $till;

		if ($hi <= $lo) {
			return [];
		}

		$tz = new DateTimeZone($timezone);
		$out = [];

		foreach ($m['timeperiods'] ?? [] as $tp) {
			$length = max(0, (int) ($tp['period'] ?? 0));

			if ($length === 0) {
				continue;
			}

			foreach (self::starts($tp, $since, $lo - min($length, self::MAX_LOOKBACK), $hi, $tz) as $start) {
				$a = max($start, $lo);
				$b = min($start + $length, $hi);

				if ($b > $a) {
					$out[] = [$a, $b];
				}
			}
		}

		return self::merge($out);
	}

	/** Seconds covered by a set of intervals. */
	public static function seconds(array $intervals): int {
		$total = 0;

		foreach ($intervals as [$a, $b]) {
			$total += $b - $a;
		}

		return $total;
	}

	/** Whether $clock falls inside one of the intervals (start inclusive, end exclusive). */
	public static function contains(array $intervals, int $clock): bool {
		foreach ($intervals as [$a, $b]) {
			if ($clock >= $a && $clock < $b) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Union of several interval lists.
	 *
	 * @param array<int, array<int, array{0: int, 1: int}>> $lists
	 */
	public static function union(array $lists): array {
		return self::merge($lists ? array_merge(...array_values($lists)) : []);
	}

	/**
	 * Whether a problem's tags fall under a maintenance's tag conditions. A maintenance
	 * with no tags covers every problem on its hosts. Evaltype 0 is And/Or: conditions on
	 * the same tag name are ORed, different names ANDed. Evaltype 2 is Or.
	 */
	public static function tagsMatch(array $m, array $problem_tags): bool {
		$conditions = $m['tags'] ?? [];

		if (!$conditions) {
			return true;
		}

		$hit = static function (array $c) use ($problem_tags): bool {
			foreach ($problem_tags as $t) {
				if ((string) $t['tag'] !== (string) $c['tag']) {
					continue;
				}

				$value = (string) ($t['value'] ?? '');
				$want = (string) ($c['value'] ?? '');

				// Operator 0 is "equals"; 2, the default, is "contains".
				if ((int) ($c['operator'] ?? 2) === 0 ? $value === $want : ($want === '' || stripos($value, $want) !== false)) {
					return true;
				}
			}

			return false;
		};

		if ((int) ($m['tags_evaltype'] ?? 0) === 2) {
			foreach ($conditions as $c) {
				if ($hit($c)) {
					return true;
				}
			}

			return false;
		}

		$by_name = [];

		foreach ($conditions as $c) {
			$by_name[(string) $c['tag']][] = $c;
		}

		foreach ($by_name as $group) {
			if (!array_filter($group, $hit)) {
				return false;
			}
		}

		return true;
	}

	/** Start times of one time period between $lo and $hi. */
	private static function starts(array $tp, int $since, int $lo, int $hi, DateTimeZone $tz): array {
		$type = (int) ($tp['timeperiod_type'] ?? 0);

		if ($type === self::ONE_TIME) {
			$start = (int) ($tp['start_date'] ?? 0);

			// Clipping in intervals() drops one that ended before the range.
			return $start > 0 && $start < $hi ? [$start] : [];
		}

		$every = max(1, (int) ($tp['every'] ?? 1));
		$start_time = (int) ($tp['start_time'] ?? 0);
		$anchor = (new DateTimeImmutable('@'.$since))->setTimezone($tz)->setTime(0, 0);
		$anchor_week = $anchor->modify('-'.((int) $anchor->format('N') - 1).' days');
		$day = (new DateTimeImmutable('@'.max($lo, $since)))->setTimezone($tz)->setTime(0, 0)->modify('-1 day');
		$out = [];

		for ($guard = 0; $guard < 800; $guard++) {
			$midnight = $day->getTimestamp();

			if ($midnight >= $hi) {
				break;
			}

			if (self::occursOn($type, $tp, $every, $day, $anchor, $anchor_week)) {
				// The time of day in seconds, added to local midnight so that it lands on the
				// wall-clock time even on a day when the clocks change.
				$start = $day->setTime(intdiv($start_time, 3600), intdiv($start_time % 3600, 60), $start_time % 60)
					->getTimestamp();

				if ($start >= $since && $start < $hi) {
					$out[] = $start;
				}
			}

			$day = $day->modify('+1 day');
		}

		return $out;
	}

	private static function occursOn(int $type, array $tp, int $every, DateTimeImmutable $day,
			DateTimeImmutable $anchor, DateTimeImmutable $anchor_week): bool {
		switch ($type) {
			case self::DAILY:
				$days = (int) round(($day->setTime(12, 0)->getTimestamp() - $anchor->setTime(12, 0)->getTimestamp()) / 86400);

				return $days >= 0 && $days % $every === 0;

			case self::WEEKLY:
				$dow = 1 << ((int) $day->format('N') - 1);

				if (((int) ($tp['dayofweek'] ?? 0) & $dow) === 0) {
					return false;
				}

				$week = $day->modify('-'.((int) $day->format('N') - 1).' days');
				$weeks = (int) round(($week->setTime(12, 0)->getTimestamp() - $anchor_week->setTime(12, 0)->getTimestamp())
					/ (7 * 86400));

				return $weeks >= 0 && $weeks % $every === 0;

			case self::MONTHLY:
				if (((int) ($tp['month'] ?? 0) & (1 << ((int) $day->format('n') - 1))) === 0) {
					return false;
				}

				$dow_mask = (int) ($tp['dayofweek'] ?? 0);

				if ($dow_mask === 0) {
					// A day number past the end of the month runs on the last day.
					$want = min((int) ($tp['day'] ?? 1), (int) $day->format('t'));

					return (int) $day->format('j') === $want;
				}

				if (($dow_mask & (1 << ((int) $day->format('N') - 1))) === 0) {
					return false;
				}

				$nth = intdiv((int) $day->format('j') - 1, 7) + 1;

				// "every" here is the week of the month: 1 to 4, or 5 for the last.
				return $every === 5 ? (int) $day->format('j') + 7 > (int) $day->format('t') : $nth === $every;
		}

		return false;
	}

	private static function merge(array $intervals): array {
		usort($intervals, static fn($a, $b) => $a[0] <=> $b[0]);
		$out = [];

		foreach ($intervals as [$a, $b]) {
			$last = count($out) - 1;

			if ($last >= 0 && $a <= $out[$last][1]) {
				$out[$last][1] = max($out[$last][1], $b);
			}
			else {
				$out[] = [$a, $b];
			}
		}

		return $out;
	}
}
