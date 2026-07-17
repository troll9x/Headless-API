<?php
namespace TLU_Headless_API\Integrations;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use TLU_Headless_API\Contracts\IntegrationInterface;

final class PreviewLinkIntegration implements IntegrationInterface {

	private \PreviewTokenService $tokens;

	public function __construct( \PreviewTokenService $tokens ) {
		$this->tokens = $tokens;
	}

	public function is_active(): bool {
		$options = get_option( 'tlu_headless_options', [] );
		$origin  = (string) ( $options['frontend_url'] ?? '' );
		return '' !== $origin && ! is_admin_bar_showing();
	}

	public function supports_headless(): bool {
		return true;
	}

	public function get_title( \WP_Post $post ): string {
		return '';
	}

	public function get_description( \WP_Post $post ): string {
		return '';
	}

	public function get_canonical( \WP_Post $post ): string {
		return '';
	}

	public function get_robots( \WP_Post $post ): array {
		return [];
	}

	public function get_open_graph( \WP_Post $post ): array {
		return [];
	}

	public function get_twitter( \WP_Post $post ): array {
		return [];
	}

	public function get_schema( \WP_Post $post ): array {
		return [];
	}

	public function get_breadcrumbs( \WP_Post $post ): array {
		return [];
	}

	public function get_seo( \WP_Post $post ): array {
		return [];
	}

	public function get_schema_json( int $post_id ): string {
		return '';
	}

	public function get_schema_type( int $post_id ): string {
		return '';
	}

	public function expand_variables( string $value, \WP_Post $post ): string {
		return $value;
	}

	public function register_hooks(): void {
		add_filter( 'preview_post_link', [ $this, 'modify_preview_link' ], 10, 2 );
	}

	public function modify_preview_link( string $preview_url, \WP_Post $post ): string {
		if ( ! $this->is_active() ) {
			return $preview_url;
		}

		if ( ! is_user_logged_in() ) {
			return $preview_url;
		}

		if ( ! current_user_can( 'edit_post', (int) $post->ID ) ) {
			return $preview_url;
		}

		$options = get_option( 'tlu_headless_options', [] );
		$origin  = (string) ( $options['frontend_url'] ?? '' );
		if ( '' === $origin ) {
			return $preview_url;
		}

		$token = $this->try_issue_token_for_post( (int) $post->ID );
		if ( is_wp_error( $token ) || ! $token ) {
			return $preview_url;
		}

		$origin = rtrim( esc_url_raw( $origin ), '/' );
		$url    = esc_url_raw( add_query_arg( [ 'token' => $token, 'post_id' => (int) $post->ID ], $origin . '/api/preview' ) );

		return apply_filters( 'headless_api_frontend_preview_url', $url, $post );
	}

	private function try_issue_token_for_post( int $post_id ): string {
		$result = $this->tokens->issue( [
			'post_id'   => $post_id,
			'source'    => 'current',
			'source_id' => $post_id,
		] );

		if ( is_wp_error( $result ) ) {
			return '';
		}

		return $result['token'] ?? '';
	}
}