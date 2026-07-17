<?php
namespace TLU_Headless_API\Services;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use TLU_Headless_API\Normalizers\PreviewNormalizer;
use TLU_Headless_API\Integrations\PolylangIntegration;

final class PreviewService {

	private PreviewTokenService $tokens;
	private PreviewNormalizer $normalizer;
	private PolylangIntegration $polylang;

	public function __construct(
		?PreviewTokenService $tokens = null,
		?PreviewNormalizer $normalizer = null,
		?PolylangIntegration $polylang = null
	) {
		$this->tokens     = $tokens ?? new PreviewTokenService();
		$this->normalizer = $normalizer ?? new PreviewNormalizer();
		$this->polylang   = $polylang ?? new PolylangIntegration();
	}

	public function issue_preview_token( int $post_id, string $source = 'current', int $source_id = 0, string $lang = '' ) {
		$source = sanitize_key( $source );
		if ( ! in_array( $source, [ 'current', 'revision', 'autosave' ], true ) ) {
			return new \WP_Error( 'headless_preview_invalid_request', 'Invalid preview source.', [ 'status' => 400 ] );
		}

		$parent = get_post( $post_id );
		if ( ! $parent instanceof \WP_Post ) {
			return new \WP_Error( 'headless_preview_source_not_found', 'Preview source not found.', [ 'status' => 404 ] );
		}

		if ( ! $this->is_allowed_post_type( $parent->post_type ) ) {
			return new \WP_Error( 'headless_preview_post_type_not_allowed', 'Post type not allowed for preview.', [ 'status' => 404 ] );
		}

		if ( ! $this->is_allowed_parent_status( $parent->post_status ) ) {
			return new \WP_Error( 'headless_preview_source_not_found', 'Preview source not found.', [ 'status' => 404 ] );
		}

		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return new \WP_Error( 'headless_preview_forbidden', 'Forbidden.', [ 'status' => 403 ] );
		}

		$language = $this->validate_language( $parent, $lang );
		if ( is_wp_error( $language ) ) {
			return $language;
		}

		$source_post = $this->resolve_source( $parent, $source, $source_id, get_current_user_id() );
		if ( is_wp_error( $source_post ) ) {
			return $source_post;
		}

		$result = $this->tokens->issue( [
			'post_id'   => $post_id,
			'source'    => $source,
			'source_id' => 'current' === $source ? $post_id : (int) $source_post->ID,
			'lang'      => $language,
		] );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$result['preview_url'] = $this->build_preview_url( $result['token'], $post_id );

