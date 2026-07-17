<?php
namespace TLU_Headless_API\Integrations;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use TLU_Headless_API\Services\RevalidationEventBuilder;
use TLU_Headless_API\Services\RevalidationQueue;
use TLU_Headless_API\Services\RevalidationDispatcher;

/**
 * Hook vào WordPress events để trigger revalidation webhooks.
 *
 * Xử lý:
 * - post_updated, transition_post_status, wp_trash_post, untrashed_post
 * - before_delete_post, deleted_post
 * - created_term, edited_term, delete_term
 * - set_object_terms
 * - updated_option (allowlist only)
 *
 * Coalesce events trong cùng request.
 */
final class RevalidationHooksIntegration {

	private RevalidationEventBuilder $builder;
	private RevalidationQueue $queue;

	public function __construct() {
		$this->builder = new RevalidationEventBuilder();
		$this->queue   = new RevalidationQueue();
	}

	/**
	 * Register WordPress hooks.
	 */
	public function register(): void {
		// Content hooks
		add_action( 'post_updated', [ $this, 'handle_post_updated' ], 10, 3 );
		add_action( 'transition_post_status', [ $this, 'handle_transition_post_status' ], 10, 3 );
		add_action( 'wp_trash_post', [ $this, 'handle_trash_post' ] );
		add_action( 'untrashed_post', [ $this, 'handle_untrash_post' ] );
		add_action( 'before_delete_post', [ $this, 'capture_post_for_deletion' ] );
		add_action( 'deleted_post', [ $this, 'handle_deleted_post' ] );
		add_action( 'save_post', [ $this, 'maybe_enqueue_content_event' ], 999, 2 );

		// Taxonomy hooks
		add_action( 'created_term', [ $this, 'handle_created_term' ], 10, 3 );
		add_action( 'edited_term', [ $this, 'handle_edited_term' ], 10, 3 );
		add_action( 'delete_term', [ $this, 'handle_deleted_term' ], 10, 4 );
		add_action( 'set_object_terms', [ $this, 'handle_set_object_terms' ], 10, 6 );

		// Options hooks
		add_action( 'added_option', [ $this, 'handle_option_change' ], 10, 3 );
		add_action( 'updated_option', [ $this, 'handle_option_change' ], 10, 3 );
		add_action( 'deleted_option', [ $this, 'handle_option_change' ], 10, 1 );

		// Cron worker
		add_action( 'headless_api_process_revalidation_event', [ $this, 'process_event' ], 10, 2 );

		// Shutdown - dispatch queued events
		add_action( 'shutdown', [ $this, 'dispatch_all_queued' ], 999 );
	}

	// ── Content Event Handlers ─────────────────────────────────────────────────

	public function handle_post_updated( int $post_id, \WP_Post $post_after, \WP_Post $post_before ): void {
		if ( $this->should_skip_post( $post_id ) ) {
			return;
		}

		$before_status = $post_before->post_status;
		$after_status = $post_after->post_status;

		// Xác định event type
		$event = $this->determine_content_event( $before_status, $after_status, $post_after->post_type );

		if ( ! $event ) {
			return;
		}

		// Capture previous path nếu slug/permalink thay đổi
		$previous_path = '';
		if ( $before_status !== $after_status || $post_before->post_name !== $post_after->post_name ) {
			$previous_path = $this->get_post_public_path( $post_before );
		}

		// Build event
		$context = [
			'causes' => [ 'content', 'slug' ],
			'previous_path' => $previous_path,
			'previous_status' => $before_status,
		];

		$this->enqueue_content_event( $event, $post_after, $context );
	}

	public function handle_transition_post_status( string $new_status, string $old_status, \WP_Post $post ): void {
		if ( $this->should_skip_post( $post->ID ) ) {
			return;
		}

		// Xác định event từ transition
		$event = $this->determine_content_event( $old_status, $new_status, $post->post_type );

		if ( ! $event ) {
			return;
		}

		// Nếu là publish transition, add seo cause
		if ( 'publish' === $new_status && 'publish' !== $old_status ) {
			$context = [ 'causes' => [ 'content', 'seo' ] ];
		} else {
			$context = [ 'causes' => [ 'content' ] ];
		}

		// Capture previous path
		$previous_path = '';
		if ( $old_status !== $new_status ) {
			$previous_path = $this->get_post_public_path( $post );
		}

		$context['previous_path'] = $previous_path;
		$context['previous_status'] = $old_status;

		$this->enqueue_content_event( $event, $post, $context );
	}

