<?php
namespace TLU_Headless_API\Endpoints;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use TLU_Headless_API\Response;
use TLU_Headless_API\Services\SearchService;
use TLU_Headless_API\Helpers\ContentVisibility;

/** Public headless bridge for the custom WPX FULLTEXT search backend. */
class Search {

	private SearchService $service;

	public function __construct() {
		$this->service = new SearchService();
	}

	public function register_routes(): void {
		register_rest_route( HEADLESS_API_NAMESPACE, '/search', [
			'methods'             => \WP_REST_Server::READABLE,
			'callback'            => [ $this, 'handle_search' ],
			'permission_callback' => '__return_true',
			'args'                => $this->search_args(),
		] );

		register_rest_route( HEADLESS_API_NAMESPACE, '/suggest', [
			'methods'             => \WP_REST_Server::READABLE,
			'callback'            => [ $this, 'handle_suggest' ],
			'permission_callback' => '__return_true',
			'args'                => $this->suggest_args(),
		] );
	}

	public function handle_search( \WP_REST_Request $request ) {
		$query = trim( (string) $request->get_param( 'q' ) );
		if ( mb_strlen( $query ) < $this->service->min_chars() ) {
			return Response::bad_request( sprintf( 'Search query must contain at least %d characters.', $this->service->min_chars() ) );
		}

		$result = $this->service->search(
			$query,
			(int) $request->get_param( 'per' ),
			(int) $request->get_param( 'page' )
		);

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		if ( isset( $result['items'] ) && is_array( $result['items'] ) ) {
			$result['items'] = $this->filter_public_results( $result['items'] );
		}

		return Response::success( $result );
	}

	public function handle_suggest( \WP_REST_Request $request ) {
		$query = trim( (string) $request->get_param( 'q' ) );
		$length = mb_strlen( $query );
		if ( 0 === $length || $length >= $this->service->min_chars() ) {
			return Response::bad_request( sprintf( 'Suggest query must contain 1 to %d characters.', $this->service->min_chars() - 1 ) );
		}

		$result = $this->service->suggest( $query );
		return is_wp_error( $result ) ? $result : Response::success( $result );
	}

	private function search_args(): array {
		return [
			'q' => [
				'required'          => true,
				'sanitize_callback' => 'sanitize_text_field',
				'validate_callback' => fn( $value ) => is_string( $value ) && mb_strlen( trim( $value ) ) <= 200,
			],
			'per' => [
				'default'           => 8,
				'sanitize_callback' => 'absint',
				'validate_callback' => fn( $value ) => is_numeric( $value ) && (int) $value >= 1 && (int) $value <= 50,
			],
			'page' => [
				'default'           => 1,
				'sanitize_callback' => 'absint',
				'validate_callback' => fn( $value ) => is_numeric( $value ) && (int) $value >= 1,
			],
		];
	}

	private function suggest_args(): array {
		return [
			'q' => [
				'required'          => true,
				'sanitize_callback' => 'sanitize_text_field',
				'validate_callback' => fn( $value ) => is_string( $value ) && mb_strlen( trim( $value ) ) <= 200,
			],
		];
	}

	private function get_result_post_id( array $result ): int {
		foreach ( [ 'id', 'ID', 'post_id', 'object_id' ] as $key ) {
			if ( ! array_key_exists( $key, $result ) ) {
				continue;
			}

			$value = $result[ $key ];

			if ( is_int( $value ) && $value > 0 ) {
				return $value;
			}

			if (
				is_string( $value )
				&& '' !== $value
				&& ctype_digit( $value )
			) {
				return (int) $value;
			}
		}

		return 0;
	}

	private function filter_public_results( array $items ): array {
		$public = [];

		foreach ( $items as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}

			$post_id = $this->get_result_post_id( $item );

			if ( $post_id <= 0 ) {
				continue;
			}

			$post = \get_post( $post_id );

			if (
				! $post instanceof \WP_Post
				|| ! ContentVisibility::is_post_public( $post )
			) {
				continue;
			}

			$public[] = $item;
		}

		return $public;
	}
}
