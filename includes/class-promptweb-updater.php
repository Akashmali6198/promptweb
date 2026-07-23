<?php
/**
 * Safe self-update of PromptWeb plugin code from the public GitHub repo.
 *
 * IMPORTANT separation of concerns:
 * - Plugin code repo (this updater): Akashmali6198/promptweb (public, no token)
 * - Website design repo (user settings): token + owner/repo for blueprints only
 *
 * This updater NEVER:
 * - Deletes or modifies WordPress options
 * - Touches blueprint JSON (promptweb_blueprint)
 * - Touches GitHub connection settings for the design repo
 * - Deletes site content or design progress
 *
 * It ONLY replaces files inside the current PromptWeb plugin directory.
 *
 * @package PromptWeb
 * @since   1.0.0
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Downloads and applies plugin code updates from the public core repository.
 *
 * @since 1.0.0
 */
class PromptWeb_Updater {

	/**
	 * Public plugin source owner.
	 *
	 * @since 1.0.0
	 * @var   string
	 */
	const REPO_OWNER = 'Akashmali6198';

	/**
	 * Public plugin source repository name.
	 *
	 * @since 1.0.0
	 * @var   string
	 */
	const REPO_NAME = 'promptweb';

	/**
	 * Branch to pull for updates.
	 *
	 * @since 1.0.0
	 * @var   string
	 */
	const REPO_BRANCH = 'main';

	/**
	 * Installed plugin version (from PROMPTWEB_VERSION constant).
	 *
	 * @since 1.0.0
	 * @return string
	 */
	public static function get_installed_version() {
		return defined( 'PROMPTWEB_VERSION' ) ? (string) PROMPTWEB_VERSION : '0.0.0';
	}

	/**
	 * Public zipball URL for the fixed branch (no auth required).
	 *
	 * @since 1.0.0
	 * @return string
	 */
	public static function get_zip_url() {
		$url = sprintf(
			'https://github.com/%s/%s/archive/refs/heads/%s.zip',
			rawurlencode( self::REPO_OWNER ),
			rawurlencode( self::REPO_NAME ),
			rawurlencode( self::REPO_BRANCH )
		);

		/**
		 * Filters the plugin update ZIP URL (defaults to public main branch zipball).
		 *
		 * @since 1.0.0
		 * @param string $url Zip URL.
		 */
		return (string) apply_filters( 'promptweb_plugin_update_zip_url', $url );
	}

	/**
	 * Human-readable source label for the UI.
	 *
	 * @since 1.0.0
	 * @return string
	 */
	public static function get_source_label() {
		return self::REPO_OWNER . '/' . self::REPO_NAME . '@' . self::REPO_BRANCH;
	}

	/**
	 * Capability required to run a plugin code update.
	 *
	 * Multisite network-active: manage_network_options.
	 * Otherwise: manage_options.
	 *
	 * @since 1.0.0
	 * @return string
	 */
	public static function get_capability() {
		if ( is_multisite() && class_exists( 'PromptWeb_Settings' ) && PromptWeb_Settings::is_plugin_network_active() ) {
			return 'manage_network_options';
		}

		return is_network_admin() ? 'manage_network_options' : 'manage_options';
	}

	/**
	 * Whether the current user may update plugin code.
	 *
	 * @since 1.0.0
	 * @return bool
	 */
	public static function current_user_can_update() {
		return current_user_can( self::get_capability() );
	}

