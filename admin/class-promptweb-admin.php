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
	 * Initialize admin hooks.
	 *
	 * Called on `init` from the main plugin class when `is_admin()` is true.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public function init() {
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );

		// Network admin scripts/styles (Multisite).
		add_action( 'network_admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
	}

	/**
	 * Enqueue admin CSS and JS when appropriate.
	 *
	 * Assets load only on PromptWeb-related screens to keep the admin light.
	 * Screen checks can be expanded once admin pages are added.
	 *
	 * @since 1.0.0
	 * @param string $hook_suffix The current admin page hook.
	 * @return void
	 */
	public function enqueue_assets( $hook_suffix ) {
		// Placeholder: enqueue only on our screens once menus exist.
		// Example: if ( false === strpos( $hook_suffix, 'promptweb' ) ) { return; }

		unset( $hook_suffix ); // Reserved for future screen checks.

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
