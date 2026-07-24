<?php
/**
 * Design pages registry: static HTML + dynamic PHP.
 *
 * Architecture (v2 — full creative freedom):
 * - Static pages  → pages/static/{slug}.html  (full HTML + Tailwind CDN + JS)
 * - Dynamic pages → pages/dynamic/{slug}.php  (WordPress-aware PHP templates)
 * - Manifest      → pages/manifest.json       (slug, type, status, title, …)
 *
 * Local copies live under uploads/promptweb/ so plugin updates never wipe design data.
 * GitHub remains the remote source of truth; Sync pulls, commit_to_github pushes.
 *
 * Legacy JSON blueprints (blueprints/latest.json) still work when no v2 pages exist.
 *
 * @package PromptWeb
 * @since   2.0.0
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Page storage, listing, CRUD, and visual analysis helpers.
 *
 * @since 2.0.0
 */
class PromptWeb_Pages {

	/**
	 * Option key for the pages manifest (Multisite-aware).
	 *
	 * @since 2.0.0
	 * @var   string
	 */
	const MANIFEST_OPTION = 'promptweb_pages_manifest';

	/**
	 * Relative path of the remote/local manifest file.
	 *
	 * @since 2.0.0
	 * @var   string
	 */
	const MANIFEST_PATH = 'pages/manifest.json';

	/**
	 * Static pages directory (repo-relative).
	 *
	 * @since 2.0.0
	 * @var   string
	 */
	const STATIC_DIR = 'pages/static';

	/**
	 * Dynamic pages directory (repo-relative).
	 *
	 * @since 2.0.0
	 * @var   string
	 */
	const DYNAMIC_DIR = 'pages/dynamic';

	/**
	 * Allowed page types.
	 *
	 * @since 2.0.0
	 * @var   string[]
	 */
	const TYPES = array( 'static', 'dynamic' );

	/**
	 * Allowed statuses.
	 *
	 * @since 2.0.0
	 * @var   string[]
	 */
	const STATUSES = array( 'draft', 'publish' );

	/**
	 * Bootstrap (ensures local storage directories exist).
	 *
	 * @since 2.0.0
	 * @return void
	 */
	public function init() {
		$this->ensure_storage();
	}

	/**
	 * Absolute base directory for local design files (uploads/promptweb).
	 *
	 * Survives plugin updates. Multisite: per-site uploads path.
	 *
	 * @since 2.0.0
	 * @return string
	 */
	public function get_storage_base() {
		$upload = wp_upload_dir();
		$base   = trailingslashit( $upload['basedir'] ) . 'promptweb';

		/**
		 * Filters the local design storage base directory.
		 *
		 * @since 2.0.0
		 * @param string $base Absolute path without trailing slash preference.
		 */
		return untrailingslashit( (string) apply_filters( 'promptweb_pages_storage_base', $base ) );
	}

