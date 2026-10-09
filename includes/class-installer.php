<?php
namespace TLU_Headless_API;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( __NAMESPACE__ . '\\Installer', false ) ) {
	/** Handles activation without loading application classes from duplicate copies. */
	final class Installer {
		private const NOTICE_KEY = 'tlu_headless_api_duplicate_plugins_deactivated';

		private static string $plugin_file = '';

		public static function register( string $plugin_file ): void {
			self::$plugin_file = $plugin_file;

			add_action( 'activated_plugin', [ self::class, 'after_plugin_activated' ], PHP_INT_MAX, 2 );
			add_action( 'plugins_loaded', [ self::class, 'cleanup_active_duplicates' ], PHP_INT_MAX );
			add_action( 'admin_notices', [ self::class, 'render_duplicate_notice' ] );
			add_action( 'network_admin_notices', [ self::class, 'render_duplicate_notice' ] );
		}

		/**
		 * Detect duplicate basenames before application classes are loaded.
		 * This keeps the current request alive regardless of plugin load order.
		 */
		public static function has_active_duplicate( string $plugin_file ): bool {
			$current = plugin_basename( $plugin_file );
			$active  = (array) get_option( 'active_plugins', [] );
			if ( ! function_exists( 'get_plugins' ) ) {
				require_once ABSPATH . 'wp-admin/includes/plugin.php';
			}
			$installed = get_plugins();

			if ( is_multisite() ) {
				$active = array_merge(
					$active,
					array_keys( (array) get_site_option( 'active_sitewide_plugins', [] ) )
				);
			}

			foreach ( array_unique( $active ) as $basename ) {
				$basename = plugin_basename( (string) $basename );
				if (
					$basename !== $current
					&& self::is_headless_api( (array) ( $installed[ $basename ] ?? [] ) )
				) {
					return true;
				}
			}

			return false;
		}

		/** Remove duplicates discovered during a normal request or silent update. */
		public static function cleanup_active_duplicates(): void {
			$current = plugin_basename( self::$plugin_file );
			$removed = [];

			if ( in_array( $current, (array) get_option( 'active_plugins', [] ), true ) ) {
				$site_duplicates = self::find_duplicate_plugins( $current, false );
				if ( ! empty( $site_duplicates ) ) {
					deactivate_plugins( $site_duplicates, true, false );
					$removed = array_merge( $removed, $site_duplicates );
				}
			}

			$network_plugins = is_multisite()
				? (array) get_site_option( 'active_sitewide_plugins', [] )
				: [];
			$network_active = isset( $network_plugins[ $current ] );
			if ( $network_active ) {
				$network_duplicates = self::find_duplicate_plugins( $current, true );
				if ( ! empty( $network_duplicates ) ) {
					deactivate_plugins( $network_duplicates, true, true );
					$removed = array_merge( $removed, $network_duplicates );
				}
			}

			self::store_duplicate_notice( $removed );
		}

		/** Deactivate older copies only after WordPress has activated this basename. */
		public static function after_plugin_activated( string $plugin, bool $network_wide ): void {
			$current = plugin_basename( self::$plugin_file );
			if ( $plugin !== $current ) {
				return;
			}

			$duplicates = self::find_duplicate_plugins( $current, $network_wide );
			if ( empty( $duplicates ) ) {
				return;
			}

			if ( ! function_exists( 'deactivate_plugins' ) ) {
				require_once ABSPATH . 'wp-admin/includes/plugin.php';
			}

			deactivate_plugins( $duplicates, true, $network_wide );
			self::store_duplicate_notice( $duplicates );
		}

		/** @return string[] Plugin basenames for other Headless API installations. */
		private static function find_duplicate_plugins( string $current, bool $network_wide ): array {
			if ( ! function_exists( 'get_plugins' ) ) {
				require_once ABSPATH . 'wp-admin/includes/plugin.php';
			}

			$duplicates = [];
			foreach ( get_plugins() as $basename => $headers ) {
				if ( $basename === $current ) {
					continue;
				}

				$is_active = $network_wide
					? is_plugin_active_for_network( $basename )
					: in_array( $basename, (array) get_option( 'active_plugins', [] ), true );
				if ( ! $is_active ) {
					continue;
				}

				if ( self::is_headless_api( (array) $headers ) ) {
					$duplicates[] = plugin_basename( $basename );
				}
			}

			return array_values( array_unique( $duplicates ) );
		}

		private static function is_headless_api( array $headers ): bool {
			$name        = trim( (string) ( $headers['Name'] ?? '' ) );
			$text_domain = trim( (string) ( $headers['TextDomain'] ?? '' ) );

			return 'Headless API' === $name || 'tlu-headless-api' === $text_domain;
		}

		private static function store_duplicate_notice( array $duplicates ): void {
			$duplicates = array_values( array_unique( $duplicates ) );
			if ( ! empty( $duplicates ) ) {
				set_transient( self::NOTICE_KEY, $duplicates, 5 * MINUTE_IN_SECONDS );
			}
		}

		public static function render_duplicate_notice(): void {
			// A deactivated legacy copy remains loaded until the current request
			// ends. Delay deleting the notice transient until the new application
			// version is the one actually serving the request.
			if (
				! defined( 'TLU_HEADLESS_API_VERSION' )
				|| version_compare( TLU_HEADLESS_API_VERSION, '2.0.2', '<' )
			) {
				return;
			}

			$duplicates = get_transient( self::NOTICE_KEY );
			if ( ! is_array( $duplicates ) || empty( $duplicates ) ) {
				return;
			}

			delete_transient( self::NOTICE_KEY );
			printf(
				'<div class="notice notice-warning is-dismissible"><p>%s</p></div>',
				esc_html(
					sprintf(
						'Headless API 2.0.2 đã vô hiệu hóa %d bản cài đặt trùng. Bạn có thể xóa các thư mục cũ sau khi kiểm tra website.',
						count( $duplicates )
					)
				)
			);
		}
	}
}
