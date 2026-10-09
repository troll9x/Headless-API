<?php
namespace TLU_Headless_API\Endpoints;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use TLU_Headless_API\Response;
use TLU_Headless_API\Services\DocumentService;

/**
 * Public read-only API cho template single và taxonomy archive của tài liệu.
 */
final class Documents {

	private DocumentService $service;

	public function __construct( ?DocumentService $service = null ) {
		$this->service = $service ?? new DocumentService();
	}

	public function register_routes(): void {
		register_rest_route( HEADLESS_API_NAMESPACE, '/documents/categories/(?P<slug>[^/]+)', [
			[
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => [ $this, 'get_category' ],
				'permission_callback' => '__return_true',
				'args'                => array_merge(
					$this->detail_args(),
					[
						'page' => [
							'default'           => 1,
							'sanitize_callback' => 'absint',
							'validate_callback' => fn( $value ): bool => is_numeric( $value ) && (int) $value >= 1,
						],
						'per_page' => [
							'default'           => 6,
							'sanitize_callback' => 'absint',
							'validate_callback' => fn( $value ): bool => is_numeric( $value ) && (int) $value >= 1 && (int) $value <= 24,
						],
					]
				),
			],
		] );

		register_rest_route( HEADLESS_API_NAMESPACE, '/documents/(?P<slug>[^/]+)', [
			[
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => [ $this, 'get_document' ],
				'permission_callback' => '__return_true',
				'args'                => $this->detail_args(),
			],
		] );
	}

	public function get_document( \WP_REST_Request $request ) {
		$result = $this->service->get_document(
			(string) $request->get_param( 'slug' ),
			(string) $request->get_param( 'lang' )
		);

		return is_wp_error( $result ) ? $result : Response::success( $result );
	}

	public function get_category( \WP_REST_Request $request ) {
		$result = $this->service->get_category_archive(
			(string) $request->get_param( 'slug' ),
			(string) $request->get_param( 'lang' ),
			(int) $request->get_param( 'page' ),
			(int) $request->get_param( 'per_page' )
		);

		return is_wp_error( $result ) ? $result : Response::success( $result );
	}

	private function detail_args(): array {
		return [
			'slug' => [
				'required'          => true,
				'sanitize_callback' => 'sanitize_title',
				'validate_callback' => fn( $value ): bool => is_string( $value ) && '' !== trim( $value ) && strlen( $value ) <= 200,
			],
			'lang' => [
				'default'           => '',
				'sanitize_callback' => fn( $value ): string => (string) preg_replace( '/[^A-Za-z0-9_-]/', '', (string) $value ),
				'validate_callback' => fn( $value ): bool => is_string( $value ) && strlen( $value ) <= 20,
			],
		];
	}
}
