<?php
namespace TLU_Headless_API\Integrations;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use TLU_Headless_API\Contracts\IntegrationInterface;

/**
 * Bọc toàn bộ Polylang API dùng trong framework.
 *
 * Mọi logic liên quan đến ngôn ngữ phải đi qua class này.
 * Hỗ trợ bất kỳ số lượng ngôn ngữ nào — không hardcode vi/en.
 * Trả về giá trị mặc định an toàn khi Polylang không được cài.
 */
class PolylangIntegration implements IntegrationInterface {

	/** Kiểm tra Polylang có đang hoạt động không. */
	public function is_active(): bool {
		if ( ! function_exists( 'pll_current_language' ) ) {
			return false;
		}

		if ( function_exists( 'pll_languages_list' ) ) {
			return [] !== (array) pll_languages_list( [ 'fields' => 'slug' ] );
		}

		return '' !== (string) pll_current_language();
	}

	/**
	 * Trả về danh sách ngôn ngữ đã cấu hình, normalize về shape ổn định.
	 *
	 * @return array<int, array{slug: string, locale: string, name: string, home_url: string, is_default: bool}>
	 */
	public function get_languages(): array {
		if ( ! $this->is_active() || ! function_exists( 'pll_languages_list' ) ) {
			return [];
		}

		$langs = pll_languages_list( [ 'fields' => 'all' ] );
		$result = [];

		foreach ( $langs as $slug => $data ) {
			$result[] = [
				'slug'       => (string) $slug,
				'locale'     => (string) ( $data['locale'] ?? '' ),
				'name'       => (string) ( $data['name'] ?? '' ),
				'home_url'   => function_exists( 'pll_home_url' ) ? esc_url_raw( pll_home_url( $slug ) ) : '',
				'is_default' => ( $slug === $this->get_default_language() ),
			];
		}

		return apply_filters( 'headless_api_languages', $result, $this );
	}

	/** Trả về slug ngôn ngữ mặc định. */
	public function get_default_language(): string {
		if ( ! $this->is_active() || ! function_exists( 'pll_default_language' ) ) {
			return '';
		}
		return (string) pll_default_language();
	}

	/** Trả về slug ngôn ngữ hiện tại. */
	public function get_current_language(): string {
		if ( ! $this->is_active() || ! function_exists( 'pll_current_language' ) ) {
			return '';
		}
		return (string) pll_current_language();
	}

	/**
	 * Normalize input (locale, uppercase, v.v.) về language slug.
	 * Trả về chuỗi rỗng nếu không hợp lệ/không hỗ trợ.
	 */
	public function normalize_language( string $language ): string {
		$language = trim( strtolower( $language ) );
		if ( '' === $language ) {
			return '';
		}

		// 1. Check direct slug match
		if ( $this->is_supported_language( $language ) ) {
			return $language;
		}

		// 2. Check locale match (replace _ or - with underscore)
		$locale = str_replace( '-', '_', $language );
		$lang_by_locale = $this->get_language_by_locale( $locale );
		if ( ! empty( $lang_by_locale ) ) {
			return $lang_by_locale;
		}

		return '';
	}

	/** Kiểm tra slug ngôn ngữ có nằm trong danh sách active không. */
	public function is_supported_language( string $language ): bool {
		if ( ! $this->is_active() ) {
			return false;
		}
		$langs = $this->available_languages();
		return in_array( $language, $langs, true );
	}

	/** Trả về mã ngôn ngữ của post, hoặc '' nếu Polylang không hoạt động. */
	public function get_post_language( int $post_id ): string {
		if ( ! $this->is_active() || ! function_exists( 'pll_get_post_language' ) ) {
			return '';
		}
		return (string) pll_get_post_language( $post_id );
	}

	/** Lấy ID của bản dịch cho một ngôn ngữ cụ thể. */
	public function get_translation_id( int $post_id, string $language ): int {
		if ( ! $this->is_active() || ! function_exists( 'pll_get_post' ) ) {
			return 0;
		}
		return (int) pll_get_post( $post_id, $language );
	}

