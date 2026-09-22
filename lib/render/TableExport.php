<?php declare(strict_types = 1);

namespace Modules\Reporter\Lib\Render;

use Modules\Reporter\Lib\Core\Format;

/**
 * Flattens a report into sheets of raw values for XLSX and CSV. Numbers stay numbers;
 * durations become hours; dates become ISO strings. Every row is included, regardless
 * of the PDF display limit.
 */
final class TableExport {

	/** @return array<int, array{name: string, header: string[], rows: array[]}> */
	public static function sheets(array $report): array {
		$tz = (string) $report['period']['timezone'];
		$sheets = [self::aboutSheet($report)];

		foreach ($report['sections'] as $section) {
			$kpis = [];

			foreach ($section['blocks'] as $block) {
				if ($block['type'] === 'kpis') {
					foreach ($block['items'] as $k) {
						$kpis[] = [$section['title'], $k['label'], self::value($k['value'], $k['format'] ?? 'int', [], $tz)];
					}
				}
			}

			if ($kpis) {
				$sheets[0]['rows'][] = [];

				foreach ($kpis as $k) {
					$sheets[0]['rows'][] = $k;
				}
			}

			foreach ($section['blocks'] as $block) {
				if ($block['type'] !== 'table') {
					continue;
				}

				$columns = array_values(array_filter($block['columns'], static fn($c) => ($c['export'] ?? true) !== false));
				$header = [];

				foreach ($columns as $c) {
					$header[] = $c['label'].(($c['format'] ?? '') === 'duration' ? ' (hours)' : '');
				}

				$rows = [];

				foreach ($block['rows'] as $row) {
					$out = [];

					foreach ($columns as $c) {
						$out[] = self::value($row[$c['key']] ?? null, $c['format'] ?? 'text', $row, $tz);
					}

					$rows[] = $out;
				}

				$sheets[] = ['name' => $block['sheet'] !== '' ? $block['sheet'] : $section['title'], 'header' => $header,
					'rows' => $rows];
			}
		}

		return $sheets;
	}

	private static function aboutSheet(array $report): array {
		$tz = (string) $report['period']['timezone'];

		return [
			'name' => 'Report',
			'header' => ['Field', 'Value', ''],
			'rows' => [
				['Report', $report['name'], ''],
				['Customer', $report['branding']['customer'], ''],
				['Period', $report['period']['label'], ''],
				['From', Format::datetime((int) $report['period']['from'], $tz, 'Y-m-d H:i:s'), ''],
				['Until', Format::datetime((int) $report['period']['till'], $tz, 'Y-m-d H:i:s'), ''],
				['Timezone', $tz, ''],
				['Devices covered', $report['scope']['hosts'], ''],
				['Host groups', implode(', ', $report['scope']['groups']), ''],
				['Generated', Format::datetime((int) $report['generated_at'], $tz, 'Y-m-d H:i:s'), ''],
				['Complete', $report['stopped'] ? 'No: '.$report['stopped'] : 'Yes', '']
			]
		];
	}

	/** @return string|int|float|null */
	public static function value($v, string $format, array $row, string $tz) {
		if ($v === null || $v === '') {
			return null;
		}

		switch ($format) {
			case 'int': return (int) round((float) $v);
			case 'number': case 'number2': case 'pp': case 'units': return round((float) $v, 4);
			case 'pct': case 'pct1': case 'pct3': return round((float) $v, 4);
			case 'duration': return round((float) $v / 3600, 3);
			case 'datetime': return Format::datetime((int) $v, $tz, 'Y-m-d H:i');
			case 'date': return Format::datetime((int) $v, $tz, 'Y-m-d');
			case 'severity': return Svg::SEVERITY_NAMES[max(0, min(5, (int) $v))];
			default: return is_array($v) ? implode(', ', $v) : (string) $v;
		}
	}
}
