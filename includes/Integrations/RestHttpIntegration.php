<?php
namespace TLU_Headless_API\Integrations;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use TLU_Headless_API\Services\CorsPolicy;
use TLU_Headless_API\Services\HttpCachePolicy;
use TLU_Headless_API\Services\CacheVersionStore;
use TLU_Headless_API\Services\CacheKeyBuilder;
use TLU_Headless_API\Cache\TransientCache;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

final class RestHttpIntegration {
	private CorsPolicy $cors;
	private HttpCachePolicy $cache_policy;
	private CacheVersionStore $versions;
	private CacheKeyBuilder $key_builder;
	private TransientCache $cache;
	private bool $enabled;
	private string $backend;

	public function __construct( ?CorsPolicy $cors = null, ?HttpCachePolicy $cache_policy = null, ?CacheVersionStore $versions = null, ?CacheKeyBuilder $key_builder = null, ?TransientCache $cache = null ) {
		$this->cors        = $cors ?? new CorsPolicy();
		$this->cache_policy = $cache_policy ?? new HttpCachePolicy( $this->cors );
		$this->versions    = $versions ?? new CacheVersionStore();
		$this->key_builder = $key_builder ?? new CacheKeyBuilder( $this->versions, $this->cors );
		$this->cache       = $cache ?? TransientCache::from_config();
		$this->enabled     = $this->cache->enabled();
		$this->backend     = $this->enabled ? ( wp_using_ext_object_cache() ? 'object-cache' : 'transient' ) : 'disabled';
	}

	public function register(): void {
		// CORS: Chạy sau core, dùng filter cuối cùng
		add_filter( 'rest_pre_serve_request', [ $this, 'apply_cors_headers' ], PHP_INT_MAX, 4 );

		// Cache: pre_dispatch để check cache
		add_filter( 'rest_pre_dispatch', [ $this, 'check_cache' ], 10, 3 );

		// Cache: post_dispatch để lưu response
		add_filter( 'rest_post_dispatch', [ $this, 'store_cache' ], 10, 3 );

		// ETag/Last-Modified: sau dispatch
		add_filter( 'rest_post_dispatch', [ $this, 'add_validation_headers' ], 20, 3 );

		// 304 responses cho conditional GET
		add_filter( 'rest_pre_dispatch', [ $this, 'handle_conditional_get' ], 5, 3 );
	}

	public function apply_cors_headers( bool $served, \WP_HTTP_Response $response, WP_REST_Request $request, WP_REST_Server $server ): bool {
		if ( ! $this->cors->is_plugin_route( $request->get_route() ) ) {
			return $served;
		}

		$origin = $this->get_request_origin();
		if ( '' === $origin ) {
			return $served;
		}

		if ( ! $this->cors->is_cross_origin_allowed_for_request( $request, $origin ) ) {
			foreach ( [ 'Access-Control-Allow-Origin', 'Access-Control-Allow-Credentials', 'Access-Control-Allow-Methods', 'Access-Control-Allow-Headers', 'Access-Control-Expose-Headers', 'Access-Control-Max-Age' ] as $header ) {
				$response->remove_header( $header );
				header_remove( $header );
			}
			return $served;
		}

		$response->header( 'Access-Control-Allow-Origin', $origin );
		$response->header( 'Access-Control-Allow-Methods', implode( ', ', $this->cors->get_allowed_methods( $request ) ) );
		$response->header( 'Access-Control-Allow-Headers', implode( ', ', $this->cors->get_allowed_headers( $request ) ) );
		$response->header( 'Access-Control-Expose-Headers', implode( ', ', $this->cors->get_exposed_headers() ) );
		$response->header( 'Access-Control-Max-Age', (string) $this->cors->get_preflight_max_age() );
		$response->header( 'Vary', $this->get_vary_header( $response ) );
		header( 'Access-Control-Allow-Origin: ' . $origin, true );
		header( 'Access-Control-Allow-Methods: ' . implode( ', ', $this->cors->get_allowed_methods( $request ) ), true );
		header( 'Access-Control-Allow-Headers: ' . implode( ', ', $this->cors->get_allowed_headers( $request ) ), true );
		header( 'Access-Control-Allow-Credentials: true', true );
		header( 'Vary: Origin', false );

		return $served;
	}

	public function check_cache( $result, WP_REST_Server $server, WP_REST_Request $request ): mixed {
		if ( ! $this->enabled || ! $this->cors->is_plugin_route( $request->get_route() ) ) {
			return $result;
		}

		if ( ! $this->cache_policy->is_cacheable_request( $request ) ) {
			return $result;
		}

		$key = $this->key_builder->build( $request );
		if ( $this->key_builder->should_bypass( $request ) ) {
			return $result;
		}

		$cached = $this->cache->get( $key );
		if ( false !== $cached && is_array( $cached ) ) {
			$response = new WP_REST_Response( $cached['data'] ?? [], $cached['status'] ?? 200 );
			foreach ( $cached['headers'] ?? [] as $name => $value ) {
				$response->header( $name, (string) $value );
			}
			$response->header( 'X-Headless-Cache', 'HIT' );

			return $response;
		}

		$response = new WP_REST_Response();
		$response->header( 'X-Headless-Cache', 'MISS' );

		return $result;
	}