		return $result;
	}

	public function get_preview( string $token ) {
		$claims = $this->tokens->validate( $token );
		if ( is_wp_error( $claims ) ) {
			return $claims;
		}

		$post_id = (int) $claims['post_id'];
		$user_id = (int) $claims['sub'];

		if ( ! get_userdata( $user_id ) || ! user_can( $user_id, 'edit_post', $post_id ) ) {
			return new \WP_Error( 'headless_preview_forbidden', 'Forbidden.', [ 'status' => 403 ] );
		}

		$parent = get_post( $post_id );
		if ( ! $parent instanceof \WP_Post ) {
			return new \WP_Error( 'headless_preview_source_not_found', 'Preview source not found.', [ 'status' => 404 ] );
		}

		$source_post = $this->resolve_source( $parent, $claims['source'], (int) $claims['source_id'], $user_id );
		if ( is_wp_error( $source_post ) ) {
			return $source_post;
		}

		return $this->normalizer->normalize( [
			'post_id'        => $post_id,
			'parent_post'    => $parent,
			'source_post'    => $source_post,
			'source'         => $claims['source'],
			'source_id'      => (int) $claims['source_id'],
			'issuer_user_id' => $user_id,
			'language'       => $claims['lang'] ?? '',
			'issued_at'      => (int) $claims['iat'],
			'expires_at'     => (int) $claims['exp'],
		] );
	}

	public function revoke_preview_token( string $token ) {
		$claims = $this->tokens->validate( $token );
		if ( is_wp_error( $claims ) ) {
			return true;
		}

		$post_id = (int) $claims['post_id'];
		$issuer = (int) $claims['sub'];
		$current_user = (int) get_current_user_id();

		if ( $current_user !== $issuer && ! current_user_can( 'edit_post', $post_id ) ) {
			return new \WP_Error( 'headless_preview_forbidden', 'Forbidden.', [ 'status' => 403 ] );
		}

		return $this->tokens->revoke( $token );
	}

	public function get_token_service(): PreviewTokenService {
		return $this->tokens;
	}

	private function resolve_source( \WP_Post $parent, string $source, int $source_id, int $issuer_user_id ) {
		if ( 'current' === $source ) {
			if ( $source_id > 0 && $source_id !== (int) $parent->ID ) {
				return new \WP_Error( 'headless_preview_invalid_request', 'source_id mismatch.', [ 'status' => 400 ] );
			}
			return $parent;
		}

		if ( 'revision' === $source ) {
			if ( $source_id <= 0 ) {
				return new \WP_Error( 'headless_preview_invalid_request', 'source_id required.', [ 'status' => 400 ] );
			}
			$revision = wp_get_post_revision( $source_id );
			if ( ! $revision instanceof \WP_Post || (int) $revision->post_parent !== (int) $parent->ID ) {
				return new \WP_Error( 'headless_preview_source_not_found', 'Preview source not found.', [ 'status' => 404 ] );
			}
			return $revision;
		}

		if ( 'autosave' === $source ) {
			if ( $source_id > 0 ) {
				$autosave_parent = wp_is_post_autosave( $source_id );
				if ( (int) $autosave_parent !== (int) $parent->ID ) {
					return new \WP_Error( 'headless_preview_source_not_found', 'Preview source not found.', [ 'status' => 404 ] );
				}
				$autosave = get_post( $source_id );
			} else {
				$autosave = wp_get_post_autosave( $parent->ID, $issuer_user_id );
			}

			if ( ! $autosave instanceof \WP_Post ) {
				return new \WP_Error( 'headless_preview_source_not_found', 'Preview source not found.', [ 'status' => 404 ] );
			}
			return $autosave;
		}

		return new \WP_Error( 'headless_preview_invalid_request', 'Invalid source.', [ 'status' => 400 ] );
	}

	private function is_allowed_parent_status( string $status ): bool {
		return in_array( $status, [ 'publish', 'future', 'draft', 'pending', 'private', 'auto-draft' ], true );
	}

	private function is_allowed_post_type( string $post_type ): bool {
		$blocked = [ 'revision', 'nav_menu_item', 'customize_changeset', 'oembed_cache', 'user_request', 'wp_global_styles', 'wp_navigation' ];
		if ( in_array( $post_type, $blocked, true ) ) {
			return false;
		}

		$obj = get_post_type_object( $post_type );
		if ( ! $obj ) {
			return false;
		}

		$allowed = ! empty( $obj->show_in_rest ) || ! empty( $obj->publicly_queryable );
		$types = (array) apply_filters( 'headless_api_preview_post_types', $allowed ? [ $post_type ] : [] );

		return in_array( $post_type, array_diff( $types, $blocked ), true );
	}

	private function validate_language( \WP_Post $post, string $lang ) {
		$lang = sanitize_key( $lang );
		if ( '' === $lang ) {
			return $this->polylang->is_active() && method_exists( $this->polylang, 'get_post_language' )
				? (string) $this->polylang->get_post_language( $post->ID )
				: '';
		}

		if ( ! $this->polylang->is_active() ) {
			return new \WP_Error( 'headless_preview_language_mismatch', 'Polylang is inactive.', [ 'status' => 400 ] );
		}

		$normalized = $this->polylang->normalize_language( $lang );
		if ( '' === $normalized ) {
			return new \WP_Error( 'headless_preview_language_mismatch', 'Invalid language.', [ 'status' => 400 ] );
		}

		if ( method_exists( $this->polylang, 'get_post_language' ) && $this->polylang->get_post_language( $post->ID ) !== $normalized ) {
			return new \WP_Error( 'headless_preview_language_mismatch', 'Language mismatch.', [ 'status' => 400 ] );
		}

		return $normalized;
	}

	private function build_preview_url( string $token, int $post_id ): string {
		$options = get_option( 'tlu_headless_options', [] );
		$origin = rtrim( (string) ( $options['frontend_url'] ?? '' ), '/' );
		if ( '' === $origin ) {
			return '';
		}
		return add_query_arg( [ 'token' => $token, 'post_id' => $post_id ], $origin . '/api/preview' );
	}
}