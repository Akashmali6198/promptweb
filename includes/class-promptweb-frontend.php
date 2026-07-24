<?php
/**
 * Frontend page routing and rendering.
 *
 * Architecture (v2):
 * - Design pages (preferred): static HTML (pages/static) or dynamic PHP (pages/dynamic)
 * - Legacy: blueprint JSON → PromptWeb_Renderer (pages → sections → elements)
 * - Draft pages are only visible to manage_options / manage_network
 *
 * ONE primary public URL format:
 * - Home (front):  https://example.com/
 * - Other pages:   https://example.com/{slug}/   e.g. /about/, /services/
 *
 * Legacy formats 301-redirect to the clean URL:
 * - /promptweb/{slug}/  →  /{slug}/  (or / if front)
 * - ?promptweb_page={slug}  →  /{slug}/  (or / if front)
 *
 * Root mapping only claims a slug when no real WP page/post owns it.
 * Multisite: design data + rewrites run in the current blog context.
 *
 * @package PromptWeb
 * @since   1.0.0
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Public front-end routing for PromptWeb blueprints.
 *
 * @since 1.0.0
 */
class PromptWeb_Frontend {

	/**
	 * Query var for explicit /promptweb/{slug}/ routes.
	 *
	 * @since 1.0.0
	 * @var   string
	 */
	const QUERY_VAR = 'promptweb_page';

	/**
	 * Fallback rewrite path segment: /promptweb/{slug}/.
	 *
	 * Primary public links use clean root URLs (/{slug}/); this base remains
	 * as an explicit fallback route.
	 *
	 * @since 1.0.0
	 * @var   string
	 */
	const REWRITE_BASE = 'promptweb';

	/**
	 * Default public URL strategy: clean root paths.
	 *
	 * "root"       → /{slug}/  (preferred public format)
	 * "namespaced" → /promptweb/{slug}/  (legacy / explicit)
	 *
	 * @since 2.0.0
	 * @var   string
	 */
	const URL_STRATEGY_DEFAULT = 'root';

	/**
	 * Option key: rewrite rules version (flush when bumped).
	 *
	 * @since 2.0.0
	 * @var   string
	 */
	const REWRITE_VERSION_OPTION = 'promptweb_rewrite_version';

	/**
	 * Current rewrite rules version (bump to force a safe flush on upgrade).
	 *
	 * @since 2.0.0
	 * @var   string
	 */
	const REWRITE_VERSION = '2.0.1-canonical';

	/**
	 * Whether this request is being handled as a PromptWeb page.
	 *
	 * @since 1.0.0
	 * @var   bool
	 */
	protected $is_promptweb_request = false;

	/**
	 * Resolved page slug for the current request (if any).
	 *
	 * @since 1.0.0
	 * @var   string|null
	 */
	protected $current_slug = null;

