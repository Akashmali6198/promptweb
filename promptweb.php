<?php
/**
 * Plugin Name:       PromptWeb
 * Plugin URI:        https://promptweb.example.com
 * Description:       PromptWeb — JSON-first site builder synced with GitHub (Multisite-ready).
 * Version:           1.0.0
 * Requires at least: 5.8
 * Requires PHP:      7.4
 * Author:            PromptWeb
 * Author URI:        https://promptweb.example.com
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       promptweb
 * Domain Path:       /languages
 * Network:           true
 *
 * Architecture (high level):
 * - Structured JSON in GitHub is the single source of truth (not Gutenberg).
 * - Sync pulls JSON; Renderer outputs HTML; Editor enables live edits + GitHub push.
 * - AI prompts are stored in JSON and processed externally after push.
 *
 * @package PromptWeb
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Current plugin version.
 */
define( 'PROMPTWEB_VERSION', '1.0.0' );

/**
 * Absolute path to the plugin directory (with trailing slash).
 */
define( 'PROMPTWEB_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );

/**
 * URL to the plugin directory (with trailing slash).
 */
define( 'PROMPTWEB_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

/**
 * Absolute path to the main plugin file.
 */
define( 'PROMPTWEB_PLUGIN_FILE', __FILE__ );

/**
 * Plugin basename (e.g. promptweb/promptweb.php).
 */
define( 'PROMPTWEB_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );

/**
 * Load the core plugin class.
 */
require_once PROMPTWEB_PLUGIN_DIR . 'includes/class-promptweb.php';

/**
 * Begins execution of the plugin.
 *
 * @since 1.0.0
 * @return PromptWeb Main plugin instance.
 */
function promptweb() {
	return PromptWeb::instance();
}

// Kick off the plugin.
promptweb();
