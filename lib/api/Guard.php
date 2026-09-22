<?php declare(strict_types = 1);

namespace Modules\Reporter\Lib\Api;

/**
 * Every API call a report makes goes through here. This is the part that keeps a report
 * from hurting the instance it reads from:
 *
 *  - read-only: only "<object>.get" on an allowlist, whatever the inner client allows;
 *  - bounded: a wall-clock deadline, a call count, a total row count and a memory floor,
 *    each of which stops the run with a message naming the limit rather than letting a
 *    runaway report keep the database busy;
 *  - polite: an optional pause between calls, so a scheduled run shares the server with
 *    the pollers instead of competing with them.
 *
 * Limits stop the run; they never silently drop data. A section that hits one reports
 * that it stopped and why.
 */
final class Guard implements ApiClient {

	private const ALLOWED = ['host', 'hostgroup', 'item', 'trend', 'event', 'alert', 'problem', 'maintenance',
		'mediatype'];

	private ApiClient $inner;
	private float $deadline;
	private int $max_calls;
	private int $max_rows;
	private int $throttle_us;
	private int $memory_floor;

	private int $calls = 0;
	private int $rows = 0;
	private float $api_seconds = 0.0;

	public function __construct(ApiClient $inner, array $limits) {
		$this->inner = $inner;
		$this->deadline = microtime(true) + max(5, (int) ($limits['time_budget'] ?? 90));
		$this->max_calls = max(10, (int) ($limits['max_api_calls'] ?? 4000));
		$this->max_rows = max(1000, (int) ($limits['max_rows_total'] ?? 8000000));
		$this->throttle_us = max(0, (int) ($limits['throttle_ms'] ?? 0)) * 1000;

		$limit = self::memoryLimitBytes();
		$headroom = max(16, (int) ($limits['memory_headroom_mb'] ?? 96)) * 1024 * 1024;
		$this->memory_floor = $limit > 0 ? max($limit - $headroom, (int) ($limit / 2)) : 0;
	}

	public function call(string $method, array $params): array {
		[$object, $verb] = array_pad(explode('.', $method, 2), 2, '');

		if ($verb !== 'get' || !in_array($object, self::ALLOWED, true)) {
			throw new ApiException(sprintf('Refused API method "%s": reports are read-only.', $method));
		}

		if ($this->calls >= $this->max_calls) {
			throw new BudgetExceeded(sprintf('API call budget of %d exhausted.', $this->max_calls));
		}

		$this->check();

		if ($this->throttle_us > 0 && $this->calls > 0) {
			usleep($this->throttle_us);
		}

		$started = microtime(true);
		$result = $this->inner->call($method, $params);
		$this->api_seconds += microtime(true) - $started;
		$this->calls++;

		if (!array_key_exists('count', $result) || count($result) !== 1) {
			$this->rows += count($result);
		}

		$this->check();

		return $result;
	}

	/** Also usable by sections doing heavy work between calls. */
	public function check(): void {
		if (microtime(true) > $this->deadline) {
			throw new BudgetExceeded('Time budget exhausted. Narrow the scope or period, or run this report from the CLI runner, which has a larger budget.');
		}

		if ($this->rows > $this->max_rows) {
			throw new BudgetExceeded(sprintf('Row budget of %d exhausted.', $this->max_rows));
		}

		if ($this->memory_floor > 0 && memory_get_usage(true) > $this->memory_floor) {
			throw new BudgetExceeded('Memory budget exhausted. Narrow the scope or period.');
		}
	}

	public function secondsLeft(): float {
		return $this->deadline - microtime(true);
	}

	public function stats(): array {
		return [
			'api_calls' => $this->calls,
			'api_rows' => $this->rows,
			'api_seconds' => round($this->api_seconds, 2)
		];
	}

	public function identity(): string {
		return $this->inner->identity();
	}

	public static function memoryLimitBytes(): int {
		$raw = trim((string) ini_get('memory_limit'));

		if ($raw === '' || $raw === '-1') {
			return 0;
		}

		$unit = strtolower(substr($raw, -1));
		$value = (int) $raw;

		switch ($unit) {
			case 'g': return $value * 1024 * 1024 * 1024;
			case 'm': return $value * 1024 * 1024;
			case 'k': return $value * 1024;
			default: return $value;
		}
	}
}
