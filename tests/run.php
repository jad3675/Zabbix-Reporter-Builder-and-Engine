<?php declare(strict_types = 1);

/*
 * php tests/run.php [--out DIR]
 *
 * Unit tests for the parts where a quiet bug produces a plausible wrong number, then an
 * end-to-end run of every built-in section against FakeApi, rendered to HTML, PDF, XLSX
 * and CSV. With --out the rendered files are kept for inspection.
 */

require __DIR__.'/../lib/bootstrap.php';
require __DIR__.'/FakeApi.php';

use Modules\Reporter\Lib\Api\ApiException;
use Modules\Reporter\Lib\Api\BudgetExceeded;
use Modules\Reporter\Lib\Api\Guard;
use Modules\Reporter\Lib\Core\Config;
use Modules\Reporter\Lib\Core\Definition;
use Modules\Reporter\Lib\Core\Period;
use Modules\Reporter\Lib\Core\Runner;
use Modules\Reporter\Lib\Core\Schedule;
use Modules\Reporter\Lib\Core\Stats;
use Modules\Reporter\Lib\Core\TagFilter;
use Modules\Reporter\Lib\Core\Format;
use Modules\Reporter\Lib\Data\Paginator;
use Modules\Reporter\Lib\Data\Problems;
use Modules\Reporter\Lib\Render\CsvWriter;
use Modules\Reporter\Lib\Render\HtmlRenderer;
use Modules\Reporter\Lib\Render\PdfRenderer;
use Modules\Reporter\Lib\Render\TableExport;
use Modules\Reporter\Lib\Render\XlsxWriter;
use Modules\Reporter\Lib\Section\Registry;

$out_dir = null;

foreach ($argv as $i => $arg) {
	if ($arg === '--out') {
		$out_dir = $argv[$i + 1] ?? null;
	}
}

$data_dir = sys_get_temp_dir().'/reporter-test-'.getmypid();
$manifest = json_decode((string) file_get_contents(__DIR__.'/../manifest.json'), true);
$config = $manifest['config'];
$config['data_dir'] = $data_dir;
Config::set($config);

$passed = 0;
$failed = 0;

function check(string $name, bool $ok, string $detail = ''): void {
	global $passed, $failed;

	if ($ok) {
		$passed++;
	}
	else {
		$failed++;
		echo "FAIL  $name".($detail !== '' ? "  ($detail)" : '')."\n";
	}
}

function throws(callable $fn, string $class): bool {
	try {
		$fn();
	}
	catch (\Throwable $e) {
		return $e instanceof $class;
	}

	return false;
}

// ---------------------------------------------------------------- Period

$ny = 'America/New_York';
$p = Period::resolve('previous_month', 30, $ny, new DateTimeImmutable('2026-03-15 10:00', new DateTimeZone($ny)));
check('previous month starts at local midnight', Format::datetime($p->from, $ny, 'Y-m-d H:i:s') === '2026-02-01 00:00:00');
check('previous month ends at last second', Format::datetime($p->till, $ny, 'Y-m-d H:i:s') === '2026-02-28 23:59:59');
check('previous month label', $p->label === 'February 2026');
check('previous month day count', count($p->dayStarts()) === 28);

// DST: March 2026 in New York has a 23-hour day.
$p = Period::resolve('previous_month', 30, $ny, new DateTimeImmutable('2026-04-02', new DateTimeZone($ny)));
check('DST month has 31 day starts', count($p->dayStarts()) === 31);
check('DST month seconds', $p->seconds() === 31 * 86400 - 3600);
$starts = $p->dayStarts();
check('dayIndex after DST shift', Period::dayIndex($starts, $starts[20] + 1) === 20);
check('dayIndex last second', Period::dayIndex($starts, $p->till) === 30);

$p = Period::resolve('previous_week', 0, 'UTC', new DateTimeImmutable('2026-09-23 12:00', new DateTimeZone('UTC')));
check('previous week is Monday to Sunday', gmdate('D Y-m-d', $p->from) === 'Mon 2026-09-14'
	&& gmdate('D Y-m-d H:i:s', $p->till) === 'Sun 2026-09-20 23:59:59');

