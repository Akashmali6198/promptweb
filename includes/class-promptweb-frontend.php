<?php
/**
 * Frontend page routing and rendering.
 *
 * Maximum AI Creativity:
 * - Stored blueprint JSON (from GitHub Sync / Push) is the source of truth.
 * - PromptWeb_Renderer turns pages → sections → elements into HTML.
 * - This class maps public URLs to blueprint pages and outputs a clean template.
 *
 * Routes (when PromptWeb is enabled and a blueprint exists):
 * - Front page  → page with is_front_page, else first page
 * - /{slug}/    → blueprint page matching slug (if no real WP page/post wins)
 * - /promptweb/{slug}/ → explicit plugin route (always available when enabled)
 * - Shortcode   → [promptweb] or [promptweb page="slug"]
 *
 * Multisite: blueprint + settings are read for the current blog context.
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
	 * Rewrite tag base segment.
	 *
	 * @since 1.0.0
	 * @var   string
	 */
	const REWRITE_BASE = 'promptweb';

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

		add_action( 'pre_get_posts', array( $this, 'maybe_flag_request' ) );
		add_filter( 'request', array( $this, 'maybe_map_root_slug' ) );

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
	 * True when "Enable PromptWeb" is on, or when a blueprint with pages is stored
	 * (so Sync/Initialize immediately produces a public site). Filter can force off.
	 *
	 * @since 1.0.0
	 * @return bool
	 */
	public function is_enabled() {
		$settings = PromptWeb_Settings::get_runtime_settings();
		$flag     = ! empty( $settings['enabled'] );

		$blueprint = PromptWeb_Settings::get_blueprint();
		$has_pages = ! empty( $blueprint['pages'] ) && is_array( $blueprint['pages'] );

		// JSON-first: a stored blueprint is enough to render the public website.
		$enabled = $flag || $has_pages;

		/**
		 * Filters whether frontend blueprint rendering is enabled.
		 *
		 * @since 1.0.0
		 * @param bool $enabled Effective enabled state.
		 * @param bool $flag    Explicit settings checkbox.
		 * @param bool $has_pages Whether stored blueprint has pages.
		 */
		return (bool) apply_filters( 'promptweb_frontend_enabled', $enabled, $flag, $has_pages );
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
	 * Whether a usable blueprint is available.
	 *
	 * @since 1.0.0
	 * @return bool
	 */
	public function has_blueprint() {
		$blueprint = $this->get_blueprint();
		return ! empty( $blueprint['pages'] ) && is_array( $blueprint['pages'] );
	}

	/**
	 * Register rewrite rules.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public function register_rewrites() {
		add_rewrite_tag( '%' . self::QUERY_VAR . '%', '([^&]+)' );

		// Explicit route: /promptweb/{slug}/
		add_rewrite_rule(
			'^' . self::REWRITE_BASE . '/([^/]+)/?$',
			'index.php?' . self::QUERY_VAR . '=$matches[1]',
			'top'
		);

		/**
		 * Fires after PromptWeb rewrite rules are registered.
		 * Call flush_rewrite_rules() after changing rules (activation).
		 *
		 * @since 1.0.0
		 */
		do_action( 'promptweb_register_rewrites' );
	}

	/**
	 * Register public query vars.
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
	 * Map bare first path segment to a blueprint slug when safe.
	 *
	 * Avoids hijacking real WP posts/pages/attachments and admin paths.
	 *
	 * @since 1.0.0
	 * @param array $query_vars Parsed request vars.
	 * @return array
	 */
	public function maybe_map_root_slug( $query_vars ) {
		if ( is_admin() || ! $this->is_enabled() || ! $this->has_blueprint() ) {
			return $query_vars;
		}

		// Already an explicit PromptWeb request.
		if ( ! empty( $query_vars[ self::QUERY_VAR ] ) ) {
			return $query_vars;
		}

		// Leave standard WP queries alone when they already resolve content.
		if ( ! empty( $query_vars['pagename'] ) || ! empty( $query_vars['name'] ) || ! empty( $query_vars['p'] ) || ! empty( $query_vars['page_id'] ) ) {
			// If pagename matches a blueprint slug and no WP page exists, map it.
			if ( ! empty( $query_vars['pagename'] ) ) {
				$slug = sanitize_title( $query_vars['pagename'] );
				if ( $this->blueprint_has_slug( $slug ) && ! $this->wp_content_exists_for_slug( $slug ) ) {
					$query_vars[ self::QUERY_VAR ] = $slug;
					unset( $query_vars['pagename'], $query_vars['page'], $query_vars['name'] );
				}
			}
			return $query_vars;
		}

		/**
		 * Filters whether root-level slug mapping is allowed.
		 *
		 * @since 1.0.0
		 * @param bool $allow Default true.
		 */
		if ( ! apply_filters( 'promptweb_allow_root_slug_mapping', true ) ) {
			return $query_vars;
		}

		return $query_vars;
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
	 * Whether the blueprint contains a page with this slug.
	 *
	 * @since 1.0.0
	 * @param string $slug Page slug.
	 * @return bool
	 */
	public function blueprint_has_slug( $slug ) {
		$slug = sanitize_title( $slug );
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

		// 404 if explicit slug requested but missing from blueprint.
		$slug = $this->get_current_slug();
		if ( null !== $slug && '' !== $slug && ! $this->blueprint_has_slug( $slug ) ) {
			global $wp_query;
			if ( $wp_query instanceof WP_Query ) {
				$wp_query->set_404();
				status_header( 404 );
				nocache_headers();
			}
			return $template;
		}

		$status = null !== $slug ? 200 : 200;
		status_header( $status );

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
	 * Render HTML for the current (or given) blueprint page.
	 *
	 * @since 1.0.0
	 * @param string|null $slug Page slug; null = front/default.
	 * @return string
	 */
	public function render_page( $slug = null ) {
		if ( null === $slug ) {
			$slug = $this->get_current_slug();
		}

		$renderer = function_exists( 'promptweb' ) ? promptweb()->renderer : null;
		if ( ! $renderer instanceof PromptWeb_Renderer ) {
			$renderer = new PromptWeb_Renderer();
		}

		$blueprint = $this->get_blueprint();
		$html      = $renderer->render( $blueprint, $slug );

		/**
		 * Filters final page HTML after Renderer output.
		 *
		 * @since 1.0.0
		 * @param string             $html     Page HTML.
		 * @param string|null        $slug     Slug.
		 * @param array              $blueprint Blueprint.
		 * @param PromptWeb_Frontend $frontend This instance.
		 */
		return (string) apply_filters( 'promptweb_frontend_page_html', $html, $slug, $blueprint, $this );
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
	 * Resolve a human title for a blueprint page.
	 *
	 * @since 1.0.0
	 * @param string|null $slug Page slug.
	 * @return string
	 */
	public function get_page_title( $slug = null ) {
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
	 * Public URL for a blueprint page slug.
	 *
	 * @since 1.0.0
	 * @param string $slug Page slug.
	 * @return string
	 */
	public function get_page_url( $slug ) {
		$slug = sanitize_title( $slug );

		/**
		 * Filters the URL strategy for blueprint pages.
		 *
		 * Return "root" to prefer /{slug}/, or "namespaced" for /promptweb/{slug}/.
		 *
		 * @since 1.0.0
		 * @param string $strategy Default "namespaced".
		 */
		$strategy = apply_filters( 'promptweb_page_url_strategy', 'namespaced' );

		if ( 'root' === $strategy && $slug ) {
			return home_url( user_trailingslashit( $slug ) );
		}

		if ( $slug ) {
			return home_url( user_trailingslashit( self::REWRITE_BASE . '/' . $slug ) );
		}

		return home_url( '/' );
	}

	/**
	 * Flush rewrite rules (call on activation).
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public static function flush_rewrites() {
		$instance = new self();
		$instance->register_rewrites();
		flush_rewrite_rules( false );
	}
}
