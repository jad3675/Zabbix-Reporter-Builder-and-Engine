<?php declare(strict_types = 1);

namespace Modules\Reporter\Lib\Core;

/**
 * Tag filters in a one-per-line syntax that fits in a text field:
 *
 *   site              tag exists
 *   site=Cincinnati   equals
 *   site~cinc         contains
 *   site!=lab         does not equal
 *   !decommissioned   tag does not exist
 *
 * Stored structurally in definitions, converted to Zabbix operators at query time.
 */
final class TagFilter {

	private const OPERATORS = [
		'contains' => 0,
		'equals' => 1,
		'not_equals' => 3,
		'exists' => 4,
		'not_exists' => 5
	];

	/** @return array<int, array{tag: string, operator: string, value: string}> */
	public static function parse(string $text): array {
		$filters = [];

		foreach (preg_split('/\r\n|\r|\n/', $text) as $line) {
			$line = trim($line);

			if ($line === '') {
				continue;
			}

			if ($line[0] === '!') {
				$filters[] = ['tag' => trim(substr($line, 1)), 'operator' => 'not_exists', 'value' => ''];
			}
			elseif (($pos = strpos($line, '!=')) !== false) {
				$filters[] = ['tag' => trim(substr($line, 0, $pos)), 'operator' => 'not_equals',
					'value' => trim(substr($line, $pos + 2))];
			}
			elseif (($pos = strpos($line, '=')) !== false) {
				$filters[] = ['tag' => trim(substr($line, 0, $pos)), 'operator' => 'equals',
					'value' => trim(substr($line, $pos + 1))];
			}
			elseif (($pos = strpos($line, '~')) !== false) {
				$filters[] = ['tag' => trim(substr($line, 0, $pos)), 'operator' => 'contains',
					'value' => trim(substr($line, $pos + 1))];
			}
			else {
				$filters[] = ['tag' => $line, 'operator' => 'exists', 'value' => ''];
			}
		}

		return self::normalize($filters);
	}

	public static function normalize($filters): array {
		$out = [];

		foreach (is_array($filters) ? $filters : [] as $filter) {
			if (!is_array($filter)) {
				continue;
			}

			$tag = mb_substr(trim((string) ($filter['tag'] ?? '')), 0, 255);
			$operator = (string) ($filter['operator'] ?? 'exists');

			if ($tag === '' || !array_key_exists($operator, self::OPERATORS)) {
				continue;
			}

			$value = in_array($operator, ['exists', 'not_exists'], true)
				? '' : mb_substr((string) ($filter['value'] ?? ''), 0, 255);

			$out[] = ['tag' => $tag, 'operator' => $operator, 'value' => $value];

			if (count($out) >= 20) {
				break;
			}
		}

		return $out;
	}

	public static function format(array $filters): string {
		$lines = [];

		foreach (self::normalize($filters) as $f) {
			switch ($f['operator']) {
				case 'not_exists': $lines[] = '!'.$f['tag']; break;
				case 'not_equals': $lines[] = $f['tag'].'!='.$f['value']; break;
				case 'equals': $lines[] = $f['tag'].'='.$f['value']; break;
				case 'contains': $lines[] = $f['tag'].'~'.$f['value']; break;
				default: $lines[] = $f['tag'];
			}
		}

		return implode("\n", $lines);
	}

	public static function toApi(array $filters): array {
		return array_map(static function (array $f): array {
			return ['tag' => $f['tag'], 'operator' => self::OPERATORS[$f['operator']], 'value' => $f['value']];
		}, self::normalize($filters));
	}
}
