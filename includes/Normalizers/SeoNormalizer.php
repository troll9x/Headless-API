<?php
namespace TLU_Headless_API\Normalizers;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use TLU_Headless_API\Contracts\NormalizerInterface;
use TLU_Headless_API\Integrations\RankMathIntegration;
use TLU_Headless_API\Integrations\PolylangIntegration;
use TLU_Headless_API\Services\UrlTransformer;

/**
 * Xây dựng cấu trúc SEO chuẩn cho một WP_Post.
 *
 * Ưu tiên Rank Math khi đang hoạt động; fallback về WordPress core fields.
 * Khi Rank Math hoạt động nhưng post không có meta riêng, fallback từng field về WP core.
 * Ưu tiên Polylang cho hreflang; trả về [] khi Polylang không hoạt động.
 *
 * Cấu trúc đầu ra:
 * {
 *   title, description, canonical, robots,
 *   open_graph: { title, description, image, type },
 *   twitter:    { title, description, image, card_type },
 *   schema_json,
 *   hreflang: [ { lang, url }, ... ]
 * }
 *
 * Bất biến: robots luôn là array (có thể rỗng).
 */
class SeoNormalizer implements NormalizerInterface {

	private RankMathIntegration $rank_math;
	private PolylangIntegration $polylang;
	private MediaNormalizer     $media;
	private UrlTransformer      $transformer;

	public function __construct(
		?RankMathIntegration $rank_math = null,
		?PolylangIntegration $polylang  = null,
		?MediaNormalizer     $media     = null,
		?UrlTransformer     $transformer = null
	) {
		$this->transformer = $transformer ?? new UrlTransformer();
		$this->rank_math = $rank_math ?? new RankMathIntegration();
		$this->polylang  = $polylang  ?? new PolylangIntegration();
		$this->media     = $media     ?? new MediaNormalizer( $this->transformer );
	}

	/** Chấp nhận WP_Post; trả về cấu trúc SEO đầy đủ. */
	public function normalize( $value ): array {
		if ( ! ( $value instanceof \WP_Post ) ) {
			return $this->empty();
		}
		return $this->from_post( $value );
	}

	public function from_post( \WP_Post $post ): array {
		if ( $this->rank_math->is_active() ) {
			return $this->from_rank_math( $post );
		}
		return $this->from_core( $post );
	}

	/** Shape rỗng — trả về khi không có WP_Post hợp lệ. */
	public function empty(): array {
		return [
			'title'       => '',
			'description' => '',
			'canonical'   => '',
			'robots'      => [],
			'open_graph'  => [ 'title' => '', 'description' => '', 'image' => null, 'type' => '' ],
			'twitter'     => [ 'title' => '', 'description' => '', 'image' => null, 'card_type' => '' ],
			'schema_json' => '',
			'hreflang'    => [],
		];
	}

	// ── Private ───────────────────────────────────────────────────────────────

