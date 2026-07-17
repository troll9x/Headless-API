<?php
namespace TLU_Headless_API\Admin;

use TLU_Headless_API\Helpers;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Integrations_Page {

	public function render() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Bạn không có quyền truy cập trang này.' );
		}

		$features     = Helpers::plugin_feature_exists();
		$has_pretty   = get_option( 'permalink_structure' ) !== '';
		$integrations = $this->get_integrations( $features, $has_pretty );
		?>
		<div class="wrap tlu-headless-wrap">
			<h1>Tích hợp</h1>
			<p class="description">Trạng thái các plugin và tính năng mà Headless API tích hợp.</p>

			<?php $this->render_notices( $integrations ); ?>

			<div class="tlu-integration-grid">
				<?php foreach ( $integrations as $item ) : ?>
					<div class="card tlu-integration-card <?php echo $item['active'] ? 'tlu-integration-active' : 'tlu-integration-inactive'; ?>">
						<div class="tlu-integration-header">
							<h3><?php echo esc_html( $item['name'] ); ?></h3>
							<?php if ( $item['active'] ) : ?>
								<span class="tlu-badge tlu-badge-active">Hoạt động</span>
							<?php else : ?>
								<span class="tlu-badge tlu-badge-inactive">Không hoạt động</span>
							<?php endif; ?>
						</div>
						<p><?php echo esc_html( $item['description'] ); ?></p>
						<?php if ( ! $item['active'] && ! empty( $item['recommendation'] ) ) : ?>
							<p class="tlu-recommendation">
								<strong>Khuyến nghị:</strong>
								<?php echo esc_html( $item['recommendation'] ); ?>
							</p>
						<?php endif; ?>
					</div>
				<?php endforeach; ?>
			</div>
		</div>
		<?php
	}

	private function render_notices( array $integrations ) {
		foreach ( $integrations as $item ) {
			if ( ! $item['active'] && ! empty( $item['notice'] ) ) {
				$type = $item['notice_type'] ?? 'warning';
				?>
				<div class="notice notice-<?php echo esc_attr( $type ); ?>">
					<p><?php echo esc_html( $item['notice'] ); ?></p>
				</div>
				<?php
			}
		}
	}

	private function get_integrations( array $features, bool $has_pretty ): array {
		return [
			[
				'name'           => 'Advanced Custom Fields (ACF)',
				'active'         => $features['acf'],
				'description'    => 'Cung cấp dữ liệu trường có cấu trúc cho các phần trang chủ, cài đặt và loại nội dung tùy chỉnh.',
				'recommendation' => 'Cài đặt và kích hoạt ACF hoặc ACF Pro để bật dữ liệu trường có cấu trúc trong API.',
				'notice'         => 'ACF là bắt buộc để cài đặt trang chủ có cấu trúc.',
				'notice_type'    => 'error',
			],
			[
				'name'           => 'Polylang',
				'active'         => $features['polylang'],
				'description'    => 'Cho phép nội dung đa ngôn ngữ và các route API theo ngôn ngữ cho tiếng Việt và tiếng Anh.',
				'recommendation' => 'Cài đặt và kích hoạt Polylang để bật các route đa ngôn ngữ trong API.',
				'notice'         => 'Polylang là bắt buộc cho các route đa ngôn ngữ.',
				'notice_type'    => 'warning',
			],
			[
				'name'           => 'Rank Math SEO',
				'active'         => $features['rank_math'],
				'description'    => 'Cung cấp metadata SEO (tiêu đề meta, mô tả, Open Graph) cho phản hồi API.',
				'recommendation' => 'Cài đặt và kích hoạt Rank Math SEO để bao gồm các trường SEO trong phản hồi API.',
				'notice'         => 'Các trường SEO của Rank Math sẽ không khả dụng.',
				'notice_type'    => 'info',
			],
			[
				'name'           => 'WordPress REST API',
				'active'         => true,
				'description'    => 'WordPress REST API lõi hỗ trợ tất cả các endpoint của Headless API. Có sẵn từ WordPress 4.7.',
				'recommendation' => '',
				'notice'         => null,
				'notice_type'    => null,
			],
			[
				'name'           => 'Pretty Permalinks',
				'active'         => $has_pretty,
				'description'    => 'Pretty permalink là bắt buộc để các route REST API hoạt động đúng.',
				'recommendation' => 'Vào Cài đặt › Permalink và chọn "Tên bài viết" hoặc cấu trúc bất kỳ.',
				'notice'         => 'Pretty permalink chưa được bật. Các endpoint REST API có thể không hoạt động.',
				'notice_type'    => 'error',
			],
			[
				'name'           => 'Thư viện Media',
				'active'         => true,
				'description'    => 'Thư viện media của WordPress cung cấp hình ảnh, logo và tệp đính kèm cho tất cả các endpoint API.',
				'recommendation' => '',
				'notice'         => null,
				'notice_type'    => null,
			],
		];
	}
}
