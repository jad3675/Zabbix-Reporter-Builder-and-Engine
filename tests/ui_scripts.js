// node tests/ui_scripts.js OUT_DIR  (needs jsdom on NODE_PATH)
// Executes the editor and settings scripts from pages rendered by the harness, clicks
// through them, and checks what they would POST.
const {JSDOM} = require('jsdom');
const fs = require('fs');
const dir = process.argv[2];
let failed = 0;
const ok = (what, cond, detail = '') => {
	console.log((cond ? 'ok    ' : 'FAIL  ') + what + (cond || !detail ? '' : '  (' + detail + ')'));
	if (!cond) failed++;
};

const page = (file) => {
	const html = fs.readFileSync(dir + '/' + file, 'utf8');
	const dom = new JSDOM('<!DOCTYPE html><body></body>', {runScripts: 'outside-only', url: 'https://zabbix.example/zabbix.php'});
	const w = dom.window;
	const errors = [];
	w.addEventListener('error', (e) => errors.push(e.message));
	// Zabbix's multiselect, as far as the editor is concerned.
	const picks = {'rpt-groups': [{id: '10', name: 'Acme/Network'}], 'rpt-hosts': [{id: '10001', name: 'core-sw-01'}]};
	w.jQuery = (arg) => {
		if (typeof arg === 'function') return arg(w.jQuery);
		const id = String(arg).replace('#', '');
		return {multiSelect: (cmd) => cmd === 'getData' ? (picks[id] || []) : undefined};
	};
	w.HTMLElement.prototype.scrollIntoView = () => {};
	w.document.body.innerHTML = html.replace(/<script>[\s\S]*?<\/script>/g, '');
	const posted = [];
	w.fetch = (url, opts) => {
		posted.push({url, body: new w.URLSearchParams(opts.body.toString())});
		return Promise.resolve({json: () => Promise.resolve({ok: true, redirect: 'x', message: 'Sent to x.',
			secrets: {token_set: true, token_by: 'Admin', token_at: 1, smtp: null}})});
	};
	for (const m of html.matchAll(/<script>([\s\S]*?)<\/script>/g)) {
		try { w.eval(m[1]); } catch (e) { errors.push(e.message); }
	}
	return {w, d: w.document, errors, posted};
};

