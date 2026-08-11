<?php
namespace TLU_Headless_API\Normalizers;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use TLU_Headless_API\Contracts\NormalizerInterface;
use TLU_Headless_API\Services\UrlTransformer;

/**
 * Chuẩn hóa ACF link field và page_link field.
 *
 * Chấp nhận: mảng ACF link {title, url, target}, URL string, post ID,
 * đối tượng WP_Post hoặc null. Page Link của ACF có thể trả về post ID
 * hoặc WP_Post tùy cấu hình Return Format.
 *
 * Cấu trúc đầu ra: { title: string, url: string, target: "_self"|"_blank" }
 * Không bao giờ trả về null — trả về shape rỗng khi đầu vào không hợp lệ.
 */
class LinkNormalizer implements NormalizerInterface {

	private UrlTransformer $transformer;

	public function __construct( ?UrlTransformer $transformer = null ) {
		$this->transformer = $transformer ?? new UrlTransformer();
	}

	public function normalize( $value ): array {
		if ( empty( $value ) ) {
			return $this->empty();
		}

		if ( $value instanceof \WP_Post ) {
			return $this->normalize_post( $value );
		}

		if ( is_numeric( $value ) ) {
			$post = get_post( (int) $value );
			return $post instanceof \WP_Post ? $this->normalize_post( $post ) : $this->empty();
		}

		if ( is_array( $value ) && isset( $value['url'] ) ) {
			return [
				'title'  => sanitize_text_field( $value['title'] ?? $value['text'] ?? '' ),
				'url'    => $this->transformer->transform_navigation_url( $value['url'] ),
				'target' => $this->valid_target( $value['target'] ?? '' ),
			];
		}

		if ( is_array( $value ) && isset( $value['ID'] ) && is_numeric( $value['ID'] ) ) {
			$post = get_post( (int) $value['ID'] );
			return $post instanceof \WP_Post ? $this->normalize_post( $post ) : $this->empty();
		}

		if ( is_string( $value ) && '' !== $value ) {
			return [ 'title' => '', 'url' => $this->transformer->transform_navigation_url( $value ), 'target' => '_self' ];
		}

		return $this->empty();
	}

	public function empty(): array {
		return [ 'title' => '', 'url' => '', 'target' => '_self' ];
	}

	private function normalize_post( \WP_Post $post ): array {
		$url = get_permalink( $post );

		return [
			'title'  => sanitize_text_field( get_the_title( $post ) ),
			'url'    => is_string( $url ) ? $this->transformer->transform_navigation_url( $url ) : '',
			'target' => '_self',
		];
	}

	private function valid_target( string $target ): string {
		return in_array( $target, [ '_blank', '_self' ], true ) ? $target : '_self';
	}
}
