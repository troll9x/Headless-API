<?php
namespace TLU_Headless_API;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Tiện ích tĩnh dùng chung trong toàn plugin.
 *
 * Hai nhiệm vụ duy nhất:
 * 1. Phát hiện plugin bên thứ ba đang hoạt động (ACF, Polylang, Rank Math).
 * 2. Thay thế biến Rank Math (%title%, %sitename%…) trong chuỗi meta.
 *
 * Kết quả phát hiện plugin được cache trong bộ nhớ — chỉ chạy một lần mỗi request.
 */
class Helpers {

	/** @var array<string,bool>|null Kết quả được cache, null nghĩa là chưa chạy lần nào. */
	private static ?array $features_cache = null;

	/**
	 * Kiểm tra những plugin tích hợp nào đang hoạt động.
	 * Trả về mảng booleans, được cache trong bộ nhớ trong suốt request.
	 *
	 * @return array{acf: bool, polylang: bool, rank_math: bool}
	 */
	public static function plugin_feature_exists(): array {
		if ( null === self::$features_cache ) {
			self::$features_cache = [
				'acf'       => function_exists( 'get_field' ),
				'polylang'  => function_exists( 'pll_current_language' ),
				'rank_math' => defined( 'RANK_MATH_VERSION' ) || class_exists( 'RankMath' ),
			];
		}
		return self::$features_cache;
	}

	/**
	 * Thay thế biến Rank Math phổ biến trong một chuỗi meta.
	 *
	 * Được dùng chung bởi endpoint Page và Seo — tránh trùng lặp logic.
	 * Hỗ trợ: %title%, %sitename%, %sep%, %excerpt%, %date%, %modified%.
	 *
	 * @param  string   $value Chuỗi chứa biến Rank Math (vd: '%title% | %sitename%').
	 * @param  \WP_Post $post  Post dùng để lấy title, excerpt, date.
	 * @return string          Chuỗi đã được thay thế biến.
	 */
	public static function rm_vars( string $value, \WP_Post $post ): string {
		if ( empty( $value ) || false === strpos( $value, '%' ) ) {
			return $value;
		}
		$replacements = [
			'%title%'    => $post->post_title,
			'%sitename%' => get_bloginfo( 'name' ),
			'%sep%'      => '|',
			'%excerpt%'  => wp_strip_all_tags( $post->post_excerpt ),
			'%date%'     => get_the_date( '', $post->ID ),
			'%modified%' => get_the_modified_date( '', $post->ID ),
		];
		return str_replace( array_keys( $replacements ), array_values( $replacements ), $value );
	}
}
