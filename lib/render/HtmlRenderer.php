<?php declare(strict_types = 1);

namespace Modules\Reporter\Lib\Render;

use Modules\Reporter\Lib\Core\Config;
use Modules\Reporter\Lib\Core\Format;

/**
 * One HTML document for three uses: the in-app preview (mode "screen"), the print view,
 * and the PDF (mode "pdf", fed to mPDF). The CSS avoids flexbox and grid because mPDF
 * does not support them; layout is tables and blocks, which is how print documents are
 * built anyway.
 */
final class HtmlRenderer {

	private array $report;
	private string $mode;
	private string $accent;
	private string $tz;
	private int $row_cap;

	public function __construct(array $report, string $mode = 'screen') {
		$this->report = $report;
		$this->mode = $mode === 'pdf' ? 'pdf' : 'screen';
		$this->accent = (string) ($report['branding']['accent'] ?? '#1f5f8b');
		$this->tz = (string) ($report['period']['timezone'] ?? 'UTC');
		$this->row_cap = Config::limit('pdf_max_table_rows', 500);
	}

	public static function e($s): string {
		return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
	}

	public function document(): string {
		return '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8">'
			.'<meta name="viewport" content="width=device-width, initial-scale=1">'
			.'<title>'.self::e($this->title()).'</title>'
			.'<style>'.$this->css().'</style></head>'
			.'<body class="mode-'.$this->mode.'">'.$this->body().'</body></html>';
	}

	public function title(): string {
		$b = $this->report['branding'];

		return trim(($b['customer'] !== '' ? $b['customer'].': ' : '').$b['title'].', '.$this->report['period']['label']);
	}

	/** Body split into chunks so the PDF renderer can feed mPDF a section at a time. */
	public function bodyParts(): array {
		$parts = [$this->cover()];

		foreach ($this->report['sections'] as $section) {
			$parts[] = $this->section($section);
		}

		if ($this->mode === 'screen') {
			$parts[] = $this->colophon();
		}

		return $parts;
	}

	public function body(): string {
		return '<div class="doc">'.implode('', $this->bodyParts()).'</div>';
	}

	private function cover(): string {
		$r = $this->report;
		$b = $r['branding'];
		$logo = $this->logo();

		$html = '<div class="cover"><table class="cover-table"><tr><td class="cover-title">';

		if ($b['customer'] !== '') {
			$html .= '<div class="customer">'.self::e($b['customer']).'</div>';
		}

		$html .= '<h1>'.self::e($b['title']).'</h1>'
			.'<div class="period">'.self::e($r['period']['label']).'</div>'
			.'</td>';

		if ($logo !== '') {
			$html .= '<td class="cover-logo">'.$logo.'</td>';
		}

		$html .= '</tr></table>';

		$scope = [];

		if ($r['scope']['groups']) {
			$groups = $r['scope']['groups'];
			$shown = array_slice($groups, 0, 6);
			$scope[] = self::e(implode(', ', $shown)).(count($groups) > 6 ? self::e(sprintf(' and %d more', count($groups) - 6)) : '');
		}

		if ($r['scope']['host_tags'] !== '') {
			$scope[] = 'tags '.self::e(str_replace("\n", ', ', $r['scope']['host_tags']));
		}

		$html .= '<table class="facts"><tr>'
			.'<td><span class="fact-label">Devices covered</span><br>'.Format::int($r['scope']['hosts']).'</td>'
			.'<td><span class="fact-label">Scope</span><br>'.implode('; ', $scope).'</td>'
			.'<td><span class="fact-label">Period</span><br>'
				.self::e(Format::datetime($r['period']['from'], $this->tz, 'j M Y H:i')).' to '
				.self::e(Format::datetime($r['period']['till'], $this->tz, 'j M Y H:i')).'<br>'
				.'<span class="fact-tz">'.self::e($this->tz).'</span></td>'
			.'<td><span class="fact-label">Prepared</span><br>'.self::e(Format::datetime($r['generated_at'], $this->tz, 'j M Y')).'</td>'
			.'</tr></table>';

		if ($r['stopped']) {
			$html .= '<div class="alert">This report is incomplete. '.self::e($r['stopped']).'</div>';
		}

		if ($r['scope']['unmatched_groups']) {
			$html .= '<div class="alert alert-soft">No visible host group matched: '
				.self::e(implode(', ', $r['scope']['unmatched_groups'])).'</div>';
		}

		return $html.'</div>';
	}

