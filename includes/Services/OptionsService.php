<?php
namespace TLU_Headless_API\Services;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use TLU_Headless_API\Integrations\AcfIntegration;
use TLU_Headless_API\Integrations\PolylangIntegration;
use TLU_Headless_API\Normalizers\AcfNormalizer;
use TLU_Headless_API\Cache\TransientCache;

/**
 * Đọc ACF options page fields và trả về dữ liệu đã chuẩn hóa.
 *
 * Chuyển ngôn ngữ trước khi đọc field, khôi phục sau khi xong
 * qua try/finally — đảm bảo context được khôi phục kể cả khi normalize lỗi.
 * Plugin không tự switch ngôn ngữ — hook cho phép site-specific plugin làm điều đó.
 */
class OptionsService {
	/** Public top-level ACF fields. New fields remain private until explicitly reviewed. */
	private const PUBLIC_FIELDS = [
		'tlu_site_hero'    => [ 'hero_slides_vi', 'hero_slides_en' ],
		'tlu_site_logo'    => [ 'logo_vi', 'logo_en', 'footer_logo_vi', 'footer_logo_en' ],
		'tlu_site_footer'  => [ 'footer_about_links_vi', 'footer_about_links_en', 'footer_quick_links_vi', 'footer_quick_links_en', 'footer_address_vi', 'footer_address_en', 'footer_email', 'footer_phone', 'url_map' ],
		'tlu_site_social'  => [ 'facebook_url', 'instagram_url', 'tiktok_url', 'youtube_url' ],
		'tlu_site_favicon' => [ 'favicon' ],
		'tlu_site_img'     => [ 'anh_tinh_vi', 'anh_tinh_en' ],
	];

	private AcfIntegration      $acf;
	private PolylangIntegration $polylang;
	private AcfNormalizer       $normalizer;
	private TransientCache      $cache;

	public function __construct(
		?AcfIntegration      $acf        = null,
		?PolylangIntegration $polylang   = null,
		?AcfNormalizer       $normalizer = null,
		?TransientCache      $cache      = null
	) {
		$this->acf        = $acf        ?? new AcfIntegration();
		$this->polylang   = $polylang   ?? new PolylangIntegration();
		$this->normalizer = $normalizer ?? new AcfNormalizer();
		$this->cache      = $cache      ?? TransientCache::from_config();
	}

	/**
	 * Lấy fields của một ACF options page theo ngôn ngữ.
	 * Kết quả được cache; cache được bỏ qua khi ACF không hoạt động.
	 *
	 * @param  string $options_page_key  Key options page ACF (vd: 'options', 'global_settings').
	 * @param  string $lang              Mã ngôn ngữ Polylang ('' = ngôn ngữ hiện tại).
	 * @return array                     Map key => value đã chuẩn hóa, hoặc [] nếu ACF không hoạt động.
	 */
	public function get_options( string $options_page_key, string $lang = '' ): array {
		if ( ! $this->acf->is_active() ) {
			return [];
		}

		$allowed_fields = $this->allowed_fields( $options_page_key );
		if ( [] === $allowed_fields ) {
			return [];
		}

		$cache_key = $this->cache->make_key( 'options', $options_page_key, $lang );
		$cached    = $this->cache->get( $cache_key );
		if ( is_array( $cached ) ) {
			// Older transients may predate the field allowlist; filter them too.
			return array_intersect_key( $cached, array_fill_keys( $allowed_fields, true ) );
		}

		$this->maybe_switch_language( $lang );
		try {
			$field_objects = $this->acf->get_options_field_objects( $options_page_key );
			$public_fields = [];
			foreach ( $field_objects as $field ) {
				if ( ! is_array( $field ) ) {
					continue;
				}
				$name = (string) ( $field['name'] ?? '' );
				if ( in_array( $name, $allowed_fields, true ) ) {
					$public_fields[ $name ] = $field;
				}
			}
			$result = [] !== $public_fields
				? $this->normalizer->normalize_field_objects( $public_fields )
				: [];
		} finally {
			$this->maybe_restore_language( $lang );
		}

		if ( ! empty( $result ) ) {
			$this->cache->set( $cache_key, $result );
		}

		return $result;
	}

	/**
	 * Site-specific fields can be added only by explicit name via this filter.
	 * Unknown options pages remain denied even when their page key is allowlisted.
	 */
	private function allowed_fields( string $options_page_key ): array {
		$defaults = self::PUBLIC_FIELDS[ $options_page_key ] ?? [];
		$fields   = apply_filters( 'headless_api_allowed_options_fields', $defaults, $options_page_key );
		if ( ! is_array( $fields ) ) {
			return [];
		}
		return array_values( array_unique( array_filter( $fields, static function ( $name ): bool {
			return is_string( $name ) && 1 === preg_match( '/^[a-zA-Z][a-zA-Z0-9_]*$/', $name );
		} ) ) );
	}

	// ── Private ───────────────────────────────────────────────────────────────

	private function maybe_switch_language( string $lang ): void {
		if ( $lang && $this->polylang->is_active() ) {
			/**
			 * Fires trước khi chuyển ngôn ngữ để đọc options page.
			 * Plugin-site-specific có thể hook vào đây để gọi pll_switch_language().
			 * Plugin framework không tự switch để không phụ thuộc vào nội bộ PLL.
			 *
			 * @param string $lang  Mã ngôn ngữ được yêu cầu.
			 */
			do_action( 'headless_api_before_options_lang_switch', $lang );
		}
	}

	private function maybe_restore_language( string $lang ): void {
		if ( $lang && $this->polylang->is_active() ) {
			/**
			 * Fires sau khi đọc xong options fields để khôi phục ngôn ngữ gốc.
			 * Được gọi trong finally — luôn chạy kể cả khi normalize phát sinh lỗi.
			 *
			 * @param string $lang  Mã ngôn ngữ đã chuyển sang.
			 */
			do_action( 'headless_api_after_options_lang_switch', $lang );
		}
	}
}
