<?php

namespace TLU_Headless_API\Helpers;

use WP_Post_Type;
use WP_Taxonomy;

defined( 'ABSPATH' ) || exit;

/**
 * Chính sách xác định nội dung có được công khai qua Headless API hay không.
 */
final class ContentVisibility {

	/**
	 * Kiểm tra Custom Post Type có thể được công khai qua Headless API.
	 *
	 * @param string|WP_Post_Type $post_type Post type slug hoặc object.
	 */
	public static function is_post_type_public( $post_type ): bool {
		$post_type_object = $post_type instanceof WP_Post_Type
			? $post_type
			: get_post_type_object( (string) $post_type );

		if ( ! $post_type_object || self::is_post_type_internal( $post_type_object->name ) ) {
			return false;
		}

		return ! empty( $post_type_object->public )
			&& ! empty( $post_type_object->publicly_queryable );
	}

	/**
	 * Kiểm tra Custom Taxonomy có thể được công khai qua Headless API.
	 *
	 * Taxonomy phải public, publicly queryable và gắn với ít nhất một
	 * post type public. Không bắt buộc bật core WordPress REST API.
	 *
	 * @param string|WP_Taxonomy $taxonomy Taxonomy slug hoặc object.
	 */
	public static function is_taxonomy_public( $taxonomy ): bool {
		$taxonomy_object = $taxonomy instanceof WP_Taxonomy
			? $taxonomy
			: get_taxonomy( (string) $taxonomy );

		if (
			! $taxonomy_object
			|| empty( $taxonomy_object->public )
			|| empty( $taxonomy_object->publicly_queryable )
		) {
			return false;
		}

		foreach ( (array) $taxonomy_object->object_type as $post_type ) {
			if ( self::is_post_type_public( $post_type ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Kiểm tra một post có thể xuất hiện trong API public.
	 *
	 * Điều kiện:
	 * - Post tồn tại.
	 * - Trạng thái publish.
	 * - Không được bảo vệ bằng mật khẩu.
	 * - Post type có thể truy cập công khai.
	 *
	 * @param mixed $post WP_Post, post ID hoặc giá trị get_post() hỗ trợ.
	 */
	public static function is_post_public( $post ): bool {
		if ( is_numeric( $post ) ) {
			$post = get_post( (int) $post );
		}

		if ( ! $post instanceof \WP_Post ) {
			return false;
		}

		if ( 'publish' !== $post->post_status ) {
			return false;
		}

		if ( '' !== (string) $post->post_password ) {
			return false;
		}

		$is_public = self::is_post_type_public( (string) $post->post_type );

		/**
		 * Cho phép điều chỉnh kết quả visibility.
		 *
		 * Không nên dùng filter này để public draft, private hoặc
		 * nội dung được bảo vệ bằng mật khẩu.
		 *
		 * @param bool     $is_public Kết quả mặc định.
		 * @param \WP_Post $post      Post đang được kiểm tra.
		 */
		return (bool) apply_filters(
			'headless_api_is_post_public',
			$is_public,
			$post
		);
	}

	/**
	 * Xác định post type nội bộ không được public qua API.
	 */
	public static function is_post_type_internal( string $post_type ): bool {
		$internal_types = [
			'revision',
			'nav_menu_item',
			'custom_css',
			'customize_changeset',
			'oembed_cache',
			'user_request',
			'wp_global_styles',
			'wp_navigation',
			'wp_font_family',
			'wp_font_face',
		];

		/**
		 * Cho phép bổ sung post type nội bộ.
		 *
		 * @param string[] $internal_types Danh sách post type nội bộ.
		 */
		$internal_types = apply_filters(
			'headless_api_internal_post_types',
			$internal_types
		);

		return in_array( $post_type, $internal_types, true );
	}
}
