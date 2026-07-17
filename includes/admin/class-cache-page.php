<?php
namespace TLU_Headless_API\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use TLU_Headless_API\Cache\TransientCache;

/**
 * Trang quản trị: Quản lý bộ nhớ đệm.
 *
	 * Sử dụng cache generation để vô hiệu hóa an toàn cả database transient
	 * lẫn persistent object cache mà không cần xóa SQL trực tiếp.
 */
class Cache_Page {

	public function init(): void {
		add_action( 'admin_post_tlu_clear_cache', [ $this, 'handle_clear_cache' ] );
	}

	public function handle_clear_cache(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Bạn không có quyền thực hiện thao tác này.' );
		}

		check_admin_referer( 'tlu_clear_cache_action', 'tlu_cache_nonce' );

		$prefix = isset( $_POST['cache_prefix'] ) ? sanitize_key( $_POST['cache_prefix'] ) : '';
		$cache  = TransientCache::from_config();

		if ( '' === $prefix ) {
			$cache->flush();
			$msg = 'all_cleared';
		} else {
			$cache->flush( $prefix );
			$msg = 'prefix_cleared';
		}

		wp_safe_redirect(
			add_query_arg(
				[ 'page' => 'tlu-headless-cache', 'tlu_cache_msg' => $msg, 'tlu_cache_prefix' => $prefix ],
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	public function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Bạn không có quyền truy cập trang này.' );
		}

		$msg    = isset( $_GET['tlu_cache_msg'] )    ? sanitize_key( $_GET['tlu_cache_msg'] )    : '';
		$prefix = isset( $_GET['tlu_cache_prefix'] ) ? sanitize_key( $_GET['tlu_cache_prefix'] ) : '';
		?>
		<div class="wrap tlu-headless-wrap">
			<h1>Quản lý bộ nhớ đệm</h1>

			<?php $this->render_notice( $msg, $prefix ); ?>

			<p class="description">
				Plugin lưu phản hồi API vào WordPress transient với tiền tố <code>headless_*</code>.
				Bật/tắt cache từ <a href="<?php echo esc_url( admin_url( 'admin.php?page=tlu-headless-settings' ) ); ?>">Cài đặt</a>.
			</p>

			<div class="tlu-section">
				<div class="card tlu-card">
					<h2>Xóa tất cả bộ nhớ đệm</h2>
				<p class="description">Vô hiệu hóa toàn bộ cache hiện tại. Entry cũ sẽ tự hết hạn theo TTL.</p>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
						<?php wp_nonce_field( 'tlu_clear_cache_action', 'tlu_cache_nonce' ); ?>
						<input type="hidden" name="action" value="tlu_clear_cache">
						<input type="hidden" name="cache_prefix" value="">
						<button type="submit" class="button button-primary">Xóa tất cả bộ nhớ đệm</button>
					</form>
				</div>
			</div>

			<div class="tlu-section">
				<div class="card tlu-card">
					<h2>Xóa theo nhóm</h2>
					<table class="widefat striped">
						<thead>
							<tr>
								<th>Nhóm cache</th>
								<th>Nhóm cache</th>
								<th>Thao tác</th>
							</tr>
						</thead>
						<tbody>
							<?php foreach ( $this->cache_groups() as $group_prefix => $label ) : ?>
								<tr>
									<td><?php echo esc_html( $label ); ?></td>
									<td><code>headless_<?php echo esc_html( $group_prefix ); ?>*</code></td>
									<td>
										<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline;">
											<?php wp_nonce_field( 'tlu_clear_cache_action', 'tlu_cache_nonce' ); ?>
											<input type="hidden" name="action" value="tlu_clear_cache">
											<input type="hidden" name="cache_prefix" value="<?php echo esc_attr( $group_prefix ); ?>">
											<button type="submit" class="button button-small">Xóa nhóm này</button>
										</form>
									</td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				</div>
			</div>
		</div>
		<?php
	}

	// ── Private ───────────────────────────────────────────────────────────────

	/** Các nhóm cache được flush theo tiền tố. */
	private function cache_groups(): array {
		return [
			'page'     => 'Trang (page, post)',
			'blocks'   => 'Flexible Content blocks',
			'seo'      => 'SEO metadata',
			'menu'     => 'Menu điều hướng',
			'options'  => 'ACF Options pages',
		];
	}

	private function render_notice( string $msg, string $prefix ): void {
		if ( 'all_cleared' === $msg ) {
			echo '<div class="notice notice-success is-dismissible"><p>Đã xóa thành công toàn bộ bộ nhớ đệm API (<code>headless_*</code>).</p></div>';
		} elseif ( 'prefix_cleared' === $msg ) {
			$groups = $this->cache_groups();
			$label  = $groups[ $prefix ] ?? esc_html( $prefix );
			echo '<div class="notice notice-success is-dismissible"><p>Đã xóa nhóm cache: ' . esc_html( $label ) . '.</p></div>';
		}
	}
}
