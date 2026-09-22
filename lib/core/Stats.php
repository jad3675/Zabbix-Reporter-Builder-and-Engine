<?php declare(strict_types = 1);

namespace Modules\Reporter\Lib\Core;

final class Stats {

	/**
	 * Least-squares fit y = slope * x + intercept.
	 *
	 * @return array{slope: float, intercept: float, r2: float}|null  null with fewer than two distinct x
	 */
	public static function linreg(array $xs, array $ys): ?array {
		$n = count($xs);

		if ($n < 2 || $n !== count($ys)) {
			return null;
		}

		$mx = array_sum($xs) / $n;
		$my = array_sum($ys) / $n;
		$sxx = 0.0;
		$sxy = 0.0;
		$syy = 0.0;

		for ($i = 0; $i < $n; $i++) {
			$dx = $xs[$i] - $mx;
			$dy = $ys[$i] - $my;
			$sxx += $dx * $dx;
			$sxy += $dx * $dy;
			$syy += $dy * $dy;
		}

		if ($sxx == 0.0) {
			return null;
		}

		$slope = $sxy / $sxx;

		return [
			'slope' => $slope,
			'intercept' => $my - $slope * $mx,
			'r2' => $syy == 0.0 ? 1.0 : ($sxy * $sxy) / ($sxx * $syy)
		];
	}

	public static function mean(array $values): ?float {
		return $values ? array_sum($values) / count($values) : null;
	}
}
