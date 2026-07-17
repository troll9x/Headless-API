<?php
namespace TLU_Headless_API\Contracts;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Hợp đồng cho cache layer của plugin.
 *
 * Controller và Service không được gọi set_transient / get_transient trực tiếp.
 * Mọi thao tác cache phải đi qua implementation của interface này
 * (hiện tại: TransientCache).
 */
interface CacheInterface {

	public function enabled(): bool;

	/**
	 * Lấy giá trị đã cache.
	 * Trả về null khi cache miss hoặc khi cache bị tắt.
	 */
	public function get( string $key ): mixed;

	/** Lưu giá trị vào cache. Trả về false khi cache bị tắt. */
	public function set( string $key, mixed $value, int $ttl = 0 ): bool;

	/** Xóa một entry cache theo key. */
	public function delete( string $key ): bool;

	/** Vô hiệu hóa một nhóm cache; truyền '' để vô hiệu hóa toàn bộ. */
	public function flush( string $prefix = '' ): void;
}
