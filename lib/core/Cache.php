<?php declare(strict_types = 1);

namespace Modules\Reporter\Lib\Core;

/**
 * Finished report results, keyed by who ran it, the definition content and the period.
 * Repeated previews and the export that follows a preview cost nothing.
 */
final class Cache {

	private string $dir;
	private int $ttl;

	public function __construct(int $ttl) {
		$this->dir = Config::dataDir('cache');
		$this->ttl = max(0, $ttl);
	}

	public static function key(string $identity, array $def, Period $period): string {
		return sha1(implode('|', [REPORTER_VERSION, $identity, Definition::hash($def), $period->key()]));
	}

	/** @return array{0: array, 1: int}|null  report and age in seconds */
	public function get(string $key): ?array {
		if ($this->ttl === 0) {
			return null;
		}

		$file = $this->dir.'/'.$key.'.json';

		if (!is_file($file)) {
			return null;
		}

		$age = time() - (int) filemtime($file);

		if ($age > $this->ttl) {
			@unlink($file);

			return null;
		}

		$data = json_decode((string) file_get_contents($file), true);

		return is_array($data) ? [$data, $age] : null;
	}

	public function put(string $key, array $report): void {
		if ($this->ttl === 0) {
			return;
		}

		try {
			Config::writeFile($this->dir.'/'.$key.'.json', (string) json_encode($report));
		}
		catch (\Throwable $e) {
			// A cache that cannot be written is only a slower cache.
		}

		if (mt_rand(1, 20) === 1) {
			$this->prune();
		}
	}

	public function prune(): void {
		$cutoff = time() - $this->ttl;

		foreach (glob($this->dir.'/*') ?: [] as $file) {
			if (@filemtime($file) < $cutoff) {
				@unlink($file);
			}
		}
	}
}
