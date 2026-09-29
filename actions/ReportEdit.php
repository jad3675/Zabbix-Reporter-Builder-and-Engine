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

		// Multiselects need ids; definitions store names, so resolve what is selected.
		$selected_groups = [];

		if ($def['scope']['groups']) {
			$found = API::HostGroup()->get(['output' => ['groupid', 'name'],
				'filter' => ['name' => $def['scope']['groups']]]);

			foreach (is_array($found) ? $found : [] as $g) {
				$selected_groups[] = ['id' => $g['groupid'], 'name' => $g['name']];
			}
		}

		$selected_hosts = [];

		if ($def['scope']['hosts']) {
			$found = API::Host()->get(['output' => ['hostid', 'name'], 'filter' => ['name' => $def['scope']['hosts']],
				'monitored_hosts' => true]);

			foreach (is_array($found) ? $found : [] as $h) {
				$selected_hosts[] = ['id' => $h['hostid'], 'name' => $h['name']];
			}
		}

		$missing = array_merge(
			array_diff($def['scope']['groups'], array_column($selected_groups, 'name')),
			array_diff($def['scope']['hosts'], array_column($selected_hosts, 'name'))
		);

		if ($missing) {
			$error = sprintf('Not found, and dropped from the pickers unless you add them back: %s.',
				implode(', ', $missing));
		}

		$this->setResponse(new CControllerResponseData([
			'title' => $id !== '' && !$clone ? _('Edit report') : _('New report'),
			'definition' => $def,
			'original_id' => $clone ? '' : $id,
			'schemas' => $this->registry()->schemas(),
			'selected_groups' => $selected_groups,
			'selected_hosts' => $selected_hosts,
			'timezones' => \DateTimeZone::listIdentifiers(),
			'period_types' => Period::TYPES,
			'csrf_save' => \CCsrfTokenHelper::get('reporter.save'),
			'error' => $error
		]));
	}
}
