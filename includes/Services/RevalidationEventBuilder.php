<?php
namespace TLU_Headless_API\Services;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use TLU_Headless_API\Normalizers\MediaNormalizer;
use TLU_Headless_API\Integrations\PolylangIntegration;

/**
 * Xây dựng webhook event payload.
 */
final class RevalidationEventBuilder {

	private MediaNormalizer      $media;
	private PolylangIntegration  $polylang;

	public function __construct() {
		$this->media     = new MediaNormalizer();
		$this->polylang  = new PolylangIntegration();
	}

	/**
	 * Xây dựng content event.
	 *
	 * @param string $event Event type: content.created|updated|published|unpublished|trashed|restored|deleted
	 * @param \WP_Post $post Post object.
	 * @param array $context Additional context: ['causes' => [...], 'previous_status' => '...', ...]
	 * @return array|false Event payload hoặc false nếu không thể xây dựng.
	 */
	public function build_content_event( string $event, \WP_Post $post, array $context = [] ): array {
		if ( ! $this->is_content_event_allowed( $event ) ) {
			return false;
		}

		if ( $this->is_internal_post_type( $post->post_type ) ) {
			return false;
		}

		$event_id = $this->generate_event_id();
		$timestamp = time();

		// Xác định language
		$language = '';
		if ( $this->polylang->is_active() && method_exists( $this->polylang, 'get_post_language' ) ) {
			$language = $this->polylang->get_post_language( $post->ID ) ?: '';
		}

		// Xây dựng paths và tags
		$paths = $this->build_content_paths( $post, $event, $context );
		$tags = $this->build_content_tags( $post, $language );

		// Xây dựng payload
		return [
			'version'       => 1,
			'event_id'      => $event_id,
			'event'         => $event,
			'occurred_at'   => $timestamp,
			'schema'        => \TLU_HEADLESS_API_SCHEMA_VERSION,
			'site'          => [
				'home'     => esc_url_raw( home_url() ),
				'frontend' => \TLU_Headless_API\Config::frontend_url(),
			],
			'entity'        => [
				'type'        => 'post',
				'id'          => (int) $post->ID,
				'subtype'     => $post->post_type,
				'status'      => $post->post_status,
				'previous_status' => $context['previous_status'] ?? '',
				'language'    => $language,
			],
			'invalidate'    => [
				'paths' => $paths,
				'tags'  => $tags,
			],
			'context'       => [
				'causes' => $context['causes'] ?? [],
			],
		];
	}

	/**
	 * Xây dựng term event.
	 *
	 * @param string $event Event type.
	 * @param int $term_id Term ID.
	 * @param string $taxonomy Taxonomy slug.
	 * @param array $context Context.
	 * @return array|false
	 */
	public function build_term_event( string $event, int $term_id, string $taxonomy, array $context = [] ): array {
		if ( ! $this->is_term_event_allowed( $event ) ) {
			return false;
		}

		$term = get_term( $term_id, $taxonomy );
		if ( ! $term instanceof \WP_Term ) {
			return false;
		}

		if ( ! $this->is_public_taxonomy( $taxonomy ) ) {
			return false;
		}

		$event_id = $this->generate_event_id();
		$timestamp = time();

		// Xác định language
		$language = '';
		if ( $this->polylang->is_active() && method_exists( $this->polylang, 'get_term_language' ) ) {
			$language = $this->polylang->get_term_language( $term_id ) ?: '';
		}

		// Xây dựng paths và tags
		$paths = $this->build_term_paths( $term, $event, $context );
		$tags = $this->build_term_tags( $term, $taxonomy, $language );

		return [
			'version'       => 1,
			'event_id'      => $event_id,
			'event'         => $event,
			'occurred_at'   => $timestamp,
			'schema'        => \TLU_HEADLESS_API_SCHEMA_VERSION,
			'site'          => [
				'home'     => esc_url_raw( home_url() ),
				'frontend' => \TLU_Headless_API\Config::frontend_url(),
			],
			'entity'        => [
				'type'    => 'term',
				'id'      => $term_id,
				'subtype' => $taxonomy,
				'status'  => 'public',
				'language' => $language,
			],
			'invalidate'    => [
				'paths' => $paths,
				'tags'  => $tags,
			],
			'context'       => [
				'causes' => $context['causes'] ?? [],
			],
		];
	}

