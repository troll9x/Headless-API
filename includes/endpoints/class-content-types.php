<?php

namespace TLU_Headless_API\Endpoints;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use TLU_Headless_API\Services\ContentTypeRegistry;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * Endpoint khám phá Custom Post Type và Custom Taxonomy.
 *
 * Routes:
 * - GET /headless/v1/content-types
 * - GET /headless/v1/content-types/{post_type}
 * - GET /headless/v1/content-taxonomies
 * - GET /headless/v1/content-taxonomies/{taxonomy}
 */
class Content_Types {

	private ContentTypeRegistry $registry;

	public function __construct( ?ContentTypeRegistry $registry = null ) {
		$this->registry = $registry ?? new ContentTypeRegistry();
	}

	public function register_routes(): void {
		register_rest_route( HEADLESS_API_NAMESPACE, '/content-types', [
			[
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => [ $this, 'get_post_types' ],
				'permission_callback' => '__return_true',
			],
		] );

		register_rest_route( HEADLESS_API_NAMESPACE, '/content-types/(?P<post_type>[a-z0-9_-]+)', [
			[
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => [ $this, 'get_post_type' ],
				'permission_callback' => '__return_true',
			],
		] );

		register_rest_route( HEADLESS_API_NAMESPACE, '/content-taxonomies', [
			[
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => [ $this, 'get_taxonomies' ],
				'permission_callback' => '__return_true',
			],
		] );

		register_rest_route( HEADLESS_API_NAMESPACE, '/content-taxonomies/(?P<taxonomy>[a-z0-9_-]+)', [
			[
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => [ $this, 'get_taxonomy' ],
				'permission_callback' => '__return_true',
			],
		] );
	}

	public function get_post_types(): WP_REST_Response {
		return new WP_REST_Response( [ 'post_types' => $this->registry->get_post_types() ], 200 );
	}

	public function get_post_type( WP_REST_Request $request ): WP_REST_Response {
		$post_type = $this->registry->get_post_type( (string) $request->get_param( 'post_type' ) );

		return $post_type
			? new WP_REST_Response( $post_type, 200 )
			: $this->not_found( 'Không tìm thấy Content Type công khai.' );
	}

	public function get_taxonomies(): WP_REST_Response {
		return new WP_REST_Response( [ 'taxonomies' => $this->registry->get_taxonomies() ], 200 );
	}

	public function get_taxonomy( WP_REST_Request $request ): WP_REST_Response {
		$taxonomy = $this->registry->get_taxonomy( (string) $request->get_param( 'taxonomy' ) );

		return $taxonomy
			? new WP_REST_Response( $taxonomy, 200 )
			: $this->not_found( 'Không tìm thấy Taxonomy công khai.' );
	}

	private function not_found( string $message ): WP_REST_Response {
		return new WP_REST_Response(
			[
				'error'   => 'headless_content_model_not_found',
				'message' => $message,
				'status'  => 404,
			],
			404
		);
	}
}
