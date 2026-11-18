<?php declare(strict_types = 1);

namespace Modules\Reporter\Lib\Core;

use DateTimeImmutable;
use DateTimeZone;

/**
 * A closed interval [from, till] in Unix seconds, resolved in the report's timezone so
 * "previous month" means the customer's month, not the server's.
 */
final class Period {

	public const TYPES = [
		'previous_month' => 'Previous calendar month',
		'previous_week' => 'Previous week (Monday to Sunday)',
		'previous_day' => 'Yesterday',
		'month_to_date' => 'Month to date',
		'last_n_days' => 'Last N days',
		'last_24_hours' => 'Last 24 hours, up to when it runs'
	];

	/**
	 * Periods pinned to the calendar rather than to "now". They run the same window
	 * every time, so a definition that uses one cannot be scheduled.
	 */
	public const FIXED = [
		'day' => 'A single day (pick a date)',
		'range' => 'From a date and time to another'
	];

	public int $from;
	public int $till;
	public string $label;
	public string $timezone;

	public function __construct(int $from, int $till, string $label, string $timezone) {
		if ($till < $from) {
			throw new \InvalidArgumentException('Period ends before it starts.');
		}

		$this->from = $from;
		$this->till = $till;
		$this->label = $label;
		$this->timezone = $timezone;
	}

	public static function resolve(string $type, int $n, string $timezone, ?DateTimeImmutable $now = null): self {
		$tz = new DateTimeZone($timezone);
		$now = ($now ?? new DateTimeImmutable('now'))->setTimezone($tz);
		$today = $now->setTime(0, 0, 0);

		switch ($type) {
			case 'previous_month':
				$start = $today->modify('first day of last month');
				$end = $today->modify('first day of this month');
				$label = $start->format('F Y');
				break;

			case 'previous_week':
				$this_monday = $today->modify('monday this week');
				$start = $this_monday->modify('-7 days');
				$end = $this_monday;
				$label = sprintf('Week of %s', $start->format('j M Y'));
				break;

			case 'previous_day':
				$start = $today->modify('-1 day');
				$end = $today;
				$label = $start->format('j F Y');
				break;

			case 'month_to_date':
				$start = $today->modify('first day of this month');
				$end = $now;
				$label = sprintf('%s to %s', $start->format('j M'), $now->format('j M Y'));
				break;

			case 'last_n_days':
				$n = max(1, min(400, $n));
				$start = $today->modify(sprintf('-%d days', $n));
				$end = $today;
				$label = sprintf('%s to %s', $start->format('j M Y'), $end->modify('-1 day')->format('j M Y'));
				break;

			// Rolling, to the minute it runs: a 06:00 report covers 06:00 yesterday to now,
			// where "Yesterday" would stop at midnight and miss the night.
			case 'last_24_hours':
				$end = $now->setTime((int) $now->format('H'), (int) $now->format('i'), 0);
				// Exactly 86,400 seconds, even across a clock change.
				$start = (new DateTimeImmutable('@'.($end->getTimestamp() - 86400)))->setTimezone($tz);
				$label = sprintf('%s to %s', $start->format('j M Y H:i'), $end->format('j M Y H:i'));
				break;

			default:
				throw new \InvalidArgumentException(sprintf('Unknown period type "%s".', $type));
		}

		return new self($start->getTimestamp(), $end->getTimestamp() - 1, $label, $timezone);
	}

	/** Every type the editor and preview offer: the relative presets, then the fixed ones. */
	public static function allTypes(): array {
		return self::TYPES + self::FIXED;
	}

	public static function isFixed(string $type): bool {
		return array_key_exists($type, self::FIXED);
	}

	/**
	 * The period a stored definition describes: {type, n, date, from, till}.
	 * $now only matters for the relative types.
	 */
	public static function fromSpec(array $spec, string $timezone, ?DateTimeImmutable $now = null): self {
		$type = (string) ($spec['type'] ?? 'previous_month');

		switch ($type) {
			case 'day':
				return self::day((string) ($spec['date'] ?? ''), $timezone);

			case 'range':
				return self::range((string) ($spec['from'] ?? ''), (string) ($spec['till'] ?? ''), $timezone);

			default:
				return self::resolve($type, (int) ($spec['n'] ?? 30), $timezone, $now);
		}
	}

	/** One whole calendar day, midnight to midnight in the report's time zone. */
	public static function day(string $date, string $timezone): self {
		$tz = new DateTimeZone($timezone);
		$start = self::parseDate($date, $tz);

		if ($start === null) {
			throw new \InvalidArgumentException('Pick the day to report on (YYYY-MM-DD).');
		}

		return new self($start->getTimestamp(), $start->modify('+1 day')->getTimestamp() - 1,
			$start->format('l j F Y'), $timezone);
	}

