<?php
namespace TLU_Headless_API\Services;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use TLU_Headless_API\Integrations\PolylangIntegration;
use TLU_Headless_API\Normalizers\AcfNormalizer;
use TLU_Headless_API\Normalizers\MediaNormalizer;

/**
 * Chuẩn hóa dữ liệu single và taxonomy archive của CPT tai-lieu cho frontend headless.
 */
final class DocumentService {

	private const POST_TYPE = 'tai-lieu';
	private const TAXONOMY  = 'loai-tai-lieu';

	private ContentResolver $resolver;
	private PolylangIntegration $polylang;
	private AcfNormalizer $acf;
	private MediaNormalizer $media;
	private UrlTransformer $transformer;

	public function __construct(
		?ContentResolver $resolver = null,
		?PolylangIntegration $polylang = null,
		?AcfNormalizer $acf = null,
		?MediaNormalizer $media = null,
		?UrlTransformer $transformer = null
	) {
		$this->resolver    = $resolver ?? new ContentResolver();
		$this->polylang    = $polylang ?? new PolylangIntegration();
		$this->transformer = $transformer ?? new UrlTransformer();
		$this->media       = $media ?? new MediaNormalizer( $this->transformer );
		$this->acf         = $acf ?? new AcfNormalizer( $this->media, null, null, null, null, $this->transformer );
	}

	/** @return array|\WP_Error */
	public function get_document( string $slug, string $language = '' ) {
		$error = $this->ensure_available();
		if ( is_wp_error( $error ) ) {
			return $error;
		}

		$post = $this->resolver->resolve( [
			'slug'      => $slug,
			'post_type' => self::POST_TYPE,
			'lang'      => $language,
		] );

		if ( is_wp_error( $post ) ) {
			return $post;
		}

		if ( ! $post instanceof \WP_Post ) {
			return new \WP_Error(
				'headless_document_not_found',
				'Không tìm thấy tài liệu công khai.',
				[ 'status' => 404 ]
			);
		}

		$raw_rows  = get_field( 'tai_len_tai_lieu', $post->ID );
		$raw_rows  = is_array( $raw_rows ) ? array_values( $raw_rows ) : [];
		$documents = $this->normalize_documents( $raw_rows );
		$banner    = $this->resolve_banner( $post->ID );
		$lang      = $this->polylang->get_post_language( $post->ID );
		$redirect  = count( $raw_rows ) === 1 && count( $documents ) === 1;

		return [
			'source'       => 'single-tai-lieu',
			'post_type'    => self::POST_TYPE,
			'taxonomy'     => self::TAXONOMY,
			'id'           => (int) $post->ID,
			'slug'         => sanitize_title( $post->post_name ),
			'title'        => sanitize_text_field( get_the_title( $post ) ),
			'excerpt'      => wp_strip_all_tags( get_the_excerpt( $post ) ),
			'link'         => $this->transformer->transform_navigation_url( get_permalink( $post ) ),
			'date'         => get_post_time( DATE_ATOM, false, $post ),
			'modified'     => get_post_modified_time( DATE_ATOM, false, $post ),
			'lang'         => $lang,
			'translations' => $this->polylang->get_post_translations( $post->ID ),
			'banner'       => $banner,
			'documents'    => $documents,
			'document_count' => count( $documents ),
			'redirect'     => [
				'required' => $redirect,
				'url'      => $redirect ? $documents[0]['file']['url'] : '',
				'status'   => $redirect ? 302 : null,
			],
		];
	}

	/** @return array|\WP_Error */
	public function get_category_archive( string $slug, string $language = '', int $page = 1, int $per_page = 6 ) {
		$error = $this->ensure_available();
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
		$per_page = max( 1, min( 24, $per_page ) );
		$term_args = [
			'taxonomy'   => self::TAXONOMY,
			'child_of'   => $term->term_id,
			'hide_empty' => false,
			'orderby'    => 'name',
			'order'      => 'ASC',
			'number'     => 50,
		];
		if ( '' !== $lang && $this->polylang->is_active() ) {
			$term_args['lang'] = $lang;
		}

		$children = get_terms( $term_args );
		if ( is_wp_error( $children ) ) {
			return $children;
		}

		$groups = [];
		foreach ( $children as $child ) {
			if ( ! $child instanceof \WP_Term ) {
				continue;
			}

			$group = $this->build_archive_group( $child, $lang, $page, $per_page );
			if ( $group['pagination']['total'] > 0 ) {
				$groups[] = $group;
			}
		}

		$mode = 'children';
		if ( [] === $groups ) {
			$mode     = 'current';
			$groups[] = $this->build_archive_group( $term, $lang, $page, $per_page );
		}

		return [
			'source'     => 'taxonomy-loai-tai-lieu',
			'post_type'  => self::POST_TYPE,
			'taxonomy'   => self::TAXONOMY,
			'lang'       => $lang,
			'mode'       => $mode,
			'category'   => $this->normalize_term( $term ),
			'ancestors'  => $this->get_ancestors( $term ),
			'banner'     => $this->get_banner_for_term( $term ),
			'groups'     => $groups,
			'group_limit' => 50,
			'pagination' => [
				'page'     => $page,
				'per_page' => $per_page,
			],
		];
	}

