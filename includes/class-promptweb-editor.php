<?php
/**
 * Frontend Visual Editor foundation.
 *
 * Maximum AI Creativity:
 * - Structured JSON in GitHub is the single source of truth (not Gutenberg).
 * - Logged-in users with edit capability get an on-page visual editor.
 * - Modes (structure only for now):
 *     1. Manual Edit — select elements, edit content/settings, live update + push JSON.
 *     2. AI Prompt   — save a prompt in JSON + push to GitHub (AI runs externally).
 * - Unknown / AI-invented element types remain editable via data-promptweb-* hooks.
 *
 * Multisite: capability checks and URLs run in the current blog context.
 * Public visitors never receive editor assets, toolbar, or editable chrome.
 *
 * @package PromptWeb
 * @since   1.0.0
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Frontend visual editor bootstrap (Multisite-aware).
 *
 * @since 1.0.0
 */
class PromptWeb_Editor {

	/**
	 * Capability required to use the frontend editor on a site.
	 *
	 * Multisite: evaluated in the current blog context (per-site editing).
	 *
	 * @since 1.0.0
	 * @var   string
	 */
	const CAPABILITY = 'edit_pages';

	/**
	 * Editor mode identifiers (client + server share these keys).
	 *
	 * @since 1.0.0
	 * @var   string
	 */
	const MODE_MANUAL = 'manual';

	/**
	 * AI Prompt mode key.
	 *
	 * @since 1.0.0
	 * @var   string
	 */
	const MODE_AI = 'ai';

	/**
	 * Whether the editor has been bootstrapped for this request.
	 *
	 * @since 1.0.0
	 * @var   bool
	 */
	protected $active = false;

