<?php
namespace TLU_Headless_API\Services;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use WP_REST_Request;

/** Fixed-window limiter with atomic Redis/object-cache increments when available. */
final class RateLimiter {
	private const CACHE_GROUP = 'tlu_headless_rate_limit';
	public const CLEANUP_HOOK = 'tlu_headless_rate_limit_cleanup';

	/** @return array{allowed:bool,limit:int,remaining:int,retry_after:int,reset:int} */
	public function consume( WP_REST_Request $request, string $bucket, int $limit, int $window_seconds, ?string $trusted_identity = null ): array {
		$limit          = min( 1000, max( 1, $limit ) );
		$window_seconds = min( 3600, max( 10, $window_seconds ) );
		$now            = time();
		$window_start   = intdiv( $now, $window_seconds ) * $window_seconds;
		$reset          = $window_start + $window_seconds;
		$key            = $this->key( $request, $bucket, $window_start, $trusted_identity );
		$ttl            = max( 1, $reset - $now + 1 );

		if ( wp_using_ext_object_cache() ) {
			if ( wp_cache_add( $key, 1, self::CACHE_GROUP, $ttl ) ) {
				$count = 1;
			} else {
				$count = wp_cache_incr( $key, 1, self::CACHE_GROUP );
				if ( false === $count ) {
					wp_cache_set( $key, 1, self::CACHE_GROUP, $ttl );
					$count = 1;
				}
			}
		} else {
			$count = $this->consume_database_counter( $key, $reset );
		}

		return [
			'allowed'     => $count <= $limit,
			'limit'       => $limit,
			'remaining'   => max( 0, $limit - $count ),
			'retry_after' => max( 1, $reset - $now ),
			'reset'       => $reset,
		];
	}

	/** Atomic database fallback for installations without Redis/object cache. */
	private function consume_database_counter( string $key, int $reset ): int {
		global $wpdb;

		$hash        = substr( hash( 'sha256', $key ), 0, 40 );
		$counter_key = 'tlu_rl_' . $hash;
		$expiry_key  = 'tlu_rl_exp_' . $hash;
		$expires_at  = (int) get_option( $expiry_key, 0 );

		if ( $expires_at <= time() ) {
			delete_option( $counter_key );
			delete_option( $expiry_key );
		}

		if ( add_option( $counter_key, 1, '', false ) ) {
			add_option( $expiry_key, $reset + 1, '', false );
			return 1;
		}

		$updated = $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$wpdb->options} SET option_value = CAST(option_value AS UNSIGNED) + 1 WHERE option_name = %s",
				$counter_key
			)
		);
		if ( 1 !== $updated ) {
			return max( 1, (int) get_option( $counter_key, 1 ) );
		}

		wp_cache_delete( $counter_key, 'options' );
		return max( 1, (int) get_option( $counter_key, 1 ) );
	}

	/** Remove expired fallback counters in bounded batches, including rows from older plugin versions. */
	public function cleanup_expired(): void {
		global $wpdb;
		$pattern = $wpdb->esc_like( 'tlu_rl_exp_' ) . '%';
		for ( $batch = 0; $batch < 10; $batch++ ) {
			$expired = $wpdb->get_col( $wpdb->prepare(
				"SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s AND CAST(option_value AS UNSIGNED) <= %d LIMIT 500",
				$pattern,
				time()
			) );
			if ( ! is_array( $expired ) || [] === $expired ) {
				return;
			}
			$removed = 0;
			foreach ( $expired as $expiry_key ) {
				if ( ! preg_match( '/^tlu_rl_exp_([a-f0-9]{40})$/', (string) $expiry_key, $matches ) ) {
					continue;
				}
				delete_option( 'tlu_rl_' . $matches[1] );
				delete_option( $expiry_key );
				$removed++;
			}
			if ( 0 === $removed || count( $expired ) < 500 ) {
				return;
			}
		}
	}

	private function key( WP_REST_Request $request, string $bucket, int $window_start, ?string $trusted_identity ): string {
		$address = isset( $_SERVER['REMOTE_ADDR'] ) ? (string) wp_unslash( $_SERVER['REMOTE_ADDR'] ) : 'unknown';
		if ( false === filter_var( $address, FILTER_VALIDATE_IP ) ) {
			$address = 'unknown';
		}
		$identity = (string) apply_filters( 'headless_api_rate_limit_identity', $trusted_identity ?? $address, $request );
		$identity = hash_hmac( 'sha256', $identity, wp_salt( 'nonce' ) );

		return sanitize_key( $bucket ) . ':' . $window_start . ':' . $identity;
	}
}
