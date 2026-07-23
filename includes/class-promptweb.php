<?php
/**
 * The core plugin class.
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
	 * GitHub connection / blueprint helper.
	 *
	 * @since 1.0.0
	 * @var   PromptWeb_GitHub|null
	 */
	public $github = null;

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
		// Settings class is required by GitHub helpers (runtime settings access).
		require_once PROMPTWEB_PLUGIN_DIR . 'admin/class-promptweb-settings.php';
		require_once PROMPTWEB_PLUGIN_DIR . 'includes/class-promptweb-github.php';
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
		// Activation / deactivation (works for single site and Multisite network activation).
		register_activation_hook( PROMPTWEB_PLUGIN_FILE, array( $this, 'activate' ) );
		register_deactivation_hook( PROMPTWEB_PLUGIN_FILE, array( $this, 'deactivate' ) );

		// GitHub helpers (available front-end and admin for future auto-detect).
		$this->github = new PromptWeb_GitHub();
		add_action( 'init', array( $this->github, 'init' ) );

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
