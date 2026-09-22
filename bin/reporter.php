#!/usr/bin/env php
<?php declare(strict_types = 1);

/*
 * Report builder runner. Sends scheduled reports and handles "Send now" from the UI.
 * The zabbix-reporter.timer runs it every five minutes as the web server user.
 *
 *   reporter.php run-due [--dry-run]    scheduled reports and queued requests (the timer runs this)
 *   reporter.php run <id> [--period=previous_month|previous_week|previous_day|month_to_date|last_n_days]
 *                         [--n=30] [--from=YYYY-MM-DD --till=YYYY-MM-DD]
 *                         [--format=pdf,xlsx,csv] [--out=DIR] [--mail] [--refresh]
 *   reporter.php check                  settings, API reachability, export support
 *   reporter.php test-api
 *   reporter.php test-mail <address>
 *   reporter.php list
 *   reporter.php validate <file.json>
 *
 * Add --quiet to any command. The Zabbix URL, API token and email media type are set on
 * the Settings page. REPORTER_DATA_DIR points at a non-default data directory.
 */

if (PHP_SAPI !== 'cli') {
	http_response_code(404);
	exit;
}

require dirname(__DIR__).'/lib/bootstrap.php';

use Modules\Reporter\Lib\Core\Cache;
use Modules\Reporter\Lib\Core\Config;
use Modules\Reporter\Lib\Core\Definition;
use Modules\Reporter\Lib\Core\DefinitionStore;
use Modules\Reporter\Lib\Core\Lock;
use Modules\Reporter\Lib\Core\Period;
use Modules\Reporter\Lib\Core\Requests;
use Modules\Reporter\Lib\Core\Runner;
use Modules\Reporter\Lib\Core\RunnerState;
use Modules\Reporter\Lib\Core\Schedule;
use Modules\Reporter\Lib\Core\Settings;
use Modules\Reporter\Lib\Delivery\Channels;
use Modules\Reporter\Lib\Render\Exporter;
use Modules\Reporter\Lib\Section\Registry;

umask(0007);

$args = [];
$flags = [];

foreach (array_slice($argv, 1) as $arg) {
	if (preg_match('/^--([a-z-]+)(?:=(.*))?$/', $arg, $m)) {
		$flags[$m[1]] = $m[2] ?? true;
	}
	else {
		$args[] = $arg;
	}
}

$quiet = isset($flags['quiet']);
$say = static function (string $msg) use ($quiet): void {
	if (!$quiet) {
		fwrite(STDOUT, $msg."\n");
	}
};
$fail = static function (string $msg, int $code = 1): void {
	fwrite(STDERR, 'reporter: '.$msg."\n");
	exit($code);
};

$command = $args[0] ?? 'help';

$limits = static function (): array {
	$limits = (array) Config::get('limits', []);
	$limits['time_budget'] = (int) ($limits['time_budget_cli'] ?? 1800);
	$limits['throttle_ms'] = (int) ($limits['throttle_ms_cli'] ?? 25);
	$limits['max_rows_total'] = (int) ($limits['max_rows_cli'] ?? 40000000);

	return $limits;
};

$registry = new Registry();

foreach ($registry->loadErrors() as $e) {
	fwrite(STDERR, 'reporter: section drop-in: '.$e."\n");
}

$load = static function (string $id) use ($registry): array {
	$raw = (new DefinitionStore())->load($id);

	if ($raw === null) {
		throw new RuntimeException(sprintf('No report with ID "%s".', $id));
	}

	[$def, $errors] = Definition::normalize($raw, $registry);

	if ($errors) {
		throw new RuntimeException(sprintf('Report "%s" is invalid: %s', $id, implode(' ', $errors)));
	}

	return $def;
};

$out_dir = static function (): string {
	return Config::dataDir('out');
};

/**
 * Run, export and optionally mail one report. Returns a one-line summary.
 */
