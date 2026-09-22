<?php declare(strict_types = 1);

namespace Modules\Reporter\Actions;

use CControllerResponseData;
use Modules\Reporter\Lib\Core\Period;
use Modules\Reporter\Lib\Render\HtmlRenderer;
use Modules\Reporter\Lib\Render\PdfRenderer;
use Modules\Reporter\Lib\Render\XlsxWriter;

class ReportPreview extends BaseAction {

	protected function init(): void {
		$this->disableCsrfValidation();
	}

	protected function checkInput(): bool {
		$ret = $this->validateInput([
			'id' => 'required|string',
			'period' => 'string',
			'n' => 'int32',
			'from' => 'string',
			'till' => 'string',
			'refresh' => 'in 0,1'
		]);

		if (!$ret) {
			$this->setResponse(new \CControllerResponseFatal());
		}

		return $ret;
	}

	protected function doAction(): void {
		$data = [
			'id' => $this->getInput('id'),
			'title' => _('Report'),
			'can_edit' => $this->canEdit(),
			'csrf_request' => \CCsrfTokenHelper::get('reporter.request'),
			'recipients' => [],
			'period_types' => Period::TYPES,
			'period_input' => [
				'period' => $this->getInput('period', ''),
				'n' => $this->getInput('n', ''),
				'from' => $this->getInput('from', ''),
				'till' => $this->getInput('till', '')
			],
			'period_args' => $this->periodArgs(),
			'can_pdf' => PdfRenderer::available(),
			'can_xlsx' => XlsxWriter::available(),
			'html' => null,
			'age' => null,
			'error' => null
		];

		try {
			$def = $this->loadDefinition($data['id']);
			$data['title'] = $def['name'];
			$data['recipients'] = $def['delivery']['email_to'];
			$data['definition_period'] = $def['period'];
			$result = $this->runReport($def, $this->periodFromInput($def), $this->getInput('refresh', '0') === '1');
			$data['html'] = (new HtmlRenderer($result['report'], 'screen'))->document();
			$data['age'] = $result['age'];
			$data['period_label'] = $result['report']['period']['label'];
		}
		catch (\Throwable $e) {
			$data['error'] = $e->getMessage();
		}

		$this->setResponse(new CControllerResponseData($data));
	}
}
