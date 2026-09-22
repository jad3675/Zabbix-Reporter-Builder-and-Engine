<?php declare(strict_types = 1);

/**
 * @var CView $this
 * @var array $data
 */

$tags_text = static function (array $filters): string {
	return \Modules\Reporter\Lib\Core\TagFilter::format($filters);
};

// Tag filters travel to the script as text in the one-per-line syntax.
$definition = $data['definition'];
$definition['scope']['host_tags'] = $tags_text($definition['scope']['host_tags']);
$definition['scope']['groups'] = implode("\n", $definition['scope']['groups']);
$definition['delivery']['email_to'] = implode(', ', $definition['delivery']['email_to']);

$schemas = $data['schemas'];

foreach ($schemas as &$schema) {
	foreach ($schema['options'] as $option) {
		if ($option['type'] === 'tags') {
			$schema['defaults'][$option['name']] = $tags_text($schema['defaults'][$option['name']]);
		}
	}
}
unset($schema);

foreach ($definition['sections'] as &$section) {
	foreach ($schemas as $schema) {
		if ($schema['type'] !== $section['type']) {
			continue;
		}

		foreach ($schema['options'] as $option) {
			if ($option['type'] === 'tags' && is_array($section['options'][$option['name']] ?? null)) {
				$section['options'][$option['name']] = $tags_text($section['options'][$option['name']]);
			}
		}
	}
}
unset($section);

