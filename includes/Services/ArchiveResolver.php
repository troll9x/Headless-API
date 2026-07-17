<?php
namespace TLU_Headless_API\Services;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use TLU_Headless_API\Contracts\Service;
use TLU_Headless_API\Helpers\ContentVisibility;
use TLU_Headless_API\Integrations\PolylangIntegration;
use TLU_Headless_API\Integrations\PermalinkManagerIntegration;

/**
 * ArchiveResolver resolves archive contexts by various selectors.
 *
 * Supports:
 * - post_type archive
 * - taxonomy + term archive
 * - author archive
 * - date archive (year, year+month, year+month+day)
 * - path resolution
 * - URL resolution
 */
final class ArchiveResolver implements Service {

	private ?PolylangIntegration $polylang;
	private ?PermalinkManagerIntegration $permalink_manager;
	private ?UrlTransformer $url_transformer;

	/**
	 * Constructor.
	 *
	 * @param PolylangIntegration|null $polylang Optional Polylang integration.
	 * @param PermalinkManagerIntegration|null $permalink_manager Optional Permalink Manager integration.
	 * @param UrlTransformer|null $url_transformer Optional URL transformer.
	 */
	public function __construct(
		?PolylangIntegration $polylang = null,
		?PermalinkManagerIntegration $permalink_manager = null,
		?UrlTransformer $url_transformer = null
	) {
		$this->polylang = $polylang;
		$this->permalink_manager = $permalink_manager;
		$this->url_transformer = $url_transformer ?? new UrlTransformer();
	}

	/**
	 * Resolve archive by query parameters.
	 *
	 * @param array $query Query with one selector family: post_type, taxonomy+term, author, date, path, url.
	 * @return array|\WP_Error Archive context or error.
	 */
	public function resolve( array $query ): array|\WP_Error {
		// Determine which selector family is used
		$selectors = [
			'post_type' => isset( $query['post_type'] ) && '' !== $query['post_type'],
			'taxonomy'  => isset( $query['taxonomy'] ) && '' !== $query['taxonomy'],
			'author'    => isset( $query['author'] ) && '' !== $query['author'],
			'year'      => isset( $query['year'] ) && '' !== $query['year'],
			'path'      => isset( $query['path'] ) && '' !== $query['path'],
			'url'       => isset( $query['url'] ) && '' !== $query['url'],
		];

		// Count active selector families
		$active_count = array_sum( $selectors );

		if ( 0 === $active_count ) {
			return new \WP_Error(
				'headless_archive_missing_selector',
				'Thiếu thông tin archive cần resolve.',
				[ 'status' => 400 ]
			);
		}

		if ( $active_count > 1 ) {
			return new \WP_Error(
				'headless_archive_ambiguous_selector',
				'Chỉ được sử dụng một loại archive selector.',
				[ 'status' => 400 ]
			);
		}

		$lang = $this->polylang ? $this->polylang->normalize_language( $query['lang'] ?? '' ) : '';

		// Route to appropriate resolver
		if ( $selectors['post_type'] ) {
			return $this->resolve_post_type( $query['post_type'], $lang );
		}

		if ( $selectors['taxonomy'] ) {
			$term = $query['term'] ?? '';
			return $this->resolve_taxonomy( $query['taxonomy'], $term, $lang );
		}

		if ( $selectors['author'] ) {
			return $this->resolve_author( $query['author'], $lang );
		}

		if ( $selectors['year'] ) {
			$year = (int) $query['year'];
			$month = isset( $query['month'] ) ? (int) $query['month'] : 0;
			$day = isset( $query['day'] ) ? (int) $query['day'] : 0;
			$post_type = $query['post_type'] ?? 'post';
			return $this->resolve_date( $year, $month, $day, $post_type, $lang );
		}

		if ( $selectors['path'] ) {
			return $this->resolve_path( $query['path'], $lang );
		}

		if ( $selectors['url'] ) {
			return $this->resolve_url( $query['url'], $lang );
		}

		return new \WP_Error(
			'headless_archive_invalid_selector',
			'Selector không hợp lệ.',
			[ 'status' => 400 ]
		);
	}

