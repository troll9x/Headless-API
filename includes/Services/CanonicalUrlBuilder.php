<?php
namespace TLU_Headless_API\Services;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Builds public article URLs without consulting WordPress permalink filters. */
final class CanonicalUrlBuilder {
	public const CANONICAL_META_KEY = '_headless_public_path';
	public const LEGACY_META_KEY   = '_headless_legacy_path';

	public static function post_path( \WP_Post $post ): string {
		$snapshot = (string) get_post_meta( $post->ID, self::CANONICAL_META_KEY, true );
		if ( '' !== $snapshot && '/' === $snapshot[0] && 0 !== strpos( $snapshot, '//' ) ) {
			return '/' . trim( $snapshot, '/' );
		}

		$language = function_exists( 'pll_get_post_language' )
			? (string) pll_get_post_language( $post->ID, 'slug' )
			: 'vi';
		$prefix = 'en' === $language ? '/en/' : '/';
		return $prefix . rawurlencode( $post->post_name ) . '-' . (int) $post->ID;
	}

	public static function post_url( \WP_Post $post ): string {
		return untrailingslashit( \TLU_Headless_API\Config::frontend_url() ) . self::post_path( $post );
	}

	/** Snapshot current public permalink while its current rewrite provider is active. */
	public static function snapshot_post( \WP_Post $post, bool $write = false ): array {
		$parsed    = wp_parse_url( (string) get_permalink( $post ) );
		$old_path  = is_array( $parsed ) ? '/' . trim( rawurldecode( (string) ( $parsed['path'] ?? '' ) ), '/' ) : '';
		if ( '/' === $old_path ) {
			$old_path = '';
		}
		$language = function_exists( 'pll_get_post_language' )
			? (string) pll_get_post_language( $post->ID, 'slug' )
			: 'vi';
		$prefix   = 'en' === $language ? '/en/' : '/';
		$segments = array_values( array_filter( explode( '/', trim( $old_path, '/' ) ), 'strlen' ) );
		$base     = $segments ? (string) end( $segments ) : $post->post_name;
		$base     = sanitize_title( $base );
		if ( '' === $base ) {
			$base = sanitize_title( $post->post_name );
		}
		$canonical = $prefix . $base;
		if ( ! preg_match( '/-' . (int) $post->ID . '$/', $canonical ) ) {
			$canonical .= '-' . (int) $post->ID;
		}

		if ( $write ) {
			update_post_meta( $post->ID, self::CANONICAL_META_KEY, $canonical );
			update_post_meta( $post->ID, self::LEGACY_META_KEY, $old_path );
		}

		return [ 'id' => (int) $post->ID, 'legacy_path' => $old_path, 'canonical_path' => $canonical ];
	}
}
