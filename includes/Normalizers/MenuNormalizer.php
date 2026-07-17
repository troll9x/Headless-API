<?php

namespace TLU_Headless_API\Normalizers;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use TLU_Headless_API\Contracts\NormalizerInterface;
use TLU_Headless_API\Helpers\ContentVisibility;
use TLU_Headless_API\Services\UrlTransformer;

/**
 * Chuyển mảng phẳng menu item WordPress thành cây phân cấp.
 *
 * Chính sách visibility:
 * - Custom URL: giữ lại.
 * - Taxonomy: giữ lại trong Phase 1.
 * - Post type archive: giữ lại.
 * - Menu item liên kết post/page:
 *   chỉ giữ khi post tồn tại và được phép public.
 * - Nếu một item bị loại nhưng có children public:
 *   children được nâng lên cấp của item bị loại.
 */
class MenuNormalizer implements NormalizerInterface {

	private UrlTransformer $transformer;

	public function __construct( ?UrlTransformer $transformer = null ) {
		$this->transformer = $transformer ?? new UrlTransformer();
	}

	/**
	 * Số cấp lồng tối đa.
	 */
	private const MAX_DEPTH = 10;

	/**
	 * Chuẩn hóa danh sách menu item.
	 *
	 * @param mixed $value Mảng WP_Post từ wp_get_nav_menu_items().
	 */
	public function normalize( $value ): array {
		if ( empty( $value ) || ! is_array( $value ) ) {
			return [];
		}

		return $this->build_tree( $value );
	}

	/**
	 * Chuẩn hóa một menu item đơn.
	 */
	public function normalize_item( \WP_Post $item ): array {
		return [
			'id'        => (int) $item->ID,
			'title'     => sanitize_text_field(
				(string) ( $item->title ?? '' )
			),
			'url'       => $this->transformer->transform_navigation_url(
				(string) ( $item->url ?? '' )
			),
			'target'    => $this->valid_target(
				(string) ( $item->target ?? '' )
			),
			'classes'   => array_values(
				array_filter(
					array_map(
						'sanitize_html_class',
						(array) ( $item->classes ?? [] )
					)
				)
			),
			'type'      => sanitize_key(
				(string) ( $item->type ?? 'custom' )
			),
			'object'    => sanitize_key(
				(string) ( $item->object ?? '' )
			),
			'object_id' => ! empty( $item->object_id )
				? (int) $item->object_id
				: null,
			'order'     => (int) ( $item->menu_order ?? 0 ),
		];
	}

	/**
	 * Xây dựng cây menu.
	 */
	private function build_tree( array $items ): array {
		$index    = [];
		$children = [];

		foreach ( $items as $item ) {
			if ( ! $item instanceof \WP_Post ) {
				continue;
			}

			$item_id = (int) $item->ID;

			if ( $item_id <= 0 ) {
				continue;
			}

			/*
			 * Giữ cả dữ liệu đã normalize và trạng thái visibility.
			 * Không trả WP_Post trong response.
			 */
			$index[ $item_id ] = [
				'node'      => $this->normalize_item( $item ),
				'is_public' => $this->is_item_public( $item ),
			];

			$parent_id = (int) ( $item->menu_item_parent ?? 0 );

			$children[ $parent_id ][] = $item_id;
		}

		$visited = [];

		return $this->subtree(
			0,
			$index,
			$children,
			$visited,
			0
		);
	}

	/**
	 * Xây dựng một nhánh menu.
	 *
	 * Khi node hiện tại không public, children public của node đó
	 * được nâng lên cùng cấp với node bị loại.
	 *
	 * @param int   $parent_id ID node cha.
	 * @param array $index     Bản đồ ID → dữ liệu node.
	 * @param array $children  Bản đồ parent ID → child IDs.
	 * @param array $visited   Danh sách ID đã xử lý.
	 * @param int   $depth     Độ sâu hiện tại.
	 */
	private function subtree(
		int $parent_id,
		array $index,
		array $children,
		array &$visited,
		int $depth
	): array {
		if ( $depth >= self::MAX_DEPTH ) {
			return [];
		}

		$result = [];

		foreach ( $children[ $parent_id ] ?? [] as $id ) {
			$id = (int) $id;

			if (
				$id <= 0
				|| isset( $visited[ $id ] )
				|| ! isset( $index[ $id ] )
			) {
				continue;
			}

			$visited[ $id ] = true;

			$entry = $index[ $id ];

			$child_nodes = $this->subtree(
				$id,
				$index,
				$children,
				$visited,
				$depth + 1
			);

			/*
			 * Item không public:
			 * bỏ item nhưng vẫn giữ và promote children public.
			 */
			if ( empty( $entry['is_public'] ) ) {
				foreach ( $child_nodes as $child_node ) {
					$result[] = $child_node;
				}

				continue;
			}

			$node             = $entry['node'];
			$node['children'] = $child_nodes;

			$result[] = $node;
		}

		return $result;
	}

	/**
	 * Kiểm tra menu item có được public hay không.
	 */
	private function is_item_public( \WP_Post $item ): bool {
		$type = (string) ( $item->type ?? 'custom' );

		/*
		 * Custom URL không có post ID đáng tin cậy để xác minh.
		 * Theo policy Phase 1, custom URL được giữ lại.
		 */
		if ( 'custom' === $type ) {
			return true;
		}

		/*
		 * Taxonomy và archive chưa thuộc phạm vi post visibility
		 * của Phase 1, nên giữ nguyên.
		 */
		if (
			'taxonomy' === $type
			|| 'post_type_archive' === $type
		) {
			return true;
		}

		/*
		 * Các type không phải liên kết post được giữ nguyên.
		 */
		if ( 'post_type' !== $type ) {
			return true;
		}

		$object_id = (int) ( $item->object_id ?? 0 );

		if ( $object_id <= 0 ) {
			return false;
		}

		$post = get_post( $object_id );

		if ( ! $post instanceof \WP_Post ) {
			return false;
		}

		return ContentVisibility::is_post_public( $post );
	}

	/**
	 * Chỉ chấp nhận target an toàn.
	 */
	private function valid_target( string $target ): string {
		return in_array(
			$target,
			[ '_blank', '_self' ],
			true
		)
			? $target
			: '_self';
	}
}