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

// Fixed periods: one picked day, and a range down to the minute.
$p = Period::day('2026-11-11', $ny);
check('single day label', $p->label === 'Wednesday 11 November 2026', $p->label);
check('single day is midnight to midnight', Format::datetime($p->from, $ny, 'Y-m-d H:i:s') === '2026-11-11 00:00:00'
	&& Format::datetime($p->till, $ny, 'Y-m-d H:i:s') === '2026-11-11 23:59:59');
check('single day across DST is 23 hours', Period::day('2026-03-08', $ny)->seconds() === 23 * 3600);
check('single day rejects a bad date', throws(fn() => Period::day('2026-02-30', $ny), \InvalidArgumentException::class)
	&& throws(fn() => Period::day('', $ny), \InvalidArgumentException::class));

$p = Period::range('2026-11-11T08:00', '2026-11-11 17:00', $ny);
check('range with times ends before the end minute', $p->seconds() === 9 * 3600
	&& Format::datetime($p->till, $ny, 'H:i:s') === '16:59:59');
check('range within a day labels the times', $p->label === '11 Nov 2026, 08:00 to 17:00', $p->label);
$p = Period::range('2026-11-09 22:00', '2026-11-12 06:30', $ny);
check('range across days labels both ends', $p->label === '9 Nov 2026 22:00 to 12 Nov 2026 06:30', $p->label);
check('range of whole dates matches custom', Period::range('2026-08-01', '2026-08-31', 'UTC')->key()
	=== Period::custom('2026-08-01', '2026-08-31', 'UTC')->key());
check('a date as the end means that whole day', Period::range('2026-08-01 12:00', '2026-08-01', 'UTC')->seconds() === 12 * 3600);
check('range refuses an end before the start', throws(fn() => Period::range('2026-11-12 08:00', '2026-11-12 08:00', $ny),
	\InvalidArgumentException::class));
check('range refuses impossible times', throws(fn() => Period::range('2026-11-12 25:00', '2026-11-13', $ny),
	\InvalidArgumentException::class));
check('fromSpec reads a stored day', Period::fromSpec(['type' => 'day', 'date' => '2026-11-11'], $ny)->label
	=== 'Wednesday 11 November 2026');
check('describe a relative period', Period::describe(['type' => 'last_n_days', 'n' => 14]) === 'Last N days (14)');
check('describe an unset fixed period', strpos(Period::describe(['type' => 'day', 'date' => '']), 'not set') !== false);

// ---------------------------------------------------------------- Interfaces

use Modules\Reporter\Lib\Data\Interfaces;

check('interface key: SNMP with OID name', Interfaces::parseKey('net.if.in[ifHCInOctets.5]') === ['in', '5']);
check('interface key: errors before in', Interfaces::parseKey('net.if.in.errors[ifInErrors.10101]') === ['in_errors', '10101']);
check('interface key: 7.x bare index', Interfaces::parseKey('net.if.out.discards[17]') === ['out_discards', '17']);
check('interface key: agent name', Interfaces::parseKey('net.if.in["eth0"]') === ['in', 'eth0']);
check('interface key: agent errors mode', Interfaces::parseKey('net.if.out[eth0,errors]') === ['out_errors', 'eth0']);
check('interface key: agent packets ignored', Interfaces::parseKey('net.if.in[eth0,packets]') === null);
check('interface key: not an interface', Interfaces::parseKey('net.if.discovery') === null
	&& Interfaces::parseKey('net.if.type[ifType.5]') === null);
check('labels from the older name', Interfaces::labels('Interface Gi1/0/1(Uplink (po1)): Bits received', [])
	=== ['Gi1/0/1', 'Uplink (po1)']);
check('labels from the 7.x name', Interfaces::labels('Interface [Gi1/0/1(Uplink)]: Bits sent', []) === ['Gi1/0/1', 'Uplink']);
check('labels with no alias', Interfaces::labels('Interface eth0(): Bits sent', []) === ['eth0', '']
	&& Interfaces::labels('Interface eth0: Bits sent', []) === ['eth0', '']);
check('tags beat the name, unresolved macros do not', Interfaces::labels('Interface Gi1/0/1(Old): Bits sent',
	[['tag' => 'description', 'value' => 'New'], ['tag' => 'interface', 'value' => 'GigabitEthernet1/0/1']])
	=== ['GigabitEthernet1/0/1', 'New']
	&& Interfaces::labels('Interface Gi1/0/1({#IFALIAS}): Bits sent', [['tag' => 'description', 'value' => '{#IFALIAS}']])
	=== ['Gi1/0/1', '']);
check('API pattern floats inside the item name', Interfaces::apiPattern('Gi1/0/?') === '*Gi1/0/*'
	&& Interfaces::apiPattern('uplink') === '*uplink*');

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
check('guard refuses unknown objects', throws(fn() => $guard->call('usermacro.get', []), ApiException::class));
check('guard allows the audit name lookups', !throws(fn() => $guard->call('hostgroup.get', ['output' => ['groupid']]),
	ApiException::class));

$guard = new Guard($fake, ['time_budget' => 60, 'max_api_calls' => 10, 'max_rows_total' => 100000]);

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

[$scoped] = Definition::normalize(['id' => 'a', 'name' => 'a', 'scope' => ['hosts' => "sw-1\nsw-2\nsw-1"],
	'sections' => [['type' => 'summary']]], $registry);
check('named devices are their own scope', $scoped['scope']['hosts'] === ['sw-1', 'sw-2']);

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
check('wildcards move to patterns and dedupe', $def['scope']['groups'] === []
	&& $def['scope']['group_patterns'] === ['CCH/*'], json_encode($def['scope']));
check('accent validated', $def['branding']['accent'] === '#1f5f8b');
check('logo path traversal refused', $def['branding']['logo'] === '');
check('options clamped and unknown dropped', $def['sections'][0]['options']['limit'] === 200
	&& !isset($def['sections'][0]['options']['bogus']));
check('section validation reported', (bool) preg_grep('/Set item tags/', $errors));
check('bad email reported', (bool) preg_grep('/not-an-email/', $errors));

