<?php
/**
 * The core plugin class.
 *
 * -------------------------------------------------------------------------
 * Architecture direction (JSON-first — not Gutenberg):
 *
 * 1. Structured JSON (Schema v1.0) in GitHub is the single source of truth.
 *    See PromptWeb_Schema for the official shape, example, and validate().
 * 2. GitHub connection + Sync pull that JSON into WordPress (kept intact).
 * 3. PromptWeb_Renderer turns pages → sections → elements into HTML.
 * 4. PromptWeb_Editor: visual editor for logged-in users with edit capability.
 *    - Manual edit → live update + push JSON to GitHub.
 *    - AI prompt → save prompt in JSON + push to GitHub (AI runs externally).
 *
 * PromptWeb_Converter (Gutenberg pages) is retained for now as a legacy path
 * used by Sync, but new product work should target Schema + Renderer + Editor.
 * -------------------------------------------------------------------------
 *
 * @package PromptWeb
 * @since   1.0.0
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Main PromptWeb class.
 *
 * Singleton responsible for loading dependencies, registering hooks,
 * and coordinating Multisite-aware bootstrap.
 *
 * @since 1.0.0
 */
final class PromptWeb {

	/**
	 * Single instance of the class.
	 *
	 * @since 1.0.0
	 * @var   PromptWeb|null
	 */
	private static $instance = null;

	/**
	 * Admin handler instance.
	 *
	 * @since 1.0.0
	 * @var   PromptWeb_Admin|null
	 */
	public $admin = null;

	/**
	 * GitHub connection / blueprint helper (fetch + sync — retained).
	 *
	 * @since 1.0.0
	 * @var   PromptWeb_GitHub|null
	 */
	public $github = null;

	/**
	 * Legacy blueprint → Gutenberg converter (still used by Sync for now).
	 *
	 * New frontend path: PromptWeb_Renderer. Prefer JSON + HTML over blocks.
	 *
	 * @since 1.0.0
	 * @var   PromptWeb_Converter|null
	 */
	public $converter = null;

	/**
	 * Structured JSON → frontend HTML renderer.
	 *
	 * @since 1.0.0
	 * @var   PromptWeb_Renderer|null
	 */
	public $renderer = null;

	/**
	 * Frontend visual editor (logged-in users with edit capability).
	 *
	 * @since 1.0.0
	 * @var   PromptWeb_Editor|null
	 */
	public $editor = null;

	/**
	 * Official JSON schema helper (document / example / validate).
	 *
	 * Static API on PromptWeb_Schema; instance kept for discoverability.
	 *
	 * @since 1.0.0
	 * @var   PromptWeb_Schema|null
	 */
	public $schema = null;

	/**
	 * Get the singleton instance.
	 *
	 * @since 1.0.0
	 * @return PromptWeb
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Constructor. Boots the plugin.
	 *
	 * @since 1.0.0
	 */
	private function __construct() {
		$this->load_dependencies();
		$this->set_locale();
		$this->define_hooks();
	}

	/**
	 * Prevent cloning.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	private function __clone() {}

	/**
	 * Prevent unserializing.
	 *
	 * @since 1.0.0
	 * @throws Exception Always, to block unserialization.
	 * @return void
	 */
	public function __wakeup() {
		throw new Exception( 'Cannot unserialize a singleton.' );
	}

	/**
	 * Load required dependency files.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	private function load_dependencies() {
		// Settings (shared by GitHub helpers and admin UI).
		require_once PROMPTWEB_PLUGIN_DIR . 'admin/class-promptweb-settings.php';

		// GitHub connection + Sync (source of truth lives in the repo).
		require_once PROMPTWEB_PLUGIN_DIR . 'includes/class-promptweb-github.php';

		// Legacy Gutenberg conversion path (still loaded; Sync may call it).
		require_once PROMPTWEB_PLUGIN_DIR . 'includes/class-promptweb-converter.php';

		// Official blueprint schema (source-of-truth contract).
		require_once PROMPTWEB_PLUGIN_DIR . 'includes/class-promptweb-schema.php';

		// JSON-first frontend stack.
		require_once PROMPTWEB_PLUGIN_DIR . 'includes/class-promptweb-renderer.php';
		require_once PROMPTWEB_PLUGIN_DIR . 'includes/class-promptweb-editor.php';

		// wp-admin / network admin.
		require_once PROMPTWEB_PLUGIN_DIR . 'admin/class-promptweb-admin.php';
	}

	/**
	 * Load the plugin text domain for translation.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	private function set_locale() {
		add_action( 'plugins_loaded', array( $this, 'load_textdomain' ) );
	}

	/**
	 * Load plugin textdomain.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public function load_textdomain() {
		load_plugin_textdomain(
			'promptweb',
			false,
			dirname( PROMPTWEB_PLUGIN_BASENAME ) . '/languages'
		);
	}

	/**
	 * Register activation, deactivation, and runtime hooks.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	private function define_hooks() {
		// Activation / deactivation (single site + Multisite network).
		register_activation_hook( PROMPTWEB_PLUGIN_FILE, array( $this, 'activate' ) );
		register_deactivation_hook( PROMPTWEB_PLUGIN_FILE, array( $this, 'deactivate' ) );

		// --- GitHub (fetch / sync) — keep fully operational ---
		$this->github = new PromptWeb_GitHub();
		add_action( 'init', array( $this->github, 'init' ) );

		// --- Legacy converter (Gutenberg pages via Sync) ---
		$this->converter = new PromptWeb_Converter();

		// --- Schema contract (static helpers; instance for promptweb()->schema) ---
		$this->schema = new PromptWeb_Schema();

		// --- JSON → HTML renderer (Schema v1.0: sections + elements) ---
		$this->renderer = new PromptWeb_Renderer();
		add_action( 'init', array( $this->renderer, 'init' ) );

		// --- Frontend visual editor foundation (capability-gated) ---
		$this->editor = new PromptWeb_Editor();
		add_action( 'init', array( $this->editor, 'init' ) );

		// Admin bootstrap (network admin + per-site admin).
		if ( is_admin() ) {
			$this->admin = new PromptWeb_Admin();
			add_action( 'init', array( $this->admin, 'init' ) );
		}
	}

	/**
	 * Plugin activation callback.
	 *
	 * Multisite-aware: when network-activated, runs setup for each site
	 * (or stores network-level state as needed). On single-site activation,
	 * runs setup for the current site only.
	 *
	 * @since 1.0.0
	 * @param bool $network_wide Whether the plugin is being network-activated.
	 * @return void
	 */
	public function activate( $network_wide ) {
		if ( is_multisite() && $network_wide ) {
			// Network activation: iterate all sites if needed later.
			// For now, only store a network option as a foundation marker.
			update_site_option( 'promptweb_network_version', PROMPTWEB_VERSION );
		} else {
			// Single-site (or per-site) activation.
			update_option( 'promptweb_version', PROMPTWEB_VERSION );
		}

		/**
		 * Fires after PromptWeb is activated.
		 *
		 * @since 1.0.0
		 * @param bool $network_wide Whether activation was network-wide.
		 */
		do_action( 'promptweb_activated', $network_wide );
	}

	/**
	 * Plugin deactivation callback.
	 *
	 * @since 1.0.0
	 * @param bool $network_wide Whether the plugin is being network-deactivated.
	 * @return void
	 */
	public function deactivate( $network_wide ) {
		/**
		 * Fires after PromptWeb is deactivated.
		 *
		 * @since 1.0.0
		 * @param bool $network_wide Whether deactivation was network-wide.
		 */
		do_action( 'promptweb_deactivated', $network_wide );
	}
}
