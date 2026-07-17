<?php
namespace TLU_Headless_API\Normalizers;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use TLU_Headless_API\Contracts\NormalizerInterface;
use TLU_Headless_API\Services\UrlTransformer;

/**
 * Chuẩn hóa archive context thành response object.
 *
 * Dùng cho: archive endpoint response.
 *
 * Cấu trúc đầu ra (post_type):
 * {
 *   type: 'post_type',
 *   post_type: string,
 *   canonical: string,
 *   label: string,
 *   posts: array,
 *   pagination: object
 * }
 *
 * Cấu trúc đầu ra (taxonomy):
 * {
 *   type: 'taxonomy',
 *   taxonomy: object,
 *   term: object,
 *   canonical: string,
 *   label: string,
 *   posts: array,
 *   pagination: object
 * }
 */
class ArchiveNormalizer implements NormalizerInterface {

	private PostNormalizer $post_normalizer;
	private TermNormalizer $term_normalizer;
	private UrlTransformer $transformer;

	public function __construct(
		?PostNormalizer $post_normalizer = null,
		?TermNormalizer $term_normalizer = null,
		?UrlTransformer $transformer = null
	) {
		$this->post_normalizer = $post_normalizer ?? new PostNormalizer();
		$this->term_normalizer = $term_normalizer ?? new TermNormalizer();
		$this->transformer = $transformer ?? new UrlTransformer();
	}

	/** Chấp nhận archive context array hoặc WP_Post. */
	public function normalize( $value ): array {
		if ( $value instanceof \WP_Post ) {
			return $this->from_post( $value );
		}

		if ( ! is_array( $value ) || ! isset( $value['type'] ) ) {
			return [];
		}

		return $value;
	}

	/** Tạo mảng từ archive context. */
	public function from_context( array $context ): array {
		$result = [
			'type'      => $context['type'] ?? 'unknown',
			'canonical' => $context['canonical'] ?? '',
			'label'     => $context['label'] ?? '',
		];

		// Post type archive
		if ( isset( $context['post_type'] ) && '' !== $context['post_type'] ) {
			$result['post_type'] = $context['post_type'];
		}

		// Taxonomy archive
		if ( isset( $context['taxonomy'] ) && is_array( $context['taxonomy'] ) ) {
			$result['taxonomy'] = $context['taxonomy'];
		}

		if ( isset( $context['term'] ) && is_array( $context['term'] ) ) {
			$result['term'] = $context['term'];
		}

		// Author archive
		if ( isset( $context['author'] ) && is_array( $context['author'] ) ) {
			$result['author'] = $context['author'];
		}

		// Date archive
		if ( isset( $context['date'] ) && is_array( $context['date'] ) ) {
			$result['date'] = $context['date'];
		}

		// Language
		if ( isset( $context['language'] ) && '' !== $context['language'] ) {
			$result['lang'] = $context['language'];
		}

		return $result;
	}

	/** Chuẩn hóa WP_Post cho archive list. */
	public function from_post( \WP_Post $post ): array {
		return [
			'id'             => (int) $post->ID,
			'slug'           => $post->post_name,
			'title'          => get_the_title( $post ),
			'excerpt'        => wp_strip_all_tags( get_the_excerpt( $post ) ),
			'link'           => $this->transformer->transform_navigation_url( get_permalink( $post ) ),
			'type'           => $post->post_type,
			'date'           => $post->post_date,
			'modified'       => $post->post_modified,
			'featured_image' => $this->get_post_featured_image( $post ),
			'author'         => $this->get_post_author( $post ),
			'terms'          => $this->get_post_terms( $post ),
		];
	}

	/**
	 * Lấy featured image của post.
	 *
	 * @param \WP_Post $post Post object.
	 * @return array Featured image data.
	 */
	private function get_post_featured_image( \WP_Post $post ): array {
		$thumbnail_id = get_post_thumbnail_id( $post->ID );
		if ( ! $thumbnail_id ) {
			return [];
		}

		$attachment = get_post( $thumbnail_id );
		if ( ! $attachment instanceof \WP_Post ) {
			return [];
		}

		$full_url = wp_get_attachment_url( $thumbnail_id );
		if ( ! $full_url ) {
			return [];
		}

		return [
			'id'          => (int) $thumbnail_id,
			'slug'        => $attachment->post_name,
			'title'       => get_the_title( $attachment ),
			'link'        => $this->transformer->transform_navigation_url( $full_url ),
			'type'        => 'attachment',
			'mime_type'   => $attachment->post_mime_type,
			'width'       => 0,
			'height'      => 0,
		];
	}

	/**
	 * Lấy author của post.
	 *
	 * @param \WP_Post $post Post object.
	 * @return array Author data.
	 */
	private function get_post_author( \WP_Post $post ): array {
		$author_id = $post->post_author;

		return [
			'id'   => (int) $author_id,
			'name' => get_the_author_meta( 'display_name', $author_id ),
			'url'  => get_author_posts_url( $author_id ),
		];
	}

	/**
	 * Lấy terms của post.
	 *
	 * @param \WP_Post $post Post object.
	 * @return array Terms data.
	 */
	private function get_post_terms( \WP_Post $post ): array {
		$terms = get_the_terms( $post->ID, get_object_taxonomies( $post->post_type ) );

		if ( ! $terms || is_wp_error( $terms ) ) {
			return [];
		}

		$result = [];
		foreach ( $terms as $term ) {
			if ( $term instanceof \WP_Term ) {
				$result[] = $this->term_normalizer->from_term( $term );
			}
		}

		return $result;
	}
}