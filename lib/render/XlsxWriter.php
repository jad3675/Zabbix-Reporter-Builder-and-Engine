<?php declare(strict_types = 1);

namespace Modules\Reporter\Lib\Render;

use Modules\Reporter\Lib\Core\Config;

/**
 * A minimal XLSX writer: inline strings, one bold header row with an autofilter and a
 * frozen pane, column widths estimated from content. Sheet XML is streamed to temp files
 * so a large sheet never sits in memory as one string. Needs ext-zip.
 */
final class XlsxWriter {

	public static function available(): bool {
		return class_exists(\ZipArchive::class);
	}

	public static function write(array $sheets): string {
		if (!self::available()) {
			throw new \RuntimeException('XLSX export needs the PHP zip extension. CSV export works without it.');
		}

		$dir = Config::dataDir('tmp');
		$zip_path = tempnam($dir, 'xlsx');
		$zip = new \ZipArchive();

		if ($zip->open($zip_path, \ZipArchive::OVERWRITE) !== true) {
			throw new \RuntimeException('Could not create the spreadsheet.');
		}

		$names = self::sheetNames(array_column($sheets, 'name'));
		$temp_files = [];

		foreach ($sheets as $i => $sheet) {
			$file = tempnam($dir, 'sheet');
			$temp_files[] = $file;
			self::writeSheet($file, $sheet);
			$zip->addFile($file, 'xl/worksheets/sheet'.($i + 1).'.xml');
		}

		$zip->addFromString('[Content_Types].xml', self::contentTypes(count($sheets)));
		$zip->addFromString('_rels/.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
			.'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
			.'<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
			.'</Relationships>');
		$zip->addFromString('xl/workbook.xml', self::workbook($names));
		$zip->addFromString('xl/_rels/workbook.xml.rels', self::workbookRels(count($sheets)));
		$zip->addFromString('xl/styles.xml', self::styles());
		$zip->close();

		$bytes = (string) file_get_contents($zip_path);
		@unlink($zip_path);

		foreach ($temp_files as $f) {
			@unlink($f);
		}

		return $bytes;
	}

	private static function writeSheet(string $file, array $sheet): void {
		$fh = fopen($file, 'wb');
		$ncols = max(1, count($sheet['header']));
		$widths = array_map(static fn($h) => min(60, max(8, mb_strlen((string) $h) + 2)), $sheet['header']);

		foreach (array_slice($sheet['rows'], 0, 500) as $row) {
			foreach ($row as $c => $v) {
				$widths[$c] = min(60, max($widths[$c] ?? 8, mb_strlen((string) $v) + 2));
			}
		}

		$last_col = self::col($ncols - 1);
		$last_row = count($sheet['rows']) + 1;

		fwrite($fh, '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
			.'<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" '
			.'xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
			.'<sheetViews><sheetView workbookViewId="0"><pane ySplit="1" topLeftCell="A2" activePane="bottomLeft" state="frozen"/></sheetView></sheetViews>'
			.'<cols>');

		foreach ($widths as $c => $w) {
			fwrite($fh, sprintf('<col min="%d" max="%d" width="%d" customWidth="1"/>', $c + 1, $c + 1, $w));
		}

		fwrite($fh, '</cols><sheetData>');
		fwrite($fh, self::row(1, $sheet['header'], 1));

		foreach ($sheet['rows'] as $i => $row) {
			fwrite($fh, self::row($i + 2, $row, 0));
		}

		fwrite($fh, '</sheetData>');

		if ($sheet['rows']) {
			fwrite($fh, sprintf('<autoFilter ref="A1:%s%d"/>', $last_col, $last_row));
		}

		fwrite($fh, '</worksheet>');
		fclose($fh);
	}

	private static function row(int $r, array $values, int $style): string {
		$xml = '<row r="'.$r.'">';

		foreach (array_values($values) as $c => $v) {
			$ref = self::col($c).$r;
			$s = $style ? ' s="'.$style.'"' : '';

			if ($v === null || $v === '') {
				continue;
			}

			if (is_int($v) || is_float($v)) {
				if (!is_finite((float) $v)) {
					continue;
				}

				$xml .= '<c r="'.$ref.'"'.$s.'><v>'.$v.'</v></c>';
			}
			else {
				$text = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', (string) $v);
				$xml .= '<c r="'.$ref.'" t="inlineStr"'.$s.'><is><t xml:space="preserve">'
					.htmlspecialchars((string) $text, ENT_XML1 | ENT_QUOTES, 'UTF-8').'</t></is></c>';
			}
		}

		return $xml.'</row>';
	}

	private static function col(int $i): string {
		$s = '';

		for ($i++; $i > 0; $i = intdiv($i - 1, 26)) {
			$s = chr(65 + ($i - 1) % 26).$s;
		}

		return $s;
	}

	private static function sheetNames(array $names): array {
		$out = [];
		$used = [];

		foreach ($names as $name) {
			$clean = trim(preg_replace('~[\[\]:*?/\\\\]~', ' ', (string) $name)) ?: 'Sheet';
			$clean = mb_substr($clean, 0, 31);
			$candidate = $clean;

			for ($n = 2; isset($used[mb_strtolower($candidate)]); $n++) {
				$suffix = ' '.$n;
				$candidate = mb_substr($clean, 0, 31 - strlen($suffix)).$suffix;
			}

			$used[mb_strtolower($candidate)] = true;
			$out[] = $candidate;
		}

		return $out;
	}

	private static function contentTypes(int $n): string {
		$xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
			.'<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
			.'<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
			.'<Default Extension="xml" ContentType="application/xml"/>'
			.'<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
			.'<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>';

		for ($i = 1; $i <= $n; $i++) {
			$xml .= '<Override PartName="/xl/worksheets/sheet'.$i.'.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>';
		}

		return $xml.'</Types>';
	}

	private static function workbook(array $names): string {
		$xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
			.'<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" '
			.'xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets>';

		foreach ($names as $i => $name) {
			$xml .= '<sheet name="'.htmlspecialchars($name, ENT_XML1 | ENT_QUOTES, 'UTF-8').'" sheetId="'.($i + 1)
				.'" r:id="rId'.($i + 1).'"/>';
		}

		return $xml.'</sheets></workbook>';
	}

	private static function workbookRels(int $n): string {
		$xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
			.'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">';

		for ($i = 1; $i <= $n; $i++) {
			$xml .= '<Relationship Id="rId'.$i.'" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet'.$i.'.xml"/>';
		}

		$xml .= '<Relationship Id="rId'.($n + 1).'" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>';

		return $xml.'</Relationships>';
	}

	private static function styles(): string {
		return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
			.'<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
			.'<fonts count="2"><font><sz val="11"/><name val="Calibri"/></font><font><b/><sz val="11"/><name val="Calibri"/></font></fonts>'
			.'<fills count="2"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill></fills>'
			.'<borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders>'
			.'<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
			.'<cellXfs count="2"><xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>'
			.'<xf numFmtId="0" fontId="1" fillId="0" borderId="0" xfId="0" applyFont="1"/></cellXfs>'
			.'<cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles>'
			.'</styleSheet>';
	}
}
