<?php
/**
 * GitHub connection, blueprint fetch, and sync helpers.
 *
 * Maximum AI Creativity: Sync pulls structured JSON (source of truth) from GitHub,
 * validates loosely via PromptWeb_Schema, stores it for Renderer/Editor, and
 * updates last_synced. Gutenberg conversion is off by default (deprecated).
 *
 * Settings / blueprint storage are Multisite-aware (PromptWeb_Settings).
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
 * Primary path stores JSON for Renderer/Editor. Gutenberg is opt-in legacy only.
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
	/**
	 * Option key for last known remote blueprint content SHA (throttle helper).
	 *
	 * @since 1.0.0
	 * @var   string
	 */
	const REMOTE_SHA_OPTION = 'promptweb_blueprint_remote_sha';

	/**
	 * Transient prefix for auto-sync throttle locks.
	 *
	 * @since 1.0.0
	 * @var   string
	 */
	const AUTO_SYNC_LOCK = 'promptweb_auto_sync_lock';

	/**
	 * Default minimum seconds between automatic GitHub fetches.
	 *
	 * @since 1.0.0
	 * @var   int
	 */
	const AUTO_SYNC_INTERVAL = 120;

	/**
	 * Bootstrap hooks (including Auto-Detect / auto-sync).
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public function init() {
		// Public front only: refresh blueprint from design repo when Auto-Detect is on.
		add_action( 'template_redirect', array( $this, 'maybe_auto_sync' ), 0 );

		/**
		 * Fires when the GitHub component is initialized.
		 *
		 * @since 1.0.0
		 * @param PromptWeb_GitHub $github This instance.
		 */
		do_action( 'promptweb_github_init', $this );
	}

	/**
	 * Automatically sync blueprint from GitHub (throttled).
	 *
	 * Runs on public page views when Auto-Detect is enabled. Does not wipe
	 * connection settings. Manual Sync remains available as a backup.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public function maybe_auto_sync() {
		if ( is_admin() ) {
			return;
		}
		if ( ( defined( 'REST_REQUEST' ) && REST_REQUEST ) || ( defined( 'DOING_AJAX' ) && DOING_AJAX ) || ( defined( 'DOING_CRON' ) && DOING_CRON ) || ( defined( 'WP_CLI' ) && WP_CLI ) ) {
			return;
		}
		if ( ! $this->is_auto_detect_enabled() ) {
			return;
		}
		if ( ! $this->is_configured() ) {
			return;
		}

		/**
		 * Filters whether automatic GitHub sync may run on this request.
		 *
		 * @since 1.0.0
		 * @param bool             $allow  Default true when Auto-Detect is on.
		 * @param PromptWeb_GitHub $github This instance.
		 */
		if ( ! apply_filters( 'promptweb_allow_auto_sync', true, $this ) ) {
			return;
		}

		$use_network = PromptWeb_Settings::use_network_options();
		$lock_key    = self::AUTO_SYNC_LOCK . '_' . ( $use_network ? 'network' : (string) get_current_blog_id() );

		/**
		 * Filters auto-sync minimum interval in seconds (default 120).
		 *
		 * @since 1.0.0
		 * @param int $seconds Interval.
		 */
		$interval = (int) apply_filters( 'promptweb_auto_sync_interval', self::AUTO_SYNC_INTERVAL );
		if ( $interval < 30 ) {
			$interval = 30;
		}

		// Throttle: skip if we synced recently (site vs network lock).
		$locked = $use_network ? get_site_transient( $lock_key ) : get_transient( $lock_key );
		if ( false !== $locked ) {
			return;
		}

		// Set lock first to prevent stampedes from parallel page views.
		if ( $use_network ) {
			set_site_transient( $lock_key, 1, $interval );
		} else {
			set_transient( $lock_key, 1, $interval );
		}

		// Skip network call if remote SHA unchanged (HEAD/meta only is still a GET; use full meta).
		$meta = $this->get_remote_file_meta( $use_network );
		if ( is_wp_error( $meta ) ) {
			// Soft-fail: keep lock so we do not hammer a failing API.
			return;
		}

		$remote_sha = isset( $meta['sha'] ) ? (string) $meta['sha'] : '';
		$local_sha  = $use_network
			? (string) get_site_option( self::REMOTE_SHA_OPTION, '' )
			: (string) get_option( self::REMOTE_SHA_OPTION, '' );

		if ( '' !== $remote_sha && $remote_sha === $local_sha ) {
			// Already up to date — no full download.
			return;
		}

		// Newer remote (or first sync): pull and store blueprint only (never wipe connection settings).
		$result = $this->sync(
			array(
				'use_network' => $use_network,
			)
		);

		if ( ! empty( $result['success'] ) ) {
			$sha = '';
			if ( ! empty( $result['data']['fetch']['sha'] ) ) {
				$sha = (string) $result['data']['fetch']['sha'];
			} elseif ( '' !== $remote_sha ) {
				$sha = $remote_sha;
			}
			if ( '' !== $sha ) {
				$this->store_remote_sha( $sha, $use_network );
			}

			/**
			 * Fires after a successful automatic blueprint sync.
			 *
			 * @since 1.0.0
			 * @param array $result Sync result.
			 */
			do_action( 'promptweb_auto_synced', $result );
		}
	}

	/**
	 * Persist last known remote content SHA (Multisite-aware).
	 *
	 * @since 1.0.0
	 * @param string $sha        Git blob SHA.
	 * @param bool   $use_network Network storage.
	 * @return void
	 */
	public function store_remote_sha( $sha, $use_network = false ) {
		$sha = sanitize_text_field( (string) $sha );
		if ( $use_network ) {
			update_site_option( self::REMOTE_SHA_OPTION, $sha );
			return;
		}
		update_option( self::REMOTE_SHA_OPTION, $sha, false );
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
	 * Default HTTP headers for GitHub API requests.
	 *
	 * @since 1.0.0
	 * @param string $token Personal access token.
	 * @return array
	 */
	protected function get_api_headers( $token ) {
		return array(
			'Accept'               => 'application/vnd.github+json',
			'Authorization'        => 'Bearer ' . $token,
			'X-GitHub-Api-Version' => '2022-11-28',
			'User-Agent'           => 'PromptWeb/' . PROMPTWEB_VERSION . '; WordPress/' . get_bloginfo( 'version' ),
		);
	}

	/**
	 * Contents API URL without ?ref= (used for PUT create/update).
	 *
	 * @since 1.0.0
	 * @param bool|null $use_network Storage context.
	 * @return string|WP_Error
	 */
	public function get_contents_put_url( $use_network = null ) {
		$url = $this->get_contents_api_url( $use_network );
		if ( is_wp_error( $url ) ) {
			return $url;
		}

		// Strip ref query — branch is sent in the PUT body.
		return remove_query_arg( 'ref', $url );
	}

	/**
	 * Fetch only the remote file SHA (and metadata) for update commits.
	 *
	 * @since 1.0.0
	 * @param bool|null $use_network Storage context.
	 * @return array|WP_Error { sha, path, branch, repo } or empty sha if file missing (404).
	 */
	public function get_remote_file_meta( $use_network = null ) {
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

		$response = wp_remote_get(
			$url,
			array(
				'timeout' => 30,
				'headers' => $this->get_api_headers( $token ),
			)
		);

		if ( is_wp_error( $response ) ) {
			return new WP_Error(
				'promptweb_http_error',
				sprintf(
					/* translators: %s: transport error */
					__( 'Could not reach GitHub: %s', 'promptweb' ),
					$response->get_error_message()
				)
			);
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$body = wp_remote_retrieve_body( $response );

		// File does not exist yet — create without sha.
		if ( 404 === $code ) {
			return array(
				'sha'    => '',
				'path'   => $this->get_blueprint_path( $use_network ),
				'branch' => $this->get_branch( $use_network ),
				'repo'   => $this->get_repo( $use_network ),
				'exists' => false,
			);
		}

		if ( 401 === $code || 403 === $code ) {
			return new WP_Error(
				'promptweb_github_auth',
				__( 'GitHub authentication failed or access was denied. Check your token permissions (Contents: Read and write).', 'promptweb' ),
				array( 'status' => $code )
			);
		}

		if ( $code < 200 || $code >= 300 ) {
			$api_message = $this->extract_github_error_message( $body );
			return new WP_Error(
				'promptweb_github_http',
				$api_message
					? sprintf(
						/* translators: 1: HTTP status, 2: message */
						__( 'GitHub API error (%1$d): %2$s', 'promptweb' ),
						$code,
						$api_message
					)
					: sprintf(
						/* translators: %d: HTTP status */
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

		return array(
			'sha'    => isset( $payload['sha'] ) ? (string) $payload['sha'] : '',
			'path'   => $this->get_blueprint_path( $use_network ),
			'branch' => $this->get_branch( $use_network ),
			'repo'   => $this->get_repo( $use_network ),
			'exists' => true,
		);
	}

	/**
	 * Create or update a text file in the connected repository (Contents API).
	 *
	 * Handles both:
	 * - Creating a new file (no remote sha)
	 * - Updating an existing file (sha required by GitHub)
	 *
	 * Multisite: uses token/repo/branch from network or site settings via $use_network.
	 *
	 * @since 1.0.0
	 * @param string    $contents    Raw file contents (not base64).
	 * @param string    $commit_msg  Commit message.
	 * @param bool|null $use_network Storage context; null = auto.
	 * @param string    $path        Optional path override (default: settings blueprint_path, e.g. blueprints/latest.json).
	 * @return array|WP_Error {
	 *     @type string $commit_sha
	 *     @type string $content_sha
	 *     @type string $path
	 *     @type string $branch
	 *     @type string $repo
	 * }
	 */
	public function create_or_update_file( $contents, $commit_msg = '', $use_network = null, $path = '' ) {
		if ( null === $use_network ) {
			$use_network = PromptWeb_Settings::use_network_options();
		}

		if ( ! $this->is_configured( $use_network ) ) {
			return new WP_Error(
				'promptweb_not_configured',
				__( 'GitHub is not configured. Please add a Personal Access Token and repository.', 'promptweb' )
			);
		}

		$contents = (string) $contents;
		$path     = is_string( $path ) ? trim( $path ) : '';
		if ( '' === $path ) {
			$path = $this->get_blueprint_path( $use_network );
		}
		$path = ltrim( str_replace( '\\', '/', $path ), '/' );

		// Temporary override path for URL builders when custom path passed.
		$branch = $this->get_branch( $use_network );
		$repo   = $this->get_repo( $use_network );
		$token  = $this->get_token( $use_network );

		// Build contents URL for this path + branch (GET meta).
		if ( false === strpos( $repo, '/' ) ) {
			return new WP_Error(
				'promptweb_invalid_repo',
				__( 'Repository must be in the format owner/repository.', 'promptweb' )
			);
		}

		list( $owner, $name ) = array_pad( explode( '/', $repo, 2 ), 2, '' );
		$owner = trim( $owner );
		$name  = trim( $name );

		$path_segments = array_map( 'rawurlencode', explode( '/', $path ) );
		$encoded_path  = implode( '/', $path_segments );
		$get_url       = sprintf(
			'%s/repos/%s/%s/contents/%s',
			self::API_BASE,
			rawurlencode( $owner ),
			rawurlencode( $name ),
			$encoded_path
		);
		$get_url = add_query_arg( 'ref', $branch, $get_url );
		$put_url = remove_query_arg( 'ref', $get_url );

		// Resolve existing sha (update) or empty (create).
		$sha      = '';
		$response = wp_remote_get(
			$get_url,
			array(
				'timeout' => 30,
				'headers' => $this->get_api_headers( $token ),
			)
		);

		if ( is_wp_error( $response ) ) {
			return new WP_Error(
				'promptweb_http_error',
				sprintf(
					/* translators: %s: error */
					__( 'Could not reach GitHub: %s', 'promptweb' ),
					$response->get_error_message()
				)
			);
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$body = wp_remote_retrieve_body( $response );

		if ( 401 === $code || 403 === $code ) {
			return new WP_Error(
				'promptweb_github_auth',
				__( 'GitHub authentication failed or access was denied. Check your token permissions (Contents: Read and write).', 'promptweb' ),
				array( 'status' => $code )
			);
		}

		if ( 404 !== $code && ( $code < 200 || $code >= 300 ) ) {
			$api_message = $this->extract_github_error_message( $body );
			return new WP_Error(
				'promptweb_github_http',
				$api_message
					? sprintf(
						/* translators: 1: status, 2: message */
						__( 'GitHub API error (%1$d): %2$s', 'promptweb' ),
						$code,
						$api_message
					)
					: sprintf(
						/* translators: %d: status */
						__( 'GitHub API returned an unexpected response (HTTP %d).', 'promptweb' ),
						$code
					),
				array( 'status' => $code )
			);
		}

		if ( 200 === $code ) {
			$payload = json_decode( $body, true );
			if ( is_array( $payload ) && ! empty( $payload['sha'] ) ) {
				$sha = (string) $payload['sha'];
			}
		}

		if ( '' === trim( $commit_msg ) ) {
			$commit_msg = sprintf(
				/* translators: %s: file path */
				__( 'Update %s via PromptWeb', 'promptweb' ),
				$path
			);
		}

		$put_body = array(
			'message' => $commit_msg,
			'content' => base64_encode( $contents ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
			'branch'  => $branch,
		);
		if ( '' !== $sha ) {
			$put_body['sha'] = $sha;
		}

		$put_response = wp_remote_request(
			$put_url,
			array(
				'method'  => 'PUT',
				'timeout' => 45,
				'headers' => array_merge(
					$this->get_api_headers( $token ),
					array( 'Content-Type' => 'application/json' )
				),
				'body'    => wp_json_encode( $put_body ),
			)
		);

		if ( is_wp_error( $put_response ) ) {
			return new WP_Error(
				'promptweb_http_error',
				sprintf(
					/* translators: %s: error */
					__( 'Could not reach GitHub: %s', 'promptweb' ),
					$put_response->get_error_message()
				)
			);
		}

		$put_code = (int) wp_remote_retrieve_response_code( $put_response );
		$put_body_raw = wp_remote_retrieve_body( $put_response );
		$decoded      = json_decode( $put_body_raw, true );

		if ( 401 === $put_code || 403 === $put_code ) {
			return new WP_Error(
				'promptweb_github_auth',
				__( 'GitHub rejected the push. Check that your token can write to this repository (Contents: Read and write).', 'promptweb' ),
				array( 'status' => $put_code )
			);
		}

		if ( 409 === $put_code || 422 === $put_code ) {
			$api_message = is_array( $decoded ) && ! empty( $decoded['message'] )
				? sanitize_text_field( (string) $decoded['message'] )
				: '';
			return new WP_Error(
				'promptweb_github_conflict',
				$api_message
					? sprintf(
						/* translators: %s: GitHub message */
						__( 'GitHub could not update the file (conflict or validation): %s', 'promptweb' ),
						$api_message
					)
					: __( 'GitHub could not update the file (conflict or validation). Try Sync first, then push again.', 'promptweb' ),
				array( 'status' => $put_code )
			);
		}

		if ( $put_code < 200 || $put_code >= 300 ) {
			$api_message = $this->extract_github_error_message( $put_body_raw );
			return new WP_Error(
				'promptweb_github_http',
				$api_message
					? sprintf(
						/* translators: 1: status, 2: message */
						__( 'GitHub push failed (%1$d): %2$s', 'promptweb' ),
						$put_code,
						$api_message
					)
					: sprintf(
						/* translators: %d: status */
						__( 'GitHub push failed (HTTP %d).', 'promptweb' ),
						$put_code
					),
				array( 'status' => $put_code )
			);
		}

		return array(
			'commit_sha'  => ( is_array( $decoded ) && ! empty( $decoded['commit']['sha'] ) ) ? (string) $decoded['commit']['sha'] : '',
			'content_sha' => ( is_array( $decoded ) && ! empty( $decoded['content']['sha'] ) ) ? (string) $decoded['content']['sha'] : '',
			'path'        => $path,
			'branch'      => $branch,
			'repo'        => $repo,
			'created'     => ( '' === $sha ),
		);
	}

	/**
	 * Push blueprint JSON to GitHub (Contents API create/update).
	 *
	 * Writes to the configured blueprint path (default: blueprints/latest.json).
	 * Also stores the blueprint locally and updates last_synced on success.
	 *
	 * @since 1.0.0
	 * @param array $blueprint Blueprint array (pages → sections → elements).
	 * @param array $args {
	 *     Optional.
	 *
	 *     @type bool|null $use_network Storage / credentials context.
	 *     @type string    $message     Commit message.
	 * }
	 * @return array{
	 *     success: bool,
	 *     message: string,
	 *     code?: string,
	 *     data?: array
	 * }
	 */
	public function push_blueprint( $blueprint, $args = array() ) {
		$args = wp_parse_args(
			$args,
			array(
				'use_network' => null,
				'message'     => '',
			)
		);

		if ( null === $args['use_network'] ) {
			$args['use_network'] = PromptWeb_Settings::use_network_options();
		}

		$use_network = (bool) $args['use_network'];

		if ( ! is_array( $blueprint ) ) {
			return array(
				'success' => false,
				'code'    => 'promptweb_invalid_blueprint',
				'message' => __( 'Blueprint must be a JSON object.', 'promptweb' ),
			);
		}

		// Guard empty payload (malformed client save).
		if ( empty( $blueprint ) ) {
			return array(
				'success' => false,
				'code'    => 'promptweb_invalid_blueprint',
				'message' => __( 'Cannot push an empty blueprint.', 'promptweb' ),
			);
		}

		if ( ! $this->is_configured( $use_network ) ) {
			return array(
				'success' => false,
				'code'    => 'promptweb_not_configured',
				'message' => __( 'GitHub is not configured. Add a token and repository in PromptWeb settings.', 'promptweb' ),
			);
		}

		// Loose schema validation before writing to the source of truth.
		if ( class_exists( 'PromptWeb_Schema' ) ) {
			$valid = PromptWeb_Schema::validate( $blueprint );
			if ( is_wp_error( $valid ) ) {
				return array(
					'success' => false,
					'code'    => $valid->get_error_code(),
					'message' => $valid->get_error_message(),
				);
			}
			$blueprint = PromptWeb_Schema::normalize( $blueprint );
		}

		/**
		 * Filters blueprint data immediately before encoding for GitHub push.
		 *
		 * @since 1.0.0
		 * @param array $blueprint   Blueprint payload.
		 * @param bool  $use_network Network context.
		 */
		$blueprint = apply_filters( 'promptweb_before_github_push', $blueprint, $use_network );

		if ( ! is_array( $blueprint ) ) {
			return array(
				'success' => false,
				'code'    => 'promptweb_invalid_blueprint',
				'message' => __( 'Blueprint became invalid before push.', 'promptweb' ),
			);
		}

		$json = wp_json_encode( $blueprint, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		if ( ! is_string( $json ) || '' === $json ) {
			return array(
				'success' => false,
				'code'    => 'promptweb_json_encode_failed',
				'message' => __( 'Could not encode blueprint as JSON.', 'promptweb' ),
			);
		}

		// Ensure trailing newline (common for repo files).
		if ( "\n" !== substr( $json, -1 ) ) {
			$json .= "\n";
		}

		$path = $this->get_blueprint_path( $use_network );
		// Prefer configured path; default product path is blueprints/latest.json.
		if ( '' === $path ) {
			$path = 'blueprints/latest.json';
		}

		$message = is_string( $args['message'] ) ? trim( $args['message'] ) : '';
		if ( '' === $message ) {
			$message = sprintf(
				/* translators: 1: site name, 2: path */
				__( 'Update %2$s via PromptWeb (%1$s)', 'promptweb' ),
				wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ),
				$path
			);
		}

		/**
		 * Filters the Git commit message for blueprint push.
		 *
		 * @since 1.0.0
		 * @param string $message     Commit message.
		 * @param array  $blueprint   Blueprint being pushed.
		 * @param bool   $use_network Network context.
		 */
		$message = apply_filters( 'promptweb_github_push_commit_message', $message, $blueprint, $use_network );

		$file_result = $this->create_or_update_file( $json, $message, $use_network, $path );

		if ( is_wp_error( $file_result ) ) {
			return array(
				'success' => false,
				'code'    => $file_result->get_error_code(),
				'message' => $file_result->get_error_message(),
			);
		}

		// Persist locally so Renderer/Editor match the remote source of truth.
		PromptWeb_Settings::save_blueprint( $blueprint, $use_network );
		PromptWeb_Settings::update_last_synced( null, $use_network );

		// Align auto-sync SHA so the next page view does not immediately re-pull.
		if ( ! empty( $file_result['content_sha'] ) ) {
			$this->store_remote_sha( (string) $file_result['content_sha'], $use_network );
		}

		$repo   = isset( $file_result['repo'] ) ? $file_result['repo'] : $this->get_repo( $use_network );
		$branch = isset( $file_result['branch'] ) ? $file_result['branch'] : $this->get_branch( $use_network );

		/**
		 * Fires after a successful blueprint push to GitHub.
		 *
		 * @since 1.0.0
		 * @param array $blueprint   Pushed blueprint.
		 * @param bool  $use_network Network context.
		 * @param array $meta        Remote meta (repo, path, branch, commit_sha, …).
		 */
		do_action(
			'promptweb_github_pushed',
			$blueprint,
			$use_network,
			array(
				'repo'        => $repo,
				'path'        => $path,
				'branch'      => $branch,
				'commit_sha'  => isset( $file_result['commit_sha'] ) ? $file_result['commit_sha'] : '',
				'content_sha' => isset( $file_result['content_sha'] ) ? $file_result['content_sha'] : '',
				'created'     => ! empty( $file_result['created'] ),
			)
		);

		return array(
			'success' => true,
			'code'    => 'promptweb_push_success',
			'message' => sprintf(
				/* translators: 1: repo, 2: path, 3: branch */
				__( 'Blueprint pushed to %1$s (%2$s @ %3$s).', 'promptweb' ),
				$repo,
				$path,
				$branch
			),
			'data'    => array(
				'repo'        => $repo,
				'path'        => $path,
				'branch'      => $branch,
				'commit_sha'  => isset( $file_result['commit_sha'] ) ? $file_result['commit_sha'] : '',
				'content_sha' => isset( $file_result['content_sha'] ) ? $file_result['content_sha'] : '',
				'created'     => ! empty( $file_result['created'] ),
				'blueprint'   => $blueprint,
			),
		);
	}

	/**
	 * Whether a path exists in the connected repository (current branch).
	 *
	 * @since 1.0.0
	 * @param string    $path        Repo-relative path.
	 * @param bool|null $use_network Storage context.
	 * @return bool|WP_Error True if exists, false if 404, WP_Error on failure.
	 */
	public function remote_file_exists( $path, $use_network = null ) {
		if ( null === $use_network ) {
			$use_network = PromptWeb_Settings::use_network_options();
		}

		if ( ! $this->is_configured( $use_network ) ) {
			return new WP_Error(
				'promptweb_not_configured',
				__( 'GitHub is not configured.', 'promptweb' )
			);
		}

		$path = ltrim( str_replace( '\\', '/', (string) $path ), '/' );
		$repo = $this->get_repo( $use_network );
		if ( false === strpos( $repo, '/' ) ) {
			return new WP_Error( 'promptweb_invalid_repo', __( 'Invalid repository.', 'promptweb' ) );
		}

		list( $owner, $name ) = array_pad( explode( '/', $repo, 2 ), 2, '' );
		$path_segments        = array_map( 'rawurlencode', explode( '/', $path ) );
		$url                  = sprintf(
			'%s/repos/%s/%s/contents/%s',
			self::API_BASE,
			rawurlencode( trim( $owner ) ),
			rawurlencode( trim( $name ) ),
			implode( '/', $path_segments )
		);
		$url = add_query_arg( 'ref', $this->get_branch( $use_network ), $url );

		$response = wp_remote_get(
			$url,
			array(
				'timeout' => 20,
				'headers' => $this->get_api_headers( $this->get_token( $use_network ) ),
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( 404 === $code ) {
			return false;
		}
		if ( $code >= 200 && $code < 300 ) {
			return true;
		}
		if ( 401 === $code || 403 === $code ) {
			return new WP_Error(
				'promptweb_github_auth',
				__( 'GitHub authentication failed or access was denied.', 'promptweb' )
			);
		}

		return new WP_Error(
			'promptweb_github_http',
			sprintf(
				/* translators: %d: status */
				__( 'Could not check remote file (HTTP %d).', 'promptweb' ),
				$code
			)
		);
	}

	/**
	 * Check whether the repo looks AI-ready (blueprint + AI_INSTRUCTIONS.md).
	 *
	 * @since 1.0.0
	 * @param bool|null $use_network Storage context.
	 * @return array{
	 *     ready: bool,
	 *     blueprint: bool|WP_Error,
	 *     instructions: bool|WP_Error,
	 *     blueprint_path: string,
	 *     instructions_path: string
	 * }
	 */
	public function get_initialization_status( $use_network = null ) {
		if ( null === $use_network ) {
			$use_network = PromptWeb_Settings::use_network_options();
		}

		$blueprint_path    = $this->get_blueprint_path( $use_network );
		if ( '' === $blueprint_path ) {
			$blueprint_path = 'blueprints/latest.json';
		}
		$instructions_path = 'AI_INSTRUCTIONS.md';

		$has_blueprint    = $this->remote_file_exists( $blueprint_path, $use_network );
		$has_instructions = $this->remote_file_exists( $instructions_path, $use_network );

		$ready = ( true === $has_blueprint && true === $has_instructions );

		return array(
			'ready'              => $ready,
			'blueprint'          => $has_blueprint,
			'instructions'       => $has_instructions,
			'blueprint_path'     => $blueprint_path,
			'instructions_path'  => $instructions_path,
			'repo'               => $this->get_repo( $use_network ),
			'branch'             => $this->get_branch( $use_network ),
		);
	}

	/**
	 * Markdown guide for external AIs working on this repository.
	 *
	 * @since 1.0.0
	 * @return string
	 */
	public function get_ai_instructions_markdown() {
		$live_url = home_url( '/' );
		$site     = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
		$repo     = $this->get_repo();
		$branch   = $this->get_branch();
		$path     = $this->get_blueprint_path();
		if ( '' === $path ) {
			$path = 'blueprints/latest.json';
		}

		$md  = "# PromptWeb — AI Agent Instructions\n\n";
		$md .= "> **Always read `README.md` and this file first** before editing anything.\n\n";
		$md .= "You are an **expert web designer + frontend developer** for a **PromptWeb** project.\n";
		$md .= "The human only writes **simple plain English**. You own all technical design work.\n\n";
		$md .= "## Live site & design repository (fill / use these)\n\n";
		$md .= "| Context | Value |\n";
		$md .= "|---------|-------|\n";
		$md .= '| **Live website URL** | ' . $live_url . " |\n";
		$md .= '| **Site name** | ' . ( $site ? $site : 'PromptWeb site' ) . " |\n";
		$md .= '| **Design repository** | `' . ( $repo ? $repo : 'YOUR_DESIGN_REPO' ) . '` |\n';
		$md .= '| **Branch** | `' . ( $branch ? $branch : 'main' ) . "` |\n";
		$md .= '| **Blueprint path** | `' . $path . "` |\n";
		$md .= "| **Plugin code repo** | `Akashmali6198/promptweb` (separate — **never** mix with design) |\n\n";
		$md .= "**After every completed task, always return the live published website URL:**\n";
		$md .= '→ **' . $live_url . "**\n\n";
		$md .= "---\n\n";
		$md .= "## Mandatory workflow\n\n";
		$md .= "1. Read **README.md** + **AI_INSTRUCTIONS.md** (this file).\n";
		$md .= "2. Read **`" . $path . "`** (only file for website design).\n";
		$md .= "3. Accept **plain-English prompts only**, e.g.\n";
		$md .= "   - *\"design a 5 section homepage for web developer portfolio\"*\n";
		$md .= "   - *\"make it more premium and modern\"*\n";
		$md .= "   - *\"update changes\" / \"publish changes\" / \"PromptWeb update changes\"*\n";
		$md .= "4. **Do not** ask the human for JSON schema or field names.\n";
		$md .= "5. Update design with **design tokens** + professional sections.\n";
		$md .= "6. Save valid JSON to `" . $path . "`.\n";
		$md .= "7. Mark pending prompts `done`.\n";
		$md .= "8. Reply with live URL: **" . $live_url . "**\n\n";
		$md .= "---\n\n";
		$md .= "## Design tokens (required for consistent professional styling)\n\n";
		$md .= "Always create or keep a top-level `design` object. WordPress maps it to CSS variables for free.\n\n";
		$md .= "```json\n";
		$md .= "{\n  \"design\": {\n    \"colors\": {\n      \"primary\": \"#4F46E5\",\n      \"primary_dark\": \"#3730A3\",\n      \"ink\": \"#0F172A\",\n      \"muted\": \"#64748B\",\n      \"surface\": \"#FFFFFF\",\n      \"surface_alt\": \"#F8FAFC\",\n      \"bg\": \"#F1F5F9\",\n      \"border\": \"#E2E8F0\"\n    },\n    \"font_family\": \"Inter, system-ui, sans-serif\",\n    \"radius\": \"12px\",\n    \"shadow\": \"0 10px 30px rgba(15, 23, 42, 0.08)\",\n    \"container_width\": \"1120px\"\n  }\n}\n";
		$md .= "```\n\n";
		$md .= "- If tokens are missing, the site still renders with clean defaults — but **you should always set them** for brand consistency.\n";
		$md .= "- Prefer token colors over random one-off hex on every element (use `settings` for local accents only).\n\n";
		$md .= "---\n\n";
		$md .= "## Structure (pages → sections → elements)\n\n";
		$md .= "```text\nblueprint\n├── version\n├── site\n├── design   ← tokens\n├── pages[]\n│   └── sections[]   (variant: hero | features | about | stats | testimonials | cta | …)\n│       └── elements[]\n└── prompts[]\n```\n\n";
		$md .= "### Section tips for beautiful pages\n\n";
		$md .= "- Use 4–7 sections on a homepage (hero, features/cards, about, stats, testimonials, CTA…).\n";
		$md .= "- Set `settings.variant` to `hero`, `features`, `about`, `stats`, `testimonials`, or `cta` when possible.\n";
		$md .= "- For card grids: `settings.layout: \"grid\"` and `settings.columns: 3` (or 2/4).\n";
		$md .= "- Use `card` elements (or nested cards) for features/pricing/testimonials.\n";
		$md .= "- Buttons: `settings.variant` = `primary` | `secondary` | `outline` | `ghost`.\n";
		$md .= "- Images: direct public URLs in `settings.src` / `settings.url` + `settings.alt`.\n\n";
		$md .= "### Element types (not a closed list)\n\n";
		$md .= "`heading`, `text`, `button`, `image`, `card`, `hero`, `spacer`, `html`, plus any custom type.\n\n";
		$md .= "---\n\n";
		$md .= "## Pending prompts\n\n";
		$md .= "Process `prompts[]` where `status` is `\"pending\"`. Apply plain-English `prompt` text, then set `status: \"done\"` + `resolved_at`.\n";
		$md .= "If unsafe/unclear: `status: \"blocked\"` — never destroy the site.\n\n";
		$md .= "---\n\n";
		$md .= "## Design quality bar\n\n";
		$md .= "- Modern / trending (clean SaaS, portfolio, agency quality)\n";
		$md .= "- Strong hierarchy, generous spacing, accessible contrast\n";
		$md .= "- Match **reference URLs/images** when provided (quality & vibe)\n";
		$md .= "- Mobile-friendly stacking; no clutter\n";
		$md .= "- No secrets/tokens in JSON\n\n";
		$md .= "---\n\n";
		$md .= "## Hard rules\n\n";
		$md .= "1. Edit **only** `" . $path . "` for design (plus this guide if needed).\n";
		$md .= "2. Keep valid JSON; preserve element `id`s.\n";
		$md .= "3. Do not ask the human for technical schema details.\n";
		$md .= "4. Do not wipe unrelated pages unless asked.\n";
		$md .= "5. Always end with the live URL: **" . $live_url . "**\n\n";
		$md .= "**PromptWeb — Maximum AI Creativity + Design Tokens (100% free)**\n";

		/**
		 * Filters the AI_INSTRUCTIONS.md body used during repository initialization.
		 *
		 * @since 1.0.0
		 * @param string $md Markdown contents.
		 */
		return (string) apply_filters( 'promptweb_ai_instructions_markdown', $md );
	}

	/**
	 * Initialize an AI-ready repository: write starter blueprint + AI_INSTRUCTIONS.md.
	 *
	 * Creates or updates:
	 * - blueprints/latest.json (or configured blueprint_path)
	 * - AI_INSTRUCTIONS.md
	 *
	 * Does not call any external AI API.
	 *
	 * @since 1.0.0
	 * @param array $args {
	 *     Optional.
	 *
	 *     @type bool|null $use_network Storage context.
	 *     @type bool      $force       Overwrite even if files exist.
	 * }
	 * @return array{ success: bool, message: string, code?: string, data?: array }
	 */
	public function initialize_repository( $args = array() ) {
		$args = wp_parse_args(
			$args,
			array(
				'use_network' => null,
				'force'       => true,
			)
		);

		if ( null === $args['use_network'] ) {
			$args['use_network'] = PromptWeb_Settings::use_network_options();
		}

		$use_network = (bool) $args['use_network'];

		if ( ! $this->is_configured( $use_network ) ) {
			return array(
				'success' => false,
				'code'    => 'promptweb_not_configured',
				'message' => __( 'GitHub is not configured. Save a Personal Access Token and repository first.', 'promptweb' ),
			);
		}

		$blueprint_path = $this->get_blueprint_path( $use_network );
		if ( '' === $blueprint_path ) {
			$blueprint_path = 'blueprints/latest.json';
		}
		$instructions_path = 'AI_INSTRUCTIONS.md';

		$starter = class_exists( 'PromptWeb_Schema' )
			? PromptWeb_Schema::get_starter_blueprint()
			: array(
				'version' => '1.0',
				'site'    => array(
					'title'   => 'My PromptWeb Site',
					'tagline' => '',
				),
				'pages'   => array(),
				'prompts' => array(),
			);

		if ( class_exists( 'PromptWeb_Schema' ) ) {
			$valid = PromptWeb_Schema::validate( $starter );
			if ( is_wp_error( $valid ) ) {
				return array(
					'success' => false,
					'code'    => $valid->get_error_code(),
					'message' => $valid->get_error_message(),
				);
			}
		}

		$json = wp_json_encode( $starter, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		if ( ! is_string( $json ) || '' === $json ) {
			return array(
				'success' => false,
				'code'    => 'promptweb_json_encode_failed',
				'message' => __( 'Could not encode the starter blueprint as JSON.', 'promptweb' ),
			);
		}
		if ( "\n" !== substr( $json, -1 ) ) {
			$json .= "\n";
		}

		$instructions = $this->get_ai_instructions_markdown();
		if ( "\n" !== substr( $instructions, -1 ) ) {
			$instructions .= "\n";
		}

		$repo   = $this->get_repo( $use_network );
		$branch = $this->get_branch( $use_network );

		$bp_result = $this->create_or_update_file(
			$json,
			sprintf(
				/* translators: %s: path */
				__( 'Initialize PromptWeb starter blueprint (%s)', 'promptweb' ),
				$blueprint_path
			),
			$use_network,
			$blueprint_path
		);

		if ( is_wp_error( $bp_result ) ) {
			return array(
				'success' => false,
				'code'    => $bp_result->get_error_code(),
				'message' => sprintf(
					/* translators: %s: error */
					__( 'Failed to write blueprint file: %s', 'promptweb' ),
					$bp_result->get_error_message()
				),
			);
		}

		$ai_result = $this->create_or_update_file(
			$instructions,
			__( 'Add PromptWeb AI_INSTRUCTIONS.md for external AI agents', 'promptweb' ),
			$use_network,
			$instructions_path
		);

		if ( is_wp_error( $ai_result ) ) {
			return array(
				'success' => false,
				'code'    => $ai_result->get_error_code(),
				'message' => sprintf(
					/* translators: %s: error */
					__( 'Blueprint was written, but AI_INSTRUCTIONS.md failed: %s', 'promptweb' ),
					$ai_result->get_error_message()
				),
				'data'    => array(
					'blueprint_path' => $blueprint_path,
					'partial'        => true,
				),
			);
		}

		// Cache starter locally so the site can render/edit immediately.
		PromptWeb_Settings::save_blueprint( $starter, $use_network );
		PromptWeb_Settings::update_last_synced( null, $use_network );

		// Turn on frontend rendering for this site/network context.
		$settings = PromptWeb_Settings::get_settings_data( $use_network );
		if ( empty( $settings['enabled'] ) ) {
			$settings['enabled'] = 1;
			if ( $use_network ) {
				update_site_option( PromptWeb_Settings::OPTION_NAME, $settings );
			} else {
				update_option( PromptWeb_Settings::OPTION_NAME, $settings, false );
			}
		}

		/**
		 * Fires after a successful AI-ready repository initialization.
		 *
		 * @since 1.0.0
		 * @param array $starter     Starter blueprint written.
		 * @param bool  $use_network Network context.
		 * @param array $meta        Paths / repo meta.
		 */
		do_action(
			'promptweb_repository_initialized',
			$starter,
			$use_network,
			array(
				'repo'              => $repo,
				'branch'            => $branch,
				'blueprint_path'    => $blueprint_path,
				'instructions_path' => $instructions_path,
			)
		);

		return array(
			'success' => true,
			'code'    => 'promptweb_init_success',
			'message' => sprintf(
				/* translators: 1: repo, 2: branch, 3: blueprint path */
				__( 'Repository initialized for AI. Wrote %3$s and AI_INSTRUCTIONS.md to %1$s @ %2$s.', 'promptweb' ),
				$repo,
				$branch,
				$blueprint_path
			),
			'data'    => array(
				'repo'              => $repo,
				'branch'            => $branch,
				'blueprint_path'    => $blueprint_path,
				'instructions_path' => $instructions_path,
				'blueprint_created' => ! empty( $bp_result['created'] ),
				'instructions_created' => ! empty( $ai_result['created'] ),
				'starter'           => $starter,
			),
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

		/*
		 * Maximum AI Creativity path (primary):
		 *   fetch JSON → loose schema validate → normalize → store blueprint → last_synced.
		 * Gutenberg conversion is NOT the main path (see PromptWeb_Converter, deprecated).
		 */
		$blueprint = isset( $result['data'] ) ? $result['data'] : null;

		if ( ! is_array( $blueprint ) ) {
			return array(
				'success' => false,
				'code'    => 'promptweb_invalid_blueprint',
				'message' => __( 'Fetched blueprint was not a valid JSON object.', 'promptweb' ),
				'data'    => array(
					'fetch' => $result,
				),
			);
		}

		if ( class_exists( 'PromptWeb_Schema' ) ) {
			$valid = PromptWeb_Schema::validate( $blueprint );
			if ( is_wp_error( $valid ) ) {
				return array(
					'success' => false,
					'code'    => $valid->get_error_code(),
					'message' => $valid->get_error_message(),
					'data'    => array(
						'fetch' => $result,
					),
				);
			}
			$blueprint = PromptWeb_Schema::normalize( $blueprint );
		}

		// Persist blueprint JSON for Renderer / Editor (Multisite-aware storage).
		// update_option() returns false when the value is unchanged — not a hard failure.
		PromptWeb_Settings::save_blueprint( $blueprint, $use_network );
		$saved = PromptWeb_Settings::get_blueprint( $use_network );
		if ( empty( $saved ) || ! is_array( $saved ) ) {
			return array(
				'success' => false,
				'code'    => 'promptweb_blueprint_store_failed',
				'message' => __( 'Blueprint was fetched but could not be stored.', 'promptweb' ),
				'data'    => array(
					'fetch' => $result,
				),
			);
		}

		// Optional LEGACY Gutenberg conversion — off by default.
		$convert = null;
		/**
		 * Filters whether Sync should still run the deprecated Gutenberg converter.
		 *
		 * Default false. Maximum AI Creativity uses JSON + Renderer only.
		 *
		 * @since 1.0.0
		 * @param bool  $use_legacy  Whether to run PromptWeb_Converter.
		 * @param array $blueprint   Normalized blueprint.
		 * @param bool  $use_network Network options context.
		 */
		$use_legacy = (bool) apply_filters( 'promptweb_sync_use_legacy_converter', false, $blueprint, $use_network );

		if ( $use_legacy && class_exists( 'PromptWeb_Converter' ) ) {
			$converter = function_exists( 'promptweb' ) ? promptweb()->get_legacy_converter() : new PromptWeb_Converter();
			$convert   = $converter->convert_blueprint( $blueprint );
		}

		$updated = PromptWeb_Settings::update_last_synced( null, $use_network );

		if ( ! $updated ) {
			$last = $this->get_last_synced( $use_network );
			if ( empty( $last ) ) {
				return array(
					'success' => false,
					'code'    => 'promptweb_last_synced_failed',
					'message' => __( 'Blueprint was stored successfully, but the last-synced timestamp could not be saved.', 'promptweb' ),
					'data'    => array(
						'fetch'     => $result,
						'blueprint' => $blueprint,
						'convert'   => $convert,
					),
				);
			}
		}

		/**
		 * Fires after a successful GitHub blueprint sync (JSON-first path).
		 *
		 * @since 1.0.0
		 * @param array      $result      Fetch payload (raw_json, data, path, branch, repo, sha).
		 * @param bool       $use_network Whether network options were used.
		 * @param array|null $convert     Legacy converter result, or null when not used.
		 * @param array|null $blueprint   Normalized stored blueprint.
		 */
		do_action( 'promptweb_github_synced', $result, $use_network, $convert, $blueprint );

		$sync_message = sprintf(
			/* translators: 1: repository, 2: path */
			__( 'Blueprint synced from %1$s (%2$s). JSON stored for Renderer/Editor.', 'promptweb' ),
			$result['repo'],
			$result['path']
		);

		if ( is_array( $convert ) && ! empty( $convert['message'] ) ) {
			$sync_message .= ' ' . $convert['message'];
		}

		// Remember remote content SHA for Auto-Detect skip-if-unchanged.
		if ( ! empty( $result['sha'] ) ) {
			$this->store_remote_sha( (string) $result['sha'], $use_network );
		}

		return array(
			'success' => true,
			'code'    => 'promptweb_sync_success',
			'message' => $sync_message,
			'data'    => array(
				'fetch'     => $result,
				'blueprint' => $blueprint,
				'convert'   => $convert,
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
