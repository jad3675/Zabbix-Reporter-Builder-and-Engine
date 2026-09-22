<?php declare(strict_types = 1);

/*
 * php -d memory_limit=256M tests/scale.php [hosts] [days]
 * Runs every built-in section over a large fake fleet and reports API calls, rows, time
 * and peak memory, with the production limits from manifest.json.
 */

require __DIR__.'/../lib/bootstrap.php';
require __DIR__.'/FakeApi.php';

use Modules\Reporter\Lib\Core\Config;
use Modules\Reporter\Lib\Core\Definition;
use Modules\Reporter\Lib\Core\Period;
use Modules\Reporter\Lib\Core\Runner;
use Modules\Reporter\Lib\Render\PdfRenderer;
use Modules\Reporter\Lib\Render\TableExport;
use Modules\Reporter\Lib\Render\XlsxWriter;
use Modules\Reporter\Lib\Section\Registry;

$hosts = (int) ($argv[1] ?? 2500);
$days = (int) ($argv[2] ?? 31);
$config = json_decode((string) file_get_contents(__DIR__.'/../manifest.json'), true)['config'];
$config['data_dir'] = sys_get_temp_dir().'/reporter-scale';
Config::set($config);

$period = Period::custom('2026-08-01', date('Y-m-d', strtotime('2026-08-01 +'.($days - 1).' days')), 'UTC');
$t = microtime(true);
$fake = new FakeApi($period->from, $period->till, $hosts);
printf("fake fleet of %d hosts built in %.1fs, memory %.0f MB\n", $hosts, microtime(true) - $t, memory_get_usage(true) / 1048576);
$base = memory_get_usage(true);

$registry = new Registry();
[$def, $errors] = Definition::normalize([
	'id' => 'scale', 'name' => 'Scale', 'scope' => ['groups' => ['CCH/*']],
	'sections' => array_map(static fn($t) => ['type' => $t, 'options' => ['include_quiet' => true]],
		array_keys($registry->all()))
], $registry);

$limits = $config['limits'];
$limits['max_rows_total'] = $limits['max_rows_frontend'];
$limits['time_budget'] = $limits['time_budget_frontend'];

$t = microtime(true);
$report = (new Runner($registry))->run($def, $period, $fake, $limits);
$run = microtime(true) - $t;

foreach ($report['sections'] as $s) {
	printf("  %-22s %-6s %5.2fs %s\n", $s['type'], $s['status'], $s['seconds'], $s['message']);
}

$t = microtime(true);
$pdf = PdfRenderer::render($report);
$pdf_s = microtime(true) - $t;
$t = microtime(true);
$xlsx = XlsxWriter::write(TableExport::sheets($report));
$xlsx_s = microtime(true) - $t;

printf("run %.1fs, %d API calls, %d rows; PDF %.1fs %d KB; XLSX %.1fs %d KB; peak memory above fake %.0f MB\n",
	$run, $report['stats']['api_calls'], $report['stats']['api_rows'], $pdf_s, strlen($pdf) / 1024, $xlsx_s,
	strlen($xlsx) / 1024, (memory_get_peak_usage(true) - $base) / 1048576);
$counts = array_count_values($fake->calls);
ksort($counts);
echo '  calls: '.json_encode($counts)."\n";
