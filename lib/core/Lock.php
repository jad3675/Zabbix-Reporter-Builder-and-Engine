<?php declare(strict_types = 1);

namespace Modules\Reporter\Lib\Core;

/**
 * A counting semaphore on flock(). At most N report runs execute at once across every
 * frontend worker and the CLI runner, however many people press Preview. The OS releases
 * the lock if the process dies, so a crashed run cannot wedge it.
 */
final class Lock {

	/** @var resource|null */
	private $handle = null;

	public static function acquire(int $slots): ?self {
		$dir = Config::dataDir('locks');

		for ($i = 0; $i < max(1, $slots); $i++) {
			$handle = fopen($dir.'/run-'.$i.'.lock', 'c');

			if ($handle === false) {
				continue;
			}

			if (flock($handle, LOCK_EX | LOCK_NB)) {
				$lock = new self();
				$lock->handle = $handle;

				return $lock;
			}

			fclose($handle);
		}

		return null;
	}

	public function release(): void {
		if ($this->handle !== null) {
			flock($this->handle, LOCK_UN);
			fclose($this->handle);
			$this->handle = null;
		}
	}

	public function __destruct() {
		$this->release();
	}
}