	/**
	 * From one moment to another. Either end may be a date (YYYY-MM-DD) or a date and
	 * time (YYYY-MM-DD HH:MM, or the T form a datetime-local input sends). A date alone
	 * as the end means the whole of that day, so "1 Aug to 31 Aug" is all of August. A
	 * time as the end is exclusive: 08:00 to 17:00 stops at 16:59:59.
	 */
	public static function range(string $from, string $till, string $timezone): self {
		$tz = new DateTimeZone($timezone);
		[$start, $start_has_time] = self::parseMoment($from, $tz);
		[$end, $end_has_time] = self::parseMoment($till, $tz);

		if ($start === null || $end === null) {
			throw new \InvalidArgumentException('Pick both ends of the period (YYYY-MM-DD or YYYY-MM-DD HH:MM).');
		}

		if (!$end_has_time) {
			$end = $end->modify('+1 day');
		}

		if ($end <= $start) {
			throw new \InvalidArgumentException('The period ends before it starts.');
		}

		$whole_days = !$start_has_time && !$end_has_time;
		$last = $end->modify('-1 second');

		if ($whole_days) {
			$label = $start->format('Y-m-d') === $last->format('Y-m-d')
				? $start->format('l j F Y')
				: sprintf('%s to %s', $start->format('j M Y'), $last->format('j M Y'));
		}
		else {
			$label = $start->format('Y-m-d') === $last->format('Y-m-d')
				? sprintf('%s, %s to %s', $start->format('j M Y'), $start->format('H:i'), $end->format('H:i'))
				: sprintf('%s to %s', $start->format('j M Y H:i'), $end->format('j M Y H:i'));
		}

		return new self($start->getTimestamp(), $end->getTimestamp() - 1, $label, $timezone);
	}

	/** Older preview links and the CLI's --from/--till. Same rules as range(). */
	public static function custom(string $from_date, string $till_date, string $timezone): self {
		return self::range($from_date, $till_date, $timezone);
	}

	/** "Previous calendar month", "Wednesday 11 November 2026", "Last N days (30)". */
	public static function describe(array $spec, string $timezone = 'UTC'): string {
		$type = (string) ($spec['type'] ?? '');

		if (self::isFixed($type)) {
			try {
				return self::fromSpec($spec, $timezone)->label;
			}
			catch (\InvalidArgumentException $e) {
				return self::FIXED[$type].' (not set)';
			}
		}

		return (self::TYPES[$type] ?? $type).($type === 'last_n_days' ? ' ('.(int) ($spec['n'] ?? 30).')' : '');
	}

	private static function parseDate(string $value, DateTimeZone $tz): ?DateTimeImmutable {
		$value = trim($value);
		$d = DateTimeImmutable::createFromFormat('!Y-m-d', $value, $tz);

		return ($d !== false && $d->format('Y-m-d') === $value) ? $d : null;
	}

	/** @return array{0: ?DateTimeImmutable, 1: bool}  the moment, and whether a time was given */
	private static function parseMoment(string $value, DateTimeZone $tz): array {
		$value = trim(str_replace('T', ' ', $value));

		if (($d = self::parseDate($value, $tz)) !== null) {
			return [$d, false];
		}

		foreach (['!Y-m-d H:i', '!Y-m-d H:i:s'] as $format) {
			$d = DateTimeImmutable::createFromFormat($format, $value, $tz);

			// Round-trip check refuses rollovers such as 2026-02-30 or 25:00.
			if ($d !== false && $d->format(substr($format, 1)) === $value) {
				return [$d, true];
			}
		}

		return [null, false];
	}

	/** Canonical stored form of a range end: YYYY-MM-DD or YYYY-MM-DD HH:MM. */
	public static function canonicalMoment(string $value): string {
		$value = trim(str_replace('T', ' ', $value));

		if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
			return $value;
		}

		if (preg_match('/^(\d{4}-\d{2}-\d{2} \d{2}:\d{2})(:\d{2})?$/', $value, $m)) {
			return $m[1];
		}

		return $value;
	}

	public function seconds(): int {
		return $this->till - $this->from + 1;
	}

	public function days(): float {
		return $this->seconds() / 86400;
	}

	public function key(): string {
		return $this->from.'-'.$this->till;
	}

	/** Midnight-aligned day starts inside the period, in the report timezone. */
	public function dayStarts(): array {
		$tz = new DateTimeZone($this->timezone);
		$day = (new DateTimeImmutable('@'.$this->from))->setTimezone($tz)->setTime(0, 0, 0);
		$starts = [];

		while ($day->getTimestamp() <= $this->till && count($starts) < 500) {
			$starts[] = $day->getTimestamp();
			$day = $day->modify('+1 day');
		}

		return $starts;
	}

	/** Index into dayStarts() for a timestamp. DST-safe because it searches real starts. */
	public static function dayIndex(array $day_starts, int $clock): int {
		$lo = 0;
		$hi = count($day_starts) - 1;

		while ($lo < $hi) {
			$mid = intdiv($lo + $hi + 1, 2);

			if ($day_starts[$mid] <= $clock) {
				$lo = $mid;
			}
			else {
				$hi = $mid - 1;
			}
		}

		return $lo;
	}

	/** The same length of time immediately before this period, for comparisons. */
	public function previous(): self {
		$length = $this->seconds();

		return new self($this->from - $length, $this->from - 1, 'the previous '.self::describeLength($length),
			$this->timezone);
	}

	private static function describeLength(int $seconds): string {
		$days = (int) round($seconds / 86400);

		if ($days >= 28 && $days <= 31) {
			return 'month';
		}

		return $days === 7 ? 'week' : ($days <= 1 ? 'day' : $days.' days');
	}

	public function toArray(): array {
		return ['from' => $this->from, 'till' => $this->till, 'label' => $this->label, 'timezone' => $this->timezone];
	}
}