	/**
	 * Ensure pages/static and pages/dynamic exist under storage base.
	 *
	 * @since 2.0.0
	 * @return bool
	 */
	public function ensure_storage() {
		$base = $this->get_storage_base();
		$dirs = array(
			$base,
			$base . '/pages',
			$base . '/' . self::STATIC_DIR,
			$base . '/' . self::DYNAMIC_DIR,
		);

		$ok = true;
		foreach ( $dirs as $dir ) {
			if ( ! is_dir( $dir ) ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir
				if ( ! wp_mkdir_p( $dir ) ) {
					$ok = false;
				}
			}
		}

		// Protect from direct web listing when possible.
		$index = $base . '/index.php';
		if ( ! file_exists( $index ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			@file_put_contents( $index, "<?php\n// Silence is golden.\n" );
		}

		return $ok;
	}

	/**
	 * Absolute local path for a repo-relative file path.
	 *
	 * @since 2.0.0
	 * @param string $relative Repo-relative path (e.g. pages/static/home.html).
	 * @return string
	 */
	public function local_path( $relative ) {
		$relative = ltrim( str_replace( '\\', '/', (string) $relative ), '/' );
		return $this->get_storage_base() . '/' . $relative;
	}

	/**
	 * Whether any v2 design pages are registered.
	 *
	 * @since 2.0.0
	 * @return bool
	 */
	public function has_pages() {
		$manifest = $this->get_manifest();
		return ! empty( $manifest['pages'] ) && is_array( $manifest['pages'] );
	}

	/**
	 * Get the pages manifest (from option, falling back to local file).
	 *
	 * @since 2.0.0
	 * @param bool|null $network Force network storage; null = auto.
	 * @return array{version?:string,pages:array}
	 */
	public function get_manifest( $network = null ) {
		if ( null === $network ) {
			$network = class_exists( 'PromptWeb_Settings' )
				? PromptWeb_Settings::use_network_options()
				: false;
		}

		if ( $network ) {
			$manifest = get_site_option( self::MANIFEST_OPTION, null );
		} else {
			$manifest = get_option( self::MANIFEST_OPTION, null );
		}

		if ( ! is_array( $manifest ) ) {
			// Fall back to local file if option empty.
			$path = $this->local_path( self::MANIFEST_PATH );
			if ( is_readable( $path ) ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
				$raw = file_get_contents( $path );
				$decoded = is_string( $raw ) ? json_decode( $raw, true ) : null;
				if ( is_array( $decoded ) ) {
					$manifest = $decoded;
				}
			}
		}

		if ( ! is_array( $manifest ) ) {
			$manifest = $this->empty_manifest();
		}

		if ( empty( $manifest['pages'] ) || ! is_array( $manifest['pages'] ) ) {
			$manifest['pages'] = array();
		}
		if ( empty( $manifest['version'] ) ) {
			$manifest['version'] = '2.0';
		}

		/**
		 * Filters the pages manifest.
		 *
		 * @since 2.0.0
		 * @param array $manifest Manifest data.
		 */
		return (array) apply_filters( 'promptweb_pages_manifest', $manifest );
	}

	/**
	 * Empty starter manifest.
	 *
	 * @since 2.0.0
	 * @return array
	 */
	public function empty_manifest() {
		return array(
			'version' => '2.0',
			'pages'   => array(),
		);
	}

	/**
	 * Persist manifest to option + local file.
	 *
	 * @since 2.0.0
	 * @param array     $manifest Manifest data.
	 * @param bool|null $network  Storage context.
	 * @return bool
	 */
	public function save_manifest( array $manifest, $network = null ) {
		if ( null === $network ) {
			$network = class_exists( 'PromptWeb_Settings' )
				? PromptWeb_Settings::use_network_options()
				: false;
		}

		$manifest = $this->normalize_manifest( $manifest );

		if ( $network ) {
			update_site_option( self::MANIFEST_OPTION, $manifest );
		} else {
			update_option( self::MANIFEST_OPTION, $manifest, false );
		}

		$this->ensure_storage();
		$json = wp_json_encode( $manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		if ( ! is_string( $json ) ) {
			return false;
		}
		if ( "\n" !== substr( $json, -1 ) ) {
			$json .= "\n";
		}

		$path = $this->local_path( self::MANIFEST_PATH );
		$dir  = dirname( $path );
		if ( ! is_dir( $dir ) ) {
			wp_mkdir_p( $dir );
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		$written = file_put_contents( $path, $json );

		/**
		 * Fires after the pages manifest is saved.
		 *
		 * @since 2.0.0
		 * @param array $manifest Manifest.
		 * @param bool  $network  Network context.
		 */
		do_action( 'promptweb_pages_manifest_saved', $manifest, (bool) $network );

		return false !== $written;
	}

	/**
	 * Normalize manifest structure and page entries.
	 *
	 * @since 2.0.0
	 * @param array $manifest Raw manifest.
	 * @return array
	 */
	public function normalize_manifest( array $manifest ) {
		$out = array(
			'version' => isset( $manifest['version'] ) ? (string) $manifest['version'] : '2.0',
			'pages'   => array(),
		);

		$pages = isset( $manifest['pages'] ) && is_array( $manifest['pages'] ) ? $manifest['pages'] : array();
		foreach ( $pages as $page ) {
			if ( ! is_array( $page ) ) {
				continue;
			}
			$normalized = $this->normalize_page_meta( $page );
			if ( $normalized ) {
				$out['pages'][] = $normalized;
			}
		}

		return $out;
	}

	/**
	 * Normalize a single page meta entry.
	 *
	 * @since 2.0.0
	 * @param array $page Page meta.
	 * @return array|null
	 */
	public function normalize_page_meta( array $page ) {
		$slug = isset( $page['slug'] ) ? sanitize_title( (string) $page['slug'] ) : '';
		if ( '' === $slug ) {
			return null;
		}

		$type = isset( $page['type'] ) ? strtolower( (string) $page['type'] ) : 'static';
		if ( ! in_array( $type, self::TYPES, true ) ) {
			$type = 'static';
		}

		$status = isset( $page['status'] ) ? strtolower( (string) $page['status'] ) : 'draft';
		if ( ! in_array( $status, self::STATUSES, true ) ) {
			$status = 'draft';
		}

		$file = isset( $page['file'] ) ? ltrim( str_replace( '\\', '/', (string) $page['file'] ), '/' ) : '';
		if ( '' === $file ) {
			$file = ( 'dynamic' === $type )
				? self::DYNAMIC_DIR . '/' . $slug . '.php'
				: self::STATIC_DIR . '/' . $slug . '.html';
		}

		$title = isset( $page['title'] ) ? sanitize_text_field( (string) $page['title'] ) : $slug;
		if ( '' === $title ) {
			$title = $slug;
		}

		return array(
			'slug'          => $slug,
			'title'         => $title,
			'type'          => $type,
			'status'        => $status,
			'file'          => $file,
			'is_front_page' => ! empty( $page['is_front_page'] ),
			'updated_at'    => isset( $page['updated_at'] ) ? sanitize_text_field( (string) $page['updated_at'] ) : '',
			'instructions'  => isset( $page['instructions'] ) ? (string) $page['instructions'] : '',
		);
	}

	/**
	 * List all pages with status (for MCP list_pages).
	 *
	 * @since 2.0.0
	 * @param array $args {
	 *     Optional.
	 *     @type string $status Filter by status (draft|publish|all). Default all.
	 *     @type string $type   Filter by type (static|dynamic|all). Default all.
	 * }
	 * @return array{pages:array,count:int}
	 */
	public function list_pages( $args = array() ) {
		$args = wp_parse_args(
			$args,
			array(
				'status' => 'all',
				'type'   => 'all',
			)
		);

		$manifest = $this->get_manifest();
		$pages    = array();

		foreach ( $manifest['pages'] as $page ) {
			if ( ! is_array( $page ) ) {
				continue;
			}
			if ( 'all' !== $args['status'] && ( $page['status'] ?? '' ) !== $args['status'] ) {
				continue;
			}
			if ( 'all' !== $args['type'] && ( $page['type'] ?? '' ) !== $args['type'] ) {
				continue;
			}

			$pages[] = array(
				'slug'          => $page['slug'],
				'title'         => $page['title'],
				'type'          => $page['type'],
				'status'        => $page['status'],
				'file'          => $page['file'],
				'is_front_page' => ! empty( $page['is_front_page'] ),
				'updated_at'    => isset( $page['updated_at'] ) ? $page['updated_at'] : '',
				'url'           => $this->get_public_url( $page['slug'], $page ),
			);
		}

		return array(
			'pages' => $pages,
			'count' => count( $pages ),
		);
	}

	/**
	 * Find page meta by slug.
	 *
	 * @since 2.0.0
	 * @param string $slug Page slug.
	 * @return array|null
	 */
	public function get_page_meta( $slug ) {
		$slug     = sanitize_title( $slug );
		$manifest = $this->get_manifest();

		foreach ( $manifest['pages'] as $page ) {
			if ( is_array( $page ) && isset( $page['slug'] ) && $page['slug'] === $slug ) {
				return $this->normalize_page_meta( $page );
			}
		}

		return null;
	}

	/**
	 * Get page code + meta (for MCP get_page).
	 *
	 * @since 2.0.0
	 * @param string $slug Page slug.
	 * @return array|WP_Error
	 */
	public function get_page( $slug ) {
		$meta = $this->get_page_meta( $slug );
		if ( ! $meta ) {
			return new WP_Error(
				'promptweb_page_not_found',
				sprintf(
					/* translators: %s: slug */
					__( 'Page “%s” was not found.', 'promptweb' ),
					sanitize_title( $slug )
				)
			);
		}

		$code = $this->read_page_file( $meta['file'] );
		if ( is_wp_error( $code ) ) {
			// File missing — return empty code with meta so AI can recreate.
			$code = '';
		}

		return array(
			'slug'          => $meta['slug'],
			'title'         => $meta['title'],
			'type'          => $meta['type'],
			'status'        => $meta['status'],
			'file'          => $meta['file'],
			'is_front_page' => ! empty( $meta['is_front_page'] ),
			'updated_at'    => $meta['updated_at'],
			'instructions'  => isset( $meta['instructions'] ) ? $meta['instructions'] : '',
			'code'          => is_string( $code ) ? $code : '',
			'url'           => $this->get_public_url( $meta['slug'], $meta ),
		);
	}

	/**
	 * Read a page file from local storage.
	 *
	 * @since 2.0.0
	 * @param string $relative Repo-relative path.
	 * @return string|WP_Error
	 */
	public function read_page_file( $relative ) {
		$path = $this->local_path( $relative );
		if ( ! is_readable( $path ) ) {
			return new WP_Error(
				'promptweb_page_file_missing',
				sprintf(
					/* translators: %s: path */
					__( 'Page file not found: %s', 'promptweb' ),
					$relative
				)
			);
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$contents = file_get_contents( $path );
		if ( false === $contents ) {
			return new WP_Error(
				'promptweb_page_read_failed',
				__( 'Could not read the page file.', 'promptweb' )
			);
		}

		return (string) $contents;
	}

	/**
	 * Write a page file to local storage.
	 *
	 * @since 2.0.0
	 * @param string $relative Repo-relative path.
	 * @param string $contents File contents.
	 * @return true|WP_Error
	 */
	public function write_page_file( $relative, $contents ) {
		$this->ensure_storage();
		$path = $this->local_path( $relative );
		$dir  = dirname( $path );

		if ( ! is_dir( $dir ) ) {
			if ( ! wp_mkdir_p( $dir ) ) {
				return new WP_Error(
					'promptweb_page_mkdir_failed',
					__( 'Could not create the pages storage directory.', 'promptweb' )
				);
			}
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		$written = file_put_contents( $path, (string) $contents );
		if ( false === $written ) {
			return new WP_Error(
				'promptweb_page_write_failed',
				__( 'Could not write the page file to local storage.', 'promptweb' )
			);
		}

		return true;
	}

	/**
	 * Create a new page (Draft by default).
	 *
	 * @since 2.0.0
	 * @param array $args {
	 *     @type string $slug          Required. Page slug.
	 *     @type string $type          static|dynamic. Default static.
	 *     @type string $title         Display title.
	 *     @type string $status        draft|publish. Default draft.
	 *     @type string $code          Full HTML or PHP source.
	 *     @type string $instructions  Design instructions (stored as meta).
	 *     @type bool   $is_front_page Whether this is the front page.
	 * }
	 * @return array|WP_Error Created page payload.
	 */
	public function create_page( $args = array() ) {
		$args = wp_parse_args(
			$args,
			array(
				'slug'          => '',
				'type'          => 'static',
				'title'         => '',
				'status'        => 'draft',
				'code'          => '',
				'instructions'  => '',
				'is_front_page' => false,
			)
		);

		$slug = sanitize_title( (string) $args['slug'] );
		if ( '' === $slug ) {
			// Derive from title if provided.
			$slug = sanitize_title( (string) $args['title'] );
		}
		if ( '' === $slug ) {
			return new WP_Error(
				'promptweb_page_invalid_slug',
				__( 'A page name or slug is required.', 'promptweb' )
			);
		}

		if ( $this->get_page_meta( $slug ) ) {
			return new WP_Error(
				'promptweb_page_exists',
				sprintf(
					/* translators: %s: slug */
					__( 'A page with slug “%s” already exists. Use update_page instead.', 'promptweb' ),
					$slug
				)
			);
		}

		$type = strtolower( (string) $args['type'] );
		if ( ! in_array( $type, self::TYPES, true ) ) {
			$type = 'static';
		}

		// New pages MUST be Draft by default (AI agency safety).
		$status = strtolower( (string) $args['status'] );
		if ( ! in_array( $status, self::STATUSES, true ) ) {
			$status = 'draft';
		}
		// Force draft unless explicitly publish — default is always draft for create.
		if ( empty( $args['force_status'] ) ) {
			$status = 'draft';
		}

		$title = sanitize_text_field( (string) $args['title'] );
		if ( '' === $title ) {
			$title = ucwords( str_replace( array( '-', '_' ), ' ', $slug ) );
		}

		$file = ( 'dynamic' === $type )
			? self::DYNAMIC_DIR . '/' . $slug . '.php'
			: self::STATIC_DIR . '/' . $slug . '.html';

		$code = (string) $args['code'];
		if ( '' === trim( $code ) ) {
			$code = $this->starter_code( $type, $title, (string) $args['instructions'] );
		}

		$written = $this->write_page_file( $file, $code );
		if ( is_wp_error( $written ) ) {
			return $written;
		}

		$meta = array(
			'slug'          => $slug,
			'title'         => $title,
			'type'          => $type,
			'status'        => $status,
			'file'          => $file,
			'is_front_page' => ! empty( $args['is_front_page'] ),
			'updated_at'    => gmdate( 'c' ),
			'instructions'  => sanitize_textarea_field( (string) $args['instructions'] ),
		);

		// Only one front page.
		$manifest = $this->get_manifest();
		if ( ! empty( $meta['is_front_page'] ) ) {
			foreach ( $manifest['pages'] as &$existing ) {
				if ( is_array( $existing ) ) {
					$existing['is_front_page'] = false;
				}
			}
			unset( $existing );
		}

		$manifest['pages'][] = $meta;
		$this->save_manifest( $manifest );

		/**
		 * Fires after a design page is created.
		 *
		 * @since 2.0.0
		 * @param array $meta Page meta.
		 * @param string $code Page source.
		 */
		do_action( 'promptweb_page_created', $meta, $code );

		return array(
			'success' => true,
			'page'    => $this->get_page( $slug ),
			'message' => sprintf(
				/* translators: 1: slug, 2: status */
				__( 'Page “%1$s” created as %2$s.', 'promptweb' ),
				$slug,
				$status
			),
		);
	}

	/**
	 * Update an existing page's code and/or meta.
	 *
	 * @since 2.0.0
	 * @param array $args {
	 *     @type string $slug          Required.
	 *     @type string $code          New source (optional).
	 *     @type string $title         New title (optional).
	 *     @type string $status        draft|publish (optional).
	 *     @type string $instructions  Design notes (optional).
	 *     @type bool   $is_front_page Optional.
	 * }
	 * @return array|WP_Error
	 */
	public function update_page( $args = array() ) {
		$args = wp_parse_args(
			$args,
			array(
				'slug'          => '',
				'code'          => null,
				'title'         => null,
				'status'        => null,
				'instructions'  => null,
				'is_front_page' => null,
			)
		);

		$slug = sanitize_title( (string) $args['slug'] );
		$meta = $this->get_page_meta( $slug );
		if ( ! $meta ) {
			return new WP_Error(
				'promptweb_page_not_found',
				sprintf(
					/* translators: %s: slug */
					__( 'Page “%s” was not found.', 'promptweb' ),
					$slug
				)
			);
		}

		if ( null !== $args['code'] && is_string( $args['code'] ) ) {
			$written = $this->write_page_file( $meta['file'], $args['code'] );
			if ( is_wp_error( $written ) ) {
				return $written;
			}
		}

		if ( null !== $args['title'] && '' !== (string) $args['title'] ) {
			$meta['title'] = sanitize_text_field( (string) $args['title'] );
		}
		if ( null !== $args['status'] ) {
			$status = strtolower( (string) $args['status'] );
			if ( in_array( $status, self::STATUSES, true ) ) {
				$meta['status'] = $status;
			}
		}
		if ( null !== $args['instructions'] ) {
			$meta['instructions'] = sanitize_textarea_field( (string) $args['instructions'] );
		}
		if ( null !== $args['is_front_page'] ) {
			$meta['is_front_page'] = (bool) $args['is_front_page'];
		}
		$meta['updated_at'] = gmdate( 'c' );

		$manifest = $this->get_manifest();
		$found    = false;
		foreach ( $manifest['pages'] as $i => $page ) {
			if ( ! is_array( $page ) || ( $page['slug'] ?? '' ) !== $slug ) {
				continue;
			}
			if ( ! empty( $meta['is_front_page'] ) ) {
				foreach ( $manifest['pages'] as $j => $other ) {
					if ( is_array( $other ) ) {
						$manifest['pages'][ $j ]['is_front_page'] = false;
					}
				}
			}
			$manifest['pages'][ $i ] = $meta;
			$found                   = true;
			break;
		}

		if ( ! $found ) {
			$manifest['pages'][] = $meta;
		}

		$this->save_manifest( $manifest );

		/**
		 * Fires after a design page is updated.
		 *
		 * @since 2.0.0
		 * @param array $meta Updated meta.
		 */
		do_action( 'promptweb_page_updated', $meta );

		return array(
			'success' => true,
			'page'    => $this->get_page( $slug ),
			'message' => sprintf(
				/* translators: %s: slug */
				__( 'Page “%s” updated.', 'promptweb' ),
				$slug
			),
		);
	}

	/**
	 * Publish a page (Draft → Publish).
	 *
	 * @since 2.0.0
	 * @param string $slug Page slug.
	 * @return array|WP_Error
	 */
	public function publish_page( $slug ) {
		return $this->update_page(
			array(
				'slug'   => $slug,
				'status' => 'publish',
			)
		);
	}

	/**
	 * Unpublish a page (Publish → Draft).
	 *
	 * @since 2.0.0
	 * @param string $slug Page slug.
	 * @return array|WP_Error
	 */
	public function unpublish_page( $slug ) {
		return $this->update_page(
			array(
				'slug'   => $slug,
				'status' => 'draft',
			)
		);
	}

	/**
	 * Whether the current viewer may see a page given its status.
	 *
	 * Published: everyone. Draft: manage_options / manage_network only.
	 *
	 * @since 2.0.0
	 * @param array $meta Page meta.
	 * @return bool
	 */
	public function can_view_page( array $meta ) {
		$status = isset( $meta['status'] ) ? $meta['status'] : 'draft';
		if ( 'publish' === $status ) {
			return true;
		}

		// Draft: admins / network admins only (preview).
		if ( is_multisite() && is_super_admin() ) {
			return true;
		}
		if ( current_user_can( 'manage_options' ) ) {
			return true;
		}
		if ( is_multisite() && current_user_can( 'manage_network' ) ) {
			return true;
		}

		return false;
	}

	/**
	 * Resolve the front-page meta (is_front_page or first published, or first any).
	 *
	 * @since 2.0.0
	 * @param bool $public_only Prefer published pages only.
	 * @return array|null
	 */
	public function get_front_page_meta( $public_only = true ) {
		$manifest = $this->get_manifest();
		$first    = null;

		foreach ( $manifest['pages'] as $page ) {
			if ( ! is_array( $page ) ) {
				continue;
			}
			$meta = $this->normalize_page_meta( $page );
			if ( ! $meta ) {
				continue;
			}
			if ( $public_only && 'publish' !== $meta['status'] && ! $this->can_view_page( $meta ) ) {
				continue;
			}
			if ( ! empty( $meta['is_front_page'] ) ) {
				return $meta;
			}
			if ( null === $first ) {
				$first = $meta;
			}
		}

		return $first;
	}

	/**
	 * Public URL for a design page.
	 *
	 * @since 2.0.0
	 * @param string     $slug Page slug.
	 * @param array|null $meta Optional meta (avoids re-lookup).
	 * @return string
	 */
	public function get_public_url( $slug, $meta = null ) {
		if ( null === $meta ) {
			$meta = $this->get_page_meta( $slug );
		}
		if ( is_array( $meta ) && ! empty( $meta['is_front_page'] ) ) {
			return home_url( '/' );
		}

		$slug = sanitize_title( $slug );
		if ( function_exists( 'promptweb' ) && isset( promptweb()->frontend ) && promptweb()->frontend instanceof PromptWeb_Frontend ) {
			return promptweb()->frontend->get_page_url( $slug );
		}

		return home_url( user_trailingslashit( 'promptweb/' . $slug ) );
	}

	/**
	 * Absolute path to the local page source file.
	 *
	 * @since 2.0.0
	 * @param array $meta Page meta.
	 * @return string
	 */
	public function get_page_file_path( array $meta ) {
		$file = isset( $meta['file'] ) ? $meta['file'] : '';
		return $this->local_path( $file );
	}

	/**
	 * Starter HTML/PHP when AI creates a page without code yet.
	 *
	 * @since 2.0.0
	 * @param string $type         static|dynamic.
	 * @param string $title        Page title.
	 * @param string $instructions Design brief.
	 * @return string
	 */
	public function starter_code( $type, $title, $instructions = '' ) {
		$title_esc = esc_html( $title );
		$brief     = $instructions
			? "<!-- Design brief: " . esc_html( $instructions ) . " -->\n"
			: '';

		if ( 'dynamic' === $type ) {
			return "<?php\n"
				. "/**\n * PromptWeb dynamic page: {$title_esc}\n"
				. " * Full WordPress context available (\$wp_query, get_posts, hooks, etc.).\n"
				. " * @package PromptWeb\n */\n"
				. "if ( ! defined( 'ABSPATH' ) ) {\n\texit;\n}\n"
				. "\$page_title = " . var_export( $title, true ) . ";\n"
				. "?>\n"
				. "<!DOCTYPE html>\n<html <?php language_attributes(); ?>>\n<head>\n"
				. "\t<meta charset=\"<?php bloginfo( 'charset' ); ?>\">\n"
				. "\t<meta name=\"viewport\" content=\"width=device-width, initial-scale=1\">\n"
				. "\t<title><?php echo esc_html( \$page_title ); ?> — <?php bloginfo( 'name' ); ?></title>\n"
				. "\t<script src=\"https://cdn.tailwindcss.com\"></script>\n"
				. "\t<?php wp_head(); ?>\n</head>\n<body <?php body_class( 'bg-slate-50 text-slate-900 antialiased' ); ?>>\n"
				. "<?php if ( function_exists( 'wp_body_open' ) ) { wp_body_open(); } ?>\n"
				. $brief
				. "<main class=\"min-h-screen\">\n"
				. "\t<section class=\"mx-auto max-w-5xl px-6 py-24\">\n"
				. "\t\t<h1 class=\"text-4xl font-bold tracking-tight sm:text-5xl\"><?php echo esc_html( \$page_title ); ?></h1>\n"
				. "\t\t<p class=\"mt-6 text-lg text-slate-600\">Replace this starter with a high-quality dynamic design using WordPress loops and queries as needed.</p>\n"
				. "\t</section>\n</main>\n"
				. "<?php wp_footer(); ?>\n</body>\n</html>\n";
		}

		// Static HTML with Tailwind CDN — full creative freedom.
		return "<!DOCTYPE html>\n"
			. "<html lang=\"en\">\n<head>\n"
			. "\t<meta charset=\"UTF-8\">\n"
			. "\t<meta name=\"viewport\" content=\"width=device-width, initial-scale=1.0\">\n"
			. "\t<title>{$title_esc}</title>\n"
			. "\t<script src=\"https://cdn.tailwindcss.com\"></script>\n"
			. "\t<link rel=\"preconnect\" href=\"https://fonts.googleapis.com\">\n"
			. "\t<link rel=\"preconnect\" href=\"https://fonts.gstatic.com\" crossorigin>\n"
			. "\t<link href=\"https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap\" rel=\"stylesheet\">\n"
			. "\t<script>tailwind.config={theme:{extend:{fontFamily:{sans:['Inter','system-ui','sans-serif']}}}}</script>\n"
			. "\t<style>body{font-family:Inter,system-ui,sans-serif}</style>\n"
			. "</head>\n"
			. "<body class=\"bg-slate-50 text-slate-900 antialiased\">\n"
			. $brief
			. "<main class=\"min-h-screen\">\n"
			. "\t<section class=\"relative overflow-hidden bg-gradient-to-br from-indigo-600 via-violet-600 to-purple-700 text-white\">\n"
			. "\t\t<div class=\"mx-auto max-w-6xl px-6 py-24 sm:py-32\">\n"
			. "\t\t\t<p class=\"text-sm font-semibold uppercase tracking-widest text-indigo-100\">Draft · Ready for design</p>\n"
			. "\t\t\t<h1 class=\"mt-4 max-w-3xl text-4xl font-extrabold tracking-tight sm:text-6xl\">{$title_esc}</h1>\n"
			. "\t\t\t<p class=\"mt-6 max-w-2xl text-lg text-indigo-100\">A clean, modern starter. Replace this with a premium layout — strong hierarchy, generous spacing, and responsive Tailwind utilities.</p>\n"
			. "\t\t\t<div class=\"mt-10 flex flex-wrap gap-4\">\n"
			. "\t\t\t\t<a href=\"#\" class=\"inline-flex items-center rounded-xl bg-white px-6 py-3 text-sm font-semibold text-indigo-700 shadow-lg shadow-indigo-900/20 transition hover:bg-indigo-50\">Get started</a>\n"
			. "\t\t\t\t<a href=\"#\" class=\"inline-flex items-center rounded-xl border border-white/30 px-6 py-3 text-sm font-semibold text-white transition hover:bg-white/10\">Learn more</a>\n"
			. "\t\t\t</div>\n"
			. "\t\t</div>\n"
			. "\t</section>\n"
			. "\t<section class=\"mx-auto max-w-6xl px-6 py-20\">\n"
			. "\t\t<div class=\"grid gap-8 sm:grid-cols-3\">\n"
			. "\t\t\t<article class=\"rounded-2xl border border-slate-200 bg-white p-8 shadow-sm\">\n"
			. "\t\t\t\t<h2 class=\"text-lg font-semibold\">Crafted detail</h2>\n"
			. "\t\t\t\t<p class=\"mt-3 text-slate-600\">Use visual analysis after each update to refine spacing, contrast, and hierarchy.</p>\n"
			. "\t\t\t</article>\n"
			. "\t\t\t<article class=\"rounded-2xl border border-slate-200 bg-white p-8 shadow-sm\">\n"
			. "\t\t\t\t<h2 class=\"text-lg font-semibold\">Responsive by default</h2>\n"
			. "\t\t\t\t<p class=\"mt-3 text-slate-600\">Tailwind breakpoints keep layouts sharp on mobile, tablet, and desktop.</p>\n"
			. "\t\t\t</article>\n"
			. "\t\t\t<article class=\"rounded-2xl border border-slate-200 bg-white p-8 shadow-sm\">\n"
			. "\t\t\t\t<h2 class=\"text-lg font-semibold\">Publish when ready</h2>\n"
			. "\t\t\t\t<p class=\"mt-3 text-slate-600\">New pages start as Draft. Publish only after design quality is high.</p>\n"
			. "\t\t\t</article>\n"
			. "\t\t</div>\n"
			. "\t</section>\n"
			. "</main>\n"
			. "</body>\n</html>\n";
	}

	/**
	 * Heuristic visual analysis of page code (no external AI required).
	 *
	 * Returns structured feedback on layout, spacing, hierarchy, and improvements.
	 * Critical for AI self-improvement loops after create/update.
	 *
	 * @since 2.0.0
	 * @param string $slug Page slug (preferred) OR empty if code provided.
	 * @param string $code Optional raw code override.
	 * @return array|WP_Error
	 */
	public function get_visual_analysis( $slug = '', $code = '' ) {
		$meta = null;
		if ( '' !== $slug ) {
			$page = $this->get_page( $slug );
			if ( is_wp_error( $page ) ) {
				return $page;
			}
			$meta = $page;
			if ( '' === $code ) {
				$code = isset( $page['code'] ) ? $page['code'] : '';
			}
		}

		if ( '' === trim( (string) $code ) ) {
			return new WP_Error(
				'promptweb_analysis_no_code',
				__( 'No page code available to analyze.', 'promptweb' )
			);
		}

		$code_str = (string) $code;
		$lower    = strtolower( $code_str );
		$score    = 100;
		$issues   = array();
		$strengths = array();
		$suggestions = array();

		// --- Document structure ---
		$has_doctype = ( false !== stripos( $code_str, '<!DOCTYPE' ) || false !== stripos( $code_str, '<html' ) );
		$has_viewport = ( false !== strpos( $lower, 'viewport' ) );
		$has_title    = (bool) preg_match( '/<title[^>]*>\s*\S+/i', $code_str );
		$has_main     = ( false !== strpos( $lower, '<main' ) || false !== strpos( $lower, 'role="main"' ) );
		$has_header   = ( false !== strpos( $lower, '<header' ) );
		$has_footer   = ( false !== strpos( $lower, '<footer' ) );
		$has_nav      = ( false !== strpos( $lower, '<nav' ) );

		if ( ! $has_viewport ) {
			$score -= 10;
			$issues[] = 'Missing responsive viewport meta tag.';
			$suggestions[] = 'Add <meta name="viewport" content="width=device-width, initial-scale=1"> in <head>.';
		} else {
			$strengths[] = 'Responsive viewport is set.';
		}

		if ( ! $has_title ) {
			$score -= 5;
			$issues[] = 'Missing or empty <title>.';
		}

		if ( ! $has_main ) {
			$score -= 8;
			$issues[] = 'No <main> landmark — hurts accessibility and hierarchy.';
			$suggestions[] = 'Wrap primary content in a <main> element.';
		} else {
			$strengths[] = 'Uses a <main> landmark.';
		}

		// --- Visual hierarchy (headings) ---
		preg_match_all( '/<h1\b/i', $code_str, $h1 );
		preg_match_all( '/<h2\b/i', $code_str, $h2 );
		preg_match_all( '/<h3\b/i', $code_str, $h3 );
		$h1_count = count( $h1[0] );
		$h2_count = count( $h2[0] );
		$h3_count = count( $h3[0] );

		if ( 0 === $h1_count ) {
			$score -= 12;
			$issues[] = 'No H1 — weak page-level hierarchy.';
			$suggestions[] = 'Add a single clear H1 for the page purpose.';
		} elseif ( $h1_count > 1 ) {
			$score -= 6;
			$issues[] = 'Multiple H1 elements (' . $h1_count . ') — prefer one primary H1.';
			$suggestions[] = 'Keep a single H1; use H2/H3 for subsections.';
		} else {
			$strengths[] = 'Single H1 establishes clear hierarchy.';
		}

		if ( 0 === $h2_count && strlen( $code_str ) > 2500 ) {
			$score -= 5;
			$issues[] = 'Long page without H2 sections — hierarchy may feel flat.';
			$suggestions[] = 'Break content into sections with descriptive H2 headings.';
		}

		// --- Tailwind / modern CSS ---
		$uses_tailwind = ( false !== strpos( $lower, 'tailwindcss' ) || false !== strpos( $lower, 'cdn.tailwindcss.com' )
			|| (bool) preg_match( '/\b(flex|grid|px-|py-|mt-|mb-|rounded-|shadow-|bg-|text-)\w*/', $code_str ) );

		if ( $uses_tailwind ) {
			$strengths[] = 'Uses Tailwind (or Tailwind-like utility classes) for modern styling.';
		} else {
			$score -= 8;
			$issues[] = 'Little or no Tailwind utility usage detected.';
			$suggestions[] = 'Prefer Tailwind via CDN for rapid, high-quality responsive design.';
		}

		// Spacing signals.
		$has_generous_spacing = (bool) preg_match( '/\b(py-(1[2-9]|[2-9]\d)|p-(1[2-9]|[2-9]\d)|space-y-|gap-(6|8|10|12|16))\b/', $code_str );
		if ( $has_generous_spacing ) {
			$strengths[] = 'Generous spacing utilities detected (good breathing room).';
		} else {
			$score -= 6;
			$issues[] = 'Spacing may be tight — limited large padding/gap utilities found.';
			$suggestions[] = 'Use larger section padding (e.g. py-16 / py-24) and consistent gaps between cards.';
		}

		// --- Images / alt text ---
		preg_match_all( '/<img\b[^>]*>/i', $code_str, $imgs );
		$img_count = count( $imgs[0] );
		$missing_alt = 0;
		foreach ( $imgs[0] as $img_tag ) {
			if ( ! preg_match( '/\balt\s*=\s*["\'][^"\']+["\']/', $img_tag ) ) {
				$missing_alt++;
			}
		}
		if ( $img_count > 0 && $missing_alt > 0 ) {
			$score -= min( 10, $missing_alt * 3 );
			$issues[] = $missing_alt . ' image(s) missing meaningful alt text.';
			$suggestions[] = 'Add descriptive alt attributes to all content images.';
		} elseif ( $img_count > 0 ) {
			$strengths[] = 'Images include alt attributes.';
		}

		// --- CTAs / interactivity ---
		preg_match_all( '/<a\b/i', $code_str, $links );
		preg_match_all( '/<button\b/i', $code_str, $buttons );
		$cta_count = count( $links[0] ) + count( $buttons[0] );
		if ( $cta_count < 1 ) {
			$score -= 4;
			$issues[] = 'No links or buttons — weak calls to action.';
			$suggestions[] = 'Add at least one clear primary CTA.';
		} else {
			$strengths[] = 'Interactive CTAs (links/buttons) present.';
		}

		// --- Accessibility basics ---
		$has_lang = (bool) preg_match( '/<html[^>]+lang=/i', $code_str );
		if ( $has_doctype && ! $has_lang ) {
			$score -= 3;
			$issues[] = 'HTML root missing lang attribute.';
			$suggestions[] = 'Set lang on <html> (e.g. lang="en").';
		}

		// --- Empty / placeholder content ---
		if ( false !== strpos( $lower, 'lorem ipsum' ) || false !== strpos( $lower, 'replace this' ) || false !== strpos( $lower, 'draft · ready' ) ) {
			$score -= 10;
			$issues[] = 'Placeholder / starter content still present.';
			$suggestions[] = 'Replace starter copy with polished, brand-specific content.';
		}

		// --- Sections ---
		preg_match_all( '/<section\b/i', $code_str, $sections );
		$section_count = count( $sections[0] );
		if ( $section_count >= 3 ) {
			$strengths[] = 'Multi-section layout (' . $section_count . ' sections) — good narrative structure.';
		} elseif ( $section_count < 2 && strlen( $code_str ) > 1500 ) {
			$score -= 4;
			$suggestions[] = 'Consider 4–7 well-spaced sections for a homepage-quality design.';
		}

		// --- Max-width / container ---
		$has_container = (bool) preg_match( '/\b(max-w-|container|mx-auto)\b/', $code_str );
		if ( $has_container ) {
			$strengths[] = 'Content width constraints (max-w / mx-auto) improve readability.';
		} else {
			$score -= 4;
			$suggestions[] = 'Constrain content width (e.g. max-w-6xl mx-auto px-6) for large screens.';
		}

		$score = max( 0, min( 100, $score ) );

		$grade = 'needs_work';
		if ( $score >= 85 ) {
			$grade = 'excellent';
		} elseif ( $score >= 70 ) {
			$grade = 'good';
		} elseif ( $score >= 50 ) {
			$grade = 'fair';
		}

		if ( $score < 85 ) {
			$suggestions[] = 'After fixes, re-run get_visual_analysis and iterate until score is 85+.';
			$suggestions[] = 'Prioritize hierarchy, spacing rhythm, contrast, and mobile stacking.';
		}

		// Deduplicate suggestions.
		$suggestions = array_values( array_unique( $suggestions ) );
		$issues      = array_values( array_unique( $issues ) );
		$strengths   = array_values( array_unique( $strengths ) );

		$analysis = array(
			'slug'               => $meta ? $meta['slug'] : sanitize_title( $slug ),
			'type'               => $meta ? $meta['type'] : '',
			'status'             => $meta ? $meta['status'] : '',
			'score'              => $score,
			'grade'              => $grade,
			'layout'             => array(
				'has_full_document' => $has_doctype,
				'has_header'        => $has_header,
				'has_nav'           => $has_nav,
				'has_main'          => $has_main,
				'has_footer'        => $has_footer,
				'section_count'     => $section_count,
				'uses_container'    => $has_container,
			),
			'hierarchy'          => array(
				'h1_count' => $h1_count,
				'h2_count' => $h2_count,
				'h3_count' => $h3_count,
			),
			'spacing'            => array(
				'generous_spacing_detected' => $has_generous_spacing,
			),
			'styling'            => array(
				'uses_tailwind' => $uses_tailwind,
			),
			'accessibility'      => array(
				'has_viewport'   => $has_viewport,
				'has_lang'       => $has_lang,
				'images'         => $img_count,
				'images_missing_alt' => $missing_alt,
			),
			'strengths'          => $strengths,
			'issues'             => $issues,
			'suggestions'        => $suggestions,
			'summary'            => sprintf(
				/* translators: 1: score, 2: grade */
				__( 'Visual quality score: %1$d/100 (%2$s). Address issues, then improve and re-analyze.', 'promptweb' ),
				$score,
				$grade
			),
		);

		/**
		 * Filters visual analysis results.
		 *
		 * @since 2.0.0
		 * @param array  $analysis Analysis payload.
		 * @param string $code     Analyzed source.
		 */
		return (array) apply_filters( 'promptweb_visual_analysis', $analysis, $code_str );
	}

	/**
	 * All local page files + manifest for GitHub commit.
	 *
	 * @since 2.0.0
	 * @return array<string,string> Map of repo-relative path => contents.
	 */
	public function get_files_for_commit() {
		$files    = array();
		$manifest = $this->get_manifest();

		// Always include manifest.
		$json = wp_json_encode( $manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		if ( is_string( $json ) ) {
			if ( "\n" !== substr( $json, -1 ) ) {
				$json .= "\n";
			}
			$files[ self::MANIFEST_PATH ] = $json;
		}

		foreach ( $manifest['pages'] as $page ) {
			if ( ! is_array( $page ) || empty( $page['file'] ) ) {
				continue;
			}
			$rel  = ltrim( str_replace( '\\', '/', (string) $page['file'] ), '/' );
			$code = $this->read_page_file( $rel );
			if ( is_wp_error( $code ) ) {
				continue;
			}
			$files[ $rel ] = $code;
		}

		return $files;
	}

	/**
	 * Import a file from GitHub sync into local storage + update manifest if needed.
	 *
	 * @since 2.0.0
	 * @param string $relative Repo path.
	 * @param string $contents File body.
	 * @return true|WP_Error
	 */
	public function import_remote_file( $relative, $contents ) {
		$relative = ltrim( str_replace( '\\', '/', (string) $relative ), '/' );

		// Manifest special-case.
		if ( self::MANIFEST_PATH === $relative || 'pages/manifest.json' === $relative ) {
			$data = json_decode( (string) $contents, true );
			if ( ! is_array( $data ) ) {
				return new WP_Error( 'promptweb_invalid_manifest', __( 'Remote manifest is not valid JSON.', 'promptweb' ) );
			}
			$this->save_manifest( $data );
			return true;
		}

		// Only accept files under pages/static or pages/dynamic.
		if ( 0 !== strpos( $relative, 'pages/static/' ) && 0 !== strpos( $relative, 'pages/dynamic/' ) ) {
			return new WP_Error( 'promptweb_invalid_page_path', __( 'Remote path is outside pages/static or pages/dynamic.', 'promptweb' ) );
		}

		$written = $this->write_page_file( $relative, (string) $contents );
		if ( is_wp_error( $written ) ) {
			return $written;
		}

		// Auto-register in manifest if missing.
		$basename = basename( $relative );
		$slug     = preg_replace( '/\.(html|php)$/i', '', $basename );
		$slug     = sanitize_title( (string) $slug );
		$type     = ( 0 === strpos( $relative, 'pages/dynamic/' ) ) ? 'dynamic' : 'static';

		if ( $slug && ! $this->get_page_meta( $slug ) ) {
			$manifest            = $this->get_manifest();
			$manifest['pages'][] = array(
				'slug'          => $slug,
				'title'         => ucwords( str_replace( array( '-', '_' ), ' ', $slug ) ),
				'type'          => $type,
				'status'        => 'publish', // Remote-only files default to publish so Sync shows them.
				'file'          => $relative,
				'is_front_page' => ( 'home' === $slug || 'index' === $slug ),
				'updated_at'    => gmdate( 'c' ),
				'instructions'  => '',
			);
			$this->save_manifest( $manifest );
		}

		return true;
	}

	/**
	 * Beautiful published homepage HTML for repository initialization (Tailwind CDN).
	 *
	 * Used only as the starter home when Initialize creates pages/static/home.html.
	 * New AI-created pages still use starter_code() and remain Draft by default.
	 *
	 * @since 2.0.0
	 * @param string $site_name Site / brand name for copy.
	 * @return string
	 */
	public function get_init_home_html( $site_name = '' ) {
		$site_name = is_string( $site_name ) ? trim( $site_name ) : '';
		if ( '' === $site_name ) {
			$site_name = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
		}
		if ( '' === $site_name ) {
			$site_name = 'PromptWeb';
		}

		// Plain text for HTML body (not WP-admin context; keep entities safe).
		$brand = htmlspecialchars( $site_name, ENT_QUOTES, 'UTF-8' );

		$html = <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
	<meta charset="UTF-8">
	<meta name="viewport" content="width=device-width, initial-scale=1.0">
	<title>{$brand} — Home</title>
	<script src="https://cdn.tailwindcss.com"></script>
	<link rel="preconnect" href="https://fonts.googleapis.com">
	<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
	<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
	<script>
		tailwind.config = {
			theme: {
				extend: {
					fontFamily: { sans: ['Inter', 'system-ui', 'sans-serif'] },
					colors: {
						brand: { 50: '#eef2ff', 100: '#e0e7ff', 500: '#6366f1', 600: '#4f46e5', 700: '#4338ca', 900: '#312e81' }
					}
				}
			}
		};
	</script>
	<style>
		body { font-family: Inter, system-ui, sans-serif; }
		html { scroll-behavior: smooth; }
	</style>
</head>
<body class="bg-white text-slate-900 antialiased">
	<!-- PromptWeb init starter · Architecture v2 · static HTML + Tailwind CDN -->
	<header class="sticky top-0 z-40 border-b border-slate-200/80 bg-white/90 backdrop-blur">
		<div class="mx-auto flex max-w-6xl items-center justify-between px-6 py-4">
			<a href="/" class="text-lg font-bold tracking-tight text-slate-900">{$brand}</a>
			<nav class="hidden items-center gap-8 text-sm font-medium text-slate-600 sm:flex" aria-label="Primary">
				<a href="#features" class="transition hover:text-brand-600">Features</a>
				<a href="#about" class="transition hover:text-brand-600">About</a>
				<a href="#work" class="transition hover:text-brand-600">Work</a>
				<a href="#contact" class="inline-flex items-center rounded-full bg-brand-600 px-4 py-2 text-white shadow-sm transition hover:bg-brand-700">Contact</a>
			</nav>
		</div>
	</header>

	<main>
		<!-- Hero -->
		<section class="relative overflow-hidden bg-gradient-to-br from-slate-950 via-brand-900 to-indigo-800 text-white">
			<div class="pointer-events-none absolute inset-0 opacity-30" aria-hidden="true">
				<div class="absolute -left-24 top-10 h-72 w-72 rounded-full bg-brand-500 blur-3xl"></div>
				<div class="absolute bottom-0 right-0 h-96 w-96 rounded-full bg-violet-500 blur-3xl"></div>
			</div>
			<div class="relative mx-auto max-w-6xl px-6 py-24 sm:py-32 lg:py-40">
				<p class="text-sm font-semibold uppercase tracking-[0.2em] text-indigo-200">PromptWeb · AI-ready</p>
				<h1 class="mt-5 max-w-3xl text-4xl font-extrabold tracking-tight sm:text-6xl sm:leading-tight">
					Build a beautiful website with full creative freedom
				</h1>
				<p class="mt-6 max-w-2xl text-lg leading-relaxed text-indigo-100 sm:text-xl">
					{$brand} is ready for high-quality design. Static HTML with Tailwind, or dynamic PHP when you need WordPress power — draft, refine, publish.
				</p>
				<div class="mt-10 flex flex-wrap gap-4">
					<a href="#features" class="inline-flex items-center rounded-xl bg-white px-6 py-3.5 text-sm font-semibold text-brand-700 shadow-lg shadow-black/20 transition hover:bg-brand-50">
						Explore features
					</a>
					<a href="#contact" class="inline-flex items-center rounded-xl border border-white/30 px-6 py-3.5 text-sm font-semibold text-white transition hover:bg-white/10">
						Get in touch
					</a>
				</div>
			</div>
		</section>

		<!-- Features -->
		<section id="features" class="bg-slate-50 py-20 sm:py-24">
			<div class="mx-auto max-w-6xl px-6">
				<div class="max-w-2xl">
					<p class="text-sm font-semibold uppercase tracking-widest text-brand-600">Features</p>
					<h2 class="mt-3 text-3xl font-bold tracking-tight text-slate-900 sm:text-4xl">Designed for modern teams</h2>
					<p class="mt-4 text-lg text-slate-600">Clean structure, generous spacing, and a professional foundation AI agents can elevate further.</p>
				</div>
				<div class="mt-14 grid gap-8 sm:grid-cols-2 lg:grid-cols-3">
					<article class="rounded-2xl border border-slate-200 bg-white p-8 shadow-sm transition hover:shadow-md">
						<div class="flex h-11 w-11 items-center justify-center rounded-xl bg-brand-50 text-brand-600" aria-hidden="true">
							<svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"/></svg>
						</div>
						<h3 class="mt-5 text-lg font-semibold text-slate-900">Lightning-fast design</h3>
						<p class="mt-3 text-slate-600 leading-relaxed">Ship premium pages with Tailwind utilities — no bloated page builders required.</p>
					</article>
					<article class="rounded-2xl border border-slate-200 bg-white p-8 shadow-sm transition hover:shadow-md">
						<div class="flex h-11 w-11 items-center justify-center rounded-xl bg-brand-50 text-brand-600" aria-hidden="true">
							<svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 5a1 1 0 011-1h14a1 1 0 011 1v2a1 1 0 01-1 1H5a1 1 0 01-1-1V5zM4 13a1 1 0 011-1h6a1 1 0 011 1v6a1 1 0 01-1 1H5a1 1 0 01-1-1v-6zM16 13a1 1 0 011-1h2a1 1 0 011 1v6a1 1 0 01-1 1h-2a1 1 0 01-1-1v-6z"/></svg>
						</div>
						<h3 class="mt-5 text-lg font-semibold text-slate-900">Full creative freedom</h3>
						<p class="mt-3 text-slate-600 leading-relaxed">Static HTML or dynamic PHP — choose the right tool for every page.</p>
					</article>
					<article class="rounded-2xl border border-slate-200 bg-white p-8 shadow-sm transition hover:shadow-md">
						<div class="flex h-11 w-11 items-center justify-center rounded-xl bg-brand-50 text-brand-600" aria-hidden="true">
							<svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"/></svg>
						</div>
						<h3 class="mt-5 text-lg font-semibold text-slate-900">Draft → publish</h3>
						<p class="mt-3 text-slate-600 leading-relaxed">New AI pages start as drafts. Publish only when design quality is excellent.</p>
					</article>
				</div>
			</div>
		</section>

		<!-- About / social proof -->
		<section id="about" class="py-20 sm:py-24">
			<div class="mx-auto grid max-w-6xl items-center gap-12 px-6 lg:grid-cols-2">
				<div>
					<p class="text-sm font-semibold uppercase tracking-widest text-brand-600">About</p>
					<h2 class="mt-3 text-3xl font-bold tracking-tight text-slate-900 sm:text-4xl">A professional foundation for {$brand}</h2>
					<p class="mt-5 text-lg leading-relaxed text-slate-600">
						This starter homepage was created by PromptWeb Initialize. Replace sections with your brand story, product screenshots, and real calls to action. Prefer visual analysis after every major update.
					</p>
					<ul class="mt-8 space-y-3 text-slate-700">
						<li class="flex items-start gap-3"><span class="mt-1 h-2 w-2 shrink-0 rounded-full bg-brand-600"></span><span>Responsive layout with clear hierarchy</span></li>
						<li class="flex items-start gap-3"><span class="mt-1 h-2 w-2 shrink-0 rounded-full bg-brand-600"></span><span>Semantic HTML and accessible landmarks</span></li>
						<li class="flex items-start gap-3"><span class="mt-1 h-2 w-2 shrink-0 rounded-full bg-brand-600"></span><span>GitHub as source of truth for design files</span></li>
					</ul>
				</div>
				<div class="rounded-3xl border border-slate-200 bg-gradient-to-br from-brand-50 to-white p-10 shadow-sm">
					<div class="grid grid-cols-2 gap-8">
						<div>
							<p class="text-3xl font-extrabold text-brand-700">100%</p>
							<p class="mt-1 text-sm text-slate-600">Creative freedom</p>
						</div>
						<div>
							<p class="text-3xl font-extrabold text-brand-700">v2</p>
							<p class="mt-1 text-sm text-slate-600">Architecture</p>
						</div>
						<div>
							<p class="text-3xl font-extrabold text-brand-700">MCP</p>
							<p class="mt-1 text-sm text-slate-600">AI tool ready</p>
						</div>
						<div>
							<p class="text-3xl font-extrabold text-brand-700">GH</p>
							<p class="mt-1 text-sm text-slate-600">Source of truth</p>
						</div>
					</div>
				</div>
			</div>
		</section>

		<!-- Work / cards -->
		<section id="work" class="border-t border-slate-100 bg-slate-50 py-20 sm:py-24">
			<div class="mx-auto max-w-6xl px-6">
				<div class="flex flex-col justify-between gap-4 sm:flex-row sm:items-end">
					<div>
						<p class="text-sm font-semibold uppercase tracking-widest text-brand-600">Work</p>
						<h2 class="mt-3 text-3xl font-bold tracking-tight text-slate-900 sm:text-4xl">Featured highlights</h2>
					</div>
					<p class="max-w-md text-slate-600">Swap these cards for case studies, services, or portfolio items.</p>
				</div>
				<div class="mt-12 grid gap-6 md:grid-cols-3">
					<article class="group overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
						<div class="h-40 bg-gradient-to-br from-brand-500 to-violet-600 transition group-hover:scale-[1.02]"></div>
						<div class="p-6">
							<h3 class="text-lg font-semibold">Brand experience</h3>
							<p class="mt-2 text-sm leading-relaxed text-slate-600">Hero, narrative, and CTA patterns tuned for conversion.</p>
						</div>
					</article>
					<article class="group overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
						<div class="h-40 bg-gradient-to-br from-slate-800 to-brand-700 transition group-hover:scale-[1.02]"></div>
						<div class="p-6">
							<h3 class="text-lg font-semibold">Product marketing</h3>
							<p class="mt-2 text-sm leading-relaxed text-slate-600">Feature grids and social proof that feel premium on every screen.</p>
						</div>
					</article>
					<article class="group overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
						<div class="h-40 bg-gradient-to-br from-indigo-600 to-cyan-500 transition group-hover:scale-[1.02]"></div>
						<div class="p-6">
							<h3 class="text-lg font-semibold">Content systems</h3>
							<p class="mt-2 text-sm leading-relaxed text-slate-600">Use dynamic PHP pages when WordPress loops are required.</p>
						</div>
					</article>
				</div>
			</div>
		</section>

		<!-- CTA -->
		<section id="contact" class="py-20 sm:py-24">
			<div class="mx-auto max-w-6xl px-6">
				<div class="relative overflow-hidden rounded-3xl bg-slate-900 px-8 py-14 text-center text-white shadow-xl sm:px-16">
					<div class="pointer-events-none absolute -right-10 -top-10 h-40 w-40 rounded-full bg-brand-500/40 blur-2xl" aria-hidden="true"></div>
					<h2 class="relative text-3xl font-bold tracking-tight sm:text-4xl">Ready to customize this site?</h2>
					<p class="relative mx-auto mt-4 max-w-xl text-lg text-slate-300">
						Ask your AI agent to redesign this homepage, add pages as Draft, run visual analysis, then publish when quality is high.
					</p>
					<a href="mailto:hello@example.com" class="relative mt-8 inline-flex items-center rounded-xl bg-white px-6 py-3.5 text-sm font-semibold text-slate-900 transition hover:bg-brand-50">
						Start a conversation
					</a>
				</div>
			</div>
		</section>
	</main>

	<footer class="border-t border-slate-200 bg-white">
		<div class="mx-auto flex max-w-6xl flex-col items-center justify-between gap-4 px-6 py-10 sm:flex-row">
			<p class="text-sm text-slate-500">&copy; <span id="pw-year"></span> {$brand}. All rights reserved.</p>
			<p class="text-xs text-slate-400">Powered by PromptWeb Architecture v2</p>
		</div>
	</footer>
	<script>
		document.getElementById('pw-year').textContent = new Date().getFullYear();
	</script>
</body>
</html>

HTML;

		/**
		 * Filters the Initialize starter homepage HTML.
		 *
		 * @since 2.0.0
		 * @param string $html      Homepage HTML.
		 * @param string $site_name Brand name used in the template.
		 */
		return (string) apply_filters( 'promptweb_init_home_html', $html, $site_name );
	}

	/**
	 * Starter home page bundle for repository initialization.
	 *
	 * - pages/manifest.json  (home as front page, status publish — starter only)
	 * - pages/static/home.html (beautiful Tailwind homepage)
	 * - pages/dynamic/.gitkeep (keeps dynamic folder in Git)
	 *
	 * @since 2.0.0
	 * @param string $site_name Optional brand name.
	 * @return array{manifest:array,files:array<string,string>}
	 */
	public function get_init_starter_bundle( $site_name = '' ) {
		$slug  = 'home';
		$title = 'Home';
		$file  = self::STATIC_DIR . '/home.html';
		$code  = $this->get_init_home_html( $site_name );

		$manifest = array(
			'version' => '2.0',
			'pages'   => array(
				array(
					'slug'          => $slug,
					'title'         => $title,
					'type'          => 'static',
					'status'        => 'publish', // Starter home only; AI-created pages use Draft.
					'file'          => $file,
					'is_front_page' => true,
					'updated_at'    => gmdate( 'c' ),
					'instructions'  => 'Initialize starter homepage — static HTML + Tailwind CDN',
				),
			),
		);

		$json = wp_json_encode( $manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		if ( ! is_string( $json ) ) {
			$json = "{}\n";
		}
		if ( "\n" !== substr( $json, -1 ) ) {
			$json .= "\n";
		}

		$gitkeep = "# PromptWeb dynamic pages live here (e.g. blog.php).\n# Use PHP + WordPress when dynamic content is required.\n";

		return array(
			'manifest' => $manifest,
			'files'    => array(
				self::MANIFEST_PATH              => $json,
				$file                            => $code,
				self::DYNAMIC_DIR . '/.gitkeep'  => $gitkeep,
			),
		);
	}

	/**
	 * Merge an init starter page into an existing manifest without removing other pages.
	 *
	 * Ensures home exists as front page (publish) for first-run UX; preserves custom pages.
	 *
	 * @since 2.0.0
	 * @param array $existing Existing manifest.
	 * @param array $starter  Starter page meta (single page entry or full starter manifest).
	 * @return array
	 */
	public function merge_init_manifest( array $existing, array $starter_page ) {
		$existing = $this->normalize_manifest( $existing );
		$home     = $this->normalize_page_meta( $starter_page );
		if ( ! $home ) {
			return $existing;
		}

		$found = false;
		foreach ( $existing['pages'] as $i => $page ) {
			if ( ! is_array( $page ) ) {
				continue;
			}
			if ( ( $page['slug'] ?? '' ) === $home['slug'] ) {
				// Keep existing custom meta when page already registered; only fill gaps.
				$merged = array_merge( $home, $page );
				// Still ensure front-page flag if nothing else claims it.
				$merged['slug'] = $home['slug'];
				$existing['pages'][ $i ] = $this->normalize_page_meta( $merged );
				$found = true;
				break;
			}
		}

		if ( ! $found ) {
			// New home: clear other front flags then append.
			foreach ( $existing['pages'] as $j => $page ) {
				if ( is_array( $page ) ) {
					$existing['pages'][ $j ]['is_front_page'] = false;
				}
			}
			$existing['pages'][] = $home;
		} else {
			// If home is front, demote others.
			$home_is_front = false;
			foreach ( $existing['pages'] as $page ) {
				if ( is_array( $page ) && ( $page['slug'] ?? '' ) === $home['slug'] && ! empty( $page['is_front_page'] ) ) {
					$home_is_front = true;
					break;
				}
			}
			if ( ! $home_is_front ) {
				// Ensure at least one front page: prefer home.
				$has_front = false;
				foreach ( $existing['pages'] as $page ) {
					if ( is_array( $page ) && ! empty( $page['is_front_page'] ) ) {
						$has_front = true;
						break;
					}
				}
				if ( ! $has_front ) {
					foreach ( $existing['pages'] as $j => $page ) {
						if ( is_array( $page ) && ( $page['slug'] ?? '' ) === $home['slug'] ) {
							$existing['pages'][ $j ]['is_front_page'] = true;
						}
					}
				}
			}
		}

		$existing['version'] = '2.0';
		return $this->normalize_manifest( $existing );
	}
}