	public function handle_trash_post( int $post_id ): void {
		$post = get_post( $post_id );
		if ( ! $post || $this->should_skip_post( $post_id ) ) {
			return;
		}

		$event = $this->determine_content_event( 'publish', 'trash', $post->post_type );
		if ( ! $event ) {
			return;
		}

		$context = [
			'causes' => [ 'content' ],
			'previous_path' => $this->get_post_public_path( $post ),
			'previous_status' => $post->post_status,
		];

		$this->enqueue_content_event( $event, $post, $context );
	}

	public function handle_untrash_post( int $post_id ): void {
		$post = get_post( $post_id );
		if ( ! $post || $this->should_skip_post( $post_id ) ) {
			return;
		}

		$event = 'content.restored';
		$context = [
			'causes' => [ 'content' ],
			'previous_path' => $this->get_post_public_path( $post ),
			'previous_status' => $post->post_status,
		];

		$this->enqueue_content_event( $event, $post, $context );
	}

	public function handle_deleted_post( int $post_id ): void {
		// Đã capture snapshot trước khi xóa
		$snapshot = get_transient( 'headless_revalidation_delete_' . $post_id );
		if ( ! $snapshot ) {
			return;
		}

		$event = 'content.deleted';
		$context = [
			'causes' => [ 'content' ],
			'previous_path' => $snapshot['path'] ?? '',
		];

		// Build dummy post từ snapshot
		$post = (object) [
			'ID' => $post_id,
			'post_type' => $snapshot['type'] ?? 'post',
			'post_status' => $snapshot['status'] ?? 'publish',
			'post_author' => $snapshot['author'] ?? 0,
		];

		$this->enqueue_content_event( $event, $post, $context );

		delete_transient( 'headless_revalidation_delete_' . $post_id );
	}

	public function maybe_enqueue_content_event( int $post_id, \WP_Post $post ): void {
		if ( $this->should_skip_post( $post_id ) ) {
			return;
		}

		// Nếu đã có event cho post này trong cùng request, skip
		$event_id = $this->get_event_dedupe_key( 'content', $post_id, $post->post_type );
		if ( $this->is_event_queued( $event_id ) ) {
			return;
		}

		// Check if ACF saved fields
		if ( defined( 'DOING_AJAX' ) && DOING_AJAX ) {
			// ACF save_post hook sẽ trigger riêng
			return;
		}

		// Update event (nếu publish)
		if ( 'publish' === $post->post_status ) {
			$event = 'content.updated';
			$context = [ 'causes' => [ 'content' ] ];
			$this->enqueue_content_event( $event, $post, $context );
		}
	}

	// ── Term Event Handlers ────────────────────────────────────────────────────

	public function handle_created_term( int $term_id, int $tt_id, string $taxonomy ): void {
		if ( ! $this->is_public_taxonomy( $taxonomy ) ) {
			return;
		}

		$term = get_term( $term_id, $taxonomy );
		if ( ! $term instanceof \WP_Term ) {
			return;
		}

		$event = 'term.created';
		$context = [ 'causes' => [ 'term' ] ];

		$this->enqueue_term_event( $event, $term_id, $taxonomy, $context );
	}

	public function handle_edited_term( int $term_id, int $tt_id, string $taxonomy ): void {
		if ( ! $this->is_public_taxonomy( $taxonomy ) ) {
			return;
		}

		$term = get_term( $term_id, $taxonomy );
		if ( ! $term instanceof \WP_Term ) {
			return;
		}

		$event = 'term.updated';
		$context = [ 'causes' => [ 'term' ] ];

		$this->enqueue_term_event( $event, $term_id, $taxonomy, $context );
	}

	public function handle_deleted_term( int $term_id, int $tt_id, string $taxonomy, \WP_Term $term ): void {
		if ( ! $this->is_public_taxonomy( $taxonomy ) ) {
			return;
		}

		$event = 'term.deleted';
		$context = [ 'causes' => [ 'term' ] ];

		$this->enqueue_term_event( $event, $term_id, $taxonomy, $context );
	}

	public function handle_set_object_terms( int $object_id, array $terms, array $tt_ids, string $taxonomy, bool $append, array $old_tt_ids ): void {
		if ( ! $this->is_public_taxonomy( $taxonomy ) ) {
			return;
		}

		// Check if any terms were added/removed
		$added = array_diff( $tt_ids, $old_tt_ids );
		$removed = array_diff( $old_tt_ids, $tt_ids );

		if ( empty( $added ) && empty( $removed ) ) {
			return;
		}

		$event = 'term.relationships_updated';
		$context = [
			'causes' => [ 'terms' ],
			'added_terms' => $added,
			'removed_terms' => $removed,
		];

		// Build term event từ object
		$term = get_term( reset( $terms ), $taxonomy );
		if ( $term instanceof \WP_Term ) {
			$this->enqueue_term_event( $event, $term->term_id, $taxonomy, $context );
		}
	}

	// ── Options Event Handlers ─────────────────────────────────────────────────

