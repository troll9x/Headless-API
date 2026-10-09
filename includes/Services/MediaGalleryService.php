<?php
namespace TLU_Headless_API\Services;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use TLU_Headless_API\Integrations\MediaGalleryIntegration;
use TLU_Headless_API\Integrations\PolylangIntegration;
use TLU_Headless_API\Normalizers\MediaNormalizer;

/**
 * Chuẩn hóa dữ liệu của hai plugin gallery thành contract dùng được bởi Next.js.
 */
final class MediaGalleryService {

	private MediaGalleryIntegration $integration;
	private PolylangIntegration $polylang;
	private MediaNormalizer $media;
	private UrlTransformer $transformer;

	public function __construct(
		?MediaGalleryIntegration $integration = null,
		?PolylangIntegration $polylang = null,
		?MediaNormalizer $media = null,
		?UrlTransformer $transformer = null
	) {
		$this->integration = $integration ?? new MediaGalleryIntegration();
		$this->polylang    = $polylang ?? new PolylangIntegration();
		$this->transformer = $transformer ?? new UrlTransformer();
		$this->media       = $media ?? new MediaNormalizer( $this->transformer );
	}

	/** @return array|\WP_Error */
	public function get_categories( int $page = 1, int $per_page = 12, string $language = '' ) {
		$error = $this->ensure_available( 'categories' );
		if ( is_wp_error( $error ) ) {
			return $error;
		}

		$lang = $this->resolve_language( $language );
		if ( is_wp_error( $lang ) ) {
			return $lang;
		}

		$page     = max( 1, $page );
		$per_page = max( 1, min( 50, $per_page ) );
		$args     = [
			'taxonomy'   => MediaGalleryIntegration::TAXONOMY,
			'hide_empty' => false,
			'number'     => $per_page,
			'offset'     => ( $page - 1 ) * $per_page,
			'orderby'    => 'name',
			'order'      => 'ASC',
		];

		if ( '' !== $lang && $this->polylang->is_active() ) {
			$args['lang'] = $lang;
		}

		$terms = get_terms( $args );
		if ( is_wp_error( $terms ) ) {
			return $terms;
		}

		$count_args = [
			'taxonomy'   => MediaGalleryIntegration::TAXONOMY,
			'hide_empty' => false,
		];
		if ( '' !== $lang && $this->polylang->is_active() ) {
			$count_args['lang'] = $lang;
		}

		$total = wp_count_terms( $count_args );
		if ( is_wp_error( $total ) ) {
			return $total;
		}

		return [
			'source'     => 'sonnguyen-media-gallery',
			'taxonomy'   => MediaGalleryIntegration::TAXONOMY,
			'back_url'   => $this->transformer->transform_navigation_url( $this->integration->get_back_url() ),
			'lang'       => $lang,
			'items'      => array_map(
				fn( \WP_Term $term ): array => $this->normalize_category( $term, $lang, true ),
				$terms
			),
			'pagination' => $this->pagination( $page, $per_page, (int) $total ),
		];
	}

	/** @return array|\WP_Error */
	public function get_category( string $slug, int $page = 1, int $per_page = 24, string $language = '', string $order = 'ASC' ) {
		$error = $this->ensure_available( 'categories' );
		if ( is_wp_error( $error ) ) {
			return $error;
		}

		$lang = $this->resolve_language( $language );
		if ( is_wp_error( $lang ) ) {
			return $lang;
		}

		$term = $this->resolve_category( $slug, $lang );
		if ( is_wp_error( $term ) ) {
			return $term;
		}

		$page     = max( 1, $page );
		$per_page = max( 1, min( 100, $per_page ) );
		$order    = 'DESC' === strtoupper( $order ) ? 'DESC' : 'ASC';
		$query    = $this->query_images( $term, $page, $per_page, $order );

		return [
			'source'     => 'sonnguyen-media-gallery',
			'taxonomy'   => MediaGalleryIntegration::TAXONOMY,
			'back_url'   => $this->transformer->transform_navigation_url( $this->integration->get_back_url() ),
			'lang'       => $lang,
			'order'      => strtolower( $order ),
			'category'   => $this->normalize_category( $term, $lang, false, (int) $query->found_posts ),
			'images'     => array_map(
				fn( \WP_Post $image ): array => $this->media->normalize( $image->ID ),
				$query->posts
			),
			'pagination' => $this->pagination( $page, $per_page, (int) $query->found_posts ),
		];
	}

