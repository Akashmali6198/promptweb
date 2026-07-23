<?php
/**
 * GitHub connection, blueprint fetch, and sync helpers.
 *
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
 * Handles GitHub configuration, API fetch, and blueprint sync.
 *
 * @since 1.0.0
 */
class PromptWeb_GitHub {

	/**
	 * GitHub REST API base URL.
	 *
	 * @since 1.0.0
	 * @var   string
	 */
	const API_BASE = 'https://api.github.com';

	/**
	 * Bootstrap hooks for future auto-detect / sync behavior.
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
	 * @param bool|null $use_network Force network (true) or site (false) options; null = auto.
	 * @return array
	 */
	public function get_settings( $use_network = null ) {
		if ( null === $use_network ) {
			return PromptWeb_Settings::get_runtime_settings();
		}

		return PromptWeb_Settings::get_settings_data( (bool) $use_network );
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
	 * @param bool|null $use_network Optional storage context.
	 * @return string
	 */
	public function get_token( $use_network = null ) {
		$settings = $this->get_settings( $use_network );

		return isset( $settings['github_token'] ) ? (string) $settings['github_token'] : '';
	}

	/**
	 * GitHub repository slug (owner/repo).
	 *
	 * @since 1.0.0
	 * @param bool|null $use_network Optional storage context.
	 * @return string
	 */
	public function get_repo( $use_network = null ) {
		$settings = $this->get_settings( $use_network );

		return isset( $settings['github_repo'] ) ? (string) $settings['github_repo'] : '';
	}

	/**
	 * GitHub branch name.
	 *
	 * @since 1.0.0
	 * @param bool|null $use_network Optional storage context.
	 * @return string
	 */
	public function get_branch( $use_network = null ) {
		$settings = $this->get_settings( $use_network );
		$branch   = isset( $settings['github_branch'] ) ? (string) $settings['github_branch'] : '';

		return '' !== $branch ? $branch : 'main';
	}

	/**
	 * Blueprint file path inside the repository.
	 *
	 * @since 1.0.0
	 * @param bool|null $use_network Optional storage context.
	 * @return string
	 */
	public function get_blueprint_path( $use_network = null ) {
		$settings = $this->get_settings( $use_network );
		$path     = isset( $settings['blueprint_path'] ) ? (string) $settings['blueprint_path'] : '';

		return '' !== $path ? $path : 'blueprints/latest.json';
	}

	/**
	 * Last successful sync timestamp (MySQL datetime or empty).
	 *
	 * @since 1.0.0
	 * @param bool|null $use_network Optional storage context.
	 * @return string
	 */
	public function get_last_synced( $use_network = null ) {
		$settings = $this->get_settings( $use_network );

		return isset( $settings['last_synced'] ) ? (string) $settings['last_synced'] : '';
	}

	/**
	 * Whether the minimum GitHub connection fields are configured.
	 *
	 * Token and repository are required; branch/path fall back to defaults.
	 *
	 * @since 1.0.0
	 * @param bool|null $use_network Optional storage context.
	 * @return bool
	 */
	public function is_configured( $use_network = null ) {
		return '' !== $this->get_token( $use_network ) && '' !== $this->get_repo( $use_network );
	}

	/**
	 * GitHub-specific settings subset for consumers.
	 *
	 * @since 1.0.0
	 * @param bool|null $use_network Optional storage context.
	 * @return array{
	 *     github_token: string,
	 *     github_repo: string,
	 *     github_branch: string,
	 *     blueprint_path: string,
	 *     auto_detect: int,
	 *     last_synced: string
	 * }
	 */
	public function get_connection_settings( $use_network = null ) {
		$settings = $this->get_settings( $use_network );

		return array(
			'github_token'   => $this->get_token( $use_network ),
			'github_repo'    => $this->get_repo( $use_network ),
			'github_branch'  => $this->get_branch( $use_network ),
			'blueprint_path' => $this->get_blueprint_path( $use_network ),
			'auto_detect'    => ! empty( $settings['auto_detect'] ) ? 1 : 0,
			'last_synced'    => $this->get_last_synced( $use_network ),
		);
	}

	/**
	 * Build the GitHub Contents API URL for the configured blueprint.
	 *
	 * @since 1.0.0
	 * @param bool|null $use_network Optional storage context.
	 * @return string|WP_Error URL on success, WP_Error if repo is invalid.
	 */
	public function get_contents_api_url( $use_network = null ) {
		$repo = $this->get_repo( $use_network );

		if ( false === strpos( $repo, '/' ) ) {
			return new WP_Error(
				'promptweb_invalid_repo',
				__( 'Repository must be in the format owner/repository.', 'promptweb' )
			);
		}

		list( $owner, $name ) = array_pad( explode( '/', $repo, 2 ), 2, '' );

		$owner = trim( $owner );
		$name  = trim( $name );

		if ( '' === $owner || '' === $name ) {
			return new WP_Error(
				'promptweb_invalid_repo',
				__( 'Repository must be in the format owner/repository.', 'promptweb' )
			);
		}

		$path   = $this->get_blueprint_path( $use_network );
		$branch = $this->get_branch( $use_network );

		// Encode each path segment; keep slashes as path separators.
		$path_segments = array_map( 'rawurlencode', explode( '/', $path ) );
		$encoded_path  = implode( '/', $path_segments );

		$url = sprintf(
			'%s/repos/%s/%s/contents/%s',
			self::API_BASE,
			rawurlencode( $owner ),
			rawurlencode( $name ),
			$encoded_path
		);

		return add_query_arg( 'ref', $branch, $url );
	}

	/**
	 * Fetch the blueprint file from GitHub via the Contents API.
	 *
	 * Uses the WordPress HTTP API (`wp_remote_get`). Does not update last_synced.
	 *
	 * @since 1.0.0
	 * @param array $args {
	 *     Optional. Fetch arguments.
	 *
	 *     @type bool|null $use_network Force network/site settings storage.
	 * }
	 * @return array|WP_Error {
	 *     @type string $raw_json   Decoded file contents (JSON string).
	 *     @type mixed  $data       json_decode() result (array|object|scalar).
	 *     @type string $path       Blueprint path used.
	 *     @type string $branch     Branch used.
	 *     @type string $repo       Repository used.
	 *     @type string $sha        Git blob SHA from GitHub (if present).
	 * }
	 */
	public function fetch_blueprint( $args = array() ) {
		$args = wp_parse_args(
			$args,
			array(
				'use_network' => null,
			)
		);

		$use_network = $args['use_network'];

		if ( ! $this->is_configured( $use_network ) ) {
			return new WP_Error(
				'promptweb_not_configured',
				__( 'GitHub is not configured. Please add a Personal Access Token and repository.', 'promptweb' )
			);
		}

		$url = $this->get_contents_api_url( $use_network );
		if ( is_wp_error( $url ) ) {
			return $url;
		}

		$token = $this->get_token( $use_network );

		/**
		 * Filters the HTTP request arguments for GitHub blueprint fetch.
		 *
		 * @since 1.0.0
		 * @param array  $request_args wp_remote_get arguments.
		 * @param string $url          Request URL.
		 */
		$request_args = apply_filters(
			'promptweb_github_fetch_args',
			array(
				'timeout'    => 30,
				'headers'    => array(
					'Accept'               => 'application/vnd.github+json',
					'Authorization'        => 'Bearer ' . $token,
					'X-GitHub-Api-Version' => '2022-11-28',
					'User-Agent'           => 'PromptWeb/' . PROMPTWEB_VERSION . '; WordPress/' . get_bloginfo( 'version' ),
				),
				// Do not cache auth-bearing responses in shared object cache by default.
				'reject_unsafe_urls' => true,
			),
			$url
		);

		$response = wp_remote_get( $url, $request_args );

		if ( is_wp_error( $response ) ) {
			return new WP_Error(
				'promptweb_http_error',
				sprintf(
					/* translators: %s: transport error message */
					__( 'Could not reach GitHub: %s', 'promptweb' ),
					$response->get_error_message()
				)
			);
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$body = wp_remote_retrieve_body( $response );

		if ( 401 === $code || 403 === $code ) {
			$message = ( 401 === $code )
				? __( 'GitHub authentication failed. Check that your Personal Access Token is valid and has not expired.', 'promptweb' )
				: __( 'GitHub denied access to this repository. Check token permissions and repository access.', 'promptweb' );

			// Prefer GitHub message when present and safe.
			$api_message = $this->extract_github_error_message( $body );
			if ( $api_message && false !== stripos( $api_message, 'rate limit' ) ) {
				$message = __( 'GitHub API rate limit exceeded. Try again later or use a token with a higher limit.', 'promptweb' );
			}

			return new WP_Error(
				'promptweb_github_auth',
				$message,
				array( 'status' => $code )
			);
		}

		if ( 404 === $code ) {
			return new WP_Error(
				'promptweb_github_not_found',
				sprintf(
					/* translators: 1: repository, 2: path, 3: branch */
					__( 'Blueprint not found. Confirm repository “%1$s”, path “%2$s”, and branch “%3$s”.', 'promptweb' ),
					$this->get_repo( $use_network ),
					$this->get_blueprint_path( $use_network ),
					$this->get_branch( $use_network )
				),
				array( 'status' => 404 )
			);
		}

		if ( $code < 200 || $code >= 300 ) {
			$api_message = $this->extract_github_error_message( $body );

			return new WP_Error(
				'promptweb_github_http',
				$api_message
					? sprintf(
						/* translators: 1: HTTP status, 2: GitHub error message */
						__( 'GitHub API error (%1$d): %2$s', 'promptweb' ),
						$code,
						$api_message
					)
					: sprintf(
						/* translators: %d: HTTP status code */
						__( 'GitHub API returned an unexpected response (HTTP %d).', 'promptweb' ),
						$code
					),
				array( 'status' => $code )
			);
		}

		$payload = json_decode( $body, true );

		if ( ! is_array( $payload ) ) {
			return new WP_Error(
				'promptweb_github_bad_response',
				__( 'GitHub returned an invalid API response.', 'promptweb' )
			);
		}

		// Contents API returns an array for directories.
		if ( isset( $payload[0] ) || ( isset( $payload['type'] ) && 'file' !== $payload['type'] ) ) {
			return new WP_Error(
				'promptweb_github_not_file',
				__( 'The blueprint path points to a directory or non-file resource. Set the path to a JSON file.', 'promptweb' )
			);
		}

		if ( empty( $payload['content'] ) ) {
			return new WP_Error(
				'promptweb_github_empty_content',
				__( 'GitHub returned an empty file for the blueprint path.', 'promptweb' )
			);
		}

		$encoding = isset( $payload['encoding'] ) ? $payload['encoding'] : 'base64';
		$raw      = $payload['content'];

		if ( 'base64' === $encoding ) {
			// GitHub may include newlines in base64 payloads.
			$raw = base64_decode( str_replace( array( "\n", "\r" ), '', $raw ), true );

			if ( false === $raw ) {
				return new WP_Error(
					'promptweb_github_decode',
					__( 'Could not decode the blueprint file from GitHub (base64).', 'promptweb' )
				);
			}
		}

		$raw = (string) $raw;

		if ( '' === trim( $raw ) ) {
			return new WP_Error(
				'promptweb_blueprint_empty',
				__( 'The blueprint file is empty.', 'promptweb' )
			);
		}

		$data = json_decode( $raw, true );

		if ( JSON_ERROR_NONE !== json_last_error() ) {
			return new WP_Error(
				'promptweb_invalid_json',
				sprintf(
					/* translators: %s: json_last_error_msg() */
					__( 'Blueprint is not valid JSON: %s', 'promptweb' ),
					json_last_error_msg()
				)
			);
		}

		return array(
			'raw_json' => $raw,
			'data'     => $data,
			'path'     => $this->get_blueprint_path( $use_network ),
			'branch'   => $this->get_branch( $use_network ),
			'repo'     => $this->get_repo( $use_network ),
			'sha'      => isset( $payload['sha'] ) ? (string) $payload['sha'] : '',
		);
	}

	/**
	 * Main sync: configure check → fetch → validate JSON → update last_synced.
	 *
	 * Does not convert the blueprint into Gutenberg blocks.
	 *
	 * @since 1.0.0
	 * @param array $args {
	 *     Optional. Sync arguments.
	 *
	 *     @type bool|null $use_network Force network/site settings storage for read + last_synced.
	 * }
	 * @return array{
	 *     success: bool,
	 *     message: string,
	 *     code?: string,
	 *     data?: array
	 * }
	 */
	public function sync( $args = array() ) {
		$args = wp_parse_args(
			$args,
			array(
				'use_network' => null,
			)
		);

		// Resolve storage once so admin (network vs site) stays consistent.
		if ( null === $args['use_network'] ) {
			$args['use_network'] = PromptWeb_Settings::use_network_options();
		}

		$use_network = (bool) $args['use_network'];

		if ( ! $this->is_configured( $use_network ) ) {
			return array(
				'success' => false,
				'code'    => 'promptweb_not_configured',
				'message' => __( 'GitHub is not configured. Please add a Personal Access Token and repository, then save settings.', 'promptweb' ),
			);
		}

		$result = $this->fetch_blueprint(
			array(
				'use_network' => $use_network,
			)
		);

		if ( is_wp_error( $result ) ) {
			return array(
				'success' => false,
				'code'    => $result->get_error_code(),
				'message' => $result->get_error_message(),
			);
		}

		// Convert decoded blueprint JSON into Gutenberg pages on the current site.
		$converter = function_exists( 'promptweb' ) ? promptweb()->converter : null;
		if ( ! $converter instanceof PromptWeb_Converter ) {
			$converter = new PromptWeb_Converter();
		}

		$convert = $converter->convert_blueprint( $result['data'] );

		if ( empty( $convert['success'] ) ) {
			return array(
				'success' => false,
				'code'    => isset( $convert['code'] ) ? $convert['code'] : 'promptweb_convert_failed',
				'message' => isset( $convert['message'] )
					? $convert['message']
					: __( 'Blueprint was fetched but could not be converted into pages.', 'promptweb' ),
				'data'    => array(
					'fetch'   => $result,
					'convert' => $convert,
				),
			);
		}

		// Mark last successful sync only after fetch + convert succeed.
		$updated = PromptWeb_Settings::update_last_synced( null, $use_network );

		if ( ! $updated ) {
			// update_option returns false when value is unchanged; treat as soft success if timestamp exists.
			$last = $this->get_last_synced( $use_network );
			if ( empty( $last ) ) {
				return array(
					'success' => false,
					'code'    => 'promptweb_last_synced_failed',
					'message' => __( 'Blueprint was converted successfully, but the last-synced timestamp could not be saved.', 'promptweb' ),
					'data'    => array(
						'fetch'   => $result,
						'convert' => $convert,
					),
				);
			}
		}

		/**
		 * Fires after a successful GitHub blueprint sync + conversion.
		 *
		 * @since 1.0.0
		 * @param array $result      Fetch payload (raw_json, data, path, branch, repo, sha).
		 * @param bool  $use_network Whether network options were used.
		 * @param array $convert     Conversion result from PromptWeb_Converter.
		 */
		do_action( 'promptweb_github_synced', $result, $use_network, $convert );

		$sync_message = sprintf(
			/* translators: 1: repository, 2: path */
			__( 'Blueprint synced from %1$s (%2$s).', 'promptweb' ),
			$result['repo'],
			$result['path']
		);

		if ( ! empty( $convert['message'] ) ) {
			$sync_message .= ' ' . $convert['message'];
		}

		return array(
			'success' => true,
			'code'    => 'promptweb_sync_success',
			'message' => $sync_message,
			'data'    => array(
				'fetch'   => $result,
				'convert' => $convert,
			),
		);
	}

	/**
	 * Pull a human-readable message from a GitHub error JSON body.
	 *
	 * @since 1.0.0
	 * @param string $body Response body.
	 * @return string Empty string if none.
	 */
	private function extract_github_error_message( $body ) {
		if ( ! is_string( $body ) || '' === $body ) {
			return '';
		}

		$decoded = json_decode( $body, true );

		if ( ! is_array( $decoded ) || empty( $decoded['message'] ) ) {
			return '';
		}

		return sanitize_text_field( (string) $decoded['message'] );
	}
}
