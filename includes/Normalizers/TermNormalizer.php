<?php
namespace TLU_Headless_API\Normalizers;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use TLU_Headless_API\Contracts\NormalizerInterface;
use TLU_Headless_API\Integrations\PolylangIntegration;
use TLU_Headless_API\Services\UrlTransformer;

/**
 * Chuẩn hóa WP_Term thành object có cấu trúc ổn định.
 *
 * Dùng cho: term response, taxonomy archive, term relationship.
 *
 * Cấu trúc đầu ra:
 * {
 *   id, slug, name, description, count, parent,
 *   link, taxonomy, ancestors, meta, children
 * }
 */
class TermNormalizer implements NormalizerInterface {

	private UrlTransformer $transformer;
	private PolylangIntegration $polylang;

	public function __construct( ?UrlTransformer $transformer = null, ?PolylangIntegration $polylang = null ) {
		$this->transformer = $transformer ?? new UrlTransformer();
		$this->polylang    = $polylang ?? new PolylangIntegration();
	}

	/** Chấp nhận WP_Term, term ID, hoặc slug. */
	public function normalize( $value ): array {
		if ( is_numeric( $value ) ) {
			$value = get_term( (int) $value );
		} elseif ( is_string( $value ) ) {
			$value = get_term_by( 'slug', $value );
		}

		if ( ! ( $value instanceof \WP_Term ) ) {
			return [];
		}

		return $this->from_term( $value );
	}

	/** Tạo mảng từ WP_Term. */
	public function from_term( \WP_Term $term ): array {
		$term_link = get_term_link( $term );

		// Build ancestors chain
		$ancestors = [];
		if ( $term->parent > 0 ) {
			$parent = get_term( $term->parent, $term->taxonomy );
			if ( $parent instanceof \WP_Term ) {
				$ancestors[] = $this->from_term( $parent );
				// Recursively get grandparents
				$grandparent = get_term( $parent->parent, $term->taxonomy );
				if ( $grandparent instanceof \WP_Term ) {
					$ancestors[] = $this->from_term( $grandparent );
				}
			}
		}

		// Get children count (for hierarchical taxonomies)
		$children_count = 0;
		if ( is_taxonomy_hierarchical( $term->taxonomy ) ) {
			$children = get_terms( [
				'taxonomy'   => $term->taxonomy,
				'parent'     => $term->term_id,
				'hide_empty' => false,
				'number'     => 0,
			] );
			$children_count = is_array( $children ) ? count( $children ) : 0;
		}

		return [
			'id'          => (int) $term->term_id,
			'slug'        => $term->slug,
			'name'        => $term->name,
			'description' => $term->description ?? '',
			'count'       => (int) $term->count,
			'parent'      => (int) $term->parent,
			'link'        => $this->transformer->transform_navigation_url( $term_link ),
			'taxonomy'    => $term->taxonomy,
			'hierarchical' => (bool) is_taxonomy_hierarchical( $term->taxonomy ),
			'ancestors'   => $ancestors,
			'children_count' => $children_count,
			'meta'        => $this->get_term_meta( $term ),
			'language'    => $this->build_language_metadata( $term ),
			'translations' => $this->build_translations( $term ),
		];
	}

	private function build_language_metadata( \WP_Term $term ): ?array {
		if ( ! $this->polylang->is_active() ) {
			return null;
		}

		$slug = $this->polylang->get_term_language( $term->term_id );
		if ( '' === $slug ) {
			return null;
		}

		foreach ( $this->polylang->get_languages() as $language ) {
			if ( $language['slug'] === $slug ) {
				return [
					'slug'       => $language['slug'],
					'locale'     => $language['locale'],
					'name'       => $language['name'],
					'is_default' => $language['is_default'],
				];
			}
		}

		return null;
	}

	private function build_translations( \WP_Term $term ): array {
		if ( ! $this->polylang->is_active() ) {
			return [];
		}

		$result = [];
		foreach ( $this->polylang->get_term_translations( $term->term_id ) as $translation ) {
			$result[] = [
				'language' => $translation['language'],
				'locale'   => $translation['locale'],
				'id'       => $translation['id'],
				'url'      => $this->transformer->transform_navigation_url( $translation['url'] ),
			];
		}

		return $result;
	}

	/**
	 * Lấy meta data của term.
	 *
	 * @param \WP_Term $term Term object.
	 * @return array Meta data.
	 */
	private function get_term_meta( \WP_Term $term ): array {
		$meta = [];

		// Get all term meta
		$term_meta = get_term_meta( $term->term_id );

		foreach ( $term_meta as $key => $value ) {
			// Skip internal/ Rank Math keys
			if ( str_starts_with( $key, '_' ) || str_starts_with( $key, 'rank_math_' ) ) {
				continue;
			}

			$meta[ $key ] = is_array( $value ) && count( $value ) === 1 ? $value[0] : $value;
		}

		return $meta;
	}
}