	/** @return array|\WP_Error */
	public function get_home_gallery() {
		$error = $this->ensure_available( 'home' );
		if ( is_wp_error( $error ) ) {
			return $error;
		}

		$slug = $this->integration->get_selected_category_slug();
		if ( '' === $slug ) {
			return [
				'source'          => 'sonnh-selected-gallery',
				'configured'      => false,
				'category'        => null,
				'featured_image'  => null,
				'featured_index'  => null,
				'selected_images' => [],
				'selection_limit' => 15,
			];
		}

		$term = $this->resolve_category( $slug, '' );
		if ( is_wp_error( $term ) ) {
			return $term;
		}

		$selected = array_values(
			array_filter(
				$this->integration->get_selected_image_ids(),
				// The curated home selection may include an image outside the label category.
				// Validate the attachment itself so all 15 selected public images survive.
				fn( int $id ): bool => $this->is_public_image_attachment( $id )
			)
		);
		$featured = $this->integration->get_featured_image_id();
		if ( ! in_array( $featured, $selected, true ) ) {
			$featured = 0;
		}

		// Giữ đúng hành vi plugin gốc: ảnh nổi bật nằm ở vị trí thứ 7 (index 6).
		if ( $featured > 0 ) {
			$current_index = array_search( $featured, $selected, true );
			if ( false !== $current_index && 6 !== $current_index ) {
				unset( $selected[ $current_index ] );
				$selected = array_values( $selected );
				array_splice( $selected, min( 6, count( $selected ) ), 0, [ $featured ] );
				$selected = array_slice( $selected, 0, 15 );
			}
		}

		$selected_images = [];
		foreach ( $selected as $index => $image_id ) {
			$selected_images[] = array_merge(
				$this->media->normalize( $image_id ),
				[
					'position'    => $index,
					'is_featured' => $featured === $image_id,
				]
			);
		}

		$featured_index = $featured > 0 ? array_search( $featured, $selected, true ) : false;

		return [
			'source'          => 'sonnh-selected-gallery',
			'configured'      => true,
			'category'        => $this->normalize_category( $term, '', false ),
			'featured_image'  => $featured > 0 ? $this->media->normalize( $featured ) : null,
			'featured_index'  => false === $featured_index ? null : (int) $featured_index,
			'selected_images' => $selected_images,
			'selection_limit' => 15,
		];
	}

	/** @return true|\WP_Error */
	private function ensure_available( string $source ) {
		if ( ! $this->integration->is_taxonomy_available() ) {
			return new \WP_Error(
				'media_gallery_taxonomy_unavailable',
				'Taxonomy mlo-category chưa được đăng ký. Hãy kích hoạt Media Library Organizer.',
				[ 'status' => 503 ]
			);
		}

		if ( 'categories' === $source && ! $this->integration->is_category_gallery_active() ) {
			return new \WP_Error(
				'media_gallery_plugin_inactive',
				'Plugin SonNH Media Gallery chưa được kích hoạt.',
				[ 'status' => 503 ]
			);
		}

		if ( 'home' === $source && ! $this->integration->is_selected_gallery_active() ) {
			return new \WP_Error(
				'sonnh_selected_gallery_inactive',
				'Plugin Son NH Template Gallery chưa được kích hoạt.',
				[ 'status' => 503 ]
			);
		}

		return true;
	}

	/** @return string|\WP_Error */
	private function resolve_language( string $language ) {
		$requested = trim( $language );
		if ( '' === $requested ) {
			return '';
		}

		$normalized = $this->polylang->normalize_language( $requested );
		if ( '' === $normalized ) {
			return new \WP_Error(
				'headless_invalid_language',
				'Ngôn ngữ không hợp lệ hoặc Polylang chưa hoạt động.',
				[ 'status' => 400 ]
			);
		}

		return $normalized;
	}