	/** @return true|\WP_Error */
	private function ensure_available() {
		if ( ! post_type_exists( self::POST_TYPE ) || ! taxonomy_exists( self::TAXONOMY ) ) {
			return new \WP_Error(
				'headless_document_unavailable',
				'Post type tai-lieu hoặc taxonomy loai-tai-lieu chưa được đăng ký.',
				[ 'status' => 503 ]
			);
		}

		if ( ! function_exists( 'get_field' ) ) {
			return new \WP_Error(
				'headless_document_acf_unavailable',
				'ACF chưa hoạt động nên không thể đọc dữ liệu tài liệu.',
				[ 'status' => 503 ]
			);
		}

		return true;
	}

	/** @return string|\WP_Error */
	private function resolve_language( string $language ) {
		$requested = trim( $language );
		if ( '' !== $requested ) {
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

		if ( $this->polylang->is_active() ) {
			return $this->polylang->get_current_language() ?: $this->polylang->get_default_language();
		}

		return '';
	}

	/** @return \WP_Term|\WP_Error */
	private function resolve_category( string $slug, string $lang ) {
		$term = get_term_by( 'slug', sanitize_title( $slug ), self::TAXONOMY );
		if ( ! $term instanceof \WP_Term ) {
			return new \WP_Error(
				'headless_document_category_not_found',
				'Không tìm thấy loại tài liệu.',
				[ 'status' => 404 ]
			);
		}

		if ( '' !== $lang && $this->polylang->is_active() ) {
			$term_lang = $this->polylang->get_term_language( $term->term_id );
			if ( '' !== $term_lang && $term_lang !== $lang ) {
				$translated_id = $this->polylang->get_term_translation_id( $term->term_id, $lang );
				$translated    = $translated_id > 0 ? get_term( $translated_id, self::TAXONOMY ) : null;
				if ( ! $translated instanceof \WP_Term ) {
					return new \WP_Error(
						'headless_document_category_not_found',
						'Không tìm thấy bản dịch loại tài liệu.',
						[ 'status' => 404 ]
					);
				}
				$term = $translated;
			}
		}

		return $term;
	}

	private function build_archive_group( \WP_Term $term, string $lang, int $page, int $per_page ): array {
		$args = [
			'post_type'           => self::POST_TYPE,
			'post_status'         => 'publish',
			'posts_per_page'      => $per_page,
			'paged'               => $page,
			'orderby'             => 'date',
			'order'               => 'DESC',
			'ignore_sticky_posts' => true,
			'tax_query'           => [
				[
					'taxonomy'         => self::TAXONOMY,
					'field'            => 'term_id',
					'terms'            => [ $term->term_id ],
					'include_children' => true,
				],
			],
		];
		if ( '' !== $lang && $this->polylang->is_active() ) {
			$args['lang'] = $lang;
		}

		$query = new \WP_Query( $args );
		$total = (int) $query->found_posts;

		return [
			'category' => $this->normalize_term( $term ),
			'items'    => array_values( array_map( [ $this, 'normalize_archive_item' ], $query->posts ) ),
			'pagination' => [
				'page'        => $page,
				'per_page'    => $per_page,
				'total'       => $total,
				'total_pages' => $total > 0 ? (int) ceil( $total / $per_page ) : 0,
			],
		];
	}

	private function normalize_archive_item( \WP_Post $post ): array {
		$raw_rows = get_field( 'tai_len_tai_lieu', $post->ID );
		$raw_rows = is_array( $raw_rows ) ? array_values( $raw_rows ) : [];
		$file     = count( $raw_rows ) === 1 && is_array( $raw_rows[0] )
			? $this->media->normalize( $raw_rows[0]['tai_lieu'] ?? null )
			: $this->media->empty();
		$detail_url = $this->transformer->transform_navigation_url( get_permalink( $post ) );
		$is_file    = '' !== $file['url'];
		$raw_date   = $this->get_scalar_field( 'ngay_ban_hanh', $post->ID );
		$thumbnail_id = get_post_thumbnail_id( $post->ID );
		$thumbnail    = $thumbnail_id > 0
			? $this->media->normalize( $thumbnail_id )
			: $this->fallback_thumbnail();

		return [
			'id'                  => (int) $post->ID,
			'slug'                => sanitize_title( $post->post_name ),
			'title'               => sanitize_text_field( get_the_title( $post ) ),
			'excerpt'             => wp_strip_all_tags( get_the_excerpt( $post ) ),
			'symbol'              => $this->get_scalar_field( 'ky_hieu', $post->ID ),
			'issued_date'         => $this->acf->normalize( $raw_date, 'date_picker' ),
			'issued_date_display' => $raw_date,
			'thumbnail'           => $thumbnail,
			'thumbnail_source'    => $thumbnail_id > 0 ? 'featured' : 'fallback',
			'detail_url'          => $detail_url,
			'document_count'      => count( $raw_rows ),
			'action'              => [
				'type'   => $is_file ? 'file' : 'detail',
				'url'    => $is_file ? $file['url'] : $detail_url,
				'target' => $is_file ? '_blank' : '_self',
				'rel'    => $is_file ? 'noopener noreferrer' : '',
			],
		];
	}

	private function get_scalar_field( string $field, int $post_id ): string {
		$value = get_field( $field, $post_id );
		return is_scalar( $value ) ? sanitize_text_field( (string) $value ) : '';
	}

	private function fallback_thumbnail(): array {
		$default = home_url( '/wp-content/uploads/2025/07/Mau-don-sinh-vien.webp' );
		$url     = apply_filters( 'headless_api_document_fallback_thumbnail_url', $default );
		return $this->media->empty_with_url( is_string( $url ) ? $url : $default );
	}

	private function normalize_term( \WP_Term $term ): array {
		$link = get_term_link( $term );
		return [
			'id'          => (int) $term->term_id,
			'slug'        => sanitize_title( $term->slug ),
			'name'        => sanitize_text_field( $term->name ),
			'description' => wp_kses_post( (string) $term->description ),
			'parent'      => (int) $term->parent,
			'count'       => (int) $term->count,
			'link'        => is_wp_error( $link ) ? '' : $this->transformer->transform_navigation_url( $link ),
		];
	}

	private function get_ancestors( \WP_Term $term ): array {
		$ids = array_reverse( get_ancestors( $term->term_id, self::TAXONOMY, 'taxonomy' ) );
		$ancestors = [];
		foreach ( $ids as $id ) {
			$ancestor = get_term( (int) $id, self::TAXONOMY );
			if ( $ancestor instanceof \WP_Term ) {
				$ancestors[] = $this->normalize_term( $ancestor );
			}
		}
		return $ancestors;
	}

	private function normalize_documents( array $rows ): array {
		$documents = [];

		foreach ( $rows as $index => $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}

			$file = $this->media->normalize( $row['tai_lieu'] ?? null );
			if ( '' === $file['url'] ) {
				continue;
			}

			$raw_date = is_scalar( $row['ngay_ban_hanh'] ?? null )
				? sanitize_text_field( (string) $row['ngay_ban_hanh'] )
				: '';
			$symbol = is_scalar( $row['ky_hieu'] ?? null )
				? sanitize_text_field( (string) $row['ky_hieu'] )
				: '';
			$title = '' !== $file['title'] ? $file['title'] : 'Tài liệu ' . ( $index + 1 );

			$documents[] = [
				'index'               => $index,
				'symbol'              => $symbol,
				'issued_date'         => $this->acf->normalize( $raw_date, 'date_picker' ),
				'issued_date_display' => $raw_date,
				'title'               => sanitize_text_field( $title ),
				'file'                => $file,
			];
		}

		return $documents;
	}

