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

		// Network settings are not saved through options.php.
		add_action( 'network_admin_edit_' . self::NETWORK_ACTION, array( $this, 'save_network_settings' ) );
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
	 * @since 1.0.0
	 * @return string
	 */
	public function get_capability() {
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
	 * Retrieve settings for the current admin context.
	 *
	 * Network Admin → site option; site admin → blog option.
	 *
	 * @since 1.0.0
	 * @return array
	 */
	public function get_settings() {
		return self::get_settings_data( $this->is_network_context() );
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
		echo '<p>' . esc_html__( 'Configure general PromptWeb options and auto-detect behavior.', 'promptweb' ) . '</p>';
	}

	/**
	 * GitHub Connection section description.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public function render_github_section() {
		echo '<p>' . esc_html__( 'Connect PromptWeb to a GitHub repository that stores your blueprints.', 'promptweb' ) . '</p>';
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

		// Use the same storage context as this settings screen (network vs site).
		$result = $github->sync(
			array(
				'use_network' => $this->is_network_context(),
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

		// Network Admin: site transient so it is visible network-wide for this user session.
		if ( $this->is_network_context() ) {
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

		if ( $this->is_network_context() ) {
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

		$use_network = $this->is_network_context();
		$force       = ! empty( $_POST['promptweb_init_force'] ); // phpcs:ignore WordPress.Security.NonceVerification.Missing

		// Optional: block accidental overwrite when already ready (unless force).
		if ( ! $force ) {
			$status = $github->get_initialization_status( $use_network );
			if ( ! empty( $status['ready'] ) ) {
				$this->store_sync_notice(
					array(
						'success' => true,
						'message' => __( 'Repository already looks AI-ready (blueprint + AI_INSTRUCTIONS.md found). Use “Re-initialize” to overwrite.', 'promptweb' ),
						'code'    => 'promptweb_already_initialized',
					)
				);
				$this->redirect_after_sync();
			}
		}

		$result = $github->initialize_repository(
			array(
				'use_network' => $use_network,
				'force'       => true,
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
		if ( $use_network ) {
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
		$use_network = $this->is_network_context();
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
			<?php esc_html_e( 'Enable PromptWeb functionality.', 'promptweb' ); ?>
		</label>
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
			<?php esc_html_e( 'Automatically detect and sync blueprints from GitHub.', 'promptweb' ); ?>
		</label>
		<p class="description">
			<?php esc_html_e( 'When enabled, PromptWeb will look for blueprint updates without a manual sync.', 'promptweb' ); ?>
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
			<?php esc_html_e( 'Path to the blueprint file inside the repository. Default: blueprints/latest.json.', 'promptweb' ); ?>
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
		<div class="wrap">
			<h1><?php echo esc_html( get_admin_page_title() ); ?></h1>

			<?php $this->render_admin_notices( $is_network ); ?>

			<?php if ( $is_network ) : ?>
				<form method="post" action="<?php echo esc_url( network_admin_url( 'edit.php?action=' . self::NETWORK_ACTION ) ); ?>">
					<?php wp_nonce_field( 'promptweb_network_settings' ); ?>
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

			<?php $this->render_init_panel(); ?>
			<?php $this->render_sync_panel(); ?>
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
	 * Initialize AI-Ready Repository panel.
	 *
	 * Creates/updates blueprints/latest.json (or configured path) + AI_INSTRUCTIONS.md.
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
			$status = promptweb()->github->get_initialization_status( $this->is_network_context() );
			if (
				is_array( $status )
				&& ! is_wp_error( $status['blueprint'] )
				&& ! is_wp_error( $status['instructions'] )
				&& ! empty( $status['ready'] )
			) {
				$already = true;
			}
		}
		?>
		<hr />
		<h2><?php esc_html_e( 'Initialize AI-Ready Repository', 'promptweb' ); ?></h2>
		<p class="description">
			<?php esc_html_e( 'Prepare the connected GitHub repository for Maximum AI Creativity. This writes a starter blueprint and an AI_INSTRUCTIONS.md guide for external AIs (Grok, Claude, ChatGPT, etc.). No external AI is called from WordPress.', 'promptweb' ); ?>
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
							<strong><?php esc_html_e( 'Already initialized (AI-ready)', 'promptweb' ); ?></strong>
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
							<?php esc_html_e( 'Not initialized yet — create starter files on GitHub.', 'promptweb' ); ?>
						<?php endif; ?>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Files written', 'promptweb' ); ?></th>
					<td>
						<ul style="list-style:disc;margin-left:1.25em;">
							<li><code><?php echo esc_html( $path ); ?></code> — <?php esc_html_e( 'starter blueprint (version, site, empty pages & prompts)', 'promptweb' ); ?></li>
							<li><code>AI_INSTRUCTIONS.md</code> — <?php esc_html_e( 'instructions for external AI agents', 'promptweb' ); ?></li>
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
									<?php esc_html_e( 'Overwrites the starter blueprint and AI_INSTRUCTIONS.md on GitHub. Existing custom pages in the remote blueprint will be replaced by the clean starter.', 'promptweb' ); ?>
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
									<?php esc_html_e( 'Creates the blueprint file and AI instructions. Requires a token with Contents read/write access.', 'promptweb' ); ?>
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
		<h2><?php esc_html_e( 'Sync from GitHub', 'promptweb' ); ?></h2>
		<p class="description">
			<?php esc_html_e( 'Fetch and validate the blueprint JSON from the repository configured above. Save connection settings before syncing if you just changed them.', 'promptweb' ); ?>
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
