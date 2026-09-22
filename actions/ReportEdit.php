<?php declare(strict_types = 1);

namespace Modules\Reporter\Actions;

use API;
use CControllerResponseData;
use Modules\Reporter\Lib\Core\Definition;
use Modules\Reporter\Lib\Core\Period;

class ReportEdit extends BaseAction {

	protected function init(): void {
		$this->disableCsrfValidation();
	}

	protected function checkInput(): bool {
		$ret = $this->validateInput([
			'id' => 'string',
			'clone' => 'in 0,1'
		]);

		if (!$ret) {
			$this->setResponse(new \CControllerResponseFatal());
		}

		return $ret;
	}

	protected function checkPermissions(): bool {
		return parent::checkPermissions() && $this->canEdit();
	}

	protected function doAction(): void {
		$error = null;
		$id = $this->getInput('id', '');
		$def = Definition::blank();

		if ($id !== '') {
			try {
				$raw = $this->store()->load($id);

				if ($raw === null) {
					$error = sprintf('Report "%s" does not exist.', $id);
				}
				else {
					[$def] = Definition::normalize($raw, $this->registry());
				}
			}
			catch (\Throwable $e) {
				$error = $e->getMessage();
			}
		}

		$clone = $this->getInput('clone', '0') === '1';

		if ($clone) {
			$def['id'] = $def['id'] !== '' ? $def['id'].'-copy' : '';
			$def['name'] = $def['name'] !== '' ? $def['name'].' (copy)' : '';
			$def['schedule']['enabled'] = false;
		}

		$groups = API::HostGroup()->get(['output' => ['name'], 'with_monitored_hosts' => true, 'limit' => 5000]);

		$this->setResponse(new CControllerResponseData([
			'title' => $id !== '' && !$clone ? _('Edit report') : _('New report'),
			'definition' => $def,
			'original_id' => $clone ? '' : $id,
			'schemas' => $this->registry()->schemas(),
			'group_names' => array_values(array_column(is_array($groups) ? $groups : [], 'name')),
			'timezones' => \DateTimeZone::listIdentifiers(),
			'period_types' => Period::TYPES,
			'csrf_save' => \CCsrfTokenHelper::get('reporter.save'),
			'error' => $error
		]));
	}
}
