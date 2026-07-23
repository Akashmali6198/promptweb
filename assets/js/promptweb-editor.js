/**
 * PromptWeb Frontend Visual Editor
 * Maximum AI Creativity — Manual Edit panel + selection foundation.
 *
 * Modes:
 *   - manual  → Manual Edit (live field editing of selected elements)
 *   - ai      → AI Prompt (placeholder only; not built yet)
 *
 * Live updates are visual-only for now. Draft values are kept on state.draft
 * and data-promptweb-draft so a later step can write JSON / push to GitHub.
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
		/** Draft model ready for future JSON persistence. */
		draft: null,
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
			'Changes update the page live. Saving to JSON / GitHub comes next.'
		);
		form.appendChild(note);

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

		cacheDom();
		bindToolbar();
		bindSelection();
		bindPanel();
		setMode(state.mode, { silent: true });
		updateSelectionLabel();

		// Empty manual form until selection.
		renderManualForm();

		dispatch('ready', { config: config, state: getPublicState() });
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

	/**
	 * Collect all dirty element drafts from the page (future save pipeline).
	 */
	function collectDirtyDrafts() {
		var nodes = $$('[data-promptweb-dirty="1"][data-promptweb-draft]');
		return nodes.map(function (node) {
			try {
				return JSON.parse(node.getAttribute('data-promptweb-draft') || '{}');
			} catch (e) {
				return null;
			}
		}).filter(Boolean);
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
		renderManualForm: renderManualForm,
		config: config,
	};

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', init);
	} else {
		init();
	}
})();
