<?php
/**
 * Content Resolver Service
 *
 * Resolves WordPress content by various selectors: ID, slug, path, URL.
 * Supports hierarchical post types and Permalink Manager integration.
 *
 * @package TLU_Headless_API
 */

namespace TLU_Headless_API\Services;

use TLU_Headless_API\Contracts\Service;
use TLU_Headless_API\Helpers\ContentVisibility;
use TLU_Headless_API\Integrations\PermalinkManagerIntegration;
use TLU_Headless_API\Integrations\PolylangIntegration;

/**
 * ContentResolver resolves posts by various selectors with security enforcement.
 */
final class ContentResolver implements Service {

	private ?PermalinkManagerIntegration $permalink_manager;
	private ?UrlTransformer $url_transformer;
	private ?PolylangIntegration $polylang;

	/**
	 * Constructor.
	 *
	 * @param PermalinkManagerIntegration|null $permalink_manager Optional Permalink Manager integration.
	 * @param UrlTransformer|null $url_transformer Optional URL transformer.
	 * @param PolylangIntegration|null $polylang Optional Polylang integration.
	 */
	public function __construct(
		?PermalinkManagerIntegration $permalink_manager = null,
		?UrlTransformer $url_transformer = null,
		?PolylangIntegration $polylang = null
	) {
		$this->permalink_manager = $permalink_manager;
		$this->url_transformer   = $url_transformer ?? new UrlTransformer();
		$this->polylang         = $polylang ?? new PolylangIntegration();
	}

	/**
	 * Resolve content by query parameters.
	 *
	 * @param array $query Query with one of: id, slug, path, url, post_type.
	 * @return \WP_Post|\WP_Error Post object or error.
	 */
	/**
	 * Resolves the language context from query and URL.
	 *
	 * @param array $query Request query.
	 * @return array{language: string, path: string}|\WP_Error
	 */
	private function resolve_language_context( array $query ) {
		$requested_lang = trim( (string) ( $query['lang'] ?? '' ) );
		$explicit_lang  = $this->polylang->normalize_language( $requested_lang );

		if ( '' !== $requested_lang && '' === $explicit_lang ) {
			return new \WP_Error(
				'headless_invalid_language',
				'Ngôn ngữ được yêu cầu không hợp lệ hoặc Polylang chưa hoạt động.',
				[ 'status' => 400 ]
			);
		}
		$path = $query['path'] ?? '';
		$url = $query['url'] ?? '';

		$prefix_context = [ 'language' => '', 'path' => $path ];

		if ( $this->polylang->is_active() ) {
			if ( ! empty( $url ) ) {
				$parsed = \wp_parse_url( $url );
				$path_to_parse = $parsed['path'] ?? '';
				if ( ! empty( $path_to_parse ) ) {
					$prefix_context = $this->polylang->extract_language_prefix( $path_to_parse );
				}
				// Update path in context to the stripped version
				$path = $prefix_context['path'];
			} elseif ( ! empty( $path ) ) {
				$prefix_context = $this->polylang->extract_language_prefix( $path );
				$path = $prefix_context['path'];
			}
		}

		$prefix_lang = $prefix_context['language'];

		// Validation: Explicit lang vs Prefix lang
		if ( ! empty( $explicit_lang ) && ! empty( $prefix_lang ) && $explicit_lang !== $prefix_lang ) {
			return new \WP_Error(
				'headless_language_mismatch',
				'Ngôn ngữ trong tham số không khớp với URL.',
				[ 'status' => 400 ]
			);
		}

		// Precedence: Explicit > Prefix > Current/Default
		$final_lang = $explicit_lang;
		if ( '' === $final_lang ) {
			$final_lang = $prefix_lang;
		}
		if ( '' === $final_lang && $this->polylang->is_active() ) {
			$final_lang = $this->polylang->get_current_language();
			if ( '' === $final_lang ) {
				$final_lang = $this->polylang->get_default_language();
			}
		}

		return [
			'language' => $final_lang,
			'path'     => $path,
		];
	}

