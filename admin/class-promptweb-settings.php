<?php
/**
 * Admin settings page and Settings API registration.
 *
 * Multisite-aware: works in Network Admin (site options) and
 * individual site admin (blog options).
 *
 * @package PromptWeb
 * @since   1.0.0
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers the PromptWeb admin menu and settings screen.
 *
 * @since 1.0.0
 */
class PromptWeb_Settings {

	/**
	 * Option name used for single-site and network storage.
	 *
	 * @since 1.0.0
	 * @var   string
	 */
	const OPTION_NAME = 'promptweb_settings';

	/**
	 * Option name for the stored blueprint JSON (source of truth cache after Sync).
	 *
	 * Maximum AI Creativity: this is the runtime copy of GitHub JSON used by
	 * Renderer + Editor — not Gutenberg post content.
	 *
	 * @since 1.0.0
	 * @var   string
	 */
	const BLUEPRINT_OPTION = 'promptweb_blueprint';

	/**
	 * Settings group (Settings API).
	 *
	 * @since 1.0.0
	 * @var   string
	 */
	const OPTION_GROUP = 'promptweb_settings_group';

	/**
	 * Menu / page slug.
	 *
	 * @since 1.0.0
	 * @var   string
	 */
	const PAGE_SLUG = 'promptweb';

	/**
	 * Network form action name (maps to network_admin_edit_{action}).
	 *
	 * @since 1.0.0
	 * @var   string
	 */
	const NETWORK_ACTION = 'promptweb_settings';

	/**
	 * Nonce action for manual "Sync Now".
	 *
	 * @since 1.0.0
	 * @var   string
	 */
	const SYNC_NONCE_ACTION = 'promptweb_sync';

	/**
	 * Nonce action for "Initialize AI-Ready Repository".
	 *
	 * @since 1.0.0
	 * @var   string
	 */
	const INIT_NONCE_ACTION = 'promptweb_init_repo';

	/**
	 * Nonce action for "Update Plugin from GitHub" (core code only).
	 *
	 * @since 1.0.0
	 * @var   string
	 */
	const UPDATE_PLUGIN_NONCE_ACTION = 'promptweb_update_plugin';

	/**
	 * Transient / site_transient prefix for sync admin notices.
	 *
	 * @since 1.0.0
	 * @var   string
	 */
	const SYNC_NOTICE_KEY = 'promptweb_sync_notice_';

	/**
	 * Option key: last successful repo initialization marker (per storage context).
	 *
	 * @since 1.0.0
	 * @var   string
	 */
	const INIT_META_OPTION = 'promptweb_repo_init_meta';

	/**
	 * Hook admin menus and settings registration.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public function init() {
		// Site admin menu.
		add_action( 'admin_menu', array( $this, 'add_admin_menu' ) );

		// Network admin menu (Multisite).
		add_action( 'network_admin_menu', array( $this, 'add_admin_menu' ) );

		// Register settings via the Settings API (single site / per-site).
		add_action( 'admin_init', array( $this, 'register_settings' ) );

		// Manual sync (site + network admin); runs early on admin_init.
		add_action( 'admin_init', array( $this, 'maybe_handle_sync' ) );

		// Initialize AI-ready repository (writes starter files to GitHub).
		add_action( 'admin_init', array( $this, 'maybe_handle_init_repo' ) );

		// Update plugin code from public core repo (never touches blueprint options).
		add_action( 'admin_init', array( $this, 'maybe_handle_plugin_update' ) );

		// Network / network-active settings are not saved through options.php.
		add_action( 'network_admin_edit_' . self::NETWORK_ACTION, array( $this, 'save_network_settings' ) );
		// Site dashboard when network-active: same storage, custom POST handler.
		add_action( 'admin_init', array( $this, 'maybe_handle_network_storage_settings_save' ) );
	}

	/**
	 * Whether the current request is in Network Admin.
	 *
	 * @since 1.0.0
	 * @return bool
	 */
	public function is_network_context() {
		return is_multisite() && is_network_admin();
	}

