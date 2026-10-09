<?php
namespace TLU_Headless_API\Endpoints;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use TLU_Headless_API\Response;
use TLU_Headless_API\Services\RevalidationEventBuilder;
use TLU_Headless_API\Services\RevalidationQueue;
use TLU_Headless_API\Services\RevalidationDispatcher;
use TLU_Headless_API\Services\RevalidationConfig;

/**
 * Admin endpoints cho quản lý revalidation webhook.
 *
 * GET  /headless/v1/revalidation/status
 * POST /headless/v1/revalidation/test
 * POST /headless/v1/revalidation/retry
 */
class Revalidation {

	private RevalidationEventBuilder $builder;
	private RevalidationQueue $queue;
	private RevalidationDispatcher $dispatcher;
	private RevalidationConfig $config;

	public function __construct( ?RevalidationConfig $config = null ) {
		$this->config = $config ?? new RevalidationConfig();
		$this->builder = new RevalidationEventBuilder();
		$this->queue   = new RevalidationQueue();
		$this->dispatcher = new RevalidationDispatcher( null, $this->config );
	}

	public function register_routes(): void {
		// Status endpoint
		register_rest_route(
			HEADLESS_API_NAMESPACE,
			'/revalidation/status',
			[
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => [ $this, 'handle_status' ],
				'permission_callback' => [ $this, 'permission_manage_options' ],
			]
		);

		// Test endpoint
		register_rest_route(
			HEADLESS_API_NAMESPACE,
			'/revalidation/test',
			[
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => [ $this, 'handle_test' ],
				'permission_callback' => [ $this, 'permission_manage_options' ],
			]
		);

		// Retry endpoint
		register_rest_route(
			HEADLESS_API_NAMESPACE,
			'/revalidation/retry',
			[
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => [ $this, 'handle_retry' ],
				'permission_callback' => [ $this, 'permission_manage_options' ],
				'args'                => [
					'event_id' => [
						'required'          => true,
						'sanitize_callback' => 'sanitize_text_field',
						'validate_callback' => fn( $v ) => is_string( $v ) && strlen( $v ) > 0,
						'description'       => 'Event ID cần retry.',
					],
				],
			]
		);
	}

	// ── Endpoints ──────────────────────────────────────────────────────────────

	public function handle_status( \WP_REST_Request $request ): \WP_REST_Response {
		return Response::success( [
			'enabled'       => $this->is_configured(),
			'configured'    => $this->is_configured(),
			'queue_size'    => $this->queue->get_size(),
			'last_delivery' => $this->get_last_delivery(),
		] );
	}

	public function handle_test( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		if ( ! $this->config->is_configured() ) {
			return Response::error( 'revalidation_not_configured', 'A valid HTTPS URL and non-empty secret are required.', 503 );
		}

		// Build và enqueue test event
		$payload = $this->builder->build_test_event();
		if ( ! $payload ) {
			return Response::error( 'failed_to_build_event', 'Failed to build test event.', 500 );
		}

		$this->queue->enqueue( $payload );

		return Response::success( [
			'test_queued' => true,
			'event_id'    => $payload['event_id'] ?? '',
			'queue_size'  => $this->queue->get_size(),
		], 201 );
	}

	public function handle_retry( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		if ( ! $this->config->is_configured() ) {
			return Response::error( 'revalidation_not_configured', 'A valid HTTPS URL and non-empty secret are required.', 503 );
		}

		$event_id = $request->get_param( 'event_id' );

		// Tìm event trong history
		$history = $this->queue->get_delivery_history();
		if ( ! is_array( $history ) || empty( $history ) ) {
			return Response::error( 'event_not_found', 'Event not found in history.', 404 );
		}

		$record = null;
		foreach ( $history as $entry ) {
			if ( ( $entry['event_id'] ?? '' ) === $event_id ) {
				$record = $entry;
				break;
			}
		}

		if ( ! $record ) {
			return Response::error( 'event_not_found', 'Event not found in history.', 404 );
		}

		// Requeue event
		$payload = $this->build_payload_from_record( $record );
		if ( ! $payload ) {
			return Response::error( 'invalid_event_data', 'Event data is invalid.', 400 );
		}

		// Reset attempts và enqueue lại
		$this->queue->enqueue( $payload );

		return Response::success( [
			'requeued'    => true,
			'event_id'    => $payload['event_id'],
			'previous_event_id' => $event_id,
			'queue_size'  => $this->queue->get_size(),
		], 201 );
	}

	// ── Permission ─────────────────────────────────────────────────────────────

	public function permission_manage_options(): bool {
		return current_user_can( 'manage_options' );
	}

	// ── Private Helpers ────────────────────────────────────────────────────────

	private function is_configured(): bool {
		return $this->config->is_configured();
	}

	private function get_last_delivery(): ?array {
		$record = $this->queue->get_latest_delivery();
		if ( ! $record ) {
			return null;
		}

		return [
			'event_id'       => $record['event_id'] ?? '',
			'event'          => $record['event'] ?? '',
			'status'         => $record['status'] ?? '',
			'attempts'       => $record['attempts'] ?? 0,
			'last_http_code' => $record['last_http_code'] ?? 0,
			'updated_at'     => $record['updated_at'] ?? 0,
		];
	}

	private function build_payload_from_record( array $record ): ?array {
		// Build minimal payload từ record
		try {
			$event_id = bin2hex( random_bytes( 16 ) );
		} catch ( \Throwable $e ) {
			return null;
		}

		$event = (string) ( $record['event'] ?? '' );
		if ( '' === $event ) {
			return null;
		}

		return [
			'version' => 1,
			'event_id' => $event_id,
			'event' => $event,
			'occurred_at' => time(),
			'schema' => \TLU_HEADLESS_API_SCHEMA_VERSION,
			'site' => [
				'home' => esc_url_raw( home_url() ),
				'frontend' => \TLU_Headless_API\Config::frontend_url(),
			],
			'entity' => [
				'type' => $record['entity_type'] ?? '',
				'id' => (int) ( $record['entity_id'] ?? 0 ),
			],
			'invalidate' => [
				'paths' => array_values( array_slice( (array) ( $record['paths'] ?? [] ), 0, 100 ) ),
				'tags' => array_values( array_slice( (array) ( $record['tags'] ?? [] ), 0, 100 ) ),
			],
			'context' => [
				'causes' => [ 'manual_retry' ],
				'previous_event_id' => (string) ( $record['event_id'] ?? '' ),
			],
		];
	}
}