	public function resolve( array $query ) {
		// 1. Resolve language context
		$lang_context = $this->resolve_language_context( $query );
		if ( is_wp_error( $lang_context ) ) {
			return $lang_context;
		}

		$lang = $lang_context['language'];

		// Determine which selector is used
		$selectors = [ 'id', 'slug', 'path', 'url' ];
		$active    = [];

		foreach ( $selectors as $selector ) {
			if ( isset( $query[ $selector ] ) && '' !== $query[ $selector ] ) {
				$active[] = $selector;
			}
		}

		// Check for selector count
		if ( 0 === count( $active ) ) {
			return new \WP_Error(
				'headless_resolver_missing_input',
				'Thiếu thông tin cần resolve.',
				[ 'status' => 400 ]
			);
		}

		if ( count( $active ) > 1 ) {
			return new \WP_Error(
				'headless_resolver_ambiguous_input',
				'Chỉ được sử dụng một trong id, slug, path hoặc url.',
				[ 'status' => 400 ]
			);
		}

		$selector  = $active[0];
		$value     = $query[ $selector ];
		$post_type = $query['post_type'] ?? 'any';

		// Route to appropriate resolver
		switch ( $selector ) {
			case 'id':
				return $this->resolve_by_id( $value, $this->sanitize_post_types( $post_type ), $lang );
			case 'slug':
				return $this->resolve_by_slug( $value, $this->sanitize_post_types( $post_type ), $lang );
			case 'path':
				return $this->resolve_by_path( $value, $this->sanitize_post_types( $post_type ), $lang );
			case 'url':
				return $this->resolve_by_url( $value, $this->sanitize_post_types( $post_type ), $lang );
		}

		return new \WP_Error(
			'headless_resolver_invalid_selector',
			'Selector không hợp lệ.',
			[ 'status' => 400 ]
		);
	}

	/**
	 * Resolve by post ID.
	 *
	 * @param mixed $post_id Post ID.
	 * @param array $post_types Allowed post types.
	 * @return \WP_Post|\WP_Error
	 */
	public function resolve_by_id( $post_id, array $post_types = [], string $lang = '' ) {
		$id = (int) $post_id;

		if ( $id <= 0 ) {
			return new \WP_Error(
				'headless_content_not_found',
				'Không tìm thấy nội dung công khai.',
				[ 'status' => 404 ]
			);
		}

		$post = get_post( $id );

		if ( ! $post instanceof \WP_Post ) {
			return new \WP_Error(
				'headless_content_not_found',
				'Không tìm thấy nội dung công khai.',
				[ 'status' => 404 ]
			);
		}

		// Check post type
		if ( ! empty( $post_types ) && ! in_array( $post->post_type, $post_types, true ) ) {
			return new \WP_Error(
				'headless_content_not_found',
				'Không tìm thấy nội dung công khai.',
				[ 'status' => 404 ]
			);
		}

		// Check visibility
		if ( ! ContentVisibility::is_post_public( $post ) ) {
			return new \WP_Error(
				'headless_content_not_found',
				'Không tìm thấy nội dung công khai.',
				[ 'status' => 404 ]
			);
		}

		if ( '' !== $lang && $this->polylang && $this->polylang->is_active() ) {
			$post_lang = $this->polylang->get_post_language( $post->ID );

			if ( $post_lang !== $lang ) {
				$translation_id = $this->polylang->get_translation_id( $post->ID, $lang );

				if ( $translation_id <= 0 ) {
					if ( apply_filters( 'headless_api_language_fallback', false, $post->ID, $lang, $this ) ) {
						return $post;
					}

					return new \WP_Error(
						'headless_content_not_found',
						'Không tìm thấy bản dịch công khai.',
						[ 'status' => 404 ]
					);
				}

				$translation = get_post( $translation_id );

				if (
					! $translation instanceof \WP_Post
					|| ( ! empty( $post_types ) && ! in_array( $translation->post_type, $post_types, true ) )
					|| ! ContentVisibility::is_post_public( $translation )
				) {
					return new \WP_Error(
						'headless_content_not_found',
						'Không tìm thấy bản dịch công khai.',
						[ 'status' => 404 ]
					);
				}

				return $translation;
			}
		}

		return $post;
	}

