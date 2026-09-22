<?php declare(strict_types = 1);

namespace Modules\Reporter\Lib\Render;

use Modules\Reporter\Lib\Core\Config;
use Modules\Reporter\Lib\Core\Format;

/**
 * PDF through mPDF, in-process. No headless browser, no extra service. Only the bundled
 * DejaVu fonts are registered, which keeps the vendored library small and the output
 * identical on every host.
 */
final class PdfRenderer {

	public static function available(): bool {
		return reporter_vendor() && class_exists(\Mpdf\Mpdf::class);
	}

	public static function render(array $report): string {
		if (!self::available()) {
			throw new \RuntimeException('PDF export needs the bundled vendor/ directory, which is missing from this installation.');
		}

		$html = new HtmlRenderer($report, 'pdf');
		$tmp = Config::dataDir('tmp/mpdf');
		$font_dir = REPORTER_ROOT.'/vendor/mpdf/mpdf/ttfonts';

		$mpdf = new \Mpdf\Mpdf([
			'mode' => 'utf-8',
			'format' => $report['branding']['paper'] === 'A4' ? 'A4' : 'Letter',
			'tempDir' => $tmp,
			'fontDir' => [$font_dir],
			'fontdata' => [
				'dejavusans' => ['R' => 'DejaVuSans.ttf', 'B' => 'DejaVuSans-Bold.ttf', 'I' => 'DejaVuSans-Oblique.ttf',
					'BI' => 'DejaVuSans-BoldOblique.ttf'],
				'dejavusanscondensed' => ['R' => 'DejaVuSansCondensed.ttf', 'B' => 'DejaVuSansCondensed-Bold.ttf',
					'I' => 'DejaVuSansCondensed-Oblique.ttf', 'BI' => 'DejaVuSansCondensed-BoldOblique.ttf']
			],
			'default_font' => 'dejavusans',
			'margin_left' => 16,
			'margin_right' => 16,
			'margin_top' => 18,
			'margin_bottom' => 16,
			'margin_header' => 8,
			'margin_footer' => 8,
			'packTableData' => true,
			'use_kwt' => true,
			'shrink_tables_to_fit' => 1,
			'autoScriptToLang' => false,
			'autoLangToFont' => false,
			'useSubstitutions' => false
		]);

		$mpdf->SetTitle($html->title());
		$mpdf->SetCreator('Zabbix report builder '.REPORTER_VERSION);
		$mpdf->SetAuthor((string) $report['branding']['customer']);

		$style = 'font-family: dejavusanscondensed; font-size: 7pt; color: #5b6577;';
		$left = HtmlRenderer::e($report['branding']['footer'] !== '' ? $report['branding']['footer'] : $html->title());

		$mpdf->SetHTMLFooter('<table width="100%" style="'.$style.' border-top: 0.5px solid #d9dee7;"><tr>'
			.'<td style="padding-top: 3px;">'.$left.'</td>'
			.'<td style="padding-top: 3px; text-align: right; white-space: nowrap;">Page {PAGENO} of {nbpg}</td>'
			.'</tr></table>');

		$mpdf->WriteHTML($html->css(), \Mpdf\HTMLParserMode::HEADER_CSS);

		foreach ($html->bodyParts() as $i => $part) {
			if ($i === 1) {
				// From the second page on, a running header; the cover page has its own.
				$mpdf->SetHTMLHeader('<table width="100%" style="'.$style.'"><tr>'
					.'<td>'.HtmlRenderer::e($report['branding']['title']).'</td>'
					.'<td style="text-align: right;">'.HtmlRenderer::e($report['period']['label']).'</td>'
					.'</tr></table>');
			}

			$mpdf->WriteHTML($part, \Mpdf\HTMLParserMode::HTML_BODY);
		}

		return $mpdf->Output('', \Mpdf\Output\Destination::STRING_RETURN);
	}

	public static function filename(array $report, string $ext): string {
		$base = preg_replace('/[^A-Za-z0-9._-]+/', '-', $report['id'].'-'.Format::datetime(
			(int) $report['period']['from'], (string) $report['period']['timezone'], 'Y-m-d'
		));

		return trim((string) $base, '-').'.'.$ext;
	}
}