	public function handle_option_change( string $option, mixed $value, mixed $old_value = null ): void {
		// Chỉ xử lý allowlist options
		$allowed_keys = $this->builder->get_allowed_option_keys();
		if ( ! in_array( $option, $allowed_keys, true ) ) {
			return;
		}

		$this->enqueue_options_event( [ $option ] );
	}

	// ── Queue and Dispatch ─────────────────────────────────────────────────────

	public function enqueue_content_event( string $event, \WP_Post $post, array $context = [] ): void {
		$dedupe_key = $this->get_event_dedupe_key( 'content', $post->ID, $post->post_type );

		// Đã có event cho entity này trong queue, skip
		if ( $this->is_event_queued( $dedupe_key ) ) {
			return;
		}

		$payload = $this->builder->build_content_event( $event, $post, $context );
		if ( ! $payload ) {
			return;
		}

		// Gắn dedupe key vào context
		$payload['context']['dedupe_key'] = $dedupe_key;

		$this->queue->enqueue( $payload );
	}

	public function enqueue_term_event( string $event, int $term_id, string $taxonomy, array $context = [] ): void {
		$dedupe_key = $this->get_event_dedupe_key( 'term', $term_id, $taxonomy );

		if ( $this->is_event_queued( $dedupe_key ) ) {
			return;
		}

		$payload = $this->builder->build_term_event( $event, $term_id, $taxonomy, $context );
		if ( ! $payload ) {
			return;
		}

		$payload['context']['dedupe_key'] = $dedupe_key;
		$this->queue->enqueue( $payload );
	}

	public function enqueue_menu_event( string $event, int $menu_id, array $context = [] ): void {
		$dedupe_key = $this->get_event_dedupe_key( 'menu', $menu_id, 'nav_menu' );

		if ( $this->is_event_queued( $dedupe_key ) ) {
			return;
		}

		$payload = $this->builder->build_menu_event( $event, $menu_id, $context );
		if ( ! $payload ) {
			return;
		}

		$payload['context']['dedupe_key'] = $dedupe_key;
		$this->queue->enqueue( $payload );
	}

	public function enqueue_options_event( array $changed_keys = [] ): void {
		$payload = $this->builder->build_options_event( $changed_keys );
		if ( ! $payload ) {
			return;
		}

		$this->queue->enqueue( $payload );
	}

	public function enqueue_test_event(): void {
		$payload = $this->builder->build_test_event();
		if ( ! $payload ) {
			return;
		}

		$this->queue->enqueue( $payload );
	}

	/**
	 * Dispatch tất cả events trong queue bằng WP-Cron.
	 */
	public function dispatch_all_queued(): void {
		if ( ! $this->is_revalidation_enabled() ) {
			return;
		}

		$size = $this->queue->get_size();
		if ( $size <= 0 ) {
			return;
		}

		// Schedule single worker
		if ( ! wp_next_scheduled( 'headless_api_process_revalidation_event' ) ) {
			wp_schedule_single_event( time(), 'headless_api_process_revalidation_event' );
		}
	}

	/**
	 * WP-Cron worker callback.
	 *
	 * @param array $args Event args (không sử dụng).
	 * @param string $job_id Job ID.
	 */
	public function process_event( $args, string $job_id ): void {
		$queue = $this->queue;
		$dispatcher = new RevalidationDispatcher();

		// Lock
		$lock_key = 'headless_revalidation_lock';
		if ( get_transient( $lock_key ) ) {
			// Đang xử lý, retry sau
			wp_schedule_single_event( time() + 30, 'headless_api_process_revalidation_event' );
			return;
		}

		set_transient( $lock_key, time(), 60 );

		try {
			$event = $queue->dequeue();
			while ( $event ) {
				$result = $dispatcher->dispatch( $event );

				// Save delivery record
				$queue->save_delivery_record( [
					'event_id' => $event['event_id'] ?? '',
					'event' => $event['event'] ?? '',
					'entity_type' => $event['entity']['type'] ?? '',
					'entity_id' => $event['entity']['id'] ?? 0,
					'attempts' => $event['attempts'] ?? 0,
					'status' => $result['success'] ? 'success' : 'failed',
					'last_http_code' => $result['http_code'],
					'last_error' => $result['error_message'] ?? '',
					'created_at' => $event['created_at'] ?? time(),
					'updated_at' => time(),
				] );

				if ( $result['success'] ) {
					// Success - mark processed để chống replay
					$queue->mark_processed( $event['event_id'] ?? '' );
				} else {
					// Failed - check retry
					if ( $dispatcher->should_retry( $result, $event['attempts'] ?? 1 ) ) {
						$delay = $dispatcher->get_retry_delay( $event['attempts'] ?? 1 );
						if ( $delay > 0 ) {
							$event['attempts'] = ( $event['attempts'] ?? 0 ) + 1;
							$event['updated_at'] = time();

							// Requeue với delay
							$queue->enqueue( $event );
							wp_schedule_single_event( time() + $delay, 'headless_api_process_revalidation_event' );
						}
					}
				}

				$event = $queue->dequeue();
			}
		} finally {
			delete_transient( $lock_key );
		}
	}

