<?php
namespace TLU_Headless_API\Endpoints;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Health {

	public function register_routes() {
		foreach ( [ TLU_HEADLESS_API_NAMESPACE, HEADLESS_API_NAMESPACE ] as $namespace ) {
			register_rest_route(
				$namespace,
				'/health',
				[
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => [ $this, 'handle' ],
					'permission_callback' => '__return_true',
				]
			);
		}
	}

	public function handle( \WP_REST_Request $request ) {
		return rest_ensure_response( [
			'ok'       => true,
			'plugin'   => 'Headless API',
			'version'  => TLU_HEADLESS_API_VERSION,
		] );
	}
}
