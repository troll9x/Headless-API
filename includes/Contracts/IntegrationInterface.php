<?php
namespace TLU_Headless_API\Contracts;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Hợp đồng cho mọi class tích hợp plugin bên thứ ba.
 *
 * Mọi lời gọi đến ACF, Polylang, Rank Math đều phải đi qua
 * class triển khai interface này — không gọi API bên thứ ba trực tiếp
 * từ Controller hay Service.
 */
interface IntegrationInterface {

	/** Kiểm tra plugin bên thứ ba có đang hoạt động không. */
	public function is_active(): bool;
}
