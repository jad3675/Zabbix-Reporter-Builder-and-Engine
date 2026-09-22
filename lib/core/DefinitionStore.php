<?php declare(strict_types = 1);

namespace Modules\Reporter\Lib\Core;

/**
 * Definitions are JSON files in <data_dir>/definitions. No database tables: a module
 * cannot own a schema migration across Zabbix upgrades, and files can be versioned,
 * diffed and copied between instances.
 */
final class DefinitionStore {

	private string $dir;

	public function __construct(?string $dir = null) {
		$this->dir = $dir ?? Config::dataDir('definitions');
	}

	private function path(string $id): string {
		if (!preg_match('/^[a-z0-9][a-z0-9-]{0,63}$/', $id)) {
			throw new \InvalidArgumentException('Invalid report ID.');
		}

		return $this->dir.'/'.$id.'.json';
	}

	public function exists(string $id): bool {
		return is_file($this->path($id));
	}

	public function load(string $id): ?array {
		$file = $this->path($id);

		if (!is_file($file)) {
			return null;
		}

		$data = json_decode((string) file_get_contents($file), true);

		return is_array($data) ? $data : null;
	}

	/** @return array<string, array> keyed by id, sorted by name */
	public function all(): array {
		$out = [];

		foreach (glob($this->dir.'/*.json') ?: [] as $file) {
			$data = json_decode((string) file_get_contents($file), true);

			if (is_array($data) && isset($data['id'])) {
				$out[$data['id']] = $data;
			}
		}

		uasort($out, static fn($a, $b) => strcasecmp((string) ($a['name'] ?? ''), (string) ($b['name'] ?? '')));

		return $out;
	}

	public function save(array $def): void {
		Config::writeFile($this->path($def['id']),
			json_encode($def, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n");
	}

	public function delete(string $id): void {
		$file = $this->path($id);

		if (is_file($file) && !unlink($file)) {
			throw new \RuntimeException('Could not delete the report definition.');
		}
	}
}
