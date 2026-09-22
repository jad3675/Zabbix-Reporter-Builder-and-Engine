<?php declare(strict_types = 1);

namespace Modules\Reporter\Lib\Render;

use Modules\Reporter\Lib\Core\Format;

/**
 * Charts drawn server-side as SVG: deterministic, no JavaScript, identical on screen and
 * in the PDF. Kept to the few shapes a service report needs.
 */
final class Svg {

	/** Zabbix's default severity colours, so the report matches what customers see in Zabbix. */
	public const SEVERITY_COLORS = ['#97aab3', '#7499ff', '#ffc859', '#ffa059', '#e97659', '#e45959'];
	public const SEVERITY_NAMES = ['Not classified', 'Information', 'Warning', 'Average', 'High', 'Disaster'];

	private const FONT = "font-family='DejaVu Sans Condensed, DejaVu Sans, Arial, sans-serif'";
	private const INK = '#1d2433';
	private const MUTED = '#5b6577';
	private const RULE = '#d9dee7';

	public static function e(string $s): string {
		return htmlspecialchars($s, ENT_QUOTES | ENT_XML1, 'UTF-8');
	}

	private static function open(int $w, int $h): string {
		return sprintf("<svg xmlns='http://www.w3.org/2000/svg' width='%d' height='%d' viewBox='0 0 %d %d'>", $w, $h, $w, $h);
	}

	/**
	 * Horizontal bars. Each datum: label, value, optional units, optional sev (six counts,
	 * drawn as a stacked severity bar).
	 */
	public static function hbar(array $data, string $format, string $accent, int $width = 680): string {
		$row = 20;
		$label_w = 210;
		$value_w = 80;
		$bar_w = $width - $label_w - $value_w - 10;
		$h = count($data) * $row + 4;
		$max = 0.0;

		foreach ($data as $d) {
			$max = max($max, (float) $d['value']);
		}

		$max = $max > 0 ? $max : 1.0;
		$svg = self::open($width, $h);

		foreach (array_values($data) as $i => $d) {
			$y = $i * $row + 2;
			$label = mb_strlen($d['label']) > 34 ? mb_substr($d['label'], 0, 33).'…' : $d['label'];
			$svg .= sprintf("<text x='%d' y='%d' %s font-size='10' fill='%s' text-anchor='end'>%s</text>",
				$label_w - 8, $y + 13, self::FONT, self::INK, self::e($label));

			$len = max(1.0, $bar_w * (float) $d['value'] / $max);

			if (isset($d['sev']) && array_sum($d['sev']) > 0) {
				$x = (float) $label_w;
				$total = array_sum($d['sev']);

				foreach ($d['sev'] as $s => $n) {
					if ($n <= 0) {
						continue;
					}

					$seg = $len * $n / $total;
					$svg .= sprintf("<rect x='%.1f' y='%d' width='%.1f' height='12' fill='%s'/>", $x, $y + 3,
						$seg, self::SEVERITY_COLORS[$s]);
					$x += $seg;
				}
			}
			else {
				$svg .= sprintf("<rect x='%d' y='%d' width='%.1f' height='12' fill='%s'/>", $label_w, $y + 3, $len,
					self::e($accent));
			}

			$value = $format === 'units'
				? Format::units($d['value'], (string) ($d['units'] ?? ''))
				: Format::int($d['value']);

			$svg .= sprintf("<text x='%.1f' y='%d' %s font-size='10' fill='%s'>%s</text>",
				$label_w + $len + 6, $y + 13, self::FONT, self::MUTED, self::e($value));
		}

		return $svg.'</svg>';
	}