	/**
	 * Register front-end hooks.
	 *
	 * Called on `init` priority 5 from the main plugin class.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public function init() {
		// Rewrites must register during init (same request is fine at later priority).
		$this->register_rewrites();
		add_filter( 'query_vars', array( $this, 'register_query_vars' ) );

		// Safe one-time flush when rewrite version changes (e.g. root URL default).
		add_action( 'init', array( $this, 'maybe_flush_rewrites_on_upgrade' ), 99 );

		// 301 legacy /promptweb/{slug}/ and ?promptweb_page= to clean /{slug}/.
		add_action( 'template_redirect', array( $this, 'maybe_redirect_legacy_urls' ), 0 );

		add_action( 'pre_get_posts', array( $this, 'maybe_flag_request' ) );
		add_filter( 'request', array( $this, 'maybe_map_root_slug' ), 5 );

		add_filter( 'template_include', array( $this, 'maybe_template_include' ), 99 );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_public_assets' ) );

		// Classic content integration: if a WP page is used as a shell, replace content.
		add_filter( 'the_content', array( $this, 'maybe_filter_the_content' ), 5 );

		add_shortcode( 'promptweb', array( $this, 'shortcode' ) );

		// Title for PromptWeb virtual pages.
		add_filter( 'document_title_parts', array( $this, 'filter_document_title' ) );
		add_filter( 'pre_get_document_title', array( $this, 'filter_pre_document_title' ), 20 );

		/**
		 * Fires when the frontend router is initialized.
		 *
		 * @since 1.0.0
		 * @param PromptWeb_Frontend $frontend This instance.
		 */
		do_action( 'promptweb_frontend_init', $this );
	}

	/**
	 * Optionally replace the main post content with a blueprint page.
	 *
	 * Useful when a theme page template is used instead of our full template.
	 * Only runs on the main query in the loop for singular pages matching a slug.
	 *
	 * @since 1.0.0
	 * @param string $content Post content.
	 * @return string
	 */
	public function maybe_filter_the_content( $content ) {
		if ( is_admin() || ! in_the_loop() || ! is_main_query() ) {
			return $content;
		}

		if ( ! $this->is_enabled() || ! $this->has_blueprint() ) {
			return $content;
		}

		// Full template path already outputs the site — do not double-render.
		if ( $this->is_promptweb_request() ) {
			return $content;
		}

		/**
		 * Filters whether the_content is replaced by a blueprint page for matching WP pages.
		 *
		 * @since 1.0.0
		 * @param bool $replace Default false (prefer template_include takeover).
		 */
		if ( ! apply_filters( 'promptweb_replace_the_content', false ) ) {
			return $content;
		}

		if ( ! is_singular( 'page' ) ) {
			return $content;
		}

		$post = get_post();
		if ( ! $post instanceof WP_Post ) {
			return $content;
		}

		$slug = $post->post_name;
		if ( ! $this->blueprint_has_slug( $slug ) ) {
			return $content;
		}

		$this->enqueue_public_assets_now();
		$html = $this->render_page( $slug );

		return is_string( $html ) && '' !== $html ? $html : $content;
	}

	/**
	 * Whether PromptWeb front rendering is active for this site.
	 *
	 * True when "Enable PromptWeb" is on, or when design pages / blueprint exist
	 * (so Sync/Initialize immediately produces a public site). Filter can force off.
	 *
	 * @since 1.0.0
	 * @return bool
	 */
	public function is_enabled() {
		$settings = PromptWeb_Settings::get_runtime_settings();
		$flag     = ! empty( $settings['enabled'] );

		$blueprint     = PromptWeb_Settings::get_blueprint();
		$has_blueprint = ! empty( $blueprint['pages'] ) && is_array( $blueprint['pages'] );
		$has_design    = $this->has_design_pages();

		// Design pages or legacy blueprint is enough to render the public website.
		$enabled = $flag || $has_blueprint || $has_design;

		/**
		 * Filters whether frontend rendering is enabled.
		 *
		 * @since 1.0.0
		 * @param bool $enabled       Effective enabled state.
		 * @param bool $flag          Explicit settings checkbox.
		 * @param bool $has_blueprint Whether stored blueprint has pages.
		 */
		return (bool) apply_filters( 'promptweb_frontend_enabled', $enabled, $flag, $has_blueprint || $has_design );
	}

	/**
	 * Whether v2 design pages (static/dynamic) are available.
	 *
	 * @since 2.0.0
	 * @return bool
	 */
	public function has_design_pages() {
		if ( ! class_exists( 'PromptWeb_Pages' ) ) {
			return false;
		}
		$pages = function_exists( 'promptweb' ) && isset( promptweb()->pages )
			? promptweb()->pages
			: new PromptWeb_Pages();
		return $pages->has_pages();
	}

	/**
	 * Pages manager instance.
	 *
	 * @since 2.0.0
	 * @return PromptWeb_Pages|null
	 */
	protected function pages_manager() {
		if ( ! class_exists( 'PromptWeb_Pages' ) ) {
			return null;
		}
		if ( function_exists( 'promptweb' ) && isset( promptweb()->pages ) && promptweb()->pages instanceof PromptWeb_Pages ) {
			return promptweb()->pages;
		}
		return new PromptWeb_Pages();
	}

	/**
	 * Stored blueprint for the current site (Multisite-aware).
	 *
	 * @since 1.0.0
	 * @return array
	 */
	public function get_blueprint() {
		$blueprint = PromptWeb_Settings::get_blueprint();

		/**
		 * Filters the blueprint used for frontend rendering.
		 *
		 * @since 1.0.0
		 * @param array $blueprint Blueprint payload.
		 */
		$blueprint = apply_filters( 'promptweb_frontend_blueprint', $blueprint );

		return is_array( $blueprint ) ? $blueprint : array();
	}

	/**
	 * Whether a usable blueprint or design pages are available.
	 *
	 * @since 1.0.0
	 * @return bool
	 */
	public function has_blueprint() {
		if ( $this->has_design_pages() ) {
			return true;
		}
		$blueprint = $this->get_blueprint();
		return ! empty( $blueprint['pages'] ) && is_array( $blueprint['pages'] );
	}

	/**
	 * Register rewrite rules.
	 *
	 * Primary public URLs are clean root paths (/{slug}/) via request mapping.
	 * /promptweb/{slug}/ is registered only so it can be detected and 301'd
	 * to the canonical clean URL (not for primary linking).
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public function register_rewrites() {
		add_rewrite_tag( '%' . self::QUERY_VAR . '%', '([^&]+)' );

		// Legacy path (redirects to /{slug}/ via maybe_redirect_legacy_urls).
		add_rewrite_rule(
			'^' . self::REWRITE_BASE . '/([^/]+)/?$',
			'index.php?' . self::QUERY_VAR . '=$matches[1]',
			'top'
		);

		/**
		 * Fires after PromptWeb rewrite rules are registered.
		 * Call flush_rewrite_rules() after changing rules (activation / version bump).
		 *
		 * @since 1.0.0
		 */
		do_action( 'promptweb_register_rewrites' );
	}

	/**
	 * Register public query vars.
	 *
	 * Query var is used internally after root mapping, and for legacy
	 * ?promptweb_page={slug} requests (which 301 to /{slug}/).
	 *
	 * @since 1.0.0
	 * @param string[] $vars Query vars.
	 * @return string[]
	 */
	public function register_query_vars( $vars ) {
		$vars[] = self::QUERY_VAR;
		return $vars;
	}

	/**
	 * 301-redirect legacy public URLs to the single clean format.
	 *
	 * - /promptweb/{slug}/     → /{slug}/  (or / if front page)
	 * - ?promptweb_page={slug} → /{slug}/  (or / if front page)
	 *
	 * Does not redirect clean /{slug}/ requests (even when mapped internally
	 * to the query var). Does not send home incorrectly to /home/.
	 *
	 * Multisite: uses home_url() for the current blog.
	 *
	 * @since 2.0.1
	 * @return void
	 */
	public function maybe_redirect_legacy_urls() {
		if ( is_admin() || wp_doing_ajax() || wp_doing_cron() ) {
			return;
		}
		if ( ( defined( 'REST_REQUEST' ) && REST_REQUEST ) || ( defined( 'WP_CLI' ) && WP_CLI ) ) {
			return;
		}
		if ( is_feed() || is_robots() || is_trackback() ) {
			return;
		}
		if ( ! $this->is_enabled() || ! $this->has_blueprint() ) {
			return;
		}

		/**
		 * Filters whether legacy PromptWeb URLs are 301'd to clean /{slug}/.
		 *
		 * @since 2.0.1
		 * @param bool $allow Default true.
		 */
		if ( ! apply_filters( 'promptweb_redirect_legacy_urls', true ) ) {
			return;
		}

		$slug_from_path  = $this->get_legacy_namespaced_path_slug();
		$slug_from_query = $this->get_legacy_query_slug();

		// Prefer explicit path legacy format; otherwise query-string legacy.
		$slug = '';
		if ( '' !== $slug_from_path ) {
			$slug = $slug_from_path;
		} elseif ( '' !== $slug_from_query ) {
			$slug = $slug_from_query;
		}

		if ( '' === $slug ) {
			return;
		}

		// Only redirect when this slug is a known design/blueprint page
		// (including drafts — admins still land on clean URL; public still gated).
		if ( ! $this->is_known_design_slug( $slug ) ) {
			return;
		}

		$target = $this->get_canonical_public_url( $slug );

		/**
		 * Filters the 301 target for a legacy PromptWeb URL.
		 *
		 * @since 2.0.1
		 * @param string $target Canonical URL.
		 * @param string $slug   Page slug.
		 */
		$target = (string) apply_filters( 'promptweb_legacy_redirect_target', $target, $slug );

		if ( '' === $target ) {
			return;
		}

		// Avoid redirect loops (already on clean URL with no legacy query).
		if ( $this->is_current_request_url( $target ) ) {
			return;
		}

		wp_safe_redirect( $target, 301 );
		exit;
	}

	/**
	 * Detect /promptweb/{slug}/ from the request path (Multisite subdirectory-safe).
	 *
	 * @since 2.0.1
	 * @return string Slug or empty.
	 */
	protected function get_legacy_namespaced_path_slug() {
		if ( empty( $_SERVER['REQUEST_URI'] ) ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
			return '';
		}

		$uri  = wp_unslash( $_SERVER['REQUEST_URI'] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$path = wp_parse_url( $uri, PHP_URL_PATH );
		if ( ! is_string( $path ) || '' === $path ) {
			return '';
		}

		// Strip site path prefix (subdirectory Multisite / installs).
		$home_path = wp_parse_url( home_url( '/' ), PHP_URL_PATH );
		if ( is_string( $home_path ) && '/' !== $home_path && 0 === strpos( $path, $home_path ) ) {
			$path = substr( $path, strlen( untrailingslashit( $home_path ) ) );
			if ( ! is_string( $path ) || '' === $path ) {
				$path = '/';
			}
		}

		$path = trim( $path, '/' );
		if ( '' === $path ) {
			return '';
		}

		$prefix = self::REWRITE_BASE . '/';
		if ( 0 !== strpos( $path, $prefix ) ) {
			// Exact /promptweb with no slug — not a page redirect.
			return '';
		}

		$rest = substr( $path, strlen( $prefix ) );
		if ( ! is_string( $rest ) || '' === $rest || false !== strpos( $rest, '/' ) ) {
			return '';
		}

		return sanitize_title( $rest );
	}

	/**
	 * Detect legacy ?promptweb_page={slug} from the raw query string.
	 *
	 * Only redirects when the query param is present in $_GET (not when the
	 * clean path was mapped internally to the same query var). Also used to
	 * strip leftover ?promptweb_page= from clean paths.
	 *
	 * @since 2.0.1
	 * @return string Slug or empty.
	 */
	protected function get_legacy_query_slug() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( empty( $_GET[ self::QUERY_VAR ] ) ) {
			return '';
		}

		// If path is already /promptweb/{slug}/, path handler owns the redirect.
		if ( '' !== $this->get_legacy_namespaced_path_slug() ) {
			return '';
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$slug = sanitize_title( wp_unslash( (string) $_GET[ self::QUERY_VAR ] ) );

		return $slug;
	}

	/**
	 * Whether a slug is registered as a design or blueprint page (any status).
	 *
	 * Unlike blueprint_has_slug(), this includes drafts so legacy draft URLs
	 * still canonicalize to the clean path (visibility checked at render time).
	 *
	 * @since 2.0.1
	 * @param string $slug Page slug.
	 * @return bool
	 */
	public function is_known_design_slug( $slug ) {
		$slug = sanitize_title( $slug );
		if ( '' === $slug ) {
			return false;
		}

		$pages = $this->pages_manager();
		if ( $pages instanceof PromptWeb_Pages ) {
			$meta = $pages->get_page_meta( $slug );
			if ( $meta ) {
				return true;
			}
		}

		foreach ( $this->get_blueprint_pages() as $page ) {
			if ( ! is_array( $page ) ) {
				continue;
			}
			$page_slug = isset( $page['slug'] ) ? sanitize_title( (string) $page['slug'] ) : '';
			if ( $page_slug === $slug ) {
				return true;
			}
			$id = isset( $page['id'] ) ? sanitize_title( (string) $page['id'] ) : '';
			if ( $id === $slug ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Canonical public URL for a design page slug.
	 *
	 * Front page → home_url( '/' )
	 * Other pages → home_url( '/{slug}/' )
	 *
	 * @since 2.0.1
	 * @param string $slug Page slug.
	 * @return string
	 */
	public function get_canonical_public_url( $slug ) {
		$slug = sanitize_title( $slug );
		if ( '' === $slug ) {
			return home_url( '/' );
		}

		if ( $this->is_front_page_slug( $slug ) ) {
			return home_url( '/' );
		}

		return home_url( user_trailingslashit( $slug ) );
	}

	/**
	 * Whether this slug is the design front page.
	 *
	 * @since 2.0.1
	 * @param string $slug Page slug.
	 * @return bool
	 */
	public function is_front_page_slug( $slug ) {
		$slug = sanitize_title( $slug );
		if ( '' === $slug ) {
			return false;
		}

		try {
			$pages = $this->pages_manager();
			if ( $pages instanceof PromptWeb_Pages ) {
				// Use raw row — avoids normalize_page_meta → public_url recursion.
				$raw = method_exists( $pages, 'get_raw_page_row' )
					? $pages->get_raw_page_row( $slug )
					: null;
				if ( is_array( $raw ) && ! empty( $raw['is_front_page'] ) ) {
					return true;
				}
				// Fallback: scan raw manifest pages.
				$manifest = $pages->get_manifest();
				if ( is_array( $manifest ) && ! empty( $manifest['pages'] ) && is_array( $manifest['pages'] ) ) {
					foreach ( $manifest['pages'] as $page ) {
						if ( ! is_array( $page ) || empty( $page['is_front_page'] ) ) {
							continue;
						}
						$page_slug = isset( $page['slug'] ) ? sanitize_title( (string) $page['slug'] ) : '';
						if ( $page_slug === $slug ) {
							return true;
						}
					}
				}
			}

			foreach ( $this->get_blueprint_pages() as $page ) {
				if ( ! is_array( $page ) ) {
					continue;
				}
				$page_slug = isset( $page['slug'] ) ? sanitize_title( (string) $page['slug'] ) : '';
				$id        = isset( $page['id'] ) ? sanitize_title( (string) $page['id'] ) : '';
				if ( ( $page_slug === $slug || $id === $slug ) && ! empty( $page['is_front_page'] ) ) {
					return true;
				}
			}
		} catch ( Exception $e ) {
			return false;
		} catch ( Throwable $e ) { // phpcs:ignore PHPCompatibility.FunctionUse.NewFunctions.throwableFound
			return false;
		}

		return false;
	}

	/**
	 * Whether the current request already matches the given absolute URL (path + host).
	 *
	 * @since 2.0.1
	 * @param string $url Absolute URL.
	 * @return bool
	 */
	protected function is_current_request_url( $url ) {
		if ( empty( $_SERVER['REQUEST_URI'] ) ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
			return false;
		}

		$current_uri  = wp_unslash( $_SERVER['REQUEST_URI'] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$current_path = wp_parse_url( $current_uri, PHP_URL_PATH );
		$target_path  = wp_parse_url( $url, PHP_URL_PATH );

		if ( ! is_string( $current_path ) || ! is_string( $target_path ) ) {
			return false;
		}

		// Compare normalized trailing-slash paths.
		$current_path = user_trailingslashit( $current_path );
		$target_path  = user_trailingslashit( $target_path );

		// Strip home path prefix differences on subdirectory installs for current path.
		$home_path = wp_parse_url( home_url( '/' ), PHP_URL_PATH );
		if ( is_string( $home_path ) && '/' !== $home_path ) {
			$home_path = user_trailingslashit( $home_path );
			// Both should already include home path when using home_url targets.
		}

		// Also require no legacy query param remaining when paths match.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$has_legacy_q = ! empty( $_GET[ self::QUERY_VAR ] );

		if ( untrailingslashit( $current_path ) === untrailingslashit( $target_path ) && ! $has_legacy_q ) {
			return true;
		}

		return false;
	}

	/**
	 * Map clean root paths /{slug}/ to PromptWeb pages when safe.
	 *
	 * Primary public format: /about/, /services/, etc.
	 * Does not hijack real WP pages/posts/attachments or reserved paths.
	 * Draft visibility is enforced later via blueprint_has_slug / can_view_page.
	 *
	 * @since 1.0.0
	 * @param array $query_vars Parsed request vars.
	 * @return array
	 */
	public function maybe_map_root_slug( $query_vars ) {
		if ( is_admin() || ! $this->is_enabled() || ! $this->has_blueprint() ) {
			return $query_vars;
		}

		// Already an explicit PromptWeb request (/promptweb/{slug}/ or ?promptweb_page=).
		if ( ! empty( $query_vars[ self::QUERY_VAR ] ) ) {
			return $query_vars;
		}

		/**
		 * Filters whether root-level slug mapping is allowed (clean /{slug}/ URLs).
		 *
		 * @since 1.0.0
		 * @param bool $allow Default true.
		 */
		if ( ! apply_filters( 'promptweb_allow_root_slug_mapping', true ) ) {
			return $query_vars;
		}

		// Do not claim attachment / feed / archive-style queries.
		if ( ! empty( $query_vars['attachment'] ) || ! empty( $query_vars['attachment_id'] )
			|| ! empty( $query_vars['feed'] ) || ! empty( $query_vars['sitemap'] )
			|| ! empty( $query_vars['rest_route'] ) ) {
			return $query_vars;
		}

		// Explicit post/page IDs always win.
		if ( ! empty( $query_vars['p'] ) || ! empty( $query_vars['page_id'] ) ) {
			return $query_vars;
		}

		$slug = '';

		// Prefer pagename (hierarchical pages), then post name, then path parse.
		if ( ! empty( $query_vars['pagename'] ) ) {
			// Only single-segment paths: "about" not "parent/child".
			$raw = (string) $query_vars['pagename'];
			if ( false === strpos( $raw, '/' ) ) {
				$slug = sanitize_title( $raw );
			}
		} elseif ( ! empty( $query_vars['name'] ) && empty( $query_vars['post_type'] ) ) {
			$slug = sanitize_title( (string) $query_vars['name'] );
		} else {
			$slug = $this->guess_root_slug_from_request();
		}

		if ( '' === $slug || $this->is_reserved_root_slug( $slug ) ) {
			return $query_vars;
		}

		// Only map when a design/blueprint page owns this slug AND WP does not.
		if ( ! $this->blueprint_has_slug( $slug ) ) {
			return $query_vars;
		}
		if ( $this->wp_content_exists_for_slug( $slug ) ) {
			return $query_vars;
		}

		$query_vars[ self::QUERY_VAR ] = $slug;
		unset( $query_vars['pagename'], $query_vars['page'], $query_vars['name'], $query_vars['error'] );

		return $query_vars;
	}

	/**
	 * Guess a single root path segment from REQUEST_URI (last resort).
	 *
	 * @since 2.0.0
	 * @return string Empty when not a simple /{slug}/ request.
	 */
	protected function guess_root_slug_from_request() {
		if ( empty( $_SERVER['REQUEST_URI'] ) ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
			return '';
		}

		$uri  = wp_unslash( $_SERVER['REQUEST_URI'] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$path = wp_parse_url( $uri, PHP_URL_PATH );
		if ( ! is_string( $path ) || '' === $path || '/' === $path ) {
			return '';
		}

		// Strip site path prefix on subdirectory Multisite / installs.
		$home_path = wp_parse_url( home_url( '/' ), PHP_URL_PATH );
		if ( is_string( $home_path ) && '/' !== $home_path && 0 === strpos( $path, $home_path ) ) {
			$path = substr( $path, strlen( untrailingslashit( $home_path ) ) );
			if ( ! is_string( $path ) || '' === $path ) {
				$path = '/';
			}
		}

		$path = trim( $path, '/' );
		if ( '' === $path || false !== strpos( $path, '/' ) ) {
			return '';
		}

		// Never treat the namespaced base itself as a page slug.
		if ( self::REWRITE_BASE === $path ) {
			return '';
		}

		return sanitize_title( $path );
	}

	/**
	 * Reserved first path segments that must never map to design pages.
	 *
	 * @since 2.0.0
	 * @param string $slug Candidate slug.
	 * @return bool
	 */
	protected function is_reserved_root_slug( $slug ) {
		$slug = sanitize_title( $slug );
		$reserved = array(
			'wp-admin',
			'wp-content',
			'wp-includes',
			'wp-json',
			'wp-login',
			'wp-cron',
			'feed',
			'rdf',
			'rss',
			'rss2',
			'atom',
			'embed',
			'xmlrpc',
			'favicon.ico',
			'robots.txt',
			'sitemap',
			'sitemap_index',
			self::REWRITE_BASE,
		);

		/**
		 * Filters reserved root slugs that PromptWeb will not claim.
		 *
		 * @since 2.0.0
		 * @param string[] $reserved Reserved slugs.
		 */
		$reserved = apply_filters( 'promptweb_reserved_root_slugs', $reserved );

		return in_array( $slug, (array) $reserved, true );
	}

	/**
	 * Detect PromptWeb front-page / query-var requests early.
	 *
	 * @since 1.0.0
	 * @param WP_Query $query Main query.
	 * @return void
	 */
	public function maybe_flag_request( $query ) {
		if ( is_admin() || ! $query->is_main_query() ) {
			return;
		}

		if ( ! $this->is_enabled() || ! $this->has_blueprint() ) {
			return;
		}

		$slug = get_query_var( self::QUERY_VAR );
		if ( is_string( $slug ) && '' !== $slug ) {
			$this->is_promptweb_request = true;
			$this->current_slug         = sanitize_title( $slug );
			$query->is_home             = false;
			$query->is_front_page       = false;
			$query->is_page             = true;
			$query->is_singular         = true;
			$query->is_404              = false;
			return;
		}

		// Front page takeover when viewing the site front.
		if ( $query->is_home() || $query->is_front_page() ) {
			/**
			 * Filters whether the front page is rendered from the blueprint.
			 *
			 * @since 1.0.0
			 * @param bool $takeover Default true when enabled + blueprint present.
			 */
			$takeover = (bool) apply_filters( 'promptweb_front_page_takeover', true );
			if ( $takeover ) {
				$this->is_promptweb_request = true;
				$this->current_slug         = null; // Renderer resolves front / first page.
				$query->is_404              = false;
			}
		}
	}

	/**
	 * Whether design pages or the blueprint contain a page with this slug.
	 *
	 * @since 1.0.0
	 * @param string $slug Page slug.
	 * @return bool
	 */
	public function blueprint_has_slug( $slug ) {
		$slug  = sanitize_title( $slug );
		$pages = $this->pages_manager();
		if ( $pages instanceof PromptWeb_Pages ) {
			$meta = $pages->get_page_meta( $slug );
			if ( $meta && $pages->can_view_page( $meta ) ) {
				return true;
			}
			// Draft exists but viewer cannot see it — treat as missing for public.
			if ( $meta && ! $pages->can_view_page( $meta ) ) {
				return false;
			}
		}

		foreach ( $this->get_blueprint_pages() as $page ) {
			$page_slug = isset( $page['slug'] ) ? sanitize_title( (string) $page['slug'] ) : '';
			if ( $page_slug === $slug ) {
				return true;
			}
			$id = isset( $page['id'] ) ? sanitize_title( (string) $page['id'] ) : '';
			if ( $id === $slug ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Blueprint pages list.
	 *
	 * @since 1.0.0
	 * @return array
	 */
	public function get_blueprint_pages() {
		$blueprint = $this->get_blueprint();
		return ( ! empty( $blueprint['pages'] ) && is_array( $blueprint['pages'] ) ) ? $blueprint['pages'] : array();
	}

	/**
	 * Whether a native WP page/post already owns this slug.
	 *
	 * @since 1.0.0
	 * @param string $slug Slug.
	 * @return bool
	 */
	protected function wp_content_exists_for_slug( $slug ) {
		// Prefer pages, then posts (avoid array $post_type for older WP edge cases).
		$page = get_page_by_path( $slug, OBJECT, 'page' );
		if ( $page instanceof WP_Post ) {
			return true;
		}
		$post = get_page_by_path( $slug, OBJECT, 'post' );
		return $post instanceof WP_Post;
	}

	/**
	 * Whether the current request should use the PromptWeb template.
	 *
	 * @since 1.0.0
	 * @return bool
	 */
	public function is_promptweb_request() {
		if ( $this->is_promptweb_request ) {
			return true;
		}

		$slug = get_query_var( self::QUERY_VAR );
		return is_string( $slug ) && '' !== $slug;
	}

	/**
	 * Current blueprint page slug for this request (null = front/default).
	 *
	 * @since 1.0.0
	 * @return string|null
	 */
	public function get_current_slug() {
		if ( null !== $this->current_slug && '' !== $this->current_slug ) {
			return $this->current_slug;
		}

		$slug = get_query_var( self::QUERY_VAR );
		if ( is_string( $slug ) && '' !== $slug ) {
			return sanitize_title( $slug );
		}

		return null;
	}

	/**
	 * Swap in the PromptWeb page template when appropriate.
	 *
	 * Static full-document pages may short-circuit and serve the HTML file directly.
	 * Dynamic PHP pages load as a WordPress template include.
	 *
	 * @since 1.0.0
	 * @param string $template Template path.
	 * @return string
	 */
	public function maybe_template_include( $template ) {
		if ( is_admin() || ! $this->is_enabled() || ! $this->has_blueprint() ) {
			return $template;
		}

		if ( ! $this->is_promptweb_request() ) {
			// Front page may not have been flagged yet on some themes/setups.
			if ( is_front_page() || is_home() ) {
				if ( ! apply_filters( 'promptweb_front_page_takeover', true ) ) {
					return $template;
				}
				$this->is_promptweb_request = true;
				$this->current_slug         = null;
			} else {
				return $template;
			}
		}

		// Resolve design page meta for this request.
		$slug = $this->get_current_slug();
		$meta = $this->resolve_design_page_meta( $slug );

		// 404 if explicit slug requested but not viewable.
		if ( null !== $slug && '' !== $slug && ! $this->blueprint_has_slug( $slug ) && ! $meta ) {
			global $wp_query;
			if ( $wp_query instanceof WP_Query ) {
				$wp_query->set_404();
				status_header( 404 );
				nocache_headers();
			}
			return $template;
		}

		// Dynamic PHP: use dedicated template that includes the PHP file in WP context.
		if ( is_array( $meta ) && 'dynamic' === $meta['type'] ) {
			status_header( 200 );
			$dyn = PROMPTWEB_PLUGIN_DIR . 'templates/dynamic-page.php';
			/**
			 * Filters the template path for dynamic design pages.
			 *
			 * @since 2.0.0
			 * @param string $dyn  Path.
			 * @param array  $meta Page meta.
			 */
			$dyn = apply_filters( 'promptweb_dynamic_page_template', $dyn, $meta );
			if ( is_string( $dyn ) && file_exists( $dyn ) ) {
				// Stash meta for the template.
				$GLOBALS['promptweb_current_page_meta'] = $meta;
				return $dyn;
			}
		}

		// Static full-document HTML: serve via static template (may output raw HTML).
		if ( is_array( $meta ) && 'static' === $meta['type'] ) {
			status_header( 200 );
			$static = PROMPTWEB_PLUGIN_DIR . 'templates/static-page.php';
			/**
			 * Filters the template path for static design pages.
			 *
			 * @since 2.0.0
			 * @param string $static Path.
			 * @param array  $meta   Page meta.
			 */
			$static = apply_filters( 'promptweb_static_page_template', $static, $meta );
			if ( is_string( $static ) && file_exists( $static ) ) {
				$GLOBALS['promptweb_current_page_meta'] = $meta;
				return $static;
			}
		}

		// Legacy blueprint JSON path.
		status_header( 200 );

		$path = PROMPTWEB_PLUGIN_DIR . 'templates/frontend-page.php';

		/**
		 * Filters the template path used for PromptWeb frontend pages.
		 *
		 * @since 1.0.0
		 * @param string             $path     Absolute path.
		 * @param string|null        $slug     Page slug or null for front.
		 * @param PromptWeb_Frontend $frontend This instance.
		 */
		$path = apply_filters( 'promptweb_frontend_template', $path, $slug, $this );

		if ( is_string( $path ) && file_exists( $path ) ) {
			return $path;
		}

		return $template;
	}

	/**
	 * Resolve design page meta for a request slug (null = front page).
	 *
	 * @since 2.0.0
	 * @param string|null $slug Page slug.
	 * @return array|null
	 */
	public function resolve_design_page_meta( $slug = null ) {
		$pages = $this->pages_manager();
		if ( ! $pages instanceof PromptWeb_Pages || ! $pages->has_pages() ) {
			return null;
		}

		if ( null === $slug || '' === $slug ) {
			$meta = $pages->get_front_page_meta( true );
		} else {
			$meta = $pages->get_page_meta( $slug );
		}

		if ( ! is_array( $meta ) ) {
			return null;
		}

		if ( ! $pages->can_view_page( $meta ) ) {
			return null;
		}

		return $meta;
	}

	/**
	 * Render HTML for the current (or given) page.
	 *
	 * Prefers v2 design pages (static/dynamic); falls back to legacy JSON renderer.
	 *
	 * @since 1.0.0
	 * @param string|null $slug Page slug; null = front/default.
	 * @return string
	 */
	public function render_page( $slug = null ) {
		if ( null === $slug ) {
			$slug = $this->get_current_slug();
		}

		// v2 design pages.
		$meta = $this->resolve_design_page_meta( $slug );
		if ( is_array( $meta ) ) {
			$html = $this->render_design_page( $meta );
			/**
			 * Filters final design page HTML.
			 *
			 * @since 2.0.0
			 * @param string             $html     HTML.
			 * @param array              $meta     Page meta.
			 * @param PromptWeb_Frontend $frontend This instance.
			 */
			return (string) apply_filters( 'promptweb_frontend_design_page_html', $html, $meta, $this );
		}

		// Legacy JSON blueprint.
		$renderer = function_exists( 'promptweb' ) ? promptweb()->renderer : null;
		if ( ! $renderer instanceof PromptWeb_Renderer ) {
			$renderer = new PromptWeb_Renderer();
		}

		$blueprint = $this->get_blueprint();
		$html      = $renderer->render( $blueprint, $slug );

		// Shortcode / embed path: inject design tokens once per request if template didn't.
		static $tokens_injected = false;
		if ( ! $tokens_injected && ! $this->is_promptweb_request() ) {
			$style = $renderer->design_tokens_style_tag();
			if ( $style ) {
				$html = $style . "\n" . $html;
			}
			$tokens_injected = true;
		}

		/**
		 * Filters final page HTML after Renderer output.
		 *
		 * @since 1.0.0
		 * @param string             $html      Page HTML.
		 * @param string|null        $slug      Slug.
		 * @param array              $blueprint Blueprint.
		 * @param PromptWeb_Frontend $frontend  This instance.
		 */
		return (string) apply_filters( 'promptweb_frontend_page_html', $html, $slug, $blueprint, $this );
	}

	/**
	 * Render a v2 design page (static file contents or dynamic include capture).
	 *
	 * @since 2.0.0
	 * @param array $meta Page meta.
	 * @return string
	 */
	public function render_design_page( array $meta ) {
		$pages = $this->pages_manager();
		if ( ! $pages instanceof PromptWeb_Pages ) {
			return '';
		}

		$path = $pages->get_page_file_path( $meta );
		if ( ! is_readable( $path ) ) {
			return '';
		}

		if ( 'dynamic' === $meta['type'] ) {
			// Capture output of PHP template in WordPress context.
			ob_start();
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_include
			include $path;
			return (string) ob_get_clean();
		}

		// Static HTML.
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$html = file_get_contents( $path );
		return is_string( $html ) ? $html : '';
	}

	/**
	 * Public assets for rendered pages (light base styles).
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public function enqueue_public_assets() {
		if ( is_admin() || ! $this->is_enabled() ) {
			return;
		}

		// Load on PromptWeb routes, front takeover, or when shortcode may appear.
		$load = $this->is_promptweb_request() || is_front_page() || is_home();

		/**
		 * Filters whether public PromptWeb CSS is enqueued.
		 *
		 * @since 1.0.0
		 * @param bool $load Whether to enqueue.
		 */
		if ( ! apply_filters( 'promptweb_enqueue_public_assets', $load ) ) {
			return;
		}

		$css_path = PROMPTWEB_PLUGIN_DIR . 'assets/css/promptweb-frontend.css';
		$ver      = file_exists( $css_path ) ? (string) filemtime( $css_path ) : PROMPTWEB_VERSION;

		wp_enqueue_style(
			'promptweb-frontend',
			PROMPTWEB_PLUGIN_URL . 'assets/css/promptweb-frontend.css',
			array(),
			$ver
		);
	}

	/**
	 * Shortcode: [promptweb] or [promptweb page="home"].
	 *
	 * @since 1.0.0
	 * @param array|string $atts Shortcode attributes.
	 * @return string
	 */
	public function shortcode( $atts ) {
		if ( ! $this->is_enabled() ) {
			return '';
		}

		$atts = shortcode_atts(
			array(
				'page' => '',
				'slug' => '',
			),
			$atts,
			'promptweb'
		);

		$slug = '' !== $atts['page'] ? $atts['page'] : $atts['slug'];
		$slug = '' !== $slug ? sanitize_title( $slug ) : null;

		// Ensure public CSS when shortcode is used mid-page.
		$this->enqueue_public_assets_now();

		$html = $this->render_page( $slug );

		return is_string( $html ) ? $html : '';
	}

	/**
	 * Force-enqueue public CSS (shortcode path).
	 *
	 * @since 1.0.0
	 * @return void
	 */
	protected function enqueue_public_assets_now() {
		$css_path = PROMPTWEB_PLUGIN_DIR . 'assets/css/promptweb-frontend.css';
		$ver      = file_exists( $css_path ) ? (string) filemtime( $css_path ) : PROMPTWEB_VERSION;

		wp_enqueue_style(
			'promptweb-frontend',
			PROMPTWEB_PLUGIN_URL . 'assets/css/promptweb-frontend.css',
			array(),
			$ver
		);
	}

	/**
	 * Build document title for PromptWeb pages.
	 *
	 * @since 1.0.0
	 * @param array $parts Title parts.
	 * @return array
	 */
	public function filter_document_title( $parts ) {
		if ( ! $this->is_promptweb_request() || ! $this->is_enabled() ) {
			return $parts;
		}

		$title = $this->get_page_title( $this->get_current_slug() );
		if ( $title ) {
			$parts['title'] = $title;
		}

		return $parts;
	}

	/**
	 * Older title filter fallback.
	 *
	 * @since 1.0.0
	 * @param string $title Title.
	 * @return string
	 */
	public function filter_pre_document_title( $title ) {
		if ( ! $this->is_promptweb_request() || ! $this->is_enabled() ) {
			return $title;
		}

		$page_title = $this->get_page_title( $this->get_current_slug() );
		if ( ! $page_title ) {
			return $title;
		}

		$site = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
		return $page_title . ' — ' . $site;
	}

	/**
	 * Resolve a human title for a design or blueprint page.
	 *
	 * @since 1.0.0
	 * @param string|null $slug Page slug.
	 * @return string
	 */
	public function get_page_title( $slug = null ) {
		$meta = $this->resolve_design_page_meta( $slug );
		if ( is_array( $meta ) && ! empty( $meta['title'] ) ) {
			return sanitize_text_field( (string) $meta['title'] );
		}

		$renderer = function_exists( 'promptweb' ) ? promptweb()->renderer : null;
		if ( ! $renderer instanceof PromptWeb_Renderer ) {
			$renderer = new PromptWeb_Renderer();
		}

		$blueprint = $this->get_blueprint();
		$page      = $renderer->resolve_page( $blueprint, $slug );

		if ( is_array( $page ) && ! empty( $page['title'] ) ) {
			return sanitize_text_field( (string) $page['title'] );
		}

		if ( is_array( $blueprint ) && ! empty( $blueprint['site']['title'] ) ) {
			return sanitize_text_field( (string) $blueprint['site']['title'] );
		}

		return '';
	}

	/**
	 * Public URL for a design / blueprint page slug.
	 *
	 * ALWAYS returns the single clean public format:
	 * - Front page slug → home_url( '/' )
	 * - Other slugs     → home_url( '/{slug}/' )
	 *
	 * Legacy /promptweb/{slug}/ and ?promptweb_page= are not returned for linking;
	 * those formats 301 to this canonical URL.
	 *
	 * @since 1.0.0
	 * @param string $slug Page slug.
	 * @return string
	 */
	public function get_page_url( $slug ) {
		$slug = sanitize_title( $slug );

		if ( '' === $slug ) {
			return home_url( '/' );
		}

		// Always canonical clean URL (filter kept for edge cases but defaults forced to root).
		/**
		 * Filters the URL strategy for design pages.
		 *
		 * Only "root" is supported for public links. "namespaced" is deprecated and ignored
		 * so users and AI always receive domain/{slug}/ links.
		 *
		 * @since 1.0.0
		 * @param string $strategy Default "root".
		 */
		$strategy = apply_filters( 'promptweb_page_url_strategy', self::URL_STRATEGY_DEFAULT );
		unset( $strategy ); // Namespaced public links removed; always clean.

		return $this->get_canonical_public_url( $slug );
	}

	/**
	 * Legacy namespaced URL (/promptweb/{slug}/) — not for public linking.
	 *
	 * Kept for diagnostics; production code should use get_page_url().
	 *
	 * @since 2.0.0
	 * @param string $slug Page slug.
	 * @return string
	 */
	public function get_namespaced_page_url( $slug ) {
		$slug = sanitize_title( $slug );
		if ( '' === $slug ) {
			return home_url( '/' );
		}
		return home_url( user_trailingslashit( self::REWRITE_BASE . '/' . $slug ) );
	}

	/**
	 * Flush rewrite rules (call on activation / upgrade).
	 *
	 * Multisite: call inside switch_to_blog() for each site when network-activating.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public static function flush_rewrites() {
		$instance = new self();
		$instance->register_rewrites();
		flush_rewrite_rules( false );

		// Mark rewrite version so maybe_flush_rewrites_on_upgrade() does not re-flush.
		update_option( self::REWRITE_VERSION_OPTION, self::REWRITE_VERSION, false );
	}

	/**
	 * Flush rewrites once when the rewrite version option is outdated.
	 *
	 * Safe for upgrades to root URL strategy without requiring re-activation.
	 * Multisite: per-blog option in the current site context.
	 *
	 * @since 2.0.0
	 * @return void
	 */
	public function maybe_flush_rewrites_on_upgrade() {
		if ( is_admin() && ! wp_doing_ajax() && ! ( defined( 'WP_CLI' ) && WP_CLI ) ) {
			// Still allow flush from admin when version mismatches (permalinks fix).
		}

		$stored = (string) get_option( self::REWRITE_VERSION_OPTION, '' );
		if ( self::REWRITE_VERSION === $stored ) {
			return;
		}

		// Soft lock to avoid concurrent flushes on busy sites.
		$lock_key = 'promptweb_rewrite_flush_lock';
		if ( get_transient( $lock_key ) ) {
			return;
		}
		set_transient( $lock_key, 1, 60 );

		$this->register_rewrites();
		flush_rewrite_rules( false );
		update_option( self::REWRITE_VERSION_OPTION, self::REWRITE_VERSION, false );
	}
}