$p = Period::custom('2026-08-01', '2026-08-31', 'UTC');
check('custom period inclusive', $p->seconds() === 31 * 86400);
check('custom period rejects bad dates', throws(fn() => Period::custom('08/01/2026', '2026-08-31', 'UTC'),
	\InvalidArgumentException::class));

// ---------------------------------------------------------------- Tags, stats, format

$tags = TagFilter::parse("site\nsite=Burnet\nrole~core\n!decom\nenv!=lab\n\n");
check('tag parse count', count($tags) === 5);
check('tag operators', array_column($tags, 'operator') === ['exists', 'equals', 'contains', 'not_exists', 'not_equals']);
check('tag round trip', TagFilter::parse(TagFilter::format($tags)) === $tags);
check('tag api operators', array_column(TagFilter::toApi($tags), 'operator') === [4, 1, 0, 5, 3]);

$fit = Stats::linreg([0, 1, 2, 3], [10, 12, 14, 16]);
check('linreg slope', abs($fit['slope'] - 2.0) < 1e-9 && abs($fit['intercept'] - 10.0) < 1e-9 && abs($fit['r2'] - 1) < 1e-9);
check('linreg degenerate', Stats::linreg([1, 1], [1, 2]) === null);

check('duration formatting', Format::duration(0) === '0m' && Format::duration(45) === '<1m'
	&& Format::duration(3720) === '1h 2m' && Format::duration(90000) === '1d 1h');
check('binary units', Format::units(1536, 'B') === '1.50 KB');
check('SI units', Format::units(2500000, 'bps') === '2.50 Mbps');
check('percent units', Format::units(93.456, '%') === '93.5%');

// ---------------------------------------------------------------- Guard

$fake = new FakeApi(strtotime('2026-08-01 00:00 UTC'), strtotime('2026-08-31 23:59:59 UTC'), 12);
$guard = new Guard($fake, ['time_budget' => 60, 'max_api_calls' => 10, 'max_rows_total' => 100000]);
check('guard refuses writes', throws(fn() => $guard->call('host.update', []), ApiException::class));
check('guard refuses unknown objects', throws(fn() => $guard->call('user.get', []), ApiException::class));
for ($i = 0; $i < 10; $i++) {
	$guard->call('hostgroup.get', ['output' => ['groupid']]);
}
check('guard stops at call budget', throws(fn() => $guard->call('hostgroup.get', []), BudgetExceeded::class));

// ---------------------------------------------------------------- Paginator

$from = strtotime('2026-08-01 00:00 UTC');
$till = strtotime('2026-08-31 23:59:59 UTC');
$fake = new FakeApi($from, $till, 60);
$all = [];
Paginator::byClock($fake, 'event.get', ['value' => 1, 'hostids' => array_map('strval', range(10001, 10060))], 'eventid',
	$from, $till, 100000, function ($e) use (&$all) { $all[$e['eventid']] = true; });
$paged = [];
$dupes = 0;
$stats = Paginator::byClock($fake, 'event.get', ['value' => 1, 'hostids' => array_map('strval', range(10001, 10060))],
	'eventid', $from, $till, 3, function ($e) use (&$paged, &$dupes) {
		if (isset($paged[$e['eventid']])) {
			$dupes++;
		}

		$paged[$e['eventid']] = true;
	});
check('paging with tiny pages returns every row once', $dupes === 0 && count($paged) === count($all),
	sprintf('all=%d paged=%d dupes=%d', count($all), count($paged), $dupes));
check('paging needed many pages', $stats['pages'] > 10);

check('open seconds clipped to period', Problems::openSeconds(['clock' => $from - 100, 'r_clock' => $from + 50], $from, $till) === 50
	&& Problems::openSeconds(['clock' => $till - 9, 'r_clock' => null], $from, $till) === 10);

// ---------------------------------------------------------------- Drop-in sections

