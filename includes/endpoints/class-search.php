<?php
namespace TLU_Headless_API\Endpoints;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use TLU_Headless_API\Response;
use TLU_Headless_API\Services\SearchService;
use TLU_Headless_API\Services\RateLimiter;

/** Public headless bridge for the custom WPX FULLTEXT search backend. */
class Search {

	private SearchService $service;
	private RateLimiter $limiter;

	public function __construct( ?SearchService $service = null, ?RateLimiter $limiter = null ) {
		$this->service = $service ?? new SearchService();
		$this->limiter = $limiter ?? new RateLimiter();
	}

	public function register_routes(): void {
		register_rest_route( HEADLESS_API_NAMESPACE, '/search', [
			'methods'             => \WP_REST_Server::READABLE,
			'callback'            => [ $this, 'handle_search' ],
			'permission_callback' => '__return_true',
			'args'                => $this->search_args(),
		] );

		register_rest_route( HEADLESS_API_NAMESPACE, '/suggest', [
			'methods'             => \WP_REST_Server::READABLE,
			'callback'            => [ $this, 'handle_suggest' ],
			'permission_callback' => '__return_true',
			'args'                => $this->suggest_args(),
		] );
	}

	public function handle_search( \WP_REST_Request $request ) {
		$limited = $this->enforce_rate_limit( $request, 'search', 60 );
		if ( $limited ) {
			return $limited;
		}

		$query = trim( (string) $request->get_param( 'q' ) );
		if ( mb_strlen( $query ) < $this->service->min_chars() ) {
			return Response::bad_request( sprintf( 'Search query must contain at least %d characters.', $this->service->min_chars() ) );
		}

		$result = $this->service->search(
			$query,
			(int) $request->get_param( 'per' ),
			(int) $request->get_param( 'page' ),
			(string) $request->get_param( 'lang' )
		);

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return Response::success( $result );
	}

	public function handle_suggest( \WP_REST_Request $request ) {
		$limited = $this->enforce_rate_limit( $request, 'suggest', 120 );
		if ( $limited ) {
			return $limited;
		}

		$query = trim( (string) $request->get_param( 'q' ) );
		$length = mb_strlen( $query );
		if ( 0 === $length || $length >= $this->service->min_chars() ) {
			return Response::bad_request( sprintf( 'Suggest query must contain 1 to %d characters.', $this->service->min_chars() - 1 ) );
		}

		$result = $this->service->suggest( $query );
		return is_wp_error( $result ) ? $result : Response::success( $result );
	}

	private function search_args(): array {
		return [
			'q' => [
				'required'          => true,
				'sanitize_callback' => 'sanitize_text_field',
				'validate_callback' => fn( $value ) => is_string( $value ) && mb_strlen( trim( $value ) ) <= 200,
			],
			'per' => [
				'default'           => 8,
				'sanitize_callback' => 'absint',
				'validate_callback' => fn( $value ) => is_numeric( $value ) && (int) $value >= 1 && (int) $value <= 50,
			],
			'page' => [
				'default'           => 1,
				'sanitize_callback' => 'absint',
				'validate_callback' => fn( $value ) => is_numeric( $value ) && (int) $value >= 1,
			],
			'lang' => [
				'required'          => false,
				'default'           => '',
				'sanitize_callback' => fn( $value ) => in_array( (string) $value, [ 'vi', 'en' ], true ) ? (string) $value : '',
				'validate_callback' => fn( $value ) => '' === $value || in_array( (string) $value, [ 'vi', 'en' ], true ),
			],
		];
	}

	private function suggest_args(): array {
		return [
			'q' => [
				'required'          => true,
				'sanitize_callback' => 'sanitize_text_field',
				'validate_callback' => fn( $value ) => is_string( $value ) && mb_strlen( trim( $value ) ) <= 200,
			],
		];
	}

	private function enforce_rate_limit( \WP_REST_Request $request, string $bucket, int $default_limit ): ?\WP_REST_Response {
		$limit = (int) apply_filters( 'headless_api_' . $bucket . '_rate_limit', $default_limit );
		$state = $this->limiter->consume( $request, $bucket, $limit, 60, $this->verified_proxy_identity( $request ) );
		if ( $state['allowed'] ) {
			return null;
		}

		$response = new \WP_REST_Response( [
			'code'    => 'rate_limit_exceeded',
			'message' => 'Too many requests.',
		], 429 );
		$response->header( 'Retry-After', (string) $state['retry_after'] );
		$response->header( 'RateLimit-Limit', (string) $state['limit'] );
		$response->header( 'RateLimit-Remaining', '0' );
		$response->header( 'RateLimit-Reset', (string) $state['reset'] );

		return $response;
	}

	/** Use the frontend's client identity only when a short-lived HMAC proves its origin. */
	private function verified_proxy_identity( \WP_REST_Request $request ): ?string {
		$secret = defined( 'TLU_HEADLESS_SEARCH_PROXY_SECRET' ) ? (string) TLU_HEADLESS_SEARCH_PROXY_SECRET : '';
		if ( strlen( $secret ) < 32 ) {
			return null;
		}
		$client = (string) $request->get_header( 'x-headless-search-client' );
		$timestamp = (string) $request->get_header( 'x-headless-search-timestamp' );
		$signature = (string) $request->get_header( 'x-headless-search-signature' );
		if ( ! preg_match( '/^[a-f0-9]{64}$/', $client ) || ! preg_match( '/^[0-9]{10}$/', $timestamp ) || ! preg_match( '/^[a-f0-9]{64}$/', $signature ) ) {
			return null;
		}
		if ( abs( time() - (int) $timestamp ) > 60 ) {
			return null;
		}
		$expected = hash_hmac( 'sha256', 'search-client:' . $timestamp . ':' . $client, $secret );
		return hash_equals( $expected, $signature ) ? 'proxy:' . $client : null;
	}

}
