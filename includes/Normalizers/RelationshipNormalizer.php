<?php
namespace TLU_Headless_API\Normalizers;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use TLU_Headless_API\Contracts\NormalizerInterface;
use TLU_Headless_API\Helpers\ContentVisibility;
use TLU_Headless_API\Services\UrlTransformer;

/**
 * Chuẩn hóa ACF relationship và post_object field.
 *
 * Chấp nhận: WP_Post đơn, mảng WP_Post, post ID, mảng ID.
 * Luôn trả về mảng của PostNormalizer summaries.
 *
 * Bảo mật: chỉ trả về bài ở trạng thái 'publish'.
 * Bài nháp, private, pending đều bị loại ra — kể cả khi ACF trả về đối tượng trực tiếp.
 * Endpoint là public nên không được lộ nội dung chưa phát hành.
 */
class RelationshipNormalizer implements NormalizerInterface {

	private PostNormalizer $posts;
	private UrlTransformer $transformer;

	public function __construct( PostNormalizer $posts = null, ?UrlTransformer $transformer = null ) {
		$this->transformer = $transformer ?? new UrlTransformer();
		$this->posts = $posts ?? new PostNormalizer( null, $this->transformer );
	}

	/** Chuẩn hóa giá trị relationship thành mảng bài viết tóm tắt (chỉ publish). */
	public function normalize( $value ): array {
		if ( empty( $value ) ) {
			return [];
		}

		// WP_Post đơn
		if ( $value instanceof \WP_Post ) {
			return $this->is_public( $value ) ? [ $this->posts->summary( $value ) ] : [];
		}

		// Mảng WP_Post hoặc mảng ID
		if ( is_array( $value ) ) {
			$result = [];
			foreach ( $value as $item ) {
				if ( $item instanceof \WP_Post ) {
					if ( $this->is_public( $item ) ) {
						$result[] = $this->posts->summary( $item );
					}
				} elseif ( is_numeric( $item ) ) {
					$post = get_post( (int) $item );
					if ( $post && $this->is_public( $post ) ) {
						$result[] = $this->posts->summary( $post );
					}
				}
			}
			return $result;
		}

		// Post ID đơn
		if ( is_numeric( $value ) ) {
			$post = get_post( (int) $value );
			return ( $post && $this->is_public( $post ) ) ? [ $this->posts->summary( $post ) ] : [];
		}

		return [];
	}

	// ── Private ───────────────────────────────────────────────────────────────

	/**
	 * Kiểm tra bài viết có thể hiển thị công khai không.
	 * Chỉ trạng thái 'publish' được chấp nhận vì endpoint này là public.
	 */
	private function is_public( \WP_Post $post ): bool {
		return ContentVisibility::is_post_public( $post );
	}
}