	private function logo(): string {
		$file = (string) ($this->report['branding']['logo'] ?? '');

		if ($file === '') {
			return '';
		}

		try {
			$path = Config::dataDir('assets').'/'.basename($file);
		}
		catch (\Throwable $e) {
			return '';
		}

		if (!is_file($path) || filesize($path) > 2 * 1024 * 1024) {
			return '';
		}

		$ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
		$mime = $ext === 'svg' ? 'image/svg+xml' : ($ext === 'png' ? 'image/png' : 'image/jpeg');

		return '<img class="logo" alt="" src="data:'.$mime.';base64,'.base64_encode((string) file_get_contents($path)).'">';
	}

	private function section(array $s): string {
		// The heading, its lede and the first block are kept on one page so a section
		// title never sits alone at the bottom of a page.
		$html = '<div class="section"><div class="keep">';
		$html .= '<h2>'.self::e($s['title']).'</h2>';

		if ($s['description'] !== '') {
			$html .= '<p class="lede">'.self::e($s['description']).'</p>';
		}

		$first = true;

		if ($s['status'] === 'error') {
			$html .= '<div class="alert">This section could not be produced: '.self::e($s['message']).'</div>';
		}
		elseif ($s['status'] === 'skipped') {
			$html .= '<div class="alert alert-soft">'.self::e($s['message']).'</div>';
		}

		foreach ($s['blocks'] as $block) {
			$chunk = '';

			switch ($block['type']) {
				case 'kpis':
					$chunk = $this->kpis($block['items']);
					break;

				case 'table':
					$chunk = $this->table($block);
					break;

				case 'chart':
					$chunk = $this->chart($block);
					break;

				case 'text':
					$chunk = '<p>'.self::e($block['text']).'</p>';
					break;
			}

			$html .= $chunk.($first ? '</div>' : '');
			$first = false;
		}

		if ($first) {
			$html .= '</div>';
		}

		if ($s['notes']) {
			$html .= '<div class="notes">';

			foreach ($s['notes'] as $note) {
				$html .= '<p>'.self::e($note).'</p>';
			}

			$html .= '</div>';
		}

		return $html.'</div>';
	}

	private function kpis(array $items): string {
		$html = '';

		foreach (array_chunk($items, 4) as $row) {
			$html .= '<table class="kpis"><tr>';

			foreach ($row as $i => $k) {
				$value = $k['value'] === null ? 'n/a' : $this->format($k['value'], $k['format'] ?? 'int', []);
				$html .= '<td class="'.($i === 0 ? 'kpi first' : 'kpi').'" style="width:25%">'
					.'<div class="kpi-value">'.$value.'</div>'
					.'<div class="kpi-label">'.self::e($k['label']).'</div>'
					.(($k['hint'] ?? '') !== '' ? '<div class="kpi-hint">'.self::e($k['hint']).'</div>' : '')
					.'</td>';
			}

			for ($pad = count($row); $pad < 4; $pad++) {
				$html .= '<td class="kpi pad" style="width:25%"></td>';
			}

			$html .= '</tr></table>';
		}

		return $html;
	}

	private function table(array $t): string {
		$columns = array_values(array_filter($t['columns'], static fn($c) => ($c['screen'] ?? true) !== false));
		$rows = $t['rows'];
		$total = count($rows);
		$limit = $t['display_limit'] > 0 ? $t['display_limit'] : $total;

		if ($this->mode === 'pdf') {
			$limit = min($limit, $this->row_cap);
		}

		$html = $t['title'] !== '' && count($t['columns']) > 0 ? '<h3>'.self::e($t['title']).'</h3>' : '';

		if ($total === 0) {
			return $html.'<p class="empty">'.self::e($t['empty']).'</p>';
		}

		$html .= '<table class="data"><thead><tr>';

		foreach ($columns as $c) {
			$html .= '<th class="'.self::align($c).'">'.self::e($c['label']).'</th>';
		}

		$html .= '</tr></thead><tbody>';

		foreach (array_slice($rows, 0, $limit) as $i => $row) {
			$html .= '<tr class="'.($i % 2 ? 'odd' : 'even').'">';

			foreach ($columns as $c) {
				$html .= '<td class="'.self::align($c).'">'.$this->format($row[$c['key']] ?? null, $c['format'] ?? 'text', $row).'</td>';
			}

			$html .= '</tr>';
		}

		$html .= '</tbody></table>';

		if ($total > $limit) {
			$html .= '<p class="more">Showing '.Format::int($limit).' of '.Format::int($total)
				.'. The spreadsheet export lists all of them.</p>';
		}

		return $html;
	}

