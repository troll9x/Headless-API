<?php
namespace TLU_Headless_API\Endpoints;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use TLU_Headless_API\Response;
use TLU_Headless_API\Services\PageService;
use TLU_Headless_API\Integrations\AcfIntegration;

/**
 * GET /headless/v1/page-blocks
 *
 * Trả về thông tin page + ACF flexible_content blocks.
 * Yêu cầu ACF Pro đang hoạt động.
 */
class Page_Blocks {

	private PageService    $service;
	private AcfIntegration $acf;

	public function __construct() {
		$this->service = new PageService();
		$this->acf     = new AcfIntegration();
	}

	public function register_routes(): void {
		register_rest_route(
			HEADLESS_API_NAMESPACE,
			'/page-blocks',
			[
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => [ $this, 'handle' ],
				'permission_callback' => '__return_true',
				'args'                => $this->get_args(),
			]
		);
	}

	public function handle( \WP_REST_Request $request ) {
		if ( ! $this->acf->is_active() ) {
			return Response::service_unavailable(
				'acf_required',
				'ACF chưa được kích hoạt. Endpoint page-blocks yêu cầu Advanced Custom Fields.'
			);
		}

		$slug      = $request->get_param( 'slug' );
		$lang      = $request->get_param( 'lang' );
		$post_type = $request->get_param( 'post_type' );

		$data = $this->service->get_page_blocks( $slug, $post_type, $lang );
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

	private function is_valid_post_type( string $value ): bool {
		if ( 'any' === $value ) {
			return true;
		}
		$obj = get_post_type_object( sanitize_key( $value ) );
		return null !== $obj && true === $obj->public;
	}
}