	/**
	 * Stacked daily columns by severity. $series[severity][day] = count.
	 */
	public static function severityColumns(array $labels, array $series, int $width = 680, int $height = 170): string {
		$n = max(1, count($labels));
		$left = 34;
		$bottom = 36;
		$top = 8;
		$plot_w = $width - $left - 6;
		$plot_h = $height - $bottom - $top;
		$totals = array_fill(0, $n, 0);

		foreach ($series as $values) {
			foreach ($values as $i => $v) {
				$totals[$i] += $v;
			}
		}

		$max = max(1, max($totals));
		$step = self::niceStep($max);
		$ceiling = (int) (ceil($max / $step) * $step);
		$slot = $plot_w / $n;
		$bar = max(1.0, $slot * 0.72);
		$svg = self::open($width, $height);

		for ($v = 0; $v <= $ceiling; $v += $step) {
			$y = $top + $plot_h - $plot_h * $v / $ceiling;
			$svg .= sprintf("<line x1='%d' x2='%d' y1='%.1f' y2='%.1f' stroke='%s' stroke-width='%s'/>", $left,
				$width - 6, $y, $y, self::RULE, $v === 0 ? '1' : '0.5');
			$svg .= sprintf("<text x='%d' y='%.1f' %s font-size='9' fill='%s' text-anchor='end'>%d</text>",
				$left - 5, $y + 3, self::FONT, self::MUTED, $v);
		}

		for ($i = 0; $i < $n; $i++) {
			$x = $left + $i * $slot + ($slot - $bar) / 2;
			$y = $top + $plot_h;

			foreach ($series as $s => $values) {
				$v = $values[$i] ?? 0;

				if ($v <= 0) {
					continue;
				}

				$h = $plot_h * $v / $ceiling;
				$y -= $h;
				$svg .= sprintf("<rect x='%.1f' y='%.1f' width='%.1f' height='%.1f' fill='%s'/>", $x, $y, $bar, $h,
					self::SEVERITY_COLORS[$s]);
			}
		}

		$every = $n > 20 ? (int) ceil($n / 16) : 1;

		for ($i = 0; $i < $n; $i += $every) {
			$svg .= sprintf("<text x='%.1f' y='%d' %s font-size='9' fill='%s' text-anchor='middle'>%s</text>",
				$left + $i * $slot + $slot / 2, $top + $plot_h + 13, self::FONT, self::MUTED, self::e((string) $labels[$i]));
		}

		// Legend: only severities that occur.
		$x = $left;

		foreach ($series as $s => $values) {
			if (array_sum($values) <= 0) {
				continue;
			}

			$svg .= sprintf("<rect x='%d' y='%d' width='9' height='9' fill='%s'/>", $x, $height - 12,
				self::SEVERITY_COLORS[$s]);
			$svg .= sprintf("<text x='%d' y='%d' %s font-size='9' fill='%s'>%s</text>", $x + 13, $height - 4,
				self::FONT, self::MUTED, self::SEVERITY_NAMES[$s]);
			$x += 22 + (int) (mb_strlen(self::SEVERITY_NAMES[$s]) * 5.2);
		}

		return $svg.'</svg>';
	}

	/** Severity mix as one proportional strip. */
	public static function sevStrip(array $counts, int $width = 84, int $height = 9): string {
		$total = array_sum($counts);
		$svg = self::open($width, $height);

		if ($total <= 0) {
			return $svg.sprintf("<rect x='0' y='%d' width='%d' height='1' fill='%s'/></svg>", intdiv($height, 2), $width,
				self::RULE);
		}

		$x = 0.0;

		for ($s = 5; $s >= 0; $s--) {
			$n = $counts[$s] ?? 0;

			if ($n <= 0) {
				continue;
			}

			$w = $width * $n / $total;
			$svg .= sprintf("<rect x='%.2f' y='0' width='%.2f' height='%d' fill='%s'/>", $x, $w, $height,
				self::SEVERITY_COLORS[$s]);
			$x += $w;
		}

		return $svg.'</svg>';
	}

	/** A small colour square, used for the severity column. */
	public static function swatch(string $color, int $size = 9): string {
		return self::open($size, $size).sprintf("<rect x='0' y='0' width='%d' height='%d' rx='1.5' fill='%s'/></svg>",
			$size, $size, self::e($color));
	}

	/** Daily sparkline; nulls are gaps. */
	public static function sparkline(array $values, string $accent, int $width = 96, int $height = 20): string {
		$present = array_filter($values, static fn($v) => $v !== null);
		$svg = self::open($width, $height);

		if (count($present) < 2) {
			return $svg.'</svg>';
		}

		$min = min($present);
		$max = max($present);
		$span = $max - $min ?: 1.0;
		$n = count($values);
		$path = '';
		$pen = false;

		foreach ($values as $i => $v) {
			if ($v === null) {
				$pen = false;

				continue;
			}

			$x = 1 + ($width - 2) * ($n > 1 ? $i / ($n - 1) : 0);
			$y = $height - 2 - ($height - 4) * (($v - $min) / $span);
			$path .= sprintf('%s%.1f %.1f ', $pen ? 'L' : 'M', $x, $y);
			$pen = true;
		}

		return $svg.sprintf("<path d='%s' fill='none' stroke='%s' stroke-width='1.2'/></svg>", trim($path),
			self::e($accent));
	}

	private static function niceStep(float $max): int {
		foreach ([1, 2, 5, 10, 20, 25, 50, 100, 200, 250, 500, 1000, 2000, 5000, 10000] as $step) {
			if ($max / $step <= 5) {
				return $step;
			}
		}

		return (int) (10 ** ceil(log10($max / 5)));
	}
}