	private static function align(array $c): string {
		$format = $c['format'] ?? 'text';

		if (in_array($format, ['int', 'delta', 'number', 'number2', 'pp', 'pct', 'pct1', 'pct3', 'duration', 'units'], true)) {
			return 'num';
		}

		return in_array($format, ['sevstrip', 'spark'], true) ? 'viz' : 'txt';
	}

	private function chart(array $c): string {
		switch ($c['kind']) {
			case 'hbar':
				$svg = Svg::hbar($c['data'], (string) ($c['options']['format'] ?? 'int'), $this->accent);
				break;

			case 'severity':
				$svg = Svg::severityColumns($c['data']['labels'], $c['data']['series']);
				break;

			default:
				return '';
		}

		return '<div class="chart">'.($c['title'] !== '' ? '<h3>'.self::e($c['title']).'</h3>' : '')
			.$this->embed($svg).'</div>';
	}

	/** Inline SVG on screen; a data URI image for mPDF, whose inline SVG support is patchier. */
	private function embed(string $svg): string {
		if ($this->mode === 'screen') {
			return $svg;
		}

		preg_match("~width='(\\d+)' height='(\\d+)'~", $svg, $m);
		$w = (int) ($m[1] ?? 100);
		$h = (int) ($m[2] ?? 20);

		// 96 px per inch in the source SVG; mPDF sizes images in mm.
		return sprintf('<img src="data:image/svg+xml;base64,%s" style="width:%.1fmm;height:%.1fmm">',
			base64_encode($svg), $w * 25.4 / 96, $h * 25.4 / 96);
	}

	public function format($value, string $format, array $row): string {
		if ($value === null || $value === '') {
			return in_array($format, ['text', 'sevstrip', 'spark'], true) ? '' : '<span class="dim">n/a</span>';
		}

		switch ($format) {
			case 'int': return Format::int($value);
			case 'number': return Format::number($value, 1);
			case 'number2': return Format::number($value, 2);
			case 'delta':
				$v = (int) $value;

				return $v === 0 ? '<span class="dim">0</span>' : self::e(($v > 0 ? '+' : '').Format::int($v));
			case 'pp': return self::e(((float) $value >= 0 ? '+' : '').Format::number($value, 1).' pp');
			case 'pct': return self::e(Format::pct($value, 2));
			case 'pct1': return self::e(Format::pct($value, 1));
			case 'pct3': return self::e(Format::pct($value, 3));
			case 'duration': return self::e(Format::duration($value));
			case 'units': return self::e(Format::units($value, (string) ($row['units'] ?? '')));
			case 'datetime': return self::e(Format::datetime((int) $value, $this->tz, 'j M H:i'));
			case 'date': return self::e(Format::datetime((int) $value, $this->tz, 'j M Y'));
			case 'severity':
				$s = max(0, min(5, (int) $value));

				return $this->embed(Svg::swatch(Svg::SEVERITY_COLORS[$s])).' '.self::e(Svg::SEVERITY_NAMES[$s]);
			case 'sevstrip': return is_array($value) ? $this->embed(Svg::sevStrip($value)) : '';
			case 'spark': return is_array($value) ? $this->embed(Svg::sparkline($value, $this->accent)) : '';
			default: return self::e(is_array($value) ? implode(', ', $value) : $value);
		}
	}

