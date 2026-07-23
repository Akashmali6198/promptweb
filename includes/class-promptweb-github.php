<?php
/**
 * GitHub connection and blueprint helpers.
 *
 * Scaffold for reading blueprints and auto-detect logic.
 * Settings are shared with PromptWeb_Settings (same option key).
 *
 * @package PromptWeb
 * @since   1.0.0
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handles GitHub-related configuration and (later) API / blueprint work.
 *
 * @since 1.0.0
 */
class PromptWeb_GitHub {

	/**
	 * Bootstrap hooks for future auto-detect / sync behavior.
	 *
	 * Intentionally minimal for now — connection helpers only.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public function init() {
		/**
		 * Fires when the GitHub component is initialized.
		 *
		 * @since 1.0.0
		 * @param PromptWeb_GitHub $github This instance.
		 */
		do_action( 'promptweb_github_init', $this );
	}

	/**
	 * Full settings array (Multisite-aware runtime storage).
	 *
	 * Uses network options when the plugin is network-activated,
	 * otherwise the current site options. Same source as the Settings UI.
	 *
	 * @since 1.0.0
	 * @return array
	 */
	public function get_settings() {
		return PromptWeb_Settings::get_runtime_settings();
	}

	/**
	 * Whether PromptWeb is enabled in settings.
	 *
	 * @since 1.0.0
	 * @return bool
	 */
	public function is_enabled() {
		$settings = $this->get_settings();

		return ! empty( $settings['enabled'] );
	}

	/**
	 * Whether auto-detect is enabled (default ON).
	 *
	 * @since 1.0.0
	 * @return bool
	 */
	public function is_auto_detect_enabled() {
		$settings = $this->get_settings();

		return ! empty( $settings['auto_detect'] );
	}

	/**
	 * GitHub personal access token.
	 *
	 * @since 1.0.0
	 * @return string
	 */
	public function get_token() {
		$settings = $this->get_settings();

		return isset( $settings['github_token'] ) ? (string) $settings['github_token'] : '';
	}

	/**
	 * GitHub repository slug (owner/repo).
	 *
	 * @since 1.0.0
	 * @return string
	 */
	public function get_repo() {
		$settings = $this->get_settings();

		return isset( $settings['github_repo'] ) ? (string) $settings['github_repo'] : '';
	}

	/**
	 * GitHub branch name.
	 *
	 * @since 1.0.0
	 * @return string
	 */
	public function get_branch() {
		$settings = $this->get_settings();
		$branch   = isset( $settings['github_branch'] ) ? (string) $settings['github_branch'] : '';

		return '' !== $branch ? $branch : 'main';
	}

	/**
	 * Blueprint file path inside the repository.
	 *
	 * @since 1.0.0
	 * @return string
	 */
	public function get_blueprint_path() {
		$settings = $this->get_settings();
		$path     = isset( $settings['blueprint_path'] ) ? (string) $settings['blueprint_path'] : '';

		return '' !== $path ? $path : 'blueprints/latest.json';
	}

	/**
	 * Last successful sync timestamp (MySQL datetime or empty).
	 *
	 * @since 1.0.0
	 * @return string
	 */
	public function get_last_synced() {
		$settings = $this->get_settings();

		return isset( $settings['last_synced'] ) ? (string) $settings['last_synced'] : '';
	}

	/**
	 * Whether the minimum GitHub connection fields are configured.
	 *
	 * Token and repository are required; branch/path fall back to defaults.
	 *
	 * @since 1.0.0
	 * @return bool
	 */
	public function is_configured() {
		return '' !== $this->get_token() && '' !== $this->get_repo();
	}

	/**
	 * GitHub-specific settings subset for consumers.
	 *
	 * @since 1.0.0
	 * @return array{
	 *     github_token: string,
	 *     github_repo: string,
	 *     github_branch: string,
	 *     blueprint_path: string,
	 *     auto_detect: int,
	 *     last_synced: string
	 * }
	 */
	public function get_connection_settings() {
		$settings = $this->get_settings();

		return array(
			'github_token'   => $this->get_token(),
			'github_repo'    => $this->get_repo(),
			'github_branch'  => $this->get_branch(),
			'blueprint_path' => $this->get_blueprint_path(),
			'auto_detect'    => ! empty( $settings['auto_detect'] ) ? 1 : 0,
			'last_synced'    => $this->get_last_synced(),
		);
	}
}
