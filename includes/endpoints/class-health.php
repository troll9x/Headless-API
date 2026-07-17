<?php
namespace TLU_Headless_API\Endpoints;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Health {

	public function register_routes() {
		register_rest_route(
			TLU_HEADLESS_API_NAMESPACE,
			'/health',
			[
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => [ $this, 'handle' ],
				'permission_callback' => '__return_true',
			]
		);
	}

	public function handle( \WP_REST_Request $request ) {
		return rest_ensure_response( [
			'ok'       => true,
			'plugin'   => 'Headless API',
			'version'  => TLU_HEADLESS_API_VERSION,
			'site_url' => esc_url_raw( site_url() ),
			'home_url' => esc_url_raw( home_url() ),
		] );
	}
}
