<?php
namespace TLU_Headless_API\Endpoints;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use TLU_Headless_API\Services\ArchiveService;
use WP_REST_Server;
use WP_REST_Response;
use WP_REST_Request;

/**
 * Endpoint xử lý archive context.
 *
 * Routes:
 * - GET /headless/v1/archive
 *   Query params:
 *   - post_type: string - Resolve post type archive
 *   - taxonomy: string, term: string - Resolve taxonomy archive
 *   - author: int|string - Resolve author archive
 *   - year: int, month?: int, day?: int - Resolve date archive
 *   - path: string - Resolve by path
 *   - url: string - Resolve by URL
 *   - lang: string - Language filter
 *   - page: int - Pagination page
 *   - per_page: int - Posts per page
 */
class Archive {

	private ArchiveService $service;

	public function __construct( ?ArchiveService $service = null ) {
		$this->service = $service ?? new ArchiveService();
	}

	/**
	 * Register REST routes.
	 */
	public function register_routes(): void {
		register_rest_route( 'headless/v1', '/archive', [
			[
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => [ $this, 'get_items' ],
				'permission_callback' => '__return_true',
				'args'                => $this->get_collection_params(),
			],
		] );

		register_rest_route( 'headless/v1', '/archive/types', [
			[
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => [ $this, 'get_post_types' ],
				'permission_callback' => '__return_true',
			],
		] );

		register_rest_route( 'headless/v1', '/archive/taxonomies', [
			[
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => [ $this, 'get_taxonomies' ],
				'permission_callback' => '__return_true',
			],
		] );
	}

	/**
	 * Get archive items.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error Response object or error.
	 */
	public function get_items( WP_REST_Request $request ) {
		$params = $request->get_query_params();

		// Determine selector type
		$query = [];

		if ( isset( $params['post_type'] ) && '' !== $params['post_type'] ) {
			$query['post_type'] = $params['post_type'];
		}

		if ( isset( $params['taxonomy'] ) && '' !== $params['taxonomy'] ) {
			$query['taxonomy'] = $params['taxonomy'];
			if ( isset( $params['term'] ) && '' !== $params['term'] ) {
				$query['term'] = $params['term'];
			}
		}

		if ( isset( $params['author'] ) && '' !== $params['author'] ) {
			$query['author'] = $params['author'];
		}

		if ( isset( $params['year'] ) && '' !== $params['year'] ) {
			$query['year'] = $params['year'];
			if ( isset( $params['month'] ) && '' !== $params['month'] ) {
				$query['month'] = $params['month'];
			}
			if ( isset( $params['day'] ) && '' !== $params['day'] ) {
				$query['day'] = $params['day'];
			}
		}

		if ( isset( $params['path'] ) && '' !== $params['path'] ) {
			$query['path'] = $params['path'];
		}

		if ( isset( $params['url'] ) && '' !== $params['url'] ) {
			$query['url'] = $params['url'];
		}

		$query['lang'] = $params['lang'] ?? '';
		$page = (int) ( $params['page'] ?? 1 );
		$per_page = (int) ( $params['per_page'] ?? 10 );

		$result = $this->service->get_archive( $query, $page, $per_page );

		if ( is_wp_error( $result ) ) {
			return new WP_REST_Response( $result, $result->get_error_data()['status'] ?? 500 );
		}

		return new WP_REST_Response( $result, 200 );
	}

	/**
	 * Get all post types with archives.
	 *
	 * @return WP_REST_Response Response object.
	 */
	public function get_post_types(): WP_REST_Response {
		$post_types = $this->service->get_post_types_with_archive();

		return new WP_REST_Response( $post_types, 200 );
	}

	/**
	 * Get all taxonomies.
	 *
	 * @return WP_REST_Response Response object.
	 */
	public function get_taxonomies(): WP_REST_Response {
		$taxonomies = $this->service->get_taxonomies_with_archive();

		return new WP_REST_Response( $taxonomies, 200 );
	}

	/**
	 * Get collection parameters.
	 *
	 * @return array Parameters.
	 */
	private function get_collection_params(): array {
		return [
			'post_type' => [
				'description'       => 'Post type slug',
				'type'              => 'string',
				'required'          => false,
				'sanitize_callback' => 'sanitize_key',
			],
			'taxonomy'  => [
				'description'       => 'Taxonomy slug',
				'type'              => 'string',
				'required'          => false,
				'sanitize_callback' => 'sanitize_key',
			],
			'term'      => [
				'description'       => 'Term slug or ID',
				'type'              => 'string',
				'required'          => false,
				'sanitize_callback' => 'sanitize_title',
			],
			'author'    => [
				'description'       => 'Author ID or nicename',
				'type'              => 'mixed',
				'required'          => false,
			],
			'year'      => [
				'description'       => 'Year (4 digits)',
				'type'              => 'integer',
				'required'          => false,
				'minimum'           => 1970,
				'maximum'           => 2100,
			],
			'month'     => [
				'description'       => 'Month (1-12)',
				'type'              => 'integer',
				'required'          => false,
				'minimum'           => 1,
				'maximum'           => 12,
			],
			'day'       => [
				'description'       => 'Day (1-31)',
				'type'              => 'integer',
				'required'          => false,
				'minimum'           => 1,
				'maximum'           => 31,
			],
			'path'      => [
				'description'       => 'Path to resolve',
				'type'              => 'string',
				'required'          => false,
			],
			'url'       => [
				'description'       => 'URL to resolve',
				'type'              => 'string',
				'required'          => false,
				'format'            => 'uri',
			],
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