<?php declare(strict_types = 1);

/**
 * @var CView $this
 * @var array $data
 */

$url = static function (string $action, array $args = []) use ($data): string {
	$u = (new CUrl('zabbix.php'))->setArgument('action', $action)->setArgument('id', $data['id']);

	foreach ($args as $k => $v) {
		$u->setArgument($k, $v);
	}

	return $u->getUrl();
};

$controls = new CList();

if ($data['can_edit']) {
	$controls->addItem(
		(new CRedirectButton(_('Edit'), $url('reporter.edit')))->addClass(ZBX_STYLE_BTN_ALT)
	);
}

$page = (new CHtmlPage())
	->setTitle($data['title'])
	->setControls((new CTag('nav', true, $controls))->setAttribute('aria-label', _('Content controls')));

// Period controls: a plain GET form, so every state of the preview has a shareable URL.
$pi = $data['period_input'];
$selected = $pi['period'] === 'custom' ? 'range' : $pi['period'];
$period_select = (new CTag('select', true))->setAttribute('name', 'period')->setId('rpt-period');
$period_select->addItem((new CTag('option', true, _('As defined in the report')))->setAttribute('value', ''));

foreach ($data['period_types'] as $value => $label) {
	$option = (new CTag('option', true, $label))->setAttribute('value', $value);

	if ($selected === $value) {
		$option->setAttribute('selected', 'selected');
	}

	$period_select->addItem($option);
}

// A datetime-local input only takes YYYY-MM-DDTHH:MM. Links from before 1.8 carry bare
// dates, where the end date meant "through the end of that day".
$local = static function (string $value, bool $is_end): string {
	$value = trim(str_replace(' ', 'T', $value));

	if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
		return $is_end ? date('Y-m-d', strtotime($value.' +1 day')).'T00:00' : $value.'T00:00';
	}

	return preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}/', $value) ? substr($value, 0, 16) : '';
};

$form = (new CForm('get'))
	->addClass('rpt-toolbar')
	->addVar('action', 'reporter.preview')
	->addVar('id', $data['id'])
	->addItem([
		new CLabel(_('Period'), 'rpt-period'),
		$period_select,
		(new CTag('input', false))->setAttribute('type', 'number')->setAttribute('name', 'n')->setAttribute('min', 1)
			->setAttribute('max', 400)->setAttribute('value', $pi['n'] !== '' ? $pi['n'] : 30)
			->setAttribute('aria-label', _('Days'))->addClass('rpt-when-n rpt-num'),
		(new CButton('rpt-day-prev', '◀'))->setId('rpt-day-prev')->addClass(ZBX_STYLE_BTN_ALT)
			->addClass('rpt-when-day rpt-step')->setAttribute('title', _('Day before')),
		(new CTag('input', false))->setAttribute('type', 'date')->setAttribute('name', 'day')->setId('rpt-day')
			->setAttribute('value', $pi['day'])->setAttribute('aria-label', _('Day'))->addClass('rpt-when-day'),
		(new CButton('rpt-day-next', '▶'))->setId('rpt-day-next')->addClass(ZBX_STYLE_BTN_ALT)
			->addClass('rpt-when-day rpt-step')->setAttribute('title', _('Day after')),
		(new CTag('input', false))->setAttribute('type', 'datetime-local')->setAttribute('name', 'from')
			->setAttribute('value', $local($pi['from'], false))->setAttribute('step', 60)
			->setAttribute('aria-label', _('From'))->addClass('rpt-when-range'),
		(new CSpan(_('to')))->addClass('rpt-when-range rpt-to'),
		(new CTag('input', false))->setAttribute('type', 'datetime-local')->setAttribute('name', 'till')
			->setAttribute('value', $local($pi['till'], true))->setAttribute('step', 60)
			->setAttribute('aria-label', _('Until'))->addClass('rpt-when-range'),
		(new CSubmitButton(_('Show')))->addClass(ZBX_STYLE_BTN_ALT)
	]);

$downloads = [];

if ($data['can_pdf']) {
	$downloads[] = new CRedirectButton(_('Download PDF'), $url('reporter.export', $data['period_args'] + ['format' => 'pdf']));
}

if ($data['can_xlsx']) {
	$downloads[] = (new CRedirectButton(_('Download XLSX'),
		$url('reporter.export', $data['period_args'] + ['format' => 'xlsx'])))->addClass(ZBX_STYLE_BTN_ALT);
}

