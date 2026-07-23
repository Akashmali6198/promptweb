<?php
/**
 * REST API for PromptWeb (frontend editor ↔ GitHub).
 *
 * Routes (namespace promptweb/v1):
 *   POST /push-blueprint  — push blueprint JSON to GitHub (canonical).
 *   POST /blueprint/push  — alias (backward compatible).
 *
 * Security:
 *   - Cookie authentication + X-WP-Nonce (WordPress REST).
 *   - permission_callback requires logged-in user with edit_pages
 *     (or filterable capability via promptweb_rest_capability).
 *
 * Maximum AI Creativity: structured JSON is the source of truth in GitHub.
 * Multisite: credentials/storage use PromptWeb_Settings::use_network_options().
 *
 * @package PromptWeb
 * @since   1.0.0
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers and handles PromptWeb REST routes.
 *
 * @since 1.0.0
 */
class PromptWeb_REST {

	/**
	 * REST namespace.
	 *
	 * @since 1.0.0
	 * @var   string
	 */
	const NAMESPACE = 'promptweb/v1';

	/**
	 * Hook rest_api_init.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public function init() {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	/**
	 * Shared route args for push endpoints.
	 *
	 * @since 1.0.0
	 * @return array
	 */
	protected function get_push_route_args() {
		return array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => array( $this, 'handle_push' ),
			'permission_callback' => array( $this, 'can_edit' ),
			'args'                => array(
				'blueprint' => array(
					'required'          => true,
					'description'       => __( 'Updated blueprint JSON object to write to GitHub (e.g. blueprints/latest.json).', 'promptweb' ),
					'validate_callback' => array( $this, 'validate_blueprint_param' ),
					// Keep nested structure intact; do not deep-sanitize away AI keys.
					'sanitize_callback' => array( $this, 'sanitize_blueprint_param' ),
				),
				'message'   => array(
					'required'          => false,
					'type'              => 'string',
					'sanitize_callback' => 'sanitize_text_field',
					'default'           => '',
					'description'       => __( 'Optional Git commit message.', 'promptweb' ),
				),
			),
		);
	}

	/**
	 * Register routes.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public function register_routes() {
		$push_args = $this->get_push_route_args();

		// Canonical endpoint per product API.
		register_rest_route( self::NAMESPACE, '/push-blueprint', $push_args );

		// Backward-compatible alias.
		register_rest_route( self::NAMESPACE, '/blueprint/push', $push_args );

		/**
		 * Fires after PromptWeb REST routes are registered.
		 *
		 * @since 1.0.0
		 * @param PromptWeb_REST $rest This instance.
		 */
		do_action( 'promptweb_rest_routes_registered', $this );
	}

	/**
	 * Permission: logged-in + edit_pages (strict).
	 *
	 * WordPress REST also validates the X-WP-Nonce cookie nonce for cookie auth.
	 *
	 * @since 1.0.0
	 * @param WP_REST_Request $request Request (unused; required by WP signature).
	 * @return bool|WP_Error
	 */
	public function can_edit( $request = null ) {
		unset( $request );

		if ( ! is_user_logged_in() ) {
			return new WP_Error(
				'promptweb_rest_not_logged_in',
				__( 'You must be logged in to push blueprints.', 'promptweb' ),
				array( 'status' => 401 )
			);
		}

		$capability = 'edit_pages';

		if ( function_exists( 'promptweb' ) && isset( promptweb()->editor ) && promptweb()->editor instanceof PromptWeb_Editor ) {
			$capability = promptweb()->editor->get_capability();
		}

		/**
		 * Filters capability required for PromptWeb REST write endpoints.
		 *
		 * @since 1.0.0
		 * @param string $capability Capability (default edit_pages).
		 */
		$capability = (string) apply_filters( 'promptweb_rest_capability', $capability );

		if ( ! current_user_can( $capability ) ) {
			return new WP_Error(
				'promptweb_rest_forbidden',
				__( 'You do not have permission to push blueprints.', 'promptweb' ),
				array( 'status' => 403 )
			);
		}

		return true;
	}

	/**
	 * Validate blueprint request param is an object/array.
	 *
	 * @since 1.0.0
	 * @param mixed           $value   Raw value.
	 * @param WP_REST_Request $request Request.
	 * @param string          $param   Param name.
	 * @return bool|WP_Error
	 */
	public function validate_blueprint_param( $value, $request = null, $param = '' ) {
		unset( $request, $param );

		if ( ! is_array( $value ) ) {
			return new WP_Error(
				'promptweb_rest_invalid_blueprint',
				__( 'The blueprint parameter must be a JSON object.', 'promptweb' ),
				array( 'status' => 400 )
			);
		}

		return true;
	}

	/**
	 * Light sanitize: ensure array shape only (do not strip AI-invented keys).
	 *
	 * @since 1.0.0
	 * @param mixed $value Raw blueprint.
	 * @return array
	 */
	public function sanitize_blueprint_param( $value ) {
		return is_array( $value ) ? $value : array();
	}

	/**
	 * POST /push-blueprint — write blueprint JSON to the connected GitHub repo.
	 *
	 * Uses existing GitHub settings (token, repo, branch, blueprint path —
	 * default blueprints/latest.json). Creates or updates the remote file.
	 *
	 * @since 1.0.0
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function handle_push( WP_REST_Request $request ) {
		$blueprint = $request->get_param( 'blueprint' );
		$message   = $request->get_param( 'message' );

		if ( ! is_array( $blueprint ) || empty( $blueprint ) ) {
			return new WP_Error(
				'promptweb_rest_invalid_blueprint',
				__( 'Invalid or empty blueprint payload.', 'promptweb' ),
				array( 'status' => 400 )
			);
		}

		$github = function_exists( 'promptweb' ) ? promptweb()->github : null;
		if ( ! $github instanceof PromptWeb_GitHub ) {
			$github = new PromptWeb_GitHub();
		}

		$result = $github->push_blueprint(
			$blueprint,
			array(
				'message' => is_string( $message ) ? $message : '',
			)
		);

		if ( ! is_array( $result ) ) {
			return new WP_Error(
				'promptweb_rest_push_failed',
				__( 'Push to GitHub failed.', 'promptweb' ),
				array( 'status' => 500 )
			);
		}

		$status = ! empty( $result['success'] ) ? 200 : 400;

		if ( empty( $result['success'] ) && ! empty( $result['code'] ) ) {
			if ( 'promptweb_github_auth' === $result['code'] ) {
				$status = 403;
			} elseif ( 'promptweb_not_configured' === $result['code'] ) {
				$status = 400;
			} elseif ( 'promptweb_github_conflict' === $result['code'] ) {
				$status = 409;
			}
		}

		// Normalize response for the frontend.
		$response = array(
			'success' => ! empty( $result['success'] ),
			'code'    => isset( $result['code'] ) ? $result['code'] : '',
			'message' => isset( $result['message'] ) ? $result['message'] : '',
			'data'    => isset( $result['data'] ) ? $result['data'] : null,
		);

		/**
		 * Filters the REST push response payload.
		 *
		 * @since 1.0.0
		 * @param array           $response Normalized response.
		 * @param array           $result   Raw push result.
		 * @param WP_REST_Request $request  Request.
		 */
		$response = apply_filters( 'promptweb_rest_push_result', $response, $result, $request );

		return new WP_REST_Response( $response, $status );
	}
}
