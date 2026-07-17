<?php
namespace TLU_Headless_API;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Truy cập cấu hình tập trung.
 *
 * Bọc hằng số PHP và option WordPress lại trong một chỗ duy nhất.
 * Không class nào khác cần biết tên hằng số thô hoặc chuỗi option key.
 */
class Config {

	public static function version(): string {
		return TLU_HEADLESS_API_VERSION;
	}

	public static function schema_version(): string {
		return TLU_HEADLESS_API_SCHEMA_VERSION;
	}

	public static function namespace(): string {
		return HEADLESS_API_NAMESPACE;
	}

	public static function legacy_namespace(): string {
		return TLU_HEADLESS_API_NAMESPACE;
	}

	public static function path(): string {
		return TLU_HEADLESS_API_PATH;
	}

	public static function url(): string {
		return TLU_HEADLESS_API_URL;
	}

	public static function option_key(): string {
		return 'tlu_headless_options';
	}

	/** Trả về toàn bộ mảng option của plugin, luôn là array kể cả khi option chưa tồn tại. */
	public static function options(): array {
		return (array) \get_option( self::option_key(), [] );
	}

	public static function cache_enabled(): bool {
		return (bool) ( self::options()['enable_cache'] ?? false );
	}

	public static function cache_ttl(): int {
		$ttl = (int) ( self::options()['cache_ttl'] ?? 300 );
		return max( 60, $ttl ); // Tối thiểu 60 giây để tránh vòng lặp liên tục.
	}

	public static function frontend_url(): string {
		return esc_url_raw( self::options()['frontend_url'] ?? 'http://localhost:3000' );
	}

	/** URL gốc của WPX FULLTEXT API khi search plugin nằm trên CMS khác. */
	public static function search_backend_url(): string {
		$default = defined( 'HEADLESS_SEARCH_BACKEND_URL' )
			? (string) HEADLESS_SEARCH_BACKEND_URL
			: 'https://tlu.edu.vn/wp-json/wpx-ft/v1';
		return untrailingslashit( esc_url_raw( (string) apply_filters( 'headless_api_search_backend_url', $default ) ) );
	}

	/**
	 * Danh sách origin được phép gửi CORS request.
	 * Đọc từ setting "allowed_domains" (mỗi dòng một origin).
	 * Trả về [] khi không có origin nào được cấu hình.
	 *
	 * @return string[]
	 */
	public static function allowed_origins(): array {
		$raw = (string) ( self::options()['allowed_domains'] ?? '' );
		if ( '' === trim( $raw ) ) {
			return [];
		}
		$origins = array_map( 'trim', explode( "\n", $raw ) );
		$origins = array_filter( array_map( [ self::class, 'normalize_origin' ], $origins ) );
		return array_values( array_unique( $origins ) );
	}

	private static function normalize_origin( string $origin ): string {
		$parts = wp_parse_url( $origin );
		if ( ! is_array( $parts ) || empty( $parts['scheme'] ) || empty( $parts['host'] ) ) {
			return '';
		}
		if ( ! in_array( strtolower( $parts['scheme'] ), [ 'http', 'https' ], true ) ) {
			return '';
		}
		if ( ! empty( $parts['user'] ) || ! empty( $parts['pass'] ) || ! empty( $parts['query'] ) || ! empty( $parts['fragment'] ) ) {
			return '';
		}
		if ( isset( $parts['path'] ) && ! in_array( $parts['path'], [ '', '/' ], true ) ) {
			return '';
		}

		$normalized = strtolower( $parts['scheme'] ) . '://' . strtolower( $parts['host'] );
		if ( isset( $parts['port'] ) ) {
			$normalized .= ':' . (int) $parts['port'];
		}
		return $normalized;
	}
}
