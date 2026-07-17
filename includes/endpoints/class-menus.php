<?php
namespace TLU_Headless_API\Endpoints;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use TLU_Headless_API\Response;
use TLU_Headless_API\Services\MenuService;

/**
 * GET /headless/v1/menus
 *
 * Trả về menu điều hướng dưới dạng cây phân cấp.
 * Hỗ trợ tra cứu theo location hoặc slug.
 * Hỗ trợ Polylang: tự động phân giải location đã dịch.
 * Cần cung cấp `location` hoặc `slug`.
 */
class Menus {

	private MenuService $service;

	public function __construct() {
		$this->service = new MenuService();
	}

	public function register_routes(): void {
		register_rest_route(
			HEADLESS_API_NAMESPACE,
			'/menus',
			[
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => [ $this, 'handle' ],
				'permission_callback' => '__return_true',
				'args'                => $this->get_args(),
			]
		);
	}

	public function handle( \WP_REST_Request $request ) {
		$location = $request->get_param( 'location' );
		$slug     = $request->get_param( 'slug' );
		$lang     = $request->get_param( 'lang' );

		if ( '' === $location && '' === $slug ) {
			return Response::bad_request( 'Cần cung cấp "location" hoặc "slug".' );
		}

		if ( '' !== $location ) {
			$menu_data = $this->service->get_menu( $location, $lang );
		} else {
			$menu_data = $this->service->get_menu_by_slug( $slug, $lang );
		}

		if ( null === $menu_data ) {
			return Response::not_found(
				sprintf( 'Không tìm thấy menu cho "%s".', $location ?: $slug )
			);
		}

		return Response::success( array_merge(
			[ 'location' => $location, 'lang' => $lang ],
			$menu_data
		) );
	}

	// ── Private ───────────────────────────────────────────────────────────────

	private function get_args(): array {
		return [
			'location' => [
				'default'           => '',
				'sanitize_callback' => 'sanitize_key',
				'validate_callback' => fn( $v ) => is_string( $v ) && strlen( $v ) <= 100,
				'description'       => 'Menu location slug đã đăng ký trong WordPress.',
			],
			'slug'     => [
				'default'           => '',
				'sanitize_callback' => 'sanitize_title',
				'validate_callback' => fn( $v ) => is_string( $v ) && strlen( $v ) <= 200,
				'description'       => 'Menu slug (dùng khi location không có).',
			],
			'lang'     => [
				'default'           => '',
				'sanitize_callback' => 'sanitize_key',
				'validate_callback' => fn( $v ) => is_string( $v ) && preg_match( '/^[a-z]{0,10}$/', $v ),
				'description'       => 'Mã ngôn ngữ Polylang.',
			],
		];
	}
}