?>
<script>
window.reporterEditorInit = () => {
	const CONFIG = <?= json_encode([
		'definition' => $definition,
		'original_id' => $data['original_id'],
		'schemas' => $schemas,
		'groups' => $data['group_names'],
		'timezones' => $data['timezones'],
		'period_types' => $data['period_types'],
		'csrf_name' => CSRF_TOKEN_NAME,
		'csrf' => $data['csrf_save']
	], JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE) ?>;

	const tagsToText = (v) => Array.isArray(v)
		? v.map((t) => ({exists: t.tag, not_exists: '!' + t.tag, equals: `${t.tag}=${t.value}`,
			contains: `${t.tag}~${t.value}`, not_equals: `${t.tag}!=${t.value}`})[t.operator] || t.tag).join('\n')
		: (v ?? '');

	// Accept stored-format JSON (arrays) as well as the editor's text format.
	const fromStored = (def) => {
		def.scope = def.scope || {};
		def.scope.groups = Array.isArray(def.scope.groups) ? def.scope.groups.join('\n') : (def.scope.groups ?? '');
		def.scope.host_tags = tagsToText(def.scope.host_tags);
		def.delivery = def.delivery || {formats: ['pdf']};
		def.delivery.email_to = Array.isArray(def.delivery.email_to)
			? def.delivery.email_to.join(', ') : (def.delivery.email_to ?? '');
		def.delivery.formats = def.delivery.formats || ['pdf'];
		def.period = def.period || {type: 'previous_month', n: 30};
		def.branding = def.branding || {};
		def.schedule = def.schedule || {enabled: false, cycle: 'monthly', day: 1, hour: 6};
		def.sections = Array.isArray(def.sections) ? def.sections : [];

		for (const section of def.sections) {
			const schema = CONFIG.schemas.find((s) => s.type === section.type);

			for (const spec of (schema ? schema.options : [])) {
				if (spec.type === 'tags' && section.options && Array.isArray(section.options[spec.name])) {
					section.options[spec.name] = tagsToText(section.options[spec.name]);
				}
			}
		}

		return def;
	};

	const state = fromStored(JSON.parse(JSON.stringify(CONFIG.definition)));
	const schemas = Object.fromEntries(CONFIG.schemas.map((s) => [s.type, s]));
	let idTouched = CONFIG.original_id !== '' || state.id !== '';

	const root = document.getElementById('rpt-editor');
	const errorBox = document.getElementById('rpt-errors');

	// Small DOM helper: h('div', {class: 'x', onclick: fn}, child, ...).
	const h = (tag, attrs = {}, ...children) => {
		const el = document.createElement(tag);

		for (const [k, v] of Object.entries(attrs || {})) {
			if (v === null || v === undefined || v === false) {
				continue;
			}

			if (k.startsWith('on')) {
				el.addEventListener(k.slice(2), v);
			}
			else if (k === 'class') {
				el.className = v;
			}
			else if (k === 'value') {
				el.value = v;
			}
			else if (k === 'checked') {
				el.checked = !!v;
			}
			else {
				el.setAttribute(k, v === true ? '' : v);
			}
		}

		for (const c of children.flat()) {
			if (c !== null && c !== undefined && c !== false) {
				el.append(c instanceof Node ? c : document.createTextNode(String(c)));
			}
		}

		return el;
	};

	let uid = 0;

	// The label points at the real form control even when the control is wrapped.
	const target = (control) => control.matches('input, select, textarea')
		? control : (control.querySelector('input, select, textarea') || control);

	const field = (label, control, hint = '') => {
		const el = target(control);
		el.id = el.id || ('rpt-f' + (++uid));

		return h('div', {class: 'rpt-field'},
			h('label', {for: el.id}, label),
			h('div', {class: 'rpt-control'}, control, hint ? h('div', {class: 'rpt-hint'}, hint) : null)
		);
	};

	const text = (value, onchange, attrs = {}) =>
		h('input', Object.assign({type: 'text', value: value ?? '', oninput: (e) => onchange(e.target.value)}, attrs));

	const area = (value, onchange, rows = 3, attrs = {}) =>
		h('textarea', Object.assign({rows, oninput: (e) => onchange(e.target.value)}, attrs), value ?? '');

	const number = (value, onchange, attrs = {}) =>
		h('input', Object.assign({type: 'number', value: value ?? '', class: 'rpt-num',
			oninput: (e) => onchange(e.target.value === '' ? '' : Number(e.target.value))}, attrs));

	const select = (value, choices, onchange, attrs = {}) =>
		h('select', Object.assign({onchange: (e) => onchange(e.target.value)}, attrs),
			Object.entries(choices).map(([v, l]) => h('option', {value: v, selected: String(v) === String(value)}, l)));

	const check = (value, label, onchange) => {
		const id = 'rpt-f' + (++uid);

		return h('span', {class: 'rpt-check'},
			h('input', {type: 'checkbox', id, checked: value, onchange: (e) => onchange(e.target.checked)}),
			h('label', {for: id}, label)
		);
	};

	const fieldset = (legend, ...children) => h('fieldset', {class: 'rpt-fieldset'}, h('legend', {}, legend), children);

	const slug = (s) => s.toLowerCase().normalize('NFKD').replace(/[^a-z0-9]+/g, '-').replace(/^-+|-+$/g, '').slice(0, 64);

	// ------------------------------------------------------------------ sections

	const optionControl = (spec, value, onchange) => {
		switch (spec.type) {
			case 'bool':
				return check(!!value, spec.label, onchange);

			case 'int':
			case 'float':
				return number(value, onchange, {min: spec.min, max: spec.max, step: spec.type === 'float' ? 'any' : 1});

			case 'select':
				return select(value, spec.choices, onchange);

			case 'tags':
				return area(value, onchange, 2, {class: 'rpt-mono', spellcheck: 'false'});

			default:
				return text(value, onchange, {maxlength: spec.maxlength || 255});
		}
	};

	const sectionCard = (section, index) => {
		const schema = schemas[section.type];

		if (!schema) {
			return h('div', {class: 'rpt-card rpt-card-missing'},
				h('div', {class: 'rpt-card-head'},
					h('strong', {}, `Unknown section "${section.type}"`),
					h('button', {type: 'button', class: 'btn-link', onclick: () => { state.sections.splice(index, 1); render(); }},
						'Remove')
				)
			);
		}

		const opts = Object.assign({}, schema.defaults, section.options || {});
		section.options = opts;

		const move = (delta) => {
			const to = index + delta;

			if (to < 0 || to >= state.sections.length) {
				return;
			}

			[state.sections[index], state.sections[to]] = [state.sections[to], state.sections[index]];
			render();
		};

		const body = h('div', {class: 'rpt-card-body'},
			field('Heading', text(section.title, (v) => section.title = v, {placeholder: schema.label, maxlength: 128})),
			schema.options.map((spec) => spec.type === 'bool'
				? h('div', {class: 'rpt-field'}, h('span'), h('div', {class: 'rpt-control'},
					optionControl(spec, opts[spec.name], (v) => opts[spec.name] = v),
					spec.hint ? h('div', {class: 'rpt-hint'}, spec.hint) : null))
				: field(spec.label, optionControl(spec, opts[spec.name], (v) => opts[spec.name] = v), spec.hint || ''))
		);

		return h('div', {class: 'rpt-card'},
			h('div', {class: 'rpt-card-head'},
				h('span', {class: 'rpt-card-index'}, String(index + 1)),
				h('div', {class: 'rpt-card-title'},
					h('strong', {}, schema.label),
					schema.description ? h('div', {class: 'rpt-hint'}, schema.description) : null
				),
				h('div', {class: 'rpt-card-tools'},
					h('button', {type: 'button', class: 'btn-link', disabled: index === 0, onclick: () => move(-1),
						'aria-label': 'Move up'}, 'Up'),
					h('button', {type: 'button', class: 'btn-link', disabled: index === state.sections.length - 1,
						onclick: () => move(1), 'aria-label': 'Move down'}, 'Down'),
					h('button', {type: 'button', class: 'btn-link', onclick: () => { state.sections.splice(index, 1); render(); }},
						'Remove')
				)
			),
			body
		);
	};

	// ------------------------------------------------------------------ form

	const render = () => {
		const d = state;
		const addType = select(CONFIG.schemas[0].type,
			Object.fromEntries(CONFIG.schemas.map((s) => [s.type, s.label])), () => {});

		const groupPicker = h('input', {type: 'text', list: 'rpt-group-list', placeholder: 'Find a host group'});
		const groupsArea = area(d.scope.groups, (v) => d.scope.groups = v, 4, {spellcheck: 'false'});
		const idInput = text(d.id, (v) => { d.id = v; idTouched = true; },
			{maxlength: 64, pattern: '[a-z0-9][a-z0-9-]*', spellcheck: 'false'});

		const nameInput = text(d.name, (v) => {
			d.name = v;

			if (!idTouched) {
				d.id = slug(v);
				idInput.value = d.id;
			}
		}, {maxlength: 128});

		const nField = number(d.period.n, (v) => d.period.n = v, {min: 1, max: 400});
		const nRow = field('Days', nField);
		nRow.hidden = d.period.type !== 'last_n_days';

		const formats = ['pdf', 'xlsx', 'csv'].map((f) => check(d.delivery.formats.includes(f), f.toUpperCase(), (on) => {
			d.delivery.formats = on
				? [...new Set([...d.delivery.formats, f])]
				: d.delivery.formats.filter((x) => x !== f);
		}));

		const dayChoices = d.schedule.cycle === 'weekly'
			? {1: 'Monday', 2: 'Tuesday', 3: 'Wednesday', 4: 'Thursday', 5: 'Friday', 6: 'Saturday', 7: 'Sunday'}
			: Object.fromEntries(Array.from({length: 28}, (_, i) => [i + 1, String(i + 1)]));

		const json = h('textarea', {rows: 18, class: 'rpt-mono', spellcheck: 'false'});
		const details = h('details', {class: 'rpt-json', ontoggle: () => {
			if (details.open) {
				json.value = JSON.stringify(state, null, 2);
			}
		}},
			h('summary', {}, 'Definition as JSON'),
			h('p', {class: 'rpt-hint'}, 'Copy a report between instances, or paste one in. Tag filters are written one per line: tag, tag=value, tag~contains, tag!=value, !tag.'),
			json,
			h('div', {}, h('button', {type: 'button', class: 'btn-alt', onclick: () => {
				try {
					const parsed = JSON.parse(json.value);
					Object.keys(state).forEach((k) => delete state[k]);
					Object.assign(state, fromStored(parsed));
					idTouched = true;
					render();
				}
				catch (e) {
					showErrors(['The JSON could not be parsed: ' + e.message]);
				}
			}}, 'Apply JSON'))
		);

		root.replaceChildren(
			h('datalist', {id: 'rpt-group-list'}, CONFIG.groups.map((g) => h('option', {value: g}))),
			h('datalist', {id: 'rpt-tz-list'}, CONFIG.timezones.map((t) => h('option', {value: t}))),

			fieldset('Report',
				field('Name', nameInput),
				field('ID', idInput, 'Used in file names and by the CLI runner. Lowercase letters, digits and hyphens.'),
				field('Description', area(d.description, (v) => d.description = v, 2, {maxlength: 1000}))
			),

			fieldset('Devices',
				field('Host groups', h('div', {},
					groupsArea,
					h('div', {class: 'rpt-inline'}, groupPicker,
						h('button', {type: 'button', class: 'btn-alt', onclick: () => {
							const v = groupPicker.value.trim();

							if (v) {
								d.scope.groups = (d.scope.groups ? d.scope.groups.replace(/\s+$/, '') + '\n' : '') + v;
								groupsArea.value = d.scope.groups;
								groupPicker.value = '';
							}
						}}, 'Add')
					)
				), 'One per line. Wildcards work: CCH/* matches every group under CCH.'),
				field('Host tags', area(d.scope.host_tags, (v) => d.scope.host_tags = v, 3,
					{class: 'rpt-mono', spellcheck: 'false'}),
					'Optional. One per line: site, site=Burnet, site~burn, site!=Lab, !decommissioned.'),
				field('Tag logic', select(d.scope.tag_logic, {and: 'All tags must match', or: 'Any tag matches'},
					(v) => d.scope.tag_logic = v))
			),

			fieldset('Period',
				field('Covers', select(d.period.type, CONFIG.period_types, (v) => {
					d.period.type = v;
					nRow.hidden = v !== 'last_n_days';
				})),
				nRow,
				field('Time zone', text(d.timezone, (v) => d.timezone = v, {list: 'rpt-tz-list', spellcheck: 'false'}),
					'Days, months and times in the report use this zone.')
			),

			fieldset('Cover and branding',
				field('Title', text(d.branding.title, (v) => d.branding.title = v, {maxlength: 128})),
				field('Customer', text(d.branding.customer, (v) => d.branding.customer = v, {maxlength: 128})),
				field('Accent colour', h('input', {type: 'color', value: d.branding.accent,
					oninput: (e) => d.branding.accent = e.target.value})),
				field('Logo file', text(d.branding.logo, (v) => d.branding.logo = v, {spellcheck: 'false'}),
					'File name of a PNG, JPEG or SVG placed in the data directory\'s assets folder.'),
				field('Paper', select(d.branding.paper, {Letter: 'Letter', A4: 'A4'}, (v) => d.branding.paper = v)),
				field('Footer text', text(d.branding.footer, (v) => d.branding.footer = v, {maxlength: 200}),
					'Defaults to the report title and period.')
			),

			fieldset('Sections',
				h('div', {class: 'rpt-cards'}, state.sections.map(sectionCard)),
				h('div', {class: 'rpt-inline'}, addType,
					h('button', {type: 'button', class: 'btn-alt', onclick: () => {
						const schema = schemas[addType.value];
						state.sections.push({type: schema.type, title: '', options: JSON.parse(JSON.stringify(schema.defaults))});
						render();
					}}, 'Add section'))
			),

			fieldset('Schedule and delivery',
				h('p', {class: 'rpt-hint'}, 'Scheduled reports are produced by the CLI runner (bin/reporter.php run-due) from a systemd timer, not by the web server.'),
				h('div', {class: 'rpt-field'}, h('span'), h('div', {class: 'rpt-control'},
					check(d.schedule.enabled, 'Produce this report on a schedule', (v) => d.schedule.enabled = v))),
				field('Every', select(d.schedule.cycle, {monthly: 'Month', weekly: 'Week', daily: 'Day'}, (v) => {
					d.schedule.cycle = v;
					d.schedule.day = 1;
					render();
				})),
				d.schedule.cycle !== 'daily'
					? field(d.schedule.cycle === 'weekly' ? 'On' : 'On day', select(d.schedule.day, dayChoices,
						(v) => d.schedule.day = Number(v)))
					: null,
				field('At hour', select(d.schedule.hour,
					Object.fromEntries(Array.from({length: 24}, (_, i) => [i, String(i).padStart(2, '0') + ':00'])),
					(v) => d.schedule.hour = Number(v))),
				field('Formats', h('div', {class: 'rpt-inline'}, formats)),
				field('Email to', area(d.delivery.email_to, (v) => d.delivery.email_to = v, 2, {spellcheck: 'false'}),
					'Comma-separated. Leave empty to only write files to the output directory.'),
				field('Subject', text(d.delivery.email_subject, (v) => d.delivery.email_subject = v,
					{placeholder: 'Defaults to the report title and period', maxlength: 200})),
				field('Message', area(d.delivery.email_body, (v) => d.delivery.email_body = v, 3, {maxlength: 4000}))
			),

			details,

			h('div', {class: 'rpt-submit'},
				h('button', {type: 'button', id: 'rpt-save', onclick: save}, 'Save and preview'),
				h('a', {href: 'zabbix.php?action=reporter.list', class: 'rpt-link'}, 'Cancel')
			)
		);
	};

	const showErrors = (errors) => {
		errorBox.replaceChildren(h('strong', {}, 'The report was not saved.'), h('ul', {}, errors.map((e) => h('li', {}, e))));
		errorBox.hidden = false;
		errorBox.scrollIntoView({behavior: 'smooth', block: 'start'});
	};

	const save = () => {
		const button = document.getElementById('rpt-save');
		const body = new URLSearchParams();
		body.append('definition', JSON.stringify(state));
		body.append('original_id', CONFIG.original_id);
		body.append(CONFIG.csrf_name, CONFIG.csrf);

		button.disabled = true;
		errorBox.hidden = true;

		fetch('zabbix.php?action=reporter.save', {method: 'POST', body})
			.then((r) => r.json())
			.then((res) => {
				if (res.ok) {
					location.href = res.redirect;
				}
				else {
					showErrors(res.errors || ['Unknown error.']);
				}
			})
			.catch(() => showErrors(['The server did not answer. Your changes are still on this page.']))
			.finally(() => button.disabled = false);
	};

	render();
};
</script>
