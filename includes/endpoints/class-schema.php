<?php
namespace TLU_Headless_API\Endpoints;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * GET /tlu/v1/schema
 *
 * Trả về tài liệu API contract: namespace, endpoint thực tế đã đăng ký, shapes.
 *
 * Danh sách endpoint được introspect trực tiếp từ WordPress REST server —
 * không hardcode — nên tự cập nhật khi thêm/xóa route.
 * Tài liệu tham số (params) bổ sung thông tin từ static map riêng.
 */
class Schema {

	public function register_routes() {
		register_rest_route(
			TLU_HEADLESS_API_NAMESPACE,
			'/schema',
			[
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => [ $this, 'handle' ],
				'permission_callback' => '__return_true',
			]
		);
	}

	public function handle( \WP_REST_Request $request ) {
		return rest_ensure_response( [
			'api_version'    => TLU_HEADLESS_API_SCHEMA_VERSION,
			'plugin_version' => TLU_HEADLESS_API_VERSION,
			'namespaces'     => [
				'legacy'  => rest_url( TLU_HEADLESS_API_NAMESPACE ),
				'generic' => rest_url( HEADLESS_API_NAMESPACE ),
			],
			'endpoints'  => $this->introspect_endpoints(),
			'shapes'     => $this->normalizer_shapes(),
		] );
	}

	// ── Private ───────────────────────────────────────────────────────────────

	/**
	 * Lấy danh sách route từ WordPress REST server, lọc theo namespace của plugin.
	 * Mỗi route được làm giàu với tài liệu tham số từ endpoint_docs().
	 * Route mới tự động xuất hiện mà không cần cập nhật schema thủ công.
	 */
	private function introspect_endpoints(): array {
		$our_namespaces = [ TLU_HEADLESS_API_NAMESPACE, HEADLESS_API_NAMESPACE ];
		$docs           = $this->endpoint_docs();
		$result         = [];

		foreach ( rest_get_server()->get_routes() as $route => $handlers ) {
			$matched_ns = null;
			foreach ( $our_namespaces as $ns ) {
				if ( str_starts_with( $route, '/' . $ns ) ) {
					$matched_ns = $ns;
					break;
				}
			}
			if ( null === $matched_ns ) {
				continue;
			}

			// Thu thập tất cả HTTP method hỗ trợ trên route này.
			$methods = [];
			foreach ( $handlers as $handler ) {
				foreach ( array_keys( $handler['methods'] ?? [] ) as $m ) {
					$methods[] = $m;
				}
			}
			$methods = array_unique( $methods );

			// Khóa tài liệu: namespace + path tương đối (vd: "tlu/v1/health").
			$relative = substr( $route, strlen( '/' . $matched_ns ) );
			$doc_key  = $matched_ns . $relative;

			$result[] = array_merge(
				[
					'namespace'   => $matched_ns,
					'path'        => $route,
					'url'         => rest_url( ltrim( $route, '/' ) ),
					'methods'     => $methods,
					'description' => '',
					'params'      => [],
				],
				$docs[ $doc_key ] ?? []
			);
		}

		return $result;
	}

