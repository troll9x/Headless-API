<?php
namespace TLU_Headless_API\Services;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use WP_REST_Request;
use WP_REST_Response;

final class HttpCachePolicy {
	public const STATIC = 'STATIC';
	public const CONTENT = 'CONTENT';
	public const DISCOVERY = 'DISCOVERY';
	public const NEGATIVE = 'NEGATIVE';
	public const NO_STORE = 'NO_STORE';

	private CorsPolicy $cors;

	public function __construct( ?CorsPolicy $cors = null ) {
		$this->cors = $cors ?? new CorsPolicy();
	}

	public function classify( WP_REST_Request $request, WP_REST_Response $response = null ): string {
		if ( ! $this->cors->is_plugin_route( $request->get_route() ) || $this->is_sensitive_request( $request ) ) {
			return self::NO_STORE;
		}
		if ( $response ) {
			$status = (int) $response->get_status();
			if ( in_array( $status, [ 400, 401, 403, 409, 422, 429 ], true ) || $status >= 500 || $this->has_set_cookie( $response ) ) {
				return self::NO_STORE;
			}
			if ( 404 === $status ) {
				return self::NEGATIVE;
			}
		}
		$route = $request->get_route();
		if ( preg_match( '#/(preview|preview-token|revalidation|cache)(/|$)#', $route ) ) {
			return self::NO_STORE;
		}
		if ( preg_match( '#/(search|suggest)(/|$)#', $route ) ) {
			return self::DISCOVERY;
		}
		if ( preg_match( '#/(health|schema|settings)(/|$)#', $route ) ) {
			return self::STATIC;
		}
		if ( in_array( strtoupper( $request->get_method() ), [ 'GET', 'HEAD' ], true ) ) {
			return self::CONTENT;
		}
		return self::NO_STORE;
	}

	public function is_cacheable_request( WP_REST_Request $request ): bool {
		if ( ! in_array( strtoupper( $request->get_method() ), [ 'GET', 'HEAD' ], true ) ) {
			return false;
		}
		if ( $this->is_sensitive_request( $request ) ) {
			return false;
		}
		$params = $request->get_params();
		foreach ( [ 'token', 'preview_token', 'secret', 'nonce', 'password', 'authorization', '_envelope', '_jsonp' ] as $name ) {
			if ( isset( $params[ $name ] ) ) {
				return false;
			}
		}
		if ( isset( $params['_context'] ) && 'view' !== $params['_context'] ) {
			return false;
		}
		return ! $this->cors->is_privileged_route( $request );
	}

	public function is_cacheable_response( WP_REST_Request $request, WP_REST_Response $response ): bool {
		$status = (int) $response->get_status();
		return $this->is_cacheable_request( $request )
			&& $status >= 200 && $status < 300
			&& ! $this->has_set_cookie( $response );
	}

	public function get_headers( string $profile ): array {
		$headers = [
			self::STATIC => [ 'Cache-Control' => 'public, max-age=0, s-maxage=3600, stale-while-revalidate=86400' ],
			self::CONTENT => [ 'Cache-Control' => 'public, max-age=0, s-maxage=300, stale-while-revalidate=3600' ],
			self::DISCOVERY => [ 'Cache-Control' => 'public, max-age=0, s-maxage=30, stale-while-revalidate=60' ],
			self::NEGATIVE => [ 'Cache-Control' => 'public, max-age=0, s-maxage=15' ],
			self::NO_STORE => [ 'Cache-Control' => 'private, no-store, no-cache, must-revalidate, max-age=0', 'Pragma' => 'no-cache', 'Expires' => '0' ],
		][ $profile ] ?? [ 'Cache-Control' => 'private, no-store, no-cache, must-revalidate, max-age=0', 'Pragma' => 'no-cache', 'Expires' => '0' ];
		$headers = apply_filters( 'headless_api_http_cache_headers', $headers, $profile );
		if ( self::NO_STORE === $profile ) {
			$headers['Cache-Control'] = 'private, no-store, no-cache, must-revalidate, max-age=0';
			$headers['Pragma'] = 'no-cache';
			$headers['Expires'] = '0';
		}
		return is_array( $headers ) ? $headers : [];
	}

	public function get_ttl( string $profile ): int {
		$ttl = [ self::STATIC => 3600, self::CONTENT => 300, self::DISCOVERY => 30, self::NEGATIVE => 0, self::NO_STORE => 0 ][ $profile ] ?? 0;
		$ttl = (int) apply_filters( 'headless_api_http_cache_ttl', $ttl, $profile );
		return self::NO_STORE === $profile ? 0 : max( 0, $ttl );
	}

	private function is_sensitive_request( WP_REST_Request $request ): bool {
		if ( is_user_logged_in() ) {
			return true;
		}
		$headers = array_change_key_case( $request->get_headers(), CASE_LOWER );
		if ( ! empty( $headers['authorization'] ) || ! empty( $headers['x_wp_nonce'] ) || ! empty( $headers['x-wp-nonce'] ) ) {
			return true;
		}
		return ! empty( $_COOKIE[ LOGGED_IN_COOKIE ] ?? '' ) || ! empty( $_COOKIE[ AUTH_COOKIE ] ?? '' ) || ! empty( $_COOKIE[ SECURE_AUTH_COOKIE ] ?? '' );
	}

	private function has_set_cookie( WP_REST_Response $response ): bool {
		foreach ( $response->get_headers() as $name => $value ) {
			if ( 0 === strcasecmp( (string) $name, 'Set-Cookie' ) ) {
				return true;
			}
		}
		return false;
	}
}