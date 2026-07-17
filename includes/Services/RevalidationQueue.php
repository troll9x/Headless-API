<?php
namespace TLU_Headless_API\Services;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Quản lý queue webhook revalidation.
 *
 * Dùng WordPress option làm queue storage (bounded queue với giới hạn).
 * Mỗi event có một event_id duy nhất để chống duplicate across requests.
 */
final class RevalidationQueue {

	private const QUEUE_KEY            = 'headless_revalidation_queue';
	private const MAX_SIZE             = 500;
	private const DELAY_KEY_PREFIX     = 'headless_revalidation_debounce_';
	private const DELIVERY_HISTORY_KEY = 'headless_revalidation_delivery_history';
	private const PROCESSED_KEY        = 'headless_revalidation_processed';

	/**
	 * Lấy kích thước hiện tại của queue.
	 *
	 * @return int
	 */
	public function get_size(): int {
		$queue = $this->get_queue();
		return is_array( $queue ) ? count( $queue ) : 0;
	}

	/**
	 * Enqueue một event.
	 *
	 * @param array $event Event data.
	 * @return array|false Queue sau khi enqueue hoặc false nếu fail.
	 */
	public function enqueue( array $event ): array {
		$queue = $this->get_queue();

		if ( ! is_array( $queue ) ) {
			$queue = [];
		}

		// Kiểm tra duplicate event_id
		$event_id = $event['event_id'] ?? '';
		if ( '' !== $event_id ) {
			foreach ( $queue as $existing ) {
				if ( ( $existing['event_id'] ?? '' ) === $event_id ) {
					// Event đã tồn tại, merge nếu cần
					return $this->merge_into_existing( $queue, $existing, $event );
				}
			}
		}

		// Thêm event mới
		$queue[] = $this->normalize_event( $event );

		// Bounded queue
		if ( count( $queue ) > self::MAX_SIZE ) {
			$queue = $this->trim_queue( $queue );
		}

		return $this->save_queue( $queue );
	}

	/**
	 * Lấy event tiếp theo để xử lý.
	 *
	 * @return array|null
	 */
	public function dequeue(): ?array {
		$queue = $this->get_queue();
		if ( ! is_array( $queue ) || empty( $queue ) ) {
			return null;
		}

		$event = array_shift( $queue );
		$this->save_queue( $queue );

		return $event;
	}

	/**
	 * Xóa một event khỏi queue bằng event_id.
	 *
	 * @param string $event_id Event ID.
	 * @return bool
	 */
	public function delete( string $event_id ): bool {
		$queue = $this->get_queue();
		if ( ! is_array( $queue ) ) {
			return false;
		}

		foreach ( $queue as $i => $e ) {
			if ( ( $e['event_id'] ?? '' ) === $event_id ) {
				unset( $queue[ $i ] );
				$queue = array_values( $queue );
				$this->save_queue( $queue );
				return true;
			}
		}

		return false;
	}

	/**
	 * Kiểm tra xem một event_id đã được xử lý gần đây chưa (để chống replay).
	 *
	 * @param string $event_id Event ID.
	 * @param int    $window_seconds Timestamp window.
	 * @return bool
	 */
	public function is_recently_processed( string $event_id, int $window_seconds = 600 ): bool {
		$processed = $this->get_processed();
		if ( ! is_array( $processed ) ) {
			return false;
		}

		$entry = $processed[ $event_id ] ?? null;
		if ( ! is_array( $entry ) ) {
			return false;
		}

		$timestamp = $entry['processed_at'] ?? 0;
		return ( time() - $timestamp ) < $window_seconds;
	}

	/**
	 * Ghi nhận event_id đã được xử lý.
	 *
	 * @param string $event_id Event ID.
	 * @param int    $timestamp Timestamp (mặc định now).
	 */
	public function mark_processed( string $event_id, int $timestamp = 0 ): void {
		$timestamp = $timestamp ?: time();
		$processed = $this->get_processed();
		if ( ! is_array( $processed ) ) {
			$processed = [];
		}
		$processed[ $event_id ] = [
			'processed_at' => $timestamp,
		];
		$this->save_processed( $processed );
	}

	/**
	 * Lưu trạng thái delivery (success/failed) cho một event.
	 *
	 * @param array $record Delivery record.
	 */
	public function save_delivery_record( array $record ): void {
		$history = $this->get_delivery_history();
		if ( ! is_array( $history ) ) {
			$history = [];
		}

		$event_id = $record['event_id'] ?? '';
		if ( '' === $event_id ) {
			return;
		}

		$history[ $event_id ] = $this->sanitize_delivery_record( $record );

		// History giới hạn 50 records
		$history = array_slice( $history, -50, null, true );
		$this->save_delivery_history( $history );
	}

	/**
	 * Lấy history delivery gần đây nhất.
	 *
	 * @return array|null
	 */
	public function get_latest_delivery(): ?array {
		$history = $this->get_delivery_history();
		if ( ! is_array( $history ) || empty( $history ) ) {
			return null;
		}
		return end( $history );
	}