$drop_dir = sys_get_temp_dir().'/reporter-sections-'.getmypid();
@mkdir($drop_dir);
copy(__DIR__.'/../sections/problems_by_hour.php.example', $drop_dir.'/problems_by_hour.php');
file_put_contents($drop_dir.'/broken.php', '<?php return 42;');
$drop_registry = new Registry($drop_dir);
check('example drop-in registers', $drop_registry->has('problems_by_hour'));
check('broken drop-in reported, not fatal', count($drop_registry->loadErrors()) === 1);
exec('rm -rf '.escapeshellarg($drop_dir));

// ---------------------------------------------------------------- Definition

$registry = new Registry(__DIR__.'/../sections');
check('registry has built-ins', count($registry->all()) >= 7, implode(',', array_keys($registry->all())));
check('registry loads drop-ins cleanly', $registry->loadErrors() === [], implode('; ', $registry->loadErrors()));

[$def, $errors] = Definition::normalize(['id' => 'x'], $registry);
check('definition requires scope and name', count($errors) >= 2);

[, $errors] = Definition::normalize(['id' => 'Bad ID!', 'name' => 'n', 'scope' => ['groups' => ['a']],
	'sections' => [['type' => 'summary']]], $registry);
check('definition rejects bad id', count($errors) === 1);

[$def, $errors] = Definition::normalize([
	'id' => 'cch-monthly', 'name' => 'CCH monthly', 'timezone' => 'Nowhere/Invalid',
	'scope' => ['groups' => "CCH/*\n\nCCH/*"],
	'branding' => ['accent' => 'red', 'logo' => '../../etc/passwd'],
	'sections' => [['type' => 'top_metrics', 'options' => ['item_tags' => '', 'keys' => '', 'limit' => 99999,
		'bogus' => 1]]],
	'delivery' => ['email_to' => 'a@example.com, not-an-email']
], $registry);
check('invalid timezone falls back', $def['timezone'] === 'UTC');
check('groups deduplicated', $def['scope']['groups'] === ['CCH/*']);
check('accent validated', $def['branding']['accent'] === '#1f5f8b');
check('logo path traversal refused', $def['branding']['logo'] === '');
check('options clamped and unknown dropped', $def['sections'][0]['options']['limit'] === 200
	&& !isset($def['sections'][0]['options']['bogus']));
check('section validation reported', (bool) preg_grep('/Set item tags/', $errors));
check('bad email reported', (bool) preg_grep('/not-an-email/', $errors));

// ---------------------------------------------------------------- Schedule

$sdef = ['timezone' => 'UTC', 'period' => ['type' => 'previous_month', 'n' => 30],
	'schedule' => ['enabled' => true, 'cycle' => 'monthly', 'day' => 1, 'hour' => 6]];
$due = Schedule::due($sdef, null, new DateTimeImmutable('2026-09-01 07:00 UTC'));
check('monthly schedule due after slot', $due !== null && $due->label === 'August 2026');
check('monthly schedule not due before slot', Schedule::due($sdef, null, new DateTimeImmutable('2026-09-01 05:00 UTC')) === null);
check('monthly schedule not repeated', Schedule::due($sdef, $due->key(), new DateTimeImmutable('2026-09-12 09:00 UTC')) === null);
check('missed slot picked up later', Schedule::due($sdef, 'old', new DateTimeImmutable('2026-09-03 02:00 UTC')) !== null);

// ---------------------------------------------------------------- Settings and secrets

use Modules\Reporter\Lib\Core\Requests;
use Modules\Reporter\Lib\Core\Secrets;
use Modules\Reporter\Lib\Core\Settings;

[$set, $errors] = Settings::normalize([
	'access' => ['min_user_type_view' => 0, 'min_user_type_edit' => 1],
	'runner' => ['api_url' => 'https://bob:secret@zabbix.example/'],
	'delivery' => ['mediatypeid' => '1; DROP', 'retention_days' => -5],
	'limits' => ['max_hosts' => 10000000, 'max_concurrent_runs' => 0, 'unknown' => 5],
	'data_dir' => '/etc', 'output' => ['dir' => '/root']
]);
check('settings clamp access levels', $set['access']['min_user_type_view'] === 1 && $set['access']['min_user_type_edit'] === 2);
check('settings refuse credentials in URL', (bool) preg_grep('/must not contain a user name/', $errors));
check('settings refuse a bad media type id', $set['delivery']['mediatypeid'] === '' && $set['delivery']['retention_days'] === 0);
check('settings clamp limits to ceilings', $set['limits']['max_hosts'] === 20000 && $set['limits']['max_concurrent_runs'] === 1
	&& !isset($set['limits']['unknown']));
