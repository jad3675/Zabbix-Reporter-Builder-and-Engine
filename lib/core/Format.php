<?php declare(strict_types = 1);

namespace Modules\Reporter\Lib\Core;

/**
 * Human formatting for the screen and PDF. Exports keep raw numbers.
 */
final class Format {

	private const BINARY_UNITS = ['B', 'Bps'];
	private const NO_PREFIX_UNITS = ['%', 'ms', 'rpm', 'RPM', 'C', '°C', 'F', 'V', 'A', 'W', 'dB', 'dBm', 's', 'unixtime',
		'uptime'];

	public static function int($value): string {
		return number_format((float) $value, 0, '.', ',');
	}

	public static function number($value, int $decimals = 1): string {
		return number_format((float) $value, $decimals, '.', ',');
	}

	public static function pct($value, int $decimals = 2): string {
		return $value === null ? '' : number_format((float) $value, $decimals, '.', ',').'%';
	}

	public static function duration($seconds): string {
		if ($seconds === null || $seconds === '') {
			return '';
		}

		$s = (int) round((float) $seconds);

		if ($s <= 0) {
			return '0m';
		}

		if ($s < 60) {
			return '<1m';
		}

		$d = intdiv($s, 86400);
		$h = intdiv($s % 86400, 3600);
		$m = intdiv($s % 3600, 60);

		if ($d > 0) {
			return $h > 0 ? sprintf('%dd %dh', $d, $h) : sprintf('%dd', $d);
		}

		if ($h > 0) {
			return $m > 0 ? sprintf('%dh %dm', $h, $m) : sprintf('%dh', $h);
		}

		return sprintf('%dm', $m);
	}

	public static function units($value, string $units): string {
		if ($value === null || $value === '') {
			return '';
		}

		$v = (float) $value;

		if ($units === 'uptime' || $units === 's') {
			return self::duration($v);
		}

		if ($units === 'unixtime') {
			return gmdate('Y-m-d H:i', (int) $v);
		}

		if ($units === '' || in_array($units, self::NO_PREFIX_UNITS, true)) {
			$decimals = abs($v) >= 100 ? 0 : (abs($v) >= 10 ? 1 : 2);

			return number_format($v, $decimals, '.', ',').($units === '' ? '' : ($units === '%' ? '%' : ' '.$units));
		}

		$base = in_array($units, self::BINARY_UNITS, true) ? 1024 : 1000;
		$prefixes = ['', 'K', 'M', 'G', 'T', 'P'];
		$i = 0;

		while (abs($v) >= $base && $i < count($prefixes) - 1) {
			$v /= $base;
			$i++;
		}

		$decimals = abs($v) >= 100 ? 0 : (abs($v) >= 10 ? 1 : 2);

		return number_format($v, $decimals, '.', ',').' '.$prefixes[$i].$units;
	}

	public static function datetime(int $clock, string $timezone, string $format = 'j M Y H:i'): string {
		return (new \DateTimeImmutable('@'.$clock))->setTimezone(new \DateTimeZone($timezone))->format($format);
	}
}
