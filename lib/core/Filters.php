<?php declare(strict_types = 1);

namespace Modules\Reporter\Lib\Core;

/**
 * Comma-separated wildcard patterns, used by the exclusion options every section offers
 * ("drop problems named *agent is not available*", "skip the lab switches").
 */
final class Filters {

	public static function patterns(string $list): array {
		return array_values(array_filter(array_map('trim', explode(',', $list)), 'strlen'));
	}

	public static function matches(string $subject, array $patterns): bool {
		foreach ($patterns as $pattern) {
			if (strpos($pattern, '*') === false && strpos($pattern, '?') === false) {
				if (stripos($subject, $pattern) !== false) {
					return true;
				}
			}
			elseif (fnmatch($pattern, $subject, FNM_CASEFOLD | FNM_NOESCAPE)) {
				return true;
			}
		}

		return false;
	}
}