	/**
	 * Download latest code from the public plugin repo and replace plugin files only.
	 *
	 * Does not call delete_option / update_option for PromptWeb data.
	 * Does not touch the user's website design repository.
	 *
	 * @since 1.0.0
	 * @return array{ success: bool, message: string, code?: string, data?: array }
	 */
	public function update_from_github() {
		if ( ! self::current_user_can_update() ) {
			return array(
				'success' => false,
				'code'    => 'promptweb_update_forbidden',
				'message' => __( 'You do not have permission to update the PromptWeb plugin.', 'promptweb' ),
			);
		}

		// Admin includes required for download_url / unzip_file / copy_dir.
		if ( ! function_exists( 'download_url' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}
		if ( ! function_exists( 'unzip_file' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}
		if ( ! function_exists( 'copy_dir' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}
		if ( ! class_exists( 'WP_Filesystem_Base', false ) ) {
			require_once ABSPATH . 'wp-admin/includes/class-wp-filesystem-base.php';
		}
		if ( ! function_exists( 'WP_Filesystem' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}

		// Initialize filesystem (Hostinger-friendly: prefers direct when possible).
		global $wp_filesystem;
		if ( ! WP_Filesystem() ) {
			return array(
				'success' => false,
				'code'    => 'promptweb_update_fs',
				'message' => __( 'Could not access the filesystem. Check that the plugin directory is writable (Hostinger: ensure file permissions allow updates).', 'promptweb' ),
			);
		}

		$dest = wp_normalize_path( untrailingslashit( PROMPTWEB_PLUGIN_DIR ) );
		if ( empty( $dest ) || ! is_dir( $dest ) ) {
			return array(
				'success' => false,
				'code'    => 'promptweb_update_bad_dest',
				'message' => __( 'PromptWeb plugin directory could not be resolved.', 'promptweb' ),
			);
		}

		// Safety: destination must be inside plugins directory.
		$plugins_root = wp_normalize_path( untrailingslashit( WP_PLUGIN_DIR ) );
		if ( 0 !== strpos( $dest, $plugins_root ) ) {
			return array(
				'success' => false,
				'code'    => 'promptweb_update_bad_dest',
				'message' => __( 'Refusing to update: destination is outside the plugins directory.', 'promptweb' ),
			);
		}

		// Verify main plugin file exists before we start (sanity check).
		$main_file = $dest . '/promptweb.php';
		if ( ! file_exists( $main_file ) ) {
			return array(
				'success' => false,
				'code'    => 'promptweb_update_bad_dest',
				'message' => __( 'Could not find promptweb.php in the plugin directory.', 'promptweb' ),
			);
		}

		$zip_url = self::get_zip_url();

		/**
		 * Fires before plugin code download begins.
		 *
		 * @since 1.0.0
		 * @param string $zip_url Package URL.
		 * @param string $dest    Plugin directory.
		 */
		do_action( 'promptweb_before_plugin_update', $zip_url, $dest );

		// --- Download ---
		$tmp_zip = download_url( $zip_url, 300 );
		if ( is_wp_error( $tmp_zip ) ) {
			return array(
				'success' => false,
				'code'    => 'promptweb_update_download',
				'message' => sprintf(
					/* translators: %s: error */
					__( 'Download failed: %s', 'promptweb' ),
					$tmp_zip->get_error_message()
				),
			);
		}

		// --- Extract to upgrade temp dir ---
		$workdir = trailingslashit( WP_CONTENT_DIR ) . 'upgrade/promptweb-update-' . time() . '-' . wp_generate_password( 6, false );
		if ( ! wp_mkdir_p( $workdir ) ) {
			$this->safe_unlink( $tmp_zip );
			return array(
				'success' => false,
				'code'    => 'promptweb_update_tmpdir',
				'message' => __( 'Could not create a temporary directory for the update.', 'promptweb' ),
			);
		}

		$unzipped = unzip_file( $tmp_zip, $workdir );
		$this->safe_unlink( $tmp_zip );

		if ( is_wp_error( $unzipped ) ) {
			$this->cleanup_dir( $workdir );
			return array(
				'success' => false,
				'code'    => 'promptweb_update_unzip',
				'message' => sprintf(
					/* translators: %s: error */
					__( 'Could not extract the update package: %s', 'promptweb' ),
					$unzipped->get_error_message()
				),
			);
		}

		$source = $this->find_plugin_root_in_extract( $workdir );
		if ( ! $source ) {
			$this->cleanup_dir( $workdir );
			return array(
				'success' => false,
				'code'    => 'promptweb_update_package',
				'message' => __( 'The downloaded package does not look like a PromptWeb plugin (promptweb.php missing).', 'promptweb' ),
			);
		}

		// --- Copy files into the existing plugin directory (overwrite code only) ---
		// copy_dir merges/overwrites files; it does not wipe the WordPress database.
		$result = copy_dir( $source, $dest );

		// Always remove working files.
		$this->cleanup_dir( $workdir );

		if ( is_wp_error( $result ) ) {
			return array(
				'success' => false,
				'code'    => 'promptweb_update_copy',
				'message' => sprintf(
					/* translators: %s: error */
					__( 'Could not write plugin files: %s Ensure the plugin folder is writable.', 'promptweb' ),
					$result->get_error_message()
				),
			);
		}

		// Confirm main file still present after copy.
		if ( ! file_exists( $dest . '/promptweb.php' ) ) {
			return array(
				'success' => false,
				'code'    => 'promptweb_update_verify',
				'message' => __( 'Update finished but promptweb.php is missing. Please reinstall manually from GitHub.', 'promptweb' ),
			);
		}

		// Read version from newly written file if possible (constant may still be old in this request).
		$new_version = $this->read_version_from_file( $dest . '/promptweb.php' );
		if ( ! $new_version ) {
			$new_version = self::get_installed_version();
		}

		/**
		 * Fires after a successful plugin file update.
		 *
		 * Intentionally does not pass or modify options/blueprint data.
		 *
		 * @since 1.0.0
		 * @param string $new_version Version string from updated promptweb.php when readable.
		 * @param string $dest        Plugin directory.
		 */
		do_action( 'promptweb_after_plugin_update', $new_version, $dest );

		return array(
			'success' => true,
			'code'    => 'promptweb_update_success',
			'message' => sprintf(
				/* translators: 1: new version, 2: source repo label */
				__( 'PromptWeb plugin code updated successfully (version %1$s) from %2$s. Your website design, blueprint data, and GitHub settings were not changed.', 'promptweb' ),
				$new_version,
				self::get_source_label()
			),
			'data'    => array(
				'version' => $new_version,
				'source'  => self::get_source_label(),
				// Explicit: no options were written by this process.
				'options_touched' => false,
			),
		);
	}

	/**
	 * Locate the directory inside the extract that contains promptweb.php.
	 *
	 * GitHub zipballs extract to `{repo}-{branch}/` (e.g. promptweb-main).
	 *
	 * @since 1.0.0
	 * @param string $workdir Extract root.
	 * @return string|null Absolute path or null.
	 */
	protected function find_plugin_root_in_extract( $workdir ) {
		$workdir = untrailingslashit( $workdir );

		if ( file_exists( $workdir . '/promptweb.php' ) ) {
			return $workdir;
		}

		$candidates = glob( $workdir . '/*', GLOB_ONLYDIR );
		if ( ! is_array( $candidates ) ) {
			return null;
		}

		foreach ( $candidates as $dir ) {
			if ( file_exists( $dir . '/promptweb.php' ) ) {
				return $dir;
			}
			// One level deeper (rare packaging quirks).
			$nested = glob( $dir . '/*', GLOB_ONLYDIR );
			if ( is_array( $nested ) ) {
				foreach ( $nested as $sub ) {
					if ( file_exists( $sub . '/promptweb.php' ) ) {
						return $sub;
					}
				}
			}
		}

		return null;
	}

	/**
	 * Parse Version header from a plugin main file.
	 *
	 * @since 1.0.0
	 * @param string $file Absolute path.
	 * @return string Empty if unknown.
	 */
	protected function read_version_from_file( $file ) {
		if ( ! is_readable( $file ) ) {
			return '';
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$contents = file_get_contents( $file, false, null, 0, 8192 );
		if ( ! is_string( $contents ) ) {
			return '';
		}

		if ( preg_match( '/^\s*\*\s*Version:\s*(.+)$/mi', $contents, $m ) ) {
			return sanitize_text_field( trim( $m[1] ) );
		}
		if ( preg_match( "/define\s*\(\s*'PROMPTWEB_VERSION'\s*,\s*'([^']+)'\s*\)/", $contents, $m ) ) {
			return sanitize_text_field( trim( $m[1] ) );
		}

		return '';
	}

	/**
	 * Delete a file if it exists.
	 *
	 * @since 1.0.0
	 * @param string $file Path.
	 * @return void
	 */
	protected function safe_unlink( $file ) {
		if ( is_string( $file ) && file_exists( $file ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
			@unlink( $file );
		}
	}

	/**
	 * Recursively remove a directory under wp-content/upgrade only.
	 *
	 * @since 1.0.0
	 * @param string $dir Directory path.
	 * @return void
	 */
	protected function cleanup_dir( $dir ) {
		if ( ! is_string( $dir ) || '' === $dir || ! is_dir( $dir ) ) {
			return;
		}

		$dir     = wp_normalize_path( untrailingslashit( $dir ) );
		$upgrade = wp_normalize_path( untrailingslashit( WP_CONTENT_DIR . '/upgrade' ) );

		// Safety: never delete outside upgrade/.
		if ( 0 !== strpos( $dir, $upgrade ) ) {
			return;
		}

		global $wp_filesystem;
		if ( $wp_filesystem instanceof WP_Filesystem_Base ) {
			$wp_filesystem->delete( $dir, true );
			return;
		}

		// Fallback recursive delete limited to upgrade path.
		$items = scandir( $dir );
		if ( ! is_array( $items ) ) {
			return;
		}
		foreach ( $items as $item ) {
			if ( '.' === $item || '..' === $item ) {
				continue;
			}
			$path = $dir . '/' . $item;
			if ( is_dir( $path ) ) {
				$this->cleanup_dir( $path );
			} else {
				$this->safe_unlink( $path );
			}
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
		@rmdir( $dir );
	}
}
