<?php
namespace TLU_Headless_API\Services;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use TLU_Headless_API\Integrations\PolylangIntegration;
use TLU_Headless_API\Normalizers\MediaNormalizer;

/**
 * Chuẩn hóa danh sách và hồ sơ chi tiết của CPT to-chuc cho frontend headless.
 */
final class OrganizationService {

	private const POST_TYPE = 'to-chuc';
	private const TAXONOMY  = 'danh-muc-to-chuc';

	private PolylangIntegration $polylang;
	private MediaNormalizer $media;
	private UrlTransformer $transformer;
	private ContentResolver $resolver;

	public function __construct(
		?PolylangIntegration $polylang = null,
		?MediaNormalizer $media = null,
		?UrlTransformer $transformer = null,
		?ContentResolver $resolver = null
	) {
		$this->polylang    = $polylang ?? new PolylangIntegration();
		$this->transformer = $transformer ?? new UrlTransformer();
		$this->media       = $media ?? new MediaNormalizer( $this->transformer );
		$this->resolver    = $resolver ?? new ContentResolver();
	}

	/** @return array|\WP_Error */
	public function get_by_category( string $slug, string $language = '' ) {
		if ( ! post_type_exists( self::POST_TYPE ) || ! taxonomy_exists( self::TAXONOMY ) ) {
			return new \WP_Error(
				'headless_organization_unavailable',
				'Post type to-chuc hoặc taxonomy danh-muc-to-chuc chưa được đăng ký.',
				[ 'status' => 503 ]
			);
		}

		$lang = $this->resolve_language( $language );
		if ( is_wp_error( $lang ) ) {
			return $lang;
		}

		$term = $this->resolve_category( $slug, $lang );
		if ( is_wp_error( $term ) ) {
			return $term;
		}

		$args = [
			'post_type'           => self::POST_TYPE,
			'post_status'         => 'publish',
			'posts_per_page'      => -1,
			'orderby'             => 'title',
			'order'               => 'ASC',
			'no_found_rows'       => true,
			'ignore_sticky_posts' => true,
			'tax_query'           => [
				[
					'taxonomy' => self::TAXONOMY,
					'field'    => 'term_id',
					'terms'    => [ $term->term_id ],
				],
			],
		];

		if ( '' !== $lang && $this->polylang->is_active() ) {
			$args['lang'] = $lang;
		}

		$query = new \WP_Query( $args );
		$posts = $query->posts;
		usort( $posts, [ $this, 'compare_members' ] );

		$leader_id = absint( get_term_meta( $term->term_id, '_leader_member_id', true ) );
		$leader    = null;
		$members   = [];

		foreach ( $posts as $post ) {
			if ( ! $post instanceof \WP_Post ) {
				continue;
			}

			$member = $this->normalize_member( $post );
			if ( $post->ID === $leader_id ) {
				$leader = $member;
				continue;
			}

			$members[] = $member;
		}

		return [
			'source'       => 'hien_thi_to_chuc',
			'post_type'    => self::POST_TYPE,
			'taxonomy'     => self::TAXONOMY,
			'lang'         => $lang,
			'category'     => $this->normalize_category( $term ),
			'leader'       => $leader,
			'members'      => $members,
			'total'        => count( $posts ),
			'member_count' => count( $members ),
		];
	}

	/** @return array|\WP_Error */
	public function get_member( string $slug, string $language = '' ) {
		if ( ! post_type_exists( self::POST_TYPE ) ) {
			return new \WP_Error(
				'headless_organization_unavailable',
				'Post type to-chuc chưa được đăng ký.',
				[ 'status' => 503 ]
			);
		}

		if ( ! function_exists( 'get_field' ) ) {
			return new \WP_Error(
				'headless_organization_acf_unavailable',
				'ACF chưa hoạt động nên không thể đọc hồ sơ tổ chức.',
				[ 'status' => 503 ]
			);
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
				'headless_organization_member_not_found',
				'Không tìm thấy hồ sơ tổ chức công khai.',
				[ 'status' => 404 ]
			);
		}

		$primary_position   = $this->get_scalar_field( 'chuc_vu', $post->ID );
		$secondary_position = $this->get_scalar_field( 'chuc_vu_phu', $post->ID );
		$name               = $this->get_scalar_field( 'ho_va_ten', $post->ID );
		$name               = '' !== $name ? $name : sanitize_text_field( get_the_title( $post ) );
		$positions          = [];

		if ( '' !== $secondary_position ) {
			$positions[] = [ 'type' => 'secondary', 'value' => $secondary_position ];
		}
		if ( '' !== $primary_position ) {
			$positions[] = [ 'type' => 'primary', 'value' => $primary_position ];
		}

		$terms = taxonomy_exists( self::TAXONOMY ) ? get_the_terms( $post->ID, self::TAXONOMY ) : [];
		$terms = is_array( $terms )
			? array_values( array_map( [ $this, 'normalize_category' ], $terms ) )
			: [];

