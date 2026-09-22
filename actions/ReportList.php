<?php declare(strict_types = 1);

namespace Modules\Reporter\Actions;

use CControllerResponseData;
use Modules\Reporter\Lib\Core\Config;
use Modules\Reporter\Lib\Core\Definition;
use Modules\Reporter\Lib\Core\TagFilter;
use Modules\Reporter\Lib\Render\PdfRenderer;
use Modules\Reporter\Lib\Render\XlsxWriter;

class ReportList extends BaseAction {

	protected function init(): void {
		$this->disableCsrfValidation();
	}

	protected function checkInput(): bool {
		return true;
	}

	protected function doAction(): void {
		$data = [
			'reports' => [],
			'can_edit' => $this->canEdit(),
			'is_super' => $this->isSuperAdmin(),
			'error' => null,
			'warnings' => $this->registry()->loadErrors(),
			'data_dir' => (string) Config::get('data_dir'),
			'csrf_delete' => \CCsrfTokenHelper::get('reporter.delete')
		];

		try {
			if ($data['is_super'] && \Modules\Reporter\Lib\Core\RunnerState::runner() === null) {
				$data['warnings'][] = 'The scheduled-delivery runner has not run yet. Open Settings for the one-time server step.';
			}
		}
		catch (\Throwable $e) {
			// Reported below by the store.
		}

		if (!PdfRenderer::available()) {
			$data['warnings'][] = 'The vendor/ directory is missing, so PDF export is unavailable. Install the release archive, which includes it.';
		}

		if (!XlsxWriter::available()) {
			$data['warnings'][] = 'The PHP zip extension is not loaded, so XLSX export is unavailable. CSV still works.';
		}

		try {
			foreach ($this->store()->all() as $id => $raw) {
				[$def, $errors] = Definition::normalize($raw, $this->registry());
				$data['reports'][] = [
					'id' => (string) $id,
					'name' => $def['name'] !== '' ? $def['name'] : (string) $id,
					'description' => $def['description'],
					'scope' => implode(', ', $def['scope']['groups'])
						.($def['scope']['host_tags'] ? ' ['.str_replace("\n", ', ', TagFilter::format($def['scope']['host_tags'])).']' : ''),
					'period' => \Modules\Reporter\Lib\Core\Period::TYPES[$def['period']['type']]
						.($def['period']['type'] === 'last_n_days' ? ' ('.$def['period']['n'].')' : ''),
					'sections' => count($def['sections']),
					'schedule' => $def['schedule']['enabled'] ? self::describeSchedule($def) : '',
					'state' => \Modules\Reporter\Lib\Core\RunnerState::report((string) $id),
					'invalid' => $errors
				];
			}
		}
		catch (\Throwable $e) {
			$data['error'] = $e->getMessage();
		}

		$this->setResponse(new CControllerResponseData($data + ['title' => _('Report builder')]));
	}

	private static function describeSchedule(array $def): string {
		$s = $def['schedule'];
		$time = sprintf('%02d:00', $s['hour']);

		switch ($s['cycle']) {
			case 'daily': return sprintf('Daily at %s', $time);
			case 'weekly': return sprintf('%ss at %s', ['', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday',
				'Saturday', 'Sunday'][$s['day']], $time);
			default: return sprintf('Monthly on day %d at %s', $s['day'], $time);
		}
	}
}
