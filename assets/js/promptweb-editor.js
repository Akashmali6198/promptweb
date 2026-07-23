/**
 * PromptWeb Frontend Visual Editor
 * Maximum AI Creativity — Manual Edit + AI Prompt + Save/Push.
 *
 * Modes:
 *   - manual  → live field editing, Save Changes, Push to GitHub
 *   - ai      → write prompts into blueprint.prompts[] (status: pending),
 *               Save Prompt / Save & Push — external AI reads from the repo
 *
 * No external AI APIs are called from this plugin.
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
		/** Last save/push result summary. */
		lastSave: null,
		/**
		 * Push is enabled only after a successful in-memory Save
		 * (or when a pushable blueprint already exists from a prior save).
		 */
		pushEnabled: false,
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

	/**
	 * Query a direct child matching selector (:scope-safe for older browsers).
	 */
	function queryDirectChild(el, selector) {
		if (!el) {
			return null;
		}
		try {
			return el.querySelector(':scope > ' + selector);
		} catch (e) {
			var kids = el.children || [];
			for (var i = 0; i < kids.length; i++) {
				if (kids[i].matches && kids[i].matches(selector)) {
					return kids[i];
				}
			}
		}
		return null;
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
		var contentNode = queryDirectChild(el, '.promptweb-element__content');
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
			'Live preview updates as you type. Click Publish to save your design and update GitHub in one step.'
		);
		form.appendChild(note);

		// One-step publish (merge blueprint + push to GitHub).
		var saveBar = document.createElement('div');
		saveBar.className = 'promptweb-editor-save';
		saveBar.innerHTML =
			'<div class="promptweb-editor-save__actions">' +
			'<button type="button" class="promptweb-editor-save__btn promptweb-editor-save__btn--primary is-ready" data-promptweb-publish>' +
			escapeHtml(i18n('publishChanges', 'Publish')) +
			'</button>' +
			'</div>' +
			'<p class="promptweb-editor-save__hint">' +
			escapeHtml(
				i18n(
					'publishHint',
					'Publishes your edits to the live blueprint on GitHub. Design data is never deleted.'
				)
			) +
			'</p>' +
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

		var publishBtn = $('[data-promptweb-publish]', form);
		if (publishBtn) {
			publishBtn.addEventListener('click', function () {
				publishChanges({ source: 'manual-panel' });
			});
		}

		// Restore last save/push notice if still relevant.
		if (state.lastSave && state.lastSave.message) {
			showSaveNotice(state.lastSave.type || 'success', state.lastSave.message);
		}
	}

	/**
	 * Legacy no-op kept for API compatibility (push no longer gated separately).
	 */
	function updatePushButtonState() {
		// One-step Publish is always available when the form is open.
	}

	/**
	 * One-step publish: merge dirty drafts into blueprint JSON, then push to GitHub.
	 *
	 * @param {object} [opts]
	 * @returns {Promise<object>}
	 */
	function publishChanges(opts) {
		opts = opts || {};

		var publishBtn =
			(dom.manualForm && $('[data-promptweb-publish]', dom.manualForm)) ||
			$('[data-promptweb-publish]');

		function setBusy(busy, label) {
			if (!publishBtn) {
				return;
			}
			publishBtn.disabled = !!busy;
			publishBtn.setAttribute('aria-disabled', busy ? 'true' : 'false');
			publishBtn.textContent =
				label ||
				(busy
					? i18n('publishing', 'Publishing…')
					: i18n('publishChanges', 'Publish'));
			publishBtn.classList.toggle('is-publishing', !!busy);
		}

		setBusy(true, i18n('publishing', 'Publishing…'));
		showSaveNotice('info', i18n('publishing', 'Publishing…'));

		// 1) Merge local edits into blueprint JSON (never wipes design).
		var saveReport = saveChanges({ source: opts.source || 'publish' });
		if (saveReport && saveReport.success === false && saveReport.type === 'error') {
			setBusy(false);
			return Promise.resolve(saveReport);
		}

		// 2) Push to GitHub automatically.
		state.pushEnabled = true;
		return pushToGitHub({ source: opts.source || 'publish', force: true })
			.then(function (pushReport) {
				if (pushReport && pushReport.success) {
					// 3) Success state: Published
					var msg =
						i18n('published', 'Published') +
						' — ' +
						(pushReport.message ||
							i18n('publishSuccessDetail', 'Your changes are live on GitHub.'));
					state.lastSave = { type: 'success', message: msg, published: true };
					showSaveNotice('success', msg);

					// 4) Clear dirty/input state where appropriate.
					$$('[data-promptweb-dirty="1"]').forEach(function (node) {
						node.removeAttribute('data-promptweb-dirty');
					});
					if (dom.manualForm) {
						$$('[data-promptweb-field]', dom.manualForm).forEach(function (input) {
							input.blur();
						});
					}

					// Refresh draft snapshot from DOM so fields match published state.
					if (state.selectedEl) {
						state.draft = buildDraftFromElement(state.selectedEl);
						persistDraftOnElement(state.selectedEl, state.draft);
						if (state.selectedEl.getAttribute) {
							state.selectedEl.removeAttribute('data-promptweb-dirty');
						}
					}

					dispatch('publish', {
						success: true,
						message: msg,
						blueprint: getUpdatedBlueprint(),
						push: pushReport,
					});

					setBusy(false, i18n('published', 'Published'));
					// Soft reset label after a moment.
					setTimeout(function () {
						if (publishBtn && !publishBtn.classList.contains('is-publishing')) {
							publishBtn.textContent = i18n('publishChanges', 'Publish');
						}
					}, 2500);

					return { success: true, message: msg, push: pushReport };
				}

				var err =
					(pushReport && pushReport.message) ||
					i18n('publishError', 'Publish failed. Your local edits are still on this page.');
				showSaveNotice('error', err);
				setBusy(false);
				dispatch('publish', { success: false, message: err, push: pushReport });
				return { success: false, message: err, push: pushReport };
			})
			.catch(function (err) {
				var msg =
					i18n('publishError', 'Publish failed. Your local edits are still on this page.') +
					(err && err.message ? ' ' + err.message : '');
				showSaveNotice('error', msg);
				setBusy(false);
				return { success: false, message: msg };
			});
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

		if (notice) {
			dom.saveNotice = notice;
			notice.hidden = !message;
			notice.textContent = message || '';
			notice.className =
				'promptweb-editor-save__notice promptweb-editor-save__notice--' + (type || 'info');
			if (!message) {
				notice.className = 'promptweb-editor-save__notice';
			}
		}

		// Mirror into AI panel when that mode is active.
		if (state.mode === MODE_AI && typeof showAiNotice === 'function') {
			showAiNotice(type, message);
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
				report.message = i18n(
					'saveNoDirty',
					'No local changes to merge (using current blueprint).'
				);
				// Allow push of current in-memory blueprint even without new dirty drafts.
				var existing = getUpdatedBlueprint();
				state.pushEnabled = !!(existing && typeof existing === 'object' && Object.keys(existing).length);
				state.lastSave = { type: report.type, message: report.message };
				showSaveNotice('info', report.message);
				updatePushButtonState();
				dispatch('save', {
					success: true,
					noDirty: true,
					blueprint: getUpdatedBlueprint(),
				});
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
				state.pushEnabled = false;
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
				// Still enable push if anything was applied (or blueprint exists).
				state.pushEnabled = true;
			} else {
				report.success = true;
				report.type = 'success';
				report.message =
					i18n('saveSuccess', 'Blueprint updated in memory. You can push to GitHub.') +
					' (' +
					merge.applied +
					')';
				state.pushEnabled = true;
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
			updatePushButtonState();

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

	/**
	 * Resolve REST push endpoint URL.
	 */
	function getPushEndpoint() {
		if (config.endpoints && config.endpoints.push) {
			return config.endpoints.push;
		}
		if (config.restUrl) {
			return String(config.restUrl).replace(/\/?$/, '/') + 'push-blueprint';
		}
		return '/wp-json/promptweb/v1/push-blueprint';
	}

	/**
	 * Push updated blueprint JSON to GitHub via REST.
	 *
	 * POST /wp-json/promptweb/v1/push-blueprint
	 * Body: { blueprint: getUpdatedBlueprint(), message? }
	 * Auth: cookie session + X-WP-Nonce (wp_rest).
	 *
	 * Requires a successful Save first (pushEnabled). Still re-merges dirty drafts
	 * if the user edited after save.
	 *
	 * @param {object} [opts]
	 * @returns {Promise<object>}
	 */
	function pushToGitHub(opts) {
		opts = opts || {};

		var pushBtn =
			(dom.manualForm && $('[data-promptweb-push]', dom.manualForm)) ||
			$('[data-promptweb-push]');
		var saveBtn =
			(dom.manualForm && $('[data-promptweb-save]', dom.manualForm)) ||
			$('[data-promptweb-save]');

		function setBusy(busy) {
			if (pushBtn) {
				// While busy keep disabled; restore via updatePushButtonState after.
				pushBtn.disabled = true;
				pushBtn.setAttribute('aria-disabled', 'true');
				pushBtn.textContent = busy
					? i18n('pushing', 'Pushing…')
					: i18n('pushGithub', 'Push to GitHub');
			}
			if (saveBtn) {
				saveBtn.disabled = !!busy;
			}
		}

		if (!state.pushEnabled && !opts.force) {
			var needSave = i18n(
				'pushHint',
				'Save Changes first to prepare JSON, then push to GitHub.'
			);
			showSaveNotice('info', needSave);
			return Promise.resolve({ success: false, message: needSave, needsSave: true });
		}

		// Merge any dirty drafts into memory before push (does not break Save flow).
		var dirtyCount = $$('[data-promptweb-dirty="1"]').length;
		if (dirtyCount > 0 || (state.selectedEl && state.draft)) {
			var saveReport = saveChanges({ source: opts.source || 'before-push' });
			if (saveReport && saveReport.success === false) {
				updatePushButtonState();
				return Promise.resolve(saveReport);
			}
		}

		var blueprint = getUpdatedBlueprint();
		if (!blueprint || typeof blueprint !== 'object' || !Object.keys(blueprint).length) {
			var emptyMsg = i18n(
				'pushNoBlueprint',
				'Nothing to push. Save your edits first or sync a blueprint.'
			);
			showSaveNotice('error', emptyMsg);
			state.lastSave = { type: 'error', message: emptyMsg };
			updatePushButtonState();
			return Promise.resolve({ success: false, message: emptyMsg });
		}

		if (!Array.isArray(blueprint.pages)) {
			blueprint.pages = [];
		}

		setBusy(true);
		showSaveNotice('info', i18n('pushing', 'Pushing…'));

		var endpoint = getPushEndpoint();
		var headers = {
			'Content-Type': 'application/json',
			Accept: 'application/json',
		};
		// WordPress REST cookie auth nonce (required with credentials: same-origin).
		if (config.nonce) {
			headers['X-WP-Nonce'] = config.nonce;
		}

		var payload = {
			blueprint: blueprint,
			message: opts.message || '',
		};

		return fetch(endpoint, {
			method: 'POST',
			credentials: 'same-origin',
			headers: headers,
			body: JSON.stringify(payload),
		})
			.then(function (res) {
				return res
					.json()
					.then(function (data) {
						return { httpStatus: res.status, data: data };
					})
					.catch(function () {
						return {
							httpStatus: res.status,
							data: {
								success: false,
								message: i18n('pushError', 'Push to GitHub failed.'),
							},
						};
					});
			})
			.then(function (pack) {
				var data = pack.data || {};
				var ok = false;

				if (typeof data.success === 'boolean') {
					ok = data.success;
				} else {
					ok =
						pack.httpStatus >= 200 &&
						pack.httpStatus < 300 &&
						data.code === 'promptweb_push_success';
				}

				// WP_Error shape: { code, message, data: { status } }
				if (!ok && data.code && data.message && data.data && data.data.status) {
					ok = false;
				}

				if (ok) {
					var msg =
						data.message ||
						i18n('pushSuccess', 'Pushed to GitHub successfully.');

					if (data.data && data.data.blueprint && typeof data.data.blueprint === 'object') {
						state.updatedBlueprint = deepClone(data.data.blueprint);
						state.baseBlueprint = deepClone(data.data.blueprint);
						window.promptwebUpdatedBlueprint = state.updatedBlueprint;
						config.blueprintData = deepClone(data.data.blueprint);
					} else {
						state.baseBlueprint = deepClone(blueprint);
						state.updatedBlueprint = deepClone(blueprint);
						window.promptwebUpdatedBlueprint = state.updatedBlueprint;
					}

					$$('[data-promptweb-dirty="1"]').forEach(function (node) {
						node.removeAttribute('data-promptweb-dirty');
					});

					// Stay ready for another push of the same snapshot.
					state.pushEnabled = true;
					state.lastSave = {
						type: 'success',
						message: msg,
						push: true,
						data: data.data || null,
					};
					showSaveNotice('success', msg);
					dispatch('push', {
						success: true,
						message: msg,
						blueprint: getUpdatedBlueprint(),
						data: data.data || null,
					});
					return { success: true, message: msg, data: data };
				}

				var errMsg =
					(data && data.message) ||
					i18n('pushError', 'Push to GitHub failed.');
				state.lastSave = { type: 'error', message: errMsg, push: true };
				showSaveNotice('error', errMsg);
				dispatch('push', { success: false, message: errMsg, data: data });
				return { success: false, message: errMsg, data: data };
			})
			.catch(function (err) {
				var errMsg = i18n('pushError', 'Push to GitHub failed.');
				if (err && err.message) {
					errMsg += ' ' + err.message;
				}
				state.lastSave = { type: 'error', message: errMsg, push: true };
				showSaveNotice('error', errMsg);
				dispatch('push', { success: false, message: errMsg, error: String(err) });
				return { success: false, message: errMsg };
			})
			.finally(function () {
				if (pushBtn) {
					pushBtn.textContent = i18n('pushGithub', 'Push to GitHub');
				}
				if (saveBtn) {
					saveBtn.disabled = false;
				}
				updatePushButtonState();
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
		var slot = queryDirectChild(el, '.promptweb-element__content');
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

	// -------------------------------------------------------------------------
	// AI Prompt panel (save prompt into blueprint.prompts[], optional push)
	// -------------------------------------------------------------------------

	/**
	 * Open AI Prompt mode and render the prompt form.
	 */
	function openAiPromptPanel() {
		setMode(MODE_AI, { silent: true });
		openPanel();
		renderAiPromptForm();

		dispatch('open-ai-panel', {
			element: state.selectedEl,
			id: state.selectedId,
			type: state.selectedType,
			state: getPublicState(),
		});
	}

	/**
	 * Build the AI Prompt form into #promptweb-editor-ai-fields.
	 */
	function renderAiPromptForm() {
		var host = $('#promptweb-editor-ai-fields');
		if (!host) {
			return;
		}

		// Hide static placeholders in the AI panel shell.
		if (dom.panelAi) {
			var ph = $('.promptweb-editor-panel__placeholder', dom.panelAi);
			var hi = $('.promptweb-editor-panel__hint', dom.panelAi);
			if (ph) {
				ph.hidden = true;
			}
			if (hi) {
				hi.hidden = true;
			}
		}

		var targetId = state.selectedId || '';
		var targetType = state.selectedType || '';
		var scope = targetId || targetType ? 'element' : 'page';
		var pageMeta = resolveCurrentPageMeta();

		var targetLabel;
		if (scope === 'element') {
			targetLabel =
				(targetType || 'element') + (targetId ? ' · #' + targetId : '');
		} else {
			targetLabel = i18n('aiScopePage', 'Entire page / blueprint');
			if (pageMeta.slug) {
				targetLabel += ' · ' + pageMeta.slug;
			} else if (pageMeta.id) {
				targetLabel += ' · #' + pageMeta.id;
			}
		}

		host.innerHTML = '';
		host.setAttribute('data-selected-id', targetId);
		host.setAttribute('data-selected-type', targetType);
		host.setAttribute('data-prompt-scope', scope);

		var form = document.createElement('div');
		form.className = 'promptweb-ai-form';

		// Target summary.
		var targetBox = document.createElement('div');
		targetBox.className = 'promptweb-ai-form__target';
		targetBox.innerHTML =
			'<span class="promptweb-ai-form__target-label">' +
			escapeHtml(i18n('aiTarget', 'Target')) +
			'</span>' +
			'<span class="promptweb-ai-form__target-value">' +
			escapeHtml(targetLabel) +
			'</span>' +
			(scope === 'element'
				? '<span class="promptweb-editor-form__badge">' +
				  escapeHtml(normalizeType(targetType) || 'unknown') +
				  '</span>'
				: '<span class="promptweb-editor-form__badge promptweb-editor-form__badge--page">' +
				  escapeHtml(i18n('aiScopePageBadge', 'page')) +
				  '</span>');
		form.appendChild(targetBox);

		// Context note.
		var note = document.createElement('p');
		note.className = 'promptweb-ai-form__note';
		note.textContent = i18n(
			'aiContextNote',
			'Your prompt is saved into the blueprint JSON (prompts[]) with status “pending”. No AI runs inside WordPress — push to GitHub so an external AI can process it later.'
		);
		form.appendChild(note);

		// Textarea.
		var field = document.createElement('div');
		field.className = 'promptweb-ai-form__field';
		var label = document.createElement('label');
		label.className = 'promptweb-editor-field__label';
		label.setAttribute('for', 'promptweb-ai-prompt-text');
		label.textContent = i18n('aiPromptLabel', 'AI prompt');
		var textarea = document.createElement('textarea');
		textarea.id = 'promptweb-ai-prompt-text';
		textarea.className = 'promptweb-ai-form__textarea';
		textarea.rows = 6;
		textarea.placeholder = i18n(
			'aiPromptPlaceholder',
			'e.g. Rewrite this heading to be more confident and shorter…'
		);
		textarea.spellcheck = true;
		field.appendChild(label);
		field.appendChild(textarea);
		form.appendChild(field);

		// Actions.
		var actions = document.createElement('div');
		actions.className = 'promptweb-ai-form__actions';
		actions.innerHTML =
			'<button type="button" class="promptweb-editor-save__btn promptweb-editor-save__btn--primary is-ready" data-promptweb-ai-push>' +
			escapeHtml(i18n('aiPublishPrompt', 'Publish Prompt')) +
			'</button>' +
			'<button type="button" class="promptweb-editor-save__btn promptweb-editor-save__btn--secondary" data-promptweb-ai-save>' +
			escapeHtml(i18n('aiSavePrompt', 'Save Prompt (local only)')) +
			'</button>';
		form.appendChild(actions);

		// Notice.
		var notice = document.createElement('div');
		notice.className = 'promptweb-editor-save__notice';
		notice.setAttribute('data-promptweb-ai-notice', '1');
		notice.hidden = true;
		form.appendChild(notice);

		// Pending prompts list (from current blueprint).
		var pendingWrap = document.createElement('div');
		pendingWrap.className = 'promptweb-ai-form__pending';
		pendingWrap.setAttribute('data-promptweb-ai-pending', '1');
		form.appendChild(pendingWrap);

		host.appendChild(form);

		var saveBtn = $('[data-promptweb-ai-save]', form);
		var pushBtn = $('[data-promptweb-ai-push]', form);

		if (saveBtn) {
			saveBtn.addEventListener('click', function () {
				saveAiPrompt({ push: false });
			});
		}
		if (pushBtn) {
			pushBtn.addEventListener('click', function () {
				saveAiPrompt({ push: true });
			});
		}

		renderAiPendingList(pendingWrap);
	}

	/**
	 * Show success/error in the AI panel notice area.
	 */
	function showAiNotice(type, message) {
		var notice =
			$('[data-promptweb-ai-notice]') ||
			(dom.panelAi && $('[data-promptweb-ai-notice]', dom.panelAi));
		if (!notice) {
			return;
		}
		notice.hidden = !message;
		notice.textContent = message || '';
		notice.className =
			'promptweb-editor-save__notice promptweb-editor-save__notice--' + (type || 'info');
		if (!message) {
			notice.className = 'promptweb-editor-save__notice';
		}
	}

	/**
	 * Best-effort page id/slug from rendered DOM or blueprint.
	 */
	function resolveCurrentPageMeta() {
		var meta = { id: '', slug: '', title: '' };
		var pageEl = $('.promptweb-page');
		if (pageEl) {
			meta.id = pageEl.getAttribute('data-promptweb-page-id') || '';
			meta.slug = pageEl.getAttribute('data-promptweb-page-slug') || '';
			meta.title = pageEl.getAttribute('data-promptweb-page-title') || '';
		}
		if (!meta.id && !meta.slug) {
			var bp = getUpdatedBlueprint();
			if (bp && Array.isArray(bp.pages) && bp.pages.length) {
				var front = null;
				for (var i = 0; i < bp.pages.length; i++) {
					if (bp.pages[i] && bp.pages[i].is_front_page) {
						front = bp.pages[i];
						break;
					}
				}
				var p = front || bp.pages[0];
				if (p) {
					meta.id = p.id ? String(p.id) : '';
					meta.slug = p.slug ? String(p.slug) : '';
					meta.title = p.title ? String(p.title) : '';
				}
			}
		}
		return meta;
	}

	/**
	 * Generate a stable-enough unique prompt id.
	 */
	function generatePromptId() {
		return (
			'prompt-' +
			Date.now().toString(36) +
			'-' +
			Math.random().toString(36).slice(2, 8)
		);
	}

	/**
	 * Append a pending prompt into blueprint.prompts[] (in memory).
	 *
	 * @param {object} opts { push?: boolean }
	 * @returns {object} report
	 */
	function saveAiPrompt(opts) {
		opts = opts || {};
		var textarea = $('#promptweb-ai-prompt-text');
		var text = textarea ? String(textarea.value || '').trim() : '';

		if (!text) {
			var emptyMsg = i18n('aiPromptEmpty', 'Please enter a prompt before saving.');
			showAiNotice('error', emptyMsg);
			return { success: false, message: emptyMsg };
		}

		// Ensure we have a blueprint base (from server or previous saves).
		if (!state.baseBlueprint || typeof state.baseBlueprint !== 'object') {
			initBlueprintState();
		}

		var base = getUpdatedBlueprint();
		if (!base || typeof base !== 'object' || !Object.keys(base).length) {
			// Allow creating a minimal blueprint shell so prompts can still be stored.
			base = {
				version: '1.0',
				site: {},
				pages: [],
				prompts: [],
			};
		}

		var next = deepClone(base);
		if (!next) {
			var err = i18n('aiSaveError', 'Could not save the prompt into the blueprint.');
			showAiNotice('error', err);
			return { success: false, message: err };
		}

		if (!Array.isArray(next.prompts)) {
			next.prompts = [];
		}
		if (!Array.isArray(next.pages)) {
			next.pages = Array.isArray(base.pages) ? deepClone(base.pages) || [] : [];
		}

		var pageMeta = resolveCurrentPageMeta();
		var targetId = state.selectedId || '';
		var targetType = state.selectedType ? normalizeType(state.selectedType) : '';
		var scope = targetId || targetType ? 'element' : 'page';

		var entry = {
			id: generatePromptId(),
			target_id: targetId,
			target_type: targetType || (scope === 'page' ? 'page' : 'unknown'),
			prompt: text,
			status: 'pending',
			created: new Date().toISOString(),
			scope: scope,
			page_id: pageMeta.id || '',
			page_slug: pageMeta.slug || '',
		};

		/**
		 * Optional hook for extensions (document-level custom event only —
		 * filters stay on PHP side).
		 */
		next.prompts.push(entry);

		// Persist in editor state (same as Manual Save output).
		state.updatedBlueprint = next;
		window.promptwebUpdatedBlueprint = next;
		state.pushEnabled = true;
		state.lastSave = {
			type: 'success',
			message: i18n('aiSaveSuccess', 'Prompt saved into blueprint JSON (pending).'),
			aiPrompt: entry,
		};

		// Clear textarea after successful save.
		if (textarea) {
			textarea.value = '';
		}

		updatePushButtonState();
		renderAiPendingList($('[data-promptweb-ai-pending]'));

		showAiNotice(
			'success',
			i18n('aiSaveSuccess', 'Prompt saved into blueprint JSON (pending).') +
				' #' +
				entry.id
		);

		dispatch('ai-prompt-save', {
			success: true,
			entry: entry,
			blueprint: deepClone(next),
			state: getPublicState(),
		});

		if (opts.push) {
			// One-step: save prompt then publish blueprint to GitHub.
			return pushToGitHub({ source: 'ai-prompt', force: true }).then(function (pushReport) {
				if (pushReport && pushReport.success) {
					var okMsg =
						i18n('published', 'Published') +
						' — ' +
						(pushReport.message ||
							i18n('aiPushSuccess', 'Prompt published to GitHub for external AI.'));
					showAiNotice('success', okMsg);
					if (textarea) {
						textarea.value = '';
					}
				} else if (pushReport && pushReport.message) {
					// Prompt is still in JSON even if push failed.
					showAiNotice(
						'error',
						i18n('aiSaveSuccess', 'Prompt saved into blueprint JSON (pending).') +
							' ' +
							(pushReport.message || i18n('pushError', 'Push to GitHub failed.'))
					);
				}
				return {
					success: !!(pushReport && pushReport.success),
					saved: true,
					entry: entry,
					push: pushReport,
					blueprint: getUpdatedBlueprint(),
				};
			});
		}

		return {
			success: true,
			saved: true,
			entry: entry,
			blueprint: getUpdatedBlueprint(),
		};
	}

	/**
	 * List pending prompts currently in the in-memory blueprint.
	 */
	function renderAiPendingList(container) {
		if (!container) {
			return;
		}

		var bp = getUpdatedBlueprint();
		var prompts = bp && Array.isArray(bp.prompts) ? bp.prompts : [];
		var pending = prompts.filter(function (p) {
			return p && (p.status === 'pending' || !p.status);
		});

		if (!pending.length) {
			container.innerHTML =
				'<p class="promptweb-ai-form__pending-empty">' +
				escapeHtml(i18n('aiNoPending', 'No pending prompts in the blueprint yet.')) +
				'</p>';
			return;
		}

		var title =
			'<h3 class="promptweb-ai-form__pending-title">' +
			escapeHtml(i18n('aiPendingTitle', 'Pending prompts')) +
			' (' +
			pending.length +
			')</h3>';

		var items = pending
			.slice()
			.reverse()
			.slice(0, 8)
			.map(function (p) {
				var meta = [];
				if (p.target_type) {
					meta.push(String(p.target_type));
				}
				if (p.target_id) {
					meta.push('#' + String(p.target_id));
				}
				if (p.scope === 'page') {
					meta.push('page');
				}
				var preview = String(p.prompt || '').slice(0, 80);
				if (String(p.prompt || '').length > 80) {
					preview += '…';
				}
				return (
					'<li class="promptweb-ai-form__pending-item">' +
					'<span class="promptweb-ai-form__pending-status">pending</span>' +
					'<span class="promptweb-ai-form__pending-meta">' +
					escapeHtml(meta.join(' · ') || p.id || '') +
					'</span>' +
					'<span class="promptweb-ai-form__pending-text">' +
					escapeHtml(preview) +
					'</span>' +
					'</li>'
				);
			})
			.join('');

		container.innerHTML = title + '<ul class="promptweb-ai-form__pending-list">' + items + '</ul>';
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
			pushEnabled: !!state.pushEnabled,
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
		publishChanges: publishChanges,
		pushToGitHub: pushToGitHub,
		getUpdatedBlueprint: getUpdatedBlueprint,
		getBaseBlueprint: getBaseBlueprint,
		renderManualForm: renderManualForm,
		renderAiPromptForm: renderAiPromptForm,
		saveAiPrompt: saveAiPrompt,
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
