<?php
namespace TLU_Headless_API\Services;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use TLU_Headless_API\Config;
use TLU_Headless_API\Helpers\ContentVisibility;

/** Bridge tới WPX FULLTEXT REST API mà không thay đổi ranking hay dữ liệu kết quả. */
class SearchService {

	private const SEARCH_ROUTE  = '/wpx-ft/v1/search';
	private const SUGGEST_ROUTE = '/wpx-ft/v1/suggest';

	public function min_chars(): int {
		return max( 2, (int) apply_filters( 'headless_api_search_min_chars', 2 ) );
	}

	/** @return array|\WP_Error */
	public function search( string $query, int $per = 8, int $page = 1 ) {
		return $this->dispatch(
			self::SEARCH_ROUTE,
			[
				'q'    => $query,
				'per'  => min( 50, max( 1, $per ) ),
				'page' => max( 1, $page ),
			],
			true
		);
	}

	/** @return array|\WP_Error */
	public function suggest( string $query ) {
		return $this->dispatch( self::SUGGEST_ROUTE, [ 'q' => $query ], false );
	}

	/**
	 * Gọi route nội bộ để không phụ thuộc DNS, TLS hay CORS.
	 * Không sort, map URL hoặc sửa item do backend FULLTEXT trả về.
	 *
	 * @return array|\WP_Error
	 */
	private function dispatch( string $route, array $params, bool $paginated ) {
		if ( ! isset( rest_get_server()->get_routes()[ $route ] ) ) {
			return $this->dispatch_remote( $route, $params, $paginated );
		}

		$request = new \WP_REST_Request( 'GET', $route );
		$request->set_query_params( $params );
		$response = rest_do_request( $request );

		if ( $response->is_error() || $response->get_status() >= 400 ) {
			return new \WP_Error(
				'search_backend_error',
				'FULLTEXT search backend returned an error.',
				[
					'status'         => 502,
					'backend_status' => $response->get_status(),
					'backend_data'   => $response->get_data(),
				]
			);
		}

		return $this->validate_data( $response->get_data(), $paginated );
	}

	/** @return array|\WP_Error */
	private function dispatch_remote( string $route, array $params, bool $paginated ) {
		$endpoint = str_ends_with( $route, '/suggest' ) ? '/suggest' : '/search';
		$url      = add_query_arg( $params, Config::search_backend_url() . $endpoint );
		$response = wp_remote_get( $url, [
			'timeout'     => 8,
			'redirection' => 2,
			'headers'     => [ 'Accept' => 'application/json' ],
		] );

		if ( is_wp_error( $response ) ) {
			return new \WP_Error(
				'search_backend_unavailable',
				'Unable to connect to the FULLTEXT search backend.',
				[ 'status' => 503, 'backend_error' => $response->get_error_code() ]
			);
		}

		$status = (int) wp_remote_retrieve_response_code( $response );
		if ( $status < 200 || $status >= 300 ) {
			return new \WP_Error(
				'search_backend_error',
				'FULLTEXT search backend returned an error.',
				[ 'status' => 502, 'backend_status' => $status ]
			);
		}

		$data = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		return $this->validate_data( $data, $paginated );
	}

	/** @return array|\WP_Error */
	private function validate_data( $data, bool $paginated ) {
		if ( ! is_array( $data ) || ! isset( $data['items'] ) || ! is_array( $data['items'] ) ) {
			return new \WP_Error(
				'invalid_search_backend_response',
				'FULLTEXT search backend returned an invalid response.',
				[ 'status' => 502 ]
			);
		}

		if ( $paginated && ( ! isset( $data['page'], $data['per'] ) ) ) {
			return new \WP_Error(
				'invalid_search_backend_response',
				'FULLTEXT search backend omitted pagination metadata.',
				[ 'status' => 502 ]
			);
		}

		// Enforce fail-closed visibility only for search (paginated), not for suggest
		if ( $paginated ) {
			$data['items'] = $this->filter_public_results( $data['items'] );
		}

		return $data;
	}

	/**
	 * Filters search results to ensure only public content is returned.
	 *
	 * @param array $items
	 * @return array
	 */
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

			if ( ! function_exists( '\get_post' ) ) {
				$public[] = $item;
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

	/**
	 * Resolve post ID from various possible keys in the backend response.
	 *
	 * @param array $result
	 * @return int Post ID or 0 if not found.
	 */
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
}
