<?php declare(strict_types = 1);

/**
 * @var CView $this
 * @var array $data
 */

$page = (new CHtmlPage())->setTitle($data['title']);

$controls = new CList();

if ($data['can_edit']) {
	$controls->addItem(new CRedirectButton(_('Create report'),
		(new CUrl('zabbix.php'))->setArgument('action', 'reporter.edit')->getUrl()
	));
}

if ($data['is_super']) {
	$controls->addItem((new CRedirectButton(_('Settings'),
		(new CUrl('zabbix.php'))->setArgument('action', 'reporter.settings')->getUrl()
	))->addClass(ZBX_STYLE_BTN_ALT));
}

$page->setControls((new CTag('nav', true, $controls))->setAttribute('aria-label', _('Content controls')));

if ($data['error'] !== null) {
	$page->addItem((new CDiv([
		(new CTag('strong', true, _('The report store is not available.'))),
		BR(),
		$data['error'],
		BR(),
		sprintf(_('Create %s and make it writable by the web server user (and by the CLI runner\'s user, if you schedule reports).'),
			$data['data_dir'])
	]))->addClass('rpt-notice rpt-notice-error'));
}

foreach ($data['warnings'] as $warning) {
	$page->addItem((new CDiv($warning))->addClass('rpt-notice'));
}

$table = (new CTableInfo())->setHeader([
	_('Name'), _('Scope'), _('Period'), _('Sections'), _('Schedule'), ''
]);

foreach ($data['reports'] as $r) {
	$preview_url = (new CUrl('zabbix.php'))
		->setArgument('action', 'reporter.preview')
		->setArgument('id', $r['id'])
		->getUrl();

	$name = [new CLink($r['name'], $preview_url)];

	if ($r['description'] !== '') {
		$name[] = (new CDiv($r['description']))->addClass('rpt-muted');
	}

	if ($r['invalid']) {
		$name[] = (new CDiv(_('Needs attention: ').implode(' ', $r['invalid'])))->addClass('rpt-invalid');
	}

	$actions = [(new CLink(_('Preview'), $preview_url))->addClass('rpt-action')];

	if ($data['can_edit']) {
		$actions[] = (new CLink(_('Edit'), (new CUrl('zabbix.php'))
			->setArgument('action', 'reporter.edit')
			->setArgument('id', $r['id'])
			->getUrl()))->addClass('rpt-action');
		$actions[] = (new CLink(_('Duplicate'), (new CUrl('zabbix.php'))
			->setArgument('action', 'reporter.edit')
			->setArgument('id', $r['id'])
			->setArgument('clone', 1)
			->getUrl()))->addClass('rpt-action');
		$actions[] = (new CLinkAction(_('Delete')))
			->addClass('rpt-action rpt-delete')
			->setAttribute('data-id', $r['id'])
			->setAttribute('data-name', $r['name']);
	}

	$table->addRow([
		$name,
		$r['scope'],
		$r['period'],
		$r['sections'],
		$r['schedule'] !== ''
			? [
				$r['schedule'],
				!empty($r['state']['last_error'])
					? (new CDiv(_('Failed: ').$r['state']['last_error']))->addClass('rpt-invalid')
					: (!empty($r['state']['last_run'])
						? (new CDiv(sprintf(_('Last sent: %s, %s ago'), $r['state']['last_label'] ?? '',
							zbx_date2age((int) $r['state']['last_run']))))->addClass('rpt-muted')
						: null)
			]
			: (new CSpan(_('Not scheduled')))->addClass('rpt-muted'),
		(new CCol($actions))->addClass('rpt-actions')
	]);
}

if (!$data['reports'] && $data['error'] === null) {
	$page->addItem((new CDiv([
		(new CTag('p', true, _('No reports yet.'))),
		(new CTag('p', true, $data['can_edit']
			? _('Create one: pick the host groups, add sections such as problems per device or capacity outlook, and preview it before exporting.')
			: _('Ask a Zabbix administrator to create one.')
		))
	]))->addClass('rpt-empty'));
}
else {
	$page->addItem($table);
}

$page->show();

(new CScriptTag('
	document.querySelectorAll(".rpt-delete").forEach((link) => {
		link.addEventListener("click", (e) => {
			e.preventDefault();

			if (!confirm('.json_encode(_('Delete report')).' + " \"" + link.dataset.name + "\"?")) {
				return;
			}

			const body = new URLSearchParams();
			body.append("id", link.dataset.id);
			body.append('.json_encode(CSRF_TOKEN_NAME).', '.json_encode($data['csrf_delete']).');

			fetch("zabbix.php?action=reporter.delete", {method: "POST", body})
				.then((r) => r.json())
				.then((res) => {
					if (res.ok) {
						location.reload();
					}
					else {
						alert((res.errors || ["Delete failed."]).join("\n"));
					}
				})
				.catch(() => alert("Delete failed."));
		});
	});
'))->show();
