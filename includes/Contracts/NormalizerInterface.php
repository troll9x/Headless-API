<?php
namespace TLU_Headless_API\Contracts;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Hợp đồng cho mọi class chuẩn hóa (Normalizer) của plugin.
 *
 * Nhận giá trị thô từ WordPress / ACF và trả về mảng có cấu trúc
 * nhất quán để frontend tiêu thụ mà không cần xử lý thêm.
 */
interface NormalizerInterface {

	/**
	 * Chuẩn hóa giá trị thô thành cấu trúc đầu ra nhất quán.
	 *
	 * @param  mixed $value Giá trị thô từ WordPress hoặc ACF.
	 * @return mixed        Đầu ra đã chuẩn hóa (thường là array).
	 */
	public function normalize( $value ): mixed;
}
