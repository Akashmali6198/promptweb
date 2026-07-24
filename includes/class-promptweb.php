<?php
/**
 * The core plugin class.
 *
 * -------------------------------------------------------------------------
 * Architecture v2 — Full creative freedom + AI agency
 *
 * 1. Design pages in GitHub (preferred):
 *    - pages/static/*.html  → full HTML + Tailwind CDN + JS
 *    - pages/dynamic/*.php  → PHP + WordPress
 *    - pages/manifest.json  → slug, type, status (draft|publish)
 * 2. MCP / Abilities tools for AI agents (list/get/create/update/publish,
 *    visual analysis, commit_to_github).
 * 3. Legacy JSON blueprints still sync and render when present.
 * 4. GitHub connection, Initialize, Sync, Auto-sync, Plugin Update preserved.
 * 5. Frontend visual editor temporarily disabled when v2 pages are active.
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
	 * GitHub connection + Sync (fetch blueprint JSON + design pages).
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
	 * JSON → frontend HTML renderer (legacy blueprint path).
	 *
	 * @since 1.0.0
	 * @var   PromptWeb_Renderer|null
	 */
	public $renderer = null;

	/**
	 * Frontend visual editor foundation (temporarily limited on v2 pages).
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
	 * Frontend page router / template loader.
	 *
	 * @since 1.0.0
	 * @var   PromptWeb_Frontend|null
	 */
	public $frontend = null;

	/**
	 * Design pages registry (static HTML + dynamic PHP).
	 *
	 * @since 2.0.0
	 * @var   PromptWeb_Pages|null
	 */
	public $pages = null;

	/**
	 * MCP / Abilities bridge.
	 *
	 * @since 2.0.0
	 * @var   PromptWeb_MCP|null
	 */
	public $mcp = null;

	/**
	 * LEGACY / DEPRECATED Gutenberg converter.
	 *
	 * Instantiated only if something still references promptweb()->converter.
	 * Prefer design pages or Schema + Renderer. File retained; not part of the main path.
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
	 * @since 1.0.0
	 * @return void
	 */
	private function load_dependencies() {
		// 1. Settings (options API, Multisite network/site storage helpers).
		require_once PROMPTWEB_PLUGIN_DIR . 'admin/class-promptweb-settings.php';

		// 2. GitHub connection + Sync (JSON + design pages from the repo).
		require_once PROMPTWEB_PLUGIN_DIR . 'includes/class-promptweb-github.php';

		// 3. Schema contract — flexible for Maximum AI Creativity (legacy JSON).
		require_once PROMPTWEB_PLUGIN_DIR . 'includes/class-promptweb-schema.php';

		// 4. Renderer — JSON pages/sections/elements → HTML (legacy path).
		require_once PROMPTWEB_PLUGIN_DIR . 'includes/class-promptweb-renderer.php';

		// 5. Design pages (static HTML + dynamic PHP) — primary v2 path.
		require_once PROMPTWEB_PLUGIN_DIR . 'includes/class-promptweb-pages.php';

		// 6. Frontend page router (design pages + blueprint → public HTML).
		require_once PROMPTWEB_PLUGIN_DIR . 'includes/class-promptweb-frontend.php';

		// 7. Frontend editor foundation (capability-gated; limited on v2 pages).
		require_once PROMPTWEB_PLUGIN_DIR . 'includes/class-promptweb-editor.php';

		// 8. REST API (editor push to GitHub, etc.).
		require_once PROMPTWEB_PLUGIN_DIR . 'includes/class-promptweb-rest.php';

		// 9. Reference URL inspector (analyze_reference_url).
		require_once PROMPTWEB_PLUGIN_DIR . 'includes/class-promptweb-reference.php';

		// 10. MCP / Abilities tools for AI agents.
		require_once PROMPTWEB_PLUGIN_DIR . 'includes/class-promptweb-mcp.php';

		// 11. Safe self-update of plugin code from public core repo (not design repo).
		require_once PROMPTWEB_PLUGIN_DIR . 'includes/class-promptweb-updater.php';

		// 12. wp-admin / Network Admin UI.
		require_once PROMPTWEB_PLUGIN_DIR . 'admin/class-promptweb-admin.php';

		// 13. LEGACY only — Gutenberg converter (deprecated; do not extend).
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
	 * @since 1.0.0
	 * @return void
	 */
	private function define_hooks() {
		// Activation / deactivation (single site + Multisite network).
		register_activation_hook( PROMPTWEB_PLUGIN_FILE, array( $this, 'activate' ) );
		register_deactivation_hook( PROMPTWEB_PLUGIN_FILE, array( $this, 'deactivate' ) );

		// --- Primary stack: design pages + GitHub + MCP ---

		// Design pages storage (uploads/promptweb — survives plugin updates).
		$this->pages = new PromptWeb_Pages();
		add_action( 'init', array( $this->pages, 'init' ), 4 );

		// GitHub fetch / sync (blueprint + pages).
		$this->github = new PromptWeb_GitHub();
		add_action( 'init', array( $this->github, 'init' ) );

		// Schema helpers (static API; instance for promptweb()->schema).
		$this->schema = new PromptWeb_Schema();

		// JSON → HTML (legacy blueprints).
		$this->renderer = new PromptWeb_Renderer();
		add_action( 'init', array( $this->renderer, 'init' ) );

		// Public routes + template (design pages + blueprint on the front end).
		$this->frontend = new PromptWeb_Frontend();
		add_action( 'init', array( $this->frontend, 'init' ), 5 );

		// Frontend visual editor (logged-in + capability; disabled when v2 pages active).
		$this->editor = new PromptWeb_Editor();
		add_action( 'init', array( $this->editor, 'init' ) );

		// REST: blueprint push to GitHub (and future write APIs).
		$this->rest = new PromptWeb_REST();
		$this->rest->init();

		// MCP / Abilities tools (list/get/create/update/publish/analyze/commit).
		$this->mcp = new PromptWeb_MCP();
		$this->mcp->init();

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
	 * @deprecated 1.0.0 Prefer design pages or Renderer + Schema.
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
	 * Never deletes design data, blueprints, or GitHub settings.
	 *
	 * @since 1.0.0
	 * @param bool $network_wide Whether the plugin is being network-activated.
	 * @return void
	 */
	public function activate( $network_wide ) {
		if ( ! class_exists( 'PromptWeb_Frontend' ) ) {
			require_once PROMPTWEB_PLUGIN_DIR . 'includes/class-promptweb-frontend.php';
		}
		if ( ! class_exists( 'PromptWeb_Pages' ) ) {
			require_once PROMPTWEB_PLUGIN_DIR . 'includes/class-promptweb-pages.php';
		}

		// Ensure local design storage exists (uploads/promptweb).
		$pages = new PromptWeb_Pages();
		$pages->ensure_storage();

		if ( is_multisite() && $network_wide ) {
			update_site_option( 'promptweb_network_version', PROMPTWEB_VERSION );

			// Flush rewrites on each site so clean /{slug}/ + /promptweb/{slug}/ work network-wide.
			$site_ids = get_sites(
				array(
					'fields' => 'ids',
					'number' => 500,
				)
			);
			if ( is_array( $site_ids ) ) {
				foreach ( $site_ids as $site_id ) {
					switch_to_blog( (int) $site_id );
					$site_pages = new PromptWeb_Pages();
					$site_pages->ensure_storage();
					PromptWeb_Frontend::flush_rewrites();
					restore_current_blog();
				}
			}
		} else {
			update_option( 'promptweb_version', PROMPTWEB_VERSION );
			// Registers fallback rewrite + flushes so clean root URLs resolve after activate.
			PromptWeb_Frontend::flush_rewrites();
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
	 * Does not delete design data, blueprints, or GitHub settings.
	 *
	 * @since 1.0.0
	 * @param bool $network_wide Whether the plugin is being network-deactivated.
	 * @return void
	 */
	public function deactivate( $network_wide ) {
		if ( is_multisite() && $network_wide ) {
			$site_ids = get_sites(
				array(
					'fields' => 'ids',
					'number' => 500,
				)
			);
			if ( is_array( $site_ids ) ) {
				foreach ( $site_ids as $site_id ) {
					switch_to_blog( (int) $site_id );
					flush_rewrite_rules( false );
					restore_current_blog();
				}
			}
		} else {
			flush_rewrite_rules( false );
		}

		/**
		 * Fires after PromptWeb is deactivated.
		 *
		 * @since 1.0.0
		 * @param bool $network_wide Whether deactivation was network-wide.
		 */
		do_action( 'promptweb_deactivated', $network_wide );
	}
}
