<?php declare(strict_types = 1);

namespace Modules\Reporter\Lib\Core;

/**
 * What the runner last reported about itself and each scheduled report.
 */
final class RunnerState {

	public static function heartbeat(array $info): void {
		Config::writeFile(Config::dataDir('state').'/runner.json', json_encode($info + ['last_seen' => time()]));
	}

	public static function runner(): ?array {
		$file = Config::dataDir('state').'/runner.json';
		$data = is_readable($file) ? json_decode((string) file_get_contents($file), true) : null;

		return is_array($data) ? $data : null;
	}

	public static function report(string $id): array {
		$file = Config::dataDir('state').'/'.basename($id).'.json';
		$data = is_readable($file) ? json_decode((string) file_get_contents($file), true) : null;

		return is_array($data) ? $data : [];
	}

	public static function saveReport(string $id, array $state): void {
		Config::writeFile(Config::dataDir('state').'/'.basename($id).'.json', json_encode($state, JSON_PRETTY_PRINT));
	}
}
