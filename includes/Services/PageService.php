<?php

namespace TLU_Headless_API\Services;

if (! defined('ABSPATH')) {
	exit;
}

use TLU_Headless_API\Helpers\ContentVisibility;
use TLU_Headless_API\Normalizers\PageNormalizer;
use TLU_Headless_API\Integrations\PolylangIntegration;
use TLU_Headless_API\Cache\TransientCache;
use TLU_Headless_API\Services\ContentResolver;

/**
 * Xử lý logic nghiệp vụ cho page/post endpoint.
 *
 * Controller chỉ validate tham số và gọi Service.
 * Mọi logic tìm kiếm, lọc ngôn ngữ, chuẩn hóa và cache đều nằm ở đây.
 */
class PageService
{

	private PageNormalizer      $normalizer;
	private PolylangIntegration $polylang;
	private TransientCache      $cache;
	private ContentResolver     $resolver;

	public function __construct(
		?PageNormalizer      $normalizer = null,
		?PolylangIntegration $polylang   = null,
		?TransientCache      $cache      = null,
		?ContentResolver     $resolver   = null
	) {
		$this->normalizer = $normalizer ?? new PageNormalizer();
		$this->polylang   = $polylang   ?? new PolylangIntegration();
		$this->cache      = $cache      ?? TransientCache::from_config();
		$this->resolver   = $resolver   ?? new ContentResolver();
	}
	/**
	 * Tìm post theo slug và post type.
	 * Không cache ở đây — cache ở tầng get_page / get_page_blocks.
	 *
	 * @param  string $slug      post_name của post.
	 * @param  string $post_type Loại post. Dùng 'any' cho tất cả loại public.
	 * @param  string $lang      Mã ngôn ngữ Polylang ('' = ngôn ngữ hiện tại).
	 * @return \WP_Post|null     Null khi không tìm thấy.
	 */
	public function find_post(
		string $slug,
		string $post_type = 'page',
		string $lang = ''
	) {
		$post = $this->resolver->resolve(
			[
				'slug'      => $slug,
				'post_type' => $post_type,
				'lang'      => $lang,
			]
		);

		if (is_wp_error($post)) {
			return $post;
		}

		if (
			! $post instanceof \WP_Post
			|| ! ContentVisibility::is_post_public($post)
		) {
			return new \WP_Error(
				'headless_content_not_found',
				'Không tìm thấy nội dung công khai.',
				['status' => 404]
			);
		}

		return $post;
	}
	/**
	 * Lấy response page đầy đủ (dùng cho endpoint /page).
	 * Kết quả được cache theo slug + post_type + lang.
	 *
	 * @param  string $slug
	 * @param  string $post_type
	 * @param  string $lang
	 * @return array|null  Null khi không tìm thấy post.
	 */
	public function get_page(string $slug, string $post_type = 'page', string $lang = ''): array|\WP_Error
	{
		$cache_key = $this->cache->make_key('page', $slug, $post_type, $lang);
		$cached    = $this->cache->get($cache_key);
		if (null !== $cached) {
			return $cached;
		}

		$post = $this->find_post($slug, $post_type, $lang);
		if (is_wp_error($post)) {
			return $post;
		}

		if (! $post) {
			return new \WP_Error('headless_content_not_found', 'Không tìm thấy nội dung công khai.', ['status' => 404]);
		}

		$data                 = $this->normalizer->from_post($post);
		$data['lang']         = $lang;
		$data['translations'] = $this->polylang->get_post_translations($post->ID);
		$this->cache->set($cache_key, $data);
		return $data;
	}

	/**
	 * Lấy danh sách post/CPT công khai kèm ACF đã chuẩn hóa.
	 *
	 * @return array|\WP_Error
	 */
	public function get_pages(
		string $post_type,
		string $lang = '',
		int $page = 1,
		int $per_page = 10
	): array|\WP_Error {
		$requested_lang = trim( $lang );
		$lang           = $this->polylang->normalize_language( $requested_lang );

		if ( '' !== $requested_lang && '' === $lang ) {
			return new \WP_Error(
				'headless_invalid_language',
				'Ngôn ngữ được yêu cầu không hợp lệ hoặc Polylang chưa hoạt động.',
				[ 'status' => 400 ]
			);
		}

		if ( '' === $lang && $this->polylang->is_active() ) {
			$lang = $this->polylang->get_current_language();
			if ( '' === $lang ) {
				$lang = $this->polylang->get_default_language();
			}
		}

		$page      = max( 1, $page );
		$per_page = max( 1, min( 100, $per_page ) );
		$cache_key = $this->cache->make_key(
			'pages',
			$post_type,
			$lang,
			(string) $page,
			(string) $per_page
		);
		$cached = $this->cache->get( $cache_key );

		if ( null !== $cached ) {
			return $cached;
		}

		$args = [
			'post_type'           => $post_type,
			'post_status'         => 'publish',
			'has_password'        => false,
			'paged'               => $page,
			'posts_per_page'      => $per_page,
			'orderby'             => 'date',
			'order'               => 'DESC',
			'ignore_sticky_posts' => true,
		];

		if ( '' !== $lang && $this->polylang->is_active() ) {
			$args['lang'] = $lang;
		}

		$query = new \WP_Query( $args );
		$posts = array_values(
			array_filter(
				$query->posts,
				static fn( $post ): bool => ContentVisibility::is_post_public( $post )
			)
		);

		$data = [
			'items'      => array_map( [ $this->normalizer, 'from_post_summary' ], $posts ),
			'pagination' => [
				'page'        => $page,
				'per_page'    => $per_page,
				'total'       => (int) $query->found_posts,
				'total_pages' => (int) $query->max_num_pages,
			],
			'post_type'  => $post_type,
			'lang'       => $lang,
		];

		$this->cache->set( $cache_key, $data );

		return $data;
	}
	/**
	 * Lấy response trang kèm ACF block data (dùng cho endpoint /page-blocks).
	 * Kết quả được cache theo slug + post_type + lang.
	 *
	 * @param  string $slug
	 * @param  string $post_type
	 * @param  string $lang
	 * @return array|null
	 */
	public function get_page_blocks(string $slug, string $post_type = 'page', string $lang = ''): array|\WP_Error
	{
		$cache_key = $this->cache->make_key('blocks', $slug, $post_type, $lang);
		$cached    = $this->cache->get($cache_key);
		if (null !== $cached) {
			return $cached;
		}

		$post = $this->find_post($slug, $post_type, $lang);
		if (is_wp_error($post)) {
			return $post;
		}

		if (! $post) {
			return new \WP_Error('headless_content_not_found', 'Không tìm thấy nội dung công khai.', ['status' => 404]);
		}
		$normalized   = $this->normalizer->from_post_with_blocks($post);
		$blocks_map   = $normalized['blocks'] ?? [];
		$blocks_field = (string) (array_key_first($blocks_map) ?? '');
		$data         = [
			'id'           => $post->ID,
			'slug'         => $post->post_name,
			'title'        => get_the_title($post),
			'type'         => $post->post_type,
			'lang'         => $lang,
			'blocks_field' => $blocks_field,
			'blocks'       => '' !== $blocks_field ? $blocks_map[$blocks_field] : [],
		];
		$this->cache->set($cache_key, $data);
		return $data;
	}
}
