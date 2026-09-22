<?php declare(strict_types = 1);

namespace Modules\Reporter\Actions;

use CController;
use Modules\Reporter\Lib\Api\FrontendApi;
use Modules\Reporter\Lib\Core\Cache;
use Modules\Reporter\Lib\Core\Config;
use Modules\Reporter\Lib\Core\Definition;
use Modules\Reporter\Lib\Core\DefinitionStore;
use Modules\Reporter\Lib\Core\Lock;
use Modules\Reporter\Lib\Core\Period;
use Modules\Reporter\Lib\Core\Runner;
use Modules\Reporter\Lib\Section\Registry;

require_once dirname(__DIR__).'/lib/bootstrap.php';

abstract class BaseAction extends CController {

	private ?Registry $registry = null;

	protected function checkPermissions(): bool {
		return $this->getUserType() >= (int) Config::get('min_user_type_view', USER_TYPE_ZABBIX_USER);
	}

	protected function canEdit(): bool {
		return $this->getUserType() >= (int) Config::get('min_user_type_edit', USER_TYPE_ZABBIX_ADMIN);
	}

	protected function isSuperAdmin(): bool {
		return $this->getUserType() >= USER_TYPE_SUPER_ADMIN;
	}

	protected function who(): string {
		return (string) (\CWebUser::$data['username'] ?? ('user '.(\CWebUser::$data['userid'] ?? '?')));
	}

	protected function registry(): Registry {
		return $this->registry ??= new Registry();
	}

	protected function store(): DefinitionStore {
		return new DefinitionStore();
	}

	/** Load and re-normalize a stored definition, so a hand-edited file cannot bypass validation. */
	protected function loadDefinition(string $id): array {
		$raw = $this->store()->load($id);

		if ($raw === null) {
			throw new \RuntimeException(sprintf('Report "%s" does not exist.', $id));
		}

		[$def, $errors] = Definition::normalize($raw, $this->registry());

		if ($errors) {
			throw new \RuntimeException('The stored definition is invalid: '.implode(' ', $errors));
		}

		return $def;
	}

	protected function frontendLimits(): array {
		$limits = (array) Config::get('limits', []);
		$limits['time_budget'] = (int) ($limits['time_budget_frontend'] ?? 90);
		$limits['throttle_ms'] = (int) ($limits['throttle_ms_frontend'] ?? 0);
		$limits['max_rows_total'] = (int) ($limits['max_rows_frontend'] ?? 8000000);

		return $limits;
	}

	/** Period from the preview controls, falling back to the definition. */
	protected function periodFromInput(array $def): Period {
		$type = $this->getInput('period', '');

		if ($type === 'custom') {
			return Period::custom($this->getInput('from', ''), $this->getInput('till', ''), $def['timezone']);
		}

		if ($type !== '' && array_key_exists($type, Period::TYPES)) {
			return Period::resolve($type, (int) $this->getInput('n', $def['period']['n']), $def['timezone']);
		}

		return Period::resolve($def['period']['type'], (int) $def['period']['n'], $def['timezone']);
	}

	/** Period arguments to carry from the preview into export and print links. */
	protected function periodArgs(): array {
		$args = [];

		foreach (['period', 'n', 'from', 'till'] as $key) {
			if ($this->hasInput($key) && $this->getInput($key) !== '') {
				$args[$key] = $this->getInput($key);
			}
		}

		return $args;
	}

	/**
	 * Run a report for the logged-in user: cached if fresh, otherwise behind the
	 * concurrency lock and inside the frontend budget.
	 *
	 * @return array{report: array, age: int|null}
	 */
	protected function runReport(array $def, Period $period, bool $refresh = false): array {
		$limits = $this->frontendLimits();
		$api = new FrontendApi();
		$cache = new Cache((int) ($limits['cache_ttl'] ?? 900));
		$key = Cache::key($api->identity(), $def, $period);
		$cached = $cache->get($key);

		if ($cached !== null && (!$refresh || $cached[1] < (int) ($limits['refresh_min_age'] ?? 60))) {
			return ['report' => $cached[0], 'age' => $cached[1]];
		}

		$lock = Lock::acquire((int) ($limits['max_concurrent_runs'] ?? 2));

		if ($lock === null) {
			throw new \RuntimeException('Other reports are running right now. Try again in a minute.');
		}

		try {
			@set_time_limit((int) $limits['time_budget'] + 60);
			$report = (new Runner($this->registry()))->run($def, $period, $api, $limits);
			$cache->put($key, $report);
		}
		finally {
			$lock->release();
		}

		return ['report' => $report, 'age' => null];
	}

	/** Send a file and stop. Used by export and print, which bypass Zabbix's layout. */
	protected function sendRaw(string $bytes, string $mime, ?string $filename): void {
		while (ob_get_level() > 0) {
			ob_end_clean();
		}

		header('Content-Type: '.$mime);
		header('Content-Length: '.strlen($bytes));
		header('X-Content-Type-Options: nosniff');
		header('Cache-Control: private, no-store');

		if ($filename !== null) {
			header('Content-Disposition: attachment; filename="'.$filename.'"');
		}

		echo $bytes;
		exit;
	}

	protected function jsonResponse(array $data): void {
		$this->setResponse(new \CControllerResponseData(['main_block' => json_encode($data)]));
	}
}
