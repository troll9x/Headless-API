<?php
namespace TLU_Headless_API\Integrations;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use TLU_Headless_API\Contracts\IntegrationInterface;

/**
 * Bọc toàn bộ Rank Math API dùng trong framework.
 *
 * Trả về giá trị mặc định an toàn (chuỗi rỗng / mảng rỗng)
 * khi Rank Math không được cài đặt — không phát sinh lỗi fatal.
 *
 * Các meta key Rank Math được đọc trực tiếp từ post meta
 * (rank_math_title, rank_math_description, rank_math_robots…)
 * vì Rank Math không cung cấp public API đủ ổn định.
 */
class RankMathIntegration implements IntegrationInterface {

	/** Kiểm tra Rank Math có đang hoạt động không. */
	public function is_active(): bool {
		return defined( 'RANK_MATH_VERSION' ) && class_exists( '\RankMath\Helper' );
	}

	/** Kiểm tra Rank Math có hỗ trợ headless output không. */
	public function supports_headless(): bool {
		if ( ! $this->is_active() ) {
			return false;
		}
		return (bool) \RankMath\Helper::get_settings( 'general.headless_support' );
	}

	/** Đọc SEO title từ meta Rank Math. */
	public function get_title( \WP_Post $post ): string {
		if ( ! $this->is_active() ) {
			return '';
		}
		return (string) get_post_meta( $post->ID, 'rank_math_title', true );
	}

	/** Đọc meta description từ Rank Math. */
	public function get_description( \WP_Post $post ): string {
		if ( ! $this->is_active() ) {
			return '';
		}
		return (string) get_post_meta( $post->ID, 'rank_math_description', true );
	}

	/** Đọc canonical URL từ Rank Math. */
	public function get_canonical( \WP_Post $post ): string {
		if ( ! $this->is_active() ) {
			return '';
		}
		return (string) get_post_meta( $post->ID, 'rank_math_canonical_url', true );
	}

	/** Đọc robots directives từ Rank Math. */
	public function get_robots( \WP_Post $post ): array {
		if ( ! $this->is_active() ) {
			return [];
		}
		return (array) ( get_post_meta( $post->ID, 'rank_math_robots', true ) ?: [] );
	}

	/** Đọc Open Graph meta fields từ Rank Math. */
	public function get_open_graph( \WP_Post $post ): array {
		if ( ! $this->is_active() ) {
			return [];
		}
		return [
			'title'       => (string) get_post_meta( $post->ID, 'rank_math_facebook_title',       true ),
			'description' => (string) get_post_meta( $post->ID, 'rank_math_facebook_description', true ),
			'image'       => (string) get_post_meta( $post->ID, 'rank_math_facebook_image',       true ),
			'type'        => (string) get_post_meta( $post->ID, 'rank_math_facebook_image_type',  true ),
		];
	}

	/** Đọc Twitter Card meta fields từ Rank Math. */
	public function get_twitter( \WP_Post $post ): array {
		if ( ! $this->is_active() ) {
			return [];
		}
		return [
			'title'       => (string) get_post_meta( $post->ID, 'rank_math_twitter_title',       true ),
			'description' => (string) get_post_meta( $post->ID, 'rank_math_twitter_description', true ),
			'image'       => (string) get_post_meta( $post->ID, 'rank_math_twitter_image',       true ),
			'card_type'   => (string) get_post_meta( $post->ID, 'rank_math_twitter_card_type',   true ),
		];
	}

	/** Đọc JSON-LD Schema từ Rank Math. */
	public function get_schema( \WP_Post $post ): array {
		if ( ! $this->is_active() ) {
			return [];
		}
		$json = (string) get_post_meta( $post->ID, 'rank_math_schema_JsonLd', true );
		if ( '' === $json ) {
			return [];
		}
		$data = json_decode( $json, true );
		return is_array( $data ) ? $data : [];
	}

	/** Lấy breadcrumbs từ Rank Math (nếu có API public). */
	public function get_breadcrumbs( \WP_Post $post ): array {
		if ( ! $this->is_active() ) {
			return [];
		}
		// Rank Math breadcrumbs are usually rendered as HTML.
		// We check if there's a structured way to get them.
		// If not, we return empty and document this limitation.
		return [];
	}

	/** Lấy toàn bộ SEO data dưới dạng mảng. */
	public function get_seo( \WP_Post $post ): array {
		if ( ! $this->is_active() ) {
			return [];
		}
		return [
			'title'       => $this->get_title( $post ),
			'description' => $this->get_description( $post ),
			'canonical'   => $this->get_canonical( $post ),
			'robots'      => $this->get_robots( $post ),
			'open_graph'  => $this->get_open_graph( $post ),
			'twitter'     => $this->get_twitter( $post ),
			'schema'      => $this->get_schema( $post ),
			'breadcrumbs' => $this->get_breadcrumbs( $post ),
		];
	}
	/** Legacy support for schema JSON string. */
	public function get_schema_json( int $post_id ): string {
		$post = get_post( $post_id );
		if ( ! $post instanceof \WP_Post ) {
			return '';
		}
		$schema = $this->get_schema( $post );
		return json_encode( $schema );
	}

	/** Legacy support for schema type. */
	public function get_schema_type( int $post_id ): string {
		if ( ! $this->is_active() ) {
			return '';
		}
		return (string) get_post_meta( $post_id, 'rank_math_rich_snippet', true );
	}

	/**
	 * Thay thế biến Rank Math trong một chuỗi meta.
	 *
	 * Hỗ trợ: %title%, %sitename%, %sep%, %excerpt%, %date%, %modified%.
	 * Trả về $value nguyên vẹn nếu không chứa biến '%'.
	 */
	public function expand_variables( string $value, \WP_Post $post ): string {
		if ( empty( $value ) || false === strpos( $value, '%' ) ) {
			return $value;
		}
		$sep  = (string) apply_filters( 'rank_math/settings/title_separator', '|' );
		$vars = [
			'%title%'    => $post->post_title,
			'%sitename%' => get_bloginfo( 'name' ),
			'%sep%'      => $sep,
			'%excerpt%'  => wp_strip_all_tags( $post->post_excerpt ),
			'%date%'     => get_the_date( '', $post->ID ),
			'%modified%' => get_the_modified_date( '', $post->ID ),
		];
		return str_replace( array_keys( $vars ), array_values( $vars ), $value );
	}
}
