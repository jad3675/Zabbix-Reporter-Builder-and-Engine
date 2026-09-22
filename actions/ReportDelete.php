<?php declare(strict_types = 1);

namespace Modules\Reporter\Actions;

/**
 * AJAX endpoint (layout.json). CSRF validation stays on: this action writes.
 */
class ReportDelete extends BaseAction {

	protected function checkInput(): bool {
		$ret = $this->validateInput(['id' => 'required|string']);

		if (!$ret) {
			$this->jsonResponse(['errors' => ['The request was incomplete.']]);
		}

		return $ret;
	}

	protected function checkPermissions(): bool {
		return parent::checkPermissions() && $this->canEdit();
	}

	protected function doAction(): void {
		try {
			$this->store()->delete($this->getInput('id'));
			$this->jsonResponse(['ok' => true]);
		}
		catch (\Throwable $e) {
			$this->jsonResponse(['errors' => [$e->getMessage()]]);
		}
	}
}
