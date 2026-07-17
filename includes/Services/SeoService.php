<?php

namespace TLU_Headless_API\Services;

use TLU_Headless_API\Cache\TransientCache;
use TLU_Headless_API\Helpers\ContentVisibility;
use TLU_Headless_API\Integrations\PolylangIntegration;
use TLU_Headless_API\Normalizers\SeoNormalizer;

if (! defined('ABSPATH')) {
	exit;
}

/**
 * Xử lý logic nghiệp vụ cho SEO endpoint.
 *
 * Controller chỉ validate tham số và gọi Service.
 * Mọi logic tìm kiếm post, xây dựng SEO data và cache nằm ở đây.
 */
class SeoService
{

	private SeoNormalizer $normalizer;

	private PolylangIntegration $polylang;

	private TransientCache $cache;

	private ContentResolver $resolver;

	public function __construct(
		?SeoNormalizer $normalizer = null,
		?PolylangIntegration $polylang = null,
		?TransientCache $cache = null,
		?ContentResolver $resolver = null
	) {
		$this->normalizer = $normalizer ?? new SeoNormalizer();
		$this->polylang   = $polylang ?? new PolylangIntegration();
		$this->cache      = $cache ?? TransientCache::from_config();
		$this->resolver   = $resolver ?? new ContentResolver();
	}

	/**
	 * Lấy SEO data của một post theo ID.
	 *
	 * @param int $id Post ID.
	 * @param string $lang Ngôn ngữ yêu cầu.
	 * @return array<string,mixed>|\WP_Error
	 */
	public function get_seo_by_id(int $id, string $lang = ''): array|\WP_Error
	{
		$post = $this->resolver->resolve(
			[
				'id'   => $id,
				'lang' => $lang,
			]
		);

		if (\is_wp_error($post)) {
			return $post;
		}

		$post = $this->ensure_public_post($post);

		if (\is_wp_error($post)) {
			return $post;
		}

		$cache_key = $this->cache->make_key(
			'seo',
			(string) $id,
			$lang
		);

		$cached = $this->cache->get($cache_key);

		if (null !== $cached) {
			return $cached;
		}

		$seo_data = $this->normalizer->from_post($post);
		$source = $seo_data['source'] ?? 'wordpress';

		$data = array_merge(
			[
				'id'     => $post->ID,
				'slug'   => $post->post_name,
				'type'   => $post->post_type,
				'lang'   => $lang,
				'source' => $source,
			],
			$seo_data
		);

		$this->cache->set($cache_key, $data);

		return $data;
	}

	/**
	 * Lấy SEO data theo slug, post type và ngôn ngữ.
	 *
	 * @return array<string,mixed>|\WP_Error
	 */
	public function get_seo(
		string $slug,
		string $post_type = 'page',
		string $lang = ''
	): array|\WP_Error {
		/*
		 * Resolve và kiểm tra visibility trước khi trả cache.
		 * Việc này tránh trả cache cũ nếu post đã chuyển sang private/draft.
		 */
		$post = $this->find_post(
			$slug,
			$post_type,
			$lang
		);

		if (\is_wp_error($post)) {
			return $post;
		}

		$cache_key = $this->cache->make_key(
			'seo',
			$slug,
			$post_type,
			$lang
		);

		$cached = $this->cache->get($cache_key);

		if (null !== $cached) {
			return $cached;
		}

		$seo_data = $this->normalizer->from_post($post);
		$source = $seo_data['source'] ?? 'wordpress';

		$data = array_merge(
			[
				'id'     => $post->ID,
				'slug'   => $post->post_name,
				'type'   => $post->post_type,
				'lang'   => $lang,
				'source' => $source,
			],
			$seo_data
		);

		$this->cache->set($cache_key, $data);

		return $data;
	}

	/**
	 * Tìm post công khai theo slug và post type.
	 *
	 * Tham số $lang được giữ để tương thích với public contract hiện tại.
	 * Việc resolve theo ngôn ngữ nâng cao thuộc Phase 4.
	 *
	 * @return \WP_Post|\WP_Error
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

		if (\is_wp_error($post)) {
			return $post;
		}

		return $this->ensure_public_post($post);
	}

	/**
	 * Defense-in-depth visibility boundary.
	 *
	 * ContentResolver đã kiểm tra visibility, nhưng SeoService vẫn kiểm tra
	 * lại để bảo vệ khi resolver bị mock hoặc được thay thế qua dependency
	 * injection.
	 *
	 * @param mixed $post Candidate post.
	 *
	 * @return \WP_Post|\WP_Error
	 */
	private function ensure_public_post($post)
	{
		if (
			! $post instanceof \WP_Post
			|| ! ContentVisibility::is_post_public($post)
		) {
			return new \WP_Error(
				'headless_content_not_found',
				'Không tìm thấy nội dung công khai.',
				[
					'status' => 404,
				]
			);
		}

		return $post;
	}
}
