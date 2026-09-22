<?php declare(strict_types = 1);

namespace Modules\Reporter\Lib\Render;

/**
 * Every table in one CSV, each preceded by its sheet name and separated by a blank line.
 * UTF-8 with BOM so Excel opens accented device names correctly. Cells that a
 * spreadsheet would treat as formulas are prefixed with an apostrophe.
 */
final class CsvWriter {

	public static function write(array $sheets): string {
		$fh = fopen('php://temp', 'w+b');
		fwrite($fh, "\xEF\xBB\xBF");

		foreach ($sheets as $i => $sheet) {
			if ($i > 0) {
				fwrite($fh, "\r\n");
			}

			fputcsv($fh, [$sheet['name']], ',', '"', '');
			fputcsv($fh, array_map([self::class, 'safe'], $sheet['header']), ',', '"', '');

			foreach ($sheet['rows'] as $row) {
				fputcsv($fh, array_map([self::class, 'safe'], $row), ',', '"', '');
			}
		}

		rewind($fh);
		$out = (string) stream_get_contents($fh);
		fclose($fh);

		return $out;
	}

	public static function safe($v) {
		if (is_string($v) && $v !== '' && strpbrk($v[0], '=+-@') !== false && !is_numeric($v)) {
			return "'".$v;
		}

		return $v;
	}
}
