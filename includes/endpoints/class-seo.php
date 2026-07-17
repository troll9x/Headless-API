<?php
namespace TLU_Headless_API\Endpoints;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use TLU_Headless_API\Response;
use TLU_Headless_API\Services\SeoService;

/**
 * GET /headless/v1/seo
 *
 * Trả về SEO metadata cho bất kỳ post/page nào.
 * Ưu tiên Rank Math khi đang hoạt động; fallback về WordPress native.
 * Cần cung cấp `id` hoặc `slug`.
 *
 * Response shape:
 *   id, slug, type, lang, title, description, canonical,
 *   robots (array), open_graph, twitter, schema_json, hreflang
 */
class Seo {

	private SeoService $service;

	public function __construct() {
		$this->service = new SeoService();
	}

	public function register_routes(): void {
		register_rest_route(
			HEADLESS_API_NAMESPACE,
			'/seo',
			[
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => [ $this, 'handle' ],
				'permission_callback' => '__return_true',
				'args'                => $this->get_args(),
			]
		);
	}

	public function handle( \WP_REST_Request $request ) {
		$id   = (int) $request->get_param( 'id' );
		$slug = $request->get_param( 'slug' );
		$type = $request->get_param( 'type' );
		$lang = $request->get_param( 'lang' );

		if ( 0 === $id && '' === $slug ) {
			return Response::bad_request( 'Cần cung cấp "id" hoặc "slug".' );
		}

		if ( $id > 0 ) {
			$data = $this->service->get_seo_by_id( $id );
		} else {
			$data = $this->service->get_seo( $slug, $type, $lang );
		}

		if ( null === $data ) {
			return Response::not_found( 'Không tìm thấy post.' );
		}

		// Đảm bảo lang luôn có trong response kể cả khi lấy bằng id.
		if ( ! isset( $data['lang'] ) ) {
			$data['lang'] = $lang;
		}

		return Response::success( $data );
	}

	// ── Private ───────────────────────────────────────────────────────────────

	private function get_args(): array {
		return [
			'id'   => [
				'default'           => 0,
				'sanitize_callback' => 'absint',
				'validate_callback' => fn( $v ) => is_numeric( $v ) && (int) $v >= 0,
				'description'       => 'Post ID. Dùng id hoặc slug.',
			],
			'slug' => [
				'default'           => '',
				'sanitize_callback' => 'sanitize_title',
				'validate_callback' => fn( $v ) => is_string( $v ) && strlen( $v ) <= 200,
				'description'       => 'Post slug.',
			],
			'type' => [
				'default'           => 'page',
				'sanitize_callback' => 'sanitize_key',
				'validate_callback' => fn( $v ) => $this->is_valid_post_type( $v ),
				'description'       => 'WordPress post type (phải là public).',
			],
			'lang' => [
				'default'           => '',
				'sanitize_callback' => 'sanitize_key',
				'validate_callback' => fn( $v ) => is_string( $v ) && preg_match( '/^[a-z]{0,10}$/', $v ),
				'description'       => 'Mã ngôn ngữ Polylang.',
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