	private function resolve_banner( int $post_id ): ?array {
		$terms = get_the_terms( $post_id, self::TAXONOMY );
		if ( empty( $terms ) || is_wp_error( $terms ) ) {
			return null;
		}

		$term = reset( $terms );
		if ( ! $term instanceof \WP_Term ) {
			return null;
		}

		return $this->get_banner_for_term( $term );
	}

	private function get_banner_for_term( \WP_Term $term ): ?array {
		$source_term = $term;
		$value       = get_field( 'banner_tin_tuc', self::TAXONOMY . '_' . $term->term_id );
		$inherited   = false;

		if ( empty( $value ) && $term->parent > 0 ) {
			$parent = get_term( $term->parent, self::TAXONOMY );
			if ( $parent instanceof \WP_Term ) {
				$value = get_field( 'banner_tin_tuc', self::TAXONOMY . '_' . $parent->term_id );
				if ( ! empty( $value ) ) {
					$source_term = $parent;
					$inherited   = true;
				}
			}
		}

		$image = $this->media->normalize( $value );
		if ( '' === $image['url'] ) {
			return null;
		}

		return [
			'image'     => $image,
			'inherited' => $inherited,
			'term'      => [
				'id'     => (int) $source_term->term_id,
				'slug'   => sanitize_title( $source_term->slug ),
				'name'   => sanitize_text_field( $source_term->name ),
				'parent' => (int) $source_term->parent,
			],
		];
	}
}
