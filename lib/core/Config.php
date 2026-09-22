<?php declare(strict_types = 1);

namespace Modules\Reporter\Lib\Core;

/**
 * Configuration, in increasing precedence:
 *
 *   1. manifest.json "config"          shipped defaults
 *   2. <data_dir>/config.json          optional hand-edited overrides
 *   3. <data_dir>/settings.json        what the Settings page saves (validated, clamped)
 *
 * The data directory itself comes from the manifest, REPORTER_DATA_DIR, or the CLI's
 * reporter.ini. It is the one thing the UI cannot change, since the UI's own settings
 * live inside it.
 */
final class Config {

	private static ?array $data = null;

	public static function all(): array {
		if (self::$data === null) {
			$manifest = json_decode((string) @file_get_contents(REPORTER_ROOT.'/manifest.json'), true);
			$data = is_array($manifest['config'] ?? null) ? $manifest['config'] : [];

			$env_dir = getenv('REPORTER_DATA_DIR');

			if (is_string($env_dir) && $env_dir !== '') {
				$data['data_dir'] = $env_dir;
			}

			$data_dir = (string) ($data['data_dir'] ?? '');
			$base = rtrim($data_dir, '/');

			foreach (['config.json', 'settings.json'] as $file) {
				$override = is_readable($base.'/'.$file)
					? json_decode((string) file_get_contents($base.'/'.$file), true)
					: null;

				if (!is_array($override)) {
					continue;
				}

				if ($file === 'settings.json') {
					$override = Settings::toConfig($override);
				}

				$data = array_replace_recursive($data, $override);
				$data['data_dir'] = $data_dir;
			}

			self::$data = $data;
		}

		return self::$data;
	}

	/** Test hook; null forces a reload (used after saving settings). */
	public static function set(?array $data): void {
		self::$data = $data;
	}

	public static function get(string $key, $default = null) {
		$node = self::all();

		foreach (explode('.', $key) as $part) {
			if (!is_array($node) || !array_key_exists($part, $node)) {
				return $default;
			}

			$node = $node[$part];
		}

		return $node;
	}

	public static function limit(string $key, int $default): int {
		return (int) self::get('limits.'.$key, $default);
	}

	/**
	 * A directory inside the data directory, created group-writable with setgid so the
	 * web server and the runner user can each use what the other wrote.
	 */
	public static function dataDir(string $sub = ''): string {
		$base = rtrim((string) self::get('data_dir', '/var/lib/zabbix/reporter'), '/');
		$dir = $sub === '' ? $base : $base.'/'.$sub;

		if (!is_dir($dir)) {
			$old = umask(0007);
			$made = @mkdir($dir, 02770, true);
			umask($old);

			if (!$made && !is_dir($dir)) {
				throw new \RuntimeException(sprintf(
					'Data directory "%s" does not exist and cannot be created. Run contrib/install-runner.sh, or create it and make it writable by the web server user.',
					$dir
				));
			}

			@chmod($dir, 02770);
		}

		if (!is_writable($dir)) {
			throw new \RuntimeException(sprintf('Data directory "%s" is not writable by this process.', $dir));
		}

		return $dir;
	}

	/** Atomic write, group read/write, never world-readable. */
	public static function writeFile(string $path, string $content, int $mode = 0660): void {
		$tmp = $path.'.'.bin2hex(random_bytes(4)).'.tmp';
		$old = umask(0007);

		try {
			if (file_put_contents($tmp, $content, LOCK_EX) === false) {
				throw new \RuntimeException(sprintf('Could not write %s.', basename($path)));
			}

			@chmod($tmp, $mode);

			if (!rename($tmp, $path)) {
				throw new \RuntimeException(sprintf('Could not replace %s.', basename($path)));
			}
		}
		finally {
			umask($old);

			if (is_file($tmp)) {
				@unlink($tmp);
			}
		}
	}
}