	private function colophon(): string {
		$s = $this->report['stats'];

		return '<p class="colophon">Built in '.self::e(number_format((float) $s['seconds'], 1)).' s with '
			.Format::int($s['api_calls']).' API calls returning '.Format::int($s['api_rows']).' rows.</p>';
	}

	public function css(): string {
		$a = $this->accent;
		$pdf = $this->mode === 'pdf';

		$base = $pdf ? '9pt' : '14px';
		$small = $pdf ? '7.5pt' : '12px';
		$table = $pdf ? '8pt' : '13px';

		return <<<CSS
body { margin: 0; color: #1d2433; background: #ffffff; font-family: 'DejaVu Sans', 'Segoe UI', Arial, sans-serif;
	font-size: {$base}; line-height: 1.4; }
.doc { max-width: 1040px; margin: 0 auto; padding: 0; }
body.mode-screen .doc { padding: 28px 32px 40px; }
h1 { font-size: 22pt; line-height: 1.15; margin: 2px 0 4px; font-weight: bold; color: #1d2433; }
h2 { font-size: 13pt; margin: 0 0 3px; padding: 0; font-weight: bold; color: #1d2433; }
h3 { font-size: {$base}; margin: 14px 0 6px; font-weight: bold; color: #1d2433; }
p { margin: 0 0 6px; }
.cover { margin-bottom: 18px; }
.cover-table { width: 100%; border-collapse: collapse; }
.cover-title { border-left: 5px solid {$a}; padding: 2px 0 2px 14px; vertical-align: bottom; }
.cover-logo { text-align: right; vertical-align: bottom; width: 35%; }
.logo { max-height: 56px; max-width: 220px; }
.customer { color: #5b6577; font-size: {$base}; }
.period { font-size: 12pt; color: {$a}; font-weight: bold; }
.facts { width: 100%; border-collapse: collapse; margin-top: 16px; border-top: 1px solid #d9dee7;
	border-bottom: 1px solid #d9dee7; }
.facts td { padding: 7px 12px 7px 0; vertical-align: top; font-size: {$small}; color: #1d2433; }
.fact-label, .fact-tz { color: #5b6577; }
.section { margin-top: 22px; page-break-inside: auto; }
.keep { page-break-inside: avoid; }
.lede { color: #5b6577; margin-bottom: 10px; font-size: {$small}; }
.kpis { width: 100%; border-collapse: collapse; margin: 6px 0 10px; }
.kpi { padding: 4px 14px; vertical-align: top; border-left: 1px solid #d9dee7; }
.kpi.first { border-left: 0; padding-left: 0; }
.kpi-value { font-family: 'DejaVu Sans Condensed', 'DejaVu Sans', Arial, sans-serif; font-size: 17pt;
	font-weight: bold; line-height: 1.1; color: #1d2433; }
.kpi-label { font-size: {$small}; color: #5b6577; margin-top: 2px; }
.kpi-hint { font-size: {$small}; color: {$a}; }
table.data { width: 100%; border-collapse: collapse; font-family: 'DejaVu Sans Condensed', 'DejaVu Sans', Arial,
	sans-serif; font-size: {$table}; margin: 2px 0 6px; }
table.data th { text-align: left; font-weight: bold; color: #5b6577; padding: 5px 6px; border-bottom: 1.5px solid #1d2433;
	vertical-align: bottom; }
table.data td { padding: 4px 6px; border-bottom: 1px solid #e6e9ef; vertical-align: middle; }
table.data tr.odd td { background: #f5f7fa; }
table.data .num { text-align: right; white-space: nowrap; }
table.data .viz { white-space: nowrap; }
.dim, .empty, .more { color: #5b6577; }
.more { font-size: {$small}; }
.chart { margin: 8px 0 4px; }
.chart svg { max-width: 100%; height: auto; }
.notes { margin-top: 8px; border-top: 1px solid #e6e9ef; padding-top: 5px; }
.notes p { font-size: {$small}; color: #5b6577; margin: 0 0 2px; }
.alert { border-left: 4px solid #e45959; background: #fdf1f1; padding: 7px 10px; margin: 8px 0; }
.alert-soft { border-left-color: #ffa059; background: #fff7ef; }
.colophon { margin-top: 30px; color: #5b6577; font-size: {$small}; }
CSS;
	}
}
