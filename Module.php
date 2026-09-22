<?php declare(strict_types = 1);

namespace Modules\Reporter;

use APP;
use CMenuItem;
use CWebUser;
use Modules\Reporter\Lib\Core\Config;
use Zabbix\Core\CModule;

class Module extends CModule {

	public function init(): void {
		require_once __DIR__.'/lib/bootstrap.php';

		if (CWebUser::getType() < (int) Config::get('min_user_type_view', USER_TYPE_ZABBIX_USER)) {
			return;
		}

		APP::Component()->get('menu.main')
			->findOrAdd(_('Reports'))
			->getSubMenu()
			->add(
				(new CMenuItem(_('Report builder')))
					->setAction('reporter.list')
					->setAliases(['reporter.edit', 'reporter.preview', 'reporter.settings'])
			);
	}
}
