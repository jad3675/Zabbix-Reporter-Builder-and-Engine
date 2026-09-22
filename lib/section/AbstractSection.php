<?php declare(strict_types = 1);

namespace Modules\Reporter\Lib\Section;

use Modules\Reporter\Lib\Core\TagFilter;

abstract class AbstractSection implements SectionInterface {

	public const SEVERITIES = [
		0 => 'Not classified',
		1 => 'Information',
		2 => 'Warning',
		3 => 'Average',
		4 => 'High',
		5 => 'Disaster'
	];

	public function description(): string {
		return '';
	}

	public function validateOptions(array $options): array {
		return [];
	}

	public function normalizeOptions(array $options): array {
		$out = [];

		foreach ($this->options() as $spec) {
			$name = $spec['name'];
			$default = $spec['default'] ?? null;
			$value = array_key_exists($name, $options) ? $options[$name] : $default;

			switch ($spec['type']) {
				case 'int':
					$value = is_numeric($value) ? (int) $value : (int) $default;
					$value = max((int) ($spec['min'] ?? PHP_INT_MIN), min((int) ($spec['max'] ?? PHP_INT_MAX), $value));
					break;

				case 'float':
					$value = is_numeric($value) ? (float) $value : (float) $default;
					$value = max((float) ($spec['min'] ?? -INF), min((float) ($spec['max'] ?? INF), $value));
					break;

				case 'bool':
					$value = is_string($value)
						? in_array(strtolower($value), ['1', 'true', 'yes', 'on'], true)
						: (bool) $value;
					break;

				case 'select':
					$choices = array_map('strval', array_keys($spec['choices'] ?? []));
					$value = in_array((string) $value, $choices, true) ? (string) $value : (string) $default;
					break;

				case 'tags':
					$value = is_string($value) ? TagFilter::parse($value) : TagFilter::normalize($value);
					break;

				default:
					$value = mb_substr(trim((string) ($value ?? '')), 0, (int) ($spec['maxlength'] ?? 255));
			}

			$out[$name] = $value;
		}

		return $out;
	}

	/** Split a comma-separated wildcard pattern list ("icmpping, icmpping[*]"). */
	protected static function patterns(string $list): array {
		return array_values(array_filter(array_map('trim', explode(',', $list)), 'strlen'));
	}

	protected static function sevOption(string $name = 'min_severity', string $label = 'Minimum severity'): array {
		return ['name' => $name, 'label' => $label, 'type' => 'select', 'default' => '0',
			'choices' => array_map('strval', self::SEVERITIES)];
	}
}
