<?php
/**
 * Frontend Visual Editor foundation.
 *
 * Maximum AI Creativity:
 * - Structured JSON in GitHub is the single source of truth (not Gutenberg).
 * - AI may invent arbitrary element types; the editor must allow inspecting/editing
 *   those nodes (content, settings, nested children) without a fixed type list.
 * - Manual edit → live update + push JSON to GitHub.
 * - AI prompt → save prompt in JSON + push; external AI processes later.
 *
 * This class is a scaffold only: capability gates, hooks, and asset stubs.
 * Full editor UI, REST/AJAX save, and GitHub write-back land in later iterations.
 *
 * Multisite: capability checks run in the current blog context.
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
	 * Super admins still need a role that maps to this cap on the site, unless
	 * filtered via `promptweb_editor_capability`.
	 *
	 * @since 1.0.0
	 * @var   string
	 */
	const CAPABILITY = 'edit_pages';

	/**
	 * Register frontend editor hooks.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public function init() {
		// Only relevant on the public front end (not wp-admin / network admin).
		if ( is_admin() ) {
			return;
		}

		add_action( 'wp', array( $this, 'maybe_bootstrap' ) );

		/**
		 * Fires when the frontend editor component is initialized.
		 *
		 * @since 1.0.0
		 * @param PromptWeb_Editor $editor This instance.
		 */
		do_action( 'promptweb_editor_init', $this );
	}

	/**
	 * After the main query is set, decide whether to load editor chrome.
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

		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'wp_footer', array( $this, 'render_editor_root' ), 5 );

		/**
		 * Fires when the frontend editor is active for the current request.
		 *
		 * Use this to attach save handlers, REST routes consumers, etc.
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

		$capability = $this->get_capability();

		return current_user_can( $capability );
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
		 * @since 1.0.0
		 * @param string $capability Default edit_pages.
		 */
		return (string) apply_filters( 'promptweb_editor_capability', self::CAPABILITY );
	}

	/**
	 * Enqueue editor CSS/JS stubs (assets added in a later iteration).
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public function enqueue_assets() {
		/**
		 * Fires when frontend editor assets should be registered/enqueued.
		 *
		 * @since 1.0.0
		 * @param PromptWeb_Editor $editor This instance.
		 */
		do_action( 'promptweb_editor_enqueue_assets', $this );

		/*
		 * Placeholder for future assets, e.g.:
		 *
		 * wp_enqueue_style(
		 *     'promptweb-editor',
		 *     PROMPTWEB_PLUGIN_URL . 'assets/css/promptweb-editor.css',
		 *     array(),
		 *     PROMPTWEB_VERSION
		 * );
		 *
		 * wp_enqueue_script(
		 *     'promptweb-editor',
		 *     PROMPTWEB_PLUGIN_URL . 'assets/js/promptweb-editor.js',
		 *     array(),
		 *     PROMPTWEB_VERSION,
		 *     true
		 * );
		 *
		 * wp_localize_script( 'promptweb-editor', 'promptwebEditor', $this->get_client_config() );
		 */
	}

	/**
	 * Client config payload for a future editor script (REST URLs, nonces, caps).
	 *
	 * Multisite: rest_url() and home_url() are blog-aware for the current site.
	 *
	 * @since 1.0.0
	 * @return array
	 */
	public function get_client_config() {
		$config = array(
			'version'    => PROMPTWEB_VERSION,
			'canEdit'    => $this->current_user_can_edit(),
			'capability' => $this->get_capability(),
			'homeUrl'    => home_url( '/' ),
			'restUrl'    => esc_url_raw( rest_url( 'promptweb/v1/' ) ),
			'nonce'      => wp_create_nonce( 'wp_rest' ),
			// Future: current page slug, blueprint revision, GitHub push status.
			'features'   => array(
				'manualEdit'          => true,  // Live update + push JSON to GitHub.
				'aiPrompt'            => true,  // Save prompt in JSON + push; AI runs externally.
				'unknownElements'     => true,  // Edit AI-invented types (Maximum AI Creativity).
				'maximumAiCreativity' => true,
				'gutenberg'           => false, // Deprecated — not the content model.
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
	 * Print a minimal mount point for the future visual editor UI.
	 *
	 * No interactive UI yet — structure only.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public function render_editor_root() {
		if ( ! $this->current_user_can_edit() ) {
			return;
		}

		/**
		 * Filters whether the editor root markup is printed.
		 *
		 * @since 1.0.0
		 * @param bool $print Default true.
		 */
		if ( ! apply_filters( 'promptweb_editor_print_root', true ) ) {
			return;
		}

		echo '<!-- PromptWeb Frontend Editor (foundation; UI not loaded yet) -->' . "\n";
		echo '<div id="promptweb-editor-root" class="promptweb-editor-root" data-promptweb-editor="1" hidden></div>' . "\n";

		/**
		 * Fires after the editor root element is printed.
		 *
		 * @since 1.0.0
		 * @param PromptWeb_Editor $editor This instance.
		 */
		do_action( 'promptweb_editor_root_rendered', $this );
	}
}
