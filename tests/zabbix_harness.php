<?php declare(strict_types = 0);

/*
 * php tests/zabbix_harness.php /path/to/zabbix/ui [--out DIR]
 *
 * Runs every frontend action and view against the real Zabbix frontend classes (no
 * database: the API facade is replaced by FakeApi). Each step runs in its own PHP process,
 * as each request does under php-fpm; Zabbix caches request input in statics. Catches wrong class names, wrong
 * method signatures, bad input rules and view errors without a full Zabbix install.
 */

$ui = realpath($argv[1] ?? '');
$out = null;
$step = null;

foreach ($argv as $i => $a) {
	if ($a === '--out') {
		$out = $argv[$i + 1];
	}

	if ($a === '--step') {
		$step = (int) $argv[$i + 1];
	}
}

if (!$ui || !is_file($ui.'/include/defines.inc.php')) {
	fwrite(STDERR, "usage: php tests/zabbix_harness.php /path/to/zabbix/ui\n");
	exit(2);
}

$module = dirname(__DIR__);
error_reporting(E_ALL);
set_error_handler(static function ($no, $str, $file, $line) {
	throw new ErrorException($str, 0, $no, $file, $line);
});

chdir($ui);
require $ui.'/include/defines.inc.php';
require $ui.'/include/func.inc.php';
require $ui.'/include/html.inc.php';
require $ui.'/include/gettextwrapper.inc.php';
require $ui.'/include/validate.inc.php';
require $ui.'/include/classes/core/CAutoloader.php';

$paths = [];
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($ui.'/include/classes', FilesystemIterator::SKIP_DOTS),
	RecursiveIteratorIterator::SELF_FIRST);

foreach ($it as $f) {
	if ($f->isDir()) {
		$paths[] = $f->getPathname().'/';
	}
}

$autoloader = new CAutoloader();
$autoloader->addNamespace('', array_merge([$ui.'/include/classes/'], $paths, [$ui.'/app/controllers/']));
$autoloader->addNamespace('Zabbix\\Core', [$ui.'/include/classes/core/']);
$autoloader->addNamespace('Modules\\Reporter', [$module.'/']);
$autoloader->register();

require __DIR__.'/FakeApi.php';

// No database: anything that falls through to SQL gets nothing back.
function DBselect(...$a) { return false; }
function DBstart(...$a) { return true; }
function DBend(...$a) { return true; }
function DBexecute(...$a) { return true; }
function DBfetch(...$a) { return false; }
function DBfetchArray(...$a) { return []; }
function DBfetchColumn(...$a) { return []; }
function zbx_dbstr($s) { return "'".addslashes((string) $s)."'"; }
function dbConditionInt(...$a) { return '1=1'; }
function dbConditionString(...$a) { return '1=1'; }
function dbConditionId(...$a) { return '1=1'; }

// API facade replacement: every API::X()->get() goes to FakeApi, the same object the
// module's FrontendApi thinks is Zabbix.
final class API {
	public static ?FakeApi $fake = null;

	public static function __callStatic($name, $args) {
		return new class(strtolower($name)) {
			private string $object;

			public function __construct(string $object) {
				$this->object = $object;
			}

			public function get(array $params) {
				return API::$fake->call($this->object.'.get', $params);
			}
		};
	}
}

$period_from = strtotime('first day of last month 00:00');
$period_till = strtotime('first day of this month 00:00') - 1;
API::$fake = new FakeApi($period_from, $period_till, 60);

putenv('REPORTER_DATA_DIR=/tmp/reporter-harness');
require $module.'/lib/bootstrap.php';

// Logged-in super admin, and a session for CSRF tokens.
$_SESSION = [];
CWebUser::$data = ['userid' => '1', 'username' => 'Admin', 'type' => (int) (getenv('HARNESS_USER_TYPE') ?: USER_TYPE_SUPER_ADMIN), 'sessionid' => 'x',
	'lang' => 'en_US', 'timezone' => 'UTC', 'debug_mode' => 0, 'gui_access' => 0, 'deprovisioned' => false,
	'roleid' => '3', 'secret' => 'harness'];

CView::registerDirectory($module.'/views');

// Settings normally come from the database.
(function () {
	$any = new class implements ArrayAccess {
		public function offsetExists($k): bool { return true; }
		public function offsetGet($k): mixed { return $k === 'default_theme' ? 'blue-theme' : '0'; }
		public function offsetSet($k, $v): void {}
		public function offsetUnset($k): void {}
	};

	foreach (['params', 'params_public'] as $name) {
		$p = new ReflectionProperty(CSettingsHelper::class, $name);
		$p->setAccessible(true);
		$p->setValue(null, $any);
	}
})();