	/**
	 * Resolve by slug.
	 *
	 * @param mixed $slug Slug.
	 * @param array $post_types Allowed post types.
	 * @return \WP_Post|\WP_Error
	 */
	public function resolve_by_slug( $slug, array $post_types = [], string $lang = '' ) {
		$slug = (string) $slug;
		$slug = trim( $slug );

		if ( '' === $slug || false !== strpos( $slug, '/' ) ) {
			return new \WP_Error(
				'headless_resolver_invalid_slug',
				'Slug không hợp lệ.',
				[ 'status' => 400 ]
			);
		}

		$slug = sanitize_title( $slug );

		if ( '' === $slug ) {
			return new \WP_Error(
				'headless_resolver_invalid_slug',
				'Slug không hợp lệ.',
				[ 'status' => 400 ]
			);
		}

		if ( empty( $post_types ) ) {
			$post_types = $this->get_public_post_types();
		}

		$args = [
			'name'             => $slug,
			'post_type'        => $post_types,
			'numberposts'      => 10,
			'post_status'      => 'publish',
			'suppress_filters' => true,
		];

		if ( '' !== $lang && $this->polylang && $this->polylang->is_active() ) {
			$args['lang'] = $lang;
		}

		$posts = get_posts( $args );

		// If not found with language restriction, try without it to see if the slug exists in another language
		if ( empty( $posts ) && '' !== $lang && $this->polylang && $this->polylang->is_active() ) {
			$args_no_lang = $args;
			unset( $args_no_lang['lang'] );
			$posts = get_posts( $args_no_lang );
		}

		if ( empty( $posts ) ) {
			return new \WP_Error(
				'headless_content_not_found',
				'Không tìm thấy nội dung công khai.',
				[ 'status' => 404 ]
			);
		}

		// Check if multiple posts with same slug (for hierarchical post types)
		$public_posts = array_filter(
			$posts,
			function ( $post ) {
				return ContentVisibility::is_post_public( $post );
			}
		);

		if ( count( $public_posts ) > 1 ) {
			// Check if all are hierarchical with different parents
			$hierarchical = get_post_types( [ 'hierarchical' => true ] );
			if ( in_array( reset( $public_posts )->post_type, $hierarchical, true ) ) {
				return new \WP_Error(
					'headless_resolver_ambiguous_slug',
					'Slug không xác định duy nhất. Hãy sử dụng full path.',
					[ 'status' => 409 ]
				);
			}
		}

		if ( count( $public_posts ) !== count( $posts ) ) {
			// Some posts are not public
			if ( empty( $public_posts ) ) {
				return new \WP_Error(
					'headless_content_not_found',
					'Không tìm thấy nội dung công khai.',
					[ 'status' => 404 ]
				);
			}
		}

		$post = reset( $public_posts );

		if ( '' !== $lang && $this->polylang && $this->polylang->is_active() && $post instanceof \WP_Post ) {
			if ( $this->polylang->get_post_language( $post->ID ) !== $lang ) {
				return $this->resolve_by_id( $post->ID, $post_types, $lang );
			}
		}

		return $post;
	}