	/**
	 * Lấy lịch sử delivery (public cho Revalidation endpoint).
	 *
	 * @return ?array
	 */
	public function get_delivery_history(): ?array {
		return get_option( self::DELIVERY_HISTORY_KEY, null );
	}

	/**
	 * Lấy queue hiện tại (public cho Revalidation endpoint).
	 *
	 * @return ?array
	 */
	public function get_queue(): ?array {
		return get_option( self::QUEUE_KEY, null );
	}

	// ── Private Helpers ────────────────────────────────────────────────────────

	private function save_queue( array $queue ): array {
		update_option( self::QUEUE_KEY, $queue, false );
		return $queue;
	}

	private function trim_queue( array $queue ): array {
		$priorities = [
			'content.deleted'      => 10,
			'content.trashed'      => 9,
			'content.unpublished'  => 8,
			'content.published'    => 7,
			'term.deleted'         => 6,
			'menu.deleted'         => 5,
			'content.restored'     => 4,
			'content.updated'      => 3,
			'content.created'     => 2,
			'term.updated'         => 3,
			'term.created'         => 2,
			'menu.updated'         => 2,
			'options.updated'      => 2,
			'revalidation.test'    => 1,
		];

		usort( $queue, function ( $a, $b ) use ( $priorities ) {
			$pa = $priorities[ $a['event'] ?? '' ] ?? 0;
			$pb = $priorities[ $b['event'] ?? '' ] ?? 0;
			if ( $pa !== $pb ) {
				return $pb - $pa;
			}
			return ( $a['occurred_at'] ?? 0 ) - ( $b['occurred_at'] ?? 0 );
		} );

		return array_slice( $queue, 0, self::MAX_SIZE );
	}

	private function normalize_event( array $event ): array {
		return [
			'event_id'         => $event['event_id'] ?? '',
			'event'            => $event['event'] ?? 'unknown',
			'entity_type'      => $event['entity']['type'] ?? '',
			'entity_id'        => (int) ( $event['entity']['id'] ?? 0 ),
			'entity_subtype'   => $event['entity']['subtype'] ?? '',
			'language'         => $event['entity']['language'] ?? '',
			'paths'            => (array) ( $event['invalidate']['paths'] ?? [] ),
			'tags'             => (array) ( $event['invalidate']['tags'] ?? [] ),
			'causes'           => (array) ( $event['context']['causes'] ?? [] ),
			'previous_path'    => $event['entity']['previous_path'] ?? '',
			'previous_status' => $event['entity']['previous_status'] ?? '',
			'created_at'       => time(),
			'updated_at'       => time(),
			'attempts'          => 0,
			'status'           => 'queued',
			'last_http_code'   => 0,
			'last_error'       => '',
			'scheduled_at'     => 0,
		];
	}

	private function merge_into_existing( array $queue, array $existing, array $new ): array {
		$new_paths  = array_unique( array_merge( $existing['paths'] ?? [], $new['invalidate']['paths'] ?? [] ) );
		$new_tags   = array_unique( array_merge( $existing['tags'] ?? [], $new['invalidate']['tags'] ?? [] ) );
		$new_causes = array_unique( array_merge( $existing['causes'] ?? [], $new['context']['causes'] ?? [] ) );

		foreach ( $queue as $i => $e ) {
			if ( ( $e['event_id'] ?? '' ) === $existing['event_id'] ) {
				$queue[ $i ]['paths']   = $new_paths;
				$queue[ $i ]['tags']    = $new_tags;
				$queue[ $i ]['causes']  = $new_causes;
				$queue[ $i ]['updated_at'] = time();
				break;
			}
		}

		return $queue;
	}

	private function get_processed(): ?array {
		return get_option( self::PROCESSED_KEY, null );
	}

	private function save_processed( array $processed ): void {
		update_option( self::PROCESSED_KEY, $processed, false );
	}

	private function save_delivery_history( array $history ): void {
		update_option( self::DELIVERY_HISTORY_KEY, $history, false );
	}

	private function sanitize_delivery_record( array $record ): array {
		return [
			'event_id'       => substr( (string) ( $record['event_id'] ?? '' ), 0, 64 ),
			'event'          => substr( (string) ( $record['event'] ?? '' ), 0, 64 ),
			'entity_type'    => substr( (string) ( $record['entity_type'] ?? '' ), 0, 32 ),
			'entity_id'      => (int) ( $record['entity_id'] ?? 0 ),
			'attempts'       => (int) ( $record['attempts'] ?? 0 ),
			'status'         => substr( (string) ( $record['status'] ?? '' ), 0, 16 ),
			'last_http_code' => (int) ( $record['last_http_code'] ?? 0 ),
			'last_error'     => substr( (string) ( $record['last_error'] ?? '' ), 0, 255 ),
			'created_at'     => (int) ( $record['created_at'] ?? time() ),
			'updated_at'     => time(),
		];
	}
}