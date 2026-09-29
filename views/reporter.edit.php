<?php declare(strict_types = 1);

/**
 * @var CView $this
 * @var array $data
 *
 * The device pickers are Zabbix's own multiselects, rendered here so they behave like
 * every other host and group picker in the frontend. Everything else is built by
 * views/js/reporter.edit.js.php from the section schemas, so a drop-in section gets an
 * editor without touching this file. The server re-validates everything on save.
 */

$this->includeJsFile('reporter.edit.js.php', $data);

$page = (new CHtmlPage())
	->setTitle($data['title'])
	->setControls((new CTag('nav', true,
		(new CList())->addItem(
			(new CRedirectButton(_('Back to reports'),
				(new CUrl('zabbix.php'))->setArgument('action', 'reporter.list')->getUrl()
			))->addClass(ZBX_STYLE_BTN_ALT)
		)
	))->setAttribute('aria-label', _('Content controls')));

if ($data['error'] !== null) {
	$page->addItem((new CDiv($data['error']))->addClass('rpt-notice rpt-notice-error'));
}

$groups_ms = (new CMultiSelect([
	'name' => 'groups[]',
	'object_name' => 'hostGroup',
	'data' => $data['selected_groups'],
	'popup' => [
		'parameters' => [
			'srctbl' => 'host_groups',
			'srcfld1' => 'groupid',
			'dstfrm' => 'rpt-form',
			'dstfld1' => 'rpt-groups',
			'monitored_hosts' => true,
			'enrich_parent_groups' => true
		]
	],
	'add_post_js' => false
]))->setId('rpt-groups');

$hosts_ms = (new CMultiSelect([
	'name' => 'hosts[]',
	'object_name' => 'hosts',
	'data' => $data['selected_hosts'],
	'popup' => [
		'parameters' => [
			'srctbl' => 'hosts',
			'srcfld1' => 'hostid',
			'dstfrm' => 'rpt-form',
			'dstfld1' => 'rpt-hosts',
			'monitored_hosts' => true
		]
	],
	'add_post_js' => false
]))->setId('rpt-hosts');

$field = static function (string $label, $control, string $hint = '') {
	return (new CDiv([
		new CLabel($label, is_object($control) && method_exists($control, 'getId') ? $control->getId() : null),
		(new CDiv([$control, $hint !== '' ? (new CDiv($hint))->addClass('rpt-hint') : null]))->addClass('rpt-control')
	]))->addClass('rpt-field');
};

$devices = (new CTag('fieldset', true, [
	new CTag('legend', true, _('Devices')),
	$field(_('Host groups'), $groups_ms, _('Every monitored host in these groups.')),
	$field(_('Group name patterns'),
		(new CTextArea('group_patterns', implode("\n", $data['definition']['scope']['group_patterns'])))
			->setId('rpt-group-patterns')
			->setAttribute('rows', 2)
			->setAttribute('spellcheck', 'false'),
		_('One per line, for groups that come and go: Acme/* matches every group whose name starts with Acme/.')
	),
	$field(_('Devices'), $hosts_ms, _('Added on top of the groups above, whatever the tag filter says.')),
	$field(_('Host tags'),
		(new CTextArea('host_tags', Modules\Reporter\Lib\Core\TagFilter::format($data['definition']['scope']['host_tags'])))
			->setId('rpt-host-tags')
			->setAttribute('rows', 3)
			->setAttribute('spellcheck', 'false'),
		_('Optional, and applied to the groups above. One per line: site, site=Toronto, site~tor, site!=Lab, !decommissioned.')
	),
	$field(_('Tag logic'),
		(new CSelect('tag_logic'))
			->setId('rpt-tag-logic')
			->setValue($data['definition']['scope']['tag_logic'])
			->addOptions(CSelect::createOptionsFromArray([
				'and' => _('All tags must match'),
				'or' => _('Any tag matches')
			]))
	)
]))->addClass('rpt-fieldset');

$page
	->addItem((new CDiv())->setId('rpt-errors')->addClass('rpt-notice rpt-notice-error')->setAttribute('hidden', 'hidden'))
	->addItem((new CForm())->setId('rpt-form')->addItem([
		(new CDiv(_('Loading editor…')))->setId('rpt-editor')->addClass('rpt-editor'),
		(new CDiv($devices))->setId('rpt-devices')
	]))
	->show();

(new CScriptTag($groups_ms->getPostJS().$hosts_ms->getPostJS().'window.reporterEditorInit();'))
	->setOnDocumentReady()
	->show();
