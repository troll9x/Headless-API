<?php
namespace TLU_Headless_API\Services;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use TLU_Headless_API\Normalizers\ArchiveNormalizer;
use TLU_Headless_API\Integrations\PolylangIntegration;
use TLU_Headless_API\Cache\TransientCache;

/**
 * Xử lý logic nghiệp vụ cho archive endpoint.
 *
 * Controller chỉ validate tham số và gọi Service.
 * Mọi logic resolve archive, lấy posts, chuẩn hóa và cache nằm ở đây.
 */
class ArchiveService {

	private ArchiveNormalizer $archive_normalizer;
	private PolylangIntegration $polylang;
	private TransientCache $cache;
	private ArchiveResolver $resolver;

	public function __construct(
		?ArchiveNormalizer $archive_normalizer = null,
		?PolylangIntegration $polylang = null,
		?TransientCache $cache = null,
		?ArchiveResolver $resolver = null
	) {
		$this->archive_normalizer = $archive_normalizer ?? new ArchiveNormalizer();
		$this->polylang = $polylang ?? new PolylangIntegration();
		$this->cache = $cache ?? TransientCache::from_config();
		$this->resolver = $resolver ?? new ArchiveResolver();
	}

	/**
	 * Resolve archive context.
	 *
	 * @param array $query Query with one selector family.
	 * @return array|\WP_Error Archive context.
	 */
	public function resolve_archive( array $query ): array|\WP_Error {
		$lang = $this->polylang ? $this->polylang->normalize_language( $query['lang'] ?? '' ) : '';
		$query['lang'] = $lang;

		return $this->resolver->resolve( $query );
	}

	/**
	 * Lấy posts trong một archive.
	 *
	 * @param array $context Archive context.
	 * @param int $page Trang.
	 * @param int $per_page Số lượng post/page.
	 * @return array Danh sách posts.
	 */
	public function get_archive_posts( array $context, int $page = 1, int $per_page = 10 ): array {
		$args = [
			'paged'          => $page,
			'posts_per_page' => $per_page,
			'post_status'    => 'publish',
		];

		switch ( $context['type'] ) {
			case 'post_type':
				$args['post_type'] = $context['post_type'];
				break;

			case 'taxonomy':
				$taxonomy = $context['taxonomy']['name'] ?? '';
				$term = $context['term']['slug'] ?? '';
				if ( '' !== $taxonomy && '' !== $term ) {
					$args['tax_query'] = [
						[
							'taxonomy' => $taxonomy,
							'terms'    => [ $term ],
							'field'    => 'slug',
						],
					];
				}
				break;

			case 'author':
				$author_id = $context['author']['id'] ?? 0;
				if ( $author_id > 0 ) {
					$args['author'] = $author_id;
				}
				break;

			case 'date':
				$year = $context['date']['year'] ?? 0;
				$month = $context['date']['month'] ?? 0;
				$day = $context['date']['day'] ?? 0;

				if ( $year > 0 ) {
					$args['year'] = $year;
					if ( $month > 0 ) {
						$args['monthnum'] = $month;
						if ( $day > 0 ) {
							$args['day'] = $day;
						}
					}
				}
				break;
		}

		// Add language filter for Polylang
		if ( '' !== $context['language'] && $this->polylang->is_active() ) {
			$args['lang'] = $context['language'];
		}

		$posts = get_posts( $args );

		return array_map( [ $this->archive_normalizer, 'from_post' ], $posts );
	}

	/**
	 * Lấy archive response đầy đủ.
	 *
	 * @param array $query Query parameters.
	 * @param int $page Trang.
	 * @param int $per_page Số lượng post/page.
	 * @return array|\WP_Error Archive response.
	 */
	public function get_archive( array $query, int $page = 1, int $per_page = 10 ): array|\WP_Error {
		$cache_key = $this->cache->make_key(
			'archive',
			$query['type'] ?? 'unknown',
			$query['post_type'] ?? '',
			$query['taxonomy'] ?? '',
			$query['term'] ?? '',
			$query['author'] ?? '',
			$query['year'] ?? '',
			(string) $page,
			(string) $per_page
		);

		$cached = $this->cache->get( $cache_key );
		if ( null !== $cached ) {
			return $cached;
		}

		$context = $this->resolve_archive( $query );
		if ( is_wp_error( $context ) ) {
			return $context;
		}

		$posts = $this->get_archive_posts( $context, $page, $per_page );

		$response = array_merge( $context, [
			'posts' => $posts,
			'pagination' => [
				'page' => $page,
				'per_page' => $per_page,
				'total' => 0, // TODO: Calculate total posts
				'total_pages' => 0,
			],
		] );

		$this->cache->set( $cache_key, $response );

		return $response;
	}

	/**
	 * Lấy danh sách các post type có archive.
	 *
	 * @return array Danh sách post types.
	 */
	public function get_post_types_with_archive(): array {
		$post_types = get_post_types( [ 'public' => true, 'has_archive' => true ] );

		$result = [];
		foreach ( $post_types as $post_type ) {
			$obj = get_post_type_object( $post_type );
			if ( $obj ) {
				$result[] = [
					'name'  => $post_type,
					'label' => $obj->label,
					'archive' => get_post_type_archive_link( $post_type ),
				];
			}
		}

		return $result;
	}

	/**
	 * Lấy danh sách taxonomies có archive.
	 *
	 * @return array Danh sách taxonomies.
	 */
	public function get_taxonomies_with_archive(): array {
		$taxonomies = get_taxonomies( [ 'public' => true ] );

		$result = [];
		foreach ( $taxonomies as $taxonomy ) {
			$obj = get_taxonomy( $taxonomy );
			if ( $obj && $obj->show_in_rest ) {
				$result[] = [
					'name'  => $taxonomy,
					'label' => $obj->label,
					'hierarchical' => (bool) $obj->hierarchical,
					'archive' => false, // Taxonomy archives don't have a single archive page
				];
			}
		}

		return $result;
	}
}