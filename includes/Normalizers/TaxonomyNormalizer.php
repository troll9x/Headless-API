<?php
namespace TLU_Headless_API\Normalizers;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use TLU_Headless_API\Contracts\NormalizerInterface;

/**
 * Chuẩn hóa WP_Term hoặc giá trị ACF taxonomy field.
 *
 * Chấp nhận:
 * - WP_Term đơn
 * - Mảng WP_Term
 * - Term ID (số nguyên)
 * - Mảng term ID
 *
 * Cấu trúc đầu ra mỗi term:
 * { id, name, slug, taxonomy, description, count, parent, link }
 */
class TaxonomyNormalizer implements NormalizerInterface {

	/** Luôn trả về mảng của mảng term — kể cả khi đầu vào là một term đơn. */
	public function normalize( $value ): array {
		if ( empty( $value ) ) {
			return [];
		}

		if ( $value instanceof \WP_Term ) {
			return [ $this->from_term( $value ) ];
		}

		if ( is_array( $value ) ) {
			$result = [];
			foreach ( $value as $item ) {
				if ( $item instanceof \WP_Term ) {
					$result[] = $this->from_term( $item );
				} elseif ( is_numeric( $item ) ) {
					$term = get_term( (int) $item );
					if ( $term && ! is_wp_error( $term ) ) {
						$result[] = $this->from_term( $term );
					}
				}
			}
			return $result;
		}

		if ( is_numeric( $value ) ) {
			$term = get_term( (int) $value );
			return ( $term && ! is_wp_error( $term ) ) ? [ $this->from_term( $term ) ] : [];
		}

		return [];
	}

	/** Chuyển WP_Term thành mảng chuẩn hóa. */
	public function from_term( \WP_Term $term ): array {
		$link = get_term_link( $term );
		return [
			'id'          => $term->term_id,
			'name'        => sanitize_text_field( $term->name ),
			'slug'        => $term->slug,
			'taxonomy'    => $term->taxonomy,
			'description' => sanitize_text_field( $term->description ),
			'count'       => (int) $term->count,
			'parent'      => (int) $term->parent,
			'link'        => is_wp_error( $link ) ? '' : esc_url_raw( $link ),
		];
	}
}