	/**
	 * Resolve by full hierarchical path.
	 *
	 * @param mixed $path Path.
	 * @param array $post_types Allowed post types.
	 * @return \WP_Post|\WP_Error
	 */
	public function resolve_by_path( $path, array $post_types = [] ) {
		$path = $this->normalize_path( $path );

		if ( '' === $path || false !== strpos( $path, '.' ) ) {
			if ( '' === $path ) {
				// Root path
				return $this->resolve_root_page( $post_types );
			}

			if ( false !== strpos( $path, '..' ) || false !== strpos( $path, './' ) || false !== strpos( $path, '/.' ) ) {
				return new \WP_Error(
					'headless_resolver_invalid_path',
					'Path không hợp lệ.',
					[ 'status' => 400 ]
				);
			}
		}

		// Check for system paths
		$system_paths = [ 'wp-admin', 'wp-login.php', 'wp-json', 'xmlrpc.php', 'wp-cron.php' ];
		$path_lower   = strtolower( $path );
		foreach ( $system_paths as $sys ) {
			if ( str_starts_with( $path_lower, $sys ) ) {
				return new \WP_Error(
					'headless_content_not_found',
					'Không tìm thấy nội dung công khai.',
					[ 'status' => 404 ]
				);
			}
		}

		if ( empty( $post_types ) ) {
			$post_types = $this->get_public_post_types();
		}

		// Try Permalink Manager first
		if ( null !== $this->permalink_manager && $this->permalink_manager->is_active() ) {
			$post_id = $this->permalink_manager->resolve_uri( '/' . $path . '/', $post_types );
			if ( $post_id > 0 ) {
				return $this->resolve_by_id( $post_id, $post_types );
			}
		}

		// Try native WordPress resolution
		$post = get_page_by_path( $path, 'OBJECT', $post_types );

		if ( $post instanceof \WP_Post && ContentVisibility::is_post_public( $post ) ) {
			return $post;
		}

		// Fallback: try exact slug for non-hierarchical
		$last_slug = basename( $path );
		if ( '' !== $last_slug && false === strpos( $last_slug, '/' ) ) {
			$slug_post = $this->resolve_by_slug( $last_slug, $post_types );
			if ( ! is_wp_error( $slug_post ) ) {
				return $slug_post;
			}
		}

		return new \WP_Error(
			'headless_content_not_found',
			'Không tìm thấy nội dung công khai.',
			[ 'status' => 404 ]
		);
	}

	/**
	 * Resolve by absolute CMS or frontend URL.
	 *
	 * @param mixed $url URL.
	 * @param array $post_types Allowed post types.
	 * @return \WP_Post|\WP_Error
	 */
	public function resolve_by_url( $url, array $post_types = [] ) {
		$url = (string) $url;
		$url = trim( $url );

		if ( '' === $url ) {
			return new \WP_Error(
				'headless_resolver_invalid_url',
				'URL không hợp lệ.',
				[ 'status' => 400 ]
			);
		}

		// Check for dangerous schemes
		$scheme = $this->extract_scheme( $url );
		if ( '' !== $scheme ) {
			$blocked = [ 'javascript', 'data', 'vbscript', 'mailto', 'tel', 'sms' ];
			if ( in_array( strtolower( $scheme ), $blocked, true ) ) {
				return new \WP_Error(
					'headless_resolver_invalid_url',
					'URL không hợp lệ.',
					[ 'status' => 400 ]
				);
			}
		}

		// Parse URL
		$parsed = wp_parse_url( $url );

		if ( ! is_array( $parsed ) || ! isset( $parsed['path'] ) ) {
			return new \WP_Error(
				'headless_resolver_invalid_url',
				'URL không hợp lệ.',
				[ 'status' => 400 ]
			);
		}

		// Check if URL is internal
		if ( ! $this->is_internal_url( $url ) ) {
			return new \WP_Error(
				'headless_resolver_external_url',
				'URL không thuộc website này.',
				[ 'status' => 400 ]
			);
		}

		// Extract path
		$path = $parsed['path'] ?? '/';
		$path = $this->normalize_path( $path );

		// Resolve by path
		return $this->resolve_by_path( $path, $post_types );
	}

	/**
	 * Resolve root page (homepage).
	 *
	 * @param array $post_types Allowed post types.
	 * @return \WP_Post|\WP_Error
	 */
	private function resolve_root_page( array $post_types = [] ) {
		$show_on_front = \get_option( 'show_on_front' );

		if ( 'page' !== $show_on_front ) {
			return new \WP_Error(
				'headless_content_not_found',
				'Không tìm thấy nội dung công khai.',
				[ 'status' => 404 ]
			);
		}

		$page_id = (int) \get_option( 'page_on_front' );

		if ( $page_id <= 0 ) {
			return new \WP_Error(
				'headless_content_not_found',
				'Không tìm thấy nội dung công khai.',
				[ 'status' => 404 ]
			);
		}

		return $this->resolve_by_id( $page_id, $post_types );
	}

