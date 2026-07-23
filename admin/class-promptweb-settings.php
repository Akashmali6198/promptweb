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
		// Skip re-registration on network admin; network uses a custom save path.
		// Fields are still registered so do_settings_sections() works when needed.
		register_setting(
			self::OPTION_GROUP,
			self::OPTION_NAME,
			array(
				'type'              => 'array',
				'description'       => __( 'PromptWeb general settings.', 'promptweb' ),
				'sanitize_callback' => array( $this, 'sanitize_settings' ),
				'default'           => $this->get_default_settings(),
				'show_in_rest'      => false,
			)
		);

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
	}

	/**
	 * Default settings values.
	 *
	 * @since 1.0.0
	 * @return array
	 */
	public function get_default_settings() {
		return array(
			'enabled' => 0,
		);
	}

	/**
	 * Retrieve settings for the current context.
	 *
	 * Network Admin → site option; site admin → blog option.
	 *
	 * @since 1.0.0
	 * @return array
	 */
	public function get_settings() {
		$defaults = $this->get_default_settings();

		if ( $this->is_network_context() ) {
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
		$defaults = $this->get_default_settings();
		$output   = $defaults;

		if ( ! is_array( $input ) ) {
			$input = array();
		}

		// Checkbox: present when checked, absent when unchecked.
		$output['enabled'] = ! empty( $input['enabled'] ) ? 1 : 0;

		/**
		 * Filters sanitized PromptWeb settings before they are stored.
		 *
		 * @since 1.0.0
		 * @param array $output Sanitized settings.
		 * @param array $input  Raw input.
		 */
		return apply_filters( 'promptweb_sanitize_settings', $output, $input );
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

		$raw = isset( $_POST[ self::OPTION_NAME ] ) ? wp_unslash( $_POST[ self::OPTION_NAME ] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
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
	 * Section description callback.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public function render_general_section() {
		echo '<p>' . esc_html__( 'Configure general PromptWeb options.', 'promptweb' ) . '</p>';
	}

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
	 * Show success notice after save.
	 *
	 * @since 1.0.0
	 * @param bool $is_network Whether we are in Network Admin.
	 * @return void
	 */
	private function render_admin_notices( $is_network ) {
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
