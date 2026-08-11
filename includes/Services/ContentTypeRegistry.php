<?php

namespace TLU_Headless_API\Services;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use TLU_Headless_API\Helpers\ContentVisibility;
use WP_Post_Type;
use WP_Taxonomy;

/**
 * Registry động cho Custom Post Type và Custom Taxonomy công khai.
 *
 * ACF Pro đăng ký Content Type/Taxonomy vào WordPress như object native.
 * Registry này đọc trực tiếp registry WordPress, vì vậy không phụ thuộc
 * cách đăng ký: ACF UI, code hay plugin khác.
 */
final class ContentTypeRegistry {

	private UrlTransformer $transformer;

	public function __construct( ?UrlTransformer $transformer = null ) {
		$this->transformer = $transformer ?? new UrlTransformer();
	}

	/** @return array<int,array<string,mixed>> */
	public function get_post_types(): array {
		$post_types = get_post_types( [ 'public' => true ], 'objects' );
		$result     = [];

		foreach ( $post_types as $post_type ) {
			if ( $post_type instanceof WP_Post_Type && ContentVisibility::is_post_type_public( $post_type ) ) {
				$result[] = $this->normalize_post_type( $post_type );
			}
		}

		usort( $result, fn( array $a, array $b ): int => strnatcasecmp( $a['label'], $b['label'] ) );

		return $result;
	}

	/** @return array<string,mixed>|null */
	public function get_post_type( string $post_type ): ?array {
		$object = get_post_type_object( sanitize_key( $post_type ) );

		return $object instanceof WP_Post_Type && ContentVisibility::is_post_type_public( $object )
			? $this->normalize_post_type( $object )
			: null;
	}

	/** @return array<int,array<string,mixed>> */
	public function get_taxonomies(): array {
		$taxonomies = get_taxonomies( [ 'public' => true ], 'objects' );
		$result     = [];

		foreach ( $taxonomies as $taxonomy ) {
			if ( $taxonomy instanceof WP_Taxonomy && ContentVisibility::is_taxonomy_public( $taxonomy ) ) {
				$result[] = $this->normalize_taxonomy( $taxonomy );
			}
		}

		usort( $result, fn( array $a, array $b ): int => strnatcasecmp( $a['label'], $b['label'] ) );

		return $result;
	}

	/** @return array<string,mixed>|null */
	public function get_taxonomy( string $taxonomy ): ?array {
		$object = get_taxonomy( sanitize_key( $taxonomy ) );

		return $object instanceof WP_Taxonomy && ContentVisibility::is_taxonomy_public( $object )
			? $this->normalize_taxonomy( $object )
			: null;
	}

	/** @return array<string,mixed> */
	private function normalize_post_type( WP_Post_Type $post_type ): array {
		$archive = get_post_type_archive_link( $post_type->name );

		return [
			'name'            => $post_type->name,
			'label'           => sanitize_text_field( $post_type->label ),
			'singular_label'  => sanitize_text_field( $post_type->labels->singular_name ?? $post_type->label ),
			'description'     => sanitize_textarea_field( $post_type->description ),
			'has_archive'     => (bool) $post_type->has_archive,
			'archive'         => is_string( $archive ) ? $this->transformer->transform_navigation_url( $archive ) : '',
			'show_in_rest'    => (bool) $post_type->show_in_rest,
			'rest_base'       => $post_type->show_in_rest ? (string) ( $post_type->rest_base ?: $post_type->name ) : '',
			'supports'        => array_values( array_keys( get_all_post_type_supports( $post_type->name ) ) ),
			'taxonomies'      => $this->get_post_type_taxonomies( $post_type->name ),
		];
	}

	/** @return array<string,mixed> */
	private function normalize_taxonomy( WP_Taxonomy $taxonomy ): array {
		return [
			'name'            => $taxonomy->name,
			'label'           => sanitize_text_field( $taxonomy->label ),
			'singular_label'  => sanitize_text_field( $taxonomy->labels->singular_name ?? $taxonomy->label ),
			'description'     => sanitize_textarea_field( $taxonomy->description ),
			'hierarchical'    => (bool) $taxonomy->hierarchical,
			'show_in_rest'    => (bool) $taxonomy->show_in_rest,
			'rest_base'       => $taxonomy->show_in_rest ? (string) ( $taxonomy->rest_base ?: $taxonomy->name ) : '',
			'object_types'    => $this->get_taxonomy_post_types( $taxonomy ),
		];
	}

	/** @return string[] */
	private function get_post_type_taxonomies( string $post_type ): array {
		$taxonomies = get_object_taxonomies( $post_type );

		return array_values(
			array_filter(
				$taxonomies,
				fn( string $taxonomy ): bool => ContentVisibility::is_taxonomy_public( $taxonomy )
			)
		);
	}

	/** @return string[] */
	private function get_taxonomy_post_types( WP_Taxonomy $taxonomy ): array {
		return array_values(
			array_filter(
				(array) $taxonomy->object_type,
				fn( string $post_type ): bool => ContentVisibility::is_post_type_public( $post_type )
			)
		);
	}
}