	/**
	 * Trả về danh sách bản dịch công khai kèm metadata.
	 *
	 * @return array<int, array{language: string, locale: string, id: int, url: string}>
	 */
	public function get_post_translations( int $post_id ): array {
		if ( ! $this->is_active() || ! function_exists( 'pll_get_post_translations' ) ) {
			return [];
		}

		$translations = pll_get_post_translations( $post_id );
		$result       = [];
		$langs_info   = $this->get_languages();

		foreach ( $translations as $lang => $id ) {
			$post = get_post( (int) $id );
			if ( ! $post || 'publish' !== $post->post_status ) {
				continue;
			}

			// Find locale and name from lang list
			$locale = '';
			foreach ( $langs_info as $l ) {
				if ( $l['slug'] === $lang ) {
					$locale = $l['locale'];
					break;
				}
			}

			$result[] = [
				'language' => (string) $lang,
				'locale'   => $locale,
				'id'       => (int) $id,
				'url'      => esc_url_raw( get_permalink( (int) $id ) ),
			];
		}

		return apply_filters( 'headless_api_public_translations', $result, $post_id, $this );
	}

	/** Tìm slug ngôn ngữ từ locale. */
	public function get_language_by_locale( string $locale ): string {
		if ( ! $this->is_active() ) {
			return '';
		}
		$langs = $this->get_languages();
		foreach ( $langs as $l ) {
			if ( strtolower( $l['locale'] ) === strtolower( $locale ) ) {
				return $l['slug'];
			}
		}
		return '';
	}

	/**
	 * Parse language prefix từ path.
	 * Ví dụ: /en/about/ -> ['language' => 'en', 'path' => 'about']
	 *
	 * @return array{language: string, path: string}
	 */
	public function extract_language_prefix( string $path ): array {
		$path = trim( $path, '/' );
		if ( '' === $path ) {
			return [ 'language' => '', 'path' => '' ];
		}

		$segments = explode( '/', $path );
		$first_segment = $segments[0];

		if ( $this->is_supported_language( $first_segment ) ) {
			array_shift( $segments );
			return [
				'language' => $first_segment,
				'path'     => implode( '/', $segments ),
			];
		}

		return [ 'language' => '', 'path' => $path ];
	}

	/** Danh sách slug ngôn ngữ đã đăng ký, hoặc [] nếu Polylang không hoạt động. */
	public function available_languages(): array {
		if ( ! $this->is_active() || ! function_exists( 'pll_languages_list' ) ) {
			return [];
		}
		return (array) pll_languages_list( [ 'fields' => 'slug' ] );
	}

	/**
	 * Polylang lưu menu location đã dịch theo quy ước: {location}_____{lang}.
	 * Trả về location key đã dịch, hoặc location gốc nếu Polylang không hoạt động.
	 */
	public function translated_menu_location( string $location, string $lang ): string {
		if ( ! $this->is_active() || empty( $lang ) ) {
			return $location;
		}
		return $location . '____' . $lang;
	}

	/**
	 * Chèn key 'lang' vào mảng args của WP_Query khi Polylang đang hoạt động.
	 */
	public function inject_lang_arg( array &$args, string $lang ): void {
		if ( $this->is_active() && '' !== $lang ) {
			$args['lang'] = $lang;
		}
	}

	/**
	 * Trong danh sách posts trả về từ get_posts(), chọn bài khớp ngôn ngữ $lang.
	 */
	public function pick_by_language( array $posts, string $lang ): ?\WP_Post {
		if ( empty( $posts ) ) {
			return null;
		}
		if ( '' !== $lang ) {
			foreach ( $posts as $post ) {
				if ( $this->get_post_language( $post->ID ) === $lang ) {
					return $post;
				}
			}
		}
		return $posts[0];
	}
}