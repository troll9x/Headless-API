<?php
namespace TLU_Headless_API\Endpoints;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use TLU_Headless_API\Response;
use TLU_Headless_API\Services\HomepageSnapshotService;
use TLU_Headless_API\Integrations\PolylangIntegration;
use WP_REST_Request;
use WP_REST_Server;

/** API gộp nội dung các section trang chủ theo ngôn ngữ. */
final class Homepage {
	private HomepageSnapshotService $service;
	private PolylangIntegration $polylang;

	public function __construct( ?HomepageSnapshotService $service = null, ?PolylangIntegration $polylang = null ) {
		$this->service  = $service ?? new HomepageSnapshotService();
		$this->polylang = $polylang ?? new PolylangIntegration();
	}

	public function register_routes(): void {
		register_rest_route( HEADLESS_API_NAMESPACE, '/homepage', [
			[
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => [ $this, 'handle' ],
				'permission_callback' => '__return_true',
				'args'                => [
					'lang' => [
						'required'          => true,
						'sanitize_callback' => 'sanitize_key',
						'validate_callback' => static fn( $value ): bool => is_string( $value ) && in_array( $value, [ 'vi', 'en' ], true ),
					],
				],
			]
		] );
	}

	public function handle( WP_REST_Request $request ) {
		$requested = sanitize_key( (string) $request->get_param( 'lang' ) );
		$locale    = $this->polylang->normalize_language( $requested );
		if ( ! in_array( $locale, [ 'vi', 'en' ], true ) || $locale !== $requested ) {
			return Response::error( 'homepage_invalid_language', 'Ngôn ngữ trang chủ phải là vi hoặc en.', 400 );
		}

		$snapshot = $this->service->get_snapshot( $locale );
		if ( is_wp_error( $snapshot ) ) {
			return $snapshot;
		}

		return Response::success( $snapshot );
	}
}