check('settings cannot set paths', !isset($set['data_dir']) && !isset($set['output']));

$media = ['mediatypeid' => '1', 'name' => 'Email (HTML)', 'smtp_server' => 'smtp.a', 'smtp_port' => '587',
	'smtp_helo' => '', 'smtp_email' => 'Zabbix <z@a.io>', 'smtp_security' => '1', 'smtp_verify_peer' => '1',
	'smtp_verify_host' => '1', 'smtp_authentication' => '1', 'username' => 'u', 'passwd' => 'hunter2'];
Secrets::setSmtp($media, 'Admin');
Secrets::setToken(str_repeat('ab', 32), 'Admin');
$st = Secrets::status();
check('secret status set', $st['token_set'] && $st['smtp']['name'] === 'Email (HTML)' && $st['smtp']['copied_by'] === 'Admin');
check('status never carries secrets', strpos(json_encode($st), 'hunter2') === false && strpos(json_encode($st), 'abab') === false);
check('runner reads the copies', Secrets::smtp()['passwd'] === 'hunter2' && Secrets::token() === str_repeat('ab', 32));
$changed = $media;
$changed['passwd'] = 'new';
check('fingerprint detects a changed media type', Secrets::fingerprint($changed) !== $st['smtp']['fingerprint']);
check('secrets file is private to its owner', (fileperms(Config::dataDir().'/secrets.json') & 0077) === 0);
Secrets::setToken(null, 'Admin');
check('token can be removed', !Secrets::status()['token_set']);

$rid = Requests::enqueue('run_report', ['id' => 'x'], 'Admin');
check('request queued', count(Requests::pending()) === 1);
Requests::complete(Requests::pending()[0], true, 'Sent.');
check('request completed', Requests::pending() === [] && Requests::results()[0]['id'] === $rid && Requests::results()[0]['ok']);
check('unknown request type refused', throws(fn() => Requests::enqueue('shell', [], 'x'), \InvalidArgumentException::class));

// ---------------------------------------------------------------- End to end

$period = Period::custom('2026-08-01', '2026-08-31', 'UTC');
$fake = new FakeApi($period->from, $period->till, 60);

[$def, $errors] = Definition::normalize([
	'id' => 'cch-monthly',
	'name' => 'CCH monthly',
	'timezone' => 'UTC',
	'scope' => ['groups' => ['CCH/*']],
	'branding' => ['title' => 'Monthly service report', 'customer' => "Cincinnati Children's", 'accent' => '#1f5f8b'],
	'sections' => [
		['type' => 'summary'],
		['type' => 'problems_by_host', 'options' => ['group_by_tag' => 'site', 'display_limit' => 20]],
		['type' => 'top_triggers'],
		['type' => 'top_metrics', 'title' => 'Busiest CPUs', 'options' => ['item_tags' => 'component=cpu']],
		['type' => 'top_metrics', 'title' => 'Busiest uplinks', 'options' => ['item_tags' => 'component=network',
			'aggregate' => 'max']],
		['type' => 'capacity_growth'],
		['type' => 'availability', 'options' => ['show' => 'worst']],
		['type' => 'maintenance'],
		['type' => 'problems_by_hour']
	]
], $drop_registry);
check('sample definition valid', $errors === [], implode('; ', $errors));

$limits = $config['limits'];
$limits['max_rows_total'] = $limits['max_rows_frontend'];
$limits['time_budget'] = 60;
$limits['page_limit'] = 7;

$report = (new Runner($drop_registry))->run($def, $period, $fake, $limits);
$statuses = array_column($report['sections'], 'status');
check('every section ran', $statuses === array_fill(0, 9, 'ok'),
	json_encode(array_map(fn($s) => [$s['type'], $s['status'], $s['message']], $report['sections'])));
check('lab host excluded by scope', $report['scope']['hosts'] === 60);

// Cross-check the per-device table against the raw fake data.
$devices = null;

foreach ($report['sections'][1]['blocks'] as $b) {
	if ($b['type'] === 'table' && $b['title'] === 'Devices') {
		$devices = $b;
	}
}

$total_from_table = array_sum(array_column($devices['rows'], 'problems'));
$kpi_total = null;

foreach ($report['sections'][0]['blocks'][0]['items'] as $k) {
	if ($k['label'] === 'Problems raised') {
		$kpi_total = $k['value'];
	}
}

check('per-device totals equal summary total', $total_from_table === $kpi_total, "$total_from_table vs $kpi_total");
check('only write-free methods called', !preg_grep('/\.(create|update|delete)$/', $fake->calls));

$counts = array_count_values($fake->calls);
check('problems fetched once for all sections', ($counts['event.get'] ?? 0) < 60, json_encode($counts));

$maint_rows = $report['sections'][7]['blocks'][0]['rows'];
check('maintenance outside period excluded', !in_array('Old window', array_column($maint_rows, 'name'), true));
check('maintenance via host group counted', in_array('Server patching', array_column($maint_rows, 'name'), true));

$budget_report = (new Runner($drop_registry))->run($def, $period, $fake, ['max_api_calls' => 12] + $limits);
check('budget stop is reported, rest skipped', $budget_report['stopped'] !== null
	&& in_array('skipped', array_column($budget_report['sections'], 'status'), true));

// Renderers.
$html = (new HtmlRenderer($report, 'screen'))->document();
check('html renders', strlen($html) > 10000 && strpos($html, 'Monthly service report') !== false);
check('html escapes customer', strpos($html, 'Cincinnati Children&#039;s') !== false);

$sheets = TableExport::sheets($report);
$csv = CsvWriter::write($sheets);
check('csv has every device row', substr_count($csv, 'core-sw-') + substr_count($csv, 'srv-') > 20);
check('csv neutralizes formulas', CsvWriter::safe('=HYPERLINK("x")') === "'=HYPERLINK(\"x\")" && CsvWriter::safe('-5') === '-5');

if (XlsxWriter::available()) {
	$xlsx = XlsxWriter::write($sheets);
	$tmp = tempnam(sys_get_temp_dir(), 'x');
	file_put_contents($tmp, $xlsx);
	$zip = new ZipArchive();
	check('xlsx opens as zip', $zip->open($tmp) === true);
	$wb = $zip->getFromName('xl/workbook.xml');
	check('xlsx workbook xml valid', @simplexml_load_string((string) $wb) !== false);
	$ok = true;

	for ($i = 1; $i <= count($sheets); $i++) {
		$ok = $ok && @simplexml_load_string((string) $zip->getFromName("xl/worksheets/sheet$i.xml")) !== false;
	}

	check('xlsx sheets are valid xml', $ok);
	$zip->close();
	@unlink($tmp);
}

$t0 = microtime(true);
$pdf = PdfRenderer::available() ? PdfRenderer::render($report) : '';
check('pdf renders', substr($pdf, 0, 5) === '%PDF-', PdfRenderer::available() ? '' : 'vendor missing');
$pdf_seconds = microtime(true) - $t0;

if ($out_dir !== null) {
	@mkdir($out_dir, 0777, true);
	file_put_contents($out_dir.'/sample.html', $html);
	file_put_contents($out_dir.'/sample.pdf', $pdf);
	file_put_contents($out_dir.'/sample.csv', $csv);

	if (isset($xlsx)) {
		file_put_contents($out_dir.'/sample.xlsx', $xlsx);
	}
}

// Cleanup.
exec('rm -rf '.escapeshellarg($data_dir));

printf("\n%d passed, %d failed  (end-to-end: %d API calls, %d rows, PDF %.1fs, peak memory %.0f MB)\n", $passed, $failed,
	$report['stats']['api_calls'], $report['stats']['api_rows'], $pdf_seconds, memory_get_peak_usage(true) / 1048576);

exit($failed > 0 ? 1 : 0);