	/** @return \WP_Term|\WP_Error */
	private function resolve_category( string $slug, string $lang ) {
		$term = get_term_by( 'slug', sanitize_title( $slug ), MediaGalleryIntegration::TAXONOMY );
		if ( ! $term instanceof \WP_Term ) {
			return new \WP_Error( 'media_gallery_category_not_found', 'Không tìm thấy danh mục ảnh.', [ 'status' => 404 ] );
		}

		if ( '' !== $lang && $this->polylang->is_active() ) {
			$term_lang = $this->polylang->get_term_language( $term->term_id );
			if ( '' !== $term_lang && $term_lang !== $lang ) {
				$translated_id = $this->polylang->get_term_translation_id( $term->term_id, $lang );
				$translated    = $translated_id > 0
					? get_term( $translated_id, MediaGalleryIntegration::TAXONOMY )
					: null;
				if ( ! $translated instanceof \WP_Term ) {
					return new \WP_Error( 'media_gallery_category_not_found', 'Không tìm thấy bản dịch danh mục ảnh.', [ 'status' => 404 ] );
				}
				$term = $translated;
			}
		}

		return $term;
	}

	private function query_images( \WP_Term $term, int $page, int $per_page, string $order ): \WP_Query {
		return new \WP_Query( [
			'post_type'           => 'attachment',
			'post_status'         => 'inherit',
			'post_mime_type'      => 'image',
			'posts_per_page'      => $per_page,
			'paged'               => $page,
			'orderby'             => 'date',
			'order'               => 'DESC' === strtoupper( $order ) ? 'DESC' : 'ASC',
			'ignore_sticky_posts' => true,
			'tax_query'           => [
				[
					'taxonomy' => MediaGalleryIntegration::TAXONOMY,
					'field'    => 'term_id',
					'terms'    => [ $term->term_id ],
				],
			],
		] );
	}

	private function normalize_category( \WP_Term $term, string $lang, bool $include_cover, ?int $count = null ): array {
		$images_url = rest_url(
			HEADLESS_API_NAMESPACE . '/media-gallery/categories/' . rawurlencode( $term->slug )
		);
		if ( '' !== $lang ) {
			$images_url = add_query_arg( 'lang', $lang, $images_url );
		}

		$data = [
			'id'          => (int) $term->term_id,
			'slug'        => sanitize_title( $term->slug ),
			'name'        => sanitize_text_field( $term->name ),
			'description' => wp_kses_post( (string) $term->description ),
			'count'       => null === $count ? (int) $term->count : $count,
			'parent'      => (int) $term->parent,
			'legacy_url'  => $this->transformer->transform_navigation_url(
				add_query_arg( 'media_cat', $term->slug, $this->integration->get_back_url() )
			),
			'images_url'  => esc_url_raw( $images_url ),
		];

		if ( $include_cover ) {
			$cover         = $this->query_images( $term, 1, 1, 'DESC' );
			$cover_post    = $cover->posts[0] ?? null;
			$data['cover'] = $cover_post instanceof \WP_Post
				? $this->media->normalize( $cover_post->ID )
				: $this->media->empty();
			$data['latest_image_date'] = $cover_post instanceof \WP_Post
				? get_post_time( DATE_ATOM, false, $cover_post )
				: null;
		}

		return $data;
	}

	private function is_public_image_attachment( int $image_id ): bool {
		$attachment = get_post( $image_id );

		return $attachment instanceof \WP_Post
			&& 'attachment' === $attachment->post_type
			&& 'inherit' === $attachment->post_status
			&& wp_attachment_is_image( $image_id )
			&& (bool) wp_get_attachment_url( $image_id );
	}

	private function is_image_in_category( int $image_id, \WP_Term $term ): bool {
		$post = get_post( $image_id );
		return $post instanceof \WP_Post
			&& 'attachment' === $post->post_type
			&& 'inherit' === $post->post_status
			&& str_starts_with( (string) get_post_mime_type( $post ), 'image/' )
			&& has_term( $term->term_id, MediaGalleryIntegration::TAXONOMY, $image_id );
	}

	private function pagination( int $page, int $per_page, int $total ): array {
		return [
			'page'        => $page,
			'per_page'    => $per_page,
			'total'       => $total,
			'total_pages' => $total > 0 ? (int) ceil( $total / $per_page ) : 0,
		];
	}
}
