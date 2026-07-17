<?php
/**
 * Permalink Manager Integration
 *
 * Integrates with Permalink Manager plugin to resolve custom URIs.
 *
 * @package TLU_Headless_API
 */

namespace TLU_Headless_API\Integrations;

use TLU_Headless_API\Contracts\IntegrationInterface;

/**
 * PermalinkManagerIntegration provides URI resolution via Permalink Manager plugin.
 */
final class PermalinkManagerIntegration implements IntegrationInterface {

	/**
	 * Check if Permalink Manager is active.
	 *
	 * @return bool
	 */
	public function is_active(): bool {
		if ( ! function_exists( 'is_plugin_active' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		return is_plugin_active( 'permalink-manager/permalink-manager.php' );
	}

	/**
	 * Resolve URI to post ID using Permalink Manager.
	 *
	 * @param string $uri URI (with leading slash).
	 * @param array $post_types Allowed post types.
	 * @return int Post ID or 0 if not found.
	 */
	public function resolve_uri( string $uri, array $post_types = [] ): int {
		if ( ! $this->is_active() ) {
			return 0;
		}

		// Check if Permalink Manager API exists
		if ( ! function_exists( 'pm_get_post_uri' ) && ! class_exists( 'Permalink_Manager' ) ) {
			return 0;
		}

		// Try to use Permalink Manager public functions or hooks
		$post_id = apply_filters(
			'headless_api_resolve_permalink',
			0,
			$uri,
			$post_types,
			$this
		);

		if ( $post_id > 0 ) {
			return (int) $post_id;
		}

		// If no direct API available, return 0 and let fallback handle it
		return 0;
	}
}