	/**
	 * Resolve post type archive.
	 *
	 * @param string $post_type Post type slug.
	 * @param string $lang Language slug.
	 * @return array|\WP_Error Archive context.
	 */
	public function resolve_post_type( string $post_type, string $lang = '' ): array|\WP_Error {
		$post_type_obj = get_post_type_object( $post_type );

		if ( ! $post_type_obj || ! $this->is_valid_public_post_type( $post_type_obj ) ) {
			return new \WP_Error(
				'headless_archive_post_type_invalid',
				'Post type không hợp lệ hoặc không công khai.',
				[ 'status' => 404 ]
			);
		}

		$archive_link = get_post_type_archive_link( $post_type );
		if ( ! $archive_link ) {
			return new \WP_Error(
				'headless_archive_post_type_no_archive',
				'Post type không có archive.',
				[ 'status' => 404 ]
			);
		}

		// Build context
		$context = [
			'type'         => 'post_type',
			'post_type'    => $post_type,
			'taxonomy'     => null,
			'term'         => null,
			'author'       => null,
			'date'         => null,
			'language'     => $lang,
			'canonical'    => $this->url_transformer->transform_navigation_url( $archive_link ),
			'canonical_source_url' => $archive_link,
			'label'        => $post_type_obj->label,
			'description'  => $post_type_obj->description ?? '',
		];

		return apply_filters( 'headless_api_archive_post_type_context', $context, $post_type, $lang, $this );
	}

	/**
	 * Resolve taxonomy archive by term.
	 *
	 * @param string $taxonomy Taxonomy slug.
	 * @param string $term Term slug or ID.
	 * @param string $lang Language slug.
	 * @return array|\WP_Error Archive context.
	 */
	public function resolve_taxonomy( string $taxonomy, string $term, string $lang = '' ): array|\WP_Error {
		$term_obj = $this->get_valid_term( $taxonomy, $term );
		if ( is_wp_error( $term_obj ) ) {
			return $term_obj;
		}

		// Get term language if Polylang active
		$term_lang = $lang;
		if ( '' !== $lang && $this->polylang && $this->polylang->is_active() ) {
			$term_lang = $this->polylang->normalize_language( $lang );
			if ( '' === $term_lang ) {
				return new \WP_Error(
					'headless_archive_invalid_language',
					'Ngôn ngữ không hợp lệ.',
					[ 'status' => 400 ]
				);
			}

			// Try to get translated term
			$translated_term_id = $this->polylang->get_translation_id( $term_obj->term_id, $term_lang );
			if ( $translated_term_id > 0 ) {
				$translated_term = get_term( $translated_term_id, $taxonomy );
				if ( $translated_term instanceof \WP_Term ) {
					$term_obj = $translated_term;
				}
			}
		}

		$term_link = get_term_link( $term_obj );
		if ( is_wp_error( $term_link ) ) {
			return new \WP_Error(
				'headless_archive_term_invalid',
				'Term không hợp lệ.',
				[ 'status' => 404 ]
			);
		}

		$term_taxonomy = get_taxonomy( $term_obj->taxonomy );
		if ( ! $term_taxonomy || ! $this->is_valid_public_taxonomy( $term_taxonomy ) ) {
			return new \WP_Error(
				'headless_archive_taxonomy_invalid',
				'Taxonomy không hợp lệ hoặc không công khai.',
				[ 'status' => 404 ]
			);
		}

		$context = [
			'type'         => 'taxonomy',
			'post_type'    => '',
			'taxonomy'     => [
				'name'         => $term_obj->taxonomy,
				'label'        => $term_taxonomy->label,
				'hierarchical' => (bool) $term_taxonomy->hierarchical,
			],
			'term'         => [
				'id'          => (int) $term_obj->term_id,
				'slug'        => $term_obj->slug,
				'name'        => $term_obj->name,
				'description' => $term_obj->description ?? '',
				'parent'      => (int) $term_obj->parent,
			],
			'author'       => null,
			'date'         => null,
			'language'     => $lang,
			'canonical'    => $this->url_transformer->transform_navigation_url( $term_link ),
			'canonical_source_url' => $term_link,
			'label'        => $term_obj->name,
			'description'  => $term_obj->description ?? '',
		];

		return apply_filters( 'headless_api_archive_taxonomy_context', $context, $term_obj, $lang, $this );
	}

	/**
	 * Resolve author archive.
	 *
	 * @param mixed $author Author ID or nicename.
	 * @param string $lang Language slug.
	 * @return array|\WP_Error Archive context.
	 */
	public function resolve_author( $author, string $lang = '' ): array|\WP_Error {
		$author_obj = null;

		if ( is_numeric( $author ) ) {
			$author_obj = get_user_by( 'id', (int) $author );
		} else {
			$author_obj = get_user_by( 'slug', sanitize_title( (string) $author ) );
		}

		if ( ! $author_obj instanceof \WP_User ) {
			return new \WP_Error(
				'headless_archive_author_invalid',
				'Author không hợp lệ.',
				[ 'status' => 404 ]
			);
		}

		// Don't expose user_email, user_login
		$author_link = get_author_posts_url( $author_obj->ID );
		if ( ! $author_link ) {
			return new \WP_Error(
				'headless_archive_author_invalid',
				'Author không có archive.',
				[ 'status' => 404 ]
			);
		}

		$context = [
			'type'         => 'author',
			'post_type'    => 'post',
			'taxonomy'     => null,
			'term'         => null,
			'author'       => [
				'id'     => (int) $author_obj->ID,
				'slug'   => $author_obj->user_nicename,
				'name'   => $author_obj->display_name,
				'avatar' => get_avatar_url( $author_obj->ID ),
			],
			'date'         => null,
			'language'     => $lang,
			'canonical'    => $this->url_transformer->transform_navigation_url( $author_link ),
			'canonical_source_url' => $author_link,
			'label'        => $author_obj->display_name,
			'description'  => '',
		];

		return apply_filters( 'headless_api_archive_author_context', $context, $author_obj, $lang, $this );
	}