	/**
	 * Xây dựng SEO từ Rank Math meta fields.
	 * Fallback về WP core khi từng field không có meta riêng.
	 * Mọi biến Rank Math (%title%, %sitename%…) đều được expand.
	 */
	private function from_rank_math( \WP_Post $post ): array {
		$rm = $this->rank_math;

		// ── Core values dùng làm fallback ─────────────────────────────────────
		$core_title       = get_the_title( $post );
		$core_description = wp_strip_all_tags( get_the_excerpt( $post ) );
		$core_canonical   = $this->transformer->transform_navigation_url( get_permalink( $post ) );
		$thumb            = $this->media->normalize( get_post_thumbnail_id( $post->ID ) ?: null );

		// ── Title & Description ───────────────────────────────────────────────
		$rm_title = $rm->get_title( $post );
		$title    = $rm->expand_variables(
			'' !== $rm_title ? $rm_title : $core_title,
			$post
		);

		$rm_desc     = $rm->get_description( $post );
		$description = $rm->expand_variables(
			'' !== $rm_desc ? $rm_desc : $core_description,
			$post
		);

		// ── Canonical ─────────────────────────────────────────────────────────
		$rm_canonical = $rm->get_canonical( $post );
		$canonical    = '' !== $rm_canonical
			? $this->transformer->transform_navigation_url( $rm_canonical )
			: $core_canonical;

		// ── Open Graph ────────────────────────────────────────────────────────
		$og          = $rm->get_open_graph( $post );
		$og_title    = $rm->expand_variables( '' !== $og['title']       ? $og['title']       : $title,       $post );
		$og_desc     = $rm->expand_variables( '' !== $og['description'] ? $og['description'] : $description, $post );
		$og_image    = $this->resolve_image( '' !== $og['image'] ? $og['image'] : null ) ?? $thumb;
		$og_type     = '' !== $og['type']       ? $og['type']       : 'website';

		// ── Twitter ───────────────────────────────────────────────────────────
		$tw           = $rm->get_twitter( $post );
		$tw_title     = $rm->expand_variables( '' !== $tw['title']       ? $tw['title']       : $title,       $post );
		$tw_desc      = $rm->expand_variables( '' !== $tw['description'] ? $tw['description'] : $description, $post );
		$tw_image     = $this->resolve_image( '' !== $tw['image'] ? $tw['image'] : null ) ?? $thumb;
		$tw_card_type = '' !== $tw['card_type'] ? $tw['card_type'] : 'summary_large_image';

		$raw_schema = $rm->get_schema( $post );
		$normalized_schema = $this->normalize_schema( $raw_schema, $post );

		return [
			'title'       => sanitize_text_field( $title ),
			'description' => sanitize_text_field( $description ),
			'canonical'   => $canonical,
			'robots'      => $this->normalize_robots( $rm->get_robots( $post ) ),
			'source'      => 'rank_math',
			'open_graph'  => [
				'title'       => sanitize_text_field( $og_title ),
				'description' => sanitize_text_field( $og_desc ),
				'image'       => $og_image,
				'type'        => sanitize_text_field( $og_type ),
			],
			'twitter'     => [
				'title'       => sanitize_text_field( $tw_title ),
				'description' => sanitize_text_field( $tw_desc ),
				'image'       => $tw_image,
				'card_type'   => sanitize_text_field( $tw_card_type ),
			],
			'schema'      => $normalized_schema,
			'schema_json' => '',
			'breadcrumbs' => [],
			'hreflang'    => $this->build_hreflang( $post->ID ),
		];
	}

	/** Fallback: xây dựng SEO từ WordPress core fields khi Rank Math không hoạt động. */
	private function from_core( \WP_Post $post ): array {
		$title       = get_the_title( $post );
		$description = wp_strip_all_tags( get_the_excerpt( $post ) );
		$canonical   = $this->transformer->transform_navigation_url( get_permalink( $post ) );
		$thumb       = $this->media->normalize( get_post_thumbnail_id( $post->ID ) ?: null );

		return [
			'title'       => sanitize_text_field( $title ),
			'description' => sanitize_text_field( $description ),
			'canonical'   => $canonical,
			'robots'      => [],
			'source'      => 'wordpress',
			'open_graph'  => [
				'title'       => sanitize_text_field( $title ),
				'description' => sanitize_text_field( $description ),
				'image'       => $thumb,
				'type'        => 'website',
			],
			'twitter'     => [
				'title'       => sanitize_text_field( $title ),
				'description' => sanitize_text_field( $description ),
				'image'       => $thumb,
				'card_type'   => 'summary_large_image',
			],
			'schema'      => [],
			'schema_json' => '',
			'breadcrumbs' => [],
			'hreflang'    => $this->build_hreflang( $post->ID ),
		];
	}

	/**
	 * Phân giải giá trị ảnh (ID, URL string, hoặc array) thành MediaNormalizer shape.
	 * Rank Math đôi khi trả URL string thay vì attachment ID.
	 */
	private function resolve_image( $image ): ?array {
		if ( empty( $image ) ) {
			return null;
		}
		if ( is_numeric( $image ) ) {
			$normalized = $this->media->normalize( (int) $image );
			return '' !== $normalized['url'] ? $normalized : null;
		}
		if ( is_string( $image ) ) {
			$shape = $this->media->empty_with_url( $image );
			return '' !== $shape['url'] ? $shape : null;
		}
		if ( is_array( $image ) ) {
			$normalized = $this->media->normalize( $image );
			return '' !== $normalized['url'] ? $normalized : null;
		}
		return null;
	}

