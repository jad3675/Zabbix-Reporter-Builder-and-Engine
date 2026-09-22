<?php declare(strict_types = 1);

namespace Modules\Reporter\Lib\Core;

/**
 * Jobs the UI asks the runner to do: send a test email, check the API token, or run a
 * report now and deliver it. The UI cannot do these itself because it cannot open the
 * secrets. The runner picks them up on its next pass (every five minutes with the
 * shipped timer) and records the outcome, which the Settings page shows.
 */
final class Requests {

	public const TYPES = ['test_mail', 'test_api', 'run_report'];
	public const MAX_PENDING = 10;

	public static function enqueue(string $type, array $params, string $who): string {
		if (!in_array($type, self::TYPES, true)) {
			throw new \InvalidArgumentException('Unknown request type.');
		}

		$dir = Config::dataDir('requests');

		if (count(glob($dir.'/*.json') ?: []) >= self::MAX_PENDING) {
			throw new \RuntimeException('Too many requests are already waiting for the runner.');
		}

		$id = gmdate('YmdHis').'-'.bin2hex(random_bytes(4));
		Config::writeFile($dir.'/'.$id.'.json', json_encode([
			'id' => $id, 'type' => $type, 'params' => $params, 'by' => $who, 'at' => time()
		]));

		return $id;
	}

	/** @return array[] oldest first */
	public static function pending(): array {
		$out = [];

		foreach (glob(Config::dataDir('requests').'/*.json') ?: [] as $file) {
			$r = json_decode((string) file_get_contents($file), true);

			if (is_array($r) && isset($r['id'])) {
				$out[] = $r;
			}
		}

		usort($out, static fn($a, $b) => strcmp($a['id'], $b['id']));

		return $out;
	}

	public static function complete(array $request, bool $ok, string $message): void {
		@unlink(Config::dataDir('requests').'/'.basename($request['id']).'.json');
		Config::writeFile(Config::dataDir('state/requests').'/'.basename($request['id']).'.json', json_encode(
			$request + ['done_at' => time(), 'ok' => $ok, 'message' => mb_substr($message, 0, 1000)]
		));
	}

	/** @return array[] newest first */
	public static function results(int $limit = 15): array {
		$files = glob(Config::dataDir('state/requests').'/*.json') ?: [];
		rsort($files);
		$out = [];

		foreach ($files as $i => $file) {
			if ($i >= $limit) {
				if (filemtime($file) < time() - 30 * 86400) {
					@unlink($file);
				}

				continue;
			}

			$r = json_decode((string) file_get_contents($file), true);

			if (is_array($r)) {
				$out[] = $r;
			}
		}

		return $out;
	}
}