	/**
	 * Register frontend editor hooks.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public function init() {
		// Never load editor chrome inside wp-admin / network admin.
		if ( is_admin() ) {
			return;
		}

		add_action( 'wp', array( $this, 'maybe_bootstrap' ) );

		/**
		 * Fires when the frontend editor component is initialized
		 * (before capability checks — always on front-end requests).
		 *
		 * @since 1.0.0
		 * @param PromptWeb_Editor $editor This instance.
		 */
		do_action( 'promptweb_editor_init', $this );
	}

	/**
	 * Whether the editor is active for the current front-end request.
	 *
	 * @since 1.0.0
	 * @return bool
	 */
	public function is_active() {
		return (bool) $this->active;
	}

	/**
	 * After the main query is set, decide whether to load editor chrome.
	 *
	 * Visitors and users without capability get zero editor output.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public function maybe_bootstrap() {
		if ( ! $this->current_user_can_edit() ) {
			return;
		}

		/**
		 * Filters whether the frontend editor should boot on this request.
		 *
		 * @since 1.0.0
		 * @param bool             $enabled Default true when the user can edit.
		 * @param PromptWeb_Editor $editor  This instance.
		 */
		$enabled = (bool) apply_filters( 'promptweb_editor_enabled', true, $this );

		if ( ! $enabled ) {
			return;
		}

		$this->active = true;

		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_filter( 'body_class', array( $this, 'filter_body_class' ) );
		add_action( 'wp_footer', array( $this, 'render_editor_shell' ), 5 );

		// Enrich Renderer output so elements can be selected (editors only).
		add_filter( 'promptweb_element_classes', array( $this, 'filter_element_classes' ), 10, 4 );
		add_filter( 'promptweb_element_attrs', array( $this, 'filter_element_attrs' ), 10, 4 );
		add_filter( 'promptweb_section_classes', array( $this, 'filter_section_classes' ), 10, 3 );
		add_filter( 'promptweb_section_attrs', array( $this, 'filter_section_attrs' ), 10, 2 );
		add_filter( 'promptweb_page_classes', array( $this, 'filter_page_classes' ), 10, 2 );

		/**
		 * Fires when the frontend editor is active for the current request.
		 *
		 * @since 1.0.0
		 * @param PromptWeb_Editor $editor This instance.
		 */
		do_action( 'promptweb_editor_boot', $this );
	}

	/**
	 * Whether the current user may use the visual editor on this site.
	 *
	 * @since 1.0.0
	 * @return bool
	 */
	public function current_user_can_edit() {
		if ( ! is_user_logged_in() ) {
			return false;
		}

		return current_user_can( $this->get_capability() );
	}

	/**
	 * Capability string for frontend editing (filterable).
	 *
	 * @since 1.0.0
	 * @return string
	 */
	public function get_capability() {
		/**
		 * Filters the capability required for the frontend visual editor.
		 *
		 * Multisite: still evaluated with current_user_can() on the current blog.
		 *
		 * @since 1.0.0
		 * @param string $capability Default edit_pages.
		 */
		return (string) apply_filters( 'promptweb_editor_capability', self::CAPABILITY );
	}

	/**
	 * Add body classes when the editor is active.
	 *
	 * @since 1.0.0
	 * @param string[] $classes Body classes.
	 * @return string[]
	 */
	public function filter_body_class( $classes ) {
		$classes   = is_array( $classes ) ? $classes : array();
		$classes[] = 'promptweb-editor-active';
		$classes[] = 'promptweb-editor-mode-' . sanitize_html_class( self::MODE_MANUAL );

		/**
		 * Filters body classes added by the frontend editor.
		 *
		 * @since 1.0.0
		 * @param string[]         $classes Body classes (editor-related only merge target).
		 * @param PromptWeb_Editor $editor  This instance.
		 */
		$extra = apply_filters( 'promptweb_editor_body_classes', array(), $this );
		if ( is_array( $extra ) ) {
			$classes = array_merge( $classes, $extra );
		}

		return $classes;
	}

	/**
	 * Mark page wrapper as editor-aware.
	 *
	 * @since 1.0.0
	 * @param string[] $classes Page classes.
	 * @param array    $page    Page definition.
	 * @return string[]
	 */
	public function filter_page_classes( $classes, $page ) {
		$classes   = is_array( $classes ) ? $classes : array();
		$classes[] = 'promptweb-editor-scope';

		return $classes;
	}

	/**
	 * Add editable class to rendered elements (editors only).
	 *
	 * @since 1.0.0
	 * @param string[] $classes  Class list.
	 * @param array    $element  Element definition.
	 * @param array    $settings Settings map.
	 * @param string   $type     Normalized type.
	 * @return string[]
	 */
	public function filter_element_classes( $classes, $element, $settings, $type = '' ) {
		$classes   = is_array( $classes ) ? $classes : array();
		$classes[] = 'promptweb-editable';

		/**
		 * Filters classes added to editable elements.
		 *
		 * @since 1.0.0
		 * @param string[] $classes Element classes.
		 * @param array    $element Element definition.
		 * @param string   $type    Type.
		 */
		return apply_filters( 'promptweb_editor_element_classes', $classes, $element, $type );
	}

	/**
	 * Add data attributes so JS can select elements reliably.
	 *
	 * @since 1.0.0
	 * @param string $extra    Existing extra attrs.
	 * @param array  $element  Element definition.
	 * @param array  $settings Settings map.
	 * @param string $type     Normalized type.
	 * @return string
	 */
	public function filter_element_attrs( $extra, $element, $settings, $type = '' ) {
		$attrs = array(
			'data-promptweb-editable="1"',
			'tabindex="0"',
			'role="button"',
		);

		// Helpful for screen readers / future a11y chrome.
		$label = __( 'Edit element', 'promptweb' );
		if ( ! empty( $type ) ) {
			/* translators: %s: element type */
			$label = sprintf( __( 'Edit %s', 'promptweb' ), $type );
		}
		$attrs[] = 'aria-label="' . esc_attr( $label ) . '"';

		if ( ! empty( $element['id'] ) && is_scalar( $element['id'] ) ) {
			// Already on the node via Renderer; keep a stable editor-specific hook.
			$attrs[] = 'data-promptweb-editor-id="' . esc_attr( (string) $element['id'] ) . '"';
		}

		/**
		 * Filters extra HTML attributes for editable elements.
		 *
		 * @since 1.0.0
		 * @param string[] $attrs   Attribute fragments (already escaped where needed).
		 * @param array    $element Element definition.
		 * @param string   $type    Type.
		 */
		$attrs = apply_filters( 'promptweb_editor_element_attrs', $attrs, $element, $type );

		$joined = is_array( $attrs ) ? implode( ' ', $attrs ) : '';
		$extra  = is_string( $extra ) ? trim( $extra ) : '';

		return trim( $extra . ' ' . $joined );
	}

	/**
	 * Sections are also selectable layout units.
	 *
	 * @since 1.0.0
	 * @param string[] $classes  Section classes.
	 * @param array    $section  Section definition.
	 * @param array    $settings Settings map.
	 * @return string[]
	 */
	public function filter_section_classes( $classes, $section, $settings = array() ) {
		$classes   = is_array( $classes ) ? $classes : array();
		$classes[] = 'promptweb-editable';
		$classes[] = 'promptweb-editable-section';

		return $classes;
	}

	/**
	 * Section data attributes for selection.
	 *
	 * @since 1.0.0
	 * @param string $extra   Existing attrs.
	 * @param array  $section Section definition.
	 * @return string
	 */
	public function filter_section_attrs( $extra, $section ) {
		$attrs = array(
			'data-promptweb-editable="1"',
			'data-promptweb-editable-section="1"',
			'tabindex="0"',
		);

		if ( ! empty( $section['id'] ) && is_scalar( $section['id'] ) ) {
			$attrs[] = 'data-promptweb-editor-id="' . esc_attr( (string) $section['id'] ) . '"';
		}

		$joined = implode( ' ', $attrs );
		$extra  = is_string( $extra ) ? trim( $extra ) : '';

		return trim( $extra . ' ' . $joined );
	}

	/**
	 * Enqueue frontend editor CSS and JS (capable users only).
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public function enqueue_assets() {
		if ( ! $this->is_active() ) {
			return;
		}

		$css_path = PROMPTWEB_PLUGIN_DIR . 'assets/css/promptweb-editor.css';
		$js_path  = PROMPTWEB_PLUGIN_DIR . 'assets/js/promptweb-editor.js';
		$css_ver  = file_exists( $css_path ) ? (string) filemtime( $css_path ) : PROMPTWEB_VERSION;
		$js_ver   = file_exists( $js_path ) ? (string) filemtime( $js_path ) : PROMPTWEB_VERSION;

		wp_enqueue_style(
			'promptweb-editor',
			PROMPTWEB_PLUGIN_URL . 'assets/css/promptweb-editor.css',
			array(),
			$css_ver
		);

		wp_enqueue_script(
			'promptweb-editor',
			PROMPTWEB_PLUGIN_URL . 'assets/js/promptweb-editor.js',
			array(),
			$js_ver,
			true
		);

		wp_localize_script(
			'promptweb-editor',
			'promptwebEditor',
			$this->get_client_config()
		);

		/**
		 * Fires after core editor assets are enqueued.
		 *
		 * @since 1.0.0
		 * @param PromptWeb_Editor $editor This instance.
		 */
		do_action( 'promptweb_editor_enqueue_assets', $this );
	}

	/**
	 * Client config for promptweb-editor.js (Multisite-aware URLs).
	 *
	 * @since 1.0.0
	 * @return array
	 */
	public function get_client_config() {
		$blueprint = class_exists( 'PromptWeb_Settings' )
			? PromptWeb_Settings::get_blueprint()
			: array();

		$page_count = ( ! empty( $blueprint['pages'] ) && is_array( $blueprint['pages'] ) )
			? count( $blueprint['pages'] )
			: 0;

		$config = array(
			'version'     => PROMPTWEB_VERSION,
			'canEdit'     => $this->current_user_can_edit(),
			'capability'  => $this->get_capability(),
			'isMultisite' => is_multisite(),
			'blogId'      => function_exists( 'get_current_blog_id' ) ? (int) get_current_blog_id() : 1,
			'homeUrl'     => home_url( '/' ),
			'restUrl'     => esc_url_raw( rest_url( 'promptweb/v1/' ) ),
			'adminUrl'    => esc_url_raw( admin_url() ),
			'nonce'       => wp_create_nonce( 'wp_rest' ),
			'editorNonce' => wp_create_nonce( 'promptweb_editor' ),
			// Default mode when the toolbar loads.
			'defaultMode' => self::MODE_MANUAL,
			'modes'       => array(
				self::MODE_MANUAL => array(
					'id'          => self::MODE_MANUAL,
					'label'       => __( 'Manual Edit', 'promptweb' ),
					'description' => __( 'Click elements on the page to edit them.', 'promptweb' ),
				),
				self::MODE_AI     => array(
					'id'          => self::MODE_AI,
					'label'       => __( 'AI Prompt', 'promptweb' ),
					'description' => __( 'Describe changes; the prompt is saved to JSON and pushed to GitHub.', 'promptweb' ),
				),
			),
			'selectors'   => array(
				'scope'    => '.promptweb-editor-scope, .promptweb-page',
				'editable' => '[data-promptweb-editable="1"]',
				'selected' => '.promptweb-editable--selected',
				'toolbar'  => '#promptweb-editor-toolbar',
				'panel'    => '#promptweb-editor-panel',
			),
			'i18n'        => array(
				'toolbarTitle'      => __( 'PromptWeb Editor', 'promptweb' ),
				'manualEdit'        => __( 'Manual Edit', 'promptweb' ),
				'aiPrompt'          => __( 'AI Prompt', 'promptweb' ),
				'selectHint'        => __( 'Click an element to select it.', 'promptweb' ),
				'noSelection'       => __( 'No element selected', 'promptweb' ),
				'selected'          => __( 'Selected', 'promptweb' ),
				'comingSoon'        => __( 'Coming soon', 'promptweb' ),
				'panelManual'       => __( 'Manual edit panel will open here.', 'promptweb' ),
				'panelAi'           => __( 'AI prompt panel will open here.', 'promptweb' ),
				'close'             => __( 'Close', 'promptweb' ),
				'groupContent'      => __( 'Content', 'promptweb' ),
				'groupSettings'     => __( 'Settings', 'promptweb' ),
				'fieldContent'      => __( 'Content', 'promptweb' ),
				'fieldUrl'          => __( 'URL', 'promptweb' ),
				'fieldAlt'          => __( 'Alt text', 'promptweb' ),
				'fieldColor'        => __( 'Color', 'promptweb' ),
				'fieldBackground'   => __( 'Background', 'promptweb' ),
				'fieldFontSize'     => __( 'Font size', 'promptweb' ),
				'fieldPadding'      => __( 'Padding', 'promptweb' ),
				'fieldMargin'       => __( 'Margin', 'promptweb' ),
				'fieldBorderRadius' => __( 'Border radius', 'promptweb' ),
				'fieldHeight'       => __( 'Height', 'promptweb' ),
				'liveOnlyNote'      => __( 'Changes update the page live. Saving to JSON / GitHub comes next.', 'promptweb' ),
			),
			'features'    => array(
				'manualEdit'          => true,
				'aiPrompt'            => true,
				'unknownElements'     => true,
				'maximumAiCreativity' => true,
				'gutenberg'           => false,
				// Live visual edits on; persistence later.
				'livePreview'         => true,
				'saveToGithub'        => false,
			),
			'blueprint'   => array(
				'pageCount' => $page_count,
				'hasData'   => $page_count > 0,
			),
		);

		/**
		 * Filters localized editor config for the frontend script.
		 *
		 * @since 1.0.0
		 * @param array            $config Editor config.
		 * @param PromptWeb_Editor $editor This instance.
		 */
		return (array) apply_filters( 'promptweb_editor_client_config', $config, $this );
	}

	/**
	 * Print editor shell: floating toolbar + side panel mount points.
	 *
	 * Visible only to capable users (this callback is only hooked when active).
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public function render_editor_shell() {
		if ( ! $this->is_active() || ! $this->current_user_can_edit() ) {
			return;
		}

		/**
		 * Filters whether the editor shell markup is printed.
		 *
		 * @since 1.0.0
		 * @param bool $print Default true.
		 */
		if ( ! apply_filters( 'promptweb_editor_print_root', true ) ) {
			return;
		}

		$manual = self::MODE_MANUAL;
		$ai     = self::MODE_AI;
		?>
		<!-- PromptWeb Frontend Editor foundation (Maximum AI Creativity) -->
		<div
			id="promptweb-editor-root"
			class="promptweb-editor-root"
			data-promptweb-editor="1"
			data-mode="<?php echo esc_attr( $manual ); ?>"
			hidden
		>
			<!-- Floating toolbar -->
			<div
				id="promptweb-editor-toolbar"
				class="promptweb-editor-toolbar"
				role="toolbar"
				aria-label="<?php esc_attr_e( 'PromptWeb Editor', 'promptweb' ); ?>"
			>
				<div class="promptweb-editor-toolbar__brand">
					<span class="promptweb-editor-toolbar__logo" aria-hidden="true">P</span>
					<span class="promptweb-editor-toolbar__title"><?php esc_html_e( 'PromptWeb', 'promptweb' ); ?></span>
				</div>

				<div class="promptweb-editor-toolbar__modes" role="group" aria-label="<?php esc_attr_e( 'Editor mode', 'promptweb' ); ?>">
					<button
						type="button"
						class="promptweb-editor-toolbar__btn promptweb-editor-toolbar__btn--mode is-active"
						data-promptweb-mode="<?php echo esc_attr( $manual ); ?>"
						aria-pressed="true"
					>
						<?php esc_html_e( 'Manual Edit', 'promptweb' ); ?>
					</button>
					<button
						type="button"
						class="promptweb-editor-toolbar__btn promptweb-editor-toolbar__btn--mode"
						data-promptweb-mode="<?php echo esc_attr( $ai ); ?>"
						aria-pressed="false"
					>
						<?php esc_html_e( 'AI Prompt', 'promptweb' ); ?>
					</button>
				</div>

				<div class="promptweb-editor-toolbar__meta">
					<span
						id="promptweb-editor-selection-label"
						class="promptweb-editor-toolbar__selection"
						data-empty="<?php esc_attr_e( 'No element selected', 'promptweb' ); ?>"
					>
						<?php esc_html_e( 'No element selected', 'promptweb' ); ?>
					</span>
				</div>
			</div>

			<!-- Side / floating panel (Manual Edit + AI Prompt placeholders) -->
			<aside
				id="promptweb-editor-panel"
				class="promptweb-editor-panel"
				hidden
				aria-hidden="true"
				aria-label="<?php esc_attr_e( 'Editor panel', 'promptweb' ); ?>"
			>
				<header class="promptweb-editor-panel__header">
					<h2 id="promptweb-editor-panel-title" class="promptweb-editor-panel__title">
						<?php esc_html_e( 'Editor', 'promptweb' ); ?>
					</h2>
					<button
						type="button"
						class="promptweb-editor-panel__close"
						data-promptweb-panel-close
						aria-label="<?php esc_attr_e( 'Close', 'promptweb' ); ?>"
					>
						&times;
					</button>
				</header>

				<div class="promptweb-editor-panel__body">
					<!-- Manual Edit mode mount -->
					<div
						id="promptweb-editor-panel-manual"
						class="promptweb-editor-panel__mode"
						data-promptweb-panel-mode="<?php echo esc_attr( $manual ); ?>"
						hidden
					>
						<p class="promptweb-editor-panel__hint">
							<?php esc_html_e( 'Select an element on the page to edit its content and settings.', 'promptweb' ); ?>
						</p>
						<div id="promptweb-editor-manual-fields" class="promptweb-editor-panel__fields"></div>
					</div>

					<!-- AI Prompt mode mount -->
					<div
						id="promptweb-editor-panel-ai"
						class="promptweb-editor-panel__mode"
						data-promptweb-panel-mode="<?php echo esc_attr( $ai ); ?>"
						hidden
					>
						<p class="promptweb-editor-panel__placeholder">
							<?php esc_html_e( 'AI prompt panel will open here.', 'promptweb' ); ?>
						</p>
						<p class="promptweb-editor-panel__hint">
							<?php esc_html_e( 'Describe the change you want. The prompt will be stored in JSON and pushed to GitHub for external AI processing.', 'promptweb' ); ?>
						</p>
						<div id="promptweb-editor-ai-fields" class="promptweb-editor-panel__fields"></div>
					</div>
				</div>
			</aside>
		</div>
		<?php

		/**
		 * Fires after the editor shell is printed.
		 *
		 * @since 1.0.0
		 * @param PromptWeb_Editor $editor This instance.
		 */
		do_action( 'promptweb_editor_root_rendered', $this );
	}

	/**
	 * Backward-compatible alias used by older hooks/docs.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public function render_editor_root() {
		$this->render_editor_shell();
	}
}
