<?php declare(strict_types = 1);

namespace Modules\Reporter\Actions;

use Modules\Reporter\Lib\Core\Definition;

/**
 * AJAX endpoint (layout.json). CSRF validation stays on: this action writes.
 */
class ReportSave extends BaseAction {

	protected function checkInput(): bool {
		$ret = $this->validateInput([
			'definition' => 'required|string',
			'original_id' => 'string'
		]);

		if (!$ret) {
			$this->jsonResponse(['errors' => ['The request was incomplete.']]);
		}

		return $ret;
	}

	protected function checkPermissions(): bool {
		return parent::checkPermissions() && $this->canEdit();
	}

	protected function doAction(): void {
		$input = json_decode($this->getInput('definition'), true);

		if (!is_array($input)) {
			$this->jsonResponse(['errors' => ['The definition is not valid JSON.']]);

			return;
		}

		[$def, $errors] = Definition::normalize($input, $this->registry());
		$original = $this->getInput('original_id', '');

		try {
			$store = $this->store();

			if (!$errors && $def['id'] !== $original && $store->exists($def['id'])) {
				$errors[] = sprintf('A report with ID "%s" already exists.', $def['id']);
			}

			if ($errors) {
				$this->jsonResponse(['errors' => $errors]);

				return;
			}

			$store->save($def);

			if ($original !== '' && $original !== $def['id'] && $store->exists($original)) {
				$store->delete($original);
			}
		}
		catch (\Throwable $e) {
			$this->jsonResponse(['errors' => [$e->getMessage()]]);

			return;
		}

		$this->jsonResponse([
			'ok' => true,
			'id' => $def['id'],
			'redirect' => (new \CUrl('zabbix.php'))
				->setArgument('action', 'reporter.preview')
				->setArgument('id', $def['id'])
				->getUrl()
		]);
	}
}
