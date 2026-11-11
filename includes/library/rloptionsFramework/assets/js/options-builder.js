/**
 * RL Builder — visual schema builder for the RL Options Framework.
 *
 * Model:
 *   { tabs: [ { id, label, priority, conditions:{relation,rules}, sections: [
 *       { id, title, priority, accordion, conditions, fields: [
 *           { id, type, label, description, default, required, width, class, multiple,
 *             text, placeholder, unit, media_type, language, min, max, step, rows,
 *             max_rows, minlength, options: [[value,label]], conditions, required_if }
 *       ] } ] } ] }
 *
 * @package RL_FSBI_Options_Framework
 */
(function (window, document) {
	'use strict';

	var cfg = window.rlBuilder || {};
	var root = document.getElementById('rl-builder');

	if (!root) {
		return;
	}

	var OPTION_TYPES = ['select', 'multiselect', 'radio', 'button_set', 'image_select', 'color_palette', 'sortable', 'sorter'];
	var BOOL_TYPES = ['checkbox', 'toggle', 'switch'];
	var NUMERIC_TYPES = ['number', 'slider', 'spinner'];

	var els = {
		palette: root.querySelector('[data-builder-palette]'),
		canvas: root.querySelector('[data-builder-canvas]'),
		empty: root.querySelector('[data-builder-empty]'),
		inspector: root.querySelector('[data-builder-inspector]'),
		exportPanel: root.querySelector('[data-builder-export]'),
		code: root.querySelector('[data-builder-code]'),
		format: root.querySelector('[data-builder-format]'),
		status: root.querySelector('[data-builder-status]'),
		search: root.querySelector('[data-builder-search]')
	};

	var state = {
		tabs: [],
		selection: null,
		history: [],
		historyIndex: -1,
		savedSnapshot: '',
		dirty: false
	};

	var collapsedTabs = {};
	var collapsedSections = {};
	var wysiwyg = false;
	var previewCache = {};
	var previewTimers = {};
	var previewQueue = [];
	var previewActive = 0;
	var PREVIEW_CONCURRENCY = 6;

	var uidCounter = 0;

	/* ------------------------------------------------------------------ utils */

	function uid(prefix) {
		uidCounter += 1;
		return prefix + '_' + Date.now().toString(36).slice(-4) + uidCounter;
	}

	function esc(value) {
		return String(value === undefined || value === null ? '' : value)
			.replace(/&/g, '&amp;')
			.replace(/</g, '&lt;')
			.replace(/>/g, '&gt;')
			.replace(/"/g, '&quot;');
	}

	function el(tag, attrs, children) {
		var node = document.createElement(tag);

		Object.keys(attrs || {}).forEach(function (key) {
			if (key === 'class') {
				node.className = attrs[key];
			} else if (key === 'text') {
				node.textContent = attrs[key];
			} else if (key === 'html') {
				node.innerHTML = attrs[key];
			} else if (key === 'list' || key.indexOf('data-') === 0 || key.indexOf('aria-') === 0) {
				node.setAttribute(key, attrs[key]);
			} else if (key in node) {
				node[key] = attrs[key];
			} else {
				node.setAttribute(key, attrs[key]);
			}
		});

		(children || []).forEach(function (child) {
			if (child) {
				node.appendChild(child);
			}
		});

		return node;
	}

	function setStatus(message, kind) {
		if (!els.status) {
			return;
		}
		els.status.textContent = message || '';
		els.status.className = 'rl-builder-status' + (kind ? ' is-' + kind : '');
	}

	function slugify(value) {
		return String(value || '')
			.toLowerCase()
			.replace(/[^a-z0-9_\-]+/g, '_')
			.replace(/^_+|_+$/g, '');
	}

	/* ------------------------------------------------------------------ model */

	function makeConditions() {
		return { relation: 'AND', rules: [] };
	}

	function makeField(type) {
		var label = (cfg.catalog && cfg.catalog.types && cfg.catalog.types[type] ? cfg.catalog.types[type].label : type) || type;

		return {
			id: slugify(type + '_' + uid('f').slice(-3)),
			type: type,
			label: label.replace(/_/g, ' ').replace(/\b\w/g, function (c) { return c.toUpperCase(); }),
			description: '',
			default: '',
			required: false,
			width: '',
			class: '',
			multiple: false,
			text: '',
			placeholder: '',
			unit: '',
			media_type: 'image',
			language: 'text/html',
			options: OPTION_TYPES.indexOf(type) !== -1 ? [['option_one', 'Option one'], ['option_two', 'Option two']] : [],
			conditions: makeConditions(),
			required_if: []
		};
	}

	function makeSection() {
		uidCounter += 1;
		return { id: 'section_' + uidCounter, title: 'New section', priority: 10, accordion: false, conditions: makeConditions(), fields: [] };
	}

	function makeTab() {
		uidCounter += 1;
		return { id: 'tab_' + uidCounter, label: 'New tab', priority: 10, conditions: makeConditions(), sections: [] };
	}

	/**
	 * Ensure an id is unique inside a collection by appending _2, _3, …
	 *
	 * @param {string} base   Desired id.
	 * @param {Array}  values Existing ids.
	 */
	function uniqueId(base, values) {
		if (values.indexOf(base) === -1) {
			return base;
		}

		var index = 2;
		var candidate = base + '_' + index;

		while (values.indexOf(candidate) !== -1) {
			index += 1;
			candidate = base + '_' + index;
		}

		return candidate;
	}

	function findTab(id) {
		return state.tabs.filter(function (tab) { return tab.id === id; })[0] || null;
	}

	function findSection(tabId, sectionId) {
		var tab = findTab(tabId);
		if (!tab) {
			return null;
		}
		return tab.sections.filter(function (section) { return section.id === sectionId; })[0] || null;
	}

	function findField(tabId, sectionId, fieldId) {
		var section = findSection(tabId, sectionId);
		if (!section) {
			return null;
		}
		return section.fields.filter(function (field) { return field.id === fieldId; })[0] || null;
	}

	function activeRules(conditions) {
		if (!conditions || !Array.isArray(conditions.rules)) {
			return [];
		}
		return conditions.rules.filter(function (rule) { return rule.field; });
	}

	function allFieldIds() {
		var ids = [];
		state.tabs.forEach(function (tab) {
			tab.sections.forEach(function (section) {
				section.fields.forEach(function (field) {
					if (field.id) {
						ids.push(field.id);
					}
				});
			});
		});
		return ids;
	}

	function selectedNode() {
		if (!state.selection) {
			return null;
		}

		var sel = state.selection;

		if (sel.kind === 'tab') {
			return findTab(sel.tabId);
		}

		if (sel.kind === 'section') {
			return findSection(sel.tabId, sel.sectionId);
		}

		return findField(sel.tabId, sel.sectionId, sel.fieldId);
	}

	/* ---------------------------------------------------------------- palette */

	function renderPalette() {
		if (!els.palette || !cfg.catalog) {
			return;
		}

		els.palette.innerHTML = '';
		var counter = 0;

		(cfg.catalog.groups || []).forEach(function (group) {
			var chips = (group.types || []).map(function (type) {
				counter += 1;
				return el('button', {
					type: 'button',
					class: 'rl-builder-chip',
					draggable: 'true',
					'data-drag': JSON.stringify({ kind: 'new', type: type }),
					title: type,
					text: (cfg.catalog.types[type] && cfg.catalog.types[type].label) || type
				});
			});

			els.palette.appendChild(
				el('div', { class: 'rl-builder-group' }, [
					el('h3', { text: group.label + ' (' + chips.length + ')' }),
					el('div', { class: 'rl-builder-chips' }, chips)
				])
			);
		});

		if (!counter) {
			els.palette.innerHTML = '<p class="rl-builder-hint">No field catalog available.</p>';
		}
	}

	/* ----------------------------------------------------------------- canvas */

	function conditionSummary(conditions) {
		var rules = activeRules(conditions);
		if (!rules.length) {
			return '';
		}
		return 'if ' + rules.length + ' rule' + (rules.length > 1 ? 's' : '') + ' (' + (conditions.relation || 'AND') + ')';
	}

	function applyWysiwyg() {
		var shell = els.canvas.closest('.rl-builder-canvas');

		els.canvas.classList.toggle('is-wysiwyg', wysiwyg);
		root.classList.toggle('is-wysiwyg', wysiwyg);

		if (shell) {
			shell.classList.toggle('is-wysiwyg', wysiwyg);
		}
	}

	function renderCanvas() {
		els.canvas.innerHTML = '';
		els.canvas.setAttribute('data-drop', 'tabs');
		applyWysiwyg();
		els.empty.hidden = state.tabs.length > 0;

		state.tabs.forEach(function (tab) {
			var fieldsCount = tab.sections.reduce(function (sum, section) { return sum + section.fields.length; }, 0);
			var isSelected = state.selection && state.selection.kind === 'tab' && state.selection.tabId === tab.id;
			var isCollapsed = !!collapsedTabs[tab.id];

			var tabNode = el('section', {
				class: 'rl-builder-tab' + (isSelected ? ' is-selected' : '') + (isCollapsed ? ' is-collapsed' : ''),
				draggable: 'true',
				'data-drag': JSON.stringify({ kind: 'tab', tabId: tab.id }),
				'data-tab': tab.id
			}, [
				el('header', { class: 'rl-builder-tab-header' }, [
					el('button', {
						type: 'button',
						class: 'rl-builder-caret dashicons dashicons-arrow-down-alt2',
						'data-builder-action': 'toggle-tab',
						'data-tab': tab.id,
						'aria-expanded': isCollapsed ? 'false' : 'true',
						title: 'Collapse / expand tab'
					}),
					el('div', { class: 'rl-builder-tab-title' }, [
						el('strong', { text: tab.label || tab.id }),
						el('code', { text: tab.id }),
						el('span', { class: 'rl-builder-count', text: tab.sections.length + ' sections / ' + fieldsCount + ' fields' }),
						conditionSummary(tab.conditions) ? el('span', { class: 'rl-builder-badge', text: conditionSummary(tab.conditions) }) : null
					]),
					el('div', { class: 'rl-builder-tab-actions' }, [
						el('button', { type: 'button', class: 'button button-small', 'data-builder-action': 'add-section', 'data-tab': tab.id, text: '+ Section' }),
						el('button', { type: 'button', class: 'button-link-delete', 'data-builder-action': 'delete-tab', 'data-tab': tab.id, text: 'Delete' })
					])
				]),
				el('div', { class: 'rl-builder-sections', 'data-drop': 'sections', 'data-tab': tab.id }, tab.sections.map(function (section) {
					return renderSection(tab, section);
				}))
			]);

			els.canvas.appendChild(tabNode);
		});

		scheduleAllPreviews();
	}

	function renderSection(tab, section) {
		var isSelected = state.selection && state.selection.kind === 'section' && state.selection.tabId === tab.id && state.selection.sectionId === section.id;
		var collapseKey = tab.id + ':' + section.id;
		var isCollapsed = !!collapsedSections[collapseKey];

		return el('article', {
			class: 'rl-builder-section' + (isSelected ? ' is-selected' : '') + (isCollapsed ? ' is-collapsed' : ''),
			draggable: 'true',
			'data-drag': JSON.stringify({ kind: 'section', tabId: tab.id, sectionId: section.id }),
			'data-section': section.id,
			'data-tab': tab.id
		}, [
			el('header', { class: 'rl-builder-section-header' }, [
				el('button', {
					type: 'button',
					class: 'rl-builder-caret dashicons dashicons-arrow-down-alt2',
					'data-builder-action': 'toggle-section',
					'data-tab': tab.id,
					'data-section': section.id,
					'aria-expanded': isCollapsed ? 'false' : 'true',
					title: 'Collapse / expand section'
				}),
				el('span', { class: 'rl-builder-drag dashicons dashicons-menu-alt2', 'aria-hidden': 'true' }),
				el('strong', { text: section.title || section.id }),
				el('code', { text: section.id }),
				section.accordion ? el('span', { class: 'rl-builder-badge', text: 'accordion' }) : null,
				conditionSummary(section.conditions) ? el('span', { class: 'rl-builder-badge', text: conditionSummary(section.conditions) }) : null
			]),
			el('div', { class: 'rl-builder-fields', 'data-drop': 'fields', 'data-tab': tab.id, 'data-section': section.id }, section.fields.map(function (field) {
				return renderField(tab, section, field);
			}).concat([
				el('button', {
					type: 'button',
					class: 'rl-builder-add-field',
					'data-builder-action': 'quick-add',
					'data-tab': tab.id,
					'data-section': section.id,
					text: '+ Add field'
				})
			]))
		]);
	}

	function renderField(tab, section, field) {
		var isSelected = state.selection && state.selection.kind === 'field' && state.selection.tabId === tab.id && state.selection.sectionId === section.id && state.selection.fieldId === field.id;
		var hidden = conditionsUnmet(field.conditions);

		return el('div', {
			class: 'rl-builder-field' + (isSelected ? ' is-selected' : '') + (hidden ? ' is-conditional' : ''),
			draggable: 'true',
			'data-drag': JSON.stringify({ kind: 'field', tabId: tab.id, sectionId: section.id, fieldId: field.id }),
			'data-field': field.id,
			'data-tab': tab.id,
			'data-section': section.id,
			title: hidden ? 'Hidden on the real page until its conditions match' : ''
		}, [
			el('div', { class: 'rl-builder-field-toolbar' }, [
				el('span', { class: 'rl-builder-drag dashicons dashicons-menu-alt2', 'aria-hidden': 'true' }),
				el('span', { class: 'rl-builder-field-type', text: field.type }),
				el('span', { class: 'rl-builder-field-actions' }, [
					el('button', {
						type: 'button',
						class: 'rl-builder-tool dashicons dashicons-admin-page',
						'data-builder-action': 'duplicate-field',
						'data-tab': tab.id,
						'data-section': section.id,
						'data-field': field.id,
						title: 'Duplicate'
					}),
					el('button', {
						type: 'button',
						class: 'rl-builder-tool dashicons dashicons-trash',
						'data-builder-action': 'delete-field',
						'data-tab': tab.id,
						'data-section': section.id,
						'data-field': field.id,
						title: 'Delete'
					})
				])
			]),
			el('div', {
				class: 'rl-builder-field-preview',
				'data-preview-for': field.id
			}, [el('span', { class: 'rl-builder-field-loading', text: 'Rendering…' })]),
			el('div', { class: 'rl-builder-field-meta' }, [
				el('code', { class: 'rl-builder-field-id', text: field.id }),
				activeRules(field.conditions).length ? el('span', { class: 'rl-builder-badge', text: conditionSummary(field.conditions) }) : null,
				Array.isArray(field.required_if) && field.required_if.length ? el('span', { class: 'rl-builder-badge is-required', text: 'required_if' }) : null,
				field.required ? el('span', { class: 'rl-builder-badge is-required', text: 'required' }) : null,
				field.width ? el('span', { class: 'rl-builder-badge', text: field.width }) : null
			])
		]);
	}

	function findFieldNode(fieldId) {
		return els.canvas.querySelector('.rl-builder-field[data-field="' + CSS.escape(fieldId) + '"]');
	}

	/* --------------------------------------------------------- live preview */

	function previewKey(field) {
		return JSON.stringify({
			t: field.type,
			l: field.label,
			d: field.description,
			v: field.default,
			o: field.options,
			r: field.required,
			m: field.multiple,
			w: field.width,
			p: field.placeholder,
			u: field.unit,
			tx: field.text,
			mt: field.media_type,
			lg: field.language,
			mi: field.min,
			ma: field.max,
			st: field.step,
			ro: field.rows,
			mr: field.max_rows,
			ml: field.minlength
		});
	}

	function scheduleAllPreviews() {
		var pending = [];

		state.tabs.forEach(function (tab) {
			tab.sections.forEach(function (section) {
				section.fields.forEach(function (field) {
					if (fillPreview(field)) {
						return;
					}

					if (els.canvas.querySelector('[data-preview-for="' + CSS.escape(field.id) + '"]')) {
						pending.push(field);
					}
				});
			});
		});

		if (pending.length) {
			requestPreviewBatch(pending);
		}
	}

	/**
	 * Hydrate a whole canvas in a few requests instead of one per field.
	 */
	function requestPreviewBatch(fields) {
		var size = 40;
		var index;

		for (index = 0; index < fields.length; index += size) {
			requestPreviewChunk(fields.slice(index, index + size));
		}
	}

	function requestPreviewChunk(fields) {
		var body = new window.URLSearchParams();

		body.set('action', cfg.fieldAction);
		body.set('nonce', cfg.nonce);
		body.set('fields', JSON.stringify(fields));

		window
			.fetch(cfg.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: body })
			.then(function (response) { return response.json(); })
			.then(function (json) {
				if (!json || !json.success || !json.data || !json.data.fields) {
					return;
				}

				var map = json.data.fields;

				fields.forEach(function (field) {
					var html = map[field.id];

					if (typeof html !== 'string') {
						return;
					}

					previewCache[previewKey(field)] = html;

					var host = els.canvas.querySelector('[data-preview-for="' + CSS.escape(field.id) + '"]');

					if (host) {
						host.innerHTML = html;
						bindPreviewControls(host, field);
					}
				});
			})
			.catch(function () {
				// The per-field debounced path retries on the next render.
			});
	}

	function schedulePreview(field) {
		if (!els.canvas.querySelector('[data-preview-for="' + CSS.escape(field.id) + '"]')) {
			return;
		}

		if (previewCache[previewKey(field)]) {
			fillPreview(field);
			return;
		}

		window.clearTimeout(previewTimers[field.id]);
		previewTimers[field.id] = window.setTimeout(function () {
			queuePreview(field);
		}, 140);
	}

	function fillPreview(field) {
		var host = els.canvas.querySelector('[data-preview-for="' + CSS.escape(field.id) + '"]');

		if (!host) {
			return false;
		}

		var html = previewCache[previewKey(field)];

		if (!html) {
			return false;
		}

		host.innerHTML = html;
		bindPreviewControls(host, field);

		return true;
	}

	/**
	 * Previews are fetched through a small queue so a 100-field schema does not
	 * open 100 simultaneous requests.
	 */
	function queuePreview(field) {
		var queued = previewQueue.some(function (item) { return item.id === field.id; });

		if (!queued) {
			previewQueue.push(field);
		}

		pumpPreviews();
	}

	function pumpPreviews() {
		while (previewActive < PREVIEW_CONCURRENCY && previewQueue.length) {
			var field = previewQueue.shift();

			if (fillPreview(field)) {
				continue;
			}

			var host = els.canvas.querySelector('[data-preview-for="' + CSS.escape(field.id) + '"]');

			if (!host) {
				continue;
			}

			previewActive += 1;
			renderPreview(field, host, previewKey(field));
		}
	}

	function renderPreview(field, host, key) {
		var body = new window.URLSearchParams();
		body.set('action', cfg.fieldAction);
		body.set('nonce', cfg.nonce);
		body.set('field', JSON.stringify(field));

		window
			.fetch(cfg.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: body })
			.then(function (response) { return response.json(); })
			.then(function (json) {
				if (!json || !json.success || !json.data || !json.data.html) {
					host.innerHTML = '<span class="rl-builder-field-loading">Preview unavailable</span>';
					return;
				}

				previewCache[key] = json.data.html;

				if (document.contains(host)) {
					host.innerHTML = json.data.html;
					bindPreviewControls(host, field);
				}
			})
			.catch(function () {
				host.innerHTML = '<span class="rl-builder-field-loading">Preview failed</span>';
			})
			.then(function () {
				previewActive = Math.max(0, previewActive - 1);
				pumpPreviews();
			});
	}

	/**
	 * Keep the preview truthful: controls show the configured default and are
	 * read-only-ish (interacting with them does not touch stored options).
	 */
	function bindPreviewControls(host, field) {
		var control = host.querySelector('.rl-field-control');
		var rendered = control && (
			control.querySelector('input, select, textarea, button, img, canvas, video, iframe, table') ||
			'' !== control.textContent.trim()
		);

		if (!rendered) {
			host.innerHTML = '<span class="rl-builder-field-loading" title="This field prints HTML from a PHP callback, so it has no static preview.">Rendered by PHP</span>';
			return;
		}

		Array.prototype.forEach.call(host.querySelectorAll('input, select, textarea, button'), function (node) {
			node.setAttribute('tabindex', '-1');
		});
	}

	/* ----------------------------------------------------- condition checking */

	function fieldDefaultMap() {
		var map = {};

		state.tabs.forEach(function (tab) {
			tab.sections.forEach(function (section) {
				section.fields.forEach(function (field) {
					map[field.id] = field.default;
				});
			});
		});

		return map;
	}

	function ruleMatches(rule, values) {
		var actual = values[rule.field];
		var expected = rule.value;
		var operator = rule.operator || 'equals';

		if (typeof actual === 'boolean') {
			actual = actual ? '1' : '';
		}

		if (actual === undefined || actual === null) {
			actual = '';
		}

		actual = String(actual);
		expected = expected === undefined || expected === null ? '' : String(expected);

		switch (operator) {
			case 'not_equals':
				return actual !== expected;
			case 'in':
				return expected.split(',').map(function (item) { return item.trim(); }).indexOf(actual) !== -1;
			case 'not_in':
				return expected.split(',').map(function (item) { return item.trim(); }).indexOf(actual) === -1;
			case 'empty':
				return actual === '';
			case 'not_empty':
				return actual !== '';
			case 'truthy':
				return actual !== '' && actual !== '0' && actual !== 'false';
			case 'falsy':
				return actual === '' || actual === '0' || actual === 'false';
			case 'contains':
				return actual.indexOf(expected) !== -1;
			case 'match':
				try {
					return new RegExp(expected).test(actual);
				} catch (error) {
					return false;
				}
			case '>':
				return parseFloat(actual) > parseFloat(expected);
			case '>=':
				return parseFloat(actual) >= parseFloat(expected);
			case '<':
				return parseFloat(actual) < parseFloat(expected);
			case '<=':
				return parseFloat(actual) <= parseFloat(expected);
			default:
				return actual === expected;
		}
	}

	function conditionsUnmet(conditions) {
		var rules = activeRules(conditions);

		if (!rules.length) {
			return false;
		}

		var values = fieldDefaultMap();
		var relation = (conditions.relation || 'AND').toUpperCase();

		var results = rules.map(function (rule) {
			return ruleMatches(rule, values);
		});

		return relation === 'OR' ? results.every(function (ok) { return !ok; }) : results.some(function (ok) { return !ok; });
	}

	/* -------------------------------------------------------------- inspector */

	function inputRow(labelText, control, hint) {
		return el('div', { class: 'rl-builder-row' }, [
			el('label', { class: 'rl-builder-row-label', text: labelText }),
			el('div', { class: 'rl-builder-row-control' }, [control, hint ? el('p', { class: 'description', text: hint }) : null])
		]);
	}

	function textInput(path, value, type) {
		return el('input', { type: type || 'text', class: 'regular-text', 'data-prop': path, value: value === undefined || value === null ? '' : value });
	}

	function selectInput(path, value, choices) {
		var select = el('select', { 'data-prop': path }, Object.keys(choices).map(function (key) {
			var option = el('option', { value: key, text: choices[key] });
			if (String(value) === String(key)) {
				option.selected = true;
			}
			return option;
		}));
		return select;
	}

	function checkboxInput(path, checked, labelText) {
		var input = el('input', { type: 'checkbox', 'data-prop': path });
		input.checked = !!checked;
		return el('label', { class: 'rl-builder-check' }, [input, el('span', { text: labelText })]);
	}

	function rulesEditor(target, node, prop, emptyLabel) {
		var conditions = prop === 'conditions' ? node.conditions : { relation: 'AND', rules: node.required_if || [] };

		if (!conditions || typeof conditions !== 'object') {
			conditions = { relation: 'AND', rules: [] };
		}
		if (prop !== 'conditions') {
			conditions = { relation: 'AND', rules: conditions.rules || [] };
		}

		var wrap = el('div', { class: 'rl-builder-rules', 'data-rules-for': prop });
		var relationWrap = null;

		if (prop === 'conditions') {
			relationWrap = el('div', { class: 'rl-builder-relation' }, [
				el('span', { text: 'Match' }),
				selectInput('__relation', conditions.relation || 'AND', { AND: 'all rules (AND)', OR: 'any rule (OR)' })
			]);
			wrap.appendChild(relationWrap);
		}

		var list = el('div', { class: 'rl-builder-rule-list' });
		wrap.appendChild(list);

		function renderRules() {
			list.innerHTML = '';

			if (!conditions.rules.length) {
				list.appendChild(el('p', { class: 'rl-builder-hint', text: emptyLabel }));
			}

			conditions.rules.forEach(function (rule, index) {
				var fieldInput = el('input', {
					type: 'text',
					class: 'regular-text',
					list: 'rl-builder-field-ids',
					placeholder: 'field_id',
					value: rule.field || ''
				});

				fieldInput.addEventListener('input', function () {
					conditions.rules[index].field = slugify(this.value);
					commitRules();
				});

				var operator = el('select', {}, Object.keys(cfg.operators || {}).map(function (key) {
					var option = el('option', { value: key, text: cfg.operators[key] });
					if ((rule.operator || 'equals') === key) {
						option.selected = true;
					}
					return option;
				}));

				operator.addEventListener('change', function () {
					conditions.rules[index].operator = this.value;
					commitRules();
				});

				var value = el('input', { type: 'text', class: 'regular-text', placeholder: 'value', value: rule.value === undefined ? '' : rule.value });
				value.addEventListener('input', function () {
					conditions.rules[index].value = this.value;
					commitRules();
				});

				var remove = el('button', { type: 'button', class: 'button-link-delete', text: 'Remove' });
				remove.addEventListener('click', function () {
					conditions.rules.splice(index, 1);
					renderRules();
					commitRules();
				});

				list.appendChild(el('div', { class: 'rl-builder-rule' }, [fieldInput, operator, value, remove]));
			});
		}

		function commitRules() {
			if (prop === 'conditions') {
				node.conditions = { relation: conditions.relation || 'AND', rules: conditions.rules };
			} else {
				node.required_if = conditions.rules;
			}
			renderCanvas();
			pushHistory();
			refreshCode();
			scheduleDraft();
		}

		var addButton = el('button', { type: 'button', class: 'button button-small', text: '+ Add rule' });
		addButton.addEventListener('click', function () {
			conditions.rules.push({ field: '', operator: 'equals', value: '' });
			renderRules();
		});

		wrap.appendChild(addButton);
		renderRules();

		if (relationWrap) {
			var relationSelect = relationWrap.querySelector('[data-prop="__relation"]');
			if (relationSelect) {
				relationSelect.addEventListener('change', function () {
					conditions.relation = this.value;
					commitRules();
				});
			}
		}

		return wrap;
	}

	function renderInspector() {
		els.inspector.innerHTML = '';
		var node = selectedNode();

		if (!node) {
			els.inspector.appendChild(el('p', { class: 'rl-builder-hint', text: 'Select a field, section or tab to edit it.' }));
			return;
		}

		var kind = state.selection.kind;

		els.inspector.appendChild(
			el('div', { class: 'rl-builder-inspector-head' }, [
				el('span', { class: 'rl-builder-kind', text: kind }),
				el('code', { text: node.id }),
				node.type ? el('span', { class: 'rl-builder-kind', text: node.type }) : null
			])
		);

		var body = el('div', { class: 'rl-builder-inspector-body' });
		els.inspector.appendChild(body);

		if (kind === 'tab') {
			body.appendChild(inputRow('Label', textInput('label', node.label)));
			body.appendChild(inputRow('ID', textInput('id', node.id), 'Slug used in code and URLs.'));
			body.appendChild(inputRow('Priority', textInput('priority', node.priority, 'number')));
			body.appendChild(el('h3', { text: 'Visibility conditions' }));
			body.appendChild(rulesEditor(els.inspector, node, 'conditions', 'Always visible.'));
		} else if (kind === 'section') {
			body.appendChild(inputRow('Title', textInput('title', node.title)));
			body.appendChild(inputRow('ID', textInput('id', node.id)));
			body.appendChild(inputRow('Priority', textInput('priority', node.priority, 'number')));
			body.appendChild(inputRow('Accordion', checkboxInput('accordion', node.accordion, 'Render as a collapsible section')));
			body.appendChild(el('h3', { text: 'Visibility conditions' }));
			body.appendChild(rulesEditor(els.inspector, node, 'conditions', 'Always visible.'));
		} else {
			var meta = (cfg.catalog.types || {})[node.type] || { props: [] };
			var props = meta.props || [];

			body.appendChild(inputRow('Label', textInput('label', node.label)));
			body.appendChild(inputRow('ID', textInput('id', node.id)));
			body.appendChild(inputRow('Description', textInput('description', node.description)));
			body.appendChild(inputRow('Default', textInput('default', node.default)));

			if (props.indexOf('required') !== -1) {
				body.appendChild(inputRow('Required', checkboxInput('required', node.required, 'Must not be empty')));
			}
			if (props.indexOf('width') !== -1) {
				body.appendChild(inputRow('Width', selectInput('width', node.width, { '': 'Default', full: 'Full', half: 'Half', third: 'One third', 'two-thirds': 'Two thirds' })));
			}
			if (props.indexOf('class') !== -1) {
				body.appendChild(inputRow('Extra CSS classes', textInput('class', node.class)));
			}
			if (props.indexOf('placeholder') !== -1) {
				body.appendChild(inputRow('Placeholder', textInput('placeholder', node.placeholder)));
			}
			if (props.indexOf('text') !== -1) {
				body.appendChild(inputRow('Inline label', textInput('text', node.text)));
			}
			if (props.indexOf('multiple') !== -1) {
				body.appendChild(inputRow('Multiple', checkboxInput('multiple', node.multiple, 'Allow several active buttons')));
			}
			if (props.indexOf('unit') !== -1) {
				body.appendChild(inputRow('Unit', textInput('unit', node.unit)));
			}
			if (props.indexOf('media_type') !== -1) {
				body.appendChild(inputRow('Media type', selectInput('media_type', node.media_type, { image: 'Image', video: 'Video', audio: 'Audio', file: 'File' })));
			}
			if (props.indexOf('language') !== -1) {
				body.appendChild(inputRow('Language', textInput('language', node.language)));
			}

			['min', 'max', 'step', 'rows', 'max_rows', 'minlength'].forEach(function (key) {
				if (props.indexOf(key) !== -1) {
					body.appendChild(inputRow(key.replace('_', ' '), textInput(key, node[key], 'number')));
				}
			});

			if (props.indexOf('options') !== -1) {
				var optionsArea = el('textarea', { class: 'large-text code', rows: 5, 'data-prop': '__options' });
				optionsArea.value = (node.options || []).map(function (pair) { return pair[0] + '=' + pair[1]; }).join('\n');
				body.appendChild(inputRow('Options', optionsArea, 'One per line: value=Label'));
				optionsArea.addEventListener('input', function () {
					node.options = this.value.split('\n').map(function (line) {
						var parts = line.split('=');
						var value = (parts.shift() || '').trim();
						var label = parts.join('=').trim() || value;
						return value ? [value, label] : null;
					}).filter(Boolean);
					pushHistory();
					refreshCode();
					scheduleDraft();
				});
			}

			body.appendChild(el('h3', { text: 'Visibility conditions' }));
			body.appendChild(rulesEditor(els.inspector, node, 'conditions', 'Always visible.'));

			body.appendChild(el('h3', { text: 'Required if' }));
			body.appendChild(rulesEditor(els.inspector, node, 'required_if', 'Never required.'));
		}

		bindInspectorInputs(body);
		ensureFieldIdDatalist();
	}

	/**
	 * Sibling ids already in use, so renaming cannot create a collision.
	 *
	 * @param {string} kind Inspected node kind.
	 * @param {Object} node Node being edited.
	 */
	function siblingIds(kind, node) {
		var sel = state.selection;
		var ids = [];

		if (kind === 'tab') {
			ids = state.tabs.filter(function (tab) { return tab !== node; }).map(function (tab) { return tab.id; });
		} else if (kind === 'section') {
			var parentTab = findTab(sel.tabId);
			if (parentTab) {
				ids = parentTab.sections.filter(function (section) { return section !== node; }).map(function (section) { return section.id; });
			}
		} else {
			var parentSection = findSection(sel.tabId, sel.sectionId);
			if (parentSection) {
				ids = parentSection.fields.filter(function (field) { return field !== node; }).map(function (field) { return field.id; });
			}
		}

		return ids;
	}

	function bindInspectorInputs(scope) {
		Array.prototype.forEach.call(scope.querySelectorAll('[data-prop]'), function (input) {
			if (input.getAttribute('data-prop').indexOf('__') === 0) {
				return;
			}

			var eventName = input.type === 'checkbox' || input.tagName === 'SELECT' ? 'change' : 'input';

			input.addEventListener(eventName, function () {
				var node = selectedNode();
				if (!node) {
					return;
				}

				var prop = this.getAttribute('data-prop');
				var value = this.type === 'checkbox' ? this.checked : this.value;

				if (prop === 'priority') {
					value = parseInt(value, 10) || 0;
				}

				if (prop === 'id') {
					value = slugify(value) || node.id;

					if (siblingIds(state.selection.kind, node).indexOf(value) !== -1) {
						this.value = node.id;
						setStatus('The id “' + value + '” is already used here', 'error');
						return;
					}

					if (this.value !== value) {
						this.value = value;
					}
				}

				node[prop] = value;

				if (prop === 'id') {
					// Keep the selection pointing at the renamed node.
					if (state.selection.kind === 'field') {
						state.selection.fieldId = value;
					} else if (state.selection.kind === 'section') {
						state.selection.sectionId = value;
					} else {
						state.selection.tabId = value;
					}

					var headCode = els.inspector.querySelector('.rl-builder-inspector-head code');
					if (headCode) {
						headCode.textContent = value;
					}

					ensureFieldIdDatalist();
				}

				if (prop === 'id' || prop === 'label' || prop === 'title') {
					renderCanvas();
				} else if (state.selection.kind === 'field') {
					var liveField = findField(state.selection.tabId, state.selection.sectionId, state.selection.fieldId);

					if (liveField) {
						schedulePreview(liveField);
					}
				}

				pushHistory();
				refreshCode();
				scheduleDraft();
			});
		});
	}

	function ensureFieldIdDatalist() {
		var datalist = document.getElementById('rl-builder-field-ids');

		if (!datalist) {
			datalist = el('datalist', { id: 'rl-builder-field-ids' });
			document.body.appendChild(datalist);
		}

		datalist.innerHTML = '';
		allFieldIds().forEach(function (id) {
			datalist.appendChild(el('option', { value: id }));
		});
	}

	/* ---------------------------------------------------------------- actions */

	function select(kind, tabId, sectionId, fieldId) {
		state.selection = { kind: kind, tabId: tabId, sectionId: sectionId || '', fieldId: fieldId || '' };
		renderCanvas();
		renderInspector();
	}

	function addField(type, tabId, sectionId, index) {
		var section = findSection(tabId, sectionId);

		if (!section) {
			return;
		}

		var field = makeField(type);
		var list = allFieldIds();
		while (list.indexOf(field.id) !== -1) {
			field.id = slugify(field.id + '_2');
		}

		if (typeof index === 'number' && index >= 0) {
			section.fields.splice(index, 0, field);
		} else {
			section.fields.push(field);
		}

		select('field', tabId, sectionId, field.id);
		pushHistory();
		refreshCode();
		scheduleDraft();
		setStatus('Added ' + type, 'ok');
	}

	function moveField(payload, tabId, sectionId, index) {
		var source = findSection(payload.tabId, payload.sectionId);
		var target = findSection(tabId, sectionId);

		if (!source || !target) {
			return;
		}

		var position = source.fields.findIndex(function (field) { return field.id === payload.fieldId; });
		if (position === -1) {
			return;
		}

		var moved = source.fields.splice(position, 1)[0];

		if (source === target && typeof index === 'number' && position < index) {
			index -= 1;
		}

		if (source !== target && target.fields.some(function (field) { return field.id === moved.id; })) {
			moved.id = uniqueId(moved.id, target.fields.map(function (field) { return field.id; }));
		}

		if (typeof index === 'number' && index >= 0) {
			target.fields.splice(index, 0, moved);
		} else {
			target.fields.push(moved);
		}

		select('field', tabId, sectionId, moved.id);
		pushHistory();
		refreshCode();
		scheduleDraft();
	}

	function deleteSelection() {
		var sel = state.selection;
		if (!sel) {
			return;
		}

		if (sel.kind === 'tab') {
			state.tabs = state.tabs.filter(function (tab) { return tab.id !== sel.tabId; });
		} else if (sel.kind === 'section') {
			var tab = findTab(sel.tabId);
			if (tab) {
				tab.sections = tab.sections.filter(function (section) { return section.id !== sel.sectionId; });
			}
		} else {
			var section = findSection(sel.tabId, sel.sectionId);
			if (section) {
				section.fields = section.fields.filter(function (field) { return field.id !== sel.fieldId; });
			}
		}

		state.selection = null;
		renderCanvas();
		renderInspector();
		pushHistory();
		refreshCode();
		scheduleDraft();
	}

	/* -------------------------------------------------- history / dirty state */

	function snapshot() {
		return JSON.stringify(state.tabs);
	}

	function pushHistory() {
		var snap = snapshot();

		if (state.history[state.historyIndex] === snap) {
			return;
		}

		state.history = state.history.slice(0, state.historyIndex + 1);
		state.history.push(snap);
		state.historyIndex = state.history.length - 1;

		if (state.history.length > 60) {
			state.history.shift();
			state.historyIndex--;
		}

		updateDirty();
		updateHistoryButtons();
	}

	function historyStep(direction) {
		var next = state.historyIndex + direction;

		if (next < 0 || next >= state.history.length) {
			return;
		}

		state.historyIndex = next;
		state.tabs = JSON.parse(state.history[next]);
		state.selection = null;
		renderCanvas();
		renderInspector();
		refreshCode();
		scheduleDraft();
		updateDirty();
		updateHistoryButtons();
	}

	function updateDirty() {
		state.dirty = snapshot() !== state.savedSnapshot;
		root.classList.toggle('is-dirty', state.dirty);

		var dot = root.querySelector('[data-builder-dirty]');

		if (dot) {
			dot.textContent = state.dirty ? 'Unsaved changes' : 'All changes saved';
			dot.className = 'rl-builder-dirty ' + (state.dirty ? 'is-dirty' : 'is-clean');
		}
	}

	function updateHistoryButtons() {
		var undo = root.querySelector('[data-builder-action="undo"]');
		var redo = root.querySelector('[data-builder-action="redo"]');

		if (undo) {
			undo.disabled = state.historyIndex <= 0;
		}

		if (redo) {
			redo.disabled = state.historyIndex >= state.history.length - 1;
		}
	}

	/* ------------------------------------------------------ field operations */

	function duplicateField(tabId, sectionId, fieldId) {
		var section = findSection(tabId, sectionId);
		var source = findField(tabId, sectionId, fieldId);

		if (!section || !source) {
			return;
		}

		var copy = JSON.parse(JSON.stringify(source));
		copy.id = uniqueId(slugify(source.id) + '_copy', section.fields.map(function (field) { return field.id; }));
		copy.label = (source.label || source.id) + ' (copy)';

		var index = section.fields.indexOf(source);
		section.fields.splice(index + 1, 0, copy);

		select('field', tabId, sectionId, copy.id);
		pushHistory();
		refreshCode();
		scheduleDraft();
		setStatus('Duplicated ' + source.id, 'ok');
	}

	/**
	 * Inline field picker: click "+ Add field" in a section, type to filter,
	 * press Enter to insert the highlighted type.
	 */
	function openQuickAdd(tabId, sectionId, anchorEl) {
		closeQuickAdd();

		var box = el('div', { class: 'rl-builder-quick-add' }, [
			el('input', { type: 'search', class: 'rl-builder-quick-search', placeholder: 'Search a field type…' }),
			el('div', { class: 'rl-builder-quick-list' })
		]);

		var input = box.querySelector('.rl-builder-quick-search');
		var list = box.querySelector('.rl-builder-quick-list');
		var types = (cfg.catalog && cfg.catalog.types) ? Object.keys(cfg.catalog.types) : [];

		function paint(filter) {
			list.innerHTML = '';
			var query = String(filter || '').toLowerCase();
			var matches = types.filter(function (type) { return type.indexOf(query) !== -1; }).slice(0, 24);

			matches.forEach(function (type, index) {
				var button = el('button', {
					type: 'button',
					class: 'rl-builder-quick-item' + (index === 0 ? ' is-active' : ''),
					'data-type': type
				}, [
					el('span', { class: 'rl-builder-field-type', text: type }),
					el('span', { text: (cfg.catalog.types[type] && cfg.catalog.types[type].label) || type })
				]);

				button.addEventListener('click', function () {
					addField(type, tabId, sectionId);
					closeQuickAdd();
				});

				list.appendChild(button);
			});

			if (!matches.length) {
				list.appendChild(el('p', { class: 'rl-builder-hint', text: 'No field type matches.' }));
			}
		}

		input.addEventListener('input', function () {
			paint(this.value);
		});

		input.addEventListener('keydown', function (event) {
			var items = list.querySelectorAll('.rl-builder-quick-item');
			var active;
			var index;

			if (!items.length) {
				return;
			}

			active = list.querySelector('.rl-builder-quick-item.is-active') || items[0];
			index = Array.prototype.indexOf.call(items, active);

			if (event.key === 'ArrowDown') {
				event.preventDefault();
				active.classList.remove('is-active');
				items[Math.min(items.length - 1, index + 1)].classList.add('is-active');
			} else if (event.key === 'ArrowUp') {
				event.preventDefault();
				active.classList.remove('is-active');
				items[Math.max(0, index - 1)].classList.add('is-active');
			} else if (event.key === 'Enter') {
				event.preventDefault();
				addField(active.getAttribute('data-type'), tabId, sectionId);
				closeQuickAdd();
			} else if (event.key === 'Escape') {
				closeQuickAdd();
			}
		});

		paint('');
		anchorEl.parentNode.insertBefore(box, anchorEl.nextSibling);
		input.focus();
	}

	function closeQuickAdd() {
		Array.prototype.forEach.call(root.querySelectorAll('.rl-builder-quick-add'), function (node) {
			node.remove();
		});
	}

	/* ------------------------------------------------------- keyboard + search */

	document.addEventListener('keydown', function (event) {
		var target = event.target;
		var tag = (target.tagName || '').toUpperCase();
		var typing = /^(INPUT|TEXTAREA|SELECT)$/.test(tag) || target.isContentEditable;

		// Never steal a key from a field the user is typing in (native undo wins),
		// but stay global otherwise so the shortcuts work right after a re-render.
		if (typing && event.key !== 'Escape') {
			return;
		}

		if ((event.metaKey || event.ctrlKey) && event.key.toLowerCase() === 'z') {
			event.preventDefault();
			historyStep(event.shiftKey ? 1 : -1);
		} else if ((event.metaKey || event.ctrlKey) && event.key.toLowerCase() === 'd') {
			if (state.selection && state.selection.kind === 'field') {
				event.preventDefault();
				duplicateField(state.selection.tabId, state.selection.sectionId, state.selection.fieldId);
			}
		} else if (!typing && (event.key === 'Delete' || event.key === 'Backspace')) {
			if (state.selection) {
				event.preventDefault();
				deleteSelection();
			}
		} else if (event.key === 'Escape') {
			closeQuickAdd();
		}
	});

	function bindPaletteSearch() {
		if (!els.search) {
			return;
		}

		els.search.addEventListener('input', function () {
			var query = this.value.toLowerCase();

			Array.prototype.forEach.call(els.palette.querySelectorAll('.rl-builder-group'), function (group) {
				var visible = 0;

				Array.prototype.forEach.call(group.querySelectorAll('.rl-builder-chip'), function (chip) {
					var title = (chip.getAttribute('title') || '').toLowerCase();
					var match = !query || title.indexOf(query) !== -1 || chip.textContent.toLowerCase().indexOf(query) !== -1;
					chip.hidden = !match;
					visible += match ? 1 : 0;
				});

				group.hidden = visible === 0;
			});
		});
	}

	/* ------------------------------------------------------------------- drag */

	var dragPayload = null;

	document.addEventListener('dragstart', function (event) {
		var source = event.target.closest('[data-drag]');
		if (!source || !root.contains(source)) {
			return;
		}

		dragPayload = source.getAttribute('data-drag');
		event.dataTransfer.effectAllowed = 'copyMove';
		event.dataTransfer.setData('text/plain', dragPayload);
		source.classList.add('is-dragging');
	});

	document.addEventListener('dragend', function (event) {
		var source = event.target.closest('[data-drag]');
		if (source) {
			source.classList.remove('is-dragging');
		}
		dragPayload = null;
	});

	document.addEventListener('dragover', function (event) {
		var zone = event.target.closest('[data-drop]');
		if (!zone || !root.contains(zone)) {
			return;
		}
		event.preventDefault();
		event.dataTransfer.dropEffect = 'move';
		zone.classList.add('is-drop-target');
	});

	document.addEventListener('dragleave', function (event) {
		var zone = event.target.closest('[data-drop]');
		if (zone) {
			zone.classList.remove('is-drop-target');
		}
	});

	document.addEventListener('drop', function (event) {
		var zone = event.target.closest('[data-drop]');
		if (!zone || !root.contains(zone)) {
			return;
		}

		event.preventDefault();
		zone.classList.remove('is-drop-target');

		var raw = dragPayload || event.dataTransfer.getData('text/plain');
		var payload;

		try {
			payload = JSON.parse(raw);
		} catch (error) {
			return;
		}

		var kind = zone.getAttribute('data-drop');
		var tabId = zone.getAttribute('data-tab');
		var sectionId = zone.getAttribute('data-section');

		if (kind === 'fields') {
			var index = dropIndex(zone, '.rl-builder-field', event.clientY);

			if (payload.kind === 'new') {
				addField(payload.type, tabId, sectionId, index);
			} else if (payload.kind === 'field') {
				moveField(payload, tabId, sectionId, index);
			}
			return;
		}

		if (kind === 'sections' && payload.kind === 'section') {
			var sourceTab = findTab(payload.tabId);
			var targetTab = findTab(tabId);
			if (!sourceTab || !targetTab) {
				return;
			}
			var position = sourceTab.sections.findIndex(function (section) { return section.id === payload.sectionId; });
			if (position === -1) {
				return;
			}
			var section = sourceTab.sections.splice(position, 1)[0];

			if (sourceTab !== targetTab && targetTab.sections.some(function (item) { return item.id === section.id; })) {
				section.id = uniqueId(section.id, targetTab.sections.map(function (item) { return item.id; }));
			}

			var sectionIndex = dropIndex(zone, '.rl-builder-section', event.clientY);

			if (sourceTab === targetTab && position < sectionIndex) {
				sectionIndex -= 1;
			}

			targetTab.sections.splice(sectionIndex, 0, section);
			select('section', tabId, section.id);
			pushHistory();
			refreshCode();
			scheduleDraft();
			return;
		}

		if (kind === 'tabs' && payload.kind === 'tab') {
			var from = state.tabs.findIndex(function (tab) { return tab.id === payload.tabId; });
			if (from === -1) {
				return;
			}
			var moved = state.tabs.splice(from, 1)[0];
			var to = dropIndex(zone, '.rl-builder-tab', event.clientY);
			if (from < to) {
				to -= 1;
			}
			state.tabs.splice(to, 0, moved);
			select('tab', moved.id);
			pushHistory();
			refreshCode();
			scheduleDraft();
		}
	});

	function dropIndex(zone, selector, clientY) {
		var items = Array.prototype.slice.call(zone.querySelectorAll(selector));
		var index = items.length;

		for (var i = 0; i < items.length; i += 1) {
			var box = items[i].getBoundingClientRect();
			if (clientY < box.top + box.height / 2) {
				index = i;
				break;
			}
		}

		return index;
	}

	/* ----------------------------------------------------------- PHP exporter */

	function php(value, depth) {
		depth = depth || 0;
		var pad = new Array(depth + 1).join('\t');

		if (typeof value === 'string') {
			return "'" + value.replace(/\\/g, '\\\\').replace(/'/g, "\\'") + "'";
		}

		if (typeof value === 'number' || typeof value === 'boolean') {
			return String(value);
		}

		if (value === null || value === undefined) {
			return 'null';
		}

		if (Array.isArray(value)) {
			if (!value.length) {
				return '[]';
			}
			return '[\n' + value.map(function (item) {
				return pad + '\t' + php(item, depth + 1) + ',';
			}).join('\n') + '\n' + pad + ']';
		}

		if (typeof value === 'object') {
			var keys = Object.keys(value);
			if (!keys.length) {
				return '[]';
			}
			return '[\n' + keys.map(function (key) {
				return pad + '\t' + php(key, depth + 1) + ' => ' + php(value[key], depth + 1) + ',';
			}).join('\n') + '\n' + pad + ']';
		}

		return "''";
	}

	function coerceValue(value) {
		if (value === 'true') {
			return true;
		}
		if (value === 'false') {
			return false;
		}
		if (value !== '' && !isNaN(value) && String(Number(value)) === String(value)) {
			return Number(value);
		}
		return value;
	}

	function fieldDefinition(field) {
		var def = { id: field.id, type: field.type };

		if (field.label) {
			def.label = field.label;
		}
		if (field.description) {
			def.description = field.description;
		}
		if (field.default !== '' && field.default !== undefined && field.default !== null) {
			def.default = BOOL_TYPES.indexOf(field.type) !== -1
				? (field.default === true || field.default === '1' || field.default === 'true')
				: (NUMERIC_TYPES.indexOf(field.type) !== -1 ? Number(field.default) : field.default);
		}
		if (field.required) {
			def.required = true;
		}
		if (field.width) {
			def.width = field.width;
		}
		if (field.class) {
			def.class = field.class;
		}
		if (field.multiple) {
			def.multiple = true;
		}
		if (field.text) {
			def.text = field.text;
		}
		if (field.placeholder) {
			def.placeholder = field.placeholder;
		}
		if (field.unit) {
			def.unit = field.unit;
		}
		if (field.media_type && field.media_type !== 'image') {
			def.media_type = field.media_type;
		}
		if (field.language && field.type === 'code') {
			def.language = field.language;
		}

		['min', 'max', 'step', 'rows', 'max_rows', 'minlength'].forEach(function (key) {
			if (field[key] !== '' && field[key] !== undefined && field[key] !== null) {
				def[key] = Number(field[key]);
			}
		});

		if (field.options && field.options.length) {
			var options = {};
			field.options.forEach(function (pair) {
				if (pair[0]) {
					options[pair[0]] = pair[1] || pair[0];
				}
			});
			if (Object.keys(options).length) {
				def.options = options;
			}
		}

		var conditions = activeRules(field.conditions);
		if (conditions.length) {
			def.conditions = {
				relation: field.conditions.relation || 'AND',
				rules: conditions.map(function (rule) {
					return { field: rule.field, operator: rule.operator || 'equals', value: coerceValue(rule.value) };
				})
			};
		}

		if (Array.isArray(field.required_if) && field.required_if.length) {
			def.required_if = field.required_if.filter(function (rule) { return rule.field; }).map(function (rule) {
				return { field: rule.field, operator: rule.operator || 'equals', value: coerceValue(rule.value) };
			});
		}

		return def;
	}

	function generateCalls() {
		var out = [];

		state.tabs.forEach(function (tab) {
			out.push('$framework->add_tab( ' + php(tab.id) + ', ' + php({
				label: tab.label || tab.id,
				priority: tab.priority || 10
			}) + ' );');

			tab.sections.forEach(function (section) {
				var tabDef = { title: section.title || section.id, priority: section.priority || 10 };
				if (section.accordion) {
					tabDef.accordion = true;
				}
				var sectionConditions = activeRules(section.conditions);
				if (sectionConditions.length) {
					tabDef.conditions = {
						relation: section.conditions.relation || 'AND',
						rules: sectionConditions.map(function (rule) {
							return { field: rule.field, operator: rule.operator || 'equals', value: coerceValue(rule.value) };
						})
					};
				}

				out.push('$framework->add_section( ' + php(tab.id) + ', ' + php(section.id) + ', ' + php(tabDef) + ' );');

				section.fields.forEach(function (field) {
					out.push('$framework->add_field( ' + php(tab.id) + ', ' + php(section.id) + ', ' + php(fieldDefinition(field)) + ' );');
				});
			});
		});

		return out.join('\n\n');
	}

	function generateFilter() {
		var tabs = {};

		state.tabs.forEach(function (tab) {
			var tabDef = {
				label: tab.label || tab.id,
				priority: tab.priority || 10,
				sections: {}
			};

			var tabConditions = activeRules(tab.conditions);
			if (tabConditions.length) {
				tabDef.conditions = {
					relation: tab.conditions.relation || 'AND',
					rules: tabConditions.map(function (rule) {
						return { field: rule.field, operator: rule.operator || 'equals', value: coerceValue(rule.value) };
					})
				};
			}

			tab.sections.forEach(function (section) {
				var sectionDef = { title: section.title || section.id, fields: {} };

				if (section.accordion) {
					sectionDef.accordion = true;
				}

				section.fields.forEach(function (field) {
					sectionDef.fields[field.id] = fieldDefinition(field);
				});

				tabDef.sections[section.id] = sectionDef;
			});

			tabs[tab.id] = tabDef;
		});

		return [
			"add_filter( '" + (cfg.optionName || 'rl_framework_settings') + "_framework_tabs', function ( $tabs ) {",
			"\treturn " + php(tabs, 1) + ' + $tabs;',
			'} );'
		].join('\n');
	}

	function refreshCode() {
		if (!els.code) {
			return;
		}
		els.code.textContent = els.format && els.format.value === 'filter' ? generateFilter() : generateCalls();
		ensureFieldIdDatalist();
	}

	/* ------------------------------------------------------------------ draft */

	var draftTimer = null;

	function scheduleDraft() {
		window.clearTimeout(draftTimer);
		draftTimer = window.setTimeout(function () {
			try {
				window.localStorage.setItem('rlBuilderDraft:' + (cfg.optionName || ''), JSON.stringify({ tabs: state.tabs }));
			} catch (error) {
				/* ignore quota errors */
			}
		}, 400);
	}

	function saveDraft() {
		var body = new window.URLSearchParams();
		body.set('action', cfg.saveAction);
		body.set('nonce', cfg.nonce);
		body.set('schema', JSON.stringify({ tabs: state.tabs }));

		setStatus('Saving…');

		window
			.fetch(cfg.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: body })
			.then(function (response) { return response.json(); })
			.then(function (json) {
				if (json && json.success) {
					state.savedSnapshot = snapshot();
					updateDirty();
					setStatus('Draft saved (' + (json.data && json.data.tabs) + ' tabs)', 'ok');
				} else {
					setStatus((json && json.data && json.data.message) || 'Save failed', 'error');
				}
			})
			.catch(function () {
				setStatus('Save failed (network)', 'error');
			});
	}

	/* ----------------------------------------------------------------- events */

	root.addEventListener('click', function (event) {
		var actionButton = event.target.closest('[data-builder-action]');
		var tabId;
		var sectionId;

		if (actionButton) {
			var action = actionButton.getAttribute('data-builder-action');
			tabId = actionButton.getAttribute('data-tab');
			sectionId = actionButton.getAttribute('data-section');

			if (action === 'add-tab') {
				var tab = makeTab();
				state.tabs.push(tab);
				select('tab', tab.id);
				pushHistory();
				refreshCode();
				scheduleDraft();
			} else if (action === 'add-section') {
				var parent = findTab(tabId);
				if (parent) {
					var section = makeSection();
					parent.sections.push(section);
					select('section', tabId, section.id);
					pushHistory();
					refreshCode();
					scheduleDraft();
				}
			} else if (action === 'toggle-tab') {
				collapsedTabs[tabId] = !collapsedTabs[tabId];
				renderCanvas();
			} else if (action === 'toggle-section') {
				var key = tabId + ':' + sectionId;
				collapsedSections[key] = !collapsedSections[key];
				renderCanvas();
			} else if (action === 'duplicate-field') {
				duplicateField(tabId, sectionId, actionButton.getAttribute('data-field'));
			} else if (action === 'delete-field') {
				state.selection = {
					kind: 'field',
					tabId: tabId,
					sectionId: sectionId,
					fieldId: actionButton.getAttribute('data-field')
				};
				deleteSelection();
			} else if (action === 'quick-add') {
				openQuickAdd(tabId, sectionId, actionButton);
			} else if (action === 'undo') {
				historyStep(-1);
			} else if (action === 'redo') {
				historyStep(1);
			} else if (action === 'wysiwyg') {
				wysiwyg = !wysiwyg;
				applyWysiwyg();
				actionButton.classList.toggle('is-active', wysiwyg);
				actionButton.setAttribute('aria-pressed', wysiwyg ? 'true' : 'false');
				actionButton.querySelector('.rl-builder-toggle-label').textContent = wysiwyg ? 'Edit mode' : 'Preview page';
			} else if (action === 'delete-tab') {
				state.selection = { kind: 'tab', tabId: tabId };
				deleteSelection();
			} else if (action === 'delete-section') {
				state.selection = { kind: 'section', tabId: tabId, sectionId: sectionId };
				deleteSelection();
			} else if (action === 'new') {
				if (window.confirm('Start a new empty schema? The saved draft is replaced when you press “Save draft”.')) {
					state.tabs = [];
					state.selection = null;
					renderCanvas();
					renderInspector();
					pushHistory();
					refreshCode();
					scheduleDraft();
				}
			} else if (action === 'load-current') {
				if (window.confirm('Replace the canvas with the tabs currently registered by this framework?')) {
					state.tabs = JSON.parse(JSON.stringify(cfg.currentTabs || []));
					state.selection = null;
					renderCanvas();
					renderInspector();
					pushHistory();
					refreshCode();
					scheduleDraft();
				}
			} else if (action === 'save') {
				saveDraft();
			} else if (action === 'export') {
				refreshCode();
				els.exportPanel.hidden = false;
				els.exportPanel.scrollIntoView({ behavior: 'smooth', block: 'start' });
			} else if (action === 'close-export') {
				els.exportPanel.hidden = true;
			} else if (action === 'copy') {
				copyCode();
			} else if (action === 'download') {
				downloadCode();
			}

			return;
		}

		var fieldEl = event.target.closest('.rl-builder-field');
		if (fieldEl) {
			select('field', fieldEl.getAttribute('data-tab'), fieldEl.getAttribute('data-section'), fieldEl.getAttribute('data-field'));
			return;
		}

		var sectionEl = event.target.closest('.rl-builder-section');
		if (sectionEl && !event.target.closest('.rl-builder-field')) {
			select('section', sectionEl.getAttribute('data-tab'), sectionEl.getAttribute('data-section'));
			return;
		}

		var tabEl = event.target.closest('.rl-builder-tab');
		if (tabEl && !event.target.closest('.rl-builder-section')) {
			select('tab', tabEl.getAttribute('data-tab'));
		}
	});

	if (els.format) {
		els.format.addEventListener('change', refreshCode);
	}

	function copyCode() {
		var text = els.code.textContent;

		var selectFallback = function () {
			var range = document.createRange();
			range.selectNodeContents(els.code);
			var selection = window.getSelection();
			selection.removeAllRanges();
			selection.addRange(range);
			setStatus('Copy blocked by the browser — the code is selected, press ⌘/Ctrl+C', 'error');
		};

		if (window.isSecureContext && navigator.clipboard && navigator.clipboard.writeText) {
			navigator.clipboard.writeText(text).then(function () {
				setStatus('Copied to clipboard', 'ok');
			}).catch(selectFallback);
			return;
		}

		selectFallback();
	}

	function downloadCode() {
		var blob = new Blob([els.code.textContent], { type: 'text/plain' });
		var link = document.createElement('a');
		link.href = window.URL.createObjectURL(blob);
		link.download = (cfg.optionName || 'options') + '-fields.php';
		document.body.appendChild(link);
		link.click();
		document.body.removeChild(link);
		window.URL.revokeObjectURL(link.href);
		setStatus('Downloaded', 'ok');
	}

	/* ------------------------------------------------------------------- init */

	function init() {
		var initial = null;

		if (cfg.draft && Array.isArray(cfg.draft.tabs) && cfg.draft.tabs.length) {
			initial = cfg.draft.tabs;
		} else if (Array.isArray(cfg.currentTabs) && cfg.currentTabs.length) {
			initial = cfg.currentTabs;
		}

		state.tabs = initial ? JSON.parse(JSON.stringify(initial)) : [];

		renderPalette();
		bindPaletteSearch();
		renderCanvas();
		renderInspector();
		refreshCode();

		state.savedSnapshot = snapshot();
		state.history = [state.savedSnapshot];
		state.historyIndex = 0;
		updateDirty();
		updateHistoryButtons();

		if (!state.tabs.length) {
			setStatus('Drag a field into the canvas, or press “Load current tabs”.');
		}
	}

	init();
})(window, document);
