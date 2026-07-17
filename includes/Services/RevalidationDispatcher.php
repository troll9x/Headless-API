<?php
namespace TLU_Headless_API\Services;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Dispatch webhook revalidation đến Next.js.
 *
 * Sử dụng wp_safe_remote_post() với các security headers.
 */
final class RevalidationDispatcher {

	private RevalidationSigner $signer;
	private const TIMEOUT = 5;

	/**
	 * @param RevalidationSigner|null $signer Optional signer instance.
	 */
	public function __construct( ?RevalidationSigner $signer = null ) {
		$this->signer = $signer ?? new RevalidationSigner();
	}

	/**
	 * Dispatch một event.
	 *
	 * @param array $event Event payload.
	 * @return array {
	 *     @var bool $success
	 *     @var int $http_code
	 *     @var string $error_code
	 *     @var string $error_message
	 * }
	 */
	public function dispatch( array $event ): array {
		$url = $this->get_revalidation_url();
		if ( ! $url ) {
			return [
				'success'      => false,
				'http_code'    => 0,
				'error_code'   => 'no_url_configured',
				'error_message' => 'Revalidation URL not configured.',
			];
		}

		if ( ! $this->is_url_allowed( $url ) ) {
			return [
				'success'      => false,
				'http_code'    => 0,
				'error_code'   => 'invalid_url',
				'error_message' => 'Revalidation URL scheme not allowed.',
			];
		}

		// Encode body
		$body = wp_json_encode( $event );
		if ( false === $body ) {
			return [
				'success'      => false,
				'http_code'    => 0,
				'error_code'   => 'json_encode_error',
				'error_message' => 'Failed to encode event body.',
			];
		}

		// Build headers
		$headers = $this->signer->build_headers( $body, $event );

		// Gửi request
		$response = wp_safe_remote_post( $url, [
			'method'      => 'POST',
			'timeout'     => self::TIMEOUT,
			'redirection' => 0,
			'blocking'    => true,
			'headers'     => $headers,
			'body'        => $body,
			'cookies'     => [],
		] );

		// Parse response
		$http_code = (int) wp_remote_retrieve_response_code( $response );
		$body = wp_remote_retrieve_body( $response );

		if ( is_wp_error( $response ) ) {
			return [
				'success'      => false,
				'http_code'    => 0,
				'error_code'   => $response->get_error_code(),
				'error_message' => $response->get_error_message(),
			];
		}

		return [
			'success'      => $this->is_success_code( $http_code ),
			'http_code'    => $http_code,
			'error_code'   => '',
			'error_message' => '',
		];
	}

	/**
	 * Kiểm tra xem có nên retry không.
	 *
	 * @param array|mixed $response_or_error Response hoặc WP_Error.
	 * @param int $attemptAttempt number (1-indexed).
	 * @return bool
	 */
	public function should_retry( $response_or_error, int $attempt ): bool {
		if ( $attempt >= 5 ) {
			return false;
		}

		if ( is_wp_error( $response_or_error ) ) {
			return true; // Network errors luôn retry
		}

		$http_code = (int) wp_remote_retrieve_response_code( $response_or_error );

		// Success codes
		if ( $http_code >= 200 && $http_code <= 299 ) {
			return false;
		}

		// Permanent failures
		$permanent = [ 400, 401, 403, 404, 405, 410, 422 ];
		if ( in_array( $http_code, $permanent, true ) ) {
			return false;
		}

		// Retryable
		$retryable = [ 408, 425, 429, 500, 502, 503, 504 ];
		return in_array( $http_code, $retryable, true );
	}

	/**
	 * Tính toán delay retry dựa trên attempt number.
	 *
	 * @param int $attempt Attempt number (1-indexed).
	 * @return int Delay in seconds.
	 */
	public function get_retry_delay( int $attempt ): int {
		$base_delays = [
			1 => 0,
			2 => 30,
			3 => 120,
			4 => 600,
			5 => 1800,
		];

		$delay = $base_delays[ $attempt ] ?? 1800;
		$delay = min( $delay, 3600 ); // Hard ceiling 1 hour

		// Thêm jitter nhỏ (0-10%)
		$jitter = (int) ( $delay * 0.1 * mt_rand( 0, 100 ) / 100 );
		$delay += $jitter;

		$delay = (int) apply_filters( 'headless_api_revalidation_retry_delay', $delay, $attempt );
		return max( 0, $delay );
	}

	// ── Private Helpers ────────────────────────────────────────────────────────

	private function get_revalidation_url(): string {
		// Priority 1: Constant
		if ( defined( 'TLU_HEADLESS_REVALIDATION_URL' ) ) {
			return (string) TLU_HEADLESS_REVALIDATION_URL;
		}

		// Priority 2: Filter
		$filtered = (string) apply_filters( 'headless_api_revalidation_url', '' );
		if ( '' !== $filtered ) {
			return $filtered;
		}

		// Priority 3: Option
		$options = \TLU_Headless_API\Config::options();
		return $options['revalidation_url'] ?? '';
	}

	private function is_url_allowed( string $url ): bool {
		// Check scheme
		$parsed = wp_parse_url( $url );
		if ( ! is_array( $parsed ) || empty( $parsed['scheme'] ) ) {
			return false;
		}

		$scheme = strtolower( $parsed['scheme'] );
		if ( ! in_array( $scheme, [ 'http', 'https' ], true ) ) {
			return false;
		}

		// Unless explicitly allowed, require HTTPS
		if ( 'https' !== $scheme && ! $this->is_insecure_allowed() ) {
			return false;
		}

		// Kiểm tra host
		if ( empty( $parsed['host'] ) ) {
			return false;
		}

		// Không cho username/password
		if ( ! empty( $parsed['user'] ) || ! empty( $parsed['pass'] ) ) {
			return false;
		}

		// Không cho dangerous schemes
		$dangerous = [ 'javascript', 'data', 'file', 'ftp', 'gopher', 'file' ];
		if ( in_array( $scheme, $dangerous, true ) ) {
			return false;
		}

		return true;
	}

	private function is_insecure_allowed(): bool {
		return (bool) apply_filters( 'headless_api_allow_insecure_revalidation_url', false );
	}

	private function is_success_code( int $code ): bool {
		return $code >= 200 && $code <= 299;
	}
}