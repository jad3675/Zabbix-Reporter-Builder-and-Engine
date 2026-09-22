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
	w.jQuery = (fn) => fn(w.jQuery);
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
	setVal(byLabel('Host groups'), 'CCH/*');

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
	ok('posted definition has the edits', def.id === 'cch-weekly-ops' && def.scope.groups === 'CCH/*'
		&& def.sections.map((s) => s.type).join() === 'summary,capacity_growth,problems_by_host', JSON.stringify(def.sections.map((s) => s.type)));
	ok('tag options travel as text', typeof def.sections[1].options.item_tags === 'string'
		&& def.sections[1].options.item_tags === 'component=storage', JSON.stringify(def.sections[1].options.item_tags));

	// JSON round trip with stored-format arrays.
	d.querySelector('details.rpt-json').open = true;
	d.querySelector('details.rpt-json').dispatchEvent(new w.Event('toggle'));
	const ta = d.querySelector('details.rpt-json textarea');
	const stored = JSON.parse(ta.value);
	stored.scope.groups = ['A/*', 'B'];
	stored.scope.host_tags = [{tag: 'site', operator: 'equals', value: 'Burnet'}, {tag: 'decom', operator: 'not_exists', value: ''}];
	ta.value = JSON.stringify(stored);
	[...d.querySelectorAll('button')].find((b) => b.textContent === 'Apply JSON').click();
	ok('stored-format JSON is accepted', byLabel('Host groups').value === 'A/*\nB' && byLabel('Host tags').value === 'site=Burnet\n!decom',
		JSON.stringify([byLabel('Host groups').value, byLabel('Host tags').value]));

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