// Session key normally comes from the settings table.
(function () {
	$p = new ReflectionProperty(CEncryptHelper::class, 'key');
	$p->setAccessible(true);
	$p->setValue(null, str_repeat('k', 32));
})();

$results = [];

function run_action(string $class, string $action, array $get, array $post = []): array {
	$_GET = $get;
	$_POST = $post;
	$_REQUEST = $get + $post;
	$_SERVER['REQUEST_METHOD'] = $post ? 'POST' : 'GET';

	$fqcn = 'Modules\\Reporter\\Actions\\'.$class;
	/** @var CController $c */
	$c = new $fqcn();
	$c->setAction($action);
	$response = $c->run();

	return [$c, $response];
}

function render_view(string $name, array $data): string {
	ob_start();
	$view = new CView($name, $data);
	echo $view->getOutput();

	return (string) ob_get_clean();
}


function ok(string $what, bool $cond, string $detail = ''): void {
	echo ($cond ? 'ok    ' : 'FAIL  ').$what.($cond || $detail === '' ? '' : "  ($detail)")."\n";
}

function save_out(string $name, string $html): void {
	global $out;

	if ($out !== null) {
		is_dir($out) || mkdir($out, 0777, true);
		file_put_contents($out.'/'.$name, $html);
	}
}

function denied(callable $fn): bool {
	try {
		$fn();
	}
	catch (CAccessDeniedException $e) {
		return true;
	}

	return false;
}

function json_of($response): array {
	return (array) json_decode($response->getData()['main_block'], true);
}

$definition = json_encode([
	'id' => 'cch-monthly', 'name' => 'CCH monthly', 'timezone' => 'UTC',
	'scope' => ['groups' => "CCH/*", 'host_tags' => 'site'],
	'branding' => ['customer' => 'Cincinnati Children\'s'],
	'sections' => [
		['type' => 'summary', 'options' => []],
		['type' => 'problems_by_host', 'options' => ['group_by_tag' => 'site']],
		['type' => 'top_metrics', 'options' => ['item_tags' => 'component=cpu']],
		['type' => 'availability', 'options' => []]
	],
	'schedule' => ['enabled' => true, 'cycle' => 'monthly', 'day' => 1, 'hour' => 6],
	'delivery' => ['formats' => ['pdf'], 'email_to' => 'noc@cch.example']
]);

