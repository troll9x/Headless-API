<?php
namespace TLU_Headless_API\Admin;

use TLU_Headless_API\Helpers;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Dashboard_Page {

	public function render() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Bạn không có quyền truy cập trang này.' );
		}

		$features  = Helpers::plugin_feature_exists();
		$endpoints = $this->get_endpoints();
		?>
		<div class="wrap tlu-headless-wrap">
			<h1>Tổng quan Headless</h1>

			<?php $this->render_quick_links(); ?>
			<?php $this->render_plugin_info( $features ); ?>
			<?php $this->render_integration_status( $features ); ?>
			<?php $this->render_endpoint_status( $endpoints ); ?>
			<?php $this->render_user_guide(); ?>
		</div>
		<?php
	}

	// -------------------------------------------------------------------------

	private function render_quick_links() {
		$legacy  = rest_url( 'tlu/v1' );
		$generic = rest_url( 'headless/v1' );

		$api_links = [
			[ 'label' => 'tlu/v1/health',    'url' => $legacy  . '/health' ],
			[ 'label' => 'tlu/v1/settings',  'url' => $legacy  . '/settings' ],
			[ 'label' => 'tlu/v1/schema',    'url' => $legacy  . '/schema' ],
			[ 'label' => 'headless/v1/page', 'url' => $generic . '/page?slug=sample-page' ],
			[ 'label' => 'headless/v1/menus','url' => $generic . '/menus?location=primary' ],
			[ 'label' => 'headless/v1/seo',  'url' => $generic . '/seo?slug=sample-page' ],
		];
		?>
		<div class="tlu-section">
			<div class="card tlu-card">
				<h2>Truy cập nhanh</h2>

				<p class="description">REST Endpoints</p>
				<div class="tlu-quick-links">
					<?php foreach ( $api_links as $link ) : ?>
						<a href="<?php echo esc_url( $link['url'] ); ?>" target="_blank" rel="noopener" class="button tlu-quick-link-btn">
							<span class="dashicons dashicons-rest-api"></span>
							<code><?php echo esc_html( $link['label'] ); ?></code>
						</a>
					<?php endforeach; ?>
				</div>

				<p class="description" style="margin-top:12px;">Quản trị</p>
				<div class="tlu-quick-links">
					<a href="<?php echo esc_url( admin_url( 'admin.php?page=tlu-headless-explorer' ) ); ?>" class="button tlu-quick-link-btn">
						<span class="dashicons dashicons-search"></span> Khám phá API
					</a>
					<a href="<?php echo esc_url( admin_url( 'admin.php?page=tlu-headless-cache' ) ); ?>" class="button tlu-quick-link-btn">
						<span class="dashicons dashicons-performance"></span> Bộ nhớ đệm
					</a>
					<a href="<?php echo esc_url( admin_url( 'admin.php?page=tlu-headless-settings' ) ); ?>" class="button tlu-quick-link-btn">
						<span class="dashicons dashicons-admin-generic"></span> Cài đặt
					</a>
				</div>
			</div>
		</div>
		<?php
	}

	private function render_plugin_info( array $features ) {
		?>
		<div class="tlu-section">
			<div class="card tlu-card">
				<h2>Thông tin plugin</h2>
				<table class="widefat striped">
					<tbody>
						<tr>
							<td><strong>Plugin</strong></td>
							<td>Headless API</td>
						</tr>
						<tr>
							<td><strong>Phiên bản plugin</strong></td>
							<td><?php echo esc_html( TLU_HEADLESS_API_VERSION ); ?></td>
						</tr>
						<tr>
							<td><strong>Phiên bản API</strong></td>
							<td><?php echo esc_html( TLU_HEADLESS_API_SCHEMA_VERSION ); ?></td>
						</tr>
						<tr>
							<td><strong>Namespace chính</strong></td>
							<td><code><?php echo esc_html( rest_url( 'headless/v1' ) ); ?></code></td>
						</tr>
						<tr>
							<td><strong>Namespace legacy</strong></td>
							<td><code><?php echo esc_html( rest_url( 'tlu/v1' ) ); ?></code></td>
						</tr>
						<tr>
							<td><strong>WordPress</strong></td>
							<td><?php echo esc_html( get_bloginfo( 'version' ) ); ?></td>
						</tr>
						<tr>
							<td><strong>PHP</strong></td>
							<td><?php echo esc_html( phpversion() ); ?></td>
						</tr>
					</tbody>
				</table>
			</div>
		</div>
		<?php
	}

	private function render_integration_status( array $features ) {
		$integrations = [
			[
				'label'   => 'Advanced Custom Fields (ACF)',
				'active'  => $features['acf'],
				'note'    => $features['acf'] ? 'Dùng /headless/v1/page, /page-blocks, /options để đọc fields.' : 'Cần ACF cho /page, /page-blocks, /options endpoints.',
			],
			[
				'label'   => 'Polylang',
				'active'  => $features['polylang'],
				'note'    => $features['polylang'] ? 'Truyền ?lang={code} vào mọi endpoint để nhận đúng ngôn ngữ.' : 'Không bắt buộc. Bật để hỗ trợ đa ngôn ngữ.',
			],
			[
				'label'   => 'Rank Math SEO',
				'active'  => $features['rank_math'],
				'note'    => $features['rank_math'] ? 'Dữ liệu SEO đầy đủ từ /seo và trong /page.' : 'Không bắt buộc. Bật để có Rank Math SEO meta.',
			],
		];
		?>
		<div class="tlu-section">
			<div class="card tlu-card">
				<h2>Trạng thái tích hợp</h2>
				<table class="widefat striped">
					<thead>
						<tr>
							<th>Plugin</th>
							<th>Trạng thái</th>
							<th>Ghi chú</th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $integrations as $item ) : ?>
							<tr>
								<td><strong><?php echo esc_html( $item['label'] ); ?></strong></td>
								<td>
									<?php if ( $item['active'] ) : ?>
										<span class="tlu-badge tlu-badge-active">Hoạt động</span>
									<?php else : ?>
										<span class="tlu-badge tlu-badge-inactive">Không hoạt động</span>
									<?php endif; ?>
								</td>
								<td class="tlu-text-muted" style="font-size:12px;"><?php echo esc_html( $item['note'] ); ?></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			</div>
		</div>
		<?php
	}

	private function render_endpoint_status( array $endpoints ) {
		?>
		<div class="tlu-section">
			<div class="card tlu-card">
				<h2>Trạng thái endpoint</h2>
				<table class="widefat">
					<thead>
						<tr>
							<th>Endpoint</th>
							<th>URL</th>
							<th>Thao tác</th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $endpoints as $ep ) : ?>
							<tr>
								<td><code><?php echo esc_html( $ep['label'] ); ?></code></td>
								<td><span class="tlu-url-text"><?php echo esc_html( $ep['url'] ); ?></span></td>
								<td>
									<a href="<?php echo esc_url( $ep['url'] ); ?>" target="_blank" rel="noopener" class="button button-small">Mở</a>
									<button class="button button-small tlu-copy-url" data-url="<?php echo esc_attr( $ep['url'] ); ?>" type="button">Sao chép</button>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			</div>
		</div>
		<?php
	}

	private function render_user_guide() {
		$generic = rest_url( 'headless/v1' );
		$legacy  = rest_url( 'tlu/v1' );
		?>
		<div class="tlu-section">
			<div class="card tlu-card">
				<h2>Hướng dẫn sử dụng</h2>

				<div class="tlu-guide">

					<div class="tlu-guide-block">
						<h3><span class="dashicons dashicons-info-outline"></span> Plugin này làm gì?</h3>
						<p>Plugin <strong>Headless API</strong> biến WordPress thành một <em>headless CMS</em> generic — đọc bất kỳ ACF field nào bạn tạo trong ACF Pro và phân phối qua REST API đến frontend. Plugin không can thiệp vào theme hoặc yêu cầu cấu trúc nội dung cụ thể.</p>
					</div>

					<div class="tlu-guide-block">
						<h3><span class="dashicons dashicons-editor-ol"></span> Quy trình làm việc</h3>
						<ol>
							<li><strong>Tạo ACF field groups</strong> trong ACF Pro (giao diện hoặc JSON import) và gán cho post types/options pages.</li>
							<li><strong>Nhập nội dung</strong> trong WordPress như thông thường.</li>
							<li><strong>Gọi API</strong> từ frontend — plugin tự động đọc và chuẩn hóa mọi field.</li>
							<li><strong>Xóa cache</strong> (nếu bật) sau khi cập nhật nội dung.</li>
						</ol>
					</div>

					<div class="tlu-guide-block">
						<h3><span class="dashicons dashicons-rest-api"></span> Các endpoint chính</h3>
						<table class="widefat striped">
							<thead>
								<tr><th>Endpoint</th><th>Dùng để làm gì</th><th>Tham số bắt buộc</th></tr>
							</thead>
							<tbody>
								<tr>
									<td><code>/page</code></td>
									<td>Tất cả ACF fields của một page/post, chuẩn hóa theo type</td>
									<td><code>slug</code></td>
								</tr>
								<tr>
									<td><code>/page-blocks</code></td>
									<td>Flexible content blocks (page_blocks / blocks / sections)</td>
									<td><code>slug</code></td>
								</tr>
								<tr>
									<td><code>/options</code></td>
									<td>Tất cả ACF fields của một options page</td>
									<td><code>key</code> (ACF post_id)</td>
								</tr>
								<tr>
									<td><code>/menus</code></td>
									<td>Menu điều hướng dạng cây phân cấp</td>
									<td><code>location</code> hoặc <code>slug</code></td>
								</tr>
								<tr>
									<td><code>/seo</code></td>
									<td>SEO meta (Rank Math hoặc WP native)</td>
									<td><code>id</code> hoặc <code>slug</code></td>
								</tr>
							</tbody>
						</table>
						<p class="description" style="margin-top:8px;">Base URL: <code><?php echo esc_html( $generic ); ?></code></p>
					</div>

					<div class="tlu-guide-block">
						<h3><span class="dashicons dashicons-translation"></span> Hỗ trợ đa ngôn ngữ (Polylang)</h3>
						<p>Khi Polylang được kích hoạt, thêm tham số <code>?lang=vi</code> hoặc <code>?lang=en</code> vào mọi endpoint để nhận nội dung đúng ngôn ngữ. Nếu không có tham số này, WordPress trả về ngôn ngữ mặc định.</p>
					</div>

					<div class="tlu-guide-block">
						<h3><span class="dashicons dashicons-database"></span> Đọc ACF Options Pages</h3>
						<p>Thay vì hardcode field groups, dùng <code>/headless/v1/options?key=<em>your_key</em></code>. Ví dụ:</p>
						<ul>
							<li><code>/options?key=options</code> — ACF global options</li>
							<li><code>/options?key=header_settings</code> — custom options page với post_id = 'header_settings'</li>
						</ul>
						<p>Plugin tự động phát hiện type của mỗi field (image, gallery, link, repeater, flex…) và chuẩn hóa output.</p>
					</div>

					<div class="tlu-guide-block">
						<h3><span class="dashicons dashicons-performance"></span> Bộ nhớ đệm</h3>
						<p>Bật cache tại <a href="<?php echo esc_url( admin_url( 'admin.php?page=tlu-headless-settings' ) ); ?>">Cài đặt</a>. Sau khi cập nhật nội dung, xóa cache tại <a href="<?php echo esc_url( admin_url( 'admin.php?page=tlu-headless-cache' ) ); ?>">Bộ nhớ đệm</a>.</p>
					</div>

				</div>
			</div>
		</div>
		<?php
	}

	private function get_endpoints(): array {
		$legacy  = rest_url( 'tlu/v1' );
		$generic = rest_url( 'headless/v1' );

		return [
			[ 'label' => 'tlu/v1/health',           'url' => $legacy  . '/health' ],
			[ 'label' => 'tlu/v1/settings',         'url' => $legacy  . '/settings' ],
			[ 'label' => 'tlu/v1/schema',           'url' => $legacy  . '/schema' ],
			[ 'label' => 'headless/v1/page',        'url' => $generic . '/page?slug=sample-page' ],
			[ 'label' => 'headless/v1/page-blocks', 'url' => $generic . '/page-blocks?slug=sample-page' ],
			[ 'label' => 'headless/v1/options',     'url' => $generic . '/options?key=options' ],
			[ 'label' => 'headless/v1/menus',       'url' => $generic . '/menus?location=primary' ],
			[ 'label' => 'headless/v1/seo',         'url' => $generic . '/seo?slug=sample-page' ],
		];
	}
}
