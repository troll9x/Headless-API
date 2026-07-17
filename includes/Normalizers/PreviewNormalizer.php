<?php
namespace TLU_Headless_API\Normalizers;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use TLU_Headless_API\Services\UrlTransformer;
use TLU_Headless_API\Integrations\PolylangIntegration;

final class PreviewNormalizer {

	private MediaNormalizer $media;
	private AcfNormalizer $acf;
	private TaxonomyNormalizer $taxonomy;
	private UserNormalizer $user;
	private UrlTransformer $transformer;
	private PolylangIntegration $polylang;

	public function __construct(
		?MediaNormalizer $media = null,
		?AcfNormalizer $acf = null,
		?TaxonomyNormalizer $taxonomy = null,
		?UserNormalizer $user = null,
		?UrlTransformer $transformer = null,
		?PolylangIntegration $polylang = null
	) {
		$this->media       = $media ?? new MediaNormalizer();
		$this->acf         = $acf ?? new AcfNormalizer();
		$this->taxonomy    = $taxonomy ?? new TaxonomyNormalizer();
		$this->user        = $user ?? new UserNormalizer();
		$this->transformer = $transformer ?? new UrlTransformer();
		$this->polylang    = $polylang ?? new PolylangIntegration();
	}

	public function normalize( array $context ): array {
		$parent = $context['parent_post'] ?? null;
		$source = $context['source_post'] ?? null;

		if ( ! $parent instanceof \WP_Post || ! $source instanceof \WP_Post ) {
			return [];
		}

		$content_post = clone $parent;
		foreach ( [ 'post_title', 'post_content', 'post_excerpt', 'post_modified' ] as $field ) {
			if ( isset( $source->{$field} ) ) {
				$content_post->{$field} = $source->{$field};
			}
		}
		if ( ! empty( $source->post_name ) ) {
			$content_post->post_name = $source->post_name;
		}

		$language = $context['language'] ?? '';
		$translations = [];
		if ( $this->polylang->is_active() && method_exists( $this->polylang, 'get_post_translations' ) ) {
			$translations = $this->polylang->get_post_translations( $parent->ID );
		}

		return [
			'preview' => [
				'is_preview' => true,
				'source'     => $context['source'] ?? 'current',
				'source_id'  => (int) ( $context['source_id'] ?? $parent->ID ),
				'post_id'    => (int) $parent->ID,
				'expires_at' => (int) ( $context['expires_at'] ?? 0 ),
			],
			'content' => [
				'id'             => (int) $parent->ID,
				'type'           => (string) $parent->post_type,
				'status'         => (string) $parent->post_status,
				'slug'           => (string) $content_post->post_name,
				'title'          => get_the_title( $content_post ),
				'excerpt'        => wp_strip_all_tags( get_the_excerpt( $content_post ) ),
				'content'        => apply_filters( 'the_content', $content_post->post_content ),
				'date'           => (string) ( $parent->post_date ?? '' ),
				'modified'       => (string) ( $content_post->post_modified ?? '' ),
				'url'            => $this->transformer->transform_navigation_url( get_permalink( $parent ) ),
				'featured_image' => $this->media->normalize( get_post_thumbnail_id( $parent->ID ) ?: null ),
				'author'         => $this->user->normalize( (int) ( $parent->post_author ?? 0 ) ),
				'terms'          => $this->get_terms( $parent ),
				'acf'            => $this->get_acf( $parent, $source, $context ),
				'language'       => $language ?: null,
				'translations'   => $translations,
			],
			'seo' => [
				'title'       => get_the_title( $content_post ),
				'description' => wp_strip_all_tags( get_the_excerpt( $content_post ) ),
				'canonical'   => $this->transformer->transform_navigation_url( get_permalink( $parent ) ),
				'robots'      => [ 'noindex', 'nofollow', 'noarchive' ],
				'open_graph'  => [],
				'twitter'     => [],
				'hreflang'    => [],
				'schema'      => [],
				'breadcrumbs' => [],
				'source'      => 'preview',
			],
		];
	}

	private function get_terms( \WP_Post $post ): array {
		$taxonomies = get_object_taxonomies( $post->post_type );
		$terms = get_the_terms( $post->ID, $taxonomies );
		if ( ! $terms || is_wp_error( $terms ) ) {
			return [];
		}
		return array_values( array_map( [ $this->taxonomy, 'normalize' ], $terms ) );
	}

	private function get_acf( \WP_Post $parent, \WP_Post $source, array $context ): array {
		$acf = function_exists( 'get_fields' ) ? ( get_fields( $parent->ID ) ?: [] ) : [];
		$acf = $this->acf->normalize( $acf );
		$acf = apply_filters( 'headless_api_preview_acf_source', $acf, $context, $parent, $source );
		return is_array( $acf ) ? $this->acf->normalize( $acf ) : [];
	}
}