<?php
namespace TLU_Headless_API\Normalizers;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use TLU_Headless_API\Contracts\NormalizerInterface;
use TLU_Headless_API\Integrations\AcfIntegration;
use TLU_Headless_API\Integrations\PolylangIntegration;
use TLU_Headless_API\Services\UrlTransformer;

/**
 * Xây dựng response đầy đủ của một trang (WP_Post) bao gồm ACF và SEO.
 *
 * Dùng cho endpoint /page và /page-blocks.
 * ACF fields yêu cầu AcfIntegration; trả về {} khi ACF không hoạt động.
 * SEO sử dụng SeoNormalizer (Rank Math ưu tiên, fallback về WP core).
 *
 * Cấu trúc đầu ra:
 * {
 *   id, slug, title, excerpt, content, link, type,
 *   date, modified, status,
 *   featured_image,
 *   acf,
 *   seo
 * }
 */
class PageNormalizer implements NormalizerInterface {

	private AcfNormalizer   $acf;
	private SeoNormalizer   $seo;
	private MediaNormalizer $media;
	private AcfIntegration  $acf_integration;
	private UrlTransformer $transformer;
	private PolylangIntegration $polylang;

	public function __construct(
		?AcfNormalizer   $acf             = null,
		?SeoNormalizer   $seo             = null,
		?MediaNormalizer $media           = null,
		?AcfIntegration  $acf_integration = null,
		?UrlTransformer $transformer     = null,
		?PolylangIntegration $polylang    = null
	) {
		$this->transformer = $transformer ?? new UrlTransformer();
		$this->acf             = $acf             ?? new AcfNormalizer();
		$this->seo             = $seo             ?? new SeoNormalizer( null, null, null, $this->transformer );
		$this->media           = $media           ?? new MediaNormalizer( $this->transformer );
		$this->acf_integration = $acf_integration ?? new AcfIntegration();
		$this->polylang       = $polylang       ?? new PolylangIntegration();
	}

	/** Chấp nhận WP_Post; trả về cấu trúc trang đầy đủ. */
	public function normalize( $value ): array {
		if ( ! ( $value instanceof \WP_Post ) ) {
			return [];
		}
		return $this->from_post( $value );
	}

	/** Xây dựng response page đầy đủ (dùng cho endpoint /page). */
	public function from_post( \WP_Post $post ): array {
		return [
			'id'             => $post->ID,
			'slug'           => $post->post_name,
			'title'          => get_the_title( $post ),
			'excerpt'        => wp_strip_all_tags( get_the_excerpt( $post ) ),
			'content'        => apply_filters( 'the_content', $post->post_content ),
			'link'           => $this->transformer->transform_navigation_url( get_permalink( $post ) ),
			'type'           => $post->post_type,
			'date'           => $post->post_date,
			'modified'       => $post->post_modified,
			'status'         => $post->post_status,
			'template'       => sanitize_text_field( get_page_template_slug( $post->ID ) ?: '' ),
			'featured_image' => $this->media->normalize( get_post_thumbnail_id( $post->ID ) ?: null ),
			'acf'            => $this->build_acf( $post->ID ),
			'seo'            => $this->seo->from_post( $post ),
			'language'       => $this->build_language_metadata( $post ),
			'translations'   => $this->build_translations( $post ),
		];
	}

	/**
	 * Response trang với dữ liệu ACF blocks riêng (dùng cho endpoint /page-blocks).
	 * Thêm key 'blocks' chứa các flexible_content field.
	 */
	public function from_post_with_blocks( \WP_Post $post ): array {
		return array_merge(
			$this->from_post( $post ),
			[ 'blocks' => $this->build_acf_blocks( $post->ID ) ]
		);
	}

	// ── Private ───────────────────────────────────────────────────────────────

	private function build_language_metadata( \WP_Post $post ): ?array {
		if ( ! $this->polylang->is_active() ) {
			return null;
		}

		$slug = $this->polylang->get_post_language( $post->ID );
		if ( '' === $slug ) {
			return null;
		}

		$langs = $this->polylang->get_languages();
		foreach ( $langs as $l ) {
			if ( $l['slug'] === $slug ) {
				return [
					'slug'       => $l['slug'],
					'locale'     => $l['locale'],
					'name'       => $l['name'],
					'is_default' => $l['is_default'],
				];
			}
		}

		return null;
	}

	private function build_translations( \WP_Post $post ): array {
		if ( ! $this->polylang->is_active() ) {
			return [];
		}

		$translations = $this->polylang->get_post_translations( $post->ID );
		$result       = [];

		foreach ( $translations as $trans ) {
			$result[] = [
				'language' => $trans['language'],
				'locale'   => $trans['locale'],
				'url'      => $this->transformer->transform_navigation_url( $trans['url'] ),
			];
		}

		return apply_filters( 'headless_api_public_translations', $result, $post->ID, $this );
	}

	/** Đọc và chuẩn hóa toàn bộ ACF fields của post. Trả về {} khi ACF không hoạt động. */
	private function build_acf( int $post_id ): array {
		if ( ! $this->acf_integration->is_active() ) {
			return [];
		}
		$fields = $this->acf_integration->get_field_objects( $post_id );
		if ( empty( $fields ) ) {
			return [];
		}
		return $this->acf->normalize_field_objects( $fields );
	}

	/**
	 * Chỉ trả về các field là flexible_content (nhận biết qua key 'layout' trong mảng con).
	 * Dùng cho endpoint /page-blocks để tách phần layout khỏi dữ liệu phụ.
	 */
	private function build_acf_blocks( int $post_id ): array {
		$all    = $this->build_acf( $post_id );
		$blocks = [];
		foreach ( $all as $key => $value ) {
			if ( is_array( $value ) && ! empty( $value ) && isset( $value[0]['layout'] ) ) {
				$blocks[ $key ] = $value;
			}
		}
		return $blocks;
	}
}