$downloads[] = (new CRedirectButton(_('Download CSV'),
	$url('reporter.export', $data['period_args'] + ['format' => 'csv'])))->addClass(ZBX_STYLE_BTN_ALT);
if ($data['can_edit'] && $data['recipients']) {
	$downloads[] = (new CButton('rpt-send', _('Send now')))
		->addClass(ZBX_STYLE_BTN_ALT)
		->setAttribute('title', sprintf(_('Email the report for its configured period to %s'), implode(', ', $data['recipients'])));
}

$downloads[] = (new CLink(_('Print view'), $url('reporter.print', $data['period_args'])))
	->setTarget('_blank')
	->addClass('rpt-link');

$page->addItem((new CDiv([$form, (new CDiv($downloads))->addClass('rpt-downloads')]))->addClass('rpt-bar'));

if ($data['error'] !== null) {
	$page->addItem((new CDiv($data['error']))->addClass('rpt-notice rpt-notice-error'));
}
else {
	if ($data['age'] !== null) {
		$page->addItem((new CDiv([
			sprintf(_('Showing a result computed %s ago.'), zbx_date2age(time() - $data['age'])),
			' ',
			new CLink(_('Recompute'), $url('reporter.preview', $data['period_args'] + ['refresh' => 1]))
		]))->addClass('rpt-cache'));
	}

	$page->addItem(
		(new CTag('iframe', true))
			->setAttribute('srcdoc', $data['html'])
			->setAttribute('sandbox', 'allow-same-origin')
			->setAttribute('title', $data['title'])
			->setId('rpt-frame')
			->addClass('rpt-frame')
	);
}

$page->show();

(new CScriptTag('
	(() => {
		const select = document.getElementById("rpt-period");
		const form = select.form;
		const sync = () => {
			const show = {"rpt-when-n": "last_n_days", "rpt-when-day": "day", "rpt-when-range": "range"};

			for (const [cls, type] of Object.entries(show)) {
				document.querySelectorAll("." + cls).forEach((el) => {
					el.hidden = select.value !== type;

					// Hidden inputs stay out of the URL, so a link carries only what it uses.
					if (el.name) {
						el.disabled = select.value !== type;
					}
				});
			}
		};

		select.addEventListener("change", sync);
		sync();

		// Step the picked day back or forward and show it straight away.
		const day = document.getElementById("rpt-day");
		const step = (delta) => {
			const base = day.value !== "" ? new Date(day.value + "T12:00:00") : new Date();
			base.setDate(base.getDate() + delta);
			day.value = base.getFullYear() + "-" + String(base.getMonth() + 1).padStart(2, "0") + "-"
				+ String(base.getDate()).padStart(2, "0");
			form.requestSubmit ? form.requestSubmit() : form.submit();
		};

		document.getElementById("rpt-day-prev").addEventListener("click", () => step(-1));
		document.getElementById("rpt-day-next").addEventListener("click", () => step(1));

		const send = document.getElementById("rpt-send");

		if (send) {
			send.addEventListener("click", () => {
				if (!confirm('.json_encode(sprintf(_('Email this report to %s now?'), implode(', ', $data['recipients']))).')) {
					return;
				}

				const body = new URLSearchParams({type: "run_report", id: '.json_encode($data['id']).'});
				body.append('.json_encode(CSRF_TOKEN_NAME).', '.json_encode($data['csrf_request']).');
				send.disabled = true;

				fetch("zabbix.php?action=reporter.request", {method: "POST", body})
					.then((r) => r.json())
					.then((res) => {
						if (res.ok) {
							send.textContent = '.json_encode(_('Queued for the runner')).';
						}
						else {
							alert((res.errors || ["Could not queue."]).join("\n"));
							send.disabled = false;
						}
					})
					.catch(() => { alert("The server did not answer."); send.disabled = false; });
			});
		}

		const frame = document.getElementById("rpt-frame");

		if (frame) {
			const fit = () => {
				try {
					frame.style.height = (frame.contentDocument.documentElement.scrollHeight + 4) + "px";
				}
				catch (e) {}
			};

			frame.addEventListener("load", fit);
			window.addEventListener("resize", fit);
		}
	})();
'))->show();
