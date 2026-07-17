<?php
namespace TLU_Headless_API\Endpoints;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use TLU_Headless_API\Response;
use TLU_Headless_API\Services\PageService;

/**
 * GET /headless/v1/page
 *
 * Trả về dữ liệu post + toàn bộ ACF field (đã chuẩn hóa theo type) + SEO metadata.
 * Chỉ trả về post ở trạng thái 'publish'; post type phải là public.
 */
class Page {

	private PageService $service;

	public function __construct() {
		$this->service = new PageService();
	}

	public function register_routes(): void {
		register_rest_route(
			HEADLESS_API_NAMESPACE,
			'/page',
			[
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => [ $this, 'handle' ],
				'permission_callback' => '__return_true',
				'args'                => $this->get_args(),
			]
		);
	}

	public function handle( \WP_REST_Request $request ) {
		$slug      = $request->get_param( 'slug' );
		$lang      = $request->get_param( 'lang' );
		$post_type = $request->get_param( 'post_type' );

		$data = $this->service->get_page( $slug, $post_type, $lang );
		if ( null === $data ) {
			return Response::not_found(
				sprintf( 'Không tìm thấy %s đã xuất bản có slug "%s".', $post_type, $slug )
			);
		}

		return Response::success( $data );
	}

	// ── Private ───────────────────────────────────────────────────────────────

	private function get_args(): array {
		return [
			'slug'      => [
				'required'          => true,
				'sanitize_callback' => 'sanitize_title',
				'validate_callback' => fn( $v ) => is_string( $v ) && strlen( trim( $v ) ) > 0 && strlen( $v ) <= 200,
				'description'       => 'Post slug (post_name).',
			],
			'lang'      => [
				'default'           => '',
				'sanitize_callback' => function( $value ) {
					return preg_replace( '/[^A-Za-z0-9_-]/', '', (string) $value );
				},
				'validate_callback' => fn( $v ) => is_string( $v ) && strlen( trim( $v ) ) <= 20,
				'description'       => 'Mã ngôn ngữ Polylang (vd: vi, en, en_US). Bỏ trống = ngôn ngữ mặc định.',
			],
			'post_type' => [
				'default'           => 'page',
				'sanitize_callback' => 'sanitize_key',
				'validate_callback' => fn( $v ) => $this->is_valid_post_type( $v ),
				'description'       => 'WordPress post type (phải là public).',
			],
		];
	}

	/**
	 * Kiểm tra post type hợp lệ: phải đã đăng ký và có thuộc tính public.
	 * Giá trị 'any' luôn được chấp nhận (chỉ trả về post đã publish bất kể type).
	 */
	private function is_valid_post_type( string $value ): bool {
		if ( 'any' === $value ) {
			return true;
		}
		$obj = get_post_type_object( sanitize_key( $value ) );
		return null !== $obj && true === $obj->public;
	}
}
