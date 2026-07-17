<?php
namespace TLU_Headless_API\Endpoints;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use TLU_Headless_API\Response;
use TLU_Headless_API\Services\OptionsService;
use TLU_Headless_API\Integrations\AcfIntegration;

/**
 * GET /headless/v1/options
 *
 * Trả về toàn bộ ACF field của một options page, đã chuẩn hóa theo field type.
 * Tham số `key` là ACF options page post_id (vd: 'options', 'global_settings').
 *
 * Bảo mật — Allowlist bắt buộc:
 * Mặc định KHÔNG có options page nào được phép truy cập công khai.
 * Dự án PHẢI khai báo rõ ràng từng key cho phép qua filter
 * `headless_api_allowed_options_pages` để tránh lộ secret ngoài ý muốn.
 *
 * Ví dụ khai báo trong functions.php hoặc plugin site-specific:
 *   add_filter( 'headless_api_allowed_options_pages', function( $keys ) {
 *       return array_merge( $keys, [ 'global_settings', 'header_options' ] );
 *   } );
 *
 * Lý do allowlist mặc định rỗng:
 * Options page ACF có thể chứa API key, webhook secret, cấu hình nội bộ,
 * credentials bên thứ ba hoặc dữ liệu nhạy cảm khác.
 * Buộc dự án khai báo tường minh thay vì assume 'options' là an toàn.
 */
class Options {

	private OptionsService $service;
	private AcfIntegration $acf;

	public function __construct() {
		$this->service = new OptionsService();
		$this->acf     = new AcfIntegration();
	}

	public function register_routes(): void {
		register_rest_route(
			HEADLESS_API_NAMESPACE,
			'/options',
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
				'ACF chưa được kích hoạt. Endpoint options yêu cầu Advanced Custom Fields.'
			);
		}

		$key  = $request->get_param( 'key' );
		$lang = $request->get_param( 'lang' );

		// Kiểm tra key có trong allowlist không — trước khi đọc bất kỳ dữ liệu nào.
		if ( ! $this->is_allowed_key( $key ) ) {
			return Response::error(
				'options_not_allowed',
				sprintf(
					'Options page "%s" không có trong danh sách cho phép. Thêm key vào filter "headless_api_allowed_options_pages".',
					esc_html( $key )
				),
				403
			);
		}

		$fields = $this->service->get_options( $key, $lang );

		if ( empty( $fields ) ) {
			return Response::not_found(
				sprintf(
					'Không tìm thấy ACF field nào cho options key "%s". Kiểm tra options page tồn tại và đã có field group gán vào.',
					esc_html( $key )
				)
			);
		}

		return Response::success( [
			'key'    => $key,
			'lang'   => $lang,
			'fields' => $fields,
		] );
	}

	// ── Private ───────────────────────────────────────────────────────────────

	/**
	 * Kiểm tra key có trong danh sách cho phép không.
	 *
	 * Allowlist mặc định: [] (rỗng) — an toàn nhất.
	 * Dự án phải khai báo tường minh các key qua filter.
	 * Key từ filter được sanitize để tránh bypass bằng whitespace hoặc ký tự lạ.
	 */
	private function is_allowed_key( string $key ): bool {
		/**
		 * Lọc danh sách options page key được phép trả về qua API công khai.
		 *
		 * Mặc định: [] (không có key nào được phép).
		 * Dự án PHẢI thêm key của mình vào đây. Không nên dùng wildcard.
		 *
		 * @param string[] $allowed_keys  Mảng key được phép.
		 */
		$raw     = (array) apply_filters( 'headless_api_allowed_options_pages', [] );
		$allowed = array_filter(
			array_map( 'sanitize_key', $raw ),
			fn( $k ) => '' !== $k
		);
		return in_array( sanitize_key( $key ), array_values( $allowed ), true );
	}

	private function get_args(): array {
		return [
			'key'  => [
				'required'          => true,
				'sanitize_callback' => 'sanitize_key',
				'validate_callback' => fn( $v ) => is_string( $v ) && preg_match( '/^[a-z0-9_-]{1,100}$/', $v ),
				'description'       => 'ACF options page post_id key (phải nằm trong allowlist).',
			],
			'lang' => [
				'default'           => '',
				'sanitize_callback' => 'sanitize_key',
				'validate_callback' => fn( $v ) => is_string( $v ) && preg_match( '/^[a-z]{0,10}$/', $v ),
				'description'       => 'Mã ngôn ngữ Polylang.',
			],
		];
	}
}
