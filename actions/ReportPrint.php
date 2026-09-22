<?php declare(strict_types = 1);

namespace Modules\Reporter\Actions;

use Modules\Reporter\Lib\Render\HtmlRenderer;

/**
 * The report as a standalone page, for the browser's own print dialog.
 */
class ReportPrint extends BaseAction {

	protected function init(): void {
		$this->disableCsrfValidation();
	}

	protected function checkInput(): bool {
		$ret = $this->validateInput([
			'id' => 'required|string',
			'period' => 'string',
			'n' => 'int32',
			'from' => 'string',
			'till' => 'string'
		]);

		if (!$ret) {
			$this->setResponse(new \CControllerResponseFatal());
		}

		return $ret;
	}

	protected function doAction(): void {
		try {
			$def = $this->loadDefinition($this->getInput('id'));
			$result = $this->runReport($def, $this->periodFromInput($def));
			$html = (new HtmlRenderer($result['report'], 'screen'))->document();
		}
		catch (\Throwable $e) {
			$html = '<!DOCTYPE html><meta charset="utf-8"><title>Report</title><p>'
				.HtmlRenderer::e(\Modules\Reporter\Lib\Core\Runner::scrub($e->getMessage())).'</p>';
		}

		$this->sendRaw($html, 'text/html; charset=utf-8', null);
	}
}
