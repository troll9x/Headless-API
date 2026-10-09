<?php
namespace TLU_Headless_API\Endpoints;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use TLU_Headless_API\Response;
use TLU_Headless_API\Services\OrganizationService;

/**
 * Public read-only API cho danh sách và hồ sơ tổ chức.
 */
final class Organizations {

	private OrganizationService $service;

	public function __construct( ?OrganizationService $service = null ) {
		$this->service = $service ?? new OrganizationService();
	}

	public function register_routes(): void {
		register_rest_route( HEADLESS_API_NAMESPACE, '/organizations/members/(?P<slug>[^/]+)', [
			[
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => [ $this, 'get_member' ],
				'permission_callback' => '__return_true',
				'args'                => $this->detail_args(),
			],
		] );

		register_rest_route( HEADLESS_API_NAMESPACE, '/organizations/(?P<slug>[^/]+)', [
			[
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => [ $this, 'get_organization' ],
				'permission_callback' => '__return_true',
				'args'                => $this->detail_args(),
			],
		] );
	}

	public function get_organization( \WP_REST_Request $request ) {
		$result = $this->service->get_by_category(
			(string) $request->get_param( 'slug' ),
			(string) $request->get_param( 'lang' )
		);

		return is_wp_error( $result ) ? $result : Response::success( $result );
	}

	public function get_member( \WP_REST_Request $request ) {
		$result = $this->service->get_member(
			(string) $request->get_param( 'slug' ),
			(string) $request->get_param( 'lang' )
		);

		return is_wp_error( $result ) ? $result : Response::success( $result );
	}

	private function detail_args(): array {
		return [
			'slug' => [
				'required'          => true,
				'sanitize_callback' => 'sanitize_title',
				'validate_callback' => fn( $value ): bool => is_string( $value ) && '' !== trim( $value ) && strlen( $value ) <= 200,
			],
			'lang' => [
				'default'           => '',
				'sanitize_callback' => fn( $value ): string => (string) preg_replace( '/[^A-Za-z0-9_-]/', '', (string) $value ),
				'validate_callback' => fn( $value ): bool => is_string( $value ) && strlen( $value ) <= 20,
			],
		];
	}
}
