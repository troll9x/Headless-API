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
		PageNormalizer      $normalizer = null,
		PolylangIntegration $polylang   = null,
		TransientCache      $cache      = null,
		ContentResolver     $resolver   = null
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
