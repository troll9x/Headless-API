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

	private AcfIntegration      $acf;
	private PolylangIntegration $polylang;
	private AcfNormalizer       $normalizer;
	private TransientCache      $cache;

	public function __construct(
		AcfIntegration      $acf        = null,
		PolylangIntegration $polylang   = null,
		AcfNormalizer       $normalizer = null,
		TransientCache      $cache      = null
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

		$cache_key = $this->cache->make_key( 'options', $options_page_key, $lang );
		$cached    = $this->cache->get( $cache_key );
		if ( null !== $cached ) {
			return $cached;
		}

		$this->maybe_switch_language( $lang );
		try {
			$field_objects = $this->acf->get_options_field_objects( $options_page_key );
			$result        = ! empty( $field_objects )
				? $this->normalizer->normalize_field_objects( $field_objects )
				: [];
		} finally {
			$this->maybe_restore_language( $lang );
		}

		if ( ! empty( $result ) ) {
			$this->cache->set( $cache_key, $result );
		}

		return $result;
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
