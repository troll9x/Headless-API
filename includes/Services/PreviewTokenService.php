<?php
namespace TLU_Headless_API\Services;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use TLU_Headless_API\Cache\TransientCache;
use TLU_Headless_API\Contracts\Service;

/**
 * Quản lý việc phát hành, xác thực và thu hồi token xem trước (preview token).
 *
 * Token được thiết kế dưới dạng signed token (payload.signature) để đảm bảo
 * tính toàn vẹn mà không cần truy vấn database cho mỗi request,
 * kết hợp với một registry ngắn hạn (transient) để hỗ trợ revoke.
 */
final class PreviewTokenService implements Service {

	/**
	 * Thời gian sống mặc định của token (giây).
	 */
	private const DEFAULT_TTL = 300;

	/**
	 * Giới hạn TTL tối thiểu và tối đa.
	 */
	private const MIN_TTL = 30;
	private const MAX_TTL = 900;

	/**
	 * Sai số thời gian cho phép (clock skew).
	 */
	private const CLOCK_SKEW = 30;

	/**
	 * Version của token format.
	 */
	private const TOKEN_VERSION = 1;

	/**
	 * @var TransientCache
	 */
	private $cache;

	public function __construct( ?TransientCache $cache = null ) {
		// The revocation registry is a security control and must remain enabled
		// even when response caching is disabled in the plugin settings.
		$this->cache = $cache ?? new TransientCache( 'headless_', self::DEFAULT_TTL, true );
	}

	/**
	 * Phát hành một preview token mới.
	 *
	 * @param array $claims Các thông tin định danh gắn với token.
	 * @return array| \WP_Error Mảng chứa token và metadata hoặc lỗi.
	 */
	public function issue( array $claims ): array|\WP_Error {
		$ttl = $this->get_ttl();
		$iat = time();
		$exp = $iat + $ttl;
		$jti = $this->generate_jti();
		if ( is_wp_error( $jti ) ) {
			return $jti;
		}

		$payload = [
			'v'         => self::TOKEN_VERSION,
			'jti'       => $jti,
			'iss'       => get_bloginfo( 'url' ),
			'aud'       => 'headless-preview',
			'sub'       => (int) get_current_user_id(),
			'post_id'   => (int) ($claims['post_id'] ?? 0),
			'source'    => sanitize_key( $claims['source'] ?? 'current' ),
			'source_id' => (int) ($claims['source_id'] ?? 0),
			'lang'      => sanitize_key( $claims['lang'] ?? '' ),
			'iat'       => $iat,
			'nbf'       => $iat,
			'exp'       => $exp,
			'schema'    => TLU_HEADLESS_API_SCHEMA_VERSION,
		];

		$token = $this->sign_payload( $payload );

		// Lưu vào registry để hỗ trợ revoke
		if ( ! $this->register_token( $payload ) ) {
			return new \WP_Error(
				'headless_preview_registry_unavailable',
				'Unable to persist the preview token revocation record.',
				[ 'status' => 503 ]
			);
		}

		return [
			'token'      => $token,
			'expires_at' => $exp,
			'expires_in' => $ttl,
			'post_id'    => $payload['post_id'],
			'source'     => $payload['source'],
			'source_id'  => $payload['source_id'],
		];
	}

	/**
	 * Xác thực một token từ client.
	 *
	 * @param string $token Token cần xác thực.
	 * @return array| \WP_Error Claims nếu hợp lệ, hoặc WP_Error.
	 */
	public function validate( string $token ): array|\WP_Error {
		if ( strlen( $token ) > 4096 ) {
			return new \WP_Error( 'headless_preview_invalid_token', 'Preview token is too long.' );
		}

		$payload = $this->unsign_payload( $token );

		if ( is_wp_error( $payload ) ) {
			return $payload;
		}

		// 1. Validate basic claims
		$error = $this->validate_claims( $payload );
		if ( is_wp_error( $error ) ) {
			return $error;
		}

		// 2. Check stateful registry (for revocation)
		if ( ! $this->is_token_in_registry( $payload['jti'], $token ) ) {
			return new \WP_Error( 'headless_preview_revoked_token', 'Token has been revoked or expired in registry.' );
		}

		return $payload;
	}

