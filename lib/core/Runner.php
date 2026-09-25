<?php declare(strict_types = 1);

namespace Modules\Reporter\Lib\Core;

use Modules\Reporter\Lib\Api\ApiClient;
use Modules\Reporter\Lib\Api\BudgetExceeded;
use Modules\Reporter\Lib\Api\Guard;
use Modules\Reporter\Lib\Section\Registry;

/**
 * Runs a definition for a period and returns a plain-array report that renderers and the
 * cache understand. A failing section becomes an error block in the report; it does not
 * take down the sections around it. A budget stop ends the run, marks the remaining
 * sections as skipped, and says which limit was hit.
 */
final class Runner {

	private Registry $registry;

	public function __construct(Registry $registry) {
		$this->registry = $registry;
	}

	public function run(array $def, Period $period, ApiClient $client, array $limits): array {
		$started = microtime(true);
		$max_days = (int) ($limits['max_period_days'] ?? 400);

		if ($period->days() > $max_days + 1) {
			throw new BudgetExceeded(sprintf('The period is longer than the %d-day limit.', $max_days));
		}

		$guard = new Guard($client, $limits);
		$scope = Scope::resolve($guard, $def['scope'], (int) ($limits['max_hosts'] ?? 5000));

		if (!$scope['hosts']) {
			throw new \RuntimeException('No monitored hosts you can see are in this report\'s scope.');
		}

		$ctx = new Context($guard, $period, $def, $scope, $limits);

		if (!empty($def['compare'])) {
			// Same scope, same client, the period before. Costs a second set of queries,
			// so it is only built if a section asks for it.
			$ctx->setPreviousFactory(static fn() => new Context($guard, $period->previous(), $def, $scope, $limits));
		}

		$sections = [];
		$stopped = null;

		foreach ($def['sections'] as $i => $s) {
			$impl = $this->registry->has($s['type']) ? $this->registry->get($s['type']) : null;
			$entry = [
				'type' => $s['type'],
				'title' => $s['title'] !== '' ? $s['title'] : ($impl ? $impl->label() : $s['type']),
				'description' => (string) ($s['options']['intro'] ?? '') !== ''
					? (string) $s['options']['intro']
					: ($impl ? $impl->description() : ''),
				'status' => 'ok',
				'message' => '',
				'blocks' => [],
				'notes' => [],
				'seconds' => 0.0
			];

			if ($impl === null) {
				$entry['status'] = 'error';
				$entry['message'] = sprintf('Section type "%s" is not installed.', $s['type']);
			}
			elseif ($stopped !== null) {
				$entry['status'] = 'skipped';
				$entry['message'] = 'Not run: the report stopped at an earlier section.';
			}
			else {
				$t0 = microtime(true);

				try {
					$result = $impl->run($ctx, $impl->normalizeOptions($s['options'] ?? []))->toArray();
					$entry['blocks'] = $result['blocks'];
					$entry['notes'] = array_merge($result['notes'], $ctx->takeWarnings());
				}
				catch (BudgetExceeded $e) {
					$entry['status'] = 'error';
					$entry['message'] = $e->getMessage();
					$stopped = $e->getMessage();
				}
				catch (\Throwable $e) {
					$entry['status'] = 'error';
					$entry['message'] = self::scrub($e->getMessage());
					$ctx->takeWarnings();
				}

				$entry['seconds'] = round(microtime(true) - $t0, 2);
			}

			$sections[] = $entry;
		}

		return [
			'version' => REPORTER_VERSION,
			'id' => $def['id'],
			'name' => $def['name'],
			'branding' => $def['branding'],
			'period' => $period->toArray(),
			'scope' => [
				'hosts' => count($scope['hosts']),
				'groups' => array_values($scope['groups']),
				'unmatched_groups' => $scope['unmatched'],
				'host_tags' => TagFilter::format($def['scope']['host_tags'])
			],
			'compare' => !empty($def['compare']),
			'generated_at' => time(),
			'stopped' => $stopped,
			'stats' => $guard->stats() + ['seconds' => round(microtime(true) - $started, 2)],
			'sections' => $sections
		];
	}

	/** Keep internal paths and SQL out of what a customer might see. */
	public static function scrub(string $message): string {
		$message = preg_replace('~/[\w./-]+\.php(:\d+)?~', '[path]', $message) ?? $message;

		return mb_substr($message, 0, 500);
	}
}
