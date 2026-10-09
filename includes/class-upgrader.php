<?php
namespace TLU_Headless_API;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use TLU_Headless_API\Services\CacheVersionStore;

/** Non-destructive, idempotent plugin upgrade coordinator. */
final class Upgrader {
	private const VERSION_OPTION = 'tlu_headless_api_version';

	public static function run(): void {
		$previous = (string) get_option( self::VERSION_OPTION, '' );
		$current  = Config::version();

		if ( $previous === $current ) {
			return;
		}

		// Version 2.0.x keeps all existing option keys and response contracts.
		// Bumping generations prevents responses produced by an older plugin
		// binary from surviving the upgrade in Redis-later cache layers.
		try {
			( new CacheVersionStore() )->bump_many( [
				'global',
				'content',
				'menus',
				'options',
				'search',
				'schema',
			] );
		} catch ( \Throwable $error ) {
			// An unavailable cache backend must not make WordPress unavailable.
			do_action( 'headless_api_upgrade_cache_error', $error, $previous, $current );
		}

		update_option( self::VERSION_OPTION, $current, false );
		do_action( 'headless_api_upgraded', $previous, $current );
	}
}
