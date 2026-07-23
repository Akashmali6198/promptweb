/**
 * PromptWeb Frontend Visual Editor
 * Maximum AI Creativity — foundation (selection + mode panels).
 *
 * Modes:
 *   - manual  → Manual Edit (click elements, future property panel)
 *   - ai      → AI Prompt (future prompt UI → JSON + GitHub push)
 *
 * Config is injected as window.promptwebEditor via wp_localize_script.
 * Public visitors never load this file.
 */
(function () {
	'use strict';

	var config = window.promptwebEditor || {};
	var MODE_MANUAL = 'manual';
	var MODE_AI = 'ai';

	/**
	 * Editor application state.
	 * Expand carefully; keep modes explicit for future panels.
	 */
	var state = {
		mode: config.defaultMode || MODE_MANUAL,
		selectedEl: null,
		selectedId: null,
		selectedType: null,
		panelOpen: false,
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
	};

	/**
	 * Safe query helpers.
	 */
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

	/**
	 * Boot after DOM is ready.
	 */
	function init() {
		if (!config.canEdit) {
			return;
		}

		dom.root = $('#promptweb-editor-root');
		if (!dom.root) {
			return;
		}

		// Reveal shell (PHP prints it with hidden for no-JS fallback).
		dom.root.hidden = false;

		cacheDom();
		bindToolbar();
		bindSelection();
		bindPanel();
		setMode(state.mode, { silent: true });
		updateSelectionLabel();

		/**
		 * Custom event for extensions.
		 * document.addEventListener('promptweb-editor:ready', ...)
		 */
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
	}

	// -------------------------------------------------------------------------
	// Modes: Manual Edit | AI Prompt
	// -------------------------------------------------------------------------

	/**
	 * Switch editor mode. Structure is ready for full panels later.
	 *
	 * @param {string} mode  'manual' | 'ai'
	 * @param {object} [opts]
	 */
	function setMode(mode, opts) {
		opts = opts || {};
		if (mode !== MODE_MANUAL && mode !== MODE_AI) {
			mode = MODE_MANUAL;
		}

		state.mode = mode;

		// Body class for CSS hooks.
		document.body.classList.remove('promptweb-editor-mode-' + MODE_MANUAL);
		document.body.classList.remove('promptweb-editor-mode-' + MODE_AI);
		document.body.classList.add('promptweb-editor-mode-' + mode);

		if (dom.root) {
			dom.root.setAttribute('data-mode', mode);
		}

		// Toolbar buttons.
		dom.modeButtons.forEach(function (btn) {
			var isActive = btn.getAttribute('data-promptweb-mode') === mode;
			btn.classList.toggle('is-active', isActive);
			btn.setAttribute('aria-pressed', isActive ? 'true' : 'false');
		});

		// Panel mode panes.
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

				// Opening a mode should show its panel foundation.
				if (mode === MODE_MANUAL) {
					openManualEditPanel();
				} else if (mode === MODE_AI) {
					openAiPromptPanel();
				}
			});
		});
	}

	// -------------------------------------------------------------------------
	// Element selection
	// -------------------------------------------------------------------------

	function bindSelection() {
		// Use capture so we win over nested interactive content during edit mode.
		document.addEventListener(
			'click',
			function (event) {
				// Ignore clicks inside the editor chrome.
				if (event.target.closest && event.target.closest('#promptweb-editor-root')) {
					return;
				}

				var editable = event.target.closest
					? event.target.closest(getSelectors().editable)
					: null;

				if (!editable) {
					// Click outside: clear selection but keep toolbar.
					clearSelection();
					return;
				}

				event.preventDefault();
				event.stopPropagation();
				selectElement(editable);
			},
			true
		);

		// Keyboard: Enter/Space on focused editable.
		document.addEventListener('keydown', function (event) {
			if (event.key !== 'Enter' && event.key !== ' ') {
				return;
			}
			var el = document.activeElement;
			if (!el || !el.matches || !el.matches(getSelectors().editable)) {
				return;
			}
			// Don't trap when focus is inside editor chrome.
			if (el.closest && el.closest('#promptweb-editor-root')) {
				return;
			}
			event.preventDefault();
			selectElement(el);
		});
	}

	/**
	 * Mark an element as selected and open the active mode panel.
	 *
	 * @param {Element} el
	 */
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

		updateSelectionLabel();

		dispatch('select', {
			element: el,
			id: state.selectedId,
			type: state.selectedType,
			state: getPublicState(),
		});

		// Open the panel for the current mode (placeholders for now).
		if (state.mode === MODE_AI) {
			openAiPromptPanel();
		} else {
			openManualEditPanel();
		}
	}

	/**
	 * Clear the current selection.
	 *
	 * @param {object} [opts]
	 */
	function clearSelection(opts) {
		opts = opts || {};
		$$(getSelectors().selected).forEach(function (node) {
			node.classList.remove('promptweb-editable--selected');
		});

		var had = !!state.selectedEl;
		state.selectedEl = null;
		state.selectedId = null;
		state.selectedType = null;
		updateSelectionLabel();

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
				dom.selectionLabel.getAttribute('data-empty') || i18n('noSelection', 'No element selected');
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

	// -------------------------------------------------------------------------
	// Panels (placeholders — expand in later steps)
	// -------------------------------------------------------------------------

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

		// Escape closes panel.
		document.addEventListener('keydown', function (event) {
			if (event.key === 'Escape' && state.panelOpen) {
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
	 * Placeholder: open Manual Edit panel.
	 * Future: content + settings form bound to selected element / JSON node.
	 */
	function openManualEditPanel() {
		setMode(MODE_MANUAL, { silent: true });
		openPanel();

		// Hook for future field injection.
		var fields = $('#promptweb-editor-manual-fields');
		if (fields && state.selectedEl) {
			// Keep empty for now — structure ready for expansion.
			fields.setAttribute('data-selected-id', state.selectedId || '');
			fields.setAttribute('data-selected-type', state.selectedType || '');
		}

		dispatch('open-manual-panel', {
			element: state.selectedEl,
			id: state.selectedId,
			type: state.selectedType,
			state: getPublicState(),
		});
	}

	/**
	 * Placeholder: open AI Prompt panel.
	 * Future: textarea + save prompt into blueprint.prompts[] + push GitHub.
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

	// -------------------------------------------------------------------------
	// Public API + events
	// -------------------------------------------------------------------------

	function getPublicState() {
		return {
			mode: state.mode,
			selectedId: state.selectedId,
			selectedType: state.selectedType,
			panelOpen: state.panelOpen,
			hasSelection: !!state.selectedEl,
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
			// IE / older — ignore; foundation targets modern browsers.
		}
	}

	// Expose a small API for future modules / PHP-injected extensions.
	window.PromptWebEditor = {
		getState: getPublicState,
		setMode: setMode,
		selectElement: selectElement,
		clearSelection: clearSelection,
		openManualEditPanel: openManualEditPanel,
		openAiPromptPanel: openAiPromptPanel,
		openPanel: openPanel,
		closePanel: closePanel,
		config: config,
	};

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', init);
	} else {
		init();
	}
})();