$produce = static function (array $def, Period $period, array $formats, ?string $out, bool $mail, bool $refresh)
		use ($limits, $registry, $say, $out_dir): string {
	$client = Channels::api();
	$lim = $limits();
	$cache = new Cache((int) ($lim['cache_ttl'] ?? 900));
	$key = Cache::key($client->identity(), $def, $period);
	$cached = $refresh ? null : $cache->get($key);

	if ($cached !== null) {
		$report = $cached[0];
	}
	else {
		// Wait for a free slot rather than failing: the timer can afford patience.
		$lock = null;

		for ($i = 0; $i < 60 && $lock === null; $i++) {
			$lock = Lock::acquire((int) ($lim['max_concurrent_runs'] ?? 2));

			if ($lock === null) {
				sleep(10);
			}
		}

		if ($lock === null) {
			throw new RuntimeException('No free run slot after 10 minutes.');
		}

		try {
			@set_time_limit(0);
			$report = (new Runner($registry))->run($def, $period, $client, $lim);
			$cache->put($key, $report);
		}
		finally {
			$lock->release();
		}
	}

	$s = $report['stats'];
	$summary = sprintf('%s, %d hosts, %d API calls, %.1f s', $period->label, $report['scope']['hosts'],
		$s['api_calls'], $s['seconds']);
	$say($def['id'].': '.$summary);

	foreach ($report['sections'] as $sec) {
		if ($sec['status'] !== 'ok') {
			fwrite(STDERR, sprintf("reporter: %s: section \"%s\" %s: %s\n", $def['id'], $sec['title'], $sec['status'],
				$sec['message']));
		}
	}

	$dir = ($out ?? $out_dir()).'/'.$def['id'];

	if (!is_dir($dir) && !mkdir($dir, 02770, true) && !is_dir($dir)) {
		throw new RuntimeException(sprintf('Cannot create %s.', $dir));
	}

	@ini_set('memory_limit', '1024M');
	$files = [];

	foreach ($formats as $format) {
		[$bytes, $name, $mime] = Exporter::export($report, $format);
		$path = $dir.'/'.$name;
		Config::writeFile($path, $bytes);
		$files[] = ['path' => $path, 'name' => $name, 'mime' => $mime];
		$say('  wrote '.$path);
	}

	if ($mail && $def['delivery']['email_to']) {
		$b = $def['branding'];
		$subject = $def['delivery']['email_subject'] !== ''
			? $def['delivery']['email_subject'].' ('.$period->label.')'
			: trim(($b['customer'] !== '' ? $b['customer'].': ' : '').$b['title'].', '.$period->label);
		$body = $def['delivery']['email_body'] !== ''
			? $def['delivery']['email_body']
			: sprintf("Attached: %s for %s.\n", $b['title'], $period->label);

		if ($report['stopped']) {
			$body .= "\n\nThis report is incomplete: ".$report['stopped']."\n";
		}

		Channels::mailer()->send($def['delivery']['email_to'], $subject, $body, $files);
		$say('  mailed '.implode(', ', $def['delivery']['email_to']));
		$summary .= '; mailed to '.implode(', ', $def['delivery']['email_to']);
	}
	elseif ($mail) {
		$summary .= '; no recipients set, files written only';
	}

	if ($report['stopped']) {
		$summary .= '; INCOMPLETE: '.$report['stopped'];
	}

	return $summary;
};

$retention = static function () use ($out_dir): void {
	$days = (int) Settings::load()['delivery']['retention_days'];
	$out = $out_dir();

	if ($days <= 0 || !is_dir($out)) {
		return;
	}

	$cutoff = time() - $days * 86400;

	foreach (glob($out.'/*/*.{pdf,xlsx,csv}', GLOB_BRACE) ?: [] as $f) {
		if (filemtime($f) < $cutoff) {
			@unlink($f);
		}
	}
};