	/**
	 * Resolve date archive.
	 *
	 * @param int $year Year.
	 * @param int $month Month (1-12, optional).
	 * @param int $day Day (optional).
	 * @param string $post_type Post type.
	 * @param string $lang Language slug.
	 * @return array|\WP_Error Archive context.
	 */
	public function resolve_date( int $year, int $month = 0, int $day = 0, string $post_type = 'post', string $lang = '' ): array|\WP_Error {
		if ( $year < 1970 || $year > 2100 ) {
			return new \WP_Error(
				'headless_archive_date_invalid',
				'Năm không hợp lệ.',
				[ 'status' => 400 ]
			);
		}

		if ( $month < 0 || $month > 12 ) {
			return new \WP_Error(
				'headless_archive_date_invalid',
				'Tháng không hợp lệ.',
				[ 'status' => 400 ]
			);
		}

		if ( $day < 0 || $day > 31 ) {
			return new \WP_Error(
				'headless_archive_date_invalid',
				'Ngày không hợp lệ.',
				[ 'status' => 400 ]
			);
		}

		if ( $month > 0 && $day > 0 && ! checkdate( $month, $day, $year ) ) {
			return new \WP_Error(
				'headless_archive_date_invalid',
				'Ngày không hợp lệ.',
				[ 'status' => 400 ]
			);
		}

		if ( $month > 0 ) {
			$archive_link = $day > 0 ? get_day_link( $year, $month, $day ) : get_month_link( $year, $month );
		} else {
			$archive_link = get_year_link( $year );
		}

		if ( ! $archive_link ) {
			return new \WP_Error(
				'headless_archive_date_invalid',
				'Archive date không hợp lệ.',
				[ 'status' => 404 ]
			);
		}

		$date_context = [
			'year'  => $year,
			'month' => $month,
			'day'   => $day,
		];

		$context = [
			'type'         => 'date',
			'post_type'    => $post_type,
			'taxonomy'     => null,
			'term'         => null,
			'author'       => null,
			'date'         => $date_context,
			'language'     => $lang,
			'canonical'    => $this->url_transformer->transform_navigation_url( $archive_link ),
			'canonical_source_url' => $archive_link,
			'label'        => $this->build_date_label( $year, $month, $day ),
			'description'  => '',
		];

		return apply_filters( 'headless_api_archive_date_context', $context, $year, $month, $day, $post_type, $lang, $this );
	}

	/**
	 * Resolve archive by path.
	 *
	 * @param string $path Path to resolve.
	 * @param string $lang Language slug.
	 * @return array|\WP_Error Archive context.
	 */
	public function resolve_path( string $path, string $lang = '' ): array|\WP_Error {
		$path = trim( $path, '/' );
		if ( '' === $path ) {
			return $this->resolve_path( 'about', $lang ); // Fallback - root should be handled by caller
		}

		// Try to extract language prefix if Polylang active
		$lang_path = $path;
		if ( $this->polylang && $this->polylang->is_active() ) {
			$prefix_context = $this->polylang->extract_language_prefix( $path );
			if ( '' !== $prefix_context['language'] ) {
				$lang = $prefix_context['language'];
				$lang_path = $prefix_context['path'];
			}
		}

		// Check if it's a known archive pattern
		$parts = explode( '/', trim( $lang_path, '/' ) );
		$first_part = $parts[0] ?? '';

		// Check if first part is a known post type or taxonomy base
		$post_types = get_post_types( [ 'public' => true, 'has_archive' => true ] );
		$taxonomies = get_taxonomies( [ 'public' => true ] );

		// Check post type archive
		foreach ( $post_types as $pt ) {
			$obj = get_post_type_object( $pt );
			if ( $obj && $first_part === $obj->rewrite['slug'] ?? $pt ) {
				return $this->resolve_post_type( $pt, $lang );
			}
		}

		// Check taxonomy archive (term)
		foreach ( $taxonomies as $tax ) {
			$obj = get_taxonomy( $tax );
			if ( ! $obj ) continue;

			$base = $obj->rewrite['slug'] ?? $tax;
			if ( $first_part === $base && isset( $parts[1] ) ) {
				$term_slug = $parts[1];
				return $this->resolve_taxonomy( $tax, $term_slug, $lang );
			}
		}

		// Check author archive
		$author = get_user_by( 'slug', sanitize_title( $first_part ) );
		if ( $author instanceof \WP_User ) {
			return $this->resolve_author( $author->ID, $lang );
		}

		// Check date archive (YYYY, YYYY/MM, YYYY/MM/DD)
		if ( ctype_digit( $first_part ) && strlen( $first_part ) === 4 ) {
			$year = (int) $first_part;
			$month = isset( $parts[1] ) && ctype_digit( $parts[1] ) ? (int) $parts[1] : 0;
			$day = isset( $parts[2] ) && ctype_digit( $parts[2] ) ? (int) $parts[2] : 0;
			return $this->resolve_date( $year, $month, $day, 'post', $lang );
		}

		return new \WP_Error(
			'headless_archive_path_not_found',
			'Không tìm thấy archive cho path này.',
			[ 'status' => 404 ]
		);
	}