		return [
			'source'             => 'single-to-chuc',
			'post_type'          => self::POST_TYPE,
			'id'                 => (int) $post->ID,
			'slug'               => sanitize_title( $post->post_name ),
			'name'               => $name,
			'title'              => sanitize_text_field( get_the_title( $post ) ),
			'primary_position'   => $primary_position,
			'secondary_position' => $secondary_position,
			'positions'          => $positions,
			'birth_year'         => $this->get_scalar_field( 'nam_sinh', $post->ID ),
			'hometown'           => $this->get_scalar_field( 'que_quan', $post->ID ),
			'qualification'      => $this->get_scalar_field( 'trinh_do', $post->ID ),
			'avatar'             => $this->media->normalize( get_post_thumbnail_id( $post->ID ) ?: null ),
			'initial'            => $this->get_initial( $name ),
			'work_history'       => $this->get_work_history( $post->ID ),
			'biography'          => wp_kses_post( apply_filters( 'the_content', $post->post_content ) ),
			'link'               => $this->transformer->transform_navigation_url( get_permalink( $post ) ),
			'date'               => (string) get_post_time( DATE_ATOM, false, $post ),
			'modified'           => (string) get_post_modified_time( DATE_ATOM, false, $post ),
			'categories'         => $terms,
			'lang'               => $this->polylang->get_post_language( $post->ID ),
			'translations'       => $this->polylang->get_post_translations( $post->ID ),
		];
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
		$term = get_term_by( 'slug', sanitize_title( $slug ), self::TAXONOMY );
		if ( ! $term instanceof \WP_Term ) {
			return new \WP_Error(
				'headless_organization_category_not_found',
				'Không tìm thấy danh mục tổ chức.',
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
						'headless_organization_category_not_found',
						'Không tìm thấy bản dịch danh mục tổ chức.',
						[ 'status' => 404 ]
					);
				}
				$term = $translated;
			}
		}

		return $term;
	}

	private function normalize_category( \WP_Term $term ): array {
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

	private function normalize_member( \WP_Post $post ): array {
		$name       = sanitize_text_field( get_the_title( $post ) );
		$position   = $this->get_position( $post->ID );
		$priority   = absint( get_post_meta( $post->ID, 'uu_tien_to_chuc', true ) );
		$thumbnail  = get_post_thumbnail_id( $post->ID );

		return [
			'id'          => (int) $post->ID,
			'slug'        => sanitize_title( $post->post_name ),
			'name'        => $name,
			'position'    => $position,
			'description' => wp_strip_all_tags( get_the_excerpt( $post ) ),
			'priority'    => $priority > 0 ? $priority : null,
			'link'        => $this->transformer->transform_navigation_url( get_permalink( $post ) ),
			'avatar'      => $this->media->normalize( $thumbnail ?: null ),
			'initial'     => $this->get_initial( $name ),
		];
	}

	private function get_position( int $post_id ): string {
		return $this->get_scalar_field( 'chuc_vu', $post_id );
	}

	private function get_scalar_field( string $field, int $post_id ): string {
		$value = function_exists( 'get_field' )
			? get_field( $field, $post_id )
			: get_post_meta( $post_id, $field, true );

		return is_scalar( $value ) ? sanitize_text_field( (string) $value ) : '';
	}

	private function get_work_history( int $post_id ): array {
		$rows = get_field( 'qua_trinh_cong_tac', $post_id );
		if ( ! is_array( $rows ) ) {
			return [];
		}

		$history = [];
		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}

			$date = is_scalar( $row['ngay_thang_nam'] ?? null )
				? sanitize_text_field( (string) $row['ngay_thang_nam'] )
				: '';
			$description = is_scalar( $row['mo_ta_qua_trinh'] ?? null )
				? sanitize_text_field( (string) $row['mo_ta_qua_trinh'] )
				: '';

			if ( '' === $date || '' === $description ) {
				continue;
			}

			$index     = count( $history );
			$history[] = [
				'index'       => $index,
				'side'        => 0 === $index % 2 ? 'left' : 'right',
				'date'        => $date,
				'description' => $description,
			];
		}

		return $history;
	}

	private function get_initial( string $name ): string {
		if ( '' === $name ) {
			return '';
		}

		$initial = function_exists( 'mb_substr' ) ? mb_substr( $name, 0, 1 ) : substr( $name, 0, 1 );
		return function_exists( 'mb_strtoupper' ) ? mb_strtoupper( $initial ) : strtoupper( $initial );
	}

	private function compare_members( \WP_Post $left, \WP_Post $right ): int {
		$left_priority  = absint( get_post_meta( $left->ID, 'uu_tien_to_chuc', true ) );
		$right_priority = absint( get_post_meta( $right->ID, 'uu_tien_to_chuc', true ) );
		$left_priority  = $left_priority > 0 ? $left_priority : PHP_INT_MAX;
		$right_priority = $right_priority > 0 ? $right_priority : PHP_INT_MAX;

		if ( $left_priority !== $right_priority ) {
			return $left_priority <=> $right_priority;
		}

		return strcasecmp( get_the_title( $left ), get_the_title( $right ) );
	}
}