$steps = [
	1 => function () {
		[, $r] = run_action('ReportList', 'reporter.list', ['action' => 'reporter.list']);
		$html = render_view('reporter.list', $r->getData());
		ok('list renders empty state', strpos($html, 'No reports yet') !== false);
		ok('list offers Settings to Super admin', strpos($html, 'reporter.settings') !== false);
	},
	2 => function () {
		[, $r] = run_action('ReportEdit', 'reporter.edit', ['action' => 'reporter.edit']);
		$data = $r->getData();
		$html = render_view('reporter.edit', $data);
		save_out('edit.html', $html);
		ok('edit renders with editor script', strpos($html, 'window.reporterEditorInit') !== false
			&& strpos($html, '"problems_by_host"') !== false && strpos($html, '<script>') !== false);
		ok('edit gets group names', in_array('CCH/Network', $data['group_names'], true));
	},
	3 => function () use ($definition) {
		ok('save without CSRF token is refused', denied(fn() => run_action('ReportSave', 'reporter.save',
			['action' => 'reporter.save'], ['definition' => $definition, 'original_id' => ''])));
	},
	4 => function () use ($definition) {
		[, $r] = run_action('ReportSave', 'reporter.save', ['action' => 'reporter.save'],
			['definition' => $definition, 'original_id' => '', CSRF_TOKEN_NAME => CCsrfTokenHelper::get('reporter.save')]);
		$json = json_of($r);
		ok('save with token succeeds', ($json['ok'] ?? false) === true, json_encode($json));
	},
	5 => function () {
		[, $r] = run_action('ReportSave', 'reporter.save', ['action' => 'reporter.save'],
			['definition' => '{"id":"x"}', 'original_id' => '', CSRF_TOKEN_NAME => CCsrfTokenHelper::get('reporter.save')]);
		ok('invalid save returns errors', count(json_of($r)['errors'] ?? []) >= 2);
	},
	6 => function () use ($definition) {
		[, $r] = run_action('ReportSave', 'reporter.save', ['action' => 'reporter.save'],
			['definition' => $definition, 'original_id' => '', CSRF_TOKEN_NAME => CCsrfTokenHelper::get('reporter.save')]);
		ok('duplicate ID refused', (bool) preg_grep('/already exists/', json_of($r)['errors'] ?? []));
	},
	7 => function () {
		[, $r] = run_action('ReportList', 'reporter.list', ['action' => 'reporter.list']);
		$html = render_view('reporter.list', $r->getData());
		ok('list shows saved report', strpos($html, 'CCH monthly') !== false && strpos($html, 'rpt-delete') !== false);
		ok('list shows schedule', strpos($html, 'Monthly on day 1') !== false);
	},
	8 => function () {
		[, $r] = run_action('ReportEdit', 'reporter.edit', ['action' => 'reporter.edit', 'id' => 'cch-monthly']);
		ok('edit loads existing', $r->getData()['definition']['name'] === 'CCH monthly');
		render_view('reporter.edit', $r->getData());
	},
	9 => function () {
		[, $r] = run_action('ReportEdit', 'reporter.edit', ['action' => 'reporter.edit', 'id' => 'cch-monthly', 'clone' => '1']);
		ok('clone renames and unschedules', $r->getData()['definition']['id'] === 'cch-monthly-copy'
			&& $r->getData()['original_id'] === '' && !$r->getData()['definition']['schedule']['enabled']);
	},
	10 => function () use ($out) {
		[, $r] = run_action('ReportPreview', 'reporter.preview', ['action' => 'reporter.preview', 'id' => 'cch-monthly']);
		$d = $r->getData();
		ok('preview has no error', $d['error'] === null, (string) $d['error']);
		$html = render_view('reporter.preview', $d);
		ok('preview renders iframe', strpos($html, '<iframe') !== false && strpos($html, 'srcdoc=') !== false);
		ok('preview offers Send now', strpos($html, 'rpt-send') !== false);
		ok('preview queried the API', count(API::$fake->calls) > 5);

		if ($out !== null) {
			is_dir($out) || mkdir($out, 0777, true);
			file_put_contents($out.'/preview.html', $html);
		}
	},
	11 => function () {
		[, $r] = run_action('ReportPreview', 'reporter.preview', ['action' => 'reporter.preview', 'id' => 'cch-monthly']);
		ok('second preview is served from cache', API::$fake->calls === [] && $r->getData()['age'] !== null,
			json_encode(API::$fake->calls));
	},
	12 => function () {
		$from = strtotime('first day of last month');
		[, $r] = run_action('ReportPreview', 'reporter.preview', ['action' => 'reporter.preview', 'id' => 'cch-monthly',
			'period' => 'custom', 'from' => date('Y-m-d', $from), 'till' => date('Y-m-d', $from + 6 * 86400)]);
		ok('custom period preview', $r->getData()['error'] === null, (string) $r->getData()['error']);
	},
	13 => function () {
		[, $r] = run_action('ReportPreview', 'reporter.preview', ['action' => 'reporter.preview', 'id' => 'cch-monthly',
			'period' => 'custom', 'from' => 'nonsense', 'till' => '']);
		ok('bad custom dates give a message', is_string($r->getData()['error']));
		ok('preview error renders', strpos(render_view('reporter.preview', $r->getData()), 'rpt-notice-error') !== false);
	},
	14 => function () {
		[, $r] = run_action('ReportPreview', 'reporter.preview', ['action' => 'reporter.preview', 'id' => 'nope']);
		ok('missing report gives a message', strpos((string) $r->getData()['error'], 'does not exist') !== false);
	},
	15 => function () {
		$_GET = $_REQUEST = ['action' => 'reporter.export', 'id' => 'cch-monthly', 'format' => 'xlsx'];
		$_POST = [];
		$c = new Modules\Reporter\Actions\ReportExport();
		$m = new ReflectionMethod($c, 'checkInput');
		$m->setAccessible(true);
		ok('export input rules accept a download link', $m->invoke($c) === true);
	},
	16 => function () {
		[, $r] = run_action('ReportSettings', 'reporter.settings', ['action' => 'reporter.settings']);
		$d = $r->getData();
		ok('settings loads', $d['error'] === null, (string) $d['error']);
		$html = render_view('reporter.settings', $d);
		ok('settings renders', strpos($html, 'window.reporterSettingsInit') !== false);
		ok('settings lists email media types only', array_column($d['media_types'], 'name') === ['Email (HTML)', 'Office 365']);
		ok('settings lists the scheduled report', count($d['reports']) === 1);
		ok('settings page never contains the media password', strpos($html, 'media-secret') === false);
	},
	17 => function () {
		[, $r] = run_action('ReportSettingsSave', 'reporter.settings.save', ['action' => 'reporter.settings.save'], [
			'settings' => json_encode(['delivery' => ['mediatypeid' => '3']]),
			CSRF_TOKEN_NAME => CCsrfTokenHelper::get('reporter.settings.save')
		]);
		ok('OAuth media type refused', (bool) preg_grep('/OAuth/', json_of($r)['errors'] ?? []), json_encode(json_of($r)));
	},
	18 => function () {
		[, $r] = run_action('ReportSettingsSave', 'reporter.settings.save', ['action' => 'reporter.settings.save'], [
			'settings' => json_encode([
				'runner' => ['api_url' => 'https://zabbix.example/'],
				'delivery' => ['mediatypeid' => '1'],
				'limits' => ['max_hosts' => 999999]
			]),
			'api_token' => str_repeat('ab', 32),
			CSRF_TOKEN_NAME => CCsrfTokenHelper::get('reporter.settings.save')
		]);
		$j = json_of($r);
		ok('settings save', ($j['ok'] ?? false) === true, json_encode($j));
		ok('token and media copy reported', ($j['secrets']['token_set'] ?? false) && ($j['secrets']['smtp']['name'] ?? '') === 'Email (HTML)');
		$secrets = json_decode((string) file_get_contents('/tmp/reporter-harness/secrets.json'), true);
		ok('media type copied for the runner', $secrets['smtp']['passwd'] === 'media-secret' && $secrets['smtp']['server'] === 'smtp.encore.example');
		ok('secrets file is 0600', (fileperms('/tmp/reporter-harness/secrets.json') & 0777) === 0600);
		$settings = json_decode((string) file_get_contents('/tmp/reporter-harness/settings.json'), true);
		ok('no secrets in settings.json', strpos(json_encode($settings), 'media-secret') === false && strpos(json_encode($settings), 'abab') === false);
		ok('limit clamped to ceiling', $settings['limits']['max_hosts'] === 20000);
		ok('media type names recorded', ($settings['media_names']['2'] ?? '') === 'HaloPSA webhook');
	},
	19 => function () {
		[, $r] = run_action('ReportSettingsSave', 'reporter.settings.save', ['action' => 'reporter.settings.save'], [
			'settings' => '{}', 'api_token' => 'not-a-token',
			CSRF_TOKEN_NAME => CCsrfTokenHelper::get('reporter.settings.save')
		]);
		ok('malformed API token refused', (bool) preg_grep('/64 hexadecimal/', json_of($r)['errors'] ?? []));
	},
	191 => function () {
		ok('settings save without CSRF token refused', denied(fn() => run_action('ReportSettingsSave', 'reporter.settings.save',
			['action' => 'reporter.settings.save'], ['settings' => '{}'])));
	},
	20 => function () {
		[, $r] = run_action('ReportSettings', 'reporter.settings', ['action' => 'reporter.settings']);
		$html = render_view('reporter.settings', $r->getData());
		save_out('settings.html', $html);
		ok('settings shows stored state', $r->getData()['secrets']['token_set'] && $r->getData()['secrets']['smtp'] !== null);
		ok('settings page never contains secrets', strpos($html, 'media-secret') === false && strpos($html, str_repeat('ab', 32)) === false);
	},
	21 => function () {
		// The test runs in the request. There is no Zabbix at zabbix.example, so it must fail
		// cleanly with a message rather than an exception.
		[, $r] = run_action('ReportRequest', 'reporter.request', ['action' => 'reporter.request'],
			['type' => 'test_api', CSRF_TOKEN_NAME => CCsrfTokenHelper::get('reporter.request')]);
		ok('test token answers inline', !empty(json_of($r)['errors']) || !empty(json_of($r)['message']), json_encode(json_of($r)));
	},
	212 => function () {
		// Unsaved values from the form are what gets tested.
		[, $r] = run_action('ReportRequest', 'reporter.request', ['action' => 'reporter.request'],
			['type' => 'test_api', 'url' => 'ftp://nope', CSRF_TOKEN_NAME => CCsrfTokenHelper::get('reporter.request')]);
		ok('test token checks the form URL', (bool) preg_grep('/http or https/', json_of($r)['errors'] ?? []), json_encode(json_of($r)));
	},
	213 => function () {
		[, $r] = run_action('ReportRequest', 'reporter.request', ['action' => 'reporter.request'],
			['type' => 'test_mail', 'to' => 'a@b.c', 'mediatypeid' => '99', CSRF_TOKEN_NAME => CCsrfTokenHelper::get('reporter.request')]);
		ok('test email reads the selected media type', (bool) preg_grep('/does not exist/', json_of($r)['errors'] ?? []), json_encode(json_of($r)));
	},
	211 => function () {
		[, $r] = run_action('ReportRequest', 'reporter.request', ['action' => 'reporter.request'],
			['type' => 'test_mail', 'to' => 'nope', CSRF_TOKEN_NAME => CCsrfTokenHelper::get('reporter.request')]);
		ok('bad test address refused', !empty(json_of($r)['errors']));
	},
	22 => function () {
		// Admin (not Super admin): may send a report now, may not use settings or tests.
		ok('Admin cannot open settings', denied(fn() => run_action('ReportSettings', 'reporter.settings', ['action' => 'reporter.settings'])));
	},
	221 => function () {
		ok('Admin cannot queue a test mail', denied(fn() => run_action('ReportRequest', 'reporter.request',
			['action' => 'reporter.request'], ['type' => 'test_mail', 'to' => 'a@b.c', CSRF_TOKEN_NAME => CCsrfTokenHelper::get('reporter.request')])));
	},
	23 => function () {
		[, $r] = run_action('ReportRequest', 'reporter.request', ['action' => 'reporter.request'],
			['type' => 'run_report', 'id' => 'cch-monthly', CSRF_TOKEN_NAME => CCsrfTokenHelper::get('reporter.request')]);
		ok('Admin can send a report now', (json_of($r)['ok'] ?? false) === true, json_encode(json_of($r)));
	},
	24 => function () {
		ok('User type cannot open the editor', denied(fn() => run_action('ReportEdit', 'reporter.edit', ['action' => 'reporter.edit'])));
		[, $r] = run_action('ReportList', 'reporter.list', ['action' => 'reporter.list']);
		ok('User type can list, without edit or settings', $r->getData()['can_edit'] === false && $r->getData()['is_super'] === false);
		render_view('reporter.list', $r->getData());
	},
	25 => function () {
		[, $r] = run_action('ReportDelete', 'reporter.delete', ['action' => 'reporter.delete'],
			['id' => 'cch-monthly', CSRF_TOKEN_NAME => CCsrfTokenHelper::get('reporter.delete')]);
		ok('delete with token', (json_of($r)['ok'] ?? false) === true);
	}
];