	/**
	 * Thu hồi một token.
	 *
	 * @param string $token Token cần thu hồi.
	 * @return bool Thành công hay thất bại.
	 */
	public function revoke( string $token ): bool {
		$payload = $this->unsign_payload( $token );

		if ( is_wp_error( $payload ) ) {
			return false;
		}
		if ( empty( $payload['jti'] ) || ! is_string( $payload['jti'] ) || ! preg_match( '/^[a-f0-9]{32}$/', $payload['jti'] ) ) {
			return false;
		}

		return $this->unregister_token( $payload['jti'] );
	}

	/**
	 * Trích xuất token từ Bearer header của request.
	 *
	 * @param \WP_REST_Request $request
	 * @return string Token hoặc chuỗi rỗng.
	 */
	public function extract_bearer_token( $request ): string {
		$auth_header = $request->get_header( 'Authorization' );
		if ( ! $auth_header ) {
			return '';
		}

		if ( preg_match( '/^Bearer\s+([^\s]+)$/i', trim( $auth_header ), $matches ) ) {
			return strlen( $matches[1] ) <= 4096 ? $matches[1] : '';
		}

		return '';
	}

	/**
	 * Lấy TTL hiện tại sau khi áp dụng filter và clamp.
	 *
	 * @return int
	 */
	public function get_ttl(): int {
		$ttl = (int) apply_filters( 'headless_api_preview_token_ttl', self::DEFAULT_TTL );
		return max( self::MIN_TTL, min( self::MAX_TTL, $ttl ) );
	}

	// ── Private Helpers ────────────────────────────────────────────────────────

	private function sign_payload( array $payload ): string {
		$encoded_payload = $this->base64url_encode( json_encode( $payload ) );
		$signature = $this->calculate_signature( $encoded_payload );
		return $encoded_payload . '.' . $signature;
	}

	private function unsign_payload( string $token ): array|\WP_Error {
		$parts = explode( '.', $token );
		if ( count( $parts ) !== 2 ) {
			return new \WP_Error( 'headless_preview_invalid_token', 'Malformed token structure.' );
		}

		$encoded_payload = $parts[0];
		$signature = $parts[1];

		if ( ! hash_equals( $this->calculate_signature( $encoded_payload ), $signature ) ) {
			return new \WP_Error( 'headless_preview_invalid_token', 'Invalid token signature.' );
		}

		$payload_json = $this->base64url_decode( $encoded_payload );
		$payload = json_decode( $payload_json, true );

		if ( ! is_array( $payload ) ) {
			return new \WP_Error( 'headless_preview_invalid_token', 'Invalid payload JSON.' );
		}

		return $payload;
	}

	private function calculate_signature( string $data ): string {
		$secret = wp_salt( 'auth' ) . 'tlu-headless-preview-v1';
		return $this->base64url_encode( hash_hmac( 'sha256', $data, $secret, true ) );
	}

