<?php

namespace TLU_Headless_API\Normalizers;

use TLU_Headless_API\Services\UrlTransformer;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Dispatcher chuẩn hóa ACF field theo type.
 */
class AcfNormalizer {

	private MediaNormalizer $media;
	private LinkNormalizer $link;
	private RelationshipNormalizer $relationship;
	private TaxonomyNormalizer $taxonomy;
	private UserNormalizer $user;
	private UrlTransformer $transformer;

	public function __construct(
		?MediaNormalizer $media = null,
		?LinkNormalizer $link = null,
		?RelationshipNormalizer $relationship = null,
		?TaxonomyNormalizer $taxonomy = null,
		?UserNormalizer $user = null,
		?UrlTransformer $transformer = null
	) {
		$this->transformer  = $transformer ?? new UrlTransformer();
		$this->media        = $media ?? new MediaNormalizer( $this->transformer );
		$this->link         = $link ?? new LinkNormalizer( $this->transformer );
		$this->relationship = $relationship ?? new RelationshipNormalizer();
		$this->taxonomy     = $taxonomy ?? new TaxonomyNormalizer();
		$this->user         = $user ?? new UserNormalizer();
	}

	public function normalize(
		$value,
		string $type = 'text',
		array $field = []
	): mixed {
		switch ( $type ) {
			case 'image':
			case 'file':
				return $this->media->normalize( $value );

			case 'gallery':
				return $this->media->normalize_gallery( $value );

			case 'url':
				return $this->transformer->transform_navigation_url(
					(string) $value
				);

			case 'link':
			case 'page_link':
				return $this->link->normalize( $value );

			case 'relationship':
			case 'post_object':
				return $this->relationship->normalize( $value );

			case 'taxonomy':
				return $this->taxonomy->normalize( $value );

			case 'user':
				return $this->user->normalize( $value );

			case 'repeater':
				return $this->normalize_repeater(
					$value,
					$field['sub_fields'] ?? []
				);

			case 'group':
			case 'clone':
				return $this->normalize_group(
					$value,
					$field['sub_fields'] ?? []
				);

			case 'flexible_content':
				return $this->normalize_flexible(
					$value,
					$field['layouts'] ?? []
				);

			case 'true_false':
				return (bool) $value;

			case 'number':
			case 'range':
				return is_numeric( $value ) ? (float) $value : null;

			default:
				return $value;
		}
	}

	public function normalize_field_objects(
		array $field_objects
	): array {
		$result = [];

		foreach ( $field_objects as $name => $field ) {
			$result[ $name ] = $this->normalize(
				$field['value'],
				$field['type'] ?? 'text',
				$field
			);
		}

		return $result;
	}

	private function normalize_repeater(
		$rows,
		array $sub_fields
	): array {
		if ( empty( $rows ) || ! is_array( $rows ) ) {
			return [];
		}

		$type_map = [];

		foreach ( $sub_fields as $sub_field ) {
			if ( isset( $sub_field['name'] ) ) {
				$type_map[ $sub_field['name'] ] = $sub_field;
			}
		}

		return array_values(
			array_map(
				function ( $row ) use ( $type_map ): array {
					if ( ! is_array( $row ) ) {
						return [];
					}

					$normalized = [];

					foreach ( $row as $key => $value ) {
						$field = $type_map[ $key ] ?? [];

						$normalized[ $key ] = $this->normalize(
							$value,
							$field['type'] ?? 'text',
							$field
						);
					}

					return $normalized;
				},
				$rows
			)
		);
	}

	private function normalize_group(
		$value,
		array $sub_fields
	): array {
		if ( empty( $value ) || ! is_array( $value ) ) {
			return [];
		}

		$type_map = [];

		foreach ( $sub_fields as $sub_field ) {
			if ( isset( $sub_field['name'] ) ) {
				$type_map[ $sub_field['name'] ] = $sub_field;
			}
		}

		$result = [];

		foreach ( $value as $key => $item_value ) {
			$field = $type_map[ $key ] ?? [];

			$result[ $key ] = $this->normalize(
				$item_value,
				$field['type'] ?? 'text',
				$field
			);
		}

		return $result;
	}

	private function normalize_flexible(
		$blocks,
		array $layouts
	): array {
		if ( empty( $blocks ) || ! is_array( $blocks ) ) {
			return [];
		}

		$layout_map = [];

		foreach ( $layouts as $layout ) {
			$name = $layout['name'] ?? '';

			if ( '' === $name ) {
				continue;
			}

			$sub_map = [];

			foreach ( $layout['sub_fields'] ?? [] as $sub_field ) {
				if ( isset( $sub_field['name'] ) ) {
					$sub_map[ $sub_field['name'] ] = $sub_field;
				}
			}

			$layout_map[ $name ] = $sub_map;
		}

		$result = [];

		foreach ( $blocks as $block ) {
			if (
				! is_array( $block )
				|| ! isset( $block['acf_fc_layout'] )
			) {
				continue;
			}

			$layout_name = (string) $block['acf_fc_layout'];
			$sub_fields  = $layout_map[ $layout_name ] ?? [];
			$data        = [];

			foreach ( $block as $key => $value ) {
				if ( 'acf_fc_layout' === $key ) {
					continue;
				}

				$field = $sub_fields[ $key ] ?? [];

				$data[ $key ] = $this->normalize(
					$value,
					$field['type'] ?? 'text',
					$field
				);
			}

			$result[] = [
				'layout' => $layout_name,
				'data'   => $data,
			];
		}

		return $result;
	}
}