try {
	switch ($command) {
		case 'check':
			$st = \Modules\Reporter\Lib\Core\Secrets::status();
			$set = Settings::load();
			$say('module:    '.REPORTER_ROOT.' ('.REPORTER_VERSION.')');
			$say('data dir:  '.Config::dataDir());
			$say('pdf:       '.(\Modules\Reporter\Lib\Render\PdfRenderer::available() ? 'available' : 'vendor/ missing'));
			$say('xlsx:      '.(\Modules\Reporter\Lib\Render\XlsxWriter::available() ? 'available' : 'PHP zip extension missing'));
			$say('sections:  '.implode(', ', array_keys($registry->all())));
			$say('zabbix:    '.($set['runner']['api_url'] ?: '(not set)'));
			$say('email:     '.($st['smtp'] ? sprintf('%s (%s:%d)', $st['smtp']['name'], $st['smtp']['server'], $st['smtp']['port']) : '(not set)'));
			$say('api:       '.Channels::testApi());
			break;

		case 'test-api':
			$say(Channels::testApi());
			break;

		case 'test-mail':
			$say(Channels::testMail((string) ($args[1] ?? '')));
			break;

		case 'list':
			foreach ((new DefinitionStore())->all() as $id => $raw) {
				$s = $raw['schedule'] ?? [];
				$say(sprintf('%-32s %-40s %s', $id, $raw['name'] ?? '', !empty($s['enabled'])
					? sprintf('%s day %d %02d:00', $s['cycle'], $s['day'], $s['hour']) : 'not scheduled'));
			}

			break;

		case 'validate':
			$raw = json_decode((string) @file_get_contents((string) ($args[1] ?? '')), true);
			[$def, $errors] = Definition::normalize($raw, $registry);

			if ($errors) {
				$fail(implode("\n", $errors));
			}

			$say('valid: '.$def['id']);
			break;

		case 'run':
			$def = $load((string) ($args[1] ?? ''));

			if (isset($flags['from']) || isset($flags['till'])) {
				$period = Period::custom((string) ($flags['from'] ?? ''), (string) ($flags['till'] ?? ''), $def['timezone']);
			}
			else {
				$period = Period::resolve((string) ($flags['period'] ?? $def['period']['type']),
					(int) ($flags['n'] ?? $def['period']['n']), $def['timezone']);
			}

			$formats = isset($flags['format'])
				? array_values(array_intersect(['pdf', 'xlsx', 'csv'], explode(',', (string) $flags['format'])))
				: $def['delivery']['formats'];

			$produce($def, $period, $formats ?: ['pdf'], isset($flags['out']) ? (string) $flags['out'] : null,
				isset($flags['mail']), isset($flags['refresh']));
			break;

		case 'run-due':
			// Only one run-due at a time, however the timer and manual runs overlap.
			$guard = fopen(Config::dataDir('locks').'/run-due.lock', 'c');

			if (!flock($guard, LOCK_EX | LOCK_NB)) {
				$say('Another run-due is in progress.');
				exit(0);
			}

			$failures = 0;
			RunnerState::heartbeat([
				'version' => REPORTER_VERSION,
				'host' => gethostname(),
				'user' => function_exists('posix_geteuid') ? (posix_getpwuid(posix_geteuid())['name'] ?? '') : get_current_user(),
				'php' => PHP_VERSION
			]);

			// 1. Requests from the UI.
			foreach (array_slice(Requests::pending(), 0, 20) as $req) {
				try {
					switch ($req['type']) {
						case 'test_api':
							$msg = Channels::testApi();
							break;

						case 'test_mail':
							$msg = Channels::testMail((string) ($req['params']['to'] ?? ''));
							break;

						case 'run_report':
							$def = $load((string) ($req['params']['id'] ?? ''));
							$period = Period::resolve($def['period']['type'], (int) $def['period']['n'], $def['timezone']);
							$msg = $produce($def, $period, $def['delivery']['formats'], null, true, false);
							break;

						default:
							throw new RuntimeException('Unknown request.');
					}

					Requests::complete($req, true, $msg);
					$say(sprintf('request %s (%s): %s', $req['id'], $req['type'], $msg));
				}
				catch (Throwable $e) {
					$failures++;
					Requests::complete($req, false, Runner::scrub($e->getMessage()));
					fwrite(STDERR, sprintf("reporter: request %s (%s) failed: %s\n", $req['id'], $req['type'], $e->getMessage()));
				}
			}

			// 2. Scheduled reports.
			foreach ((new DefinitionStore())->all() as $id => $raw) {
				[$def, $errors] = Definition::normalize($raw, $registry);

				if ($errors || !$def['schedule']['enabled']) {
					continue;
				}

				$state = RunnerState::report((string) $id);
				$period = Schedule::due($def, $state['last_key'] ?? null);

				if ($period === null) {
					continue;
				}

				if (isset($flags['dry-run'])) {
					$say(sprintf('%s is due for %s', $id, $period->label));

					continue;
				}

				// Back off after a failure so a broken report does not retry every five minutes.
				if (!empty($state['last_error']) && ($state['last_attempt_key'] ?? null) === $period->key()
						&& time() - (int) ($state['last_attempt'] ?? 0) < 3600) {
					continue;
				}

				try {
					$summary = $produce($def, $period, $def['delivery']['formats'], null, true, true);
					$state = ['last_key' => $period->key(), 'last_label' => $period->label, 'last_run' => time(),
						'last_summary' => $summary, 'last_error' => null];
				}
				catch (Throwable $e) {
					$failures++;
					// last_key stays put so this period is retried.
					$state['last_error'] = Runner::scrub($e->getMessage());
					$state['last_attempt'] = time();
					$state['last_attempt_key'] = $period->key();
					fwrite(STDERR, sprintf("reporter: %s failed: %s\n", $id, $e->getMessage()));
				}

				RunnerState::saveReport((string) $id, $state);
			}

			$retention();
			exit($failures > 0 ? 1 : 0);

		default:
			$say(trim((string) preg_replace('/^ \* ?/m', '', explode('*/', explode('/*', (string) file_get_contents(__FILE__), 2)[1])[0])));
	}
}
catch (Throwable $e) {
	$fail($e->getMessage());
}
