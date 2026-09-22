<?php declare(strict_types = 1);

/**
 * @var CView $this
 * @var array $data
 *
 * The form is rendered by views/js/reporter.edit.js.php from the section schemas, so a
 * drop-in section gets an editor without touching this file. The server re-validates
 * everything on save; the script is a convenience, not a gate.
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

$page
	->addItem((new CDiv())->setId('rpt-errors')->addClass('rpt-notice rpt-notice-error')->setAttribute('hidden', 'hidden'))
	->addItem((new CDiv(_('Loading editor…')))->setId('rpt-editor')->addClass('rpt-editor'))
	->show();

(new CScriptTag('window.reporterEditorInit();'))
	->setOnDocumentReady()
	->show();
