<?php
/**
 * GitHub connection, blueprint fetch, design pages sync, and commit helpers.
 *
 * Architecture (v2):
 * - Design pages live in pages/static/*.html and pages/dynamic/*.php
 * - pages/manifest.json tracks slug, type, status (draft|publish)
 * - Legacy JSON blueprints (blueprints/latest.json) remain supported
 *
 * Sync pulls blueprint JSON + design pages. commit_design_pages pushes local
 * page files. Gutenberg conversion is off by default (deprecated).
 *
 * Settings / blueprint / pages storage are Multisite-aware (PromptWeb_Settings).
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
	 * Option key for last known remote pages manifest SHA.
	 *
	 * @since 2.0.0
	 * @var   string
	 */
	const REMOTE_PAGES_SHA_OPTION = 'promptweb_pages_remote_sha';

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

		// Skip full sync if remote blueprint SHA and pages manifest SHA are unchanged.
		$remote_sha          = '';
		$blueprint_unchanged = false;
		$meta                = $this->get_remote_file_meta( $use_network );

		// Soft-fail on transport/auth errors (keep lock; do not hammer API).
		if ( is_wp_error( $meta ) ) {
			$code = $meta->get_error_code();
			if ( in_array( $code, array( 'promptweb_http_error', 'promptweb_github_auth', 'promptweb_not_configured' ), true ) ) {
				return;
			}
			// Missing blueprint file is OK - may be pages-only repo.
		} else {
			$remote_sha = isset( $meta['sha'] ) ? (string) $meta['sha'] : '';
			$local_sha  = $use_network
				? (string) get_site_option( self::REMOTE_SHA_OPTION, '' )
				: (string) get_option( self::REMOTE_SHA_OPTION, '' );
			// Empty remote sha (404 meta shape) means no blueprint yet.
			$blueprint_unchanged = ( '' === $remote_sha ) || ( '' !== $remote_sha && $remote_sha === $local_sha );
		}

		$pages_unchanged = false;
		$pages_meta      = $this->fetch_remote_file( 'pages/manifest.json', $use_network );
		if ( ! is_wp_error( $pages_meta ) && ! empty( $pages_meta['sha'] ) ) {
			$local_pages_sha = $use_network
				? (string) get_site_option( self::REMOTE_PAGES_SHA_OPTION, '' )
				: (string) get_option( self::REMOTE_PAGES_SHA_OPTION, '' );
			$pages_unchanged = ( $pages_meta['sha'] === $local_pages_sha );
		} elseif ( is_wp_error( $pages_meta ) ) {
			$pcode = $pages_meta->get_error_code();
			if ( in_array( $pcode, array( 'promptweb_http_error', 'promptweb_github_auth', 'promptweb_not_configured' ), true ) ) {
				return;
			}
			// No pages manifest remotely - treat as unchanged for pages path.
			if ( 'promptweb_github_not_found' === $pcode ) {
				$pages_unchanged = true;
			}
		}

		if ( $blueprint_unchanged && $pages_unchanged ) {
			// Already up to date - no full download.
			return;
		}

		// Newer remote (or first sync): pull design pages + blueprint (never wipe connection settings).
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
					__( 'Blueprint not found. Confirm repository "%1$s", path "%2$s", and branch "%3$s".', 'promptweb' ),
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

		// Strip ref query - branch is sent in the PUT body.
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

		// File does not exist yet - create without sha.
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
	 * @param array $blueprint Blueprint array (pages â†’ sections â†’ elements).
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
		 * @param array $meta        Remote meta (repo, path, branch, commit_sha, ...).
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
	 * Check whether the repo looks AI-ready (pages structure and/or blueprint + AI_INSTRUCTIONS.md).
	 *
	 * @since 1.0.0
	 * @param bool|null $use_network Storage context.
	 * @return array
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
		$manifest_path     = 'pages/manifest.json';

		$has_blueprint    = $this->remote_file_exists( $blueprint_path, $use_network );
		$has_instructions = $this->remote_file_exists( $instructions_path, $use_network );
		$has_manifest     = $this->remote_file_exists( $manifest_path, $use_network );

		// Ready when AI guide exists and either v2 pages or legacy blueprint is present.
		$ready = ( true === $has_instructions && ( true === $has_manifest || true === $has_blueprint ) );

		return array(
			'ready'              => $ready,
			'blueprint'          => $has_blueprint,
			'instructions'       => $has_instructions,
			'manifest'           => $has_manifest,
			'blueprint_path'     => $blueprint_path,
			'instructions_path'  => $instructions_path,
			'manifest_path'      => $manifest_path,
			'repo'               => $this->get_repo( $use_network ),
			'branch'             => $this->get_branch( $use_network ),
		);
	}

	/**
	 * Fetch raw file contents from GitHub Contents API.
	 *
	 * @since 2.0.0
	 * @param string    $path        Repo-relative path.
	 * @param bool|null $use_network Storage context.
	 * @return array|WP_Error { content: string, sha: string, path: string }
	 */
	public function fetch_remote_file( $path, $use_network = null ) {
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
				'timeout' => 30,
				'headers' => $this->get_api_headers( $this->get_token( $use_network ) ),
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$body = wp_remote_retrieve_body( $response );

		if ( 404 === $code ) {
			return new WP_Error(
				'promptweb_github_not_found',
				sprintf(
					/* translators: %s: path */
					__( 'Remote file not found: %s', 'promptweb' ),
					$path
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
						/* translators: 1: status, 2: message */
						__( 'GitHub API error (%1$d): %2$s', 'promptweb' ),
						$code,
						$api_message
					)
					: sprintf(
						/* translators: %d: status */
						__( 'GitHub API error (HTTP %d).', 'promptweb' ),
						$code
					),
				array( 'status' => $code )
			);
		}

		$payload = json_decode( $body, true );
		if ( ! is_array( $payload ) || empty( $payload['content'] ) ) {
			// Directory listing returns array of files without single content.
			if ( is_array( $payload ) && isset( $payload[0] ) ) {
				return new WP_Error(
					'promptweb_github_is_directory',
					__( 'Path is a directory, not a file.', 'promptweb' )
				);
			}
			return new WP_Error(
				'promptweb_github_empty_content',
				__( 'GitHub returned empty file content.', 'promptweb' )
			);
		}

		$raw = $payload['content'];
		if ( isset( $payload['encoding'] ) && 'base64' === $payload['encoding'] ) {
			$raw = base64_decode( str_replace( array( "\n", "\r" ), '', $raw ), true );
			if ( false === $raw ) {
				return new WP_Error(
					'promptweb_github_decode',
					__( 'Could not decode remote file (base64).', 'promptweb' )
				);
			}
		}

		return array(
			'content' => (string) $raw,
			'sha'     => isset( $payload['sha'] ) ? (string) $payload['sha'] : '',
			'path'    => $path,
		);
	}

	/**
	 * List files in a remote directory (non-recursive Contents API).
	 *
	 * @since 2.0.0
	 * @param string    $dir         Directory path.
	 * @param bool|null $use_network Storage context.
	 * @return array|WP_Error List of { path, name, type, sha } entries.
	 */
	public function list_remote_directory( $dir, $use_network = null ) {
		if ( null === $use_network ) {
			$use_network = PromptWeb_Settings::use_network_options();
		}

		if ( ! $this->is_configured( $use_network ) ) {
			return new WP_Error( 'promptweb_not_configured', __( 'GitHub is not configured.', 'promptweb' ) );
		}

		$dir  = trim( str_replace( '\\', '/', (string) $dir ), '/' );
		$repo = $this->get_repo( $use_network );
		if ( false === strpos( $repo, '/' ) ) {
			return new WP_Error( 'promptweb_invalid_repo', __( 'Invalid repository.', 'promptweb' ) );
		}

		list( $owner, $name ) = array_pad( explode( '/', $repo, 2 ), 2, '' );
		$path_segments        = array_map( 'rawurlencode', explode( '/', $dir ) );
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
				'timeout' => 30,
				'headers' => $this->get_api_headers( $this->get_token( $use_network ) ),
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$body = wp_remote_retrieve_body( $response );

		if ( 404 === $code ) {
			return array(); // Empty directory is fine.
		}

		if ( $code < 200 || $code >= 300 ) {
			return new WP_Error(
				'promptweb_github_http',
				sprintf(
					/* translators: %d: status */
					__( 'Could not list remote directory (HTTP %d).', 'promptweb' ),
					$code
				)
			);
		}

		$payload = json_decode( $body, true );
		if ( ! is_array( $payload ) ) {
			return new WP_Error( 'promptweb_github_bad_response', __( 'Invalid directory listing response.', 'promptweb' ) );
		}

		// Single file response (not a directory).
		if ( isset( $payload['type'] ) && 'file' === $payload['type'] ) {
			return array(
				array(
					'path' => isset( $payload['path'] ) ? (string) $payload['path'] : $dir,
					'name' => isset( $payload['name'] ) ? (string) $payload['name'] : basename( $dir ),
					'type' => 'file',
					'sha'  => isset( $payload['sha'] ) ? (string) $payload['sha'] : '',
				),
			);
		}

		$items = array();
		foreach ( $payload as $entry ) {
			if ( ! is_array( $entry ) ) {
				continue;
			}
			$items[] = array(
				'path' => isset( $entry['path'] ) ? (string) $entry['path'] : '',
				'name' => isset( $entry['name'] ) ? (string) $entry['name'] : '',
				'type' => isset( $entry['type'] ) ? (string) $entry['type'] : '',
				'sha'  => isset( $entry['sha'] ) ? (string) $entry['sha'] : '',
			);
		}

		return $items;
	}

	/**
	 * Sync design pages (manifest + static/dynamic files) from GitHub.
	 *
	 * Does not delete GitHub connection settings or local blueprint options.
	 *
	 * @since 2.0.0
	 * @param array $args {
	 *     @type bool|null $use_network Storage context.
	 * }
	 * @return array{ success: bool, message: string, code?: string, data?: array }
	 */
	public function sync_design_pages( $args = array() ) {
		$args = wp_parse_args(
			$args,
			array(
				'use_network' => null,
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
				'message' => __( 'GitHub is not configured.', 'promptweb' ),
			);
		}

		if ( ! class_exists( 'PromptWeb_Pages' ) ) {
			return array(
				'success' => false,
				'code'    => 'promptweb_pages_missing',
				'message' => __( 'Pages component is not loaded.', 'promptweb' ),
			);
		}

		$pages_mgr = function_exists( 'promptweb' ) && isset( promptweb()->pages )
			? promptweb()->pages
			: new PromptWeb_Pages();

		$imported = 0;
		$errors   = array();

		// 1. Manifest (optional - repo may only have legacy blueprint).
		$manifest_fetch = $this->fetch_remote_file( 'pages/manifest.json', $use_network );
		if ( ! is_wp_error( $manifest_fetch ) ) {
			$import = $pages_mgr->import_remote_file( 'pages/manifest.json', $manifest_fetch['content'] );
			if ( is_wp_error( $import ) ) {
				$errors[] = $import->get_error_message();
			} else {
				$imported++;
				if ( ! empty( $manifest_fetch['sha'] ) ) {
					if ( $use_network ) {
						update_site_option( self::REMOTE_PAGES_SHA_OPTION, $manifest_fetch['sha'] );
					} else {
						update_option( self::REMOTE_PAGES_SHA_OPTION, $manifest_fetch['sha'], false );
					}
				}
			}
		}

		// 2. Static HTML files.
		$static_list = $this->list_remote_directory( 'pages/static', $use_network );
		if ( ! is_wp_error( $static_list ) && is_array( $static_list ) ) {
			foreach ( $static_list as $item ) {
				if ( empty( $item['type'] ) || 'file' !== $item['type'] ) {
					continue;
				}
				$path = isset( $item['path'] ) ? $item['path'] : '';
				if ( '' === $path || ! preg_match( '/\.html?$/i', $path ) ) {
					continue;
				}
				$file = $this->fetch_remote_file( $path, $use_network );
				if ( is_wp_error( $file ) ) {
					$errors[] = $file->get_error_message();
					continue;
				}
				$import = $pages_mgr->import_remote_file( $path, $file['content'] );
				if ( is_wp_error( $import ) ) {
					$errors[] = $import->get_error_message();
				} else {
					$imported++;
				}
			}
		}

		// 3. Dynamic PHP files.
		$dynamic_list = $this->list_remote_directory( 'pages/dynamic', $use_network );
		if ( ! is_wp_error( $dynamic_list ) && is_array( $dynamic_list ) ) {
			foreach ( $dynamic_list as $item ) {
				if ( empty( $item['type'] ) || 'file' !== $item['type'] ) {
					continue;
				}
				$path = isset( $item['path'] ) ? $item['path'] : '';
				if ( '' === $path || ! preg_match( '/\.php$/i', $path ) ) {
					continue;
				}
				$file = $this->fetch_remote_file( $path, $use_network );
				if ( is_wp_error( $file ) ) {
					$errors[] = $file->get_error_message();
					continue;
				}
				$import = $pages_mgr->import_remote_file( $path, $file['content'] );
				if ( is_wp_error( $import ) ) {
					$errors[] = $import->get_error_message();
				} else {
					$imported++;
				}
			}
		}

		// No pages folder at all is not a hard failure (legacy blueprint-only repos).
		if ( 0 === $imported && is_wp_error( $manifest_fetch ) ) {
			return array(
				'success' => true,
				'code'    => 'promptweb_pages_none',
				'message' => __( 'No design pages directory found on GitHub (legacy blueprint-only repo is fine).', 'promptweb' ),
				'data'    => array(
					'imported' => 0,
				),
			);
		}

		// Backfill public_url / site_url / url_format for existing pages missing them.
		// Does not delete pages; Multisite-aware via storage context.
		$pages_mgr->backfill_public_urls( $use_network );

		return array(
			'success' => true,
			'code'    => 'promptweb_pages_synced',
			'message' => sprintf(
				/* translators: %d: file count */
				__( 'Synced %d design page file(s) from GitHub.', 'promptweb' ),
				$imported
			),
			'data'    => array(
				'imported' => $imported,
				'errors'   => $errors,
			),
		);
	}

	/**
	 * Commit and push all local design pages + manifest to GitHub.
	 *
	 * Used by MCP commit_to_github tool.
	 *
	 * @since 2.0.0
	 * @param array $args {
	 *     @type bool|null $use_network Storage context.
	 *     @type string    $message     Commit message prefix.
	 * }
	 * @return array{ success: bool, message: string, code?: string, data?: array }
	 */
	public function commit_design_pages( $args = array() ) {
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

		if ( ! $this->is_configured( $use_network ) ) {
			return array(
				'success' => false,
				'code'    => 'promptweb_not_configured',
				'message' => __( 'GitHub is not configured. Add a token and repository in PromptWeb settings.', 'promptweb' ),
			);
		}

		if ( ! class_exists( 'PromptWeb_Pages' ) ) {
			return array(
				'success' => false,
				'code'    => 'promptweb_pages_missing',
				'message' => __( 'Pages component is not loaded.', 'promptweb' ),
			);
		}

		$pages_mgr = function_exists( 'promptweb' ) && isset( promptweb()->pages )
			? promptweb()->pages
			: new PromptWeb_Pages();

		$files = $pages_mgr->get_files_for_commit();
		if ( empty( $files ) ) {
			return array(
				'success' => false,
				'code'    => 'promptweb_nothing_to_commit',
				'message' => __( 'No design page files to commit.', 'promptweb' ),
			);
		}

		$base_msg = is_string( $args['message'] ) ? trim( $args['message'] ) : '';
		if ( '' === $base_msg ) {
			$base_msg = sprintf(
				/* translators: %s: site name */
				__( 'Update design pages via PromptWeb (%s)', 'promptweb' ),
				wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES )
			);
		}

		$pushed = array();
		$errors = array();

		foreach ( $files as $path => $contents ) {
			$result = $this->create_or_update_file(
				$contents,
				$base_msg . ' - ' . $path,
				$use_network,
				$path
			);
			if ( is_wp_error( $result ) ) {
				$errors[ $path ] = $result->get_error_message();
				continue;
			}
			$pushed[ $path ] = array(
				'commit_sha'  => isset( $result['commit_sha'] ) ? $result['commit_sha'] : '',
				'content_sha' => isset( $result['content_sha'] ) ? $result['content_sha'] : '',
				'created'     => ! empty( $result['created'] ),
			);
		}

		// Do not overwrite AI_INSTRUCTIONS.md / README.md here — those belong to the
		// design repository (agent design rules must not be forced from plugin PHP).

		if ( empty( $pushed ) ) {
			return array(
				'success' => false,
				'code'    => 'promptweb_commit_failed',
				'message' => ! empty( $errors )
					? implode( ' ', array_slice( array_values( $errors ), 0, 3 ) )
					: __( 'Could not push design pages to GitHub.', 'promptweb' ),
				'data'    => array( 'errors' => $errors ),
			);
		}

		PromptWeb_Settings::update_last_synced( null, $use_network );

		/**
		 * Fires after design pages are committed to GitHub.
		 *
		 * @since 2.0.0
		 * @param array $pushed      Paths pushed.
		 * @param bool  $use_network Network context.
		 * @param array $errors      Per-path errors.
		 */
		do_action( 'promptweb_design_pages_committed', $pushed, $use_network, $errors );

		return array(
			'success' => true,
			'code'    => 'promptweb_commit_success',
			'message' => sprintf(
				/* translators: 1: file count, 2: repo */
				__( 'Committed %1$d file(s) to %2$s.', 'promptweb' ),
				count( $pushed ),
				$this->get_repo( $use_network )
			),
			'data'    => array(
				'repo'   => $this->get_repo( $use_network ),
				'branch' => $this->get_branch( $use_network ),
				'pushed' => $pushed,
				'errors' => $errors,
			),
		);
	}

	/**
	 * Minimal design-repo README (technical bootstrap only — no AI design doctrine).
	 *
	 * Full agent design rules live in the design repository itself, not in plugin PHP.
	 * Written only when Initialize creates a missing README.md.
	 *
	 * @since 2.0.0
	 * @return string
	 */
	public function get_design_repo_readme_markdown() {
		$live_url = home_url( '/' );
		$site     = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
		if ( '' === $site ) {
			$site = 'PromptWeb site';
		}
		$repo   = $this->get_repo();
		$branch = $this->get_branch();

		$md  = "# PromptWeb design repository\n\n";
		$md .= "Design source of truth for **{$site}**.\n\n";
		$md .= "- Live site: {$live_url}\n";
		$md .= '- Repository: `' . ( $repo ? $repo : 'owner/repo' ) . "`\n";
		$md .= '- Branch: `' . ( $branch ? $branch : 'main' ) . "`\n\n";
		$md .= "## Structure\n\n";
		$md .= "```text\n";
		$md .= "pages/manifest.json\n";
		$md .= "pages/static/*.html\n";
		$md .= "pages/dynamic/*.php\n";
		$md .= "AI_INSTRUCTIONS.md\n";
		$md .= "README.md\n";
		$md .= "```\n\n";
		$md .= "Public URLs: home → site root; other pages → `/{slug}/`.\n";
		$md .= "Plugin code is separate (`Akashmali6198/promptweb`) and never deletes this design data.\n";

		/**
		 * Filters design-repo README body (keep technical; do not inject design doctrine into the plugin).
		 *
		 * @since 2.0.0
		 * @param string $md Markdown.
		 */
		return (string) apply_filters( 'promptweb_design_repo_readme', $md );
	}

	/**
	 * Minimal AI_INSTRUCTIONS.md bootstrap (technical only).
	 *
	 * Website design rules (Reference Mode, Research Mode, visual doctrine, etc.)
	 * must live in the design repository — not embedded in this plugin PHP.
	 * Written only when Initialize creates a missing AI_INSTRUCTIONS.md.
	 *
	 * @since 1.0.0
	 * @return string
	 */
	public function get_ai_instructions_markdown() {
		$live_url  = home_url( '/' );
		$live_trim = untrailingslashit( $live_url );
		$site      = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
		$repo      = $this->get_repo();
		$branch    = $this->get_branch();

		$md  = "# PromptWeb — technical bootstrap\n\n";
		$md .= "This file is a **minimal plugin-generated bootstrap**. Maintain full agent design guidance in this design repository as needed.\n\n";
		$md .= "| | |\n|---|---|\n";
		$md .= "| Live site | {$live_url} |\n";
		$md .= '| Design repo | `' . ( $repo ? $repo : 'owner/repo' ) . "` |\n";
		$md .= '| Branch | `' . ( $branch ? $branch : 'main' ) . "` |\n";
		$md .= '| Site name | ' . ( $site ? $site : 'PromptWeb site' ) . " |\n\n";
		$md .= "## Paths\n\n";
		$md .= "- `pages/static/{slug}.html` — static HTML\n";
		$md .= "- `pages/dynamic/{slug}.php` — dynamic PHP templates\n";
		$md .= "- `pages/manifest.json` — page catalog (`public_url`, status, type)\n\n";
		$md .= "## Public URLs\n\n";
		$md .= "- Home: `{$live_url}`\n";
		$md .= "- Other pages: `{$live_trim}/{slug}/`\n\n";
		$md .= "## MCP / REST (site admin)\n\n";
		$md .= "Tools: `list_pages`, `get_page`, `create_page`, `update_page`, `publish_page`, `get_visual_analysis`, `commit_to_github`.\n";
		$md .= "REST: `/wp-json/promptweb/v1/mcp/*` (requires `manage_options`). Responses include `public_url`.\n\n";
		$md .= "New pages are created as Draft. Plugin updates never delete design data.\n";

		/**
		 * Filters bootstrap AI_INSTRUCTIONS body. Prefer maintaining full design rules in the design repo.
		 *
		 * @since 1.0.0
		 * @param string $md Markdown contents.
		 */
		return (string) apply_filters( 'promptweb_ai_instructions_markdown', $md );
	}

	/**
	 * Initialize an AI-ready repository for Architecture v2.
	 *
	 * Writes / updates (via create_or_update_file; never deletes other design files):
	 *
	 * 1. pages/manifest.json       - catalog (home as front page, status publish)
	 * 2. pages/static/home.html    - starter homepage HTML
	 * 3. pages/dynamic/.gitkeep    - keeps dynamic folder in Git
	 * 4. AI_INSTRUCTIONS.md        - minimal technical bootstrap only if missing
	 * 5. README.md                 - minimal technical bootstrap only if missing
	 * 6. blueprints/latest.json    - only created if missing (legacy compatibility)
	 *
	 * Design-agent doctrine lives in the design repo, not in this plugin.
	 * Existing design pages and blueprints are preserved. Multisite-aware.
	 *
	 * @since 1.0.0
	 * @param array $args {
	 *     Optional.
	 *
	 *     @type bool|null $use_network Storage context.
	 *     @type bool      $force       When true, refresh starter home even if present.
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
		$force       = ! empty( $args['force'] );

		if ( ! $this->is_configured( $use_network ) ) {
			return array(
				'success' => false,
				'code'    => 'promptweb_not_configured',
				'message' => __( 'GitHub is not configured. Save a Personal Access Token and repository first.', 'promptweb' ),
			);
		}

		if ( ! class_exists( 'PromptWeb_Pages' ) ) {
			return array(
				'success' => false,
				'code'    => 'promptweb_pages_missing',
				'message' => __( 'Pages component is not loaded. Cannot initialize Architecture v2 structure.', 'promptweb' ),
			);
		}

		$blueprint_path    = $this->get_blueprint_path( $use_network );
		if ( '' === $blueprint_path ) {
			$blueprint_path = 'blueprints/latest.json';
		}
		$instructions_path = 'AI_INSTRUCTIONS.md';
		$home_path         = 'pages/static/home.html';
		$manifest_path     = 'pages/manifest.json';
		$dynamic_keep      = 'pages/dynamic/.gitkeep';

		$repo   = $this->get_repo( $use_network );
		$branch = $this->get_branch( $use_network );
		$site   = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );

		$pages_mgr = function_exists( 'promptweb' ) && isset( promptweb()->pages ) && promptweb()->pages instanceof PromptWeb_Pages
			? promptweb()->pages
			: new PromptWeb_Pages();

		$pages_mgr->ensure_storage();
		$bundle = $pages_mgr->get_init_starter_bundle( $site );

		$written = array(); // path => 'created'|'updated'|'skipped'|'failed'
		$errors  = array();

		// ---------------------------------------------------------------------
		// 1-3. Architecture v2 pages structure
		// ---------------------------------------------------------------------

		// --- pages/static/home.html ---
		$home_exists = $this->remote_file_exists( $home_path, $use_network );
		if ( is_wp_error( $home_exists ) ) {
			return array(
				'success' => false,
				'code'    => $home_exists->get_error_code(),
				'message' => $home_exists->get_error_message(),
			);
		}

		$home_html = isset( $bundle['files'][ $home_path ] ) ? $bundle['files'][ $home_path ] : $pages_mgr->get_init_home_html( $site );
		$write_home = $force || true !== $home_exists;

		if ( $write_home ) {
			$home_result = $this->create_or_update_file(
				$home_html,
				__( 'Initialize PromptWeb pages/static/home.html (v2 starter homepage)', 'promptweb' ),
				$use_network,
				$home_path
			);
			if ( is_wp_error( $home_result ) ) {
				return array(
					'success' => false,
					'code'    => $home_result->get_error_code(),
					'message' => sprintf(
						/* translators: %s: error */
						__( 'Failed to write pages/static/home.html: %s', 'promptweb' ),
						$home_result->get_error_message()
					),
				);
			}
			$written[ $home_path ] = ! empty( $home_result['created'] ) ? 'created' : 'updated';
			$pages_mgr->write_page_file( $home_path, $home_html );
		} else {
			// Keep existing custom home; still ensure local cache has something.
			$remote_home = $this->fetch_remote_file( $home_path, $use_network );
			if ( ! is_wp_error( $remote_home ) && isset( $remote_home['content'] ) ) {
				$pages_mgr->write_page_file( $home_path, $remote_home['content'] );
			}
			$written[ $home_path ] = 'skipped';
		}

		// --- pages/manifest.json (merge - never drop other pages) ---
		$starter_home_meta = array();
		if ( ! empty( $bundle['manifest']['pages'][0] ) && is_array( $bundle['manifest']['pages'][0] ) ) {
			$starter_home_meta = $bundle['manifest']['pages'][0];
		} else {
			$starter_home_meta = array(
				'slug'          => 'home',
				'title'         => 'Home',
				'type'          => 'static',
				'status'        => 'publish',
				'file'          => $home_path,
				'is_front_page' => true,
				'updated_at'    => gmdate( 'c' ),
			);
		}

		$remote_manifest_raw = $this->fetch_remote_file( $manifest_path, $use_network );
		$merged_manifest     = $bundle['manifest'];

		if ( ! is_wp_error( $remote_manifest_raw ) && ! empty( $remote_manifest_raw['content'] ) ) {
			$decoded = json_decode( $remote_manifest_raw['content'], true );
			if ( is_array( $decoded ) ) {
				// Preserve existing pages; ensure home entry exists.
				$merged_manifest = $pages_mgr->merge_init_manifest( $decoded, $starter_home_meta );
			}
		}

		// Also merge with local manifest if richer (Multisite: current blog context).
		$local_manifest = $pages_mgr->get_manifest( $use_network );
		if ( ! empty( $local_manifest['pages'] ) ) {
			foreach ( $local_manifest['pages'] as $local_page ) {
				if ( ! is_array( $local_page ) || empty( $local_page['slug'] ) ) {
					continue;
				}
				$slug = sanitize_title( (string) $local_page['slug'] );
				$exists_in_merged = false;
				foreach ( $merged_manifest['pages'] as $mp ) {
					if ( is_array( $mp ) && ( $mp['slug'] ?? '' ) === $slug ) {
						$exists_in_merged = true;
						break;
					}
				}
				if ( ! $exists_in_merged ) {
					$merged_manifest['pages'][] = $local_page;
				}
			}
			$merged_manifest = $pages_mgr->normalize_manifest( $merged_manifest );
		}

		$manifest_json = wp_json_encode( $merged_manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		if ( ! is_string( $manifest_json ) || '' === $manifest_json ) {
			return array(
				'success' => false,
				'code'    => 'promptweb_json_encode_failed',
				'message' => __( 'Could not encode pages/manifest.json.', 'promptweb' ),
			);
		}
		if ( "\n" !== substr( $manifest_json, -1 ) ) {
			$manifest_json .= "\n";
		}

		$mf_result = $this->create_or_update_file(
			$manifest_json,
			__( 'Initialize PromptWeb pages/manifest.json (v2 page catalog)', 'promptweb' ),
			$use_network,
			$manifest_path
		);
		if ( is_wp_error( $mf_result ) ) {
			return array(
				'success' => false,
				'code'    => $mf_result->get_error_code(),
				'message' => sprintf(
					/* translators: %s: error */
					__( 'Failed to write pages/manifest.json: %s', 'promptweb' ),
					$mf_result->get_error_message()
				),
			);
		}
		$written[ $manifest_path ] = ! empty( $mf_result['created'] ) ? 'created' : 'updated';
		// Normalize + backfill public_url on every page; never wipes custom pages.
		$pages_mgr->save_manifest( $merged_manifest, $use_network );
		$pages_mgr->backfill_public_urls( $use_network );

		// --- pages/dynamic/.gitkeep ---
		$dyn_exists = $this->remote_file_exists( $dynamic_keep, $use_network );
		if ( true !== $dyn_exists ) {
			$gitkeep = isset( $bundle['files'][ $dynamic_keep ] )
				? $bundle['files'][ $dynamic_keep ]
				: "# PromptWeb dynamic pages (PHP + WordPress)\n";
			$dyn_result = $this->create_or_update_file(
				$gitkeep,
				__( 'Initialize PromptWeb pages/dynamic/ folder', 'promptweb' ),
				$use_network,
				$dynamic_keep
			);
			if ( is_wp_error( $dyn_result ) ) {
				// Non-fatal: static pages still work without dynamic folder marker.
				$errors[ $dynamic_keep ] = $dyn_result->get_error_message();
				$written[ $dynamic_keep ] = 'failed';
			} else {
				$written[ $dynamic_keep ] = ! empty( $dyn_result['created'] ) ? 'created' : 'updated';
			}
		} else {
			$written[ $dynamic_keep ] = 'skipped';
		}

		// ---------------------------------------------------------------------
		// 4. AI_INSTRUCTIONS.md — create only if missing (do not overwrite design-repo rules)
		// ---------------------------------------------------------------------
		$ai_exists = $this->remote_file_exists( $instructions_path, $use_network );
		if ( true === $ai_exists ) {
			$written[ $instructions_path ] = 'skipped';
		} else {
			$instructions = $this->get_ai_instructions_markdown();
			if ( "\n" !== substr( $instructions, -1 ) ) {
				$instructions .= "\n";
			}
			$ai_result = $this->create_or_update_file(
				$instructions,
				__( 'Initialize PromptWeb AI_INSTRUCTIONS.md (technical bootstrap)', 'promptweb' ),
				$use_network,
				$instructions_path
			);
			if ( is_wp_error( $ai_result ) ) {
				// Soft-fail: pages already written; design repo can add its own guide.
				$errors[ $instructions_path ]  = $ai_result->get_error_message();
				$written[ $instructions_path ] = 'failed';
			} else {
				$written[ $instructions_path ] = ! empty( $ai_result['created'] ) ? 'created' : 'updated';
			}
		}

		// ---------------------------------------------------------------------
		// 5. README.md — create only if missing (do not overwrite design-repo docs)
		// ---------------------------------------------------------------------
		$rm_exists = $this->remote_file_exists( 'README.md', $use_network );
		if ( true === $rm_exists ) {
			$written['README.md'] = 'skipped';
		} else {
			$readme = $this->get_design_repo_readme_markdown();
			if ( "\n" !== substr( $readme, -1 ) ) {
				$readme .= "\n";
			}
			$rm_result = $this->create_or_update_file(
				$readme,
				__( 'Initialize PromptWeb design repository README.md', 'promptweb' ),
				$use_network,
				'README.md'
			);
			if ( is_wp_error( $rm_result ) ) {
				$errors['README.md']  = $rm_result->get_error_message();
				$written['README.md'] = 'failed';
			} else {
				$written['README.md'] = ! empty( $rm_result['created'] ) ? 'created' : 'updated';
			}
		}

		// ---------------------------------------------------------------------
		// 6. Legacy blueprints/latest.json - only if missing (never overwrite)
		// ---------------------------------------------------------------------
		$bp_status = 'skipped';
		$bp_exists = $this->remote_file_exists( $blueprint_path, $use_network );
		if ( is_wp_error( $bp_exists ) ) {
			// Soft-fail: v2 pages already written.
			$errors[ $blueprint_path ] = $bp_exists->get_error_message();
			$bp_status                 = 'failed';
		} elseif ( true !== $bp_exists ) {
			$starter = class_exists( 'PromptWeb_Schema' )
				? PromptWeb_Schema::get_starter_blueprint()
				: array(
					'version' => '1.0',
					'site'    => array(
						'title'   => $site ? $site : 'My PromptWeb Site',
						'tagline' => '',
					),
					'pages'   => array(),
					'prompts' => array(),
				);

			if ( class_exists( 'PromptWeb_Schema' ) ) {
				$valid = PromptWeb_Schema::validate( $starter );
				if ( ! is_wp_error( $valid ) ) {
					$starter = PromptWeb_Schema::normalize( $starter );
				}
			}

			$json = wp_json_encode( $starter, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
			if ( is_string( $json ) && '' !== $json ) {
				if ( "\n" !== substr( $json, -1 ) ) {
					$json .= "\n";
				}
				$bp_result = $this->create_or_update_file(
					$json,
					sprintf(
						/* translators: %s: path */
						__( 'Initialize legacy compatibility blueprint (%s)', 'promptweb' ),
						$blueprint_path
					),
					$use_network,
					$blueprint_path
				);
				if ( is_wp_error( $bp_result ) ) {
					$errors[ $blueprint_path ] = $bp_result->get_error_message();
					$bp_status                 = 'failed';
				} else {
					$bp_status = ! empty( $bp_result['created'] ) ? 'created' : 'updated';
					// Only seed local blueprint option if empty (do not wipe existing).
					$existing_bp = PromptWeb_Settings::get_blueprint( $use_network );
					if ( empty( $existing_bp ) || empty( $existing_bp['pages'] ) ) {
						PromptWeb_Settings::save_blueprint( $starter, $use_network );
					}
				}
			}
		} else {
			// Existing blueprint preserved - never overwrite design data.
			$bp_status = 'skipped';
		}
		$written[ $blueprint_path ] = $bp_status;

		PromptWeb_Settings::update_last_synced( null, $use_network );

		// Enable frontend rendering for this site/network context.
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
		 * Fires after a successful AI-ready repository initialization (v2).
		 *
		 * @since 1.0.0
		 * @param array $merged_manifest Final pages manifest.
		 * @param bool  $use_network     Network context.
		 * @param array $meta            Paths / write status.
		 */
		do_action(
			'promptweb_repository_initialized',
			$merged_manifest,
			$use_network,
			array(
				'repo'              => $repo,
				'branch'            => $branch,
				'blueprint_path'    => $blueprint_path,
				'instructions_path' => $instructions_path,
				'written'           => $written,
				'errors'            => $errors,
			)
		);

		// Human-readable summary of files Initialize writes.
		$file_lines = array(
			$manifest_path . ' (' . $written[ $manifest_path ] . ')',
			$home_path . ' (' . $written[ $home_path ] . ')',
			$dynamic_keep . ' (' . ( isset( $written[ $dynamic_keep ] ) ? $written[ $dynamic_keep ] : 'n/a' ) . ')',
			$instructions_path . ' (' . $written[ $instructions_path ] . ')',
			'README.md (' . ( isset( $written['README.md'] ) ? $written['README.md'] : 'n/a' ) . ')',
			$blueprint_path . ' (' . $bp_status . ')',
		);

		$message = sprintf(
			/* translators: 1: repo, 2: branch, 3: file list */
			__( 'Repository initialized for Architecture v2 on %1$s @ %2$s. Files: %3$s. Existing design pages and blueprints were not deleted.', 'promptweb' ),
			$repo,
			$branch,
			implode( '; ', $file_lines )
		);

		return array(
			'success' => true,
			'code'    => 'promptweb_init_success',
			'message' => $message,
			'data'    => array(
				'repo'              => $repo,
				'branch'            => $branch,
				'blueprint_path'    => $blueprint_path,
				'instructions_path' => $instructions_path,
				'manifest_path'     => $manifest_path,
				'home_path'         => $home_path,
				'written'           => $written,
				'errors'            => $errors,
				'files_written'     => array(
					'pages/manifest.json'       => __( 'Page catalog (home as front page, status publish)', 'promptweb' ),
					'pages/static/home.html'    => __( 'Beautiful Tailwind CDN starter homepage (front page)', 'promptweb' ),
					'pages/dynamic/.gitkeep'    => __( 'Dynamic pages folder placeholder', 'promptweb' ),
					'AI_INSTRUCTIONS.md'        => __( 'Technical bootstrap only if missing (design rules stay in the design repo)', 'promptweb' ),
					'README.md'                 => __( 'Technical bootstrap only if missing', 'promptweb' ),
					$blueprint_path             => __( 'Legacy JSON blueprint (created only if missing)', 'promptweb' ),
				),
				'manifest'          => $merged_manifest,
			),
		);
	}

	/**
	 * Main sync: configure check â†’ fetch â†’ validate JSON â†’ update last_synced.
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

		$result    = $this->fetch_blueprint(
			array(
				'use_network' => $use_network,
			)
		);
		$blueprint = null;
		$convert   = null;
		$bp_ok     = false;

		/*
		 * Path A - legacy / optional JSON blueprint.
		 * Missing blueprint is OK when v2 pages/ exist.
		 */
		if ( ! is_wp_error( $result ) ) {
			$blueprint = isset( $result['data'] ) ? $result['data'] : null;

			if ( is_array( $blueprint ) ) {
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

				// Persist blueprint JSON (never wipe connection settings).
				PromptWeb_Settings::save_blueprint( $blueprint, $use_network );
				$bp_ok = true;

				/**
				 * Filters whether Sync should still run the deprecated Gutenberg converter.
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

				if ( ! empty( $result['sha'] ) ) {
					$this->store_remote_sha( (string) $result['sha'], $use_network );
				}
			}
		}

		// Path B - v2 design pages (static HTML + dynamic PHP).
		$pages_sync = $this->sync_design_pages(
			array(
				'use_network' => $use_network,
			)
		);
		$pages_ok = is_array( $pages_sync ) && ! empty( $pages_sync['success'] )
			&& ( empty( $pages_sync['code'] ) || 'promptweb_pages_none' !== $pages_sync['code'] || ! empty( $pages_sync['data']['imported'] ) );

		// Consider pages present if imported > 0 or local pages already exist after sync.
		$has_local_pages = false;
		if ( class_exists( 'PromptWeb_Pages' ) ) {
			$pm = function_exists( 'promptweb' ) && isset( promptweb()->pages ) ? promptweb()->pages : new PromptWeb_Pages();
			$has_local_pages = $pm->has_pages();
		}

		if ( ! $bp_ok && ! $has_local_pages && ( is_wp_error( $result ) || ! $pages_ok ) ) {
			// Nothing usable.
			if ( is_wp_error( $result ) ) {
				return array(
					'success' => false,
					'code'    => $result->get_error_code(),
					'message' => $result->get_error_message() . ' '
						. __( 'Also no design pages found. Initialize the AI-ready repository or add pages/static + pages/manifest.json.', 'promptweb' ),
				);
			}
			return array(
				'success' => false,
				'code'    => 'promptweb_sync_empty',
				'message' => __( 'Sync found no blueprint JSON and no design pages to import.', 'promptweb' ),
			);
		}

		PromptWeb_Settings::update_last_synced( null, $use_network );

		/**
		 * Fires after a successful GitHub sync (blueprint and/or design pages).
		 *
		 * @since 1.0.0
		 * @param array|WP_Error $result      Fetch payload or error if blueprint missing.
		 * @param bool           $use_network Whether network options were used.
		 * @param array|null     $convert     Legacy converter result, or null when not used.
		 * @param array|null     $blueprint   Normalized stored blueprint.
		 */
		do_action( 'promptweb_github_synced', $result, $use_network, $convert, $blueprint );

		$parts = array();
		if ( $bp_ok && is_array( $result ) ) {
			$parts[] = sprintf(
				/* translators: 1: repository, 2: path */
				__( 'Blueprint synced from %1$s (%2$s).', 'promptweb' ),
				isset( $result['repo'] ) ? $result['repo'] : $this->get_repo( $use_network ),
				isset( $result['path'] ) ? $result['path'] : $this->get_blueprint_path( $use_network )
			);
		}
		if ( is_array( $pages_sync ) && ! empty( $pages_sync['message'] ) ) {
			$parts[] = $pages_sync['message'];
		}
		if ( is_array( $convert ) && ! empty( $convert['message'] ) ) {
			$parts[] = $convert['message'];
		}

		$sync_message = ! empty( $parts )
			? implode( ' ', $parts )
			: __( 'Sync completed.', 'promptweb' );

		return array(
			'success' => true,
			'code'    => 'promptweb_sync_success',
			'message' => $sync_message,
			'data'    => array(
				'fetch'      => is_wp_error( $result ) ? null : $result,
				'blueprint'  => $blueprint,
				'convert'    => $convert,
				'pages_sync' => $pages_sync,
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
