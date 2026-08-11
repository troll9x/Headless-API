<?php
namespace TLU_Headless_API\Services;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use TLU_Headless_API\Normalizers\TermNormalizer;
use TLU_Headless_API\Normalizers\PostNormalizer;
use TLU_Headless_API\Integrations\PolylangIntegration;
use TLU_Headless_API\Cache\TransientCache;
use TLU_Headless_API\Integrations\RankMathIntegration;

/**
 * Xử lý logic nghiệp vụ cho taxonomy endpoint.
 *
 * Controller chỉ validate tham số và gọi Service.
 * Mọi logic tìm kiếm term, lấy posts, chuẩn hóa và cache nằm ở đây.
 */
class TaxonomyService {

	private TermNormalizer    $term_normalizer;
	private PostNormalizer    $post_normalizer;
	private PolylangIntegration $polylang;
	private TransientCache    $cache;
	private RankMathIntegration $rank_math;

	public function __construct(
		?TermNormalizer $term_normalizer = null,
		?PostNormalizer $post_normalizer = null,
		?PolylangIntegration $polylang = null,
		?TransientCache $cache = null,
		?RankMathIntegration $rank_math = null
	) {
		$this->term_normalizer = $term_normalizer ?? new TermNormalizer();
		$this->post_normalizer = $post_normalizer ?? new PostNormalizer();
		$this->polylang = $polylang ?? new PolylangIntegration();
		$this->cache = $cache ?? TransientCache::from_config();
		$this->rank_math = $rank_math ?? new RankMathIntegration();
	}

	/**
	 * Lấy term object theo taxonomy và term slug hoặc ID.
	 *
	 * @param string $taxonomy Taxonomy slug.
	 * @param string $term Term slug hoặc ID.
	 * @param string $lang Ngôn ngữ.
	 * @return \WP_Term|null Term object hoặc null nếu không tìm thấy.
	 */
	public function get_term( string $taxonomy, string $term, string $lang = '' ) {
		$requested_lang = trim( $lang );
		$lang = $this->polylang->normalize_language( $requested_lang );
		if ( '' !== $requested_lang && '' === $lang ) {
			return null;
		}

		$term_obj = null;

		if ( ctype_digit( $term ) ) {
			$term_obj = get_term( (int) $term, $taxonomy );
		} else {
			$term_obj = get_term_by( 'slug', $term, $taxonomy );
		}

		if ( ! $term_obj instanceof \WP_Term ) {
			return null;
		}

		// Handle Polylang translation
		if ( '' !== $lang && $this->polylang->is_active() ) {
			$translated_term_id = $this->polylang->get_term_translation_id( $term_obj->term_id, $lang );
			if ( $translated_term_id <= 0 ) {
				return null;
			}

			$translated_term = get_term( $translated_term_id, $taxonomy );
			if ( ! $translated_term instanceof \WP_Term ) {
				return null;
			}

			$term_obj = $translated_term;
		}

		return $term_obj;
	}

	/**
	 * Lấy term response đầy đủ (dùng cho endpoint /term).
	 *
	 * @param string $taxonomy Taxonomy slug.
	 * @param string $term Term slug hoặc ID.
	 * @param string $lang Ngôn ngữ.
	 * @param int $page Trang.
	 * @param int $per_page Số lượng term/page.
	 * @return array|null Term data hoặc null nếu không tìm thấy.
	 */
	public function get_term_data( string $taxonomy, string $term, string $lang = '', int $page = 1, int $per_page = 10 ): array {
		$requested_lang = trim( $lang );
		$lang = $this->polylang->normalize_language( $requested_lang );
		if ( '' !== $requested_lang && '' === $lang ) {
			return [
				'error'   => 'headless_invalid_language',
				'message' => 'Ngôn ngữ được yêu cầu không hợp lệ hoặc Polylang chưa hoạt động.',
				'status'  => 400,
			];
		}

		$cache_key = $this->cache->make_key( 'term', $taxonomy, $term, $lang, (string) $page, (string) $per_page );
		$cached = $this->cache->get( $cache_key );

		if ( null !== $cached ) {
			return $cached;
		}

		$term_obj = $this->get_term( $taxonomy, $term, $lang );

		if ( ! $term_obj ) {
			return [
				'error' => 'headless_term_not_found',
				'message' => 'Không tìm thấy term.',
				'status' => 404,
			];
		}

		// Get term children
		$children = get_terms( [
			'taxonomy'   => $taxonomy,
			'parent'     => $term_obj->term_id,
			'hide_empty' => false,
			'number'     => 0,
			'lang'       => $lang,
		] );

		// Build term data
		$term_data = $this->term_normalizer->from_term( $term_obj );
		$term_data['lang'] = $lang;
		$term_data['posts'] = $this->get_term_posts( $term_obj->term_id, $taxonomy, $lang, $page, $per_page );
		$term_data['children'] = array_map( [ $this->term_normalizer, 'from_term' ], $children );
		$term_data['parent'] = $term_obj->parent > 0 ? $this->term_normalizer->from_term( get_term( $term_obj->parent, $taxonomy ) ) : null;

		// Add SEO data if Rank Math active
		if ( $this->rank_math->is_active() ) {
			$term_data['seo'] = $this->get_term_seo( $term_obj );
		}

		$this->cache->set( $cache_key, $term_data );

		return $term_data;
	}

