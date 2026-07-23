/**
 * PromptWeb Frontend Visual Editor
 * Maximum AI Creativity — Manual Edit + in-memory blueprint Save.
 *
 * Modes:
 *   - manual  → Manual Edit (live fields + Save Changes → updated JSON)
 *   - ai      → AI Prompt (placeholder only; not built yet)
 *
 * Save merges dirty drafts into pages → sections → elements by id.
 * Result: window.PromptWebEditor.getUpdatedBlueprint() (GitHub push is next step).
 *
 * Config: window.promptwebEditor (wp_localize_script).
 * Public visitors never load this file.
 */
(function () {
	'use strict';

	var config = window.promptwebEditor || {};
	var MODE_MANUAL = 'manual';
	var MODE_AI = 'ai';

	/** @type {EditorState} */
	var state = {
		mode: config.defaultMode || MODE_MANUAL,
		selectedEl: null,
		selectedId: null,
		selectedType: null,
		panelOpen: false,
		/** Draft model for the currently selected element. */
		draft: null,
		/** Original blueprint from server (clone). */
		baseBlueprint: null,
		/** Last successfully saved blueprint (in memory). */
		updatedBlueprint: null,
		/** Last save result summary. */
		lastSave: null,
	};

	var dom = {
		root: null,
		toolbar: null,
		panel: null,
		panelTitle: null,
		selectionLabel: null,
		modeButtons: [],
		panelManual: null,
		panelAi: null,
		manualFields: null,
		manualForm: null,
		saveBar: null,
		saveNotice: null,
	};

	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------

	function $(selector, ctx) {
		return (ctx || document).querySelector(selector);
	}

	function $$(selector, ctx) {
		return Array.prototype.slice.call((ctx || document).querySelectorAll(selector));
	}

	function getSelectors() {
		var s = config.selectors || {};
		return {
			scope: s.scope || '.promptweb-editor-scope, .promptweb-page',
			editable: s.editable || '[data-promptweb-editable="1"]',
			selected: s.selected || '.promptweb-editable--selected',
			toolbar: s.toolbar || '#promptweb-editor-toolbar',
			panel: s.panel || '#promptweb-editor-panel',
		};
	}

	function i18n(key, fallback) {
		if (config.i18n && config.i18n[key]) {
			return config.i18n[key];
		}
		return fallback || key;
	}

	function normalizeType(type) {
		if (!type) {
			return 'unknown';
		}
		return String(type)
			.toLowerCase()
			.replace(/^core\//, '')
			.replace(/[\s-]+/g, '_');
	}

	function escapeHtml(str) {
		return String(str)
			.replace(/&/g, '&amp;')
			.replace(/</g, '&lt;')
			.replace(/>/g, '&gt;')
			.replace(/"/g, '&quot;');
	}

	function cssPropToCamel(prop) {
		return prop.replace(/-([a-z])/g, function (_, c) {
			return c.toUpperCase();
		});
	}

	/**
	 * Read a usable CSS value (inline first, then computed).
	 */
	function readStyle(el, prop) {
		if (!el) {
			return '';
		}
		var camel = cssPropToCamel(prop);
		var inline = el.style && el.style[camel] ? el.style[camel] : '';
		if (inline) {
			return inline;
		}
		try {
			var computed = window.getComputedStyle(el);
			return computed ? computed.getPropertyValue(prop) || '' : '';
		} catch (e) {
			return '';
		}
	}

	/**
	 * Extract editable text from an element (type-aware).
	 */
	function readContent(el, type) {
		if (!el) {
			return '';
		}
		type = normalizeType(type);

		if (type === 'image' || type === 'img') {
			return '';
		}

		if (type === 'button') {
			return (el.textContent || '').trim();
		}

		if (type === 'html' || type === 'custom_html' || type === 'raw') {
			return el.innerHTML || '';
		}

		// Prefer direct text for headings / text; fall back to .promptweb-element__content
		var contentNode = el.querySelector(':scope > .promptweb-element__content');
		if (contentNode) {
			return (contentNode.textContent || '').trim();
		}

		// Avoid sucking in nested editable children for sections/unknown wrappers.
		if (type === 'section' || type === 'buttons' || type === 'button_group') {
			return '';
		}

		// Clone and strip nested editables for cleaner text.
		var clone = el.cloneNode(true);
		$$('[data-promptweb-editable="1"]', clone).forEach(function (child) {
			if (child !== clone) {
				child.parentNode && child.parentNode.removeChild(child);
			}
		});
		$$('.promptweb-element__children', clone).forEach(function (child) {
			child.parentNode && child.parentNode.removeChild(child);
		});

		return (clone.textContent || '').trim();
	}

	function readUrl(el, type) {
		if (!el) {
			return '';
		}
		type = normalizeType(type);
		if (type === 'image' || type === 'img') {
			return el.getAttribute('src') || '';
		}
		if (el.tagName === 'A' || type === 'button') {
			return el.getAttribute('href') || '';
		}
		var link = el.querySelector('a');
		return link ? link.getAttribute('href') || '' : '';
	}

	function readAlt(el) {
		if (!el) {
			return '';
		}
		if (el.tagName === 'IMG') {
			return el.getAttribute('alt') || '';
		}
		var img = el.querySelector('img');
		return img ? img.getAttribute('alt') || '' : '';
	}

	/**
	 * Build draft model from DOM (foundation for future JSON save).
	 */
	function buildDraftFromElement(el) {
		var type = normalizeType(el.getAttribute('data-promptweb-type') || '');
		var id =
			el.getAttribute('data-promptweb-editor-id') ||
			el.getAttribute('data-promptweb-id') ||
			'';

		var draft = {
			id: id,
			type: type || 'unknown',
			content: readContent(el, type),
			url: readUrl(el, type),
			alt: readAlt(el),
			settings: {
				color: normalizeColor(readStyle(el, 'color')),
				background: normalizeColor(
					readStyle(el, 'background-color') || readStyle(el, 'background')
				),
				font_size: simplifyLength(readStyle(el, 'font-size')),
				padding: simplifyBox(readStyle(el, 'padding')),
				margin: simplifyBox(readStyle(el, 'margin')),
				border_radius: simplifyLength(readStyle(el, 'border-radius')),
			},
		};

		// Spacers often use height.
		if (type === 'spacer' || type === 'space') {
			draft.settings.height = simplifyLength(readStyle(el, 'height')) || '40px';
		}

		return draft;
	}

	function normalizeColor(value) {
		value = (value || '').trim();
		if (!value || value === 'rgba(0, 0, 0, 0)' || value === 'transparent') {
			return '';
		}
		// rgb(r,g,b) → #hex when possible
		var m = value.match(/^rgba?\(\s*(\d+)\s*,\s*(\d+)\s*,\s*(\d+)/i);
		if (m) {
			return (
				'#' +
				[m[1], m[2], m[3]]
					.map(function (n) {
						var h = parseInt(n, 10).toString(16);
						return h.length === 1 ? '0' + h : h;
					})
					.join('')
			);
		}
		return value;
	}

	function simplifyLength(value) {
		value = (value || '').trim();
		if (!value) {
			return '';
		}
		// 16px → 16px; leave multi-values as-is
		return value;
	}

	function simplifyBox(value) {
		value = (value || '').trim();
		if (!value || value === '0px') {
			return value === '0px' ? '0' : '';
		}
		// Collapse equal 4-value padding/margin
		var parts = value.split(/\s+/);
		if (parts.length === 4 && parts[0] === parts[1] && parts[1] === parts[2] && parts[2] === parts[3]) {
			return parts[0];
		}
		if (parts.length === 4 && parts[0] === parts[2] && parts[1] === parts[3]) {
			return parts[0] + ' ' + parts[1];
		}
		return value;
	}

	// -------------------------------------------------------------------------
	// Field schema (type → fields)
	// -------------------------------------------------------------------------

	/**
	 * Which form fields to show for a given element type.
	 * Unknown / AI types get content + basic settings.
	 */
	function getFieldsForType(type) {
		type = normalizeType(type);

		var basicSettings = [
			{ key: 'color', kind: 'color', label: i18n('fieldColor', 'Color'), setting: true },
			{
				key: 'background',
				kind: 'color',
				label: i18n('fieldBackground', 'Background'),
				setting: true,
			},
			{
				key: 'font_size',
				kind: 'text',
				label: i18n('fieldFontSize', 'Font size'),
				placeholder: '16px',
				setting: true,
			},
			{
				key: 'padding',
				kind: 'text',
				label: i18n('fieldPadding', 'Padding'),
				placeholder: '8px 16px',
				setting: true,
			},
			{
				key: 'margin',
				kind: 'text',
				label: i18n('fieldMargin', 'Margin'),
				placeholder: '0 0 16px',
				setting: true,
			},
			{
				key: 'border_radius',
				kind: 'text',
				label: i18n('fieldBorderRadius', 'Border radius'),
				placeholder: '8px',
				setting: true,
			},
		];

		var contentField = {
			key: 'content',
			kind: type === 'html' || type === 'custom_html' || type === 'raw' ? 'textarea' : 'text',
			label: i18n('fieldContent', 'Content'),
			setting: false,
		};

		var urlField = {
			key: 'url',
			kind: 'url',
			label: i18n('fieldUrl', 'URL'),
			placeholder: 'https:// or /path',
			setting: false,
		};

		var altField = {
			key: 'alt',
			kind: 'text',
			label: i18n('fieldAlt', 'Alt text'),
			setting: false,
		};

		var fields = [];

		switch (type) {
			case 'heading':
			case 'h1':
			case 'h2':
			case 'h3':
			case 'h4':
			case 'h5':
			case 'h6':
			case 'text':
			case 'paragraph':
			case 'p':
				fields = [contentField].concat(basicSettings);
				break;

			case 'button':
				fields = [contentField, urlField].concat(basicSettings);
				break;

			case 'image':
			case 'img':
				fields = [urlField, altField].concat(
					basicSettings.filter(function (f) {
						// Images rarely need font-size as primary — keep for consistency.
						return true;
					})
				);
				break;

			case 'html':
			case 'custom_html':
			case 'raw':
				fields = [contentField].concat(basicSettings);
				break;

			case 'spacer':
			case 'space':
				fields = [
					{
						key: 'height',
						kind: 'text',
						label: i18n('fieldHeight', 'Height'),
						placeholder: '40px',
						setting: true,
					},
					{
						key: 'background',
						kind: 'color',
						label: i18n('fieldBackground', 'Background'),
						setting: true,
					},
				];
				break;

			case 'section':
			case 'buttons':
			case 'button_group':
				// Layout containers: settings only.
				fields = basicSettings;
				break;

			default:
				// AI / unknown: content + basic settings (Maximum AI Creativity).
				fields = [contentField].concat(basicSettings);
				break;
		}

		return fields;
	}

	// -------------------------------------------------------------------------
	// Manual Edit panel UI
	// -------------------------------------------------------------------------

	function openManualEditPanel() {
		setMode(MODE_MANUAL, { silent: true });
		openPanel();
		renderManualForm();
		dispatch('open-manual-panel', {
			element: state.selectedEl,
			id: state.selectedId,
			type: state.selectedType,
			draft: state.draft,
			state: getPublicState(),
		});
	}

	/**
	 * Build / rebuild the Manual Edit form for the current selection.
	 */
	function renderManualForm() {
		var host = dom.manualFields || $('#promptweb-editor-manual-fields');
		if (!host) {
			return;
		}
		dom.manualFields = host;

		// Clear previous form.
		host.innerHTML = '';
		dom.manualForm = null;

		if (!state.selectedEl) {
			host.innerHTML =
				'<p class="promptweb-editor-form__empty">' +
				escapeHtml(i18n('selectHint', 'Click an element to select it.')) +
				'</p>';
			return;
		}

		state.draft = buildDraftFromElement(state.selectedEl);
		persistDraftOnElement(state.selectedEl, state.draft);

		var type = state.draft.type;
		var fields = getFieldsForType(type);

		var form = document.createElement('form');
		form.className = 'promptweb-editor-form';
		form.setAttribute('novalidate', 'novalidate');
		form.addEventListener('submit', function (e) {
			e.preventDefault();
		});

		// Meta header.
		var meta = document.createElement('div');
		meta.className = 'promptweb-editor-form__meta';
		meta.innerHTML =
			'<span class="promptweb-editor-form__badge">' +
			escapeHtml(type || 'unknown') +
			'</span>' +
			(state.draft.id
				? '<code class="promptweb-editor-form__id">' + escapeHtml(state.draft.id) + '</code>'
				: '');
		form.appendChild(meta);

		// Field groups.
		var contentKeys = { content: 1, url: 1, alt: 1 };
		var contentGroup = document.createElement('div');
		contentGroup.className = 'promptweb-editor-form__group';
		contentGroup.innerHTML =
			'<h3 class="promptweb-editor-form__group-title">' +
			escapeHtml(i18n('groupContent', 'Content')) +
			'</h3>';

		var styleGroup = document.createElement('div');
		styleGroup.className = 'promptweb-editor-form__group';
		styleGroup.innerHTML =
			'<h3 class="promptweb-editor-form__group-title">' +
			escapeHtml(i18n('groupSettings', 'Settings')) +
			'</h3>';

		var hasContent = false;
		var hasStyle = false;

		fields.forEach(function (field) {
			var row = buildFieldRow(field, state.draft);
			if (contentKeys[field.key]) {
				contentGroup.appendChild(row);
				hasContent = true;
			} else {
				styleGroup.appendChild(row);
				hasStyle = true;
			}
		});

		if (hasContent) {
			form.appendChild(contentGroup);
		}
		if (hasStyle) {
			form.appendChild(styleGroup);
		}

		var note = document.createElement('p');
		note.className = 'promptweb-editor-form__note';
		note.textContent = i18n(
			'liveOnlyNote',
			'Live preview updates as you type. Use Save Changes to update the blueprint JSON (GitHub push comes next).'
		);
		form.appendChild(note);

		// Save bar: button + notice region.
		var saveBar = document.createElement('div');
		saveBar.className = 'promptweb-editor-save';
		saveBar.innerHTML =
			'<button type="button" class="promptweb-editor-save__btn" data-promptweb-save>' +
			escapeHtml(i18n('saveChanges', 'Save Changes')) +
			'</button>' +
			'<div class="promptweb-editor-save__notice" data-promptweb-save-notice hidden></div>';
		form.appendChild(saveBar);
		dom.saveBar = saveBar;
		dom.saveNotice = $('[data-promptweb-save-notice]', saveBar);

		host.appendChild(form);
		dom.manualForm = form;

		// Bind inputs.
		$$('[data-promptweb-field]', form).forEach(function (input) {
			var handler = function () {
				onFieldChange(input);
			};
			input.addEventListener('input', handler);
			input.addEventListener('change', handler);
		});

		var saveBtn = $('[data-promptweb-save]', form);
		if (saveBtn) {
			saveBtn.addEventListener('click', function () {
				saveChanges({ source: 'manual-panel' });
			});
		}

		// Restore last save notice if still relevant.
		if (state.lastSave && state.lastSave.message) {
			showSaveNotice(state.lastSave.type || 'success', state.lastSave.message);
		}
	}

	/**
	 * Show success/error message under the Save button.
	 *
	 * @param {'success'|'error'|'info'} type
	 * @param {string} message
	 */
	function showSaveNotice(type, message) {
		var notice =
			dom.saveNotice ||
			(dom.manualForm && $('[data-promptweb-save-notice]', dom.manualForm)) ||
			null;

		if (!notice) {
			return;
		}

		dom.saveNotice = notice;
		notice.hidden = !message;
		notice.textContent = message || '';
		notice.className =
			'promptweb-editor-save__notice promptweb-editor-save__notice--' + (type || 'info');
		if (!message) {
			notice.className = 'promptweb-editor-save__notice';
		}
	}

	// -------------------------------------------------------------------------
	// Save: dirty drafts → updated blueprint JSON (in memory)
	// -------------------------------------------------------------------------

	/**
	 * Deep-clone plain JSON-safe data.
	 */
	function deepClone(value) {
		try {
			return JSON.parse(JSON.stringify(value));
		} catch (e) {
			return null;
		}
	}

	/**
	 * Load base blueprint from config into state.
	 */
	function initBlueprintState() {
		var raw =
			config.blueprintData && typeof config.blueprintData === 'object'
				? config.blueprintData
				: {};
		state.baseBlueprint = deepClone(raw) || {};
		// Start updated = base; Save will replace with merged copy.
		state.updatedBlueprint = deepClone(state.baseBlueprint) || {};
	}

	/**
	 * Flush current form draft onto the selected element as dirty.
	 */
	function flushCurrentDraft() {
		if (state.selectedEl && state.draft) {
			persistDraftOnElement(state.selectedEl, state.draft);
			state.selectedEl.setAttribute('data-promptweb-dirty', '1');
		}
	}

	/**
	 * Collect dirty drafts, including refreshing parse from DOM attributes.
	 * Also attaches node reference for debugging (not serialized).
	 */
	function collectDirtyDrafts() {
		flushCurrentDraft();

		var nodes = $$('[data-promptweb-dirty="1"][data-promptweb-draft]');
		var drafts = [];

		nodes.forEach(function (node) {
			try {
				var draft = JSON.parse(node.getAttribute('data-promptweb-draft') || '{}');
				if (!draft || typeof draft !== 'object') {
					return;
				}
				// Prefer live attributes if draft id empty.
				if (!draft.id) {
					draft.id =
						node.getAttribute('data-promptweb-editor-id') ||
						node.getAttribute('data-promptweb-id') ||
						'';
				}
				if (!draft.type) {
					draft.type = node.getAttribute('data-promptweb-type') || 'unknown';
				}
				drafts.push(draft);
			} catch (e) {
				// skip bad JSON
			}
		});

		return drafts;
	}

	/**
	 * Apply a single draft onto a blueprint element node (mutates node).
	 *
	 * @param {object} node   Element (or section) object in blueprint JSON.
	 * @param {object} draft  Editor draft.
	 */
	function applyDraftToBlueprintNode(node, draft) {
		if (!node || !draft) {
			return;
		}

		var type = normalizeType(draft.type || node.type || '');

		// Content (headings, text, buttons, html, unknown).
		if (Object.prototype.hasOwnProperty.call(draft, 'content')) {
			var skipContent =
				type === 'image' ||
				type === 'img' ||
				type === 'spacer' ||
				type === 'space' ||
				type === 'section' ||
				type === 'buttons' ||
				type === 'button_group';
			if (!skipContent) {
				node.content = draft.content == null ? '' : String(draft.content);
			}
		}

		if (!node.settings || typeof node.settings !== 'object' || Array.isArray(node.settings)) {
			node.settings = {};
		}

		// Merge style / layout settings from draft.
		var settings = draft.settings || {};
		Object.keys(settings).forEach(function (key) {
			var val = settings[key];
			if (val === '' || val == null) {
				// Empty means clear — remove key so defaults can apply later.
				if (Object.prototype.hasOwnProperty.call(node.settings, key)) {
					delete node.settings[key];
				}
				// Also clear common aliases.
				if (key === 'background' && node.settings.background_color) {
					delete node.settings.background_color;
				}
				return;
			}
			node.settings[key] = val;

			// Keep common aliases in sync for older blueprints.
			if (key === 'background') {
				node.settings.background_color = val;
			}
		});

		// URL → settings for buttons / images.
		if (Object.prototype.hasOwnProperty.call(draft, 'url')) {
			var url = draft.url == null ? '' : String(draft.url);
			if (type === 'image' || type === 'img') {
				if (url) {
					node.settings.src = url;
					node.settings.url = url;
				} else {
					delete node.settings.src;
					delete node.settings.url;
				}
			} else {
				if (url) {
					node.settings.url = url;
					node.settings.href = url;
				} else {
					delete node.settings.url;
					delete node.settings.href;
				}
			}
		}

		// Alt text for images.
		if (Object.prototype.hasOwnProperty.call(draft, 'alt')) {
			var alt = draft.alt == null ? '' : String(draft.alt);
			if (alt) {
				node.settings.alt = alt;
			} else {
				delete node.settings.alt;
			}
			// Some blueprints store alt top-level.
			if (Object.prototype.hasOwnProperty.call(node, 'alt')) {
				node.alt = alt;
			}
		}

		// Preserve type if missing on node (AI elements).
		if (!node.type && draft.type) {
			node.type = draft.type;
		}
	}

	/**
	 * Walk blueprint tree and apply drafts by id.
	 *
	 * @param {object} blueprint Blueprint root (mutated copy).
	 * @param {object} draftById Map id → draft.
	 * @returns {{ applied: number, appliedIds: string[], unmatched: object[] }}
	 */
	function applyDraftsToBlueprint(blueprint, draftById) {
		var applied = 0;
		var appliedIds = [];
		var remaining = {};
		Object.keys(draftById).forEach(function (id) {
			remaining[id] = draftById[id];
		});

		function visit(node) {
			if (!node || typeof node !== 'object' || Array.isArray(node)) {
				return;
			}

			var id = node.id != null ? String(node.id) : '';
			if (id && remaining[id]) {
				applyDraftToBlueprintNode(node, remaining[id]);
				applied += 1;
				appliedIds.push(id);
				delete remaining[id];
			}

			// Nested collections AI / schema may use.
			var keys = ['pages', 'sections', 'elements', 'children', 'items', 'blocks'];
			keys.forEach(function (key) {
				if (!Array.isArray(node[key])) {
					return;
				}
				node[key].forEach(function (child) {
					visit(child);
				});
			});
		}

		visit(blueprint);

		var unmatched = Object.keys(remaining).map(function (id) {
			return remaining[id];
		});

		return {
			applied: applied,
			appliedIds: appliedIds,
			unmatched: unmatched,
		};
	}

	/**
	 * Save Changes: merge dirty drafts into blueprint JSON (memory only).
	 *
	 * @param {object} [opts]
	 * @returns {{ success: boolean, message: string, blueprint?: object, result?: object }}
	 */
	function saveChanges(opts) {
		opts = opts || {};

		var saveBtn =
			(dom.manualForm && $('[data-promptweb-save]', dom.manualForm)) ||
			$('[data-promptweb-save]');
		if (saveBtn) {
			saveBtn.disabled = true;
			saveBtn.textContent = i18n('saving', 'Saving…');
		}

		var report = {
			success: false,
			message: '',
			type: 'error',
		};

		try {
			if (!state.baseBlueprint || typeof state.baseBlueprint !== 'object') {
				initBlueprintState();
			}

			var base = state.updatedBlueprint || state.baseBlueprint || {};
			if (!base || typeof base !== 'object' || !Object.keys(base).length) {
				// Allow empty object only if config truly empty.
				if (!config.blueprintData || !Object.keys(config.blueprintData).length) {
					report.message = i18n(
						'saveNoBlueprint',
						'No blueprint loaded. Sync from GitHub in settings first.'
					);
					report.type = 'error';
					state.lastSave = { type: report.type, message: report.message };
					showSaveNotice('error', report.message);
					return report;
				}
				base = config.blueprintData;
			}

			var drafts = collectDirtyDrafts();
			if (!drafts.length) {
				report.success = true;
				report.type = 'info';
				report.message = i18n('saveNoDirty', 'No changes to save.');
				state.lastSave = { type: report.type, message: report.message };
				showSaveNotice('info', report.message);
				dispatch('save', { success: true, noDirty: true, blueprint: getUpdatedBlueprint() });
				return report;
			}

			// Index drafts by id (last write wins).
			var draftById = {};
			var noId = [];
			drafts.forEach(function (d) {
				var id = d && d.id != null ? String(d.id).trim() : '';
				if (!id) {
					noId.push(d);
					return;
				}
				draftById[id] = d;
			});

			var next = deepClone(base);
			if (!next) {
				report.message = i18n('saveError', 'Could not update the blueprint.');
				report.type = 'error';
				state.lastSave = { type: report.type, message: report.message };
				showSaveNotice('error', report.message);
				return report;
			}

			// Ensure pages array exists so structure stays valid.
			if (!Array.isArray(next.pages)) {
				next.pages = Array.isArray(base.pages) ? deepClone(base.pages) : [];
			}

			var merge = applyDraftsToBlueprint(next, draftById);
			var unmatched = merge.unmatched.concat(noId);

			state.updatedBlueprint = next;
			// Keep window-level handle for next step / debugging.
			window.promptwebUpdatedBlueprint = next;

			// Clear dirty flags for successfully applied ids.
			var appliedSet = {};
			merge.appliedIds.forEach(function (id) {
				appliedSet[id] = true;
			});
			$$('[data-promptweb-dirty="1"]').forEach(function (node) {
				var nid =
					node.getAttribute('data-promptweb-editor-id') ||
					node.getAttribute('data-promptweb-id') ||
					'';
				if (nid && appliedSet[nid]) {
					node.removeAttribute('data-promptweb-dirty');
					// Keep data-promptweb-draft as last known good snapshot.
				}
			});

			if (merge.applied === 0 && unmatched.length) {
				report.success = false;
				report.type = 'error';
				report.message =
					i18n('saveError', 'Could not update the blueprint.') +
					' (' +
					unmatched.length +
					' unmatched)';
			} else if (unmatched.length) {
				report.success = true;
				report.type = 'info';
				report.message =
					i18n('savePartial', 'Saved with some unmatched elements.') +
					' ' +
					merge.applied +
					' updated, ' +
					unmatched.length +
					' skipped.';
			} else {
				report.success = true;
				report.type = 'success';
				report.message =
					i18n('saveSuccess', 'Blueprint updated in memory. Ready for GitHub push.') +
					' (' +
					merge.applied +
					')';
			}

			report.blueprint = next;
			report.result = {
				applied: merge.applied,
				appliedIds: merge.appliedIds,
				unmatched: unmatched,
				draftCount: drafts.length,
			};

			state.lastSave = { type: report.type, message: report.message, result: report.result };
			showSaveNotice(report.type, report.message);

			dispatch('save', {
				success: report.success,
				message: report.message,
				blueprint: deepClone(next),
				result: report.result,
				source: opts.source || 'manual',
			});

			return report;
		} catch (err) {
			report.success = false;
			report.type = 'error';
			report.message = i18n('saveError', 'Could not update the blueprint.');
			state.lastSave = { type: report.type, message: report.message };
			showSaveNotice('error', report.message);
			dispatch('save', { success: false, message: report.message, error: String(err) });
			return report;
		} finally {
			if (saveBtn) {
				saveBtn.disabled = false;
				saveBtn.textContent = i18n('saveChanges', 'Save Changes');
			}
		}
	}

	/**
	 * Public accessor for the in-memory updated blueprint (post-Save).
	 */
	function getUpdatedBlueprint() {
		if (state.updatedBlueprint) {
			return deepClone(state.updatedBlueprint);
		}
		if (state.baseBlueprint) {
			return deepClone(state.baseBlueprint);
		}
		return deepClone(config.blueprintData || {}) || {};
	}

	/**
	 * Original blueprint as loaded from the server.
	 */
	function getBaseBlueprint() {
		return deepClone(state.baseBlueprint || config.blueprintData || {}) || {};
	}

	function buildFieldRow(field, draft) {
		var wrap = document.createElement('div');
		wrap.className = 'promptweb-editor-field';
		wrap.setAttribute('data-field-key', field.key);

		var id = 'promptweb-field-' + field.key;
		var label = document.createElement('label');
		label.className = 'promptweb-editor-field__label';
		label.setAttribute('for', id);
		label.textContent = field.label;

		var value = '';
		if (field.setting) {
			value = (draft.settings && draft.settings[field.key]) || '';
		} else {
			value = draft[field.key] != null ? draft[field.key] : '';
		}

		var control;

		if (field.kind === 'textarea') {
			control = document.createElement('textarea');
			control.rows = 4;
			control.value = value;
		} else if (field.kind === 'color') {
			// Color + text for free-form values (rgb, hex, named).
			var colorWrap = document.createElement('div');
			colorWrap.className = 'promptweb-editor-field__color-row';

			var picker = document.createElement('input');
			picker.type = 'color';
			picker.className = 'promptweb-editor-field__color-picker';
			picker.value = toColorPickerValue(value);

			control = document.createElement('input');
			control.type = 'text';
			control.className = 'promptweb-editor-field__input';
			control.value = value;
			control.placeholder = field.placeholder || '#000000';

			picker.addEventListener('input', function () {
				control.value = picker.value;
				control.dispatchEvent(new Event('input', { bubbles: true }));
			});

			colorWrap.appendChild(picker);
			colorWrap.appendChild(control);

			wrap.appendChild(label);
			wrap.appendChild(colorWrap);

			control.id = id;
			control.setAttribute('data-promptweb-field', field.key);
			control.setAttribute('data-setting', field.setting ? '1' : '0');
			control.setAttribute('data-kind', field.kind);
			return wrap;
		} else {
			control = document.createElement('input');
			control.type = field.kind === 'url' ? 'text' : field.kind || 'text';
			control.value = value;
			if (field.placeholder) {
				control.placeholder = field.placeholder;
			}
		}

		control.id = id;
		control.className =
			(control.className ? control.className + ' ' : '') +
			(field.kind === 'textarea'
				? 'promptweb-editor-field__textarea'
				: 'promptweb-editor-field__input');
		control.setAttribute('data-promptweb-field', field.key);
		control.setAttribute('data-setting', field.setting ? '1' : '0');
		control.setAttribute('data-kind', field.kind || 'text');
		control.autocomplete = 'off';
		control.spellcheck = field.key === 'content';

		wrap.appendChild(label);
		wrap.appendChild(control);
		return wrap;
	}

	function toColorPickerValue(value) {
		value = (value || '').trim();
		if (/^#[0-9a-fA-F]{6}$/.test(value)) {
			return value;
		}
		if (/^#[0-9a-fA-F]{3}$/.test(value)) {
			return (
				'#' +
				value
					.slice(1)
					.split('')
					.map(function (c) {
						return c + c;
					})
					.join('')
			);
		}
		return '#000000';
	}

	/**
	 * Handle a field change: update draft + live DOM.
	 */
	function onFieldChange(input) {
		if (!state.selectedEl || !state.draft) {
			return;
		}

		var key = input.getAttribute('data-promptweb-field');
		var isSetting = input.getAttribute('data-setting') === '1';
		var value = input.value;

		if (isSetting) {
			state.draft.settings = state.draft.settings || {};
			state.draft.settings[key] = value;
		} else {
			state.draft[key] = value;
		}

		applyDraftToElement(state.selectedEl, state.draft, key);
		persistDraftOnElement(state.selectedEl, state.draft);

		dispatch('draftchange', {
			key: key,
			value: value,
			isSetting: isSetting,
			draft: cloneDraft(state.draft),
			element: state.selectedEl,
			state: getPublicState(),
		});
	}

	function cloneDraft(draft) {
		try {
			return JSON.parse(JSON.stringify(draft));
		} catch (e) {
			return draft;
		}
	}

	/**
	 * Store draft on the element for later JSON export / save pipeline.
	 */
	function persistDraftOnElement(el, draft) {
		if (!el || !draft) {
			return;
		}
		try {
			el.setAttribute('data-promptweb-draft', JSON.stringify(draft));
			el.setAttribute('data-promptweb-dirty', '1');
		} catch (e) {
			// ignore quota / serialization issues
		}
	}

	/**
	 * Apply draft values to the live DOM (visual only).
	 *
	 * @param {Element} el
	 * @param {object} draft
	 * @param {string} [changedKey] Optional — which field changed (optimise later).
	 */
	function applyDraftToElement(el, draft, changedKey) {
		if (!el || !draft) {
			return;
		}

		var type = normalizeType(draft.type);
		var settings = draft.settings || {};

		// --- Content ---
		if (!changedKey || changedKey === 'content') {
			applyContent(el, type, draft.content);
		}

		// --- URL ---
		if (!changedKey || changedKey === 'url') {
			applyUrl(el, type, draft.url);
		}

		// --- Alt ---
		if (!changedKey || changedKey === 'alt') {
			applyAlt(el, draft.alt);
		}

		// --- Settings → inline styles ---
		applySettingsStyles(el, settings);
	}

	function applyContent(el, type, content) {
		content = content == null ? '' : String(content);
		type = normalizeType(type);

		if (type === 'image' || type === 'img' || type === 'spacer' || type === 'space') {
			return;
		}

		if (type === 'button' || el.tagName === 'A') {
			// Preserve child structure if any; prefer textContent for safety.
			el.textContent = content;
			return;
		}

		if (type === 'html' || type === 'custom_html' || type === 'raw') {
			// Manual edit: treat as text-safe HTML only via text for now if user
			// pastes tags — allow basic innerHTML for html type intentionally.
			el.innerHTML = content;
			return;
		}

		if (type === 'heading' || /^h[1-6]$/.test(type) || type === 'text' || type === 'paragraph' || type === 'p') {
			el.textContent = content;
			return;
		}

		// Unknown / AI containers: update content slot if present.
		var slot = el.querySelector(':scope > .promptweb-element__content');
		if (slot) {
			slot.textContent = content;
			return;
		}

		// If element has no nested editables, set textContent.
		var nested = el.querySelector('[data-promptweb-editable="1"]');
		if (!nested) {
			el.textContent = content;
		}
	}

	function applyUrl(el, type, url) {
		url = url == null ? '' : String(url);
		type = normalizeType(type);

		if (type === 'image' || type === 'img' || el.tagName === 'IMG') {
			var img = el.tagName === 'IMG' ? el : el.querySelector('img');
			if (img) {
				if (url) {
					img.setAttribute('src', url);
				}
			} else if (url && el.tagName === 'IMG') {
				el.setAttribute('src', url);
			}
			return;
		}

		if (type === 'button' || el.tagName === 'A') {
			if (url) {
				el.setAttribute('href', url);
			} else {
				el.removeAttribute('href');
			}
			return;
		}

		var link = el.querySelector('a.promptweb-button, a');
		if (link) {
			if (url) {
				link.setAttribute('href', url);
			} else {
				link.removeAttribute('href');
			}
		}
	}

	function applyAlt(el, alt) {
		alt = alt == null ? '' : String(alt);
		var img = el.tagName === 'IMG' ? el : el.querySelector('img');
		if (img) {
			img.setAttribute('alt', alt);
		}
	}

	/**
	 * Map draft.settings keys → element.style
	 */
	function applySettingsStyles(el, settings) {
		if (!el || !settings) {
			return;
		}

		var map = {
			color: 'color',
			background: 'backgroundColor',
			background_color: 'backgroundColor',
			font_size: 'fontSize',
			padding: 'padding',
			margin: 'margin',
			border_radius: 'borderRadius',
			height: 'height',
		};

		Object.keys(map).forEach(function (key) {
			if (!Object.prototype.hasOwnProperty.call(settings, key)) {
				return;
			}
			var cssKey = map[key];
			var val = settings[key];
			if (val === '' || val == null) {
				el.style[cssKey] = '';
			} else {
				el.style[cssKey] = String(val);
			}
		});
	}

	// -------------------------------------------------------------------------
	// Boot / chrome (toolbar, selection, AI placeholder)
	// -------------------------------------------------------------------------

	function init() {
		if (!config.canEdit) {
			return;
		}

		dom.root = $('#promptweb-editor-root');
		if (!dom.root) {
			return;
		}

		dom.root.hidden = false;

		initBlueprintState();
		cacheDom();
		bindToolbar();
		bindSelection();
		bindPanel();
		setMode(state.mode, { silent: true });
		updateSelectionLabel();

		// Empty manual form until selection.
		renderManualForm();

		dispatch('ready', {
			config: config,
			state: getPublicState(),
			blueprint: getUpdatedBlueprint(),
		});
	}

	function cacheDom() {
		var sel = getSelectors();
		dom.toolbar = $(sel.toolbar) || $('#promptweb-editor-toolbar');
		dom.panel = $(sel.panel) || $('#promptweb-editor-panel');
		dom.panelTitle = $('#promptweb-editor-panel-title');
		dom.selectionLabel = $('#promptweb-editor-selection-label');
		dom.modeButtons = $$('[data-promptweb-mode]', dom.root);
		dom.panelManual = $('#promptweb-editor-panel-manual');
		dom.panelAi = $('#promptweb-editor-panel-ai');
		dom.manualFields = $('#promptweb-editor-manual-fields');
	}

	function setMode(mode, opts) {
		opts = opts || {};
		if (mode !== MODE_MANUAL && mode !== MODE_AI) {
			mode = MODE_MANUAL;
		}

		state.mode = mode;

		document.body.classList.remove('promptweb-editor-mode-' + MODE_MANUAL);
		document.body.classList.remove('promptweb-editor-mode-' + MODE_AI);
		document.body.classList.add('promptweb-editor-mode-' + mode);

		if (dom.root) {
			dom.root.setAttribute('data-mode', mode);
		}

		dom.modeButtons.forEach(function (btn) {
			var isActive = btn.getAttribute('data-promptweb-mode') === mode;
			btn.classList.toggle('is-active', isActive);
			btn.setAttribute('aria-pressed', isActive ? 'true' : 'false');
		});

		if (dom.panelManual) {
			dom.panelManual.hidden = mode !== MODE_MANUAL;
		}
		if (dom.panelAi) {
			dom.panelAi.hidden = mode !== MODE_AI;
		}

		if (dom.panelTitle) {
			dom.panelTitle.textContent =
				mode === MODE_AI ? i18n('aiPrompt', 'AI Prompt') : i18n('manualEdit', 'Manual Edit');
		}

		// Hide placeholder copy once Manual form is real.
		var manualPlaceholder = dom.panelManual
			? $('.promptweb-editor-panel__placeholder', dom.panelManual)
			: null;
		var manualHint = dom.panelManual
			? $('.promptweb-editor-panel__hint', dom.panelManual)
			: null;
		if (manualPlaceholder) {
			manualPlaceholder.hidden = true;
		}
		if (manualHint) {
			manualHint.hidden = !!state.selectedEl;
		}

		if (!opts.silent) {
			dispatch('modechange', { mode: mode, state: getPublicState() });
		}
	}

	function bindToolbar() {
		if (!dom.toolbar) {
			return;
		}

		dom.modeButtons.forEach(function (btn) {
			btn.addEventListener('click', function () {
				var mode = btn.getAttribute('data-promptweb-mode') || MODE_MANUAL;
				setMode(mode);
				if (mode === MODE_MANUAL) {
					openManualEditPanel();
				} else if (mode === MODE_AI) {
					openAiPromptPanel();
				}
			});
		});
	}

	function bindSelection() {
		document.addEventListener(
			'click',
			function (event) {
				if (event.target.closest && event.target.closest('#promptweb-editor-root')) {
					return;
				}

				var editable = event.target.closest
					? event.target.closest(getSelectors().editable)
					: null;

				if (!editable) {
					clearSelection();
					return;
				}

				event.preventDefault();
				event.stopPropagation();
				selectElement(editable);
			},
			true
		);

		document.addEventListener('keydown', function (event) {
			if (event.key !== 'Enter' && event.key !== ' ') {
				return;
			}
			var el = document.activeElement;
			if (!el || !el.matches || !el.matches(getSelectors().editable)) {
				return;
			}
			if (el.closest && el.closest('#promptweb-editor-root')) {
				return;
			}
			// Don't steal space from form fields (none on page for editables usually).
			event.preventDefault();
			selectElement(el);
		});
	}

	function selectElement(el) {
		if (!el) {
			return;
		}

		clearSelection({ silent: true });

		el.classList.add('promptweb-editable--selected');
		state.selectedEl = el;
		state.selectedId =
			el.getAttribute('data-promptweb-editor-id') ||
			el.getAttribute('data-promptweb-id') ||
			null;
		state.selectedType = el.getAttribute('data-promptweb-type') || null;
		state.draft = buildDraftFromElement(el);
		persistDraftOnElement(el, state.draft);

		updateSelectionLabel();

		dispatch('select', {
			element: el,
			id: state.selectedId,
			type: state.selectedType,
			draft: cloneDraft(state.draft),
			state: getPublicState(),
		});

		if (state.mode === MODE_AI) {
			openAiPromptPanel();
		} else {
			openManualEditPanel();
		}
	}

	function clearSelection(opts) {
		opts = opts || {};
		$$(getSelectors().selected).forEach(function (node) {
			node.classList.remove('promptweb-editable--selected');
		});

		var had = !!state.selectedEl;
		state.selectedEl = null;
		state.selectedId = null;
		state.selectedType = null;
		state.draft = null;
		updateSelectionLabel();

		if (state.mode === MODE_MANUAL && state.panelOpen) {
			renderManualForm();
		}

		if (had && !opts.silent) {
			dispatch('deselect', { state: getPublicState() });
		}
	}

	function updateSelectionLabel() {
		if (!dom.selectionLabel) {
			return;
		}

		if (!state.selectedEl) {
			dom.selectionLabel.textContent =
				dom.selectionLabel.getAttribute('data-empty') ||
				i18n('noSelection', 'No element selected');
			dom.selectionLabel.classList.remove('has-selection');
			return;
		}

		var parts = [i18n('selected', 'Selected')];
		if (state.selectedType) {
			parts.push(state.selectedType);
		}
		if (state.selectedId) {
			parts.push('#' + state.selectedId);
		}

		dom.selectionLabel.textContent = parts.join(' · ');
		dom.selectionLabel.classList.add('has-selection');
	}

	function bindPanel() {
		if (!dom.panel) {
			return;
		}

		var closeBtn = $('[data-promptweb-panel-close]', dom.panel);
		if (closeBtn) {
			closeBtn.addEventListener('click', function () {
				closePanel();
			});
		}

		document.addEventListener('keydown', function (event) {
			if (event.key === 'Escape' && state.panelOpen) {
				// If focus is inside an input, still close — expected for panel.
				closePanel();
			}
		});
	}

	function openPanel() {
		if (!dom.panel) {
			return;
		}
		dom.panel.hidden = false;
		dom.panel.setAttribute('aria-hidden', 'false');
		state.panelOpen = true;
		dispatch('panelopen', { mode: state.mode, state: getPublicState() });
	}

	function closePanel() {
		if (!dom.panel) {
			return;
		}
		dom.panel.hidden = true;
		dom.panel.setAttribute('aria-hidden', 'true');
		state.panelOpen = false;
		dispatch('panelclose', { state: getPublicState() });
	}

	/**
	 * AI Prompt panel — placeholder only (not built yet).
	 */
	function openAiPromptPanel() {
		setMode(MODE_AI, { silent: true });
		openPanel();

		var fields = $('#promptweb-editor-ai-fields');
		if (fields) {
			fields.setAttribute('data-selected-id', state.selectedId || '');
			fields.setAttribute('data-selected-type', state.selectedType || '');
		}

		dispatch('open-ai-panel', {
			element: state.selectedEl,
			id: state.selectedId,
			type: state.selectedType,
			state: getPublicState(),
		});
	}

	function getPublicState() {
		return {
			mode: state.mode,
			selectedId: state.selectedId,
			selectedType: state.selectedType,
			panelOpen: state.panelOpen,
			hasSelection: !!state.selectedEl,
			draft: state.draft ? cloneDraft(state.draft) : null,
			dirty: !!(state.selectedEl && state.selectedEl.getAttribute('data-promptweb-dirty') === '1'),
			dirtyCount: $$('[data-promptweb-dirty="1"]').length,
			hasUpdatedBlueprint: !!state.updatedBlueprint,
			lastSave: state.lastSave,
		};
	}

	function dispatch(name, detail) {
		try {
			document.dispatchEvent(
				new CustomEvent('promptweb-editor:' + name, {
					detail: detail || {},
					bubbles: true,
				})
			);
		} catch (e) {
			// ignore
		}
	}

	window.PromptWebEditor = {
		getState: getPublicState,
		setMode: setMode,
		selectElement: selectElement,
		clearSelection: clearSelection,
		openManualEditPanel: openManualEditPanel,
		openAiPromptPanel: openAiPromptPanel,
		openPanel: openPanel,
		closePanel: closePanel,
		getDraft: function () {
			return state.draft ? cloneDraft(state.draft) : null;
		},
		collectDirtyDrafts: collectDirtyDrafts,
		saveChanges: saveChanges,
		getUpdatedBlueprint: getUpdatedBlueprint,
		getBaseBlueprint: getBaseBlueprint,
		renderManualForm: renderManualForm,
		config: config,
	};

	// Convenience alias for the next (GitHub push) step.
	window.promptwebUpdatedBlueprint = null;

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', init);
	} else {
		init();
	}
})();
