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
		'last_n_days' => 'Last N days'
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

			default:
				throw new \InvalidArgumentException(sprintf('Unknown period type "%s".', $type));
		}

		return new self($start->getTimestamp(), $end->getTimestamp() - 1, $label, $timezone);
	}

	public static function custom(string $from_date, string $till_date, string $timezone): self {
		$tz = new DateTimeZone($timezone);
		$start = DateTimeImmutable::createFromFormat('!Y-m-d', $from_date, $tz);
		$end = DateTimeImmutable::createFromFormat('!Y-m-d', $till_date, $tz);

		if ($start === false || $end === false) {
			throw new \InvalidArgumentException('Custom dates must be YYYY-MM-DD.');
		}

		return new self($start->getTimestamp(), $end->modify('+1 day')->getTimestamp() - 1,
			sprintf('%s to %s', $start->format('j M Y'), $end->format('j M Y')), $timezone
		);
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

	public function toArray(): array {
		return ['from' => $this->from, 'till' => $this->till, 'label' => $this->label, 'timezone' => $this->timezone];
	}
}
