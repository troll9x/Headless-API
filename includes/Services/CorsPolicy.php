<?php
namespace TLU_Headless_API\Services;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use TLU_Headless_API\Config;
use WP_REST_Request;

/**
 * Quản lý chính sách CORS cho Headless API.
 * 
 * Đảm bảo chỉ các origin hợp lệ trong allowlist được phép truy cập
 * và chỉ áp dụng cho các namespace của plugin.
 */
final class CorsPolicy {

	/**
	 * Kiểm tra xem một route có thuộc quản lý của plugin hay không.
	 * 
	 * @param string $route Route từ $request->get_route().
	 * @return bool
	 */
	public function is_plugin_route( string $route ): bool {
		if ( empty( $route ) ) {
			return false;
		}

		// Normalize: đảm bảo bắt đầu bằng /
		$route = '/' . ltrim( $route, '/' );

		// Match exact namespace prefixes
		$namespaces = [
			'/' . TLU_HEADLESS_API_NAMESPACE,
			'/' . HEADLESS_API_NAMESPACE,
		];

		foreach ( $namespaces as $ns ) {
			if ( $route === $ns || str_starts_with( $route, $ns . '/' ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Normalize origin về dạng scheme://host[:port]
	 * 
	 * @param string $origin
	 * @return string
	 */
	public function normalize_origin( string $origin ): string {
		$origin = trim( $origin );
		if ( '' === $origin ) {
			return '';
		}

		$parts = wp_parse_url( $origin );
		if ( ! is_array( $parts ) || empty( $parts['scheme'] ) || empty( $parts['host'] ) ) {
			return '';
		}

		$scheme = strtolower( $parts['scheme'] );
		if ( ! in_array( $scheme, [ 'http', 'https' ], true ) ) {
			return '';
		}

		$host = strtolower( $parts['host'] );

		// Bỏ default port
		if ( isset( $parts['port'] ) ) {
			if ( ( 'http' === $scheme && 80 === (int)$parts['port'] ) || 
			     ( 'https' === $scheme && 443 === (int)$parts['port'] ) ) {
				$port = '';
			} else {
				$port = ':' . (int)$parts['port'];
			}
		} else {
			$port = '';
		}

		// Reject if contains user/pass, path, query or fragment
		if ( ! empty( $parts['user'] ) || ! empty( $parts['pass'] ) || 
		     ! empty( $parts['path'] ) || ! empty( $parts['query'] ) || 
		     ! empty( $parts['fragment'] ) ) {
			return '';
		}

		return $scheme . '://' . $host . $port;
	}

	/**
	 * Lấy danh sách allowed origins từ Config và filter.
	 * 
	 * @return string[]
	 */
	public function get_allowed_origins(): array {
		$origins = Config::allowed_origins();
		
		// Apply filter
		$origins = apply_filters( 'headless_api_allowed_origins', $origins );
		if ( ! is_array( $origins ) ) {
			$origins = [];
		}

		// Normalize and validate all
		$normalized = [];
		foreach ( $origins as $origin ) {
			$norm = $this->normalize_origin( (string) $origin );
			if ( '' !== $norm ) {
				$normalized[] = $norm;
			}
		}

		return array_values( array_unique( $normalized ) );
	}

	/**
	 * Kiểm tra origin có được phép hay không.
	 * 
	 * @param string $origin
	 * @return bool
	 */
	public function is_origin_allowed( string $origin ): bool {
		$norm = $this->normalize_origin( $origin );
		if ( '' === $norm ) {
			return false;
		}

		return in_array( $norm, $this->get_allowed_origins(), true );
	}

	/**
	 * Kiểm tra xem request có được phép cross-origin hay không.
	 * 
	 * @param WP_REST_Request $request
	 * @param string $origin
	 * @return bool
	 */
	public function is_cross_origin_allowed_for_request( WP_REST_Request $request, string $origin ): bool {
		if ( '' === $origin ) {
			return true; // Same-origin or server-to-server
		}

		if ( ! $this->is_origin_allowed( $origin ) ) {
			return false;
		}

		// Privileged routes check
		if ( $this->is_privileged_route( $request ) ) {
			// Privileged routes require explicit opt-in via filter
			$privileged_origins = apply_filters( 'headless_api_privileged_cors_origins', [] );
			if ( ! is_array( $privileged_origins ) ) {
				return false;
			}
			$privileged_origins = array_filter( array_map( [ $this, 'normalize_origin' ], $privileged_origins ) );
			return in_array( $this->normalize_origin( $origin ), $privileged_origins, true );
		}

		return true;
	}

	/**
	 * Xác định route có phải là privileged (cần bảo mật cao hơn) hay không.
	 * 
	 * @param WP_REST_Request $request
	 * @return bool
	 */
	public function is_privileged_route( WP_REST_Request $request ): bool {
		$route = $request->get_route();
		
		$privileged_patterns = [
			'/preview-token',
			'/revalidation',
			'/cache',
		];

		foreach ( $privileged_patterns as $pattern ) {
			if ( str_contains( $route, $pattern ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Lấy danh sách methods được cho phép cho request.
	 * 
	 * @param WP_REST_Request $request
	 * @return string[]
	 */
	public function get_allowed_methods( WP_REST_Request $request ): array {
		$route = $request->get_route();
		
		// Admin mutation routes
		if ( $this->is_privileged_route( $request ) ) {
			return [ 'GET', 'HEAD', 'POST', 'OPTIONS' ];
		}

		// Public read routes
		return [ 'GET', 'HEAD', 'OPTIONS' ];
	}

	/**
	 * Lấy danh sách headers được cho phép.
	 * 
	 * @param WP_REST_Request $request
	 * @return string[]
	 */
	public function get_allowed_headers( WP_REST_Request $request ): array {
		$headers = [
			'Accept',
			'Content-Type',
			'Authorization',
			'X-WP-Nonce',
		];

		$headers = apply_filters( 'headless_api_cors_allowed_headers', $headers );
		if ( ! is_array( $headers ) ) {
			$headers = [ 'Accept', 'Content-Type', 'Authorization', 'X-WP-Nonce' ];
		}

		// Normalize and dedupe
		$normalized = array_map( 'ucwords', array_map( 'strtolower', $headers ) );
		return array_values( array_unique( $normalized ) );
	}

	/**
	 * Lấy danh sách exposed headers.
	 * 
	 * @return string[]
	 */
	public function get_exposed_headers(): array {
		$headers = [
			'ETag',
			'Last-Modified',
			'X-Headless-Schema',
			'X-Headless-Cache',
		];

		$headers = apply_filters( 'headless_api_cors_exposed_headers', $headers );
		if ( ! is_array( $headers ) ) {
			return $headers;
		}

		return $headers;
	}

	/**
	 * Lấy Max-Age cho preflight.
	 * 
	 * @return int
	 */
	public function get_preflight_max_age(): int {
		$max_age = (int) apply_filters( 'headless_api_cors_preflight_max_age', 600 );
		return max( 0, min( 3600, $max_age ) );
	}
}
