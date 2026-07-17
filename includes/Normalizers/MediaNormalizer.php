<?php
namespace TLU_Headless_API\Normalizers;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use TLU_Headless_API\Contracts\NormalizerInterface;
use TLU_Headless_API\Services\UrlTransformer;

/**
 * Chuẩn hóa bất kỳ giá trị hình ảnh / file WordPress / ACF nào thành cấu trúc nhất quán.
 *
 * Chấp nhận:
 * - Mảng ACF image hoặc file (có key 'url')
 * - Attachment ID (số nguyên)
 * - URL string thuần
 * - null / giá trị rỗng
 *
 * Cấu trúc đầu ra:
 * {
 *   id, url, alt, title, caption, description,
 *   width, height, mime_type,
 *   sizes: { thumbnail, medium, large, full }
 * }
 */
class MediaNormalizer implements NormalizerInterface {

	private UrlTransformer $transformer;

	public function __construct( ?UrlTransformer $transformer = null ) {
		$this->transformer = $transformer ?? new UrlTransformer();
	}

	/** Chuẩn hóa một giá trị hình ảnh / file. */
	public function normalize( $value ): array {
		if ( empty( $value ) ) {
			return $this->empty();
		}

		// Mảng ACF (image hoặc file)
		if ( is_array( $value ) && isset( $value['url'] ) ) {
			return $this->from_acf_array( $value );
		}

		// Attachment ID
		if ( is_numeric( $value ) && (int) $value > 0 ) {
			return $this->from_id( (int) $value );
		}

		// URL string thuần
		if ( is_string( $value ) && '' !== $value ) {
			return array_merge( $this->empty(), [ 'url' => $this->transformer->transform_asset_url( $value ) ] );
		}

		return $this->empty();
	}

	/** Chuẩn hóa ACF gallery field — mảng ảnh hoặc mảng ID. */
	public function normalize_gallery( $value ): array {
		if ( empty( $value ) || ! is_array( $value ) ) {
			return [];
		}
		return array_values( array_map( [ $this, 'normalize' ], $value ) );
	}

	/** Tạo shape tối thiểu khi chỉ có URL string, không có attachment ID. */
	public function empty_with_url( string $url ): array {
		return array_merge( $this->empty(), [ 'url' => $this->transformer->transform_asset_url( $url ) ] );
	}

	/** Shape rỗng — trả về khi không có dữ liệu hợp lệ. */
	public function empty(): array {
		return [
			'id'          => null,
			'url'         => '',
			'alt'         => '',
			'title'       => '',
			'caption'     => '',
			'description' => '',
			'width'       => null,
			'height'      => null,
			'mime_type'   => '',
			'sizes'       => [ 'thumbnail' => '', 'medium' => '', 'large' => '', 'full' => '' ],
		];
	}

	// ── Private ───────────────────────────────────────────────────────────────

	private function from_acf_array( array $v ): array {
		$id  = (int) ( $v['ID'] ?? $v['id'] ?? 0 ) ?: null;
		$url = $this->transformer->transform_asset_url( $v['url'] );
		return [
			'id'          => $id,
			'url'         => $url,
			'alt'         => sanitize_text_field( $v['alt']         ?? '' ),
			'title'       => sanitize_text_field( $v['title']       ?? '' ),
			'caption'     => wp_kses_post( $v['caption']            ?? '' ),
			'description' => wp_kses_post( $v['description']        ?? '' ),
			'width'       => isset( $v['width'] )  ? (int) $v['width']  : null,
			'height'      => isset( $v['height'] ) ? (int) $v['height'] : null,
			'mime_type'   => sanitize_mime_type( $v['mime_type']    ?? '' ),
			'sizes'       => [
				'thumbnail' => $this->transformer->transform_asset_url( $v['sizes']['thumbnail'] ?? $url ),
				'medium'    => $this->transformer->transform_asset_url( $v['sizes']['medium'] ?? $url ),
				'large'     => $this->transformer->transform_asset_url( $v['sizes']['large'] ?? $url ),
				'full'      => $url,
			],
		];
	}

	private function from_id( int $id ): array {
		$url = wp_get_attachment_url( $id );
		if ( ! $url ) {
			return $this->empty();
		}

		$meta = wp_get_attachment_metadata( $id );
		$post = get_post( $id );
		$alt  = get_post_meta( $id, '_wp_attachment_image_alt', true );

		return [
			'id'          => $id,
			'url'         => $this->transformer->transform_asset_url( $url ),
			'alt'         => sanitize_text_field( $alt ?: '' ),
			'title'       => $post ? sanitize_text_field( $post->post_title ) : '',
			'caption'     => $post ? wp_kses_post( $post->post_excerpt ) : '',
			'description' => $post ? wp_kses_post( $post->post_content )  : '',
			'width'       => isset( $meta['width'] )  ? (int) $meta['width']  : null,
			'height'      => isset( $meta['height'] ) ? (int) $meta['height'] : null,
			'mime_type'   => sanitize_mime_type( get_post_mime_type( $id ) ?: '' ),
			'sizes'       => [
				'thumbnail' => $this->transformer->transform_asset_url( wp_get_attachment_image_url( $id, 'thumbnail' ) ?: $url ),
				'medium'    => $this->transformer->transform_asset_url( wp_get_attachment_image_url( $id, 'medium' ) ?: $url ),
				'large'     => $this->transformer->transform_asset_url( wp_get_attachment_image_url( $id, 'large' ) ?: $url ),
				'full'      => $this->transformer->transform_asset_url( $url ),
			],
		];
	}
}