	/**
	 * Xây dựng menu event.
	 *
	 * @param string $event Event type.
	 * @param int $menu_id Menu term ID.
	 * @param array $context Context.
	 * @return array|false
	 */
	public function build_menu_event( string $event, int $menu_id, array $context = [] ): array {
		if ( ! $this->is_menu_event_allowed( $event ) ) {
			return false;
		}

		$event_id = $this->generate_event_id();
		$timestamp = time();

		// Menu không có language cụ thể, dùng tag global
		return [
			'version'       => 1,
			'event_id'      => $event_id,
			'event'         => $event,
			'occurred_at'   => $timestamp,
			'schema'        => \TLU_HEADLESS_API_SCHEMA_VERSION,
			'site'          => [
				'home'     => esc_url_raw( home_url() ),
				'frontend' => \TLU_Headless_API\Config::frontend_url(),
			],
			'entity'        => [
				'type' => 'menu',
				'id'   => $menu_id,
			],
			'invalidate'    => [
				'paths' => [],
				'tags'  => [
					'wp:menu:' . $menu_id,
					'wp:global:menus',
				],
			],
			'context'       => [
				'causes' => $context['causes'] ?? [],
			],
		];
	}

	/**
	 * Xây dựng options event.
	 *
	 * @param array $changed_keys Keys of changed options.
	 * @return array|false
	 */
	public function build_options_event( array $changed_keys = [] ): array {
		if ( empty( $changed_keys ) ) {
			return false;
		}

		// Lọc chỉ allowlist options
		$allowed_keys = $this->get_allowed_option_keys();
		$filtered_keys = array_intersect( $changed_keys, $allowed_keys );
		if ( empty( $filtered_keys ) ) {
			return false;
		}

		$event_id = $this->generate_event_id();
		$timestamp = time();

		return [
			'version'       => 1,
			'event_id'      => $event_id,
			'event'         => 'options.updated',
			'occurred_at'   => $timestamp,
			'schema'        => \TLU_HEADLESS_API_SCHEMA_VERSION,
			'site'          => [
				'home'     => esc_url_raw( home_url() ),
				'frontend' => \TLU_Headless_API\Config::frontend_url(),
			],
			'entity'        => [
				'type' => 'options',
			],
			'invalidate'    => [
				'paths' => [],
				'tags'  => [
					'wp:global:options',
				],
			],
			'context'       => [
				'causes' => ['options'],
				'changed_keys' => $filtered_keys,
			],
		];
	}

	/**
	 * Xây dựng test event.
	 *
	 * @return array
	 */
	public function build_test_event(): array {
		return [
			'version'       => 1,
			'event_id'      => $this->generate_event_id(),
			'event'         => 'revalidation.test',
			'occurred_at'   => time(),
			'schema'        => \TLU_HEADLESS_API_SCHEMA_VERSION,
			'site'          => [
				'home'     => esc_url_raw( home_url() ),
				'frontend' => \TLU_Headless_API\Config::frontend_url(),
			],
			'entity'        => [
				'type' => 'system',
			],
			'invalidate'    => [
				'paths' => [],
				'tags'  => [
					'wp:global:options',
					'wp:global:menus',
				],
			],
			'context'       => [
				'causes' => ['test'],
			],
		];
	}

	// ── Private Helpers ────────────────────────────────────────────────────────

	private function generate_event_id(): string {
		try {
			return bin2hex( random_bytes( 16 ) );
		} catch ( \Throwable $e ) {
			return uniqid( 'evt_', true );
		}
	}

	private function is_content_event_allowed( string $event ): bool {
		$allowed = [
			'content.created',
			'content.updated',
			'content.published',
			'content.unpublished',
			'content.trashed',
			'content.restored',
			'content.deleted',
		];
		return in_array( $event, $allowed, true );
	}

	private function is_term_event_allowed( string $event ): bool {
		$allowed = [
			'term.created',
			'term.updated',
			'term.deleted',
			'term.relationships_updated',
		];
		return in_array( $event, $allowed, true );
	}

	private function is_menu_event_allowed( string $event ): bool {
		return in_array( $event, [ 'menu.updated', 'menu.deleted' ], true );
	}

	private function is_internal_post_type( string $post_type ): bool {
		$internal = [
			'revision',
			'nav_menu_item',
			'customize_changeset',
			'oembed_cache',
			'user_request',
			'wp_global_styles',
			'wp_navigation',
		];
		return in_array( $post_type, $internal, true );
	}

	private function is_public_taxonomy( string $taxonomy ): bool {
		$obj = get_taxonomy( $taxonomy );
		if ( ! $obj ) {
			return false;
		}
		return ! empty( $obj->public ) || ! empty( $obj->publicly_queryable );
	}

	private function get_allowed_option_keys(): array {
		$allowed = [
			// Site identity
			'site_title',
			'site_description',
			// Menus
			'menu_locations',
			// Public headless options
			'frontend_url',
		];
		return apply_filters( 'headless_api_revalidation_option_names', $allowed );
	}

