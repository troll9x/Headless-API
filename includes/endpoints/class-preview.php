<?php
namespace TLU_Headless_API\Endpoints;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use TLU_Headless_API\Response;
use TLU_Headless_API\Services\PreviewService;

/**
 * POST /headless/v1/preview
 *
 * Trả về nội dung preview từ token được cấp.
 * Token có thể nằm trong Authorization header hoặc request body.
 * Response sẽ không được cache, và có noindex/noindex/noarchive header.
 */
class Preview {

	private PreviewService $service;

	public function __construct() {
		$this->service = new PreviewService();
	}

	public function register_routes(): void {
		register_rest_route(
			HEADLESS_API_NAMESPACE,
			'/preview',
			[
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => [ $this, 'handle' ],
				'permission_callback' => '__return_true',
				'args'                => [
					'token' => [
						'required'          => false,
						'sanitize_callback' => 'sanitize_text_field',
						'validate_callback' => fn( $v ) => is_string( $v ) && strlen( trim( $v ) ) > 10,
						'description'       => 'Preview token từ POST /preview-token.',
					],
				],
			]
		);
	}

	public function handle( \WP_REST_Request $request ): \WP_REST_Response {
		$token = $this->extract_token( $request );
		if ( '' === $token ) {
			return Response::error( 'headless_preview_auth_required', 'Preview token is required.', 401 );
		}

		$data = $this->service->get_preview( $token );
		if ( is_wp_error( $data ) ) {
			$status = $data->get_error_data()['status'] ?? 400;
			return Response::error( $data->get_error_code(), $data->get_error_message(), $status );
		}

		$response = Response::success( $data );
		$response->header( 'Cache-Control', 'private, no-store, no-cache, must-revalidate, max-age=0' );
		$response->header( 'Pragma', 'no-cache' );
		$response->header( 'Expires', '0' );
		$response->header( 'X-Robots-Tag', 'noindex, nofollow, noarchive' );
		$response->header( 'Referrer-Policy', 'no-referrer' );
		$response->header( 'Vary', 'Authorization' );

		return $response;
	}

	private function extract_token( \WP_REST_Request $request ): string {
		$auth_header = $request->get_header( 'Authorization' );
		if ( $auth_header && preg_match( '/Bearer\s+(.+)$/i', $auth_header, $matches ) ) {
			return $matches[1];
		}

		$token = $request->get_param( 'token' );
		if ( $token ) {
			return $token;
		}

		return '';
	}
}