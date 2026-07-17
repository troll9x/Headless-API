<?php
namespace TLU_Headless_API;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Factory tạo phản hồi REST API.
 *
 * Tập trung việc xây dựng WP_REST_Response và WP_Error để:
 * - Handler endpoint dễ đọc, không lặp lại HTTP status code.
 * - Error code nhất quán trên toàn plugin.
 */
class Response {

	public static function success( array $data, int $status = 200 ): \WP_REST_Response {
		return new \WP_REST_Response( $data, $status );
	}

	public static function error( string $code, string $message, int $status ): \WP_Error {
		return new \WP_Error( $code, $message, [ 'status' => $status ] );
	}

	public static function not_found( string $message = 'Not found.' ): \WP_Error {
		return self::error( 'not_found', $message, 404 );
	}

	public static function bad_request( string $message = 'Bad request.' ): \WP_Error {
		return self::error( 'bad_request', $message, 400 );
	}

	public static function service_unavailable( string $code, string $message ): \WP_Error {
		return self::error( $code, $message, 503 );
	}
}