$user_type = [22 => USER_TYPE_ZABBIX_ADMIN, 221 => USER_TYPE_ZABBIX_ADMIN, 23 => USER_TYPE_ZABBIX_ADMIN, 24 => USER_TYPE_ZABBIX_USER];

if ($step === null) {
	// Driver: one process per step.
	exec('rm -rf /tmp/reporter-harness');
	$failed = 0;

	$order = array_keys($steps);
	usort($order, static fn($a, $b) => ($a > 100 ? $a / 10 : $a) <=> ($b > 100 ? $b / 10 : $b));

	foreach ($order as $n) {
		$cmd = sprintf('HARNESS_USER_TYPE=%d %s -d memory_limit=512M %s %s --step %d%s 2>&1', $user_type[$n] ?? USER_TYPE_SUPER_ADMIN,
			escapeshellarg(PHP_BINARY), escapeshellarg(__FILE__), escapeshellarg($ui), $n,
			$out !== null ? ' --out '.escapeshellarg($out) : '');
		exec($cmd, $lines, $rc);
		$text = implode("\n", $lines);
		$lines = [];
		echo $text === '' ? "FAIL  step $n printed nothing (exit $rc)\n" : $text."\n";
		$failed += substr_count($text, 'FAIL') + ($rc !== 0 ? 1 : 0);
	}

	echo $failed === 0 ? "\nall frontend checks passed\n" : "\n$failed frontend problem(s)\n";
	exit($failed > 0 ? 1 : 0);
}

try {
	$steps[$step]();
}
catch (Throwable $e) {
	echo "FAIL  step $step: ".get_class($e).': '.$e->getMessage().' at '.$e->getFile().':'.$e->getLine()."\n";
	exit(1);
}

// Zabbix's profile writer runs at shutdown and wants a database.
exit(0);