	/**
	 * Whether the plugin is network-activated.
	 *
	 * @since 1.0.0
	 * @return bool
	 */
	public static function is_plugin_network_active() {
		if ( ! is_multisite() ) {
			return false;
		}

		if ( ! function_exists( 'is_plugin_active_for_network' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		return is_plugin_active_for_network( PROMPTWEB_PLUGIN_BASENAME );
	}

	/**
	 * Whether settings should use network (site) options for runtime reads.
	 *
	 * Admin UI still uses request context (network admin vs site admin).
	 * Runtime (e.g. GitHub helpers) prefers network options when network-active.
	 *
	 * @since 1.0.0
	 * @return bool
	 */
	public static function use_network_options() {
		if ( ! is_multisite() ) {
			return false;
		}

		if ( is_network_admin() ) {
			return true;
		}

		return self::is_plugin_network_active();
	}

	/**
	 * Capability required to manage settings in the current context.
	 *
	 * Network-activated installs require manage_network_options (even from a site
	 * dashboard) so GitHub credentials stay network-scoped and consistent.
	 *
	 * @since 1.0.0
	 * @return string
	 */
	public function get_capability() {
		if ( is_multisite() && self::is_plugin_network_active() ) {
			return 'manage_network_options';
		}

		return $this->is_network_context() ? 'manage_network_options' : 'manage_options';
	}

	/**
	 * Add the top-level PromptWeb menu.
	 *
	 * Bound to both `admin_menu` and `network_admin_menu`.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public function add_admin_menu() {
		add_menu_page(
			__( 'PromptWeb Settings', 'promptweb' ),
			__( 'PromptWeb', 'promptweb' ),
			$this->get_capability(),
			self::PAGE_SLUG,
			array( $this, 'render_settings_page' ),
			'dashicons-admin-site-alt3',
			58
		);
	}

	/**
	 * Register settings, sections, and fields (Settings API).
	 *
	 * Used for single-site saves via options.php. Field callbacks are
	 * also reused when rendering the network settings form.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public function register_settings() {
		register_setting(
			self::OPTION_GROUP,
			self::OPTION_NAME,
			array(
				'type'              => 'array',
				'description'       => __( 'PromptWeb plugin settings.', 'promptweb' ),
				'sanitize_callback' => array( $this, 'sanitize_settings' ),
				'default'           => self::get_default_settings(),
				'show_in_rest'      => false,
			)
		);

		// -------------------------------------------------------------------------
		// Section: General Settings
		// -------------------------------------------------------------------------
		add_settings_section(
			'promptweb_general_section',
			__( 'General Settings', 'promptweb' ),
			array( $this, 'render_general_section' ),
			self::PAGE_SLUG
		);

		add_settings_field(
			'promptweb_enabled',
			__( 'Enable PromptWeb', 'promptweb' ),
			array( $this, 'render_enabled_field' ),
			self::PAGE_SLUG,
			'promptweb_general_section',
			array(
				'label_for' => 'promptweb_enabled',
			)
		);

		add_settings_field(
			'promptweb_auto_detect',
			__( 'Auto-Detect', 'promptweb' ),
			array( $this, 'render_auto_detect_field' ),
			self::PAGE_SLUG,
			'promptweb_general_section',
			array(
				'label_for' => 'promptweb_auto_detect',
			)
		);

		add_settings_field(
			'promptweb_last_synced',
			__( 'Last Synced', 'promptweb' ),
			array( $this, 'render_last_synced_field' ),
			self::PAGE_SLUG,
			'promptweb_general_section'
		);

		// -------------------------------------------------------------------------
		// Section: GitHub Connection
		// -------------------------------------------------------------------------
		add_settings_section(
			'promptweb_github_section',
			__( 'GitHub Connection', 'promptweb' ),
			array( $this, 'render_github_section' ),
			self::PAGE_SLUG
		);

		add_settings_field(
			'promptweb_github_token',
			__( 'Personal Access Token', 'promptweb' ),
			array( $this, 'render_github_token_field' ),
			self::PAGE_SLUG,
			'promptweb_github_section',
			array(
				'label_for' => 'promptweb_github_token',
			)
		);

		add_settings_field(
			'promptweb_github_repo',
			__( 'Repository', 'promptweb' ),
			array( $this, 'render_github_repo_field' ),
			self::PAGE_SLUG,
			'promptweb_github_section',
			array(
				'label_for' => 'promptweb_github_repo',
			)
		);

		add_settings_field(
			'promptweb_github_branch',
			__( 'Branch', 'promptweb' ),
			array( $this, 'render_github_branch_field' ),
			self::PAGE_SLUG,
			'promptweb_github_section',
			array(
				'label_for' => 'promptweb_github_branch',
			)
		);

		add_settings_field(
			'promptweb_blueprint_path',
			__( 'Blueprint Path', 'promptweb' ),
			array( $this, 'render_blueprint_path_field' ),
			self::PAGE_SLUG,
			'promptweb_github_section',
			array(
				'label_for' => 'promptweb_blueprint_path',
			)
		);
	}

	/**
	 * Default settings values.
	 *
	 * @since 1.0.0
	 * @return array
	 */
	public static function get_default_settings() {
		return array(
			'enabled'        => 0,
			'auto_detect'    => 1, // ON by default.
			'github_token'   => '',
			'github_repo'    => '',
			'github_branch'  => 'main',
			'blueprint_path' => 'blueprints/latest.json',
			'last_synced'    => '',
		);
	}

	/**
	 * Retrieve settings for the admin UI.
	 *
	 * Uses network (site) options when the plugin is network-activated or when
	 * viewing Network Admin — matching runtime storage (use_network_options()).
	 *
	 * @since 1.0.0
	 * @return array
	 */
	public function get_settings() {
		return self::get_settings_data( self::use_network_options() );
	}

	/**
	 * Retrieve settings for runtime use (Multisite-aware).
	 *
	 * Prefer network options when the plugin is network-activated.
	 *
	 * @since 1.0.0
	 * @return array
	 */
	public static function get_runtime_settings() {
		return self::get_settings_data( self::use_network_options() );
	}

	/**
	 * Load and merge stored settings with defaults.
	 *
	 * @since 1.0.0
	 * @param bool $use_network Whether to read network (site) options.
	 * @return array
	 */
	public static function get_settings_data( $use_network = false ) {
		$defaults = self::get_default_settings();

		if ( $use_network ) {
			$settings = get_site_option( self::OPTION_NAME, array() );
		} else {
			$settings = get_option( self::OPTION_NAME, array() );
		}

		if ( ! is_array( $settings ) ) {
			$settings = array();
		}

		return wp_parse_args( $settings, $defaults );
	}

	/**
	 * Sanitize settings before save.
	 *
	 * @since 1.0.0
	 * @param mixed $input Raw input from the form.
	 * @return array
	 */
	public function sanitize_settings( $input ) {
		$defaults = self::get_default_settings();
		$existing = $this->get_settings();
		$output   = $defaults;

		if ( ! is_array( $input ) ) {
			$input = array();
		}

		// Checkboxes: present when checked, absent when unchecked.
		$output['enabled']     = ! empty( $input['enabled'] ) ? 1 : 0;
		$output['auto_detect'] = ! empty( $input['auto_detect'] ) ? 1 : 0;

		// GitHub token (password field): keep existing if left blank so users
		// do not have to re-enter the token on every save.
		if ( isset( $input['github_token'] ) && '' !== trim( (string) $input['github_token'] ) ) {
			$output['github_token'] = self::sanitize_github_token( $input['github_token'] );
		} else {
			$output['github_token'] = isset( $existing['github_token'] ) ? $existing['github_token'] : '';
		}

		// Repository: owner/name (e.g. username/repository).
		$repo = isset( $input['github_repo'] ) ? $input['github_repo'] : '';
		$output['github_repo'] = self::sanitize_github_repo( $repo );

		// Branch name.
		$branch = isset( $input['github_branch'] ) ? $input['github_branch'] : $defaults['github_branch'];
		$branch = sanitize_text_field( wp_unslash( $branch ) );
		$output['github_branch'] = '' !== $branch ? $branch : $defaults['github_branch'];

		// Blueprint path relative to repo root.
		$path = isset( $input['blueprint_path'] ) ? $input['blueprint_path'] : $defaults['blueprint_path'];
		$path = sanitize_text_field( wp_unslash( $path ) );
		$path = ltrim( str_replace( '\\', '/', $path ), '/' );
		$output['blueprint_path'] = '' !== $path ? $path : $defaults['blueprint_path'];

		// last_synced is system-managed / read-only — never accept from form input.
		$output['last_synced'] = isset( $existing['last_synced'] ) ? $existing['last_synced'] : '';

		/**
		 * Filters sanitized PromptWeb settings before they are stored.
		 *
		 * @since 1.0.0
		 * @param array $output   Sanitized settings.
		 * @param array $input    Raw input.
		 * @param array $existing Previously stored settings.
		 */
		return apply_filters( 'promptweb_sanitize_settings', $output, $input, $existing );
	}

	/**
	 * Sanitize a GitHub personal access token.
	 *
	 * Strips tags and control characters; does not log or expose the value.
	 *
	 * @since 1.0.0
	 * @param mixed $token Raw token value.
	 * @return string
	 */
	public static function sanitize_github_token( $token ) {
		$token = sanitize_text_field( wp_unslash( (string) $token ) );
		// Tokens should be single-line secrets without whitespace.
		$token = preg_replace( '/\s+/', '', $token );

		return is_string( $token ) ? $token : '';
	}

	/**
	 * Sanitize a GitHub repository slug (owner/repo).
	 *
	 * @since 1.0.0
	 * @param mixed $repo Raw repository value.
	 * @return string
	 */
	public static function sanitize_github_repo( $repo ) {
		$repo = sanitize_text_field( wp_unslash( (string) $repo ) );
		$repo = trim( $repo );

		// Allow optional github.com URL paste → reduce to owner/repo.
		if ( preg_match( '#github\.com[:/]([^/]+/[^/]+?)(?:\.git)?/?$#i', $repo, $matches ) ) {
			$repo = $matches[1];
		}

		// Keep only valid owner/repo characters.
		if ( ! preg_match( '#^[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+$#', $repo ) ) {
			// Soft-sanitize: strip invalid characters but preserve a slash if present.
			$repo = preg_replace( '#[^A-Za-z0-9_./-]#', '', $repo );
		}

		return $repo;
	}

	/**
	 * Save settings from Network Admin.
	 *
	 * WordPress network options cannot use options.php; this handler
	 * runs via network_admin_edit_{action}.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public function save_network_settings() {
		if ( ! current_user_can( 'manage_network_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to manage these settings.', 'promptweb' ) );
		}

		check_admin_referer( 'promptweb_network_settings' );

		$raw   = isset( $_POST[ self::OPTION_NAME ] ) ? wp_unslash( $_POST[ self::OPTION_NAME ] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$clean = $this->sanitize_settings( $raw );

		update_site_option( self::OPTION_NAME, $clean );

		$redirect = add_query_arg(
			array(
				'page'             => self::PAGE_SLUG,
				'settings-updated' => 'true',
			),
			network_admin_url( 'admin.php' )
		);

		wp_safe_redirect( $redirect );
		exit;
	}

	/**
	 * Save settings when storage is network-scoped but the form was posted
	 * from a site admin screen (network-active plugin + super admin).
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public function maybe_handle_network_storage_settings_save() {
		if ( empty( $_POST['promptweb_save_network_storage'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			return;
		}

		if ( empty( $_GET['page'] ) || self::PAGE_SLUG !== $_GET['page'] ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		}

		// Only when we are not already in the network_admin_edit handler path.
		if ( is_network_admin() ) {
			return;
		}

		if ( ! self::use_network_options() ) {
			return;
		}

		if ( ! current_user_can( $this->get_capability() ) ) {
			wp_die( esc_html__( 'You do not have permission to manage these settings.', 'promptweb' ) );
		}

		check_admin_referer( 'promptweb_network_settings' );

		$raw   = isset( $_POST[ self::OPTION_NAME ] ) ? wp_unslash( $_POST[ self::OPTION_NAME ] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$clean = $this->sanitize_settings( $raw );

		update_site_option( self::OPTION_NAME, $clean );

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'             => self::PAGE_SLUG,
					'settings-updated' => 'true',
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	/**
	 * Persist last_synced timestamp (system update, not form-driven).
	 *
	 * @since 1.0.0
	 * @param string|int $timestamp MySQL datetime string or Unix timestamp.
	 * @param bool|null  $network   Force network storage; null = auto-detect.
	 * @return bool
	 */
	public static function update_last_synced( $timestamp = null, $network = null ) {
		if ( null === $timestamp ) {
			$timestamp = current_time( 'mysql' );
		} elseif ( is_numeric( $timestamp ) ) {
			$timestamp = gmdate( 'Y-m-d H:i:s', (int) $timestamp );
		} else {
			$timestamp = sanitize_text_field( (string) $timestamp );
		}

		if ( null === $network ) {
			$network = self::use_network_options();
		}

		$settings                = self::get_settings_data( (bool) $network );
		$settings['last_synced'] = $timestamp;

		if ( $network ) {
			return update_site_option( self::OPTION_NAME, $settings );
		}

		return update_option( self::OPTION_NAME, $settings );
	}

	/**
	 * Store the synced blueprint JSON (Multisite-aware).
	 *
	 * JSON-first path: GitHub remains source of truth; this option is the local
	 * cache for Renderer / Editor on the current network or site context.
	 *
	 * @since 1.0.0
	 * @param mixed     $blueprint Blueprint array (or null to clear).
	 * @param bool|null $network   Force network storage; null = auto-detect.
	 * @return bool
	 */
	public static function save_blueprint( $blueprint, $network = null ) {
		if ( null === $network ) {
			$network = self::use_network_options();
		}

		if ( null === $blueprint ) {
			if ( $network ) {
				return delete_site_option( self::BLUEPRINT_OPTION );
			}
			return delete_option( self::BLUEPRINT_OPTION );
		}

		if ( ! is_array( $blueprint ) ) {
			return false;
		}

		if ( $network ) {
			return update_site_option( self::BLUEPRINT_OPTION, $blueprint );
		}

		return update_option( self::BLUEPRINT_OPTION, $blueprint, false );
	}

	/**
	 * Retrieve the stored blueprint JSON (Multisite-aware).
	 *
	 * @since 1.0.0
	 * @param bool|null $network Force network read; null = auto-detect.
	 * @return array Empty array when none stored.
	 */
	public static function get_blueprint( $network = null ) {
		if ( null === $network ) {
			$network = self::use_network_options();
		}

		if ( $network ) {
			$blueprint = get_site_option( self::BLUEPRINT_OPTION, array() );
		} else {
			$blueprint = get_option( self::BLUEPRINT_OPTION, array() );
		}

		return is_array( $blueprint ) ? $blueprint : array();
	}

	// -------------------------------------------------------------------------
	// Section descriptions
	// -------------------------------------------------------------------------

	/**
	 * General Settings section description.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public function render_general_section() {
		echo '<p>' . esc_html__( 'Configure general PromptWeb options and auto-detect behavior. Architecture v2 design pages (static HTML / dynamic PHP) and legacy blueprints render when enabled or when design data is present.', 'promptweb' ) . '</p>';
	}

	/**
	 * GitHub Connection section description.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public function render_github_section() {
		echo '<p>' . esc_html__( 'Connect PromptWeb to a design GitHub repository (pages/static, pages/dynamic, manifest, and optional legacy blueprints). Plugin code updates use a separate repo and never delete design data. GitHub remains the source of truth.', 'promptweb' ) . '</p>';
	}

	/**
	 * Absolute URL for the PromptWeb settings screen (site or network).
	 *
	 * @since 1.0.0
	 * @return string
	 */
	public function get_settings_page_url() {
		$path = 'admin.php?page=' . self::PAGE_SLUG;

		if ( $this->is_network_context() ) {
			return network_admin_url( $path );
		}

		return admin_url( $path );
	}

	/**
	 * Handle "Sync Now" form submission (nonce + capability protected).
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public function maybe_handle_sync() {
		if ( empty( $_POST['promptweb_do_sync'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			return;
		}

		// Only process on our settings screen.
		if ( empty( $_GET['page'] ) || self::PAGE_SLUG !== $_GET['page'] ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		}

		if ( ! current_user_can( $this->get_capability() ) ) {
			wp_die( esc_html__( 'You do not have permission to sync PromptWeb.', 'promptweb' ) );
		}

		check_admin_referer( self::SYNC_NONCE_ACTION, 'promptweb_sync_nonce' );

		$github = function_exists( 'promptweb' ) ? promptweb()->github : null;

		if ( ! $github instanceof PromptWeb_GitHub ) {
			$this->store_sync_notice(
				array(
					'success' => false,
					'message' => __( 'GitHub component is not available.', 'promptweb' ),
				)
			);
			$this->redirect_after_sync();
		}

		// Match runtime storage (network-active → site options).
		$result = $github->sync(
			array(
				'use_network' => self::use_network_options(),
			)
		);

		$this->store_sync_notice(
			array(
				'success' => ! empty( $result['success'] ),
				'message' => isset( $result['message'] ) ? $result['message'] : '',
				'code'    => isset( $result['code'] ) ? $result['code'] : '',
			)
		);

		$this->redirect_after_sync();
	}

	/**
	 * Persist a one-time sync notice for the current user.
	 *
	 * @since 1.0.0
	 * @param array $notice Notice payload (success, message, code).
	 * @return void
	 */
	private function store_sync_notice( array $notice ) {
		$key = self::SYNC_NOTICE_KEY . get_current_user_id();

		// Network Admin (or network-scoped config): site transient for this user.
		if ( $this->is_network_context() || self::use_network_options() ) {
			set_site_transient( $key, $notice, 60 );
			return;
		}

		set_transient( $key, $notice, 60 );
	}

	/**
	 * Read and clear a one-time sync notice for the current user.
	 *
	 * @since 1.0.0
	 * @return array|null
	 */
	private function consume_sync_notice() {
		$key = self::SYNC_NOTICE_KEY . get_current_user_id();

		if ( $this->is_network_context() || self::use_network_options() ) {
			$notice = get_site_transient( $key );
			if ( false !== $notice ) {
				delete_site_transient( $key );
			}
		} else {
			$notice = get_transient( $key );
			if ( false !== $notice ) {
				delete_transient( $key );
			}
		}

		return is_array( $notice ) ? $notice : null;
	}

	/**
	 * Redirect back to settings after sync (PRG pattern).
	 *
	 * @since 1.0.0
	 * @return void
	 */
	private function redirect_after_sync() {
		wp_safe_redirect( $this->get_settings_page_url() );
		exit;
	}

	/**
	 * Handle "Initialize AI-Ready Repository" (capability + nonce protected).
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public function maybe_handle_init_repo() {
		if ( empty( $_POST['promptweb_do_init_repo'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			return;
		}

		if ( empty( $_GET['page'] ) || self::PAGE_SLUG !== $_GET['page'] ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		}

		if ( ! current_user_can( $this->get_capability() ) ) {
			wp_die( esc_html__( 'You do not have permission to initialize the PromptWeb repository.', 'promptweb' ) );
		}

		check_admin_referer( self::INIT_NONCE_ACTION, 'promptweb_init_nonce' );

		$github = function_exists( 'promptweb' ) ? promptweb()->github : null;

		if ( ! $github instanceof PromptWeb_GitHub ) {
			$this->store_sync_notice(
				array(
					'success' => false,
					'message' => __( 'GitHub component is not available.', 'promptweb' ),
				)
			);
			$this->redirect_after_sync();
		}

		$use_network = self::use_network_options();
		$force       = ! empty( $_POST['promptweb_init_force'] ); // phpcs:ignore WordPress.Security.NonceVerification.Missing

		// Optional: block accidental overwrite when already ready (unless force / Re-initialize).
		if ( ! $force ) {
			$status = $github->get_initialization_status( $use_network );
			if ( ! empty( $status['ready'] ) ) {
				$this->store_sync_notice(
					array(
						'success' => true,
						'message' => __( 'Repository already looks AI-ready (pages/ or blueprint + AI_INSTRUCTIONS.md). Use “Re-initialize” to refresh starter home and guides. Existing custom pages and blueprints are not deleted.', 'promptweb' ),
						'code'    => 'promptweb_already_initialized',
					)
				);
				$this->redirect_after_sync();
			}
		}

		// First-time init: force=false still creates missing files; Re-init uses force=true to refresh starter home + guides.
		$result = $github->initialize_repository(
			array(
				'use_network' => $use_network,
				'force'       => (bool) $force,
			)
		);

		if ( ! empty( $result['success'] ) ) {
			$this->store_init_meta(
				array(
					'repo'           => isset( $result['data']['repo'] ) ? $result['data']['repo'] : '',
					'branch'         => isset( $result['data']['branch'] ) ? $result['data']['branch'] : '',
					'blueprint_path' => isset( $result['data']['blueprint_path'] ) ? $result['data']['blueprint_path'] : '',
					'initialized_at' => current_time( 'mysql' ),
				),
				$use_network
			);
		}

		$this->store_sync_notice(
			array(
				'success' => ! empty( $result['success'] ),
				'message' => isset( $result['message'] ) ? $result['message'] : '',
				'code'    => isset( $result['code'] ) ? $result['code'] : '',
			)
		);

		$this->redirect_after_sync();
	}

	/**
	 * Persist initialization marker (Multisite-aware).
	 *
	 * @since 1.0.0
	 * @param array $meta        Meta payload.
	 * @param bool  $use_network Network storage.
	 * @return void
	 */
	private function store_init_meta( array $meta, $use_network ) {
		// Prefer explicit flag; fall back to runtime network detection.
		if ( $use_network || self::use_network_options() ) {
			update_site_option( self::INIT_META_OPTION, $meta );
			return;
		}
		update_option( self::INIT_META_OPTION, $meta, false );
	}

	/**
	 * Read initialization marker for the current settings context.
	 *
	 * @since 1.0.0
	 * @return array
	 */
	private function get_init_meta() {
		$use_network = self::use_network_options();
		if ( $use_network ) {
			$meta = get_site_option( self::INIT_META_OPTION, array() );
		} else {
			$meta = get_option( self::INIT_META_OPTION, array() );
		}
		return is_array( $meta ) ? $meta : array();
	}

	// -------------------------------------------------------------------------
	// Field renderers — General
	// -------------------------------------------------------------------------

	/**
	 * "Enable PromptWeb" checkbox field.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public function render_enabled_field() {
		$settings = $this->get_settings();
		$enabled  = ! empty( $settings['enabled'] );
		$name     = self::OPTION_NAME . '[enabled]';
		?>
		<label for="promptweb_enabled">
			<input
				type="checkbox"
				id="promptweb_enabled"
				name="<?php echo esc_attr( $name ); ?>"
				value="1"
				<?php checked( $enabled, true ); ?>
			/>
			<?php esc_html_e( 'Enable PromptWeb on the public website.', 'promptweb' ); ?>
		</label>
		<p class="description">
			<?php esc_html_e( 'When design pages or a blueprint are stored (after Sync or Initialize), they render on the public site automatically. Uncheck and clear design data to fully disable frontend output. The on-page visual editor is temporarily disabled when v2 design pages are active; use MCP/AI tools instead.', 'promptweb' ); ?>
		</p>
		<?php
	}

	/**
	 * "Auto-Detect" checkbox field (default ON).
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public function render_auto_detect_field() {
		$settings    = $this->get_settings();
		$auto_detect = ! empty( $settings['auto_detect'] );
		$name        = self::OPTION_NAME . '[auto_detect]';
		?>
		<label for="promptweb_auto_detect">
			<input
				type="checkbox"
				id="promptweb_auto_detect"
				name="<?php echo esc_attr( $name ); ?>"
				value="1"
				<?php checked( $auto_detect, true ); ?>
			/>
			<?php esc_html_e( 'Auto-sync design from GitHub on page views (recommended).', 'promptweb' ); ?>
		</label>
		<p class="description">
			<?php esc_html_e( 'When enabled, visitors and editors get the latest blueprint automatically (throttled, skips unchanged files). Manual Sync remains available as a backup only. Connection settings are never wiped.', 'promptweb' ); ?>
		</p>
		<?php
	}

	/**
	 * Format a last_synced MySQL datetime for admin display.
	 *
	 * @since 1.0.0
	 * @param string $last_synced Stored datetime string.
	 * @return string
	 */
	public function format_last_synced_display( $last_synced ) {
		if ( empty( $last_synced ) ) {
			return __( 'Never', 'promptweb' );
		}

		$timestamp = strtotime( $last_synced );

		if ( false === $timestamp ) {
			return (string) $last_synced;
		}

		return sprintf(
			/* translators: 1: date, 2: time */
			__( '%1$s at %2$s', 'promptweb' ),
			date_i18n( get_option( 'date_format' ), $timestamp ),
			date_i18n( get_option( 'time_format' ), $timestamp )
		);
	}

	/**
	 * Read-only "Last Synced" display.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public function render_last_synced_field() {
		$settings    = $this->get_settings();
		$last_synced = isset( $settings['last_synced'] ) ? $settings['last_synced'] : '';
		$display     = $this->format_last_synced_display( $last_synced );
		?>
		<p>
			<code id="promptweb_last_synced"><?php echo esc_html( $display ); ?></code>
		</p>
		<p class="description">
			<?php esc_html_e( 'Updated when a sync completes (manual or automatic). Not editable.', 'promptweb' ); ?>
		</p>
		<?php
	}

	// -------------------------------------------------------------------------
	// Field renderers — GitHub Connection
	// -------------------------------------------------------------------------

	/**
	 * GitHub personal access token (password input).
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public function render_github_token_field() {
		$settings = $this->get_settings();
		$has_token = ! empty( $settings['github_token'] );
		$name      = self::OPTION_NAME . '[github_token]';
		?>
		<input
			type="password"
			id="promptweb_github_token"
			name="<?php echo esc_attr( $name ); ?>"
			value=""
			class="regular-text"
			autocomplete="new-password"
			spellcheck="false"
			placeholder="<?php echo $has_token ? esc_attr__( '••••••••••••••••', 'promptweb' ) : ''; ?>"
		/>
		<p class="description">
			<?php
			if ( $has_token ) {
				esc_html_e( 'A token is saved. Leave blank to keep the current token, or enter a new one to replace it.', 'promptweb' );
			} else {
				esc_html_e( 'GitHub Personal Access Token with access to the repository (classic: repo scope, or fine-grained: Contents read).', 'promptweb' );
			}
			?>
		</p>
		<?php
	}

	/**
	 * GitHub repository field (owner/repo).
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public function render_github_repo_field() {
		$settings = $this->get_settings();
		$value    = isset( $settings['github_repo'] ) ? $settings['github_repo'] : '';
		$name     = self::OPTION_NAME . '[github_repo]';
		?>
		<input
			type="text"
			id="promptweb_github_repo"
			name="<?php echo esc_attr( $name ); ?>"
			value="<?php echo esc_attr( $value ); ?>"
			class="regular-text"
			placeholder="<?php esc_attr_e( 'username/repository', 'promptweb' ); ?>"
			spellcheck="false"
		/>
		<p class="description">
			<?php esc_html_e( 'Format: owner/repository (example: username/repository).', 'promptweb' ); ?>
		</p>
		<?php
	}

	/**
	 * GitHub branch field.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public function render_github_branch_field() {
		$settings = $this->get_settings();
		$value    = isset( $settings['github_branch'] ) ? $settings['github_branch'] : 'main';
		$name     = self::OPTION_NAME . '[github_branch]';
		?>
		<input
			type="text"
			id="promptweb_github_branch"
			name="<?php echo esc_attr( $name ); ?>"
			value="<?php echo esc_attr( $value ); ?>"
			class="regular-text"
			placeholder="main"
			spellcheck="false"
		/>
		<p class="description">
			<?php esc_html_e( 'Branch to read blueprints from. Default: main.', 'promptweb' ); ?>
		</p>
		<?php
	}

	/**
	 * Blueprint path field.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public function render_blueprint_path_field() {
		$settings = $this->get_settings();
		$value    = isset( $settings['blueprint_path'] ) ? $settings['blueprint_path'] : 'blueprints/latest.json';
		$name     = self::OPTION_NAME . '[blueprint_path]';
		?>
		<input
			type="text"
			id="promptweb_blueprint_path"
			name="<?php echo esc_attr( $name ); ?>"
			value="<?php echo esc_attr( $value ); ?>"
			class="regular-text"
			placeholder="blueprints/latest.json"
			spellcheck="false"
		/>
		<p class="description">
			<?php esc_html_e( 'Legacy JSON blueprint path (optional). Architecture v2 prefers pages/static and pages/dynamic. Default: blueprints/latest.json — existing blueprints keep working.', 'promptweb' ); ?>
		</p>
		<?php
	}

	// -------------------------------------------------------------------------
	// Page shell
	// -------------------------------------------------------------------------

	/**
	 * Render the settings page markup.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public function render_settings_page() {
		if ( ! current_user_can( $this->get_capability() ) ) {
			return;
		}

		$is_network = $this->is_network_context();
		?>
		<div class="wrap promptweb-settings-wrap">
			<h1><?php echo esc_html( get_admin_page_title() ); ?></h1>
			<?php $this->render_settings_page_styles(); ?>

			<?php $this->render_admin_notices( $is_network ); ?>

			<?php if ( self::use_network_options() && ! $is_network ) : ?>
				<div class="notice notice-info inline">
					<p>
						<?php esc_html_e( 'PromptWeb is network-activated. Settings are stored network-wide (same as Network Admin → PromptWeb).', 'promptweb' ); ?>
					</p>
				</div>
			<?php endif; ?>

			<?php $this->render_architecture_helper_panel(); ?>

			<?php if ( self::use_network_options() ) : ?>
				<?php
				// Network Admin: dedicated edit.php action. Site dashboard (super admin): local POST handler.
				$form_action = $is_network
					? network_admin_url( 'edit.php?action=' . self::NETWORK_ACTION )
					: $this->get_settings_page_url();
				?>
				<form method="post" action="<?php echo esc_url( $form_action ); ?>">
					<?php wp_nonce_field( 'promptweb_network_settings' ); ?>
					<?php if ( ! $is_network ) : ?>
						<input type="hidden" name="promptweb_save_network_storage" value="1" />
					<?php endif; ?>
					<?php $this->render_settings_fields(); ?>
					<?php submit_button( __( 'Save Changes', 'promptweb' ) ); ?>
				</form>
			<?php else : ?>
				<form method="post" action="options.php">
					<?php
					settings_fields( self::OPTION_GROUP );
					$this->render_settings_fields();
					submit_button( __( 'Save Changes', 'promptweb' ) );
					?>
				</form>
			<?php endif; ?>

			<?php $this->render_design_pages_status_panel(); ?>
			<?php $this->render_mcp_status_panel(); ?>
			<?php $this->render_plugin_update_panel(); ?>
			<?php $this->render_init_panel(); ?>
			<?php $this->render_sync_panel(); ?>
		</div>
		<?php
	}

	/**
	 * Lightweight admin styles for Architecture v2 status panels.
	 *
	 * @since 2.0.0
	 * @return void
	 */
	private function render_settings_page_styles() {
		?>
		<style>
			.promptweb-settings-wrap .promptweb-card {
				background: #fff;
				border: 1px solid #c3c4c7;
				border-left-width: 4px;
				box-shadow: 0 1px 1px rgba(0,0,0,.04);
				margin: 16px 0 20px;
				padding: 12px 16px 16px;
			}
			.promptweb-settings-wrap .promptweb-card--info { border-left-color: #2271b1; }
			.promptweb-settings-wrap .promptweb-card--pages { border-left-color: #00a32a; }
			.promptweb-settings-wrap .promptweb-card--mcp { border-left-color: #9b51e0; }
			.promptweb-settings-wrap .promptweb-card h2 {
				margin: 0 0 8px;
				padding: 0;
				font-size: 1.15em;
			}
			.promptweb-settings-wrap .promptweb-stat-grid {
				display: flex;
				flex-wrap: wrap;
				gap: 10px;
				margin: 12px 0;
			}
			.promptweb-settings-wrap .promptweb-stat {
				background: #f6f7f7;
				border: 1px solid #dcdcde;
				border-radius: 4px;
				min-width: 110px;
				padding: 10px 14px;
			}
			.promptweb-settings-wrap .promptweb-stat__value {
				display: block;
				font-size: 1.4em;
				font-weight: 600;
				line-height: 1.2;
				color: #1d2327;
			}
			.promptweb-settings-wrap .promptweb-stat__label {
				display: block;
				margin-top: 2px;
				color: #646970;
				font-size: 12px;
			}
			.promptweb-settings-wrap .promptweb-badge {
				display: inline-block;
				border-radius: 3px;
				font-size: 12px;
				font-weight: 600;
				line-height: 1.4;
				padding: 2px 8px;
				margin-right: 6px;
			}
			.promptweb-settings-wrap .promptweb-badge--ok {
				background: #edfaef;
				color: #00a32a;
			}
			.promptweb-settings-wrap .promptweb-badge--warn {
				background: #fcf9e8;
				color: #996800;
			}
			.promptweb-settings-wrap .promptweb-badge--muted {
				background: #f0f0f1;
				color: #50575e;
			}
			.promptweb-settings-wrap .promptweb-badge--draft {
				background: #f0f6fc;
				color: #2271b1;
			}
			.promptweb-settings-wrap .promptweb-badge--publish {
				background: #edfaef;
				color: #007017;
			}
			.promptweb-settings-wrap .promptweb-slug-list {
				margin: 8px 0 0;
				padding-left: 1.2em;
			}
			.promptweb-settings-wrap .promptweb-slug-list li {
				margin: 4px 0;
			}
			.promptweb-settings-wrap .promptweb-helper-list {
				margin: 8px 0 0 1.2em;
				list-style: disc;
			}
			.promptweb-settings-wrap .promptweb-helper-list li {
				margin: 4px 0;
			}
			.promptweb-settings-wrap code.promptweb-path {
				font-size: 12px;
			}
		</style>
		<?php
	}

	/**
	 * Architecture v2 helper text for site owners.
	 *
	 * @since 2.0.0
	 * @return void
	 */
	private function render_architecture_helper_panel() {
		$version = defined( 'PROMPTWEB_VERSION' ) ? PROMPTWEB_VERSION : '';
		?>
		<div class="promptweb-card promptweb-card--info">
			<h2>
				<?php esc_html_e( 'Architecture v2 — Design & AI', 'promptweb' ); ?>
				<?php if ( $version ) : ?>
					<span class="description" style="font-weight:400;">· <?php echo esc_html( sprintf( /* translators: %s: version */ __( 'Plugin %s', 'promptweb' ), $version ) ); ?></span>
				<?php endif; ?>
			</h2>
			<p class="description" style="margin-top:0;">
				<?php esc_html_e( 'PromptWeb gives AI agents full creative freedom to build your site. GitHub remains the source of truth for design files.', 'promptweb' ); ?>
			</p>
			<ul class="promptweb-helper-list">
				<li><?php esc_html_e( 'AI can create static HTML pages (Tailwind via CDN + JavaScript) or dynamic PHP + WordPress pages.', 'promptweb' ); ?></li>
				<li><?php esc_html_e( 'New pages start as Draft — visitors only see published pages.', 'promptweb' ); ?></li>
				<li><?php esc_html_e( 'Publish only when design quality is high (use visual analysis after create/update).', 'promptweb' ); ?></li>
				<li><?php esc_html_e( 'GitHub remains the source of truth — Sync / Auto-sync pull design into WordPress; commit pushes changes back.', 'promptweb' ); ?></li>
			</ul>
			<p class="description" style="margin-bottom:0;">
				<code class="promptweb-path">pages/static/</code>
				·
				<code class="promptweb-path">pages/dynamic/</code>
				·
				<code class="promptweb-path">pages/manifest.json</code>
				·
				<code class="promptweb-path">AI_INSTRUCTIONS.md</code>
			</p>
		</div>
		<?php
	}

	/**
	 * Collect design pages statistics for the current storage context.
	 *
	 * @since 2.0.0
	 * @return array{
	 *     total:int,
	 *     static:int,
	 *     dynamic:int,
	 *     draft:int,
	 *     publish:int,
	 *     slugs:array<int,array{slug:string,type:string,status:string,title:string,is_front_page:bool}>,
	 *     has_pages:bool
	 * }
	 */
	private function get_design_pages_stats() {
		$stats = array(
			'total'     => 0,
			'static'    => 0,
			'dynamic'   => 0,
			'draft'     => 0,
			'publish'   => 0,
			'slugs'     => array(),
			'has_pages' => false,
		);

		if ( ! class_exists( 'PromptWeb_Pages' ) ) {
			return $stats;
		}

		$pages_mgr = function_exists( 'promptweb' ) && isset( promptweb()->pages ) && promptweb()->pages instanceof PromptWeb_Pages
			? promptweb()->pages
			: new PromptWeb_Pages();

		$list = $pages_mgr->list_pages(
			array(
				'status' => 'all',
				'type'   => 'all',
			)
		);

		$pages = isset( $list['pages'] ) && is_array( $list['pages'] ) ? $list['pages'] : array();
		$stats['total']     = count( $pages );
		$stats['has_pages'] = $stats['total'] > 0;

		foreach ( $pages as $page ) {
			if ( ! is_array( $page ) ) {
				continue;
			}
			$type   = isset( $page['type'] ) ? (string) $page['type'] : 'static';
			$status = isset( $page['status'] ) ? (string) $page['status'] : 'draft';
			$slug   = isset( $page['slug'] ) ? (string) $page['slug'] : '';

			if ( 'dynamic' === $type ) {
				$stats['dynamic']++;
			} else {
				$stats['static']++;
			}
			if ( 'publish' === $status ) {
				$stats['publish']++;
			} else {
				$stats['draft']++;
			}

			if ( '' !== $slug ) {
				$stats['slugs'][] = array(
					'slug'          => $slug,
					'type'          => $type,
					'status'        => $status,
					'title'         => isset( $page['title'] ) ? (string) $page['title'] : $slug,
					'is_front_page' => ! empty( $page['is_front_page'] ),
				);
			}
		}

		return $stats;
	}

	/**
	 * Design Pages status panel (Architecture v2).
	 *
	 * @since 2.0.0
	 * @return void
	 */
	private function render_design_pages_status_panel() {
		$stats = $this->get_design_pages_stats();
		$max_slugs = 12;
		?>
		<div class="promptweb-card promptweb-card--pages">
			<h2><?php esc_html_e( 'Design Pages', 'promptweb' ); ?></h2>
			<p class="description" style="margin-top:0;">
				<?php esc_html_e( 'Local catalog from pages/manifest.json (uploads/promptweb). Synced from the design repository; not stored in plugin options that get wiped on update.', 'promptweb' ); ?>
			</p>

			<div class="promptweb-stat-grid">
				<div class="promptweb-stat">
					<span class="promptweb-stat__value"><?php echo esc_html( (string) $stats['total'] ); ?></span>
					<span class="promptweb-stat__label"><?php esc_html_e( 'Total pages', 'promptweb' ); ?></span>
				</div>
				<div class="promptweb-stat">
					<span class="promptweb-stat__value"><?php echo esc_html( (string) $stats['static'] ); ?></span>
					<span class="promptweb-stat__label"><?php esc_html_e( 'Static (HTML)', 'promptweb' ); ?></span>
				</div>
				<div class="promptweb-stat">
					<span class="promptweb-stat__value"><?php echo esc_html( (string) $stats['dynamic'] ); ?></span>
					<span class="promptweb-stat__label"><?php esc_html_e( 'Dynamic (PHP)', 'promptweb' ); ?></span>
				</div>
				<div class="promptweb-stat">
					<span class="promptweb-stat__value"><?php echo esc_html( (string) $stats['publish'] ); ?></span>
					<span class="promptweb-stat__label"><?php esc_html_e( 'Published', 'promptweb' ); ?></span>
				</div>
				<div class="promptweb-stat">
					<span class="promptweb-stat__value"><?php echo esc_html( (string) $stats['draft'] ); ?></span>
					<span class="promptweb-stat__label"><?php esc_html_e( 'Draft', 'promptweb' ); ?></span>
				</div>
			</div>

			<?php if ( ! $stats['has_pages'] ) : ?>
				<p>
					<span class="promptweb-badge promptweb-badge--warn"><?php esc_html_e( 'No design pages yet', 'promptweb' ); ?></span>
					<span class="description">
						<?php esc_html_e( 'Run Initialize AI-Ready Repository or Sync from GitHub to load pages/static and pages/dynamic.', 'promptweb' ); ?>
					</span>
				</p>
			<?php else : ?>
				<p style="margin-bottom:4px;"><strong><?php esc_html_e( 'Pages', 'promptweb' ); ?></strong></p>
				<ul class="promptweb-slug-list">
					<?php
					$i = 0;
					foreach ( $stats['slugs'] as $item ) :
						if ( $i >= $max_slugs ) {
							break;
						}
						$i++;
						$status_class = ( 'publish' === $item['status'] ) ? 'promptweb-badge--publish' : 'promptweb-badge--draft';
						$type_label   = ( 'dynamic' === $item['type'] )
							? __( 'dynamic', 'promptweb' )
							: __( 'static', 'promptweb' );
						$status_label = ( 'publish' === $item['status'] )
							? __( 'Publish', 'promptweb' )
							: __( 'Draft', 'promptweb' );
						?>
						<li>
							<code><?php echo esc_html( $item['slug'] ); ?></code>
							<?php if ( ! empty( $item['is_front_page'] ) ) : ?>
								<span class="promptweb-badge promptweb-badge--ok"><?php esc_html_e( 'front', 'promptweb' ); ?></span>
							<?php endif; ?>
							<span class="promptweb-badge promptweb-badge--muted"><?php echo esc_html( $type_label ); ?></span>
							<span class="promptweb-badge <?php echo esc_attr( $status_class ); ?>"><?php echo esc_html( $status_label ); ?></span>
							<?php if ( ! empty( $item['title'] ) && $item['title'] !== $item['slug'] ) : ?>
								<span class="description">— <?php echo esc_html( $item['title'] ); ?></span>
							<?php endif; ?>
						</li>
					<?php endforeach; ?>
				</ul>
				<?php if ( $stats['total'] > $max_slugs ) : ?>
					<p class="description">
						<?php
						printf(
							/* translators: %d: remaining page count */
							esc_html__( '…and %d more. Use list_pages (MCP/REST) for the full catalog.', 'promptweb' ),
							(int) ( $stats['total'] - $max_slugs )
						);
						?>
					</p>
				<?php endif; ?>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * MCP / Abilities / REST status for AI tools.
	 *
	 * @since 2.0.0
	 * @return void
	 */
	private function render_mcp_status_panel() {
		$abilities_api = function_exists( 'wp_register_ability' );
		$mcp_adapter   = class_exists( '\WP\MCP\Core\McpAdapter' )
			|| class_exists( 'WP\MCP\Core\McpAdapter' )
			|| defined( 'WP_MCP_ADAPTER_VERSION' );

		// REST fallback always ships with PromptWeb.
		$rest_base = rest_url( 'promptweb/v1/mcp/' );
		$rest_base = is_string( $rest_base ) ? $rest_base : home_url( '/wp-json/promptweb/v1/mcp/' );

		$tools = array(
			'list_pages',
			'get_page',
			'create_page',
			'update_page',
			'publish_page',
			'get_visual_analysis',
			'commit_to_github',
		);
		?>
		<div class="promptweb-card promptweb-card--mcp">
			<h2><?php esc_html_e( 'MCP & AI tools', 'promptweb' ); ?></h2>
			<p class="description" style="margin-top:0;">
				<?php esc_html_e( 'AI agents use these tools to manage design pages. All tools require manage_options (or manage_network).', 'promptweb' ); ?>
			</p>

			<table class="form-table" role="presentation" style="margin-top:8px;">
				<tbody>
					<tr>
						<th scope="row"><?php esc_html_e( 'Abilities API', 'promptweb' ); ?></th>
						<td>
							<?php if ( $abilities_api ) : ?>
								<span class="promptweb-badge promptweb-badge--ok"><?php esc_html_e( 'Available', 'promptweb' ); ?></span>
								<span class="description"><?php esc_html_e( 'wp_register_ability is present (WordPress 6.9+ or abilities-api plugin). PromptWeb registers design abilities automatically.', 'promptweb' ); ?></span>
							<?php else : ?>
								<span class="promptweb-badge promptweb-badge--warn"><?php esc_html_e( 'Not detected', 'promptweb' ); ?></span>
								<span class="description"><?php esc_html_e( 'Optional. REST fallback still works without it.', 'promptweb' ); ?></span>
							<?php endif; ?>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'mcp-adapter', 'promptweb' ); ?></th>
						<td>
							<?php if ( $mcp_adapter ) : ?>
								<span class="promptweb-badge promptweb-badge--ok"><?php esc_html_e( 'Available', 'promptweb' ); ?></span>
								<span class="description">
									<?php esc_html_e( 'Official WordPress MCP adapter detected. Custom server id:', 'promptweb' ); ?>
									<code>promptweb-mcp-server</code>
									<?php esc_html_e( '· route:', 'promptweb' ); ?>
									<code>/wp-json/promptweb-mcp/mcp</code>
								</span>
							<?php else : ?>
								<span class="promptweb-badge promptweb-badge--muted"><?php esc_html_e( 'Not installed', 'promptweb' ); ?></span>
								<span class="description"><?php esc_html_e( 'Optional. Install WordPress mcp-adapter for native MCP clients. AI tools still work via REST without it.', 'promptweb' ); ?></span>
							<?php endif; ?>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'REST fallback', 'promptweb' ); ?></th>
						<td>
							<span class="promptweb-badge promptweb-badge--ok"><?php esc_html_e( 'Always available', 'promptweb' ); ?></span>
							<p class="description" style="margin:6px 0 0;">
								<?php esc_html_e( 'Namespace:', 'promptweb' ); ?>
								<code class="promptweb-path"><?php echo esc_html( $rest_base ); ?>*</code>
							</p>
							<p class="description" style="margin:4px 0 0;">
								<?php esc_html_e( 'Authenticate with Application Passwords. AI tools work even without mcp-adapter via REST.', 'promptweb' ); ?>
							</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Tools', 'promptweb' ); ?></th>
						<td>
							<p style="margin:0 0 6px;">
								<?php foreach ( $tools as $tool ) : ?>
									<code style="margin:0 4px 4px 0;display:inline-block;"><?php echo esc_html( $tool ); ?></code>
								<?php endforeach; ?>
							</p>
							<p class="description" style="margin:0;">
								<?php esc_html_e( 'create_page always creates Draft. Use get_visual_analysis, then publish_page when quality is high, then commit_to_github.', 'promptweb' ); ?>
							</p>
						</td>
					</tr>
				</tbody>
			</table>
		</div>
		<?php
	}

	/**
	 * Output settings sections and fields.
	 *
	 * Uses the Settings API section/field registry so both contexts share markup.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	private function render_settings_fields() {
		do_settings_sections( self::PAGE_SLUG );
	}

	/**
	 * Handle "Update Plugin from GitHub" (core code only; never touches options).
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public function maybe_handle_plugin_update() {
		if ( empty( $_POST['promptweb_do_plugin_update'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			return;
		}

		if ( empty( $_GET['page'] ) || self::PAGE_SLUG !== $_GET['page'] ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		}

		$cap = class_exists( 'PromptWeb_Updater' )
			? PromptWeb_Updater::get_capability()
			: ( is_network_admin() ? 'manage_network_options' : 'manage_options' );

		if ( ! current_user_can( $cap ) ) {
			wp_die( esc_html__( 'You do not have permission to update the PromptWeb plugin.', 'promptweb' ) );
		}

		check_admin_referer( self::UPDATE_PLUGIN_NONCE_ACTION, 'promptweb_update_plugin_nonce' );

		if ( ! class_exists( 'PromptWeb_Updater' ) ) {
			$this->store_sync_notice(
				array(
					'success' => false,
					'message' => __( 'Updater component is not available.', 'promptweb' ),
				)
			);
			$this->redirect_after_sync();
		}

		$updater = new PromptWeb_Updater();
		$result  = $updater->update_from_github();

		$this->store_sync_notice(
			array(
				'success' => ! empty( $result['success'] ),
				'message' => isset( $result['message'] ) ? $result['message'] : '',
				'code'    => isset( $result['code'] ) ? $result['code'] : '',
			)
		);

		$this->redirect_after_sync();
	}

	/**
	 * Panel: update PromptWeb plugin code from the public core GitHub repo.
	 *
	 * Completely separate from the user's website design repository.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	private function render_plugin_update_panel() {
		$version = class_exists( 'PromptWeb_Updater' )
			? PromptWeb_Updater::get_installed_version()
			: ( defined( 'PROMPTWEB_VERSION' ) ? PROMPTWEB_VERSION : '—' );

		$source = class_exists( 'PromptWeb_Updater' )
			? PromptWeb_Updater::get_source_label()
			: 'Akashmali6198/promptweb@main';

		$cap = class_exists( 'PromptWeb_Updater' )
			? PromptWeb_Updater::get_capability()
			: $this->get_capability();

		$can_update = current_user_can( $cap );
		?>
		<hr />
		<h2><?php esc_html_e( 'Plugin updates', 'promptweb' ); ?></h2>
		<p class="description">
			<?php esc_html_e( 'Updates plugin code only. Your website design and blueprint data will not be deleted.', 'promptweb' ); ?>
		</p>
		<table class="form-table" role="presentation">
			<tbody>
				<tr>
					<th scope="row"><?php esc_html_e( 'Installed version', 'promptweb' ); ?></th>
					<td>
						<code><?php echo esc_html( $version ); ?></code>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Update source', 'promptweb' ); ?></th>
					<td>
						<code><?php echo esc_html( $source ); ?></code>
						<p class="description">
							<?php esc_html_e( 'Public core plugin repository (no token required). This is not your website design repository.', 'promptweb' ); ?>
						</p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Update', 'promptweb' ); ?></th>
					<td>
						<form method="post" action="<?php echo esc_url( $this->get_settings_page_url() ); ?>" onsubmit="return confirm('<?php echo esc_js( __( 'Update PromptWeb plugin files from GitHub? Your blueprint, design data, and connection settings will not be deleted.', 'promptweb' ) ); ?>');">
							<?php wp_nonce_field( self::UPDATE_PLUGIN_NONCE_ACTION, 'promptweb_update_plugin_nonce' ); ?>
							<input type="hidden" name="promptweb_do_plugin_update" value="1" />
							<?php
							submit_button(
								__( 'Update Plugin from GitHub', 'promptweb' ),
								'secondary',
								'promptweb_update_plugin_submit',
								false,
								$can_update ? array() : array( 'disabled' => 'disabled' )
							);
							?>
						</form>
						<p class="description">
							<?php esc_html_e( 'Downloads the latest code from the public PromptWeb repository and replaces files only inside this plugin folder. WordPress options, blueprints, GitHub design-repo settings, and site content are left untouched.', 'promptweb' ); ?>
						</p>
						<?php if ( ! $can_update ) : ?>
							<p class="description">
								<?php esc_html_e( 'You need manage_options (or manage_network_options on Multisite) to run plugin updates.', 'promptweb' ); ?>
							</p>
						<?php endif; ?>
					</td>
				</tr>
			</tbody>
		</table>
		<?php
	}

	/**
	 * Initialize AI-Ready Repository panel.
	 *
	 * Creates/updates pages/ structure + AI_INSTRUCTIONS.md + README + legacy blueprint.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	private function render_init_panel() {
		$settings   = $this->get_settings();
		$configured = ! empty( $settings['github_token'] ) && ! empty( $settings['github_repo'] );
		$meta       = $this->get_init_meta();
		$repo       = isset( $settings['github_repo'] ) ? $settings['github_repo'] : '';
		$branch     = isset( $settings['github_branch'] ) ? $settings['github_branch'] : 'main';
		$path       = ! empty( $settings['blueprint_path'] ) ? $settings['blueprint_path'] : 'blueprints/latest.json';

		$already = false;
		if ( $configured && ! empty( $meta['repo'] ) && $meta['repo'] === $repo ) {
			$already = true;
		}

		// Live remote check when configured (nice-to-have status).
		if ( $configured && function_exists( 'promptweb' ) && promptweb()->github instanceof PromptWeb_GitHub ) {
			$status = promptweb()->github->get_initialization_status( self::use_network_options() );
			if ( is_array( $status ) && ! empty( $status['ready'] ) ) {
				$already = true;
			}
		}
		?>
		<hr />
		<h2><?php esc_html_e( 'Initialize AI-Ready Repository', 'promptweb' ); ?></h2>
		<p class="description">
			<?php esc_html_e( 'Prepare the connected design repository for Architecture v2: pages/static + pages/dynamic, AI_INSTRUCTIONS.md, and README.md. Existing custom pages and blueprints are never deleted. No external AI is called from WordPress.', 'promptweb' ); ?>
		</p>
		<table class="form-table" role="presentation">
			<tbody>
				<tr>
					<th scope="row"><?php esc_html_e( 'Status', 'promptweb' ); ?></th>
					<td>
						<?php if ( ! $configured ) : ?>
							<span class="dashicons dashicons-warning" style="color:#dba617;"></span>
							<?php esc_html_e( 'Connect GitHub (token + repository) and save settings first.', 'promptweb' ); ?>
						<?php elseif ( $already ) : ?>
							<span class="dashicons dashicons-yes-alt" style="color:#00a32a;"></span>
							<strong><?php esc_html_e( 'Already initialized (AI-ready / v2)', 'promptweb' ); ?></strong>
							<?php if ( ! empty( $meta['initialized_at'] ) ) : ?>
								<br />
								<span class="description">
									<?php
									printf(
										/* translators: %s: datetime */
										esc_html__( 'Last initialized: %s', 'promptweb' ),
										esc_html( $this->format_last_synced_display( $meta['initialized_at'] ) )
									);
									?>
								</span>
							<?php endif; ?>
						<?php else : ?>
							<span class="dashicons dashicons-info" style="color:#2271b1;"></span>
							<?php esc_html_e( 'Not initialized yet — create Architecture v2 starter files on GitHub.', 'promptweb' ); ?>
						<?php endif; ?>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Files Initialize writes', 'promptweb' ); ?></th>
					<td>
						<ul style="list-style:disc;margin-left:1.25em;">
							<li><code>pages/manifest.json</code> — <?php esc_html_e( 'page catalog; home as front page, status publish (starter only)', 'promptweb' ); ?></li>
							<li><code>pages/static/home.html</code> — <?php esc_html_e( 'beautiful modern Tailwind CDN starter homepage (published)', 'promptweb' ); ?></li>
							<li><code>pages/dynamic/.gitkeep</code> — <?php esc_html_e( 'dynamic PHP pages folder (created if missing)', 'promptweb' ); ?></li>
							<li><code>AI_INSTRUCTIONS.md</code> — <?php esc_html_e( 'Architecture v2 full creative freedom + MCP tools', 'promptweb' ); ?></li>
							<li><code>README.md</code> — <?php esc_html_e( 'clear AI workflow for static/dynamic pages + MCP', 'promptweb' ); ?></li>
							<li><code><?php echo esc_html( $path ); ?></code> — <?php esc_html_e( 'legacy JSON blueprint — created only if missing (existing data kept)', 'promptweb' ); ?></li>
						</ul>
						<p class="description">
							<?php
							printf(
								/* translators: 1: repo, 2: branch */
								esc_html__( 'Target: %1$s @ %2$s', 'promptweb' ),
								esc_html( $repo ? $repo : '—' ),
								esc_html( $branch )
							);
							?>
						</p>
						<p class="description">
							<?php esc_html_e( 'Local design copies are stored under uploads/promptweb/ so plugin updates never delete website design data. Multisite-safe (network or per-site settings).', 'promptweb' ); ?>
						</p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'MCP / AI tools', 'promptweb' ); ?></th>
					<td>
						<p class="description">
							<?php esc_html_e( 'AI agents can use Abilities/MCP tools (list_pages, get_page, create_page, update_page, publish_page, get_visual_analysis, commit_to_github) when the WordPress Abilities API and optional mcp-adapter are available. REST mirrors: /wp-json/promptweb/v1/mcp/* (requires manage_options).', 'promptweb' ); ?>
						</p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Initialize', 'promptweb' ); ?></th>
					<td>
						<form method="post" action="<?php echo esc_url( $this->get_settings_page_url() ); ?>">
							<?php wp_nonce_field( self::INIT_NONCE_ACTION, 'promptweb_init_nonce' ); ?>
							<input type="hidden" name="promptweb_do_init_repo" value="1" />
							<?php if ( $already ) : ?>
								<input type="hidden" name="promptweb_init_force" value="1" />
								<?php
								submit_button(
									__( 'Re-initialize Repository', 'promptweb' ),
									'secondary',
									'promptweb_init_submit',
									false,
									$configured ? array() : array( 'disabled' => 'disabled' )
								);
								?>
								<p class="description">
									<?php esc_html_e( 'Refreshes AI_INSTRUCTIONS.md, README.md, and the starter home.html. Merges pages/manifest.json without removing other pages. Never overwrites an existing blueprints/latest.json. Prefer AI tools for design work instead of re-initializing when possible.', 'promptweb' ); ?>
								</p>
							<?php else : ?>
								<?php
								submit_button(
									__( 'Initialize AI-Ready Repository', 'promptweb' ),
									'primary',
									'promptweb_init_submit',
									false,
									$configured ? array() : array( 'disabled' => 'disabled' )
								);
								?>
								<p class="description">
									<?php esc_html_e( 'Creates Architecture v2 files on GitHub (pages/, AI_INSTRUCTIONS.md, README.md). Requires a token with Contents read/write access.', 'promptweb' ); ?>
								</p>
							<?php endif; ?>
						</form>
					</td>
				</tr>
			</tbody>
		</table>
		<?php
	}

	/**
	 * "Sync from GitHub" panel (separate form so Save Changes is unaffected).
	 *
	 * Placed under the GitHub Connection settings for a clear workflow:
	 * save credentials → Sync Now.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	private function render_sync_panel() {
		$settings    = $this->get_settings();
		$last_synced = isset( $settings['last_synced'] ) ? $settings['last_synced'] : '';
		$display     = $this->format_last_synced_display( $last_synced );
		$configured  = ! empty( $settings['github_token'] ) && ! empty( $settings['github_repo'] );
		?>
		<hr />
		<h2><?php esc_html_e( 'Sync from GitHub (backup)', 'promptweb' ); ?></h2>
		<p class="description">
			<?php esc_html_e( 'Optional backup control. Pulls design pages (pages/static, pages/dynamic, manifest) and any legacy blueprint JSON. With Auto-Detect enabled, the live site normally refreshes on its own. Use Sync Now after changing credentials or for an immediate refresh. Sync never deletes GitHub connection settings.', 'promptweb' ); ?>
		</p>
		<table class="form-table" role="presentation">
			<tbody>
				<tr>
					<th scope="row"><?php esc_html_e( 'Last Synced', 'promptweb' ); ?></th>
					<td>
						<code><?php echo esc_html( $display ); ?></code>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Manual Sync', 'promptweb' ); ?></th>
					<td>
						<form method="post" action="<?php echo esc_url( $this->get_settings_page_url() ); ?>">
							<?php wp_nonce_field( self::SYNC_NONCE_ACTION, 'promptweb_sync_nonce' ); ?>
							<input type="hidden" name="promptweb_do_sync" value="1" />
							<?php
							submit_button(
								__( 'Sync Now', 'promptweb' ),
								'secondary',
								'promptweb_sync_submit',
								false,
								$configured ? array() : array( 'disabled' => 'disabled' )
							);
							?>
						</form>
						<?php if ( ! $configured ) : ?>
							<p class="description">
								<?php esc_html_e( 'Add a Personal Access Token and repository, then save settings to enable sync.', 'promptweb' ); ?>
							</p>
						<?php else : ?>
							<p class="description">
								<?php
								printf(
									/* translators: 1: repo, 2: path, 3: branch */
									esc_html__( 'Will fetch %2$s from %1$s @ %3$s.', 'promptweb' ),
									esc_html( $settings['github_repo'] ),
									esc_html( $settings['blueprint_path'] ),
									esc_html( $settings['github_branch'] )
								);
								?>
							</p>
						<?php endif; ?>
					</td>
				</tr>
			</tbody>
		</table>
		<?php
	}

	/**
	 * Show success/error notices after settings save or sync.
	 *
	 * @since 1.0.0
	 * @param bool $is_network Whether we are in Network Admin.
	 * @return void
	 */
	private function render_admin_notices( $is_network ) {
		// Sync result (transient, consumed once).
		$sync_notice = $this->consume_sync_notice();
		if ( is_array( $sync_notice ) && ! empty( $sync_notice['message'] ) ) {
			$class = ! empty( $sync_notice['success'] ) ? 'notice-success' : 'notice-error';
			?>
			<div class="notice <?php echo esc_attr( $class ); ?> is-dismissible">
				<p><?php echo esc_html( $sync_notice['message'] ); ?></p>
			</div>
			<?php
		}

		// Single site: options.php redirects with settings-updated=true.
		// Network: our save handler sets the same query arg.
		if ( empty( $_GET['settings-updated'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		}
		?>
		<div class="notice notice-success is-dismissible">
			<p>
				<?php
				if ( $is_network ) {
					esc_html_e( 'Network settings saved.', 'promptweb' );
				} else {
					esc_html_e( 'Settings saved.', 'promptweb' );
				}
				?>
			</p>
		</div>
		<?php
	}
}