	/**
	 * Tài liệu tham số cho từng endpoint.
	 * Được merge vào kết quả introspect — không phải nguồn truth của route.
	 * Khi route không có trong map này, vẫn xuất hiện trong schema (params rỗng).
	 */
	private function endpoint_docs(): array {
		$tlu = TLU_HEADLESS_API_NAMESPACE;
		$h   = HEADLESS_API_NAMESPACE;

		return [
			"$tlu/health"   => [ 'description' => 'Kiểm tra plugin đang hoạt động.' ],
			"$tlu/settings" => [ 'description' => 'Thông tin site, branding, cấu hình frontend.' ],
			"$tlu/schema"   => [ 'description' => 'Tài liệu API này.' ],

			"$h/page"  => [
				'description' => 'Toàn bộ ACF field của page/post, đã chuẩn hóa theo type.',
				'params'      => [
					[ 'name' => 'slug',      'type' => 'string',  'required' => true,  'description' => 'Post slug.' ],
					[ 'name' => 'lang',      'type' => 'string',  'required' => false, 'default' => '',     'description' => 'Mã ngôn ngữ Polylang (vd: vi, en).' ],
					[ 'name' => 'post_type', 'type' => 'string',  'required' => false, 'default' => 'page', 'description' => 'WordPress post type.' ],
				],
			],

			"$h/page-blocks" => [
				'description' => 'ACF Flexible Content blocks của trang, theo field name.',
				'params'      => [
					[ 'name' => 'slug',      'type' => 'string', 'required' => true ],
					[ 'name' => 'lang',      'type' => 'string', 'required' => false, 'default' => '' ],
					[ 'name' => 'post_type', 'type' => 'string', 'required' => false, 'default' => 'page' ],
				],
			],

			"$h/options" => [
				'description' => 'Toàn bộ ACF field của options page (key phải trong allowlist).',
				'params'      => [
					[ 'name' => 'key',  'type' => 'string', 'required' => true,  'description' => 'ACF options page post_id key. Allowlist mặc định rỗng.' ],
					[ 'name' => 'lang', 'type' => 'string', 'required' => false, 'default' => '' ],
				],
			],

			"$h/menus" => [
				'description' => 'Menu điều hướng dạng cây phân cấp.',
				'params'      => [
					[ 'name' => 'location', 'type' => 'string', 'required' => false, 'description' => 'Menu location slug đã đăng ký.' ],
					[ 'name' => 'slug',     'type' => 'string', 'required' => false, 'description' => 'Menu slug (dùng khi location không có).' ],
					[ 'name' => 'lang',     'type' => 'string', 'required' => false, 'default' => '' ],
				],
			],

			"$h/seo" => [
				'description' => 'SEO metadata qua Rank Math (fallback về WP native).',
				'params'      => [
					[ 'name' => 'id',   'type' => 'integer', 'required' => false, 'description' => 'Post ID.' ],
					[ 'name' => 'slug', 'type' => 'string',  'required' => false, 'description' => 'Post slug (dùng khi id không có).' ],
					[ 'name' => 'type', 'type' => 'string',  'required' => false, 'default' => 'page' ],
					[ 'name' => 'lang', 'type' => 'string',  'required' => false, 'default' => '' ],
				],
			],

			"$h/search" => [
				'description' => 'Bridge giữ nguyên ranking và dữ liệu từ WPX FULLTEXT search.',
				'params'      => [
					[ 'name' => 'q',    'type' => 'string',  'required' => true,  'description' => 'Từ khóa, tối thiểu 2 ký tự.' ],
					[ 'name' => 'per',  'type' => 'integer', 'required' => false, 'default' => 8 ],
					[ 'name' => 'page', 'type' => 'integer', 'required' => false, 'default' => 1 ],
				],
			],

			"$h/suggest" => [
				'description' => 'Gợi ý WPX FULLTEXT cho chuỗi ngắn hơn min_chars.',
				'params'      => [
					[ 'name' => 'q', 'type' => 'string', 'required' => true, 'description' => 'Chuỗi 1 ký tự khi min_chars=2.' ],
				],
			],
			"$h/resolve" => [
				'description' => 'Resolve nội dung qua ID, slug, path hoặc URL.',
				'params'      => [
					[ 'name' => 'id',        'type' => 'integer', 'required' => false, 'description' => 'Post ID.' ],
					[ 'name' => 'slug',      'type' => 'string',  'required' => false, 'description' => 'Post slug.' ],
					[ 'name' => 'path',      'type' => 'string',  'required' => false, 'description' => 'Full hierarchical path.' ],
					[ 'name' => 'url',       'type' => 'string',  'required' => false, 'description' => 'Absolute CMS or frontend URL.' ],
					[ 'name' => 'post_type', 'type' => 'string',  'required' => false, 'default' => 'any', 'description' => 'WordPress post type.' ],
				],
				'responses' => [
					'200' => 'Success: Normalized content',
					'400' => 'Invalid or ambiguous input selector',
					'404' => 'Content not found or non-public',
					'409' => 'Ambiguous slug (hierarchical)',
				],
			],

			"$h/content-types" => [
				'description' => 'Danh sách Custom Post Type công khai, gồm archive, supports và taxonomy liên kết.',
			],
			"$h/content-types/(?P<post_type>[a-z0-9_-]+)" => [
				'description' => 'Metadata của một Custom Post Type công khai.',
			],
			"$h/content-taxonomies" => [
				'description' => 'Danh sách Custom Taxonomy công khai và các Content Type liên kết.',
			],
			"$h/content-taxonomies/(?P<taxonomy>[a-z0-9_-]+)" => [
				'description' => 'Metadata của một Custom Taxonomy công khai.',
			],

			"$h/revalidation/status" => [
				'description' => 'Trạng thái webhook revalidation (admin).',
				'params'      => [],
				'responses'   => [
					'200' => 'Status with enabled/configured/queue_size/last_delivery',
					'401' => 'Unauthorized',
				],
			],
			"$h/revalidation/test" => [
				'description' => 'Tạo test event và enqueue (admin).',
				'params'      => [],
				'responses'   => [
					'201' => 'Test event queued',
					'401' => 'Unauthorized',
					'500' => 'Failed to build event',
				],
			],
			"$h/revalidation/retry" => [
				'description' => 'Retry event bằng event_id (admin).',
				'params'      => [
					[ 'name' => 'event_id', 'type' => 'string', 'required' => true, 'description' => 'Event ID cần retry.' ],
				],
				'responses'   => [
					'201' => 'Event requeued',
					'400' => 'Invalid event_id or payload',
					'404' => 'Event not found in history',
					'401' => 'Unauthorized',
				],
			],
		];
	}