	public function store_cache( $result, WP_REST_Server $server, WP_REST_Request $request ): mixed {
		if ( ! $this->enabled || ! $this->cors->is_plugin_route( $request->get_route() ) ) {
			return $result;
		}

		$response = rest_ensure_response( $result );
		if ( ! $response instanceof WP_REST_Response || ! $this->cache_policy->is_cacheable_response( $request, $response ) ) {
			return $result;
		}

		$key = $this->key_builder->build( $request );
		if ( $this->key_builder->should_bypass( $request ) ) {
			return $result;
		}

		$ttl = $this->cache_policy->get_ttl( $this->cache_policy->classify( $request, $response ) );
		if ( 0 === $ttl ) {
			return $result;
		}

		$safe_headers = $this->extract_safe_headers( $response );

		$entry = [
			'data'          => $response->get_data(),
			'status'        => $response->get_status(),
			'headers'       => $safe_headers,
			'etag'          => $response->get_header( 'ETag' ),
			'last_modified' => $response->get_header( 'Last-Modified' ),
			'created_at'    => time(),
			'expires_at'    => time() + $ttl,
		];

		// Check entry size
		if ( strlen( wp_json_encode( $entry ) ) > (int) apply_filters( 'headless_api_response_cache_max_bytes', 1048576 ) ) {
			return $result;
		}

		$this->cache->set( $key, $entry, $ttl );
		$response->header( 'X-Headless-Cache', 'STORED' );

		return $result;
	}

	public function add_validation_headers( $result, WP_REST_Server $server, WP_REST_Request $request ): mixed {
		if ( ! $this->cors->is_plugin_route( $request->get_route() ) ) {
			return $result;
		}

		$response = rest_ensure_response( $result );
		if ( ! $response instanceof WP_REST_Response ) {
			return $result;
		}

		$response->header( 'X-Headless-Schema', TLU_HEADLESS_API_SCHEMA_VERSION );
		$response->header( 'X-Headless-Cache', $response->get_header( 'X-Headless-Cache' ) ?: 'BYPASS' );

		$cache_key = $this->key_builder->build( $request );
		$cached = $this->cache->get( $cache_key );

		if ( $cached && is_array( $cached ) ) {
			if ( ! $response->get_header( 'ETag' ) ) {
				$response->header( 'ETag', $cached['etag'] ?? '' );
			}
			if ( ! $response->get_header( 'Last-Modified' ) && isset( $cached['last_modified'] ) ) {
				$response->header( 'Last-Modified', (string) $cached['last_modified'] );
			}
			$response->header( 'Cache-Control', $this->cache_policy->get_headers( $this->cache_policy->classify( $request, $response ) )['Cache-Control'] ?? '' );
		}

		return $result;
	}

	public function handle_conditional_get( $result, WP_REST_Server $server, WP_REST_Request $request ): mixed {
		if ( ! in_array( strtoupper( $request->get_method() ), [ 'GET', 'HEAD' ], true ) ) {
			return $result;
		}

		$cache_key = $this->key_builder->build( $request );
		$cached = $this->cache->get( $cache_key );

		if ( ! $cached || ! is_array( $cached ) ) {
			return $result;
		}

		$response = new WP_REST_Response();
		$response->set_status( 304 );

		if ( $request->get_header( 'If-None-Match' ) ) {
			$etag = $request->get_header( 'If-None-Match' );
			$expected = $cached['etag'] ?? '';
			if ( $this->etag_match( $etag, $expected ) ) {
				$response->set_data( null );
				return $response;
			}
		}

		if ( $request->get_header( 'If-Modified-Since' ) && $cached['last_modified'] ) {
			$modified_since = $request->get_header( 'If-Modified-Since' );
			$last_modified = $cached['last_modified'];
			if ( strtotime( $modified_since ) >= strtotime( $last_modified ) ) {
				$response->set_data( null );
				return $response;
			}
		}

		return $result;
	}

	private function get_request_origin(): string {
		if ( isset( $_SERVER['HTTP_ORIGIN'] ) ) {
			$origin = trim( wp_unslash( $_SERVER['HTTP_ORIGIN'] ) );
			return $this->cors->normalize_origin( $origin );
		}
		return '';
	}

	private function get_vary_header( WP_REST_Response $response ): string {
		$existing = $response->get_headers()['Vary'] ?? '';
		$parts = $existing ? array_map( 'trim', explode( ',', $existing ) ) : [];
		$cors_vars = [ 'Origin', 'Access-Control-Request-Method', 'Access-Control-Request-Headers' ];

		foreach ( $cors_vars as $var ) {
			$var_lower = strtolower( $var );
			$found = false;
			foreach ( $parts as $p ) {
				if ( strtolower( $p ) === $var_lower ) {
					$found = true;
					break;
				}
			}
			if ( ! $found ) {
				$parts[] = $var;
			}
		}

		return implode( ', ', array_unique( array_filter( array_map( 'trim', $parts ) ) ) );
	}

	private function extract_safe_headers( WP_REST_Response $response ): array {
		$headers = $response->get_headers();
		$safe = [];
		$forbidden = [ 'set-cookie', 'authorization', 'www-authenticate' ];

		foreach ( $headers as $name => $value ) {
			$name_lower = strtolower( (string) $name );
			if ( in_array( $name_lower, $forbidden, true ) ) {
				continue;
			}
			$safe[ $name ] = is_array( $value ) ? implode( ', ', (array) $value ) : (string) $value;
		}

		return $safe;
	}

	private function etag_match( string $etag_header, string $expected ): bool {
		$etags = preg_split( '/\s*,\s*/', $etag_header );
		foreach ( $etags as $etag ) {
			$etag = trim( $etag );
			if ( $etag === $expected || '*' === $etag || 'W/"' . substr( $expected, 2 ) === $etag ) {
				return true;
			}
		}
		return false;
	}
}
