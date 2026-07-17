<?php
namespace TLU_Headless_API\Cache;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use TLU_Headless_API\Contracts\CacheInterface;
use TLU_Headless_API\Config;

/**
 * Cache dựa trên WordPress transient.
 *
 * Tất cả key được gắn prefix để tránh xung đột và cho phép flush theo nhóm.
 * TTL và trạng thái bật/tắt được đọc từ cài đặt plugin (Config).
 *
 * Controller và Service không được gọi set_transient / get_transient trực tiếp —
 * phải dùng class này thông qua CacheInterface.
 *
 * Quy ước key:
 *   - get(), set(), delete() nhận key CHƯA có prefix — prefix được thêm nội bộ.
 *   - make_key() trả về key RAW (chưa có prefix) để truyền vào get/set/delete.
 *   - Không bao giờ truyền kết quả make_key() vào prefixed() thủ công.
 */
class TransientCache implements CacheInterface {

	private string $prefix;
	private int    $default_ttl;
	private bool   $enabled;

	private const GENERATION_OPTION = 'tlu_headless_cache_generations';

	public function __construct( string $prefix = 'headless_', int $default_ttl = 300, bool $enabled = true ) {
		$this->prefix      = $prefix;
		$this->default_ttl = max( 60, $default_ttl );
		$this->enabled     = $enabled;
	}

	/** Tạo instance từ cài đặt plugin hiện tại (đọc Config). */
	public static function from_config(): self {
		return new self(
			'headless_',
			Config::cache_ttl(),
			Config::cache_enabled()
		);
	}

	public function enabled(): bool {
		return $this->enabled;
	}

	/**
	 * Lấy giá trị cache.
	 * Trả về null khi miss hoặc cache bị tắt.
	 *
	 * @param string $key Key CHƯA có prefix (dùng kết quả của make_key()).
	 */
	public function get( string $key ): mixed {
		if ( ! $this->enabled ) {
			return null;
		}
		$result = get_transient( $this->prefixed( $key ) );
		return false === $result ? null : $result;
	}

	/**
	 * Lưu giá trị vào cache.
	 * Dùng TTL mặc định nếu $ttl = 0.
	 *
	 * @param string $key Key CHƯA có prefix (dùng kết quả của make_key()).
	 */
	public function set( string $key, mixed $value, int $ttl = 0 ): bool {
		if ( ! $this->enabled ) {
			return false;
		}
		return (bool) set_transient( $this->prefixed( $key ), $value, $ttl ?: $this->default_ttl );
	}

	/**
	 * Xóa một transient theo key.
	 *
	 * @param string $key Key CHƯA có prefix (dùng kết quả của make_key()).
	 */
	public function delete( string $key ): bool {
		return (bool) delete_transient( $this->prefixed( $key ) );
	}

	/**
	 * Vô hiệu hóa cache theo generation thay vì xóa SQL trực tiếp.
	 * Cách này hoạt động cả khi WordPress dùng persistent object cache: key generation
	 * mới khiến mọi entry cũ không còn được đọc và chúng tự hết hạn theo TTL.
	 *
	 * @param string $prefix Nhóm cache tùy chọn (page, blocks, seo, menu, options).
	 */
	public function flush( string $prefix = '' ): void {
		$generations = $this->generations();
		$group       = $this->normalize_group( $prefix );

		if ( '' === $group ) {
			$generations['global'] = (int) ( $generations['global'] ?? 1 ) + 1;
		} else {
			$generations[ $group ] = (int) ( $generations[ $group ] ?? 1 ) + 1;
		}

		update_option( self::GENERATION_OPTION, $generations, false );
	}

	/**
	 * Tạo cache key RAW từ một hoặc nhiều phần chuỗi.
	 *
	 * Trả về key CHƯA có prefix — truyền kết quả này vào get/set/delete
	 * (chúng sẽ tự thêm prefix).
	 * Tự động hash kết quả nếu độ dài vượt quá giới hạn WordPress transient key.
	 * Tổng option_name = "_transient_" (12) + prefix (9) + key ≤ 191 chars (utf8mb4).
	 * Key tối đa an toàn: 191 - 12 - 9 = 170 ký tự. Hash khi raw > 150.
	 *
	 * @param  string ...$parts Các phần tạo nên key (vd: 'page', 'home', 'vi').
	 * @return string           Key thuần, CHƯA có prefix.
	 */
	public function make_key( string ...$parts ): string {
		$group       = $this->normalize_group( $parts[0] ?? '' );
		$generations = $this->generations();
		$payload     = [
			'schema'           => Config::schema_version(),
			'global_generation'=> (int) ( $generations['global'] ?? 1 ),
			'group_generation' => (int) ( $generations[ $group ] ?? 1 ),
			'parts'            => array_values( $parts ),
		];

		return $group . '_' . md5( (string) wp_json_encode( $payload ) );
	}

	// ── Private ───────────────────────────────────────────────────────────────

	private function prefixed( string $key ): string {
		return $this->prefix . $key;
	}

	private function generations(): array {
		$value = \get_option( self::GENERATION_OPTION, [ 'global' => 1 ] );
		return is_array( $value ) ? $value : [ 'global' => 1 ];
	}

	private function normalize_group( string $group ): string {
		$group = sanitize_key( $group );
		return str_starts_with( $group, 'menu' ) ? 'menu' : $group;
	}
}
