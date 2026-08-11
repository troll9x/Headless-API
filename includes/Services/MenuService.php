<?php
namespace TLU_Headless_API\Services;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use TLU_Headless_API\Integrations\PolylangIntegration;
use TLU_Headless_API\Normalizers\MenuNormalizer;
use TLU_Headless_API\Cache\TransientCache;

/**
 * Xử lý logic nghiệp vụ cho nav menu endpoint.
 *
 * Hỗ trợ Polylang: tự động phân giải location key dạng {location}____{lang}
 * khi Polylang đang hoạt động và có truyền tham số $lang.
 */
class MenuService {

	private PolylangIntegration $polylang;
	private MenuNormalizer      $normalizer;
	private TransientCache      $cache;

	public function __construct(
		PolylangIntegration $polylang   = null,
		MenuNormalizer      $normalizer = null,
		TransientCache      $cache      = null
	) {
		$this->polylang   = $polylang   ?? new PolylangIntegration();
		$this->normalizer = $normalizer ?? new MenuNormalizer();
		$this->cache      = $cache      ?? TransientCache::from_config();
	}

	/**
	 * Lấy menu theo location, tự động phân giải location theo ngôn ngữ.
	 * Kết quả được cache theo location + lang.
	 *
	 * @param  string $location  Slug menu location đã đăng ký trong WordPress.
	 * @param  string $lang      Mã ngôn ngữ ('' = ngôn ngữ hiện tại).
	 * @return array|null        Null khi location không có menu gán vào.
	 */
	public function get_menu( string $location, string $lang = '' ): ?array {
		$lang = $this->polylang->normalize_language( $lang );
		$cache_key = $this->cache->make_key( 'menu', $location, $lang );
		$cached    = $this->cache->get( $cache_key );
		if ( null !== $cached ) {
			return $cached;
		}

		$resolved = $this->resolve_location( $location, $lang );
		$result   = $this->get_menu_by_location( $resolved );

		if ( null !== $result ) {
			$this->cache->set( $cache_key, $result );
		}

		return $result;
	}

	/**
	 * Lấy menu theo slug trực tiếp (không qua location).
	 * Kết quả được cache theo slug + lang.
	 *
	 * @param  string $slug  Menu slug WordPress.
	 * @param  string $lang  Mã ngôn ngữ.
	 * @return array|null
	 */
	public function get_menu_by_slug( string $slug, string $lang = '' ): ?array {
		$lang = $this->polylang->normalize_language( $lang );
		$cache_key = $this->cache->make_key( 'menu_slug', $slug, $lang );
		$cached    = $this->cache->get( $cache_key );
		if ( null !== $cached ) {
			return $cached;
		}

		$menu = wp_get_nav_menu_object( $slug );
		if ( ! $menu || is_wp_error( $menu ) ) {
			return null;
		}

		if ( '' !== $lang && $this->polylang->is_active() ) {
			$translated_menu_id = $this->polylang->get_term_translation_id( (int) $menu->term_id, $lang );
			if ( $translated_menu_id <= 0 ) {
				return null;
			}

			$menu = wp_get_nav_menu_object( $translated_menu_id );
			if ( ! $menu || is_wp_error( $menu ) ) {
				return null;
			}
		}

		$items = wp_get_nav_menu_items( $menu->term_id );
		if ( ! is_array( $items ) || [] === $items ) {
			return null;
		}

		$result = [
			'id'    => $menu->term_id,
			'name'  => $menu->name,
			'slug'  => $menu->slug,
			'items' => $this->normalizer->normalize( $items ),
		];

		$this->cache->set( $cache_key, $result );
		return $result;
	}

	/**
	 * Kiểm tra location có menu được gán không.
	 */
	public function location_has_menu( string $location, string $lang = '' ): bool {
		$resolved  = $this->resolve_location( $location, $lang );
		$locations = get_nav_menu_locations();
		if ( empty( $locations[ $resolved ] ) ) {
			return false;
		}
		return (bool) wp_get_nav_menu_object( $locations[ $resolved ] );
	}

	// ── Private ───────────────────────────────────────────────────────────────

	/**
	 * Trả về location key đã dịch theo Polylang, hoặc slug gốc khi Polylang tắt.
	 * Quy ước Polylang: {location}____{lang}
	 */
	private function resolve_location( string $location, string $lang ): string {
		if ( $lang && $this->polylang->is_active() ) {
			return $this->polylang->translated_menu_location( $location, $lang );
		}
		return $location;
	}

	/**
	 * Lấy menu items theo location slug và trả về dữ liệu menu đầy đủ.
	 * Trả về null khi không tìm thấy location hoặc menu rỗng.
	 */
	private function get_menu_by_location( string $location ): ?array {
		$locations = get_nav_menu_locations();
		if ( empty( $locations[ $location ] ) ) {
			return null;
		}

		$menu = wp_get_nav_menu_object( $locations[ $location ] );
		if ( ! $menu ) {
			return null;
		}

		$items = wp_get_nav_menu_items( $locations[ $location ] );
		if ( ! is_array( $items ) || [] === $items ) {
			return null;
		}

		return [
			'id'    => $menu->term_id,
			'name'  => $menu->name,
			'slug'  => $menu->slug,
			'items' => $this->normalizer->normalize( $items ),
		];
	}
}