	private function validate_claims( array $payload ): ?\WP_Error {
		$now = time();

		if ( ( ! isset( $payload['v'] ) ) || $payload['v'] !== self::TOKEN_VERSION ) {
			return new \WP_Error( 'headless_preview_invalid_token', 'Unsupported token version.' );
		}

		if ( ( ! isset( $payload['aud'] ) ) || $payload['aud'] !== 'headless-preview' ) {
			return new \WP_Error( 'headless_preview_invalid_token', 'Invalid token audience.' );
		}

		$expected_issuer = untrailingslashit( (string) get_bloginfo( 'url' ) );
		$actual_issuer   = untrailingslashit( (string) ( $payload['iss'] ?? '' ) );
		if ( '' === $actual_issuer || ! hash_equals( $expected_issuer, $actual_issuer ) ) {
			return new \WP_Error( 'headless_preview_invalid_token', 'Invalid token issuer.' );
		}

		if ( empty( $payload['sub'] ) || empty( $payload['post_id'] ) ) {
			return new \WP_Error( 'headless_preview_invalid_token', 'Invalid token subject.' );
		}

		if ( ! isset( $payload['jti'] ) || ! is_string( $payload['jti'] ) || ! preg_match( '/^[a-f0-9]{32}$/', $payload['jti'] ) ) {
			return new \WP_Error( 'headless_preview_invalid_token', 'Invalid token identifier.' );
		}

		if ( ! in_array( (string) ( $payload['source'] ?? '' ), [ 'current', 'revision', 'autosave' ], true ) ) {
			return new \WP_Error( 'headless_preview_invalid_token', 'Invalid preview source.' );
		}

		if ( ( ! isset( $payload['schema'] ) ) || $payload['schema'] !== TLU_HEADLESS_API_SCHEMA_VERSION ) {
			return new \WP_Error( 'headless_preview_invalid_token', 'Token schema mismatch.' );
		}

		if ( ! isset( $payload['exp'] ) || $payload['exp'] < ( $now - self::CLOCK_SKEW ) ) {
			return new \WP_Error( 'headless_preview_expired_token', 'Token has expired.' );
		}

		if ( isset( $payload['nbf'] ) && $payload['nbf'] > ( $now + self::CLOCK_SKEW ) ) {
			return new \WP_Error( 'headless_preview_invalid_token', 'Token not yet valid.' );
		}

		if ( isset( $payload['iat'] ) && $payload['iat'] > ( $now + self::CLOCK_SKEW ) ) {
			return new \WP_Error( 'headless_preview_invalid_token', 'Token issued in the future.' );
		}

		if ( isset( $payload['exp'] ) && isset( $payload['iat'] ) && $payload['exp'] <= $payload['iat'] ) {
			return new \WP_Error( 'headless_preview_invalid_token', 'Invalid token lifetime.' );
		}

		return null;
	}

	private function register_token( array $payload ): bool {
		$jti = $payload['jti'];
		$key = 'headless_preview_token:' . hash( 'sha256', $jti );

		$registry_data = [
			'token_hash' => hash( 'sha256', $this->sign_payload( $payload ) ),
			'user_id'    => $payload['sub'],
			'post_id'    => $payload['post_id'],
			'expires_at' => $payload['exp'],
			'revoked'    => false,
		];

		return $this->cache->set( $key, $registry_data, $payload['exp'] - time() );
	}

	private function is_token_in_registry( string $jti, string $raw_token ): bool {
		$key = 'headless_preview_token:' . hash( 'sha256', $jti );
		$data = $this->cache->get( $key );

		if ( ! is_array( $data ) ) {
			return false;
		}

		if ( ! empty( $data['revoked'] ) ) {
			return false;
		}

		$stored_hash = (string) ( $data['token_hash'] ?? '' );
		if ( '' === $stored_hash || ! hash_equals( $stored_hash, hash( 'sha256', $raw_token ) ) ) {
			return false;
		}

		return true;
	}

	private function unregister_token( string $jti ): bool {
		$key = 'headless_preview_token:' . hash( 'sha256', $jti );
		return $this->cache->delete( $key );
	}

	private function generate_jti(): string|\WP_Error {
		try {
			return bin2hex( random_bytes( 16 ) );
		} catch ( \Throwable $e ) {
			return new \WP_Error(
				'headless_preview_entropy_unavailable',
				'Unable to generate a cryptographically secure preview token.',
				[ 'status' => 503 ]
			);
		}
	}

	private function base64url_encode( string $data ): string {
		return rtrim( strtr( base64_encode( $data ), '+/', '-_' ), '=' );
	}

	private function base64url_decode( string $data ): string {
		$remainder = strlen( $data ) % 4;
		if ( $remainder ) {
			$padlen = 4 - $remainder;
			$data .= str_repeat( '=', $padlen );
		}
		$decoded = base64_decode( strtr( $data, '-_', '+/' ), true );
		return false === $decoded ? '' : $decoded;
	}
}