	/**
	 * Lấy danh sách posts trong một term.
	 *
	 * @param int $term_id Term ID.
	 * @param string $taxonomy Taxonomy slug.
	 * @param string $lang Ngôn ngữ.
	 * @param int $page Trang.
	 * @param int $per_page Số lượng post/page.
	 * @return array Danh sách posts.
	 */
	public function get_term_posts( int $term_id, string $taxonomy, string $lang = '', int $page = 1, int $per_page = 10 ): array {
		$args = [
			'tax_query'  => [
				[
					'taxonomy' => $taxonomy,
					'terms'    => [ $term_id ],
					'field'    => 'term_id',
				],
			],
			'paged'      => $page,
			'posts_per_page' => $per_page,
			'post_status' => 'publish',
		];

		if ( '' !== $lang && $this->polylang->is_active() ) {
			$args['lang'] = $lang;
		}

		$posts = get_posts( $args );

		return array_map( [ $this->post_normalizer, 'summary' ], $posts );
	}

	/**
	 * Lấy SEO data cho một term.
	 *
	 * @param \WP_Term $term_obj Term object.
	 * @return array SEO data.
	 */
	public function get_term_seo( \WP_Term $term_obj ): array {
		$meta_key_prefix = 'rank_math_' . $term_obj->taxonomy . '_';

		// Get Rank Math SEO data from term meta
		$seo_data = [
			'title'       => get_term_meta( $term_obj->term_id, $meta_key_prefix . 'title', true ),
			'description' => get_term_meta( $term_obj->term_id, $meta_key_prefix . 'description', true ),
			'robots'      => get_term_meta( $term_obj->term_id, $meta_key_prefix . 'robots', true ),
			'canonical'   => get_term_meta( $term_obj->term_id, $meta_key_prefix . 'canonical', true ),
		];

		// Fallback to default Rank Math keys
		if ( '' === $seo_data['title'] ) {
			$seo_data['title'] = get_term_meta( $term_obj->term_id, 'rank_math_title', true );
		}
		if ( '' === $seo_data['description'] ) {
			$seo_data['description'] = get_term_meta( $term_obj->term_id, 'rank_math_description', true );
		}
		if ( '' === $seo_data['robots'] ) {
			$seo_data['robots'] = get_term_meta( $term_obj->term_id, 'rank_math_robots', true );
		}
		if ( '' === $seo_data['canonical'] ) {
			$seo_data['canonical'] = get_term_meta( $term_obj->term_id, 'rank_math_canonical_url', true );
		}

		return array_filter( $seo_data, fn( $v ) => '' !== $v );
	}

	/**
	 * Lấy danh sách all terms trong một taxonomy.
	 *
	 * @param string $taxonomy Taxonomy slug.
	 * @param string $lang Ngôn ngữ.
	 * @param int $page Trang.
	 * @param int $per_page Số lượng term/page.
	 * @return array Danh sách terms.
	 */
	public function get_all_terms( string $taxonomy, string $lang = '', int $page = 1, int $per_page = 10 ): array {
		$requested_lang = trim( $lang );
		$lang = $this->polylang->normalize_language( $requested_lang );
		if ( '' !== $requested_lang && '' === $lang ) {
			return [
				'error'   => 'headless_invalid_language',
				'message' => 'Ngôn ngữ được yêu cầu không hợp lệ hoặc Polylang chưa hoạt động.',
				'status'  => 400,
			];
		}

		$page     = max( 1, $page );
		$per_page = max( 1, $per_page );
		$args = [
			'taxonomy'     => $taxonomy,
			'hide_empty'   => false,
			'number'       => $per_page,
			'offset'       => ( $page - 1 ) * $per_page,
			'orderby'      => 'count',
			'order'        => 'DESC',
		];

		if ( '' !== $lang && $this->polylang->is_active() ) {
			$args['lang'] = $lang;
		}

		$terms = get_terms( $args );

		if ( is_wp_error( $terms ) ) {
			return [
				'error' => 'headless_terms_error',
				'message' => $terms->get_error_message(),
				'status' => 500,
			];
		}

		$count_args = [
			'taxonomy'   => $taxonomy,
			'hide_empty' => false,
		];
		if ( '' !== $lang && $this->polylang->is_active() ) {
			$count_args['lang'] = $lang;
		}
		$total_terms = wp_count_terms( $count_args );
		if ( is_wp_error( $total_terms ) ) {
			return [
				'error'   => 'headless_terms_error',
				'message' => $total_terms->get_error_message(),
				'status'  => 500,
			];
		}
		$total_pages = (int) ceil( $total_terms / $per_page );

		return [
			'terms' => array_map( [ $this->term_normalizer, 'from_term' ], $terms ),
			'pagination' => [
				'page' => (int) $page,
				'per_page' => $per_page,
				'total' => $total_terms,
				'total_pages' => $total_pages,
			],
		];
	}
}
