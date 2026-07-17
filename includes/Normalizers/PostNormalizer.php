<?php
namespace TLU_Headless_API\Normalizers;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use TLU_Headless_API\Contracts\NormalizerInterface;
use TLU_Headless_API\Services\UrlTransformer;

/**
 * Chuẩn hóa WP_Post thành tóm tắt nhẹ.
 *
 * Dùng cho: relationship field, post_object field, danh sách archive.
 * Không dùng cho response page đầy đủ (dùng PageNormalizer cho mục đó).
 *
 * Cấu trúc đầu ra:
 * { id, slug, title, excerpt, link, type, date, modified, featured_image }
 */
class PostNormalizer implements NormalizerInterface {

	private MediaNormalizer $media;
	private UrlTransformer $transformer;

	public function __construct( MediaNormalizer $media = null, ?UrlTransformer $transformer = null ) {
		$this->media = $media ?? new MediaNormalizer();
		$this->transformer = $transformer ?? new UrlTransformer();
	}

	/** Chấp nhận WP_Post hoặc post ID. */
	public function normalize( $value ): array {
		if ( is_numeric( $value ) ) {
			$value = get_post( (int) $value );
		}
		if ( ! ( $value instanceof \WP_Post ) ) {
			return [];
		}
		return $this->summary( $value );
	}

	/** Tạo mảng tóm tắt từ WP_Post. */
	public function summary( \WP_Post $post ): array {
		return [
			'id'             => $post->ID,
			'slug'           => $post->post_name,
			'title'          => get_the_title( $post ),
			'excerpt'        => wp_strip_all_tags( get_the_excerpt( $post ) ),
			'link'           => $this->transformer->transform_navigation_url( get_permalink( $post ) ),
			'type'           => $post->post_type,
			'date'           => $post->post_date,
			'modified'       => $post->post_modified,
			'featured_image' => $this->media->normalize( get_post_thumbnail_id( $post->ID ) ?: null ),
		];
	}
}
