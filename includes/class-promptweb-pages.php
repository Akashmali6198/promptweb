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
	 * Starter home page for repository initialization.
	 *
	 * @since 2.0.0
	 * @return array{manifest:array,files:array<string,string>}
	 */
	public function get_init_starter_bundle() {
		$slug  = 'home';
		$title = 'Home';
		$file  = self::STATIC_DIR . '/home.html';
		$code  = $this->starter_code( 'static', $title, 'Premium modern homepage — hero, features, CTA' );

		$manifest = array(
			'version' => '2.0',
			'pages'   => array(
				array(
					'slug'          => $slug,
					'title'         => $title,
					'type'          => 'static',
					'status'        => 'publish',
					'file'          => $file,
					'is_front_page' => true,
					'updated_at'    => gmdate( 'c' ),
					'instructions'  => '',
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

		return array(
			'manifest' => $manifest,
			'files'    => array(
				self::MANIFEST_PATH => $json,
				$file               => $code,
			),
		);
	}
}