	// ── Private Helpers ────────────────────────────────────────────────────────

	private function should_skip_post( int $post_id ): bool {
		// Revision/autosave
		if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
			return true;
		}

		// Internal post types
		$post_type = get_post_type( $post_id );
		if ( in_array( $post_type, [ 'revision', 'nav_menu_item', 'customize_changeset', 'oembed_cache', 'user_request', 'wp_global_styles', 'wp_navigation' ], true ) ) {
			return true;
		}

		return false;
	}

	private function is_public_taxonomy( string $taxonomy ): bool {
		$obj = get_taxonomy( $taxonomy );
		if ( ! $obj ) {
			return false;
		}
		return ! empty( $obj->public ) || ! empty( $obj->publicly_queryable );
	}

	private function determine_content_event( string $old_status, string $new_status, string $post_type ): ?string {
		// Internal types
		if ( in_array( $post_type, [ 'revision', 'nav_menu_item', 'customize_changeset' ], true ) ) {
			return null;
		}

		// Trashed
		if ( 'trash' === $new_status && 'trash' !== $old_status ) {
			return 'content.trashed';
		}

		// Unpublished (publish -> non-publish)
		if ( 'publish' === $old_status && 'publish' !== $new_status ) {
			return 'content.unpublished';
		}

		// Restored
		if ( 'publish' === $new_status && 'trash' === $old_status ) {
			return 'content.restored';
		}

		// Published
		if ( 'publish' === $new_status && 'publish' !== $old_status ) {
			return 'content.published';
		}

		// Updated
		if ( 'publish' === $new_status && 'publish' === $old_status ) {
			return 'content.updated';
		}

		// Created
		if ( 'publish' !== $old_status && 'publish' === $new_status ) {
			return 'content.created';
		}

		return null;
	}

	private function get_post_public_path( \WP_Post $post ): string {
		if ( ! in_array( $post->post_status, [ 'publish', 'future' ], true ) ) {
			return '';
		}

		$url = get_permalink( $post->ID );
		if ( ! $url ) {
			return '';
		}

		$parsed = wp_parse_url( $url );
		$path = $parsed['path'] ?? '';

		if ( '' === $path || '/' === $path ) {
			return '/';
		}

		return '/' . ltrim( $path, '/' );
	}

	private function capture_post_for_deletion( int $post_id ): void {
		$post = get_post( $post_id );
		if ( ! $post ) {
			return;
		}

		// Skip internal types
		if ( in_array( $post->post_type, [ 'revision', 'nav_menu_item', 'customize_changeset' ], true ) ) {
			return;
		}

		$snapshot = [
			'type' => $post->post_type,
			'status' => $post->post_status,
			'author' => $post->post_author,
			'path' => $this->get_post_public_path( $post ),
		];

		set_transient( 'headless_revalidation_delete_' . $post_id, $snapshot, 300 );
	}

	private function get_event_dedupe_key( string $type, int $id, string $subtype ): string {
		return $type . ':' . $subtype . ':' . $id;
	}

	private function is_event_queued( string $dedupe_key ): bool {
		$queue = $this->queue->get_queue();
		if ( ! is_array( $queue ) ) {
			return false;
		}

		foreach ( $queue as $e ) {
			if ( ( $e['context']['dedupe_key'] ?? '' ) === $dedupe_key ) {
				return true;
			}
		}

		return false;
	}

	private function is_revalidation_enabled(): bool {
		// Check if URL and secret are configured
		$url = defined( 'TLU_HEADLESS_REVALIDATION_URL' )
			? (string) TLU_HEADLESS_REVALIDATION_URL
			: '';
		if ( '' === $url ) {
			$url = (string) apply_filters( 'headless_api_revalidation_url', '' );
		}
		if ( '' === $url ) {
			$url = \TLU_Headless_API\Config::options()['revalidation_url'] ?? '';
		}

		$secret = defined( 'TLU_HEADLESS_REVALIDATION_SECRET' )
			? (string) TLU_HEADLESS_REVALIDATION_SECRET
			: '';
		if ( '' === $secret ) {
			$secret = (string) apply_filters( 'headless_api_revalidation_secret', '' );
		}
		if ( '' === $secret ) {
			$secret = \TLU_Headless_API\Config::options()['revalidation_secret'] ?? '';
		}

		return '' !== $url && '' !== $secret;
	}
}