<?php
namespace TLU_Headless_API\Endpoints;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use TLU_Headless_API\Services\TaxonomyService;
use WP_REST_Server;
use WP_REST_Response;
use WP_REST_Request;

/**
 * Endpoint xử lý taxonomy term.
 *
 * Routes:
 * - GET /headless/v1/taxonomies/{taxonomy}/terms
 *   Query params:
 *   - page: int - Pagination page
 *   - per_page: int - Terms per page
 *
 * - GET /headless/v1/term/{taxonomy}/{term}
 *   Query params:
 *   - lang: string - Language filter
 *   - page: int - Pagination page
 *   - per_page: int - Posts per page
 */
class Term {

	private TaxonomyService $service;

	public function __construct( ?TaxonomyService $service = null ) {
		$this->service = $service ?? new TaxonomyService();
	}

	/**
	 * Register REST routes.
	 */
	public function register_routes(): void {
		register_rest_route( 'headless/v1', '/taxonomies/(?P<taxonomy>[a-z_-]+)/terms', [
			[
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => [ $this, 'get_terms' ],
				'permission_callback' => '__return_true',
				'args'                => $this->get_terms_collection_params(),
			],
		] );

		register_rest_route( 'headless/v1', '/term/(?P<taxonomy>[a-z_-]+)/(?P<term>[a-z0-9_-]+)', [
			[
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => [ $this, 'get_term' ],
				'permission_callback' => '__return_true',
				'args'                => $this->get_term_params(),
			],
		] );
	}

	/**
	 * Get all terms for a taxonomy.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error Response object or error.
	 */
	public function get_terms( WP_REST_Request $request ) {
		$taxonomy = $request->get_param( 'taxonomy' );
		$lang = $request->get_param( 'lang' ) ?? '';
		$page = (int) ( $request->get_param( 'page' ) ?? 1 );
		$per_page = (int) ( $request->get_param( 'per_page' ) ?? 10 );

		// Check if taxonomy exists
		$obj = get_taxonomy( $taxonomy );
		if ( ! $obj ) {
			return new WP_REST_Response( [
				'error'   => 'headless_taxonomy_not_found',
				'message' => 'Taxonomy không tồn tại.',
				'status'  => 404,
			], 404 );
		}

		$result = $this->service->get_all_terms( $taxonomy, $lang, $page, $per_page );

		return new WP_REST_Response( $result, 200 );
	}

	/**
	 * Get specific term data.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error Response object or error.
	 */
	public function get_term( WP_REST_Request $request ) {
		$taxonomy = $request->get_param( 'taxonomy' );
		$term = $request->get_param( 'term' );
		$lang = $request->get_param( 'lang' ) ?? '';
		$page = (int) ( $request->get_param( 'page' ) ?? 1 );
		$per_page = (int) ( $request->get_param( 'per_page' ) ?? 10 );

		// Check if taxonomy exists
		$obj = get_taxonomy( $taxonomy );
		if ( ! $obj ) {
			return new WP_REST_Response( [
				'error'   => 'headless_taxonomy_not_found',
				'message' => 'Taxonomy không tồn tại.',
				'status'  => 404,
			], 404 );
		}

		$result = $this->service->get_term_data( $taxonomy, $term, $lang, $page, $per_page );

		if ( isset( $result['error'] ) ) {
			return new WP_REST_Response( $result, $result['status'] ?? 404 );
		}

		return new WP_REST_Response( $result, 200 );
	}

	/**
	 * Get collection parameters for terms endpoint.
	 *
	 * @return array Parameters.
	 */
	private function get_terms_collection_params(): array {
		return [
			'lang'      => [
				'description'       => 'Language slug',
				'type'              => 'string',
				'required'          => false,
			],
			'page'      => [
				'description'       => 'Page number',
				'type'              => 'integer',
				'required'          => false,
				'default'           => 1,
				'minimum'           => 1,
			],
			'per_page'  => [
				'description'       => 'Terms per page',
				'type'              => 'integer',
				'required'          => false,
				'default'           => 10,
				'minimum'           => 1,
				'maximum'           => 100,
			],
		];
	}

	/**
	 * Get parameters for term endpoint.
	 *
	 * @return array Parameters.
	 */
	private function get_term_params(): array {
		return [
			'lang'      => [
				'description'       => 'Language slug',
				'type'              => 'string',
				'required'          => false,
			],
			'page'      => [
				'description'       => 'Page number',
				'type'              => 'integer',
				'required'          => false,
				'default'           => 1,
				'minimum'           => 1,
			],
			'per_page'  => [
				'description'       => 'Posts per page',
				'type'              => 'integer',
				'required'          => false,
				'default'           => 10,
				'minimum'           => 1,
				'maximum'           => 100,
			],
		];
	}
}