$base_def = ['id' => 'p', 'name' => 'p', 'scope' => ['groups' => ['a']], 'sections' => [['type' => 'summary']]];
[$pdef, $errors] = Definition::normalize($base_def + ['period' => ['type' => 'range', 'from' => '2026-11-11T08:00',
	'till' => '2026-11-11T17:00', 'date' => 'junk']], $registry);
check('range stored in canonical form', $errors === [] && $pdef['period']['from'] === '2026-11-11 08:00'
	&& $pdef['period']['till'] === '2026-11-11 17:00' && $pdef['period']['date'] === '', json_encode([$pdef['period'], $errors]));
[, $errors] = Definition::normalize($base_def + ['period' => ['type' => 'day', 'date' => '11/11/2026']], $registry);
check('bad day refused', (bool) preg_grep('/Pick the day/', $errors), implode('; ', $errors));
[, $errors] = Definition::normalize($base_def + ['period' => ['type' => 'day', 'date' => '2026-11-11'],
	'schedule' => ['enabled' => true]], $registry);
check('fixed period cannot be scheduled', (bool) preg_grep('/cannot be scheduled/', $errors));
[, $errors] = Definition::normalize($base_def + ['period' => ['type' => 'range', 'from' => '2024-01-01',
	'till' => '2026-01-01']], $registry);
check('overlong range refused', (bool) preg_grep('/400 days/', $errors));
[, $errors] = Definition::normalize(array_replace($base_def, ['sections' => [['type' => 'network_interfaces',
	'options' => ['alias' => '', 'name' => '']]]]), $registry);
check('interfaces section wants a pattern', (bool) preg_grep('/description or interface name pattern/', $errors));

// ---------------------------------------------------------------- Schedule

$sdef = ['timezone' => 'UTC', 'period' => ['type' => 'previous_month', 'n' => 30],
	'schedule' => ['enabled' => true, 'cycle' => 'monthly', 'day' => 1, 'hour' => 6]];
$due = Schedule::due($sdef, null, new DateTimeImmutable('2026-09-01 07:00 UTC'));
check('monthly schedule due after slot', $due !== null && $due->label === 'August 2026');
check('monthly schedule not due before slot', Schedule::due($sdef, null, new DateTimeImmutable('2026-09-01 05:00 UTC')) === null);
check('monthly schedule not repeated', Schedule::due($sdef, $due->key(), new DateTimeImmutable('2026-09-12 09:00 UTC')) === null);
check('missed slot picked up later', Schedule::due($sdef, 'old', new DateTimeImmutable('2026-09-03 02:00 UTC')) !== null);
check('a fixed period is never due', Schedule::due(array_replace($sdef, ['period' => ['type' => 'day', 'date' => '2026-09-01']]),
	null, new DateTimeImmutable('2026-09-01 07:00 UTC')) === null);

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

// The runner's systemd unit sets RestrictSUIDSGID, which denies creating a directory
// with the setgid bit (EPERM). Nothing may ask for one.
$setgid = [];

foreach (['../lib', '../bin', '../actions', '../contrib'] as $dir) {
	$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(__DIR__.'/'.$dir));

	foreach ($it as $f) {
		if ($f->isFile() && preg_match('/\.(php|sh)$/', $f->getFilename())
				&& preg_match('/(mkdir|chmod|install)[^\n]*\b0?2[0-7]{3}\b/', (string) file_get_contents($f->getPathname()))) {
			$setgid[] = $f->getFilename();
		}
	}
}