	/** Cấu trúc JSON shape do các Normalizer trả về. */
	private function normalizer_shapes(): array {
		return [
			'media' => [
				'id'          => 'integer|null',
				'url'         => 'string',
				'alt'         => 'string',
				'title'       => 'string',
				'caption'     => 'string',
				'description' => 'string',
				'width'       => 'integer|null',
				'height'      => 'integer|null',
				'mime_type'   => 'string',
				'sizes'       => '{ thumbnail, medium, large, full }',
			],
			'link' => [
				'title'  => 'string',
				'url'    => 'string',
				'target' => '"_self"|"_blank"',
			],
			'post_summary' => [
				'id'             => 'integer',
				'slug'           => 'string',
				'title'          => 'string',
				'excerpt'        => 'string',
				'link'           => 'string',
				'type'           => 'string',
				'date'           => 'string (Y-m-d H:i:s)',
				'modified'       => 'string (Y-m-d H:i:s)',
				'featured_image' => 'media',
			],
			'seo' => [
				'title'       => 'string',
				'description' => 'string',
				'canonical'   => 'string',
				'robots'      => 'string[]',
				'open_graph'  => '{ title, description, image: media|null, type }',
				'twitter'     => '{ title, description, image: media|null, card_type }',
				'schema_json' => 'string',
				'hreflang'    => '[ { lang, url } ]',
			],
			'flexible_block' => [
				'layout' => 'string (ACF layout name)',
				'data'   => 'object (normalized field values)',
			],
			'menu_item' => [
				'id'        => 'integer',
				'title'     => 'string',
				'url'       => 'string',
				'target'    => '"_self"|"_blank"',
				'classes'   => 'string[]',
				'type'      => 'string',
				'object'    => 'string',
				'object_id' => 'integer|null',
				'order'     => 'integer',
				'children'  => 'menu_item[]',
			],
		];
	}
}
