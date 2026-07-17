<?php
namespace TLU_Headless_API\Endpoints;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use TLU_Headless_API\Services\CorsPolicy;
use TLU_Headless_API\Services\CacheVersionStore;
use WP_REST_Server;
use WP_REST_Request;
use WP_REST_Response;

final class Cache {
	private CorsPolicy $cors;
	private CacheVersionStore $versions;

	public function __construct( ?CorsPolicy $cors = null, ?CacheVersionStore $versions = null ) {
		$this->cors     = $cors ?? new CorsPolicy();
		$this->versions = $versions ?? new CacheVersionStore();
	}

	public function register_routes(): void {
		register_rest_route( 'headless/v1', '/cache/status', [
			[
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => [ $this, 'get_status' ],
				'permission_callback' => [ $this, 'can_manage_options' ],
				'args'                => [],
			],
		] );

		register_rest_route( 'headless/v1', '/cache/purge', [
			[
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => [ $this, 'purge_cache' ],
				'permission_callback' => [ $this, 'can_manage_options' ],
				'args'                => [
					'domains' => [
						'type'     => 'array',
						'items'    => [ 'type' => 'string' ],
						'required' => false,
					],
					'all'     => [
						'type'     => 'boolean',
						'required' => false,
					],
				],
			],
		] );
	}

	public function get_status( WP_REST_Request $request ): WP_REST_Response {
		$enabled = function_exists( 'get_transient' );
		$backend = $enabled ? ( wp_using_ext_object_cache() ? 'object-cache' : 'transient' ) : 'disabled';

		$generations = $this->versions->get_many( $this->versions->get_all_domains() );
		ksort( $generations );

		return new WP_REST_Response( [
			'enabled'       => $enabled,
			'backend'       => $backend,
			'schema'        => TLU_HEADLESS_API_SCHEMA_VERSION,
			'generations'   => $generations,
			'configuration' => [
				'static_ttl'    => 3600,
				'content_ttl'   => 300,
				'discovery_ttl' => 30,
			],
		] );
	}

	public function purge_cache( WP_REST_Request $request ): WP_REST_Response {
		$params = $request->get_params();
		$domains = $params['domains'] ?? [];
		$all = ! empty( $params['all'] );

		if ( $all ) {
			$this->versions->reset_all();
		} elseif ( ! empty( $domains ) ) {
			$cleaned = [];
			foreach ( $domains as $domain ) {
				$domain = sanitize_key( (string) $domain );
				if ( in_array( $domain, $this->versions->get_all_domains(), true ) || str_starts_with( $domain, 'post_type:' ) || str_starts_with( $domain, 'taxonomy:' ) || str_starts_with( $domain, 'language:' ) ) {
					$cleaned[] = $domain;
				}
			}
			if ( ! empty( $cleaned ) ) {
				$this->versions->bump_many( $cleaned );
			}
		}

		return new WP_REST_Response( [ 'success' => true ] );
	}

	public function can_manage_options(): bool {
		return current_user_can( 'manage_options' );
	}
}