(async () => {
	// Editor.
	let {w, d, errors, posted} = page('edit.html');
	w.reporterEditorInit();
	ok('editor script runs without errors', errors.length === 0, errors.join('; '));
	ok('editor renders fieldsets', d.querySelectorAll('.rpt-fieldset').length === 6);
	ok('editor renders default sections', d.querySelectorAll('.rpt-card').length === 2);

	const setVal = (el, v, ev = 'input') => { el.value = v; el.dispatchEvent(new w.Event(ev)); };
	const byLabel = (text) => {
		const l = [...d.querySelectorAll('label')].find((x) => x.textContent.trim() === text);
		return l && d.getElementById(l.getAttribute('for'));
	};

	setVal(byLabel('Name'), 'CCH Weekly Ops');
	ok('ID follows the name', byLabel('ID').value === 'cch-weekly-ops', byLabel('ID').value);

	const addSel = [...d.querySelectorAll('.rpt-inline select')].pop();
	setVal(addSel, 'capacity_growth', 'change');
	[...d.querySelectorAll('button')].find((b) => b.textContent === 'Add section').click();
	ok('adding a section', d.querySelectorAll('.rpt-card').length === 3);
	[...d.querySelectorAll('.rpt-card')][2].querySelector('button[aria-label="Move up"]').click();
	ok('moving a section', d.querySelectorAll('.rpt-card')[1].textContent.includes('Capacity outlook'));

	d.getElementById('rpt-save').click();
	await new Promise((r) => setTimeout(r, 10));
	const def = JSON.parse(posted[0].body.get('definition'));
	ok('save posts to reporter.save with CSRF token', posted[0].url.includes('reporter.save') && posted[0].body.get('_csrf_token'));
	ok('posted definition has the edits', def.id === 'cch-weekly-ops'
		&& def.sections.map((s) => s.type).join() === 'summary,capacity_growth,problems_by_host', JSON.stringify(def.sections.map((s) => s.type)));
	ok('tag options travel as text', typeof def.sections[1].options.item_tags === 'string'
		&& def.sections[1].options.item_tags === 'component=storage', JSON.stringify(def.sections[1].options.item_tags));

	// JSON round trip with stored-format arrays.
	d.querySelector('details.rpt-json').open = true;
	d.querySelector('details.rpt-json').dispatchEvent(new w.Event('toggle'));
	const ta = d.querySelector('details.rpt-json textarea');
	const stored = JSON.parse(ta.value);
	stored.scope.group_patterns = ['A/*', 'B/*'];
	stored.scope.host_tags = [{tag: 'site', operator: 'equals', value: 'Toronto'}, {tag: 'decom', operator: 'not_exists', value: ''}];
	ta.value = JSON.stringify(stored);
	[...d.querySelectorAll('button')].find((b) => b.textContent === 'Apply JSON').click();
	ok('pasted patterns and tags land in the fields', d.getElementById('rpt-group-patterns').value === 'A/*\nB/*'
		&& d.getElementById('rpt-host-tags').value === 'site=Toronto\n!decom',
		JSON.stringify([d.getElementById('rpt-group-patterns').value, d.getElementById('rpt-host-tags').value]));
	ok('scope comes from the Zabbix pickers', def.scope.groups.join() === 'Acme/Network'
		&& def.scope.hosts.join() === 'core-sw-01', JSON.stringify(def.scope));

	// Fixed periods in the editor: the right picker shows, and what is picked is posted.
	const rowOf = (label) => byLabel(label) && byLabel(label).closest('.rpt-field');
	setVal(byLabel('Covers'), 'day', 'change');
	ok('picking "a single day" shows the date picker only', !rowOf('Day').hidden && rowOf('Days').hidden
		&& rowOf('From').hidden && byLabel('Day').type === 'date');
	setVal(byLabel('Day'), '2026-11-11');
	setVal(byLabel('Covers'), 'range', 'change');
	ok('picking a range shows two date-time pickers', rowOf('Day').hidden && !rowOf('From').hidden && !rowOf('Until').hidden
		&& byLabel('From').type === 'datetime-local');
	setVal(byLabel('From'), '2026-11-11T08:00');
	setVal(byLabel('Until'), '2026-11-11T17:00');

	// Every add re-renders the editor, so the earlier select is gone.
	setVal([...d.querySelectorAll('.rpt-inline select')].pop(), 'network_interfaces', 'change');
	[...d.querySelectorAll('button')].find((b) => b.textContent === 'Add section').click();
	ok('interfaces section added with its filters', d.querySelectorAll('.rpt-card').length === 4
		&& byLabel('Description (ifAlias) matches') && byLabel('Description (ifAlias) matches').value === '*uplink*'
		&& byLabel('Interface name matches') && byLabel('When both are set'));
	setVal(byLabel('Interface name matches'), 'Te1/1/*');
	ok('link state filter offered', byLabel('Link state') && [...byLabel('Link state').options].map((o) => o.value).join()
		=== 'all,not_up_any,not_up_end,never_up,up_all');
	setVal(byLabel('Link state'), 'not_up_end', 'change');

	const before = posted.length;
	d.getElementById('rpt-save').click();
	await new Promise((r) => setTimeout(r, 10));
	const def2 = JSON.parse(posted[before].body.get('definition'));
	ok('range is posted', def2.period.type === 'range' && def2.period.from === '2026-11-11T08:00'
		&& def2.period.till === '2026-11-11T17:00', JSON.stringify(def2.period));
	const ifs = def2.sections.find((x) => x.type === 'network_interfaces');
	ok('interfaces options posted', ifs && ifs.options.alias === '*uplink*' && ifs.options.name === 'Te1/1/*'
		&& ifs.options.sort === 'peak_util' && ifs.options.state === 'not_up_end', JSON.stringify(ifs && ifs.options));
	ok('editor still error-free', errors.length === 0, errors.join('; '));
	ok('the picked range survives a re-render', byLabel('From').value === '2026-11-11T08:00' && !rowOf('From').hidden);

	// Preview toolbar.
	({w, d, errors} = page('preview.html'));
	ok('preview script runs without errors', errors.length === 0, errors.join('; '));
	const period = d.getElementById('rpt-period');
	const dayInput = d.getElementById('rpt-day');
	const fromInput = d.querySelector('input[name=from]');
	ok('preview starts with only the period select', dayInput.hidden && dayInput.disabled && fromInput.disabled
		&& d.querySelector('input[name=n]').disabled);
	setVal(period, 'day', 'change');
	ok('single day enables the date and the step buttons', !dayInput.hidden && !dayInput.disabled
		&& !d.getElementById('rpt-day-prev').hidden && fromInput.disabled);
	let submitted = 0;
	period.form.addEventListener('submit', (e) => { submitted++; e.preventDefault(); });
	dayInput.value = '2026-03-01';
	d.getElementById('rpt-day-prev').click();
	ok('the day-before button steps back across a month end and submits', dayInput.value === '2026-02-28' && submitted === 1,
		dayInput.value + ' / ' + submitted);
	d.getElementById('rpt-day-next').click();
	d.getElementById('rpt-day-next').click();
	ok('the day-after button steps forward', dayInput.value === '2026-03-02', dayInput.value);
	setVal(period, 'range', 'change');
	ok('a range enables both date-time pickers and disables the day', !fromInput.disabled
		&& !d.querySelector('input[name=till]').disabled && dayInput.disabled);
	ok('only the inputs in use would go in the link', [...new w.FormData(period.form).keys()].sort().join()
		=== 'action,from,id,period,till', [...new w.FormData(period.form).keys()].join());

	// Settings.
	({w, d, errors, posted} = page('settings.html'));
	w.reporterSettingsInit();
	ok('settings script runs without errors', errors.length === 0, errors.join('; '));
	ok('settings shows token status', d.body.textContent.includes('Set by Admin'));
	ok('settings shows the media copy is current', d.body.textContent.includes('Using smtp.encore.example:587'));
	ok('token input is empty', d.querySelector('input[type=password]').value === '');
	const media = byLabel('Send email using');
	ok('media dropdown offers email types', [...media.options].map((o) => o.textContent).join('|')
		=== 'None: no email delivery|Email (HTML)|Office 365 (OAuth, not supported)');
	setVal(byLabel('API token'), 'cd'.repeat(32));
	setVal(byLabel('Zabbix URL'), 'http://localhost/zabbix/');
	d.getElementById('rpt-save').click();
	await new Promise((r) => setTimeout(r, 10));
	const b = posted[0].body;
	const sent = JSON.parse(b.get('settings'));
	ok('settings save posts settings, token and CSRF', posted[0].url.includes('reporter.settings.save')
		&& sent.runner.api_url === 'http://localhost/zabbix/' && sent.delivery.mediatypeid === '1'
		&& b.get('api_token') === 'cd'.repeat(32) && b.get('_csrf_token'));
	ok('typed token is gone after save', d.querySelector('input[type=password]').value === '');

	setVal(byLabel('API token'), 'ef'.repeat(32));
	setVal(byLabel('Zabbix URL'), 'https://zabbix.monitored.it');
	[...d.querySelectorAll('button')].find((x) => x.textContent === 'Test token').click();
	await new Promise((r) => setTimeout(r, 10));
	const t = posted[posted.length - 1].body;
	ok('test token sends the unsaved form values', t.get('type') === 'test_api' && t.get('url') === 'https://zabbix.monitored.it'
		&& t.get('token') === 'ef'.repeat(32) && t.get('verify_tls') === '1');

	setVal(d.querySelector('input[type=email]'), 'john@encore.example');
	[...d.querySelectorAll('button')].find((x) => x.textContent === 'Send test email').click();
	await new Promise((r) => setTimeout(r, 10));
	const m = posted[posted.length - 1];
	ok('test email uses the selected media type and shows the answer inline', m.url.includes('reporter.request')
		&& m.body.get('type') === 'test_mail' && m.body.get('mediatypeid') === '1' && d.body.textContent.includes('Sent to x.'));

	console.log(failed ? `\n${failed} UI script check(s) failed` : '\nall UI script checks passed');
	process.exit(failed ? 1 : 0);
})();
