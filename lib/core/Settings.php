<?php declare(strict_types = 1);

namespace Modules\Reporter\Lib\Core;

/**
 * What the Settings page can change, stored in <data_dir>/settings.json. Secrets (the API
 * token and the copied SMTP credentials) are kept apart in secrets.json.
 *
 * The data and output directories are not settable from the UI: a setting that chooses
 * where files are written would let a Zabbix Super admin write files as the web server
 * user.
 *
 * Every limit has a hard ceiling here, so the UI can tune the safety rails but not
 * remove them.
 */
final class Settings {

	/** name => [min, max, label, hint] */
	public const LIMITS = [
		'max_hosts' => [10, 20000, 'Hosts per report', 'Reports whose scope matches more hosts are refused.'],
		'max_items' => [100, 100000, 'Items per selector', 'Item selectors matching more are refused.'],
		'max_period_days' => [1, 400, 'Longest period (days)', ''],
		'time_budget_frontend' => [10, 300, 'Time budget in the browser (s)', 'Keep below PHP max_execution_time and your web server timeout.'],
		'time_budget_cli' => [60, 14400, 'Time budget for scheduled runs (s)', ''],
		'max_rows_frontend' => [100000, 20000000, 'Rows per run in the browser', 'Trend rows plus events. A month at 2,500 hosts with every section is about 5 million.'],
		'max_rows_cli' => [100000, 200000000, 'Rows per scheduled run', ''],
		'max_api_calls' => [100, 20000, 'API calls per run', ''],
		'max_concurrent_runs' => [1, 4, 'Runs at the same time', 'Across all users and the runner.'],
		'cache_ttl' => [0, 86400, 'Cache results for (s)', '0 disables the cache.'],
		'throttle_ms_frontend' => [0, 200, 'Pause between API calls in the browser (ms)', ''],
		'throttle_ms_cli' => [0, 1000, 'Pause between API calls for scheduled runs (ms)', 'Spreads scheduled load out; 25 ms costs a few seconds per report.'],
		'pdf_max_table_rows' => [50, 2000, 'Table rows in a PDF', 'Spreadsheet exports always contain every row.']
	];

	public static function defaults(): array {
		$limits = [];
		$manifest = json_decode((string) @file_get_contents(REPORTER_ROOT.'/manifest.json'), true);

		foreach (self::LIMITS as $name => $spec) {
			$limits[$name] = (int) ($manifest['config']['limits'][$name] ?? $spec[0]);
		}

		return [
			'access' => ['min_user_type_view' => 1, 'min_user_type_edit' => 2],
			'runner' => ['api_url' => '', 'verify_tls' => true],
			'delivery' => ['mediatypeid' => '', 'retention_days' => 400],
			'limits' => $limits,
			'media_names' => []
		];
	}

	/** @return array{0: array, 1: string[]} */
	public static function normalize($input): array {
		$in = is_array($input) ? $input : [];
		$d = self::defaults();
		$errors = [];
		$out = $d;

		$a = (array) ($in['access'] ?? []);
		$out['access']['min_user_type_view'] = max(1, min(3, (int) ($a['min_user_type_view'] ?? $d['access']['min_user_type_view'])));
		$out['access']['min_user_type_edit'] = max(2, min(3, (int) ($a['min_user_type_edit'] ?? $d['access']['min_user_type_edit'])));

		$r = (array) ($in['runner'] ?? []);
		$url = trim((string) ($r['api_url'] ?? ''));

		if ($url !== '') {
			$parts = parse_url($url);

			if ($parts === false || !in_array(strtolower($parts['scheme'] ?? ''), ['http', 'https'], true)
					|| ($parts['host'] ?? '') === '') {
				$errors[] = 'Zabbix URL must be an http or https address.';
			}
			elseif (isset($parts['user']) || isset($parts['pass'])) {
				$errors[] = 'Zabbix URL must not contain a user name or password; the runner uses the API token.';
			}
		}

		$out['runner']['api_url'] = mb_substr($url, 0, 500);
		$out['runner']['verify_tls'] = self::bool($r['verify_tls'] ?? true);

		$del = (array) ($in['delivery'] ?? []);
		$mediatypeid = (string) ($del['mediatypeid'] ?? '');
		$out['delivery']['mediatypeid'] = ctype_digit($mediatypeid) ? $mediatypeid : '';
		$out['delivery']['retention_days'] = max(0, min(3650, (int) ($del['retention_days'] ?? $d['delivery']['retention_days'])));

		foreach (self::LIMITS as $name => [$min, $max]) {
			$value = $in['limits'][$name] ?? $d['limits'][$name];
			$out['limits'][$name] = is_numeric($value) ? max($min, min($max, (int) $value)) : $d['limits'][$name];
		}

		$names = [];

		foreach ((array) ($in['media_names'] ?? []) as $id => $name) {
			if (ctype_digit((string) $id)) {
				$names[(string) $id] = mb_substr(self::oneLine((string) $name), 0, 100);
			}
		}

		$out['media_names'] = array_slice($names, 0, 200, true);

		return [$out, $errors];
	}

	public static function load(): array {
		$file = Config::dataDir().'/settings.json';
		$raw = is_readable($file) ? json_decode((string) file_get_contents($file), true) : [];

		return self::normalize(is_array($raw) ? $raw : [])[0];
	}

	public static function save(array $settings): void {
		[$normalized] = self::normalize($settings);
		Config::writeFile(Config::dataDir().'/settings.json',
			json_encode($normalized, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n");
		Config::set(null);
	}

	/** The parts of settings that feed Config. */
	public static function toConfig(array $raw): array {
		[$s] = self::normalize($raw);

		return [
			'min_user_type_view' => $s['access']['min_user_type_view'],
			'min_user_type_edit' => $s['access']['min_user_type_edit'],
			'limits' => $s['limits'],
			'media_names' => $s['media_names']
		];
	}

	private static function bool($v): bool {
		return is_string($v) ? in_array(strtolower($v), ['1', 'true', 'on', 'yes'], true) : (bool) $v;
	}

	private static function oneLine(string $s): string {
		return trim(str_replace(["\r", "\n", "\0"], ' ', $s));
	}
}
