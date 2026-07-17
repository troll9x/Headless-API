<?php
namespace TLU_Headless_API\Endpoints;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use TLU_Headless_API\Response;
use TLU_Headless_API\Services\PreviewService;

/**
 * POST /headless/v1/preview-token
 * POST /headless/v1/preview-token/revoke
 */
class PreviewToken {

	private PreviewService $service;

	public function __construct() {
		$this->service = new PreviewService();
	}

	public function register_routes(): void {
		// Route issue token
		register_rest_route(
			HEADLESS_API_NAMESPACE,
			'/preview-token',
			[
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => [ $this, 'handle_issue' ],
				'permission_callback' => [ $this, 'permission_issue' ],
				'args'                => [
					'post_id'   => [
						'required'          => true,
						'sanitize_callback' => 'absint',
						'validate_callback' => fn( $v ) => is_numeric( $v ) && (int) $v > 0,
						'description'       => 'Post ID cần preview.',
					],
					'source'    => [
						'default'           => 'current',
						'sanitize_callback' => 'sanitize_key',
						'validate_callback' => fn( $v ) => in_array( $v, [ 'current', 'revision', 'autosave' ], true ),
						'description'       => 'Nguồn preview: current, revision, autosave.',
					],
					'source_id' => [
						'default'           => 0,
						'sanitize_callback' => 'absint',
						'validate_callback' => fn( $v ) => is_numeric( $v ) && (int) $v >= 0,
						'description'       => 'Revision/autosave ID (bắt buộc nếu source !== current).',
					],
					'lang'      => [
						'default'           => '',
						'sanitize_callback' => 'sanitize_key',
						'validate_callback' => fn( $v ) => is_string( $v ) && strlen( trim( $v ) ) <= 20,
						'description'       => 'Ngôn ngữ (tùy chọn).',
					],
				],
			]
		);

		// Route revoke token
		register_rest_route(
			HEADLESS_API_NAMESPACE,
			'/preview-token/revoke',
			[
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => [ $this, 'handle_revoke' ],
				'permission_callback' => [ $this, 'permission_revoke' ],
				'args'                => [
					'token' => [
						'required'          => true,
						'sanitize_callback' => 'sanitize_text_field',
						'validate_callback' => fn( $v ) => is_string( $v ) && strlen( $v ) > 10,
						'description'       => 'Token cần thu hồi.',
					],
				],
			]
		);
	}

	public function handle_issue( \WP_REST_Request $request ): \WP_REST_Response {
		$post_id   = (int) $request->get_param( 'post_id' );
		$source    = $request->get_param( 'source' );
		$source_id = (int) $request->get_param( 'source_id' );
		$lang      = $request->get_param( 'lang' );

		$result = $this->service->issue_preview_token( $post_id, $source, $source_id, $lang );
		if ( is_wp_error( $result ) ) {
			$status = $result->get_error_data()['status'] ?? 400;
			return Response::error( $result->get_error_code(), $result->get_error_message(), $status );
		}

		return Response::success( $result, 201 );
	}

	public function handle_revoke( \WP_REST_Request $request ): \WP_REST_Response {
		$token = $request->get_param( 'token' );
		$success = $this->service->revoke_preview_token( $token );
		if ( is_wp_error( $success ) ) {
			return Response::error( $success->get_error_code(), $success->get_error_message(), 403 );
		}
		return Response::success( [ 'revoked' => true ] );
	}

	public function permission_issue(): bool {
		if ( ! is_user_logged_in() ) {
			return false;
		}
		return true;
	}

	public function permission_revoke(): bool {
		if ( ! is_user_logged_in() ) {
			return false;
		}
		return true;
	}
}