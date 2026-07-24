<?php
/**
 * MCP tools via WordPress Abilities API + optional mcp-adapter.
 *
 * Official approach:
 * 1. Register typed Abilities with wp_register_ability() (WP 6.9+ / abilities-api plugin).
 * 2. Expose them on a custom MCP server when WordPress/mcp-adapter is present.
 * 3. Always register REST mirrors under promptweb/v1/mcp/* as a Hostinger-friendly fallback.
 *
 * Tools (all require manage_options or manage_network):
 *   list_pages, get_page, create_page, update_page, publish_page,
 *   get_visual_analysis, commit_to_github
 *
 * @package PromptWeb
 * @since   2.0.0
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * PromptWeb MCP / Abilities bridge.
 *
 * @since 2.0.0
 */
class PromptWeb_MCP {

	/**
	 * Ability namespace.
	 *
	 * @since 2.0.0
	 * @var   string
	 */
	const NS = 'promptweb';

	/**
	 * Ability category slug.
	 *
	 * @since 2.0.0
	 * @var   string
	 */
	const CATEGORY = 'promptweb-design';

	/**
	 * Custom MCP server id (mcp-adapter).
	 *
	 * @since 2.0.0
	 * @var   string
	 */
	const SERVER_ID = 'promptweb-mcp-server';

	/**
	 * Bootstrap hooks.
	 *
	 * @since 2.0.0
	 * @return void
	 */
	public function init() {
		// Abilities API (core 6.9+ or plugin).
		add_action( 'wp_abilities_api_categories_init', array( $this, 'register_category' ) );
		add_action( 'wp_abilities_api_init', array( $this, 'register_abilities' ) );

		// Custom MCP server when adapter is available.
		add_action( 'mcp_adapter_init', array( $this, 'register_mcp_server' ) );

		// REST fallback tools (always available with Application Passwords / cookies).
		add_action( 'rest_api_init', array( $this, 'register_rest_routes' ) );
	}

	/**
	 * Permission: only manage_options (or manage_network on Multisite).
	 *
	 * @since 2.0.0
	 * @return bool
	 */
	public function can_manage() {
		if ( is_multisite() && current_user_can( 'manage_network' ) ) {
			return true;
		}
		return current_user_can( 'manage_options' );
	}

	/**
	 * Shared permission callback for abilities / REST.
	 *
	 * @since 2.0.0
	 * @param mixed $input Unused ability input.
	 * @return bool|WP_Error
	 */
	public function permission_callback( $input = null ) {
		unset( $input );

		if ( ! is_user_logged_in() ) {
			return new WP_Error(
				'promptweb_mcp_auth',
				__( 'Authentication required.', 'promptweb' ),
				array( 'status' => 401 )
			);
		}

		if ( ! $this->can_manage() ) {
			return new WP_Error(
				'promptweb_mcp_forbidden',
				__( 'You need manage_options (or manage_network) to use PromptWeb design tools.', 'promptweb' ),
				array( 'status' => 403 )
			);
		}

		return true;
	}

	/**
	 * Pages manager instance.
	 *
	 * @since 2.0.0
	 * @return PromptWeb_Pages
	 */
	protected function pages() {
		if ( function_exists( 'promptweb' ) && isset( promptweb()->pages ) && promptweb()->pages instanceof PromptWeb_Pages ) {
			return promptweb()->pages;
		}
		return new PromptWeb_Pages();
	}

	/**
	 * GitHub instance.
	 *
	 * @since 2.0.0
	 * @return PromptWeb_GitHub
	 */
	protected function github() {
		if ( function_exists( 'promptweb' ) && isset( promptweb()->github ) && promptweb()->github instanceof PromptWeb_GitHub ) {
			return promptweb()->github;
		}
		return new PromptWeb_GitHub();
	}

	/**
	 * Register ability category.
	 *
	 * @since 2.0.0
	 * @return void
	 */
	public function register_category() {
		if ( ! function_exists( 'wp_register_ability_category' ) ) {
			return;
		}

		wp_register_ability_category(
			self::CATEGORY,
			array(
				'label'       => __( 'PromptWeb Design', 'promptweb' ),
				'description' => __( 'High-quality static HTML and dynamic PHP page tools for AI design agents.', 'promptweb' ),
			)
		);
	}

