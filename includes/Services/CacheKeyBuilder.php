<?php
namespace TLU_Headless_API\Services;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use TLU_Headless_API\Config;
use WP_REST_Request;

final class CacheKeyBuilder {
	private CacheVersionStore $versions;
	private CorsPolicy $cors;

	public function __construct( ?CacheVersionStore $versions = null, ?CorsPolicy $cors = null ) {
		$this->versions = $versions ?? new CacheVersionStore();
		$this->cors     = $cors ?? new CorsPolicy();
	}

	public function build( WP_REST_Request $request ): string {
		$descriptor = [
			'method'      => strtoupper( $request->get_method() ),
			'route'       => $this->normalize_route( $request->get_route() ),
			'query'       => $this->canonical_query( $request ),
			'schema'      => Config::schema_version(),
			'blog_id'     => function_exists( 'get_current_blog_id' ) ? (int) get_current_blog_id() : 1,
			'frontend'    => Config::frontend_url(),
			'generations' => $this->versions->get_many( $this->versions->relevant_domains_for_request( $request ) ),
		];

		return 'tlu_headless:v' . Config::schema_version() . ':site:' . $descriptor['blog_id'] . ':' . hash( 'sha256', wp_json_encode( $descriptor ) );
	}

	public function should_bypass( WP_REST_Request $request ): bool {
		if ( ! $this->cors->is_plugin_route( $request->get_route() ) ) {
			return true;
		}

		$params = $request->get_params();
		$unsafe = [ 'token', 'preview_token', 'secret', 'nonce', 'password', 'authorization', '_envelope', '_jsonp' ];
		foreach ( $unsafe as $name ) {
			if ( isset( $params[ $name ] ) ) {
				return true;
			}
		}

		$allowed = [
			'lang', 'slug', 'id', 'path', 'url', 'post_type', 'type', 'taxonomy', 'location',
			'key', 'q', 'per', 'page', '_fields', '_embed', '_context',
		];
		$allowed = array_merge( $allowed, (array) apply_filters( 'headless_api_cache_allowed_query_args', [] ) );

		foreach ( array_keys( $params ) as $key ) {
			if ( in_array( $key, $this->ignored_query_args(), true ) ) {
				continue;
			}
			if ( ! in_array( $key, $allowed, true ) ) {
				return true;
			}
		}

		return false;
	}

	private function normalize_route( string $route ): string {
		$route = '/' . ltrim( $route, '/' );
		return preg_replace( '#/+#', '/', $route ) ?: '/';
	}

	private function canonical_query( WP_REST_Request $request ): array {
		$params = $request->get_params();

		foreach ( $this->ignored_query_args() as $ignored ) {
			unset( $params[ $ignored ] );
		}

		unset( $params['token'], $params['preview_token'], $params['secret'], $params['nonce'], $params['password'], $params['authorization'] );

		$normalized = [];
		foreach ( $params as $key => $value ) {
			$normalized[ (string) $key ] = $this->normalize_value( $value, (string) $key );
		}

		ksort( $normalized );
		return $normalized;
	}

	private function normalize_value( $value, string $key ) {
		if ( is_bool( $value ) ) {
			return $value;
		}

		if ( is_string( $value ) ) {
			$trimmed = trim( $value );
			if ( is_numeric( $trimmed ) && preg_match( '/^-?\d+$/', $trimmed ) ) {
				return (int) $trimmed;
			}
			if ( in_array( strtolower( $trimmed ), [ 'true', 'false' ], true ) ) {
				return 'true' === strtolower( $trimmed );
			}
			return $trimmed;
		}

		if ( is_array( $value ) ) {
			$is_assoc = array_keys( $value ) !== range( 0, count( $value ) - 1 );
			if ( $is_assoc ) {
				$out = [];
				foreach ( $value as $k => $v ) {
					$out[ (string) $k ] = $this->normalize_value( $v, (string) $k );
				}
				ksort( $out );
				return $out;
			}

			$out = array_map( fn( $v ) => $this->normalize_value( $v, $key ), $value );

			if ( ! in_array( $key, [ '_fields' ], true ) ) {
				sort( $out );
			}

			return array_values( $out );
		}

		return $value;
	}

	private function ignored_query_args(): array {
		$args = [
			'utm_source',
			'utm_medium',
			'utm_campaign',
			'utm_term',
			'utm_content',
			'gclid',
			'fbclid',
		];

		$args = apply_filters( 'headless_api_cache_ignored_query_args', $args );
		return is_array( $args ) ? array_values( array_unique( array_map( 'strval', $args ) ) ) : [];
	}
}