check('nothing creates setgid directories', $setgid === [], implode(', ', $setgid));

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
	'compare' => true,
	'scope' => ['groups' => ['CCH/*']],
	'branding' => ['title' => 'Monthly service report', 'customer' => "Cincinnati Children's", 'accent' => '#1f5f8b'],
	'sections' => [
		['type' => 'narrative', 'options' => ['text' => "First paragraph.\n\nSecond paragraph."]],
		['type' => 'summary', 'options' => ['exclude_problems' => '*Unavailable by ICMP*']],
		['type' => 'problems_by_host', 'options' => ['group_by_tag' => 'site', 'display_limit' => 20,
			'exclude_problems' => '*Unavailable by ICMP*']],
		['type' => 'top_triggers'],
		['type' => 'top_metrics', 'title' => 'Busiest CPUs', 'options' => ['item_tags' => 'component=cpu']],
		['type' => 'top_metrics', 'title' => 'Busiest uplinks', 'options' => ['item_tags' => 'component=network',
			'aggregate' => 'max']],
		['type' => 'top_metrics', 'title' => 'Per device', 'options' => ['layout' => 'per_device',
			'item_tags' => "component=cpu\ncomponent=network", 'tag_logic' => 'or', 'per_device_limit' => 2,
			'limit' => 5, 'chart' => false]],
		['type' => 'top_metrics', 'title' => 'Per device by name', 'options' => ['layout' => 'per_device',
			'item_tags' => '', 'names' => '*cpu*, *memory*', 'per_device_limit' => 1, 'limit' => 3,
			'chart' => false]],
		['type' => 'capacity_growth'],
		['type' => 'availability', 'options' => ['show' => 'worst']],
		['type' => 'maintenance'],
		['type' => 'response_times', 'options' => ['group_by' => 'tag', 'tag' => 'site']],
		['type' => 'problem_log', 'options' => ['sort' => 'duration']],
		['type' => 'threshold_breaches', 'options' => ['item_tags' => 'component=cpu', 'threshold' => 60]],
		['type' => 'network_interfaces', 'title' => 'Uplinks', 'options' => ['alias' => 'uplink', 'exclude' => 'Vlan*',
			'limit' => 30]],
		['type' => 'monitoring_health'],
		['type' => 'inventory', 'options' => ['field' => 'vendor', 'list_devices' => true]],
		['type' => 'audit_log', 'options' => ['display_limit' => 20]],
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
check('every section ran', $statuses === array_fill(0, 19, 'ok'),
	json_encode(array_map(fn($s) => [$s['type'], $s['status'], $s['message']], $report['sections'])));
check('lab host excluded by scope', $report['scope']['hosts'] === 60);

// A named device joins the scope even though it is in a group the report does not cover.
$mixed = (new Runner($drop_registry))->run(
	array_replace_recursive($def, ['sections' => [['type' => 'summary', 'title' => '', 'options' => []]],
		'scope' => ['hosts' => ['lab1']]]),
	$period, new FakeApi($period->from, $period->till, 60), $limits
);
check('named device added to a group scope', $mixed['scope']['hosts'] === 61, (string) $mixed['scope']['hosts']);
check('unmatched names are reported', (new Runner($drop_registry))->run(
	array_replace_recursive($def, ['sections' => [['type' => 'summary', 'title' => '', 'options' => []]],
		'scope' => ['hosts' => ['nope-1']]]),
	$period, new FakeApi($period->from, $period->till, 60), $limits
)['scope']['unmatched_groups'] === ['nope-1']);

$sections = array_column($report['sections'], null, 'type');

// Cross-check the per-device table against the raw fake data.
$devices = null;

foreach ($sections['problems_by_host']['blocks'] as $b) {
	if ($b['type'] === 'table' && $b['title'] === 'Devices') {
		$devices = $b;
	}
}

$total_from_table = array_sum(array_column($devices['rows'], 'problems'));
$kpi_total = null;

foreach ($sections['summary']['blocks'][0]['items'] as $k) {
	if ($k['label'] === 'Problems raised') {
		$kpi_total = $k['value'];
	}
}

check('per-device totals equal summary total', $total_from_table === $kpi_total, "$total_from_table vs $kpi_total");

// Sections that filter must not be counting what they filtered out. The problem log has
// no exclusion set, so it still sees them.
$logged = $sections['problem_log']['blocks'][0]['rows'];
$icmp = array_filter($logged, static fn($r) => stripos($r['name'], 'Unavailable by ICMP') !== false);
check('exclusion filter drops matching problems', $icmp !== [] && $kpi_total === count($logged) - count($icmp),
	sprintf('%d logged, %d icmp, summary %d', count($logged), count($icmp), $kpi_total));
check('comparison against the previous period appears', (bool) preg_match('/(up|down|unchanged) .*previous period/',
	json_encode($sections['summary']['blocks'])));
check('per-device table gains a change column', (bool) preg_grep('/delta/',
	array_column($devices['columns'], 'format')));
check('narrative renders both paragraphs', count($sections['narrative']['blocks']) === 2);
check('monitoring health finds the broken items and interfaces',
	$sections['monitoring_health']['blocks'][1]['rows'] !== [] && $sections['monitoring_health']['blocks'][2]['rows'] !== []);
check('inventory groups by vendor', count($sections['inventory']['blocks'][2]['rows']) === 4,
	json_encode(array_column($sections['inventory']['blocks'][2]['rows'], 'value')));
check('response times computed', (bool) array_filter(array_column($sections['response_times']['blocks'][1]['rows'], 'mtta')));
check('problem log lists every problem, including the excluded ones', count($logged) > $kpi_total);
// Per-device layout: five devices, two items each, device name printed once per device.
$per_device = null;

foreach ($report['sections'] as $section) {
	if ($section['title'] === 'Per device') {
		$per_device = $section;
	}
}

$table_pd = null;

foreach ($per_device['blocks'] as $b) {
	if ($b['type'] === 'table') {
		$table_pd = $b;
	}
}

$rows_pd = $table_pd['rows'] ?? [];
$named = array_values(array_filter($rows_pd, static fn($r) => $r['host_display'] !== ''));
check('per-device layout groups by device', count($rows_pd) === 10 && count($named) === 5,
	sprintf('%d rows, %d device headings', count($rows_pd), count($named)));
check('per-device rows all carry the device for export', count(array_filter(array_column($rows_pd, 'host'))) === 10);
check('tag logic "or" matched both components', count(array_unique(array_map(
	static fn($r) => preg_match('/CPU/', $r['item']) ? 'cpu' : 'net', $rows_pd))) === 2);

// Name patterns are matched the way the API searches: case-insensitively. And a CPU
// percentage must not lose its place to memory measured in bytes.
$by_name = null;

foreach ($report['sections'] as $section) {
	if ($section['title'] === 'Per device by name') {
		$by_name = $section;
	}
}

$name_rows = [];

foreach ($by_name['blocks'] as $b) {
	if ($b['type'] === 'table') {
		$name_rows = $b['rows'];
	}
}

check('case-insensitive patterns find "CPU utilization"',
	(bool) array_filter($name_rows, static fn($r) => stripos($r['item'], 'CPU') !== false), json_encode(array_column($name_rows, 'item')));
check('each pattern keeps its own slot per device',
	count(array_unique(array_column($name_rows, 'group'))) === 2
	&& count($name_rows) === 2 * count(array_unique(array_column($name_rows, 'host'))),
	json_encode(array_map(static fn($r) => $r['group'].': '.$r['item'], $name_rows)));

// Audit log: details flattened, bulk operations collapsed, system and logins filtered.
$audit_rows = [];

foreach ($sections['audit_log']['blocks'] as $b) {
	if ($b['type'] === 'table' && $b['title'] === 'Changes') {
		$audit_rows = $b['rows'];
	}
}

$audit_text = json_encode($audit_rows);
check('audit log finds the deleted dashboard', (bool) preg_match('/NOC overview.*Delete|Delete.*NOC overview/', $audit_text));
check('a macro deleted inside a host update gets its own row', (bool) array_filter($audit_rows,
	static fn($r) => strpos($r['field'], 'macros') !== false && $r['change'] === 'Deleted'), $audit_text);
check('field updates show old and new', (bool) array_filter($audit_rows,
	static fn($r) => $r['field'] === 'host.name' && strpos($r['change'], 'sw-mason-001 to core-sw-mason-001') !== false));
check('bulk operation collapsed with a count', count(array_filter($audit_rows,
	static fn($r) => $r['recordsetid'] === 'r3')) === 1
	&& array_values(array_filter($audit_rows, static fn($r) => $r['recordsetid'] === 'r3'))[0]['objects'] === 2);
check('logins and system entries left out by default', !preg_match('/Login|Housekeeping/', $audit_text));

// Ids become names, codes become labels, and the plumbing rows are gone.
$rights = array_values(array_filter($audit_rows, static fn($r) => strpos($r['field'], 'hostgroup_rights') !== false));
check('a host group id is shown by name', (bool) array_filter($rights,
	static fn($r) => strpos($r['change'], 'CCH/Network (10)') !== false), json_encode(array_column($rights, 'change')));
check('a permission is written as one line', count($rights) === 1
	&& strpos($rights[0]['change'], 'CCH/Network (10): Read') !== false, json_encode($rights));
check('enum outside the id fields decoded', (bool) array_filter($audit_rows,
	static fn($r) => $r['field'] === 'usergroup.gui_access' && strpos($r['change'], 'Disabled (3)') !== false));
check('internal row ids dropped', !preg_match('/rightid|hostmacroid|hostgroupid/', $audit_text));
check('the object\'s own id is dropped', !preg_match('/usergroup.usrgrpid/', $audit_text));
check('per-field rows are gone once composed', !array_filter($audit_rows,
	static fn($r) => strpos($r['field'], 'conditions[20].') !== false
		|| strpos($r['field'], 'hostgroup_rights[45].') !== false));

$audit_raw = (new Runner($drop_registry))->run(
	array_replace_recursive($def, ['sections' => [['type' => 'audit_log', 'title' => '',
		'options' => ['resolve_ids' => false, 'hide_internal_ids' => false, 'compose' => false,
			'max_fields' => 50]]]]),
	$period, new FakeApi($period->from, $period->till, 10), $limits
);
$raw_text = json_encode($audit_raw['sections'][0]['blocks']);
check('a template group id resolves as a template group', (bool) array_filter($audit_rows,
	static fn($r) => $r['field'] === 'template.groups[903].groupid'
		&& strpos($r['change'], 'Templates/Network (301)') !== false),
	json_encode(array_column($audit_rows, 'change')));
check('a deleted object keeps the name the audit log remembers', (bool) array_filter($audit_rows,
	static fn($r) => strpos($r['field'], 'hosts[904].hostid') !== false
		&& strpos($r['change'], 'drill-host-01 (10950)') !== false));
// Six detail lines describing one condition become one sentence.
$conditions = array_values(array_filter($audit_rows, static fn($r) => strpos($r['field'], 'conditions[') !== false));
check('an action condition is written as one line', count($conditions) === 2
	&& (bool) array_filter($conditions,
		static fn($r) => strpos($r['change'], 'Event tag value: notify.class contains cpu') !== false),
	json_encode(array_column($conditions, 'change')));
check('a condition naming a host group resolves it', (bool) array_filter($conditions,
	static fn($r) => strpos($r['change'], 'Host group equals CCH/Network (10)') !== false),
	json_encode(array_column($conditions, 'change')));
check('a live name beats the one the audit log remembers', (bool) array_filter($audit_rows,
	static fn($r) => strpos($r['change'], 'via Email (HTML)') !== false),
	json_encode(array_values(array_filter(array_column($audit_rows, 'change'),
		static fn($c) => strpos($c, 'via ') !== false))));
check('media type status reads as enabled or disabled', (bool) array_filter($audit_rows,
	static fn($r) => $r['field'] === 'mediatype.status' && strpos($r['change'], 'Disabled (1) to Enabled (0)') !== false),
	json_encode(array_values(array_filter(array_column($audit_rows, 'change'),
		static fn($c) => strpos($c, 'abled') !== false))));
check('host status reads as monitored', (bool) array_filter($audit_rows,
	static fn($r) => $r['field'] === 'host.status'
		&& strpos($r['change'], 'Monitored (0) to Not monitored (1)') !== false));
check('a composed condition keeps its formula letter', (bool) array_filter($audit_rows,
	static fn($r) => $r['field'] === 'action.filter.conditions[20]' && strncmp($r['change'], 'Added: A:', 9) === 0),
	json_encode(array_column($conditions, 'change')));
check('a recovery operation composes too', (bool) array_filter($audit_rows,
	static fn($r) => strpos($r['field'], 'recovery_operations[') !== false
		&& strpos($r['change'], 'Send recovery message via Email (HTML)') !== false),
	json_encode(array_column($audit_rows, 'change')));
check('fields Zabbix omits as defaults are filled back in', (bool) array_filter($audit_rows,
	static fn($r) => $r['field'] === 'action.filter.conditions[21]'
		&& strpos($r['change'], 'Host group equals CCH/Network (10)') !== false));
check('an operation says who it messages and how', (bool) array_filter($audit_rows,
	static fn($r) => strpos($r['field'], 'operations[') !== false
		&& strpos($r['change'], 'Send message to user group Servers Test alerts (24) via Email (HTML)') !== false
		&& strpos($r['change'], 'escalation step 2') !== false),
	json_encode(array_values(array_filter(array_column($audit_rows, 'change'),
		static fn($c) => strpos($c, 'Send message') !== false))));
check('a macro is written as one line', (bool) array_filter($audit_rows,
	static fn($r) => strpos($r['change'], '{$ALERT.CHANNEL:"cpu"} = ,email, (Set by Alert routing)') !== false),
	json_encode(array_column($audit_rows, 'change')));
check('a long text change shows only what changed', (bool) array_filter($audit_rows,
	static fn($r) => strpos($r['change'], 'inside a longer text') !== false
		&& strpos($r['change'], 'sms') !== false),
	json_encode(array_values(array_filter(array_column($audit_rows, 'change'),
		static fn($c) => strpos($c, 'longer text') !== false))));
check('a delete with no details still says what happened', (bool) array_filter($audit_rows,
	static fn($r) => $r['name'] === 'NOC overview' && $r['change'] === 'Deleted'));
check('uuid hidden as noise', !preg_match('/uuid/', $audit_text));

check('every cleanup can be turned off', strpos($raw_text, 'rightid') !== false
	&& strpos($raw_text, 'CCH/Network (10)') === false
	&& strpos($raw_text, 'conditions[20].conditiontype') !== false);

$audit_deletes = (new Runner($drop_registry))->run(
	array_replace_recursive($def, ['sections' => [['type' => 'audit_log', 'title' => '',
		'options' => ['actions' => 'deletes']]]]),
	$period, new FakeApi($period->from, $period->till, 10), $limits
);
$delete_rows = [];

foreach ($audit_deletes['sections'][0]['blocks'] as $b) {
	if ($b['type'] === 'table' && $b['title'] === 'Changes') {
		$delete_rows = $b['rows'];
	}
}

check('"deletions only" keeps the update that deleted a macro', count(array_unique(array_column($delete_rows, 'name'))) === 2,
	json_encode(array_column($delete_rows, 'name')));

// Interfaces: picked by description, the idle and excluded ones left out.
$if_rows = [];
$if_kpis = [];

foreach ($sections['network_interfaces']['blocks'] as $b) {
	if ($b['type'] === 'table') {
		$if_rows = $b['rows'];
	}
	elseif ($b['type'] === 'kpis') {
		$if_kpis = array_column($b['items'], 'value', 'label');
	}
}

$if_names = array_unique(array_column($if_rows, 'interface'));
sort($if_names);
check('interfaces matched by description only', $if_names === ['Gi1/0/49', 'Gi1/0/50'], json_encode($if_names));
check('every uplink counted, the table capped', ($if_kpis['Interfaces'] ?? 0) === 80 && count($if_rows) === 30,
	json_encode($if_kpis).' rows '.count($if_rows));
check('description read from the 7.x item name when the tag is an unresolved macro',
	in_array('Uplink to core-01', array_column($if_rows, 'alias'), true)
	&& !in_array('{#IFALIAS}', array_column($if_rows, 'alias'), true));
check('utilization from speed', (bool) array_filter($if_rows, static fn($r) => $r['peak_util'] > 0 && $r['peak_util'] < 100));
check('sorted by peak utilization', array_column($if_rows, 'peak_util') === (function ($v) { rsort($v); return $v; })(
	array_column($if_rows, 'peak_util')));
check('link-down hours found', ($if_kpis['Not up at some point'] ?? 0) > 0);
check('errors estimated', (bool) array_filter(array_column($if_rows, 'errors')));
check('idle interface counted in a note', (bool) preg_grep('/left out: no traffic/', $sections['network_interfaces']['notes']));

$if_run = static function (array $options) use ($def, $period, $drop_registry, $limits): array {
	$r = (new Runner($drop_registry))->run(
		array_replace($def, ['compare' => false, 'sections' => [['type' => 'network_interfaces', 'title' => '',
			'options' => (new Registry(__DIR__.'/../sections'))->get('network_interfaces')->normalizeOptions($options)]]]),
		$period, new FakeApi($period->from, $period->till, 20), $limits
	);

	foreach ($r['sections'][0]['blocks'] as $b) {
		if ($b['type'] === 'table') {
			return $b['rows'];
		}
	}

	return [];
};

$rows_and = $if_run(['alias' => 'uplink', 'name' => 'Gi1/0/50']);
check('description and name together', array_unique(array_column($rows_and, 'interface')) === ['Gi1/0/50']);
$rows_or = $if_run(['alias' => 'printer', 'name' => 'Gi1/0/50', 'logic' => 'or']);
check('description or name', count(array_unique(array_column($rows_or, 'interface'))) === 2);
$rows_idle = $if_run(['alias' => '', 'name' => 'Te1/1/*', 'hide_idle' => false]);
check('idle ports shown when asked', $rows_idle !== [] && array_sum(array_column($rows_idle, 'in_max')) == 0);
$rows_vlan = $if_run(['alias' => 'uplink', 'sort' => 'name']);
check('no speed, no utilization', (bool) array_filter($rows_vlan, static fn($r) => $r['interface'] === 'Vlan10'
	&& $r['peak_util'] === null));

// Link state: status shown, filtered on, and measured to the half hour.
check('status column on screen with the end state', in_array('Lower layer down', array_column($if_rows, 'status'), true)
	|| ($if_kpis['Not up at the end'] ?? 0) > 0, json_encode(array_count_values(array_column($if_rows, 'status'))));
check('not-up-at-end counted', ($if_kpis['Not up at the end'] ?? 0) > 0, json_encode($if_kpis));

$if_section = static function (array $options) use ($def, $period, $drop_registry, $limits): array {
	$r = (new Runner($drop_registry))->run(
		array_replace($def, ['compare' => false, 'sections' => [['type' => 'network_interfaces', 'title' => '',
			'options' => $options]]]),
		$period, new FakeApi($period->from, $period->till, 20), $limits
	);
	$rows = [];

	foreach ($r['sections'][0]['blocks'] as $b) {
		if ($b['type'] === 'table') {
			$rows = $b['rows'];
		}
	}

	return [$rows, $r['sections'][0]['notes'], $r['sections'][0]];
};

[$rows] = $if_section(['alias' => 'uplink', 'exclude' => 'Te*', 'state' => 'not_up_end', 'sort' => 'uptime']);
check('filter: not up at the end', $rows !== [] && array_unique(array_column($rows, 'status')) === ['Lower layer down']
	&& array_unique(array_column($rows, 'interface')) === ['Gi1/0/50'], json_encode(array_map(
		fn($r) => [$r['host'], $r['interface'], $r['status']], $rows)));
check('ten hours down at the end', array_unique(array_column($rows, 'down_seconds')) === [36000.0],
	json_encode(array_column($rows, 'down_seconds')));

[$rows] = $if_section(['alias' => 'uplink', 'name' => 'Gi1/0/49', 'state' => 'not_up_any']);
check('filter: not up at some point, a drop that came back', $rows !== []
	&& array_unique(array_column($rows, 'status')) === ['Up'], json_encode(array_column($rows, 'status')));
check('a drop starting mid-hour measures three and a half hours', array_unique(array_column($rows, 'down_seconds'))
	=== [12600.0], json_encode(array_column($rows, 'down_seconds')));
check('uptime from it', abs($rows[0]['uptime'] - (1 - 12600 / $period->seconds()) * 100) < 0.01,
	(string) $rows[0]['uptime']);

[$rows] = $if_section(['alias' => 'uplink', 'state' => 'not_up_end', 'sort' => 'uptime']);
check('lowest uptime first: the spare that never came up leads', ($rows[0]['interface'] ?? '') === 'Te1/1/1'
	&& $rows[0]['uptime'] == 0 && end($rows)['interface'] === 'Gi1/0/50');

[$rows] = $if_section(['alias' => 'spare', 'state' => 'never_up']);
check('filter: never up keeps the idle port it is about', $rows !== []
	&& array_unique(array_column($rows, 'interface')) === ['Te1/1/1'] && array_unique(array_column($rows, 'uptime')) === [0.0]
	&& array_unique(array_column($rows, 'status')) === ['Down'], json_encode($rows[0] ?? null));

[$rows] = $if_section(['alias' => 'uplink', 'state' => 'up_all']);
check('filter: up the whole period', $rows !== [] && max(array_column($rows, 'down_seconds')) == 0
	&& !in_array('Te1/1/1', array_column($rows, 'interface'), true));

[$rows, $notes] = $if_section(['alias' => '', 'name' => 'Te1/1/*']);
check('a spare port down all period is idle, and the note says so', $rows === []
	&& (bool) preg_grep('/not up at any time/', $notes), json_encode($notes));

[, , $sec] = $if_section(['alias' => 'uplink', 'state' => 'not_up_end', 'show_status' => false]);
check('picking a link state brings its columns even with them switched off',
	in_array('status', array_column(array_values(array_filter($sec['blocks'], fn($b) => $b['type'] === 'table'))[0]['columns'], 'key'), true));

// Down right now: the morning down report, on status-only fabric ports.
$fab_fake = new FakeApi($period->from, $period->till, 20);
$fab_hosts = array_values(array_filter($fab_fake->call('host.get', ['output' => ['hostid', 'name']]),
	fn($h) => (int) $h['hostid'] % 10 === 0 && strpos($h['name'], 'core-sw') === 0));
$expect_down = array_values(array_map(fn($h) => $h['name'],
	array_filter($fab_hosts, fn($h) => (int) $h['hostid'] % 20 === 0)));
sort($expect_down);
$hours_in = (int) ceil(($period->till - $period->from) / 3600);

[$rows, $notes, $sec] = $if_section(['alias' => '*Fabric Physical Link*', 'state' => 'down_now', 'sort' => 'name']);
$down_hosts = array_column($rows, 'host');
check('down now: exactly the fabric links that are down at the end', $down_hosts === $expect_down && $expect_down !== []
	&& array_unique(array_column($rows, 'interface')) === ['eth1/49'] && array_unique(array_column($rows, 'status')) === ['Down'],
	json_encode(['got' => $down_hosts, 'want' => $expect_down]));
check('down now: ports with only a status item are reported', count($fab_hosts) > 0
	&& array_column(array_values(array_filter($sec['blocks'], fn($b) => $b['type'] === 'kpis'))[0]['items'], 'value', 'label')
		['Interfaces checked'] === 2 * count($fab_hosts));
check('down now: down since the end of the last hour it was up', array_unique(array_column($rows, 'since'))
	=== [$period->from + ($hours_in - 30) * 3600], json_encode(array_column($rows, 'since_text')));
check('down now: a link that came back is not listed', !in_array('eth1/50', array_column($rows, 'interface'), true));
check('down now: the table is the down list', ($sec['blocks'][1]['title'] ?? '') === 'Interfaces not up now'
	&& !in_array('in_max', array_column($sec['blocks'][1]['columns'], 'key'), true));
[$rows, , $sec] = $if_section(['alias' => 'spine-2', 'state' => 'down_now']);
check('down now: none down says all up', $rows === []
	&& preg_match('/^All \d+ matching interfaces with a status item are up\.$/', $sec['blocks'][1]['empty']));

[$rows] = $if_section(['alias' => 'Fabric Physical Link', 'state' => 'not_up_any', 'sort' => 'name']);
$e50 = array_values(array_filter($rows, fn($r) => $r['interface'] === 'eth1/50'));
check('status-only ports show blank traffic, not zero', count($e50) === count($fab_hosts) && $e50[0]['in_max'] === null
	&& $e50[0]['peak_util'] === null && $e50[0]['down_seconds'] == 5.5 * 3600, json_encode($e50[0] ?? null));

// When nothing matches, the section says why.
[, $notes] = $if_section(['alias' => 'Fabric health']);
check('explain: matching items on non-interface keys', (bool) preg_grep('/none uses an interface key.*cisco\.aci\.fabric\.health\[\.\.\.\]/', $notes),
	json_encode($notes));
[, $notes] = $if_section(['alias' => 'eth1/49']);
check('explain: the text is in the interface name', (bool) preg_grep('/put the pattern in "Interface name matches"/', $notes),
	json_encode($notes));
[, $notes] = $if_section(['alias' => '*no-such-thing-zz*']);
check('explain: nothing by that name at all', (bool) preg_grep('/^No item on the devices in scope has a name containing/', $notes));
[, $notes] = $if_section(['alias' => 'Fabric Physical', 'exclude' => '*spine*']);
check('explain: everything excluded', (bool) preg_grep('/left out by "Skip interfaces"/', $notes), json_encode($notes));

// The report as sent, and as it should be set up. Neither is refused on save.
$johns = json_decode(<<<'JSON'
{"id":"network-interface-down","name":"Network Interface Down","description":"","timezone":"America/Detroit",
 "period":{"type":"last_n_days","n":1,"date":"","from":"","till":""},"compare":false,
 "scope":{"groups":["Network/ACI","Network/Cisco"],"hosts":[],"group_patterns":"","host_tags":"","tag_logic":"and"},
 "branding":{"title":"Daily Network Interface Report","customer":"","accent":"#1f5f8b","logo":"","paper":"Letter","footer":""},
 "sections":[{"type":"network_interfaces","title":"","options":{"alias":"*Fabric Physical Link*","name":"","logic":"and",
  "exclude":"","state":"not_up_any","sort":"name","limit":50,"util_threshold":80,"hide_idle":true,"show_errors":false,
  "show_status":false,"sparkline":false,"chart":false,"intro":"","exclude_hosts":""}}],
 "schedule":{"enabled":false,"cycle":"daily","day":1,"hour":6},
 "delivery":{"formats":["pdf","xlsx"],"email_to":"","email_subject":"","email_body":""}}
JSON, true);
[, $errors] = Definition::normalize($johns, $registry);
check('the report as sent validates', $errors === [], implode('; ', $errors));
$johns['period']['type'] = 'last_24_hours';
$johns['sections'][0]['options']['state'] = 'down_now';
$johns['schedule']['enabled'] = true;
[$jdef, $errors] = Definition::normalize($johns, $registry);
check('the 6am down report validates and schedules', $errors === [] && $jdef['period']['type'] === 'last_24_hours'
	&& $jdef['sections'][0]['options']['state'] === 'down_now', implode('; ', $errors));

// Last 24 hours: to the minute it runs, 24 real hours across a clock change.
$p = Period::resolve('last_24_hours', 1, 'America/Detroit', new DateTimeImmutable('2026-11-19 06:00:37', new DateTimeZone('America/Detroit')));
check('last 24 hours ends at the minute it runs', $p->label === '18 Nov 2026 06:00 to 19 Nov 2026 06:00' && $p->seconds() === 86400,
	$p->label);
$p = Period::resolve('last_24_hours', 1, $ny, new DateTimeImmutable('2026-11-01 06:00', new DateTimeZone($ny)));
check('last 24 hours across the clock change', $p->seconds() === 86400 && $p->label === '31 Oct 2026 07:00 to 1 Nov 2026 06:00',
	$p->label);

check('threshold breaches counted hours', (bool) array_filter(array_column($sections['threshold_breaches']['blocks'][2]['rows'], 'hours')));
check('only write-free methods called', !preg_grep('/\.(create|update|delete)$/', $fake->calls));

$counts = array_count_values($fake->calls);
check('problems fetched once for all sections', ($counts['event.get'] ?? 0) < 60, json_encode($counts));

$maint_rows = $sections['maintenance']['blocks'][0]['rows'];

// Devices in maintenance now, and the ones nobody took out.
$maint_blocks = $sections['maintenance']['blocks'];
$maint_kpis = array_column(array_values(array_filter($maint_blocks, fn($b) => $b['type'] === 'kpis'))[0]['items'] ?? [],
	'value', 'label');
$in_rows = [];

foreach ($maint_blocks as $b) {
	if ($b['type'] === 'table' && $b['title'] === 'Devices in maintenance now') {
		$in_rows = $b['rows'];
	}
}

$stuck = array_values(array_filter($in_rows, fn($r) => $r['flag'] !== ''));
check('devices in maintenance listed', ($maint_kpis['In maintenance now'] ?? 0) === 22 && count($in_rows) === 22,
	json_encode($maint_kpis).' rows '.count($in_rows));
check('stuck devices flagged and listed first', count($stuck) === 2
	&& array_column(array_slice($in_rows, 0, 2), 'flag') === array_column($stuck, 'flag')
	&& strpos($stuck[0]['flag'], 'Over 7 days') !== false && strpos($stuck[0]['flag'], 'Out in') !== false,
	json_encode(array_map(fn($r) => [$r['host'], $r['flag']], $stuck)));
check('stuck since 120 days', abs($stuck[0]['for'] - 120 * 86400) < 120 && $stuck[0]['window'] === 'Core swap - remove after cutover');
check('due out at the end of the 1,000-day period, inside the window', abs($stuck[0]['ends'] - (time() + 880 * 86400)) < 120
	&& strpos($stuck[0]['flag'], 'Out in 2 years') !== false, $stuck[0]['flag']);
$by_host = array_column($stuck, null, 'host');
check('who added each device, per device from the audit log',
	($by_host[$ctx_name = 'core-sw-mason-002']['added_by'] ?? '') === 'jsmith'
	&& ($by_host['core-sw-liberty-005']['added_by'] ?? '') === 'mlee',
	json_encode(array_map(fn($r) => [$r['host'], $r['added_by']], $stuck)));
check('a recurring window that just opened is not flagged', !array_filter($in_rows,
	fn($r) => $r['window'] === 'Server patching' && $r['flag'] !== '')
	&& ($maint_kpis['Without data collection'] ?? 0) === 20);

$stale_only = (new Runner($drop_registry))->run(
	array_replace($def, ['compare' => false, 'sections' => [['type' => 'maintenance', 'title' => '',
		'options' => ['show' => 'devices', 'stale_only' => true, 'stale_days' => 30]]]]),
	$period, new FakeApi($period->from, $period->till, 60), $limits
)['sections'][0];
$only = array_values(array_filter($stale_only['blocks'], fn($b) => $b['type'] === 'table'));
check('a stuck-in-maintenance report: devices only, flagged only', count($only) === 1
	&& count($only[0]['rows']) === 2 && $only[0]['title'] === 'Devices in maintenance now');
check('maintenance outside period excluded', !in_array('Old window', array_column($maint_rows, 'name'), true));

// Time in maintenance, and the problems it kept quiet, checked against the fake's raw events.
$mtable = static function (array $blocks, string $title): array {
	foreach ($blocks as $b) {
		if ($b['type'] === 'table' && $b['title'] === $title) {
			return $b['rows'];
		}
	}

	return [];
};
$win_rows = array_column($mtable($maint_blocks, 'Windows in effect during the period'), null, 'name');
check('windows table uses the schedule: five Sundays of four hours', ($win_rows['Server patching']['hours'] ?? 0) === 5 * 4 * 3600
	&& ($win_rows['Core switch firmware']['hours'] ?? 0) === 7200 && isset($win_rows['DB failover']),
	json_encode(array_map(fn($r) => $r['hours'], $win_rows)));

$time_rows = array_column($mtable($maint_blocks, 'Time in maintenance during the period'), null, 'host');
$srv = array_filter($time_rows, fn($r) => strpos($r['host'], 'srv-') === 0);
check('servers lost twenty hours of data each', count($srv) === 20
	&& array_unique(array_column($srv, 'nodata')) === [72000] && array_unique(array_column($srv, 'total')) === [72000]);
check('a switch in maintenance all month, with data', ($time_rows['core-sw-mason-002']['total'] ?? 0) === $period->seconds()
	&& $time_rows['core-sw-mason-002']['nodata'] === 0, json_encode($time_rows['core-sw-mason-002'] ?? null));
check('no-data devices sort first', strpos(array_values($time_rows)[0]['host'], 'srv-') === 0);

$names = array_column($fake->call('host.get', ['output' => ['hostid', 'name']]), 'name', 'hostid');
$raw = static function (string $hostid, int $a, int $b, ?string $component = null) use ($fake): array {
	return array_values(array_filter($fake->call('event.get', ['output' => 'extend', 'source' => 0, 'object' => 0,
		'value' => 1, 'hostids' => [$hostid], 'time_from' => $a, 'time_till' => $b - 1]),
		fn($e) => $component === null || FakeApi::componentOf($e['name']) === $component));
};
$expect = [
	'10002' => count($raw('10002', $period->from, $period->till + 1)),
	'10005' => count($raw('10005', $period->from, $period->till + 1)),
	'10004' => count($raw('10004', $period->from + 3 * 86400 + 3600, $period->from + 3 * 86400 + 3600 + 7200)),
	'10014' => count($raw('10014', $period->from, $period->till + 1, 'cpu'))
];
$hid_rows = array_column($mtable($maint_blocks, 'Problems during maintenance'), null, 'host');
$got = [];

foreach ($expect as $hostid => $n) {
	$got[$hostid] = $hid_rows[$names[$hostid]]['problems'] ?? 0;
}

check('problems during maintenance match the raw events per device', $got === $expect && array_sum($expect) > 0,
	json_encode(['got' => $got, 'expect' => $expect]));
$all_10014 = count($raw('10014', $period->from, $period->till + 1));
check('a tag-filtered window counts only matching problems', $all_10014 > $expect['10014'] && $expect['10014'] > 0
	&& strpos($hid_rows[$names['10014']]['most'] ?? '', 'High CPU') === 0, $all_10014.' vs '.$expect['10014']);
// Core swap runs to 2029, so only problems never resolved outlast it, even ones that
// resolved after the end of August.
$open_10002 = count(array_filter($raw('10002', $period->from, $period->till + 1), fn($e) => $e['r_eventid'] === '0'));
check('outlasting a maintenance follows it past the end of the period',
	($hid_rows[$names['10002']]['outlasted'] ?? -1) === $open_10002, json_encode($hid_rows[$names['10002']] ?? null));
check('no-data maintenance hides nothing countable', !array_filter($hid_rows, fn($r) => strpos($r['host'], 'srv-') === 0));
$mk = [];

foreach ($maint_blocks as $b) {
	if ($b['type'] === 'kpis') {
		$mk += array_column($b['items'], 'value', 'label');
	}
}

check('hidden total is the sum over devices', ($mk['Problems'] ?? -1) === array_sum($expect)
	+ array_sum(array_map(fn($r) => in_array($r['host'], array_map(fn($h) => $names[$h], array_keys($expect)), true)
		? 0 : $r['problems'], $hid_rows)), json_encode($mk));
check('device-hours without data', ($mk['Device-hours without data'] ?? 0) === 400);

[, $errors] = Definition::normalize(array_replace($base_def, ['sections' => [['type' => 'maintenance',
	'options' => ['show_windows' => false, 'show_now' => false, 'show_time' => false, 'show_hidden' => false]]]]), $registry);
check('maintenance needs at least one part', (bool) preg_grep('/at least one part/', $errors));
check('maintenance via host group counted', in_array('Server patching', array_column($maint_rows, 'name'), true));

// A section whose filter matches too much fails alone; the shared budgets are untouched.
$broad = (new Runner($drop_registry))->run(
	array_replace($def, ['compare' => false, 'sections' => [
		['type' => 'network_interfaces', 'title' => '', 'options' => ['alias' => '*']],
		['type' => 'top_metrics', 'title' => '', 'options' => ['item_tags' => 'component=cpu']],
		['type' => 'summary', 'title' => '', 'options' => []]
	]]),
	$period, new FakeApi($period->from, $period->till, 20), ['max_items' => 40] + $limits
);
check('an over-broad section fails alone', array_column($broad['sections'], 'status') === ['error', 'ok', 'ok']
	&& $broad['stopped'] === null && strpos($broad['sections'][0]['message'], 'Narrow') !== false,
	json_encode(array_map(fn($s) => [$s['status'], $s['message']], $broad['sections'])));

// "Match both" may fetch many candidates; the item limit counts only the ports kept.
$both = static function (int $max) use ($def, $period, $drop_registry, $limits): array {
	return (new Runner($drop_registry))->run(
		array_replace($def, ['compare' => false, 'sections' => [['type' => 'network_interfaces', 'title' => '',
			'options' => ['alias' => 'uplink', 'name' => 'Gi1/0/49']]]]),
		$period, new FakeApi($period->from, $period->till, 20), ['max_items' => $max] + $limits
	)['sections'][0];
};
$fits = $both(120);
$over = $both(100);
check('candidates dropped by the other pattern do not count against the limit', $fits['status'] === 'ok'
	&& $over['status'] === 'error' && strpos($over['message'], 'over the limit of 100') !== false,
	json_encode([$fits['status'], $fits['message'], $over['status'], $over['message']]));

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