	/**
	 * Register all Abilities (auto-exposed via mcp-adapter when public meta is set).
	 *
	 * @since 2.0.0
	 * @return void
	 */
	public function register_abilities() {
		if ( ! function_exists( 'wp_register_ability' ) ) {
			return;
		}

		$common_meta = array(
			'mcp' => array(
				'public' => true,
			),
		);

		// 1. list_pages
		wp_register_ability(
			self::NS . '/list-pages',
			array(
				'label'               => __( 'List pages', 'promptweb' ),
				'description'         => __( 'List all PromptWeb design pages (static HTML and dynamic PHP) with Draft/Publish status. Each item includes public_url (clean / or /{slug}/ only).', 'promptweb' ),
				'category'            => self::CATEGORY,
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'status' => array(
							'type'        => 'string',
							'description' => 'Filter: draft, publish, or all.',
							'default'     => 'all',
						),
						'type'   => array(
							'type'        => 'string',
							'description' => 'Filter: static, dynamic, or all.',
							'default'     => 'all',
						),
					),
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'pages' => array( 'type' => 'array' ),
						'count' => array( 'type' => 'integer' ),
					),
				),
				'execute_callback'    => array( $this, 'ability_list_pages' ),
				'permission_callback' => array( $this, 'permission_callback' ),
				'meta'                => $common_meta,
			)
		);

		// 2. get_page
		wp_register_ability(
			self::NS . '/get-page',
			array(
				'label'               => __( 'Get page', 'promptweb' ),
				'description'         => __( 'Get the current full source code and metadata of a design page by slug. Response includes public_url (clean home / or /{slug}/). Use public_url as the last line of your reply after work.', 'promptweb' ),
				'category'            => self::CATEGORY,
				'input_schema'        => array(
					'type'       => 'object',
					'required'   => array( 'slug' ),
					'properties' => array(
						'slug' => array(
							'type'        => 'string',
							'description' => 'Page slug (e.g. home, about, services).',
						),
					),
				),
				'output_schema'       => array( 'type' => 'object' ),
				'execute_callback'    => array( $this, 'ability_get_page' ),
				'permission_callback' => array( $this, 'permission_callback' ),
				'meta'                => $common_meta,
			)
		);

		// 3. create_page
		wp_register_ability(
			self::NS . '/create-page',
			array(
				'label'               => __( 'Create page', 'promptweb' ),
				'description'         => __( 'Create a new design page as Draft. Pass type static (full HTML + Tailwind CDN) or dynamic (PHP + WordPress). Response includes public_url — your last reply line after create/update/publish must be exactly that URL.', 'promptweb' ),
				'category'            => self::CATEGORY,
				'input_schema'        => array(
					'type'       => 'object',
					'required'   => array( 'slug' ),
					'properties' => array(
						'slug'             => array(
							'type'        => 'string',
							'description' => 'URL slug (e.g. home, about).',
						),
						'type'             => array(
							'type'        => 'string',
							'description' => 'static (HTML+Tailwind) or dynamic (PHP+WordPress).',
							'default'     => 'static',
						),
						'title'            => array(
							'type'        => 'string',
							'description' => 'Human-readable page title.',
						),
						'design_instructions' => array(
							'type'        => 'string',
							'description' => 'Plain-English design brief for this page.',
						),
						'code'             => array(
							'type'        => 'string',
							'description' => 'Full HTML (static) or PHP (dynamic) source. Preferred — generate beautiful production-quality code.',
						),
						'is_front_page'    => array(
							'type'        => 'boolean',
							'description' => 'Whether this page is the site front page.',
							'default'     => false,
						),
					),
				),
				'output_schema'       => array( 'type' => 'object' ),
				'execute_callback'    => array( $this, 'ability_create_page' ),
				'permission_callback' => array( $this, 'permission_callback' ),
				'meta'                => $common_meta,
			)
		);

		// 4. update_page
		wp_register_ability(
			self::NS . '/update-page',
			array(
				'label'               => __( 'Update page', 'promptweb' ),
				'description'         => __( 'Update an existing page with new HTML or PHP code and optional meta changes. Response includes public_url for the FINAL REPLY RULE.', 'promptweb' ),
				'category'            => self::CATEGORY,
				'input_schema'        => array(
					'type'       => 'object',
					'required'   => array( 'slug' ),
					'properties' => array(
						'slug'                => array( 'type' => 'string' ),
						'code'                => array(
							'type'        => 'string',
							'description' => 'Replacement full source code.',
						),
						'title'               => array( 'type' => 'string' ),
						'design_instructions' => array( 'type' => 'string' ),
						'is_front_page'       => array( 'type' => 'boolean' ),
					),
				),
				'output_schema'       => array( 'type' => 'object' ),
				'execute_callback'    => array( $this, 'ability_update_page' ),
				'permission_callback' => array( $this, 'permission_callback' ),
				'meta'                => $common_meta,
			)
		);

		// 5. publish_page
		wp_register_ability(
			self::NS . '/publish-page',
			array(
				'label'               => __( 'Publish page', 'promptweb' ),
				'description'         => __( 'Change page status from Draft to Publish so visitors can see it. Response includes public_url — end your reply with that exact clean URL.', 'promptweb' ),
				'category'            => self::CATEGORY,
				'input_schema'        => array(
					'type'       => 'object',
					'required'   => array( 'slug' ),
					'properties' => array(
						'slug' => array( 'type' => 'string' ),
					),
				),
				'output_schema'       => array( 'type' => 'object' ),
				'execute_callback'    => array( $this, 'ability_publish_page' ),
				'permission_callback' => array( $this, 'permission_callback' ),
				'meta'                => $common_meta,
			)
		);

		// 6. get_visual_analysis
		wp_register_ability(
			self::NS . '/get-visual-analysis',
			array(
				'label'               => __( 'Get visual analysis', 'promptweb' ),
				'description'         => __( 'Analyze the current design of a page: layout, spacing, visual hierarchy, accessibility, and improvement suggestions. Use after create/update to self-improve design quality.', 'promptweb' ),
				'category'            => self::CATEGORY,
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'slug' => array(
							'type'        => 'string',
							'description' => 'Page slug to analyze.',
						),
						'code' => array(
							'type'        => 'string',
							'description' => 'Optional raw code to analyze instead of stored page.',
						),
					),
				),
				'output_schema'       => array( 'type' => 'object' ),
				'execute_callback'    => array( $this, 'ability_get_visual_analysis' ),
				'permission_callback' => array( $this, 'permission_callback' ),
				'meta'                => $common_meta,
			)
		);

		// 7. commit_to_github
		wp_register_ability(
			self::NS . '/commit-to-github',
			array(
				'label'               => __( 'Commit to GitHub', 'promptweb' ),
				'description'         => __( 'Commit and push all local design page changes (manifest + static/dynamic files) to the connected design repository.', 'promptweb' ),
				'category'            => self::CATEGORY,
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'message' => array(
							'type'        => 'string',
							'description' => 'Optional Git commit message.',
						),
					),
				),
				'output_schema'       => array( 'type' => 'object' ),
				'execute_callback'    => array( $this, 'ability_commit_to_github' ),
				'permission_callback' => array( $this, 'permission_callback' ),
				'meta'                => $common_meta,
			)
		);
	}

	/**
	 * Ability IDs exposed as MCP tools.
	 *
	 * @since 2.0.0
	 * @return string[]
	 */
	public function get_ability_ids() {
		return array(
			self::NS . '/list-pages',
			self::NS . '/get-page',
			self::NS . '/create-page',
			self::NS . '/update-page',
			self::NS . '/publish-page',
			self::NS . '/get-visual-analysis',
			self::NS . '/commit-to-github',
		);
	}

	/**
	 * Register custom MCP server (official mcp-adapter).
	 *
	 * @since 2.0.0
	 * @param object $adapter McpAdapter instance (may be unused; we re-fetch singleton).
	 * @return void
	 */
	public function register_mcp_server( $adapter = null ) {
		unset( $adapter );

		if ( ! class_exists( '\WP\MCP\Core\McpAdapter' ) ) {
			return;
		}

		try {
			$mcp = \WP\MCP\Core\McpAdapter::instance();
			if ( ! is_object( $mcp ) || ! method_exists( $mcp, 'create_server' ) ) {
				return;
			}

			$transports = array();
			if ( class_exists( '\WP\MCP\Transport\HttpTransport' ) ) {
				$transports[] = \WP\MCP\Transport\HttpTransport::class;
			}

			$error_handler = class_exists( '\WP\MCP\Infrastructure\ErrorHandling\ErrorLogMcpErrorHandler' )
				? \WP\MCP\Infrastructure\ErrorHandling\ErrorLogMcpErrorHandler::class
				: null;
			$obs_handler   = class_exists( '\WP\MCP\Infrastructure\Observability\NullMcpObservabilityHandler' )
				? \WP\MCP\Infrastructure\Observability\NullMcpObservabilityHandler::class
				: null;

			if ( empty( $transports ) || ! $error_handler || ! $obs_handler ) {
				return;
			}

			$mcp->create_server(
				self::SERVER_ID,
				'promptweb-mcp',
				'mcp',
				'PromptWeb Design MCP',
				__( 'High-quality design page tools: static HTML (Tailwind) + dynamic PHP, visual analysis, and GitHub commit.', 'promptweb' ),
				defined( 'PROMPTWEB_VERSION' ) ? 'v' . PROMPTWEB_VERSION : 'v2.0.0',
				$transports,
				$error_handler,
				$obs_handler,
				$this->get_ability_ids(),
				array(),
				array()
			);
		} catch ( Exception $e ) {
			// Soft-fail: REST tools still work without adapter server.
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
				error_log( 'PromptWeb MCP server registration failed: ' . $e->getMessage() );
			}
		}
	}

	// -------------------------------------------------------------------------
	// Ability execute callbacks
	// -------------------------------------------------------------------------

	/**
	 * @since 2.0.0
	 * @param array $input Input.
	 * @return array
	 */
	public function ability_list_pages( $input = array() ) {
		$input = is_array( $input ) ? $input : array();
		return $this->pages()->list_pages(
			array(
				'status' => isset( $input['status'] ) ? (string) $input['status'] : 'all',
				'type'   => isset( $input['type'] ) ? (string) $input['type'] : 'all',
			)
		);
	}

	/**
	 * @since 2.0.0
	 * @param array $input Input.
	 * @return array|WP_Error
	 */
	public function ability_get_page( $input = array() ) {
		$input = is_array( $input ) ? $input : array();
		$slug  = isset( $input['slug'] ) ? (string) $input['slug'] : '';
		$page  = $this->pages()->get_page( $slug );
		if ( is_wp_error( $page ) ) {
			return $page;
		}
		// Ensure public_url is present for FINAL REPLY RULE (top-level + page fields).
		if ( is_array( $page ) ) {
			$public = ! empty( $page['public_url'] )
				? (string) $page['public_url']
				: $this->pages()->get_public_url( isset( $page['slug'] ) ? (string) $page['slug'] : $slug );
			$page['public_url']      = $public;
			$page['url']             = $public;
			$page['final_reply_url'] = $public;
		}
		return $page;
	}

	/**
	 * @since 2.0.0
	 * @param array $input Input.
	 * @return array|WP_Error
	 */
	public function ability_create_page( $input = array() ) {
		$input = is_array( $input ) ? $input : array();

		// Accept design_instructions alias.
		$instructions = '';
		if ( ! empty( $input['design_instructions'] ) ) {
			$instructions = (string) $input['design_instructions'];
		} elseif ( ! empty( $input['instructions'] ) ) {
			$instructions = (string) $input['instructions'];
		}

		$result = $this->pages()->create_page(
			array(
				'slug'          => isset( $input['slug'] ) ? (string) $input['slug'] : '',
				'type'          => isset( $input['type'] ) ? (string) $input['type'] : 'static',
				'title'         => isset( $input['title'] ) ? (string) $input['title'] : '',
				'code'          => isset( $input['code'] ) ? (string) $input['code'] : '',
				'instructions'  => $instructions,
				'is_front_page' => ! empty( $input['is_front_page'] ),
				// Always Draft on create (enforced inside create_page).
				'status'        => 'draft',
			)
		);

		// Auto-run visual analysis hint for AI self-improvement loop.
		if ( is_array( $result ) && ! empty( $result['success'] ) && ! empty( $result['page']['slug'] ) ) {
			$analysis = $this->pages()->get_visual_analysis( $result['page']['slug'] );
			if ( ! is_wp_error( $analysis ) ) {
				$result['visual_analysis'] = $analysis;
				$result['message']        .= ' ' . __( 'Visual analysis attached — improve if score < 85, then publish when ready.', 'promptweb' );
			}
		}

		return $this->with_public_url( $result );
	}

	/**
	 * @since 2.0.0
	 * @param array $input Input.
	 * @return array|WP_Error
	 */
	public function ability_update_page( $input = array() ) {
		$input = is_array( $input ) ? $input : array();

		$args = array(
			'slug' => isset( $input['slug'] ) ? (string) $input['slug'] : '',
		);
		if ( array_key_exists( 'code', $input ) ) {
			$args['code'] = (string) $input['code'];
		}
		if ( ! empty( $input['title'] ) ) {
			$args['title'] = (string) $input['title'];
		}
		if ( array_key_exists( 'is_front_page', $input ) ) {
			$args['is_front_page'] = (bool) $input['is_front_page'];
		}
		if ( ! empty( $input['design_instructions'] ) ) {
			$args['instructions'] = (string) $input['design_instructions'];
		} elseif ( ! empty( $input['instructions'] ) ) {
			$args['instructions'] = (string) $input['instructions'];
		}

		$result = $this->pages()->update_page( $args );

		if ( is_array( $result ) && ! empty( $result['success'] ) && ! empty( $result['page']['slug'] ) ) {
			$analysis = $this->pages()->get_visual_analysis( $result['page']['slug'] );
			if ( ! is_wp_error( $analysis ) ) {
				$result['visual_analysis'] = $analysis;
			}
		}

		return $this->with_public_url( $result );
	}

	/**
	 * @since 2.0.0
	 * @param array $input Input.
	 * @return array|WP_Error
	 */
	public function ability_publish_page( $input = array() ) {
		$input = is_array( $input ) ? $input : array();
		$slug  = isset( $input['slug'] ) ? (string) $input['slug'] : '';
		$result = $this->pages()->publish_page( $slug );

		if ( is_array( $result ) && ! empty( $result['success'] ) ) {
			$public = ! empty( $result['public_url'] ) ? (string) $result['public_url'] : '';
			$result['message'] = sprintf(
				/* translators: 1: slug, 2: public URL */
				__( 'Page “%1$s” is now published. public_url: %2$s', 'promptweb' ),
				sanitize_title( $slug ),
				$public
			);
		}

		return $this->with_public_url( $result );
	}

	/**
	 * Ensure tool result always exposes clean public_url for AI FINAL REPLY RULE.
	 *
	 * @since 2.0.1
	 * @param mixed $result Ability result.
	 * @return mixed
	 */
	protected function with_public_url( $result ) {
		if ( ! is_array( $result ) || is_wp_error( $result ) ) {
			return $result;
		}

		$public = '';
		if ( ! empty( $result['public_url'] ) ) {
			$public = (string) $result['public_url'];
		} elseif ( ! empty( $result['page']['public_url'] ) ) {
			$public = (string) $result['page']['public_url'];
		} elseif ( ! empty( $result['page']['slug'] ) ) {
			$public = $this->pages()->get_public_url( (string) $result['page']['slug'] );
		} elseif ( ! empty( $result['slug'] ) ) {
			$public = $this->pages()->get_public_url( (string) $result['slug'] );
		}

		if ( '' !== $public ) {
			$result['public_url']      = $public;
			$result['final_reply_url'] = $public;
			if ( isset( $result['page'] ) && is_array( $result['page'] ) ) {
				$result['page']['public_url'] = $public;
				$result['page']['url']        = $public;
			}
		}

		return $result;
	}

	/**
	 * @since 2.0.0
	 * @param array $input Input.
	 * @return array|WP_Error
	 */
	public function ability_get_visual_analysis( $input = array() ) {
		$input = is_array( $input ) ? $input : array();
		$slug  = isset( $input['slug'] ) ? (string) $input['slug'] : '';
		$code  = isset( $input['code'] ) ? (string) $input['code'] : '';
		return $this->pages()->get_visual_analysis( $slug, $code );
	}

	/**
	 * @since 2.0.0
	 * @param array $input Input.
	 * @return array
	 */
	public function ability_commit_to_github( $input = array() ) {
		$input   = is_array( $input ) ? $input : array();
		$message = isset( $input['message'] ) ? (string) $input['message'] : '';

		$github = $this->github();
		if ( ! method_exists( $github, 'commit_design_pages' ) ) {
			return array(
				'success' => false,
				'message' => __( 'GitHub commit_design_pages is not available.', 'promptweb' ),
			);
		}

		return $github->commit_design_pages(
			array(
				'message' => $message,
			)
		);
	}

	// -------------------------------------------------------------------------
	// REST fallback (promptweb/v1/mcp/*)
	// -------------------------------------------------------------------------

	/**
	 * Register REST routes mirroring MCP tools.
	 *
	 * @since 2.0.0
	 * @return void
	 */
	public function register_rest_routes() {
		$namespace = 'promptweb/v1';

		$routes = array(
			'/mcp/list-pages'          => array( 'GET', 'rest_list_pages' ),
			'/mcp/get-page'            => array( 'GET', 'rest_get_page' ),
			'/mcp/create-page'         => array( 'POST', 'rest_create_page' ),
			'/mcp/update-page'         => array( 'POST', 'rest_update_page' ),
			'/mcp/publish-page'        => array( 'POST', 'rest_publish_page' ),
			'/mcp/get-visual-analysis' => array( array( 'GET', 'POST' ), 'rest_get_visual_analysis' ),
			'/mcp/commit-to-github'    => array( 'POST', 'rest_commit_to_github' ),
		);

		foreach ( $routes as $route => $config ) {
			list( $methods, $callback ) = $config;
			register_rest_route(
				$namespace,
				$route,
				array(
					'methods'             => $methods,
					'callback'            => array( $this, $callback ),
					'permission_callback' => array( $this, 'permission_callback' ),
				)
			);
		}
	}

	/**
	 * Normalize ability/tool result to REST response.
	 *
	 * @since 2.0.0
	 * @param mixed $result Result or WP_Error.
	 * @return WP_REST_Response|WP_Error
	 */
	protected function to_rest( $result ) {
		if ( is_wp_error( $result ) ) {
			$data = $result->get_error_data();
			if ( ! is_array( $data ) || empty( $data['status'] ) ) {
				$result->add_data( array( 'status' => 400 ) );
			}
			return $result;
		}
		return new WP_REST_Response( $result, 200 );
	}

	/**
	 * @since 2.0.0
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function rest_list_pages( WP_REST_Request $request ) {
		return $this->to_rest(
			$this->ability_list_pages(
				array(
					'status' => $request->get_param( 'status' ),
					'type'   => $request->get_param( 'type' ),
				)
			)
		);
	}

	/**
	 * @since 2.0.0
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function rest_get_page( WP_REST_Request $request ) {
		return $this->to_rest(
			$this->ability_get_page(
				array(
					'slug' => $request->get_param( 'slug' ),
				)
			)
		);
	}

	/**
	 * @since 2.0.0
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function rest_create_page( WP_REST_Request $request ) {
		$params = $request->get_json_params();
		if ( ! is_array( $params ) ) {
			$params = $request->get_params();
		}
		return $this->to_rest( $this->ability_create_page( is_array( $params ) ? $params : array() ) );
	}

	/**
	 * @since 2.0.0
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function rest_update_page( WP_REST_Request $request ) {
		$params = $request->get_json_params();
		if ( ! is_array( $params ) ) {
			$params = $request->get_params();
		}
		return $this->to_rest( $this->ability_update_page( is_array( $params ) ? $params : array() ) );
	}

	/**
	 * @since 2.0.0
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function rest_publish_page( WP_REST_Request $request ) {
		$params = $request->get_json_params();
		if ( ! is_array( $params ) ) {
			$params = $request->get_params();
		}
		return $this->to_rest( $this->ability_publish_page( is_array( $params ) ? $params : array() ) );
	}

	/**
	 * @since 2.0.0
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function rest_get_visual_analysis( WP_REST_Request $request ) {
		$params = $request->get_json_params();
		if ( ! is_array( $params ) ) {
			$params = $request->get_params();
		}
		return $this->to_rest( $this->ability_get_visual_analysis( is_array( $params ) ? $params : array() ) );
	}

	/**
	 * @since 2.0.0
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function rest_commit_to_github( WP_REST_Request $request ) {
		$params = $request->get_json_params();
		if ( ! is_array( $params ) ) {
			$params = $request->get_params();
		}
		return $this->to_rest( $this->ability_commit_to_github( is_array( $params ) ? $params : array() ) );
	}
}
