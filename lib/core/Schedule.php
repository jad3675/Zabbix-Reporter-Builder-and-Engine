<?php declare(strict_types = 1);

namespace Modules\Reporter\Lib\Core;

use DateTimeImmutable;
use DateTimeZone;

/**
 * Decides whether a scheduled report is due. The CLI runner calls run-due hourly; a
 * report is due once its slot for the current cycle has passed and the period that slot
 * covers has not been delivered yet. A missed hour (host down, timer late) is picked up
 * on the next run instead of being skipped.
 */
final class Schedule {

	/** @return Period|null  the period to run for, or null if nothing is due */
	public static function due(array $def, ?string $last_key, ?DateTimeImmutable $now = null): ?Period {
		$s = $def['schedule'] ?? [];

		if (empty($s['enabled'])) {
			return null;
		}

		$tz = new DateTimeZone($def['timezone'] ?? 'UTC');
		$now = ($now ?? new DateTimeImmutable('now'))->setTimezone($tz);
		$hour = (int) ($s['hour'] ?? 6);

		switch ($s['cycle'] ?? 'monthly') {
			case 'daily':
				$slot = $now->setTime($hour, 0, 0);
				break;

			case 'weekly':
				$slot = $now->modify('monday this week')->modify(sprintf('+%d days', ((int) $s['day']) - 1))
					->setTime($hour, 0, 0);
				break;

			default:
				$slot = $now->modify('first day of this month')->modify(sprintf('+%d days', ((int) $s['day']) - 1))
					->setTime($hour, 0, 0);
		}

		if ($now < $slot) {
			return null;
		}

		$period = Period::resolve($def['period']['type'], (int) $def['period']['n'], $tz->getName(), $slot);

		return $period->key() === $last_key ? null : $period;
	}
}
