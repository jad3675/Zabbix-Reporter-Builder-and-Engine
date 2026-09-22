<?php declare(strict_types = 1);

namespace Modules\Reporter\Lib\Render;

final class Exporter {

	public const MIME = [
		'pdf' => 'application/pdf',
		'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
		'csv' => 'text/csv; charset=utf-8'
	];

	/** @return array{0: string, 1: string, 2: string}  bytes, filename, mime */
	public static function export(array $report, string $format): array {
		switch ($format) {
			case 'pdf':
				$bytes = PdfRenderer::render($report);
				break;

			case 'xlsx':
				$bytes = XlsxWriter::write(TableExport::sheets($report));
				break;

			case 'csv':
				$bytes = CsvWriter::write(TableExport::sheets($report));
				break;

			default:
				throw new \InvalidArgumentException(sprintf('Unknown export format "%s".', $format));
		}

		return [$bytes, PdfRenderer::filename($report, $format), self::MIME[$format]];
	}
}