	/**
	 * Resolve archive by URL.
	 *
	 * @param string $url URL to resolve.
	 * @param string $lang Language slug.
	 * @return array|\WP_Error Archive context.
	 */
	public function resolve_url( string $url, string $lang = '' ): array|\WP_Error {
		$parsed = wp_parse_url( $url );

		if ( ! $parsed || ! isset( $parsed['path'] ) ) {
			return new \WP_Error(
				'headless_archive_url_invalid',
				'URL không hợp lệ.',
				[ 'status' => 400 ]
			);
		}

		$path = $parsed['path'];
		return $this->resolve_path( $path, $lang );
	}

	/**
	 * Get valid term by taxonomy and term identifier.
	 *
	 * @param string $taxonomy Taxonomy slug.
	 * @param string $term Term slug or ID.
	 * @return \WP_Term|\WP_Error Term object or error.
	 */
	private function get_valid_term( string $taxonomy, string $term ): \WP_Term|\WP_Error {
		$term_obj = null;

		if ( ctype_digit( $term ) ) {
			$term_obj = get_term( (int) $term, $taxonomy );
		} else {
			$term_obj = get_term_by( 'slug', $term, $taxonomy );
		}

		if ( ! $term_obj instanceof \WP_Term ) {
			return new \WP_Error(
				'headless_archive_term_not_found',
				'Term không tìm thấy.',
				[ 'status' => 404 ]
			);
		}

		return $term_obj;
	}

	/**
	 * Check if post type is valid and public.
	 *
	 * @param \WP_Post_Type $post_type_obj Post type object.
	 * @return bool Whether post type is valid.
	 */
	private function is_valid_public_post_type( \WP_Post_Type $post_type_obj ): bool {
		// Must be public and publicly queryable
		if ( ! $post_type_obj->public || ! $post_type_obj->publicly_queryable ) {
			return false;
		}

		// Must have archive
		if ( $post_type_obj->has_archive === false ) {
			return false;
		}

		// Internal types check
		$internal = [
			'revision', 'nav_menu_item', 'custom_css', 'customize_changeset',
			'oembed_cache', 'user_request', 'wp_global_styles', 'wp_navigation',
			'wp_font_family', 'wp_font_face', 'attachment',
		];

		if ( in_array( $post_type_obj->name, $internal, true ) ) {
			return false;
		}

		return true;
	}

	/**
	 * Check if taxonomy is valid and public.
	 *
	 * @param \WP_Taxonomy $taxonomy_obj Taxonomy object.
	 * @return bool Whether taxonomy is valid.
	 */
	private function is_valid_public_taxonomy( \WP_Taxonomy $taxonomy_obj ): bool {
		if ( ! $taxonomy_obj->public || ! $taxonomy_obj->publicly_queryable ) {
			return false;
		}

		if ( ! $taxonomy_obj->show_in_rest ) {
			return false;
		}

		// Must be associated with at least one public post type
		$object_types = $taxonomy_obj->object_type;
		$public_types = get_post_types( [ 'public' => true ] );
		$has_public_type = array_intersect( $object_types, $public_types );

		if ( empty( $has_public_type ) ) {
			return false;
		}

		return true;
	}

	/**
	 * Build human-readable date label.
	 *
	 * @param int $year Year.
	 * @param int $month Month (0 if not specified).
	 * @param int $day Day (0 if not specified).
	 * @return string Date label.
	 */
	private function build_date_label( int $year, int $month = 0, int $day = 0 ): string {
		$labels = [ 'January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December' ];

		if ( $month > 0 && $month <= 12 && $day > 0 ) {
			return sprintf( '%s %d, %d', $labels[ $month - 1 ], $day, $year );
		}

		if ( $month > 0 && $month <= 12 ) {
			return sprintf( '%s %d', $labels[ $month - 1 ], $year );
		}

		return (string) $year;
	}
}