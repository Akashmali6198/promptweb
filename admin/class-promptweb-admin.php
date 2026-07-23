<?php
/**
 * Admin-facing functionality.
 *
 * @package PromptWeb
 * @since   1.0.0
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handles WordPress admin (and network admin) integration.
 *
 * @since 1.0.0
 */
class PromptWeb_Admin {

	/**
	 * Settings page handler.
	 *
	 * @since 1.0.0
	 * @var   PromptWeb_Settings|null
	 */
	public $settings = null;

	/**
	 * Initialize admin hooks and sub-components.
	 *
	 * Called on `init` from the main plugin class when `is_admin()` is true.
	 * Settings and GitHub classes are loaded by the core plugin; this method
	 * only boots the admin UI (menus / Settings API registration).
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public function init() {
		$this->init_settings();

		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );

		// Network admin scripts/styles (Multisite).
		add_action( 'network_admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
	}

	/**
	 * Instantiate and bootstrap the settings class.
	 *
	 * PromptWeb_Settings is required early by the core plugin (GitHub helpers).
	 * Here we only construct the admin instance and register menu/settings hooks.
	 *
	 * Access GitHub helpers via promptweb()->github when needed in admin UI.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	private function init_settings() {
		$this->settings = new PromptWeb_Settings();
		$this->settings->init();
	}

	/**
	 * Enqueue admin CSS and JS on PromptWeb screens only.
	 *
	 * @since 1.0.0
	 * @param string $hook_suffix The current admin page hook.
	 * @return void
	 */
	public function enqueue_assets( $hook_suffix ) {
		// Load assets only on our top-level menu page (site or network).
		// Hook format: toplevel_page_promptweb
		if ( false === strpos( $hook_suffix, 'promptweb' ) ) {
			return;
		}

		/*
		wp_enqueue_style(
			'promptweb-admin',
			PROMPTWEB_PLUGIN_URL . 'assets/css/promptweb-admin.css',
			array(),
			PROMPTWEB_VERSION
		);

		wp_enqueue_script(
			'promptweb-admin',
			PROMPTWEB_PLUGIN_URL . 'assets/js/promptweb-admin.js',
			array( 'jquery' ),
			PROMPTWEB_VERSION,
			true
		);
		*/
	}
}
