<?php
namespace TLU_Headless_API\Services;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use TLU_Headless_API\Config;

/** Resolves and validates the outbound revalidation webhook configuration. */
final class RevalidationConfig {
	public function url(): string {
		if ( defined( 'TLU_HEADLESS_REVALIDATION_URL' ) ) {
			$url = (string) TLU_HEADLESS_REVALIDATION_URL;
			if ( '' !== trim( $url ) ) {
				return trim( $url );
			}
		}

		$filtered = (string) apply_filters( 'headless_api_revalidation_url', '' );
		if ( '' !== trim( $filtered ) ) {
			return trim( $filtered );
		}

		return trim( (string) ( Config::options()['revalidation_url'] ?? '' ) );
	}

	public function secret(): string {
		if ( defined( 'TLU_HEADLESS_REVALIDATION_SECRET' ) ) {
			$secret = (string) TLU_HEADLESS_REVALIDATION_SECRET;
			if ( '' !== $secret ) {
				return $secret;
			}
		}

		$filtered = (string) apply_filters( 'headless_api_revalidation_secret', '' );
		if ( '' !== $filtered ) {
			return $filtered;
		}

		return (string) ( Config::options()['revalidation_secret'] ?? '' );
	}

	public function is_configured(): bool {
		return '' !== $this->secret() && $this->is_url_allowed( $this->url() );
	}

	/** Reject non-HTTPS, credentialed and private-network destinations by default. */
	public function is_url_allowed( string $url ): bool {
		$parts = wp_parse_url( trim( $url ) );
		if ( ! is_array( $parts ) || empty( $parts['scheme'] ) || empty( $parts['host'] ) ) {
			return false;
		}

		$scheme = strtolower( (string) $parts['scheme'] );
		if ( 'https' !== $scheme ) {
			if ( 'http' !== $scheme || ! (bool) apply_filters( 'headless_api_allow_insecure_revalidation_url', false ) ) {
				return false;
			}
		}

		if ( ! empty( $parts['user'] ) || ! empty( $parts['pass'] ) ) {
			return false;
		}

		$host          = strtolower( trim( (string) $parts['host'], '[]' ) );
		$allow_private = (bool) apply_filters( 'headless_api_allow_private_revalidation_url', false, $url );
		if ( ! $allow_private && $this->is_private_or_reserved_host( $host ) ) {
			return false;
		}

		if ( ! $allow_private && function_exists( 'wp_http_validate_url' ) && false === wp_http_validate_url( $url ) ) {
			return false;
		}

		return true;
	}

	private function is_private_or_reserved_host( string $host ): bool {
		if ( '' === $host || 'localhost' === $host || str_ends_with( $host, '.localhost' ) ) {
			return true;
		}

		if ( filter_var( $host, FILTER_VALIDATE_IP ) ) {
			return false === filter_var(
				$host,
				FILTER_VALIDATE_IP,
				FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
			);
		}

		$addresses = function_exists( 'gethostbynamel' ) ? gethostbynamel( $host ) : false;
		if ( is_array( $addresses ) ) {
			foreach ( $addresses as $address ) {
				if ( false === filter_var(
					$address,
					FILTER_VALIDATE_IP,
					FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
				) ) {
					return true;
				}
			}
		}

		return false;
	}
}
