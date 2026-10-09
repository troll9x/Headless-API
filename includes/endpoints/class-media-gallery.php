<?php
namespace TLU_Headless_API\Endpoints;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use TLU_Headless_API\Response;
use TLU_Headless_API\Services\MediaGalleryService;

/**
 * Public read-only API cho SonNH Media Gallery và Son NH Template Gallery.
 */
final class Media_Gallery {

	private MediaGalleryService $service;

	public function __construct( ?MediaGalleryService $service = null ) {
		$this->service = $service ?? new MediaGalleryService();
	}

	public function register_routes(): void {
		register_rest_route( HEADLESS_API_NAMESPACE, '/media-gallery/categories', [
			[
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => [ $this, 'get_categories' ],
				'permission_callback' => '__return_true',
				'args'                => $this->collection_args( 12, 50 ),
			],
		] );

		register_rest_route( HEADLESS_API_NAMESPACE, '/media-gallery/categories/(?P<slug>[^/]+)', [
			[
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => [ $this, 'get_category' ],
				'permission_callback' => '__return_true',
				'args'                => array_merge(
					[
						'slug' => [
							'required'          => true,
							'sanitize_callback' => 'sanitize_title',
							'validate_callback' => fn( $value ): bool => is_string( $value ) && '' !== trim( $value ) && strlen( $value ) <= 200,
						],
					],
					$this->collection_args( 24, 100 ),
					[
						'order' => [
							'default'           => 'asc',
							'sanitize_callback' => fn( $value ): string => strtolower( sanitize_key( (string) $value ) ),
							'validate_callback' => fn( $value ): bool => is_string( $value ) && in_array( strtolower( $value ), [ 'asc', 'desc' ], true ),
						],
					]
				),
			],
		] );

		register_rest_route( HEADLESS_API_NAMESPACE, '/media-gallery/home', [
			[
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => [ $this, 'get_home_gallery' ],
				'permission_callback' => '__return_true',
			],
		] );
	}

	public function get_categories( \WP_REST_Request $request ) {
		$result = $this->service->get_categories(
			(int) $request->get_param( 'page' ),
			(int) $request->get_param( 'per_page' ),
			(string) $request->get_param( 'lang' ),
			(string) $request->get_param( 'order' )
		);
		return is_wp_error( $result ) ? $result : Response::success( $result );
	}

	public function get_category( \WP_REST_Request $request ) {
		$result = $this->service->get_category(
			(string) $request->get_param( 'slug' ),
			(int) $request->get_param( 'page' ),
			(int) $request->get_param( 'per_page' ),
			(string) $request->get_param( 'lang' )
		);
		return is_wp_error( $result ) ? $result : Response::success( $result );
	}

	public function get_home_gallery( \WP_REST_Request $request ) {
		$result = $this->service->get_home_gallery();
		return is_wp_error( $result ) ? $result : Response::success( $result );
	}

	private function collection_args( int $default_per_page, int $maximum ): array {
		return [
			'page'     => [
				'default'           => 1,
				'sanitize_callback' => 'absint',
				'validate_callback' => fn( $value ): bool => is_numeric( $value ) && (int) $value >= 1,
			],
			'per_page' => [
				'default'           => $default_per_page,
				'sanitize_callback' => 'absint',
				'validate_callback' => fn( $value ): bool => is_numeric( $value ) && (int) $value >= 1 && (int) $value <= $maximum,
			],
			'lang'     => [
				'default'           => '',
				'sanitize_callback' => fn( $value ): string => (string) preg_replace( '/[^A-Za-z0-9_-]/', '', (string) $value ),
				'validate_callback' => fn( $value ): bool => is_string( $value ) && strlen( $value ) <= 20,
			],
		];
	}
}