	/**
	 * Normalize a path.
	 *
	 * @param string $path Path to normalize.
	 * @return string Normalized path.
	 */
	public function normalize_path( string $path ): string {
		if ( '' === $path ) {
			return '';
		}

		// Remove query string and fragment
		$pos_query = strpos( $path, '?' );
		if ( false !== $pos_query ) {
			$path = substr( $path, 0, $pos_query );
		}

		$pos_fragment = strpos( $path, '#' );
		if ( false !== $pos_fragment ) {
			$path = substr( $path, 0, $pos_fragment );
		}

		// Trim whitespace
		$path = trim( $path );

		if ( '' === $path ) {
			return '';
		}

		// Reject null bytes
		if ( false !== strpos( $path, "\0" ) ) {
			return '';
		}

		// Normalize slashes: convert backslash to forward slash
		$path = str_replace( '\\', '/', $path );

		// Gom duplicate slashes
		$path = preg_replace( '#/+#', '/', $path );

		if ( ! is_string( $path ) ) {
			return '';
		}

		// Remove leading and trailing slashes
		$path = trim( $path, '/' );

		// Decode percent encoding once
		$path = urldecode( $path );

		// Check for traversal attempts
		if ( false !== strpos( $path, '..' ) || false !== strpos( $path, './' ) || false !== strpos( $path, '/.' ) ) {
			return '';
		}

		return $path;
	}

	/**
	 * Get public post types.
	 *
	 * @return string[]
	 */
	private function get_public_post_types(): array {
		$post_types = get_post_types( [ 'public' => true ] );

		if ( ! is_array( $post_types ) ) {
			return [];
		}

		// Remove internal types
		$internal = [
			'revision',
			'nav_menu_item',
			'custom_css',
			'customize_changeset',
			'oembed_cache',
			'user_request',
			'wp_global_styles',
			'wp_navigation',
			'wp_font_family',
			'wp_font_face',
			'attachment',
		];

		$post_types = array_diff( $post_types, $internal );

		return apply_filters(
			'headless_api_resolver_post_types',
			$post_types,
			[],
			$this
		);
	}

	/**
	 * Sanitize post types.
	 *
	 * @param mixed $post_type Post type(s).
	 * @return string[]
	 */
	private function sanitize_post_types( $post_type ) {
		if ( 'any' === $post_type ) {
			return $this->get_public_post_types();
		}

		if ( is_array( $post_type ) ) {
			return array_map( 'sanitize_key', $post_type );
		}

		$type = sanitize_key( (string) $post_type );

		return '' === $type ? $this->get_public_post_types() : [ $type ];
	}

	/**
	 * Check if URL is internal.
	 *
	 * @param string $url URL.
	 * @return bool
	 */
	private function is_internal_url( string $url ): bool {
		if ( '' === $url ) {
			return false;
		}

		$parsed = wp_parse_url( $url );

		if ( ! is_array( $parsed ) || ! isset( $parsed['host'] ) ) {
			return false;
		}

		$url_host = strtolower( $parsed['host'] );

		// Get internal hosts
		$home = home_url();
		$site = site_url();

		$internal_hosts = [];

		if ( ! empty( $home ) ) {
			$home_parsed = wp_parse_url( $home );
			if ( is_array( $home_parsed ) && isset( $home_parsed['host'] ) ) {
				$internal_hosts[] = strtolower( $home_parsed['host'] );
			}
		}

		if ( ! empty( $site ) && $site !== $home ) {
			$site_parsed = wp_parse_url( $site );
			if ( is_array( $site_parsed ) && isset( $site_parsed['host'] ) ) {
				$internal_hosts[] = strtolower( $site_parsed['host'] );
			}
		}

		return in_array( $url_host, $internal_hosts, true );
	}

	/**
	 * Extract URL scheme.
	 *
	 * @param string $url URL.
	 * @return string Scheme or empty string.
	 */
	private function extract_scheme( string $url ): string {
		$pos = strpos( $url, '://' );

		if ( false === $pos ) {
			return '';
		}

		$scheme = substr( $url, 0, $pos );

		if ( preg_match( '/^[a-z][a-z0-9+\-.]*$/i', $scheme ) ) {
			return $scheme;
		}

		return '';
	}
}
