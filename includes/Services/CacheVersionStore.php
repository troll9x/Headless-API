<?php
namespace TLU_Headless_API\Services;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class CacheVersionStore {
	private const OPTION_KEY = 'tlu_headless_api_cache_generations';
	private const LOCK_OPTION = 'tlu_headless_api_cache_generations_lock';
	private const CACHE_GROUP = 'tlu_headless_api';
	private const LOCK_TIMEOUT_SECONDS = 5;
	private const BASE_DOMAINS = [ 'global', 'content', 'menus', 'options', 'search', 'schema' ];

	public function get( string $domain ): int {
		$domain = $this->sanitize_domain( $domain );
		if ( '' === $domain ) {
			return 1;
		}
		if ( wp_using_ext_object_cache() ) {
			$cached = wp_cache_get( $this->cache_key( $domain ), self::CACHE_GROUP );
			if ( false !== $cached ) {
				return max( 1, (int) $cached );
			}
		}

		$versions = $this->versions();
		$version  = max( 1, (int) ( $versions[ $domain ] ?? 1 ) );
		if ( wp_using_ext_object_cache() ) {
			wp_cache_add( $this->cache_key( $domain ), $version, self::CACHE_GROUP );
		}
		return $version;
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
		return $this->with_lock( function () use ( $domain ): int {
			$versions = $this->versions();
			$versions[ $domain ] = max( 1, (int) ( $versions[ $domain ] ?? 1 ) ) + 1;
			update_option( self::OPTION_KEY, $versions, false );
			wp_cache_set( $this->cache_key( $domain ), $versions[ $domain ], self::CACHE_GROUP );
			return $versions[ $domain ];
		} );
	}

	public function bump_many( array $domains ): array {
		$domains = array_values( array_unique( array_filter( array_map(
			fn( $domain ) => $this->sanitize_domain( (string) $domain ),
			$domains
		) ) ) );
		if ( empty( $domains ) ) {
			return [];
		}

		return $this->with_lock( function () use ( $domains ): array {
			$versions = $this->versions();
			$out      = [];
			foreach ( $domains as $domain ) {
				$versions[ $domain ] = max( 1, (int) ( $versions[ $domain ] ?? 1 ) ) + 1;
				$out[ $domain ]      = $versions[ $domain ];
			}
			update_option( self::OPTION_KEY, $versions, false );
			foreach ( $out as $domain => $version ) {
				wp_cache_set( $this->cache_key( $domain ), $version, self::CACHE_GROUP );
			}
			return $out;
		} );
	}

	public function reset_all(): void {
		$this->with_lock( function (): void {
			$versions = array_fill_keys( self::BASE_DOMAINS, 1 );
			update_option( self::OPTION_KEY, $versions, false );
			foreach ( $versions as $domain => $version ) {
				wp_cache_set( $this->cache_key( $domain ), $version, self::CACHE_GROUP );
			}
		} );
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

	private function cache_key( string $domain ): string {
		return 'generation:' . get_current_blog_id() . ':' . $domain;
	}

	/**
	 * Serialize generation writes so concurrent invalidations cannot overwrite
	 * each other. add_option() is atomic at the database unique-key boundary and
	 * therefore also works when Redis is unavailable or restarting.
	 */
	private function with_lock( callable $callback ): mixed {
		$deadline = microtime( true ) + self::LOCK_TIMEOUT_SECONDS;
		do {
			$now = microtime( true );
			if ( add_option( self::LOCK_OPTION, $now + self::LOCK_TIMEOUT_SECONDS, '', false ) ) {
				try {
					return $callback();
				} finally {
					delete_option( self::LOCK_OPTION );
				}
			}

			$expires = (float) get_option( self::LOCK_OPTION, 0 );
			if ( $expires > 0 && $expires < $now ) {
				delete_option( self::LOCK_OPTION );
				continue;
			}
			usleep( 10_000 );
		} while ( microtime( true ) < $deadline );

		throw new \RuntimeException( 'Timed out while updating Headless API cache generations.' );
	}
}
