<?php
namespace TLU_Headless_API\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class API_Explorer_Page {

	public function render() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Bạn không có quyền truy cập trang này.' );
		}

		$legacy  = rest_url( 'tlu/v1' );
		$generic = rest_url( 'headless/v1' );

		$endpoint_groups = [
			'tlu/v1 — Legacy' => [
				[ 'path' => 'tlu/v1/health',   'label' => 'Kiểm tra sức khỏe',    'url' => $legacy  . '/health' ],
				[ 'path' => 'tlu/v1/settings', 'label' => 'Cài đặt website',       'url' => $legacy  . '/settings' ],
				[ 'path' => 'tlu/v1/schema',   'label' => 'Sơ đồ API',             'url' => $legacy  . '/schema' ],
			],
			'headless/v1 — Generic' => [
				[ 'path' => 'headless/v1/page',        'label' => 'Page / Post fields',      'url' => $generic . '/page?slug=sample-page' ],
				[ 'path' => 'headless/v1/page-blocks', 'label' => 'Flexible content blocks', 'url' => $generic . '/page-blocks?slug=sample-page' ],
				[ 'path' => 'headless/v1/options',     'label' => 'Options page fields',     'url' => $generic . '/options?key=options' ],
				[ 'path' => 'headless/v1/menus',       'label' => 'Menu điều hướng',         'url' => $generic . '/menus?location=primary' ],
				[ 'path' => 'headless/v1/seo',         'label' => 'SEO metadata',            'url' => $generic . '/seo?slug=sample-page' ],
			],
		];

		// Flat list for the sidebar
		$all_endpoints = array_merge( ...array_values( $endpoint_groups ) );
		?>
		<div class="wrap tlu-headless-wrap">
			<h1>Khám phá API</h1>
			<p class="description">Chọn endpoint để xem phản hồi JSON trực tiếp.</p>

			<div class="tlu-explorer">

				<div class="tlu-explorer-sidebar">
					<?php foreach ( $endpoint_groups as $group_label => $endpoints ) : ?>
						<h3><?php echo esc_html( $group_label ); ?></h3>
						<ul class="tlu-endpoint-list">
							<?php foreach ( $endpoints as $ep ) : ?>
								<li>
									<button
										class="tlu-endpoint-btn button"
										data-url="<?php echo esc_attr( $ep['url'] ); ?>"
										type="button"
									>
										<code><?php echo esc_html( $ep['path'] ); ?></code>
										<span><?php echo esc_html( $ep['label'] ); ?></span>
									</button>
								</li>
							<?php endforeach; ?>
						</ul>
					<?php endforeach; ?>
				</div>

				<div class="tlu-explorer-main">
					<div class="tlu-explorer-toolbar" id="tlu-explorer-toolbar" style="display:none;">
						<span id="tlu-explorer-url" class="tlu-explorer-url-label"></span>
						<button class="button button-small" id="tlu-copy-url-btn" type="button">Sao chép URL</button>
						<button class="button button-small" id="tlu-copy-response-btn" type="button">Sao chép phản hồi</button>
					</div>

					<div id="tlu-json-loading" class="tlu-explorer-loading" style="display:none;">
						<span class="spinner is-active" style="float:none; margin:0 8px 0 0;"></span>
						Đang tải phản hồi...
					</div>

					<div class="tlu-explorer-empty" id="tlu-explorer-empty">
						<p>Chọn endpoint từ danh sách để xem phản hồi.</p>
					</div>

					<pre id="tlu-json-preview" class="tlu-json-preview" style="display:none;"></pre>
				</div>

			</div>
		</div>
		<?php
	}
}
