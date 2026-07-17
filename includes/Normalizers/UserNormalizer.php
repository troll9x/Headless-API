<?php
namespace TLU_Headless_API\Normalizers;

defined( 'ABSPATH' ) || exit;

use TLU_Headless_API\Contracts\NormalizerInterface;

/**
 * Chuẩn hóa dữ liệu user thành thông tin công khai an toàn.
 *
 * Không trả email, username đăng nhập, role, capability,
 * password, token hoặc user meta riêng tư.
 */
class UserNormalizer implements NormalizerInterface {

	/**
	 * Chuẩn hóa WP_User, user ID, ACF user array hoặc danh sách users.
	 *
	 * @param mixed $value Dữ liệu user cần chuẩn hóa.
	 * @return mixed
	 */
	public function normalize( $value ): mixed {
		if ( null === $value || false === $value || '' === $value ) {
			return null;
		}

		// ACF User field cho phép chọn nhiều user.
		if ( is_array( $value ) && $this->is_list_array( $value ) ) {
			$users = [];

			foreach ( $value as $item ) {
				$normalized = $this->normalize_single( $item );

				if ( null !== $normalized ) {
					$users[] = $normalized;
				}
			}

			return $users;
		}

		return $this->normalize_single( $value );
	}

	/**
	 * Chuẩn hóa một user.
	 *
	 * @param mixed $value WP_User, ID hoặc array do ACF trả về.
	 */
	private function normalize_single( $value ): ?array {
		$user = $this->resolve_user( $value );

		if ( ! $user instanceof \WP_User ) {
			return null;
		}

		$data = [
			'id'          => (int) $user->ID,
			'name'        => sanitize_text_field( $user->display_name ),
			'slug'        => sanitize_title( $user->user_nicename ),
			'description' => sanitize_textarea_field(
				(string) get_user_meta( $user->ID, 'description', true )
			),
			'avatar'      => esc_url_raw(
				get_avatar_url(
					$user->ID,
					[
						'size' => 192,
					]
				)
			),
			'url'         => esc_url_raw(
				get_author_posts_url( $user->ID )
			),
		];

		/**
		 * Cho phép chỉnh sửa dữ liệu user công khai.
		 *
		 * Cảnh báo: không nên bổ sung email, login, role,
		 * capability hoặc dữ liệu riêng tư vào response public.
		 *
		 * @param array    $data  Dữ liệu công khai đã chuẩn hóa.
		 * @param \WP_User $user  User object nguồn.
		 * @param mixed    $value Giá trị đầu vào ban đầu.
		 */
		$filtered = apply_filters(
			'headless_api_normalize_user',
			$data,
			$user,
			$value
		);

		return is_array( $filtered ) ? $filtered : $data;
	}

	/**
	 * Resolve nhiều định dạng user về WP_User.
	 *
	 * @param mixed $value Giá trị đầu vào.
	 */
	/**
	 * Kiểm tra array có phải danh sách tuần tự hay không.
	 * Tương thích cả PHP thấp hơn 8.1.
	 */
	private function is_list_array( array $value ): bool {
		if ( function_exists( 'array_is_list' ) ) {
			return \array_is_list( $value );
		}

		if ( [] === $value ) {
			return true;
		}

		return array_keys( $value ) === range( 0, count( $value ) - 1 );
	}

	private function resolve_user( $value ): ?\WP_User {
		if ( $value instanceof \WP_User ) {
			return $value;
		}

		if ( is_numeric( $value ) ) {
			$user = get_userdata( (int) $value );

			return $user instanceof \WP_User ? $user : null;
		}

		if ( ! is_array( $value ) ) {
			return null;
		}

		$user_id = 0;

		foreach ( [ 'ID', 'id', 'user_id' ] as $key ) {
			if ( isset( $value[ $key ] ) && is_numeric( $value[ $key ] ) ) {
				$user_id = (int) $value[ $key ];
				break;
			}
		}

		if ( $user_id <= 0 ) {
			return null;
		}

		$user = get_userdata( $user_id );

		return $user instanceof \WP_User ? $user : null;
	}
}