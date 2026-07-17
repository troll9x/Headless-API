<?php
namespace TLU_Headless_API\Services;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Ký và xác thực webhook revalidation event.
 *
 * Sử dụng HMAC-SHA256 trên chuỗi: {timestamp}.{event_id}.{raw_body}
 */
final class RevalidationSigner {

	/**
	 * Tính signature của một event.
	 *
	 * @param string $raw_body JSON-encoded body của event.
	 * @param int    $timestamp Unix timestamp của event.
	 * @param string $event_id Unique identifier của event.
	 * @return string Base64-encoded HMAC signature.
	 */
	public function sign( string $raw_body, int $timestamp, string $event_id ): string {
		$secret = $this->get_secret();
		$canonical = $timestamp . '.' . $event_id . '.' . $raw_body;
		return base64_encode( hash_hmac( 'sha256', $canonical, $secret, true ) );
	}

	/**
	 * Tính signature dạng hex để so sánh bằng hash_equals.
	 *
	 * @param string $raw_body JSON-encoded body.
	 * @param int    $timestamp Unix timestamp.
	 * @param string $event_id Event ID.
	 * @return string Hex signature.
	 */
	public function sign_hex( string $raw_body, int $timestamp, string $event_id ): string {
		$secret = $this->get_secret();
		$canonical = $timestamp . '.' . $event_id . '.' . $raw_body;
		return hash_hmac( 'sha256', $canonical, $secret );
	}

	/**
	 * Xây dựng toàn bộ request headers cần thiết.
	 *
	 * @param string $raw_body JSON-encoded body.
	 * @param array  $event Event data (để lấy event_id và timestamp).
	 * @return array Headers array.
	 */
	public function build_headers( string $raw_body, array $event ): array {
		$timestamp = $event['occurred_at'] ?? time();
		$event_id  = $event['event_id'] ?? '';

		$signature = $this->sign( $raw_body, $timestamp, $event_id );

		return [
			'Content-Type'           => 'application/json',
			'User-Agent'             => 'TLU-Headless-API/' . \TLU_HEADLESS_API_VERSION,
			'X-Headless-Event-ID'    => $event_id,
			'X-Headless-Timestamp'   => (string) $timestamp,
			'X-Headless-Signature'   => 'sha256=' . $signature,
			'X-Headless-Schema'      => \TLU_HEADLESS_API_SCHEMA_VERSION,
		];
	}

	/**
	 * Lấy secret từ config (constants > filters > option).
	 *
	 * @return string Secret.
	 */
	private function get_secret(): string {
		// Priority 1: Constant
		if ( defined( 'TLU_HEADLESS_REVALIDATION_SECRET' ) ) {
			return (string) TLU_HEADLESS_REVALIDATION_SECRET;
		}

		// Priority 2: Filter
		$filtered = (string) apply_filters( 'headless_api_revalidation_secret', '' );
		if ( '' !== $filtered ) {
			return $filtered;
		}

		// Priority 3: Option
		$options = \TLU_Headless_API\Config::options();
		return $options['revalidation_secret'] ?? '';
	}
}