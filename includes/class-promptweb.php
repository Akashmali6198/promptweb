<?php
/**
 * The core plugin class.
 *
 * -------------------------------------------------------------------------
 * Architecture: Maximum AI Creativity (JSON-first)
 *
 * 1. Structured JSON in GitHub is the single source of truth for the site.
 * 2. AI has high freedom to invent element types, layouts, and settings —
 *    the schema only guards a light skeleton: pages → sections → elements.
 * 3. GitHub connection + Sync pull JSON into WordPress (Multisite-aware).
 * 4. PromptWeb_Renderer turns that JSON into frontend HTML (not Gutenberg).
 * 5. PromptWeb_Editor: visual editor for logged-in users with edit capability.
 *    - Manual edit → live update + push JSON to GitHub.
 *    - AI prompt → save prompt in JSON + push (AI processes externally).
 *    - Editor must support editing AI-generated / unknown element types.
 *
 * LEGACY: PromptWeb_Converter (Gutenberg) is deprecated and not loaded on
 * the main path. Do not build new features on it.
 *
 * Multisite: network activation, network settings, and per-site options are
 * supported throughout Settings / GitHub / runtime storage.
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
 * Singleton: dependency loading, hooks, Multisite-aware bootstrap.
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
	 * Admin handler (settings UI, Multisite network admin).
	 *
	 * @since 1.0.0
	 * @var   PromptWeb_Admin|null
	 */
	public $admin = null;

	/**
	 * GitHub connection + Sync (fetch blueprint JSON).
	 *
	 * @since 1.0.0
	 * @var   PromptWeb_GitHub|null
	 */
	public $github = null;

	/**
	 * Official JSON schema (document / example / loose validate / normalize).
	 *
	 * @since 1.0.0
	 * @var   PromptWeb_Schema|null
	 */
	public $schema = null;

	/**
	 * JSON → frontend HTML renderer (Maximum AI Creativity presentation path).
	 *
	 * @since 1.0.0
	 * @var   PromptWeb_Renderer|null
	 */
	public $renderer = null;

	/**
	 * Frontend visual editor foundation (edit AI-generated elements).
	 *
	 * @since 1.0.0
	 * @var   PromptWeb_Editor|null
	 */
	public $editor = null;

	/**
	 * REST API (blueprint push to GitHub, future write endpoints).
	 *
	 * @since 1.0.0
	 * @var   PromptWeb_REST|null
	 */
	public $rest = null;

	/**
	 * LEGACY / DEPRECATED Gutenberg converter.
	 *
	 * Instantiated only if something still references promptweb()->converter.
	 * Prefer Schema + Renderer. File retained; not part of the main path.
	 *
	 * @since 1.0.0
	 * @deprecated 1.0.0
	 * @var   PromptWeb_Converter|null
	 */
	public $converter = null;

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
	 * Order: shared settings → GitHub → Schema → Renderer → Editor → Admin,
	 * then legacy converter last (deprecated; still require_once for BC).
	 *
	 * @since 1.0.0
	 * @return void
	 */
	private function load_dependencies() {
		// 1. Settings (options API, Multisite network/site storage helpers).
		require_once PROMPTWEB_PLUGIN_DIR . 'admin/class-promptweb-settings.php';

		// 2. GitHub connection + Sync (JSON source of truth from the repo).
		require_once PROMPTWEB_PLUGIN_DIR . 'includes/class-promptweb-github.php';

		// 3. Schema contract — flexible for Maximum AI Creativity.
		require_once PROMPTWEB_PLUGIN_DIR . 'includes/class-promptweb-schema.php';

		// 4. Renderer — JSON pages/sections/elements → HTML.
		require_once PROMPTWEB_PLUGIN_DIR . 'includes/class-promptweb-renderer.php';

		// 5. Frontend editor foundation (capability-gated).
		require_once PROMPTWEB_PLUGIN_DIR . 'includes/class-promptweb-editor.php';

		// 6. REST API (editor push to GitHub, etc.).
		require_once PROMPTWEB_PLUGIN_DIR . 'includes/class-promptweb-rest.php';

		// 7. wp-admin / Network Admin UI.
		require_once PROMPTWEB_PLUGIN_DIR . 'admin/class-promptweb-admin.php';

		// 8. LEGACY only — Gutenberg converter (deprecated; do not extend).
		require_once PROMPTWEB_PLUGIN_DIR . 'includes/class-promptweb-converter.php';
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
	 * Main path: GitHub → Schema → Renderer → Editor (+ Admin).
	 * Converter is available as a lazy legacy accessor only.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	private function define_hooks() {
		// Activation / deactivation (single site + Multisite network).
		register_activation_hook( PROMPTWEB_PLUGIN_FILE, array( $this, 'activate' ) );
		register_deactivation_hook( PROMPTWEB_PLUGIN_FILE, array( $this, 'deactivate' ) );

		// --- Primary stack: Maximum AI Creativity ---

		// GitHub fetch / sync.
		$this->github = new PromptWeb_GitHub();
		add_action( 'init', array( $this->github, 'init' ) );

		// Schema helpers (static API; instance for promptweb()->schema).
		$this->schema = new PromptWeb_Schema();

		// JSON → HTML.
		$this->renderer = new PromptWeb_Renderer();
		add_action( 'init', array( $this->renderer, 'init' ) );

		// Frontend visual editor (logged-in + capability).
		$this->editor = new PromptWeb_Editor();
		add_action( 'init', array( $this->editor, 'init' ) );

		// REST: blueprint push to GitHub (and future write APIs).
		$this->rest = new PromptWeb_REST();
		$this->rest->init();

		// Admin / Network Admin settings UI.
		if ( is_admin() ) {
			$this->admin = new PromptWeb_Admin();
			add_action( 'init', array( $this->admin, 'init' ) );
		}

		// LEGACY converter: not bootstrapped on the main path.
		// Access via promptweb()->get_legacy_converter() if absolutely required.
	}

	/**
	 * Lazy-load the deprecated Gutenberg converter (backward compatibility).
	 *
	 * @since 1.0.0
	 * @deprecated 1.0.0 Prefer Renderer + Schema.
	 * @return PromptWeb_Converter
	 */
	public function get_legacy_converter() {
		if ( null === $this->converter ) {
			$this->converter = new PromptWeb_Converter();
		}

		// Expose on ->converter for older call sites.
		return $this->converter;
	}

	/**
	 * Plugin activation callback.
	 *
	 * Multisite-aware: network vs single-site option markers.
	 *
	 * @since 1.0.0
	 * @param bool $network_wide Whether the plugin is being network-activated.
	 * @return void
	 */
	public function activate( $network_wide ) {
		if ( is_multisite() && $network_wide ) {
			update_site_option( 'promptweb_network_version', PROMPTWEB_VERSION );
		} else {
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