	private function build_content_paths( \WP_Post $post, string $event, array $context = [] ): array {
		$paths = [];

		// Lấy current path
		$current_path = $this->get_post_public_path( $post );
		if ( $current_path ) {
			$paths[] = $current_path;
		}

		// Thêm previous path nếu có
		if ( isset( $context['previous_path'] ) && $context['previous_path'] !== $current_path ) {
			$paths[] = $context['previous_path'];
		}

		// Thêm archive path nếu publish
		if ( 'content.published' === $event || 'content.updated' === $event ) {
			$archive = $this->get_post_type_archive_path( $post->post_type );
			if ( $archive ) {
				$paths[] = $archive;
			}
		}

		// Root path cho content ở home
		if ( 'content.published' === $event ) {
			$paths[] = '/';
		}

		return $this->sanitize_paths( $paths );
	}

	private function build_content_tags( \WP_Post $post, string $language ): array {
		$tags = [
			'wp:post:' . $post->ID,
			'wp:type:' . $post->post_type,
		];

		if ( $language ) {
			$tags[] = 'wp:lang:' . $language;
		}

		// Author tag
		if ( $post->post_author ) {
			$tags[] = 'wp:author:' . $post->post_author;
		}

		// Term tags (chỉ tags tổng quát, không list từng term để tránh quá nhiều tags)
		$taxonomies = get_object_taxonomies( $post->post_type, 'names' );
		foreach ( $taxonomies as $tax ) {
			$tags[] = 'wp:taxonomy:' . $tax;
		}

		// Search/sitemap tags
		$tags[] = 'wp:global:search';
		$tags[] = 'wp:global:sitemap';

		return array_values( array_unique( $tags ) );
	}

	private function build_term_paths( \WP_Term $term, string $event, array $context = [] ): array {
		$paths = [];

		// Term archive path
		$term_link = get_term_link( $term );
		if ( ! is_wp_error( $term_link ) ) {
			$paths[] = $this->normalize_url_to_path( $term_link );
		}

		// Taxonomy archive
		$tax_archive = get_post_type_archive_link( $term->taxonomy );
		if ( $tax_archive ) {
			$paths[] = $this->normalize_url_to_path( $tax_archive );
		}

		return $this->sanitize_paths( $paths );
	}

	private function build_term_tags( \WP_Term $term, string $taxonomy, string $language ): array {
		$tags = [
			'wp:term:' . $taxonomy . ':' . $term->term_id,
			'wp:taxonomy:' . $taxonomy,
		];

		if ( $language ) {
			$tags[] = 'wp:lang:' . $language;
		}

		return array_values( array_unique( $tags ) );
	}

	private function get_post_public_path( \WP_Post $post ): string {
		if ( ! in_array( $post->post_status, [ 'publish', 'future' ], true ) ) {
			return '';
		}

		$url = get_permalink( $post->ID );
		if ( ! $url ) {
			return '';
		}

		return $this->normalize_url_to_path( $url );
	}

	private function get_post_type_archive_path( string $post_type ): string {
		$obj = get_post_type_object( $post_type );
		if ( ! $obj || empty( $obj->has_archive ) ) {
			return '';
		}

		$archive = get_post_type_archive_link( $post_type );
		if ( ! $archive ) {
			return '';
		}

		return $this->normalize_url_to_path( $archive );
	}

	private function normalize_url_to_path( string $url ): string {
		$parsed = wp_parse_url( $url );
		if ( ! is_array( $parsed ) || empty( $parsed['path'] ) ) {
			return '/';
		}

		$path = $parsed['path'];
		if ( '' === $path || '/' === $path ) {
			return '/';
		}

		// Chuẩn hóa: loại query, fragment, double slashes
		$path = '/' . ltrim( $path, '/' );
		$path = strtok( $path, '?' ); // Loại query
		$path = strtok( $path, '#' ); // Loại fragment

		return $path;
	}

	private function sanitize_paths( array $paths ): array {
		$max_paths = apply_filters( 'headless_api_revalidation_max_paths', 100 );
		$max_paths = min( $max_paths, 200 ); // Hard ceiling

		$sanitized = [];
		foreach ( $paths as $p ) {
			$p = trim( $p );
			if ( '' === $p ) {
				continue;
			}

			// Bắt đầu bằng /
			if ( '/' !== $p[0] ) {
				$p = '/' . $p;
			}

			// Loại query, fragment
			$p = strtok( $p, '?' );
			$p = strtok( $p, '#' );

			// Kiểm tra độ dài
			if ( strlen( $p ) > 2048 ) {
				continue;
			}

			// Tránh directory traversal
			if ( strpos( $p, '..' ) !== false ) {
				continue;
			}

			// Không cho null byte
			if ( strpos( $p, "\x00" ) !== false ) {
				continue;
			}

			$sanitized[] = $p;
		}

		$sanitized = array_values( array_unique( $sanitized ) );
		$sanitized = array_slice( $sanitized, 0, $max_paths );

		return $sanitized;
	}
}