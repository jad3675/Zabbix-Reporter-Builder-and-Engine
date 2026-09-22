<?php declare(strict_types = 1);

/**
 * @var CView $this
 * @var array $data
 */

$user_types = [1 => _('User'), 2 => _('Admin'), 3 => _('Super admin')];

?>
<script>
window.reporterSettingsInit = () => {
	const C = <?= json_encode([
		'settings' => $data['settings'],
		'limits' => $data['limits_spec'],
		'secrets' => $data['secrets'],
		'runner' => $data['runner'],
		'pending' => $data['pending'],
		'results' => $data['results'],
		'reports' => $data['reports'],
		'media_types' => $data['media_types'],
		'checks' => $data['checks'],
		'data_dir' => $data['data_dir'],
		'suggested_url' => $data['suggested_url'],
		'user_types' => $user_types,
		'module_dir' => dirname(__DIR__, 2),
		'now' => time(),
		'csrf_name' => CSRF_TOKEN_NAME,
		'csrf_save' => $data['csrf_save'],
		'csrf_request' => $data['csrf_request']
	], JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;

	const s = JSON.parse(JSON.stringify(C.settings));
	const token = {value: '', clear: false};
	const root = document.getElementById('rpt-settings');
	const errorBox = document.getElementById('rpt-errors');
	const savedBox = document.getElementById('rpt-saved');
	let uid = 0;

	const h = (tag, attrs = {}, ...children) => {
		const el = document.createElement(tag);

		for (const [k, v] of Object.entries(attrs || {})) {
			if (v === null || v === undefined || v === false) continue;
			if (k.startsWith('on')) el.addEventListener(k.slice(2), v);
			else if (k === 'class') el.className = v;
			else if (k === 'value') el.value = v;
			else if (k === 'checked') el.checked = !!v;
			else el.setAttribute(k, v === true ? '' : v);
		}

		for (const c of children.flat()) {
			if (c !== null && c !== undefined && c !== false) {
				el.append(c instanceof Node ? c : document.createTextNode(String(c)));
			}
		}

		return el;
	};

	// The label points at the real form control even when the control is wrapped.
	const target = (control) => control.matches('input, select, textarea')
		? control : (control.querySelector('input, select, textarea') || control);

	const field = (label, control, hint = '') => {
		const el = target(control);
		el.id = el.id || ('rpt-s' + (++uid));

		return h('div', {class: 'rpt-field'},
			h('label', {for: el.id}, label),
			h('div', {class: 'rpt-control'}, control, hint ? h('div', {class: 'rpt-hint'}, hint) : null));
	};

	const text = (obj, key, attrs = {}) => h('input', Object.assign({type: 'text', value: obj[key] ?? '',
		oninput: (e) => obj[key] = e.target.value}, attrs));

	const number = (obj, key, attrs = {}) => h('input', Object.assign({type: 'number', class: 'rpt-num-wide',
		value: obj[key], oninput: (e) => obj[key] = e.target.value === '' ? '' : Number(e.target.value)}, attrs));

	const select = (obj, key, choices, attrs = {}) => h('select', Object.assign({onchange: (e) => {
		obj[key] = typeof obj[key] === 'number' ? Number(e.target.value) : e.target.value;
		if (attrs.onpick) attrs.onpick();
	}}, attrs, {onpick: null}), (Array.isArray(choices) ? choices : Object.entries(choices))
		.map(([v, l]) => h('option', {value: v, selected: String(v) === String(obj[key])}, l)));

	const check = (obj, key, label) => {
		const id = 'rpt-s' + (++uid);

		return h('span', {class: 'rpt-check'},
			h('input', {type: 'checkbox', id, checked: obj[key], onchange: (e) => obj[key] = e.target.checked}),
			h('label', {for: id}, label));
	};

	const row = (...children) => h('div', {class: 'rpt-field'}, h('span'), h('div', {class: 'rpt-control'}, children));
	const fieldset = (legend, ...children) => h('fieldset', {class: 'rpt-fieldset'}, h('legend', {}, legend), children);
	const badge = (kind, label) => h('span', {class: 'rpt-badge rpt-badge-' + kind}, label);

	const ago = (ts) => {
		if (!ts) return 'never';
		const d = Math.max(0, C.now - ts);
		if (d < 90) return d + ' s ago';
		if (d < 5400) return Math.round(d / 60) + ' min ago';
		if (d < 172800) return Math.round(d / 3600) + ' h ago';
		return Math.round(d / 86400) + ' days ago';
	};

	const showErrors = (errors) => {
		savedBox.hidden = true;
		errorBox.replaceChildren(h('strong', {}, 'Not saved.'), h('ul', {}, errors.map((e) => h('li', {}, e))));
		errorBox.hidden = false;
		errorBox.scrollIntoView({behavior: 'smooth', block: 'start'});
	};

	// A test or "Send now": post, then show the answer next to the button.
	const request = (params, button, out) => {
		const body = new URLSearchParams(params);
		body.append(C.csrf_name, C.csrf_request);
		button.disabled = true;
		out.replaceChildren(badge('warn', 'Working…'));

		return fetch('zabbix.php?action=reporter.request', {method: 'POST', body})
			.then((r) => r.json())
			.then((res) => out.replaceChildren(res.ok ? badge('ok', res.message) : badge('bad', (res.errors || ['Failed.']).join(' '))))
			.catch(() => out.replaceChildren(badge('bad', 'The server did not answer.')))
			.finally(() => button.disabled = false);
	};

	// ---------------------------------------------------------------- status

	const statusPanel = () => {
		const r = C.runner;
		const stale = r && C.now - r.last_seen > 15 * 60;
		const notes = [];

		if (!r) {
			notes.push(h('div', {class: 'rpt-notice'}, h('strong', {}, 'Scheduled delivery is not running yet. '),
				'Previews and downloads work without it. To send reports on a schedule, run this once as root on the Zabbix server:',
				h('pre', {class: 'rpt-pre'}, `sh ${C.module_dir}/contrib/install-runner.sh`)));
		}
		else if (stale) {
			notes.push(h('div', {class: 'rpt-notice rpt-notice-error'}, h('strong', {}, 'The runner has stopped. '),
				`Last seen ${ago(r.last_seen)}. Check `, h('code', {}, 'systemctl status zabbix-reporter.timer'), ' and ',
				h('code', {}, 'journalctl -u zabbix-reporter.service')));
		}

		const facts = [
			['Runner', r ? `Last ran ${ago(r.last_seen)} as ${r.user} on ${r.host} (PHP ${r.php}, module ${r.version})` : 'Not set up'],
			['Data directory', C.data_dir],
			['PDF export', C.checks.pdf ? 'Available' : 'Unavailable: the vendor/ directory is missing'],
			['XLSX export', C.checks.xlsx ? 'Available' : 'Unavailable: install the PHP zip extension (php-zip on Ubuntu, php-pecl-zip on RHEL)'],
			['Test buttons', C.checks.curl ? 'Available' : 'The PHP curl extension is missing in the web server (php-curl on Ubuntu)']
		];

		return fieldset('Status', notes,
			h('table', {class: 'rpt-facts'}, h('tbody', {}, facts.map(([k, v]) => h('tr', {}, h('th', {}, k), h('td', {}, v))))));
	};

	// ---------------------------------------------------------------- form

	const tokenField = () => {
		const st = C.secrets;
		const input = h('input', {type: 'password', autocomplete: 'new-password', spellcheck: 'false',
			placeholder: st.token_set ? 'Leave empty to keep the stored token' : '',
			oninput: (e) => token.value = e.target.value.trim()});
		const clear = st.token_set
			? h('span', {class: 'rpt-check'},
				h('input', {type: 'checkbox', id: 'rpt-clear-token',
					onchange: (e) => { token.clear = e.target.checked; input.disabled = e.target.checked; }}),
				h('label', {for: 'rpt-clear-token'}, 'Remove'))
			: null;

		return field('API token', h('div', {},
			h('div', {class: 'rpt-inline'}, input, clear),
			h('div', {class: 'rpt-hint'}, st.token_set
				? badge('ok', `Set by ${st.token_by}, ${ago(st.token_at)}`)
				: badge('warn', 'Not set'))
		), 'Never shown again once saved.');
	};

	const mediaField = () => {
		// An array, not an object: numeric keys would sort ahead of "None".
		const choices = [['', 'None: no email delivery'], ...C.media_types.map((m) =>
			[m.mediatypeid, m.name + (m.enabled ? '' : ' (disabled)') + (m.oauth ? ' (OAuth, not supported)' : '')])];

		const copy = C.secrets.smtp;
		const current = C.media_types.find((m) => m.mediatypeid === s.delivery.mediatypeid);
		let status = null;

		if (copy && current && copy.mediatypeid === current.mediatypeid) {
			status = copy.fingerprint === current.fingerprint
				? badge('ok', `Using ${copy.server}:${copy.port} as ${copy.email}, copied ${ago(copy.copied_at)}`)
				: badge('bad', 'This media type changed since it was copied. Save to pick up the change.');
		}
		else if (s.delivery.mediatypeid !== '') {
			status = badge('warn', 'Save to use this media type.');
		}

		return field('Send email using', h('div', {}, select(s.delivery, 'mediatypeid', choices),
			status ? h('div', {class: 'rpt-hint'}, status) : null),
			'Uses the server, port, security and credentials of a Zabbix Email media type. The runner uses a copy taken when you save here, so save again after changing the media type.');
	};

	const reportsPanel = () => fieldset('Scheduled reports',
		C.reports.length
			? h('table', {class: 'list-table rpt-table'},
				h('thead', {}, h('tr', {}, ['Report', 'Recipients', 'Last sent', 'Problem', ''].map((t) => h('th', {}, t)))),
				h('tbody', {}, C.reports.map((r) => {
					const out = h('div', {class: 'rpt-hint'});
					const send = h('button', {type: 'button', class: 'btn-alt',
						onclick: (e) => request({type: 'run_report', id: r.id}, e.target, out)}, 'Send now');

					return h('tr', {},
						h('td', {}, h('a', {href: 'zabbix.php?action=reporter.preview&id=' + encodeURIComponent(r.id)}, r.name)),
						h('td', {}, r.email_to.length ? r.email_to.join(', ') : h('span', {class: 'rpt-muted'}, 'Files only')),
						h('td', {}, r.last_run ? `${r.last_label}, ${ago(r.last_run)}` : 'Not yet'),
						h('td', {}, r.last_error ? h('span', {class: 'rpt-invalid'}, r.last_error) : ''),
						h('td', {}, send, out));
				})))
			: h('p', {class: 'rpt-hint'}, 'No report has a schedule yet. Turn one on in the report editor.'));

	const activityPanel = () => {
		const rows = [
			...C.pending.map((p) => h('tr', {}, h('td', {}, 'Send ' + p.params.id), h('td', {}, p.by), h('td', {}, ago(p.at)),
				h('td', {}, badge('warn', 'Waiting for the runner')), h('td', {}, ''))),
			...C.results.map((r) => h('tr', {}, h('td', {}, r.type === 'run_report' ? 'Send ' + r.params.id : r.type),
				h('td', {}, r.by), h('td', {}, ago(r.done_at)),
				h('td', {}, r.ok ? badge('ok', 'Done') : badge('bad', 'Failed')), h('td', {}, r.message)))
		];

		return rows.length
			? fieldset('Recent "Send now" requests', h('table', {class: 'list-table rpt-table'},
				h('thead', {}, h('tr', {}, ['Request', 'By', 'When', 'Status', 'Result'].map((t) => h('th', {}, t)))),
				h('tbody', {}, rows)), h('p', {}, h('a', {href: location.href, class: 'rpt-link'}, 'Refresh')))
			: null;
	};

	const render = () => {
		const urlInput = text(s.runner, 'api_url', {placeholder: C.suggested_url, spellcheck: 'false'});
		const apiOut = h('span', {class: 'rpt-hint'});
		const mailOut = h('span', {class: 'rpt-hint'});
		const mailTo = h('input', {type: 'email', placeholder: 'you@example.com'});

		root.replaceChildren(
			statusPanel(),

			fieldset('Zabbix connection for scheduled reports',
				h('p', {class: 'rpt-hint'}, 'Scheduled reports run as the owner of this API token and show exactly what that user can see. Use a dedicated user with the User role and read access to the customer\'s host groups. Create the token under Users > API tokens.'),
				field('Zabbix URL', h('div', {class: 'rpt-inline'}, urlInput,
					h('button', {type: 'button', class: 'btn-alt', onclick: () => {
						s.runner.api_url = C.suggested_url;
						urlInput.value = C.suggested_url;
					}}, 'Use this frontend')),
					'Where the runner reaches api_jsonrpc.php. On the Zabbix server itself, http://localhost/ plus the frontend path skips the load balancer.'),
				row(check(s.runner, 'verify_tls', 'Verify the TLS certificate')),
				tokenField(),
				row(h('button', {type: 'button', class: 'btn-alt', onclick: (e) => request({type: 'test_api',
					url: s.runner.api_url, verify_tls: s.runner.verify_tls ? '1' : '0', token: token.clear ? '' : token.value},
					e.target, apiOut)}, 'Test token'), ' ', apiOut,
					h('div', {class: 'rpt-hint'}, 'Tests what is on this page, before saving. An empty token field tests the saved token.'))
			),

			fieldset('Email',
				mediaField(),
				field('Test', h('div', {class: 'rpt-inline'}, mailTo,
					h('button', {type: 'button', class: 'btn-alt',
						onclick: (e) => request({type: 'test_mail', to: mailTo.value.trim(),
							mediatypeid: s.delivery.mediatypeid}, e.target, mailOut)}, 'Send test email'),
					mailOut), 'Uses the media type selected above, before saving.')
			),

			fieldset('Access',
				field('Who can view reports', select(s.access, 'min_user_type_view', C.user_types),
					'Viewers see only data their own permissions allow.'),
				field('Who can edit reports', select(s.access, 'min_user_type_edit',
					{2: C.user_types[2], 3: C.user_types[3]})),
				h('p', {class: 'rpt-hint'}, 'This settings page is always Super admin only.')
			),

			fieldset('Files',
				field('Keep delivered files for (days)', number(s.delivery, 'retention_days', {min: 0, max: 3650}),
					'0 keeps them forever. Files are kept under the data directory, in out/.')
			),

			fieldset('Safety limits', h('p', {class: 'rpt-hint'},
				'These stop a report before it can load the database or the web server. Each has a hard ceiling that cannot be raised from here.'),
				Object.entries(C.limits).map(([name, [min, max, label, hint]]) =>
					field(label, number(s.limits, name, {min, max}), [`${min} to ${max}.`, hint].filter(Boolean).join(' ')))),

			reportsPanel(),
			activityPanel(),

			h('div', {class: 'rpt-submit'}, h('button', {type: 'button', id: 'rpt-save', onclick: save}, 'Save settings'))
		);
	};

	const save = () => {
		const button = document.getElementById('rpt-save');
		const body = new URLSearchParams();
		body.append('settings', JSON.stringify(s));
		body.append('api_token', token.clear ? '' : token.value);
		body.append('clear_api_token', token.clear ? '1' : '0');
		body.append(C.csrf_name, C.csrf_save);

		button.disabled = true;
		errorBox.hidden = true;
		savedBox.hidden = true;

		fetch('zabbix.php?action=reporter.settings.save', {method: 'POST', body})
			.then((r) => r.json())
			.then((res) => {
				if (res.ok) {
					C.secrets = res.secrets;
					token.value = '';
					token.clear = false;
					render();
					savedBox.textContent = 'Settings saved.';
					savedBox.hidden = false;
					savedBox.scrollIntoView({behavior: 'smooth', block: 'start'});
				}
				else {
					showErrors(res.errors || ['Unknown error.']);
				}
			})
			.catch(() => showErrors(['The server did not answer. Nothing was saved.']))
			.finally(() => { const b = document.getElementById('rpt-save'); if (b) b.disabled = false; });
	};

	render();
};
</script>
