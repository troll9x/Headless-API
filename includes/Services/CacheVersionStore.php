<?php
namespace TLU_Headless_API\Services;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class CacheVersionStore {
	private const OPTION_KEY = 'tlu_headless_api_cache_generations';
	private const BASE_DOMAINS = [ 'global', 'content', 'menus', 'options', 'search', 'schema' ];

	public function get( string $domain ): int {
		$domain = $this->sanitize_domain( $domain );
		if ( '' === $domain ) {
			return 1;
		}
		$versions = $this->versions();
		return max( 1, (int) ( $versions[ $domain ] ?? 1 ) );
	}

	public function get_many( array $domains ): array {
		$out = [];
		foreach ( $domains as $domain ) {
			$domain = $this->sanitize_domain( (string) $domain );
			if ( '' !== $domain ) {
				$out[ $domain ] = $this->get( $domain );
			}
		}
		return $out;
	}

	public function bump( string $domain ): int {
		$domain = $this->sanitize_domain( $domain );
		if ( '' === $domain ) {
			return 1;
		}
		$versions = $this->versions();
		$versions[ $domain ] = max( 1, (int) ( $versions[ $domain ] ?? 1 ) ) + 1;
		update_option( self::OPTION_KEY, $versions, false );
		return $versions[ $domain ];
	}

	public function bump_many( array $domains ): array {
		$out = [];
		foreach ( $domains as $domain ) {
			$out[ $domain ] = $this->bump( (string) $domain );
		}
		return $out;
	}

	public function reset_all(): void {
		update_option( self::OPTION_KEY, array_fill_keys( self::BASE_DOMAINS, 1 ), false );
	}

	public function relevant_domains_for_request( $request ): array {
		$route = method_exists( $request, 'get_route' ) ? $request->get_route() : '';
		$params = method_exists( $request, 'get_params' ) ? $request->get_params() : [];
		$lang = isset( $params['lang'] ) ? sanitize_key( (string) $params['lang'] ) : '';
		$type = isset( $params['post_type'] ) ? sanitize_key( (string) $params['post_type'] ) : ( isset( $params['type'] ) ? sanitize_key( (string) $params['type'] ) : 'page' );
		$taxonomy = isset( $params['taxonomy'] ) ? sanitize_key( (string) $params['taxonomy'] ) : '';

		$domains = [ 'global' ];

		if ( preg_match( '#/(schema|health|settings)(/|$)#', $route ) ) {
			$domains[] = 'schema';
		} elseif ( preg_match( '#/(menus)(/|$)#', $route ) ) {
			$domains[] = 'menus';
		} elseif ( preg_match( '#/(options)(/|$)#', $route ) ) {
			$domains[] = 'options';
		} elseif ( preg_match( '#/(search|suggest)(/|$)#', $route ) ) {
			$domains[] = 'content';
			$domains[] = 'search';
		} elseif ( preg_match( '#/(term|taxonom|archive)(/|$)#', $route ) ) {
			$domains[] = 'content';
			if ( $type ) {
				$domains[] = 'post_type:' . $type;
			}
			if ( $taxonomy ) {
				$domains[] = 'taxonomy:' . $taxonomy;
			}
		} else {
			$domains[] = 'content';
			if ( $type ) {
				$domains[] = 'post_type:' . $type;
			}
			$domains[] = 'options';
		}
		if ( $lang ) {
			$domains[] = 'language:' . $lang;
		}

		$domains = apply_filters( 'headless_api_cache_generation_domains', $domains, $request );
		if ( ! is_array( $domains ) ) {
			$domains = [ 'global' ];
		}

		return array_values( array_unique( array_filter( array_map( [ $this, 'sanitize_domain' ], $domains ) ) ) );
	}

	public function sanitize_domain( string $domain ): string {
		$domain = strtolower( trim( $domain ) );
		if ( strlen( $domain ) > 80 ) {
			return '';
		}
		return preg_match( '/^[a-z0-9_:-]+$/', $domain ) ? $domain : '';
	}

	public function get_all_domains(): array {
		return array_merge( self::BASE_DOMAINS, [ 'post_type', 'taxonomy', 'language' ] );
	}

	private function versions(): array {
		$value = get_option( self::OPTION_KEY, [] );
		if ( ! is_array( $value ) ) {
			$value = [];
		}
		foreach ( self::BASE_DOMAINS as $domain ) {
			$value[ $domain ] = max( 1, (int) ( $value[ $domain ] ?? 1 ) );
		}
		return $value;
	}
}