	private function normalize_robots( array $robots ): array {
		$raw = [];
		$index = true;
		$follow = true;
		$archive = true;
		$image_index = true;
		$snippet = true;

		foreach ( $robots as $directive ) {
			$directive = strtolower( trim( (string) $directive ) );
			if ( '' === $directive || preg_match( '/[^a-z0-9_:\-,]/', $directive ) ) {
				continue;
			}
			if ( ! in_array( $directive, $raw, true ) ) {
				$raw[] = $directive;
			}
			if ( 'noindex' === $directive ) {
				$index = false;
			} elseif ( 'index' === $directive && $index ) {
				$index = true;
			} elseif ( 'nofollow' === $directive ) {
				$follow = false;
			} elseif ( 'follow' === $directive && $follow ) {
				$follow = true;
			} elseif ( 'noarchive' === $directive ) {
				$archive = false;
			} elseif ( 'noimageindex' === $directive ) {
				$image_index = false;
			} elseif ( 'nosnippet' === $directive ) {
				$snippet = false;
			}
		}

		return apply_filters(
			'headless_api_seo_robots',
			[
				'index'       => $index,
				'follow'      => $follow,
				'archive'     => $archive,
				'image_index' => $image_index,
				'snippet'     => $snippet,
				'raw'         => $raw,
			],
			$this
		);
	}

	private function normalize_schema( array $schema, \WP_Post $post ): array {
		if ( empty( $schema ) ) {
			return [];
		}

		$nodes = isset( $schema['@graph'] ) && is_array( $schema['@graph'] ) ? $schema['@graph'] : $schema;

		if ( isset( $nodes['@type'] ) ) {
			$nodes = [ $nodes ];
		}

		if ( ! is_array( $nodes ) ) {
			return [];
		}

		$result = [];
		foreach ( $nodes as $node ) {
			if ( is_array( $node ) ) {
				$result[] = $this->normalize_schema_node( $node, 0 );
			}
		}

		return apply_filters( 'headless_api_seo_schema', $result, $post, $this );
	}

	private function normalize_schema_node( array $node, int $depth ): array {
		if ( $depth > 8 ) {
			return [];
		}

		foreach ( $node as $key => $value ) {
			if ( is_object( $value ) || is_resource( $value ) ) {
				unset( $node[ $key ] );
				continue;
			}

			if ( is_array( $value ) ) {
				$node[ $key ] = $this->normalize_schema_node( $value, $depth + 1 );
				continue;
			}

			if ( ! is_string( $value ) ) {
				continue;
			}

			if ( strlen( $value ) > 5000 ) {
				$node[ $key ] = substr( $value, 0, 5000 );
				continue;
			}

			if ( ! preg_match( '#^(https?:)?//#i', $value ) && ! str_starts_with( $value, '/' ) && ! preg_match( '#^[a-z][a-z0-9+\-.]*:#i', $value ) ) {
				continue;
			}

			if ( preg_match( '#^(javascript|data|vbscript):#i', $value ) ) {
				unset( $node[ $key ] );
				continue;
			}

			$asset_keys = [ 'contentUrl', 'thumbnailUrl', 'embedUrl', 'logo', 'image', 'primaryImageOfPage' ];
			if ( in_array( $key, $asset_keys, true ) ) {
				$node[ $key ] = $this->transformer->transform_asset_url( $value );
			} else {
				$node[ $key ] = $this->transformer->transform_navigation_url( $value );
			}
		}

		return $node;
	}

	/**
	 * Xây dựng mảng hreflang từ Polylang translations.
	 * Bao gồm cả bài hiện tại và tất cả bản dịch.
	 */
	private function build_hreflang( int $post_id ): array {
		if ( ! $this->polylang->is_active() ) {
			return [];
		}

		$current_lang = $this->polylang->get_post_language( $post_id );
		$translations = $this->polylang->get_post_translations( $post_id );
		$result       = [];

		// 1. Add current post
		$self_post = get_post( $post_id );
		if ( $self_post && '' !== $current_lang ) {
			$result[] = [
				'lang' => $current_lang,
				'url'  => $this->transformer->transform_navigation_url( get_permalink( $self_post ) ),
			];
		}

		// 2. Add translations
		foreach ( $translations as $trans ) {
			$lang = $trans['language'];
			$url  = $trans['url'];

			// Avoid duplicate current language
			if ( $lang === $current_lang ) {
				continue;
			}

			$result[] = [
				'lang' => $lang,
				'url'  => $this->transformer->transform_navigation_url( $url ),
			];
		}

		// 3. Deduplicate URLs and ensure deterministic order by lang slug
		$unique_results = [];
		$seen_urls = [];
		foreach ( $result as $item ) {
			if ( ! in_array( $item['url'], $seen_urls, true ) ) {
				$unique_results[] = $item;
				$seen_urls[] = $item['url'];
			}
		}

		usort( $unique_results, fn( $a, $b ) => strcmp( $a['lang'], $b['lang'] ) );

		return apply_filters( 'headless_api_hreflang', $unique_results, $post_id, $this );
	}
}
