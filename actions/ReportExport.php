<?php declare(strict_types = 1);

namespace Modules\Reporter\Actions;

use Modules\Reporter\Lib\Render\Exporter;

/**
 * Download as PDF, XLSX or CSV. Reuses the cached run from the preview, so exporting what
 * you just looked at does not query Zabbix again.
 */
class ReportExport extends BaseAction {

	protected function init(): void {
		$this->disableCsrfValidation();
	}

	protected function checkInput(): bool {
		$ret = $this->validateInput([
			'id' => 'required|string',
			'format' => 'required|in pdf,xlsx,csv',
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
			@ini_set('memory_limit', (string) max(\Modules\Reporter\Lib\Api\Guard::memoryLimitBytes(), 512 * 1024 * 1024));
			[$bytes, $filename, $mime] = Exporter::export($result['report'], $this->getInput('format'));
			$this->sendRaw($bytes, $mime, $filename);
		}
		catch (\Throwable $e) {
			\CMessageHelper::setErrorTitle(_('Export failed'));
			\CMessageHelper::addError(\Modules\Reporter\Lib\Core\Runner::scrub($e->getMessage()));
			$this->setResponse(new \CControllerResponseRedirect(
				(new \CUrl('zabbix.php'))
					->setArgument('action', 'reporter.preview')
					->setArgument('id', $this->getInput('id'))
			));
		}
	}
}
