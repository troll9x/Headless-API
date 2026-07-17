<?php
namespace TLU_Headless_API\Integrations;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use TLU_Headless_API\Contracts\IntegrationInterface;

/**
 * Bọc toàn bộ ACF Pro API dùng trong framework.
 *
 * Mọi class khác phải gọi ACF thông qua class này — không gọi get_field(),
 * get_field_objects(), acf_add_options_page()… trực tiếp.
 * Điều này đảm bảo plugin không fatal error khi ACF không được cài.
 *
 * Plugin không đăng ký ACF field group. ACF Pro tự quản lý điều đó.
 */
class AcfIntegration implements IntegrationInterface {

	/** Kiểm tra ACF Pro có đang hoạt động không. */
	public function is_active(): bool {
		return function_exists( 'get_field' );
	}

	/** Kiểm tra ACF có hỗ trợ đăng ký options page không. */
	public function can_add_options_pages(): bool {
		return function_exists( 'acf_add_options_page' );
	}

	/**
	 * Trả về field objects của một post, được key theo tên field.
	 * Trả về [] nếu ACF không hoạt động hoặc post chưa có field nào.
	 *
	 * @param  int $post_id WordPress post ID.
	 */
	public function get_field_objects( int $post_id ): array {
		if ( ! $this->is_active() ) {
			return [];
		}
		$fields = get_field_objects( $post_id );
		return is_array( $fields ) ? $fields : [];
	}

	/**
	 * Trả về field objects của một options page.
	 *
	 * @param  string $key ACF options page post_id (vd: 'options', 'global_settings').
	 */
	public function get_options_field_objects( string $key ): array {
		if ( ! $this->is_active() ) {
			return [];
		}
		$fields = get_field_objects( $key );
		return is_array( $fields ) ? $fields : [];
	}

	/**
	 * Kiểm tra field có được phép expose trong REST API không.
	 *
	 * @param array $field Định nghĩa field ACF.
	 * @param array $allowlist Danh sách name hoặc key được phép.
	 * @return bool
	 */
	public function is_field_public( array $field, array $allowlist ): bool {
		if ( empty( $field['show_in_rest'] ) ) {
			return false;
		}

		$parent = (string) ( $field['parent'] ?? '' );

		if (
			'' === $parent
			|| ! function_exists( 'acf_get_field_group' )
		) {
			return false;
		}

		$group = acf_get_field_group( $parent );

		if (
			! is_array( $group )
			|| empty( $group['show_in_rest'] )
		) {
			return false;
		}

		$name = (string) ( $field['name'] ?? '' );
		$key  = (string) ( $field['key'] ?? '' );

		return in_array( $name, $allowlist, true )
			|| in_array( $key, $allowlist, true );
	}
}
