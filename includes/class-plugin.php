<?php
namespace TLU_Headless_API;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use TLU_Headless_API\Cache\TransientCache;
use TLU_Headless_API\Integrations\RevalidationHooksIntegration;
use TLU_Headless_API\Integrations\RestHttpIntegration;
use TLU_Headless_API\Integrations\CacheInvalidationIntegration;

/**
 * Điểm khởi động plugin — Singleton.
 *
 * Uỷ quyền việc nạp file cho Loader, đăng ký hook WordPress,
 * và uỷ quyền đăng ký route cho Rest_Service_Provider.
 * Không chứa bất kỳ logic nghiệp vụ nào.
 */
class Plugin {

	private static ?Plugin $instance = null;

	private Loader $loader;

	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		$this->loader = new Loader();
		$this->loader->load_core();
		$this->init_hooks();
	}

	private function init_hooks(): void {
		add_action( 'rest_api_init', [ $this, 'register_rest_routes' ] );

		// Invalidation cache khi nội dung thay đổi.
		add_action( 'save_post',                [ $this, 'on_post_change' ], 10, 2 );
		add_action( 'deleted_post',             [ $this, 'on_post_change' ], 10, 2 );
		add_action( 'wp_update_nav_menu',       [ $this, 'on_menu_change' ] );
		add_action( 'wp_update_nav_menu_item',  [ $this, 'on_menu_change' ] );
		add_action( 'wp_delete_nav_menu',       [ $this, 'on_menu_change' ] );
		// ACF options save
		add_action( 'acf/save_post',            [ $this, 'on_acf_save' ], 20 );
 		// Term changes (taxonomy cache)
 		add_action( 'edited_term',              [ $this, 'on_term_change' ] );
 		add_action( 'deleted_term_taxonomy',    [ $this, 'on_term_change' ] );

  		// Revalidation hooks integration
  		( new RevalidationHooksIntegration() )->register();
  		
  		// HTTP cache integration
  		( new RestHttpIntegration() )->register();
  		
  		// Cache invalidation integration
  		( new CacheInvalidationIntegration() )->register();

		if ( is_admin() ) {
			$this->loader->load_admin();
			( new Admin\Admin_Menu() )->init();
			( new Admin\Settings_Page() )->init();
			( new Admin\Cache_Page() )->init();
			add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_admin_assets' ] );
		}
	}

	public function register_rest_routes(): void {
		( new Rest_Service_Provider() )->register();
	}

	/**
	 * Flush toàn bộ cache khi post được lưu hoặc xóa.
	 * Bỏ qua autosave và revision.
	 *
	 * @param int           $post_id
	 * @param \WP_Post|null $post
	 */
	public function on_post_change( int $post_id, $post = null ): void {
		if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
			return;
		}
		TransientCache::from_config()->flush();
	}

	/** Flush chỉ menu cache khi menu thay đổi. */
	public function on_menu_change(): void {
		TransientCache::from_config()->flush( 'menu' );
	}

	/**
	 * Flush cache khi ACF lưu options page hoặc post.
	 * acf/save_post nhận post_id có thể là string 'options'.
	 *
	 * @param int|string $post_id
	 */
	public function on_acf_save( $post_id ): void {
		if ( 'options' === $post_id || ( is_string( $post_id ) && str_ends_with( $post_id, 'options' ) ) ) {
			TransientCache::from_config()->flush( 'options' );
		} else {
			// Bài viết thông thường — flush toàn bộ.
			TransientCache::from_config()->flush();
		}
	}

	/** Flush toàn bộ cache khi taxonomy/term thay đổi (ảnh hưởng nhiều response). */
	public function on_term_change(): void {
		TransientCache::from_config()->flush();
	}

	public function enqueue_admin_assets( string $hook ): void {
		if ( false === strpos( $hook, 'tlu-headless' ) ) {
			return;
		}

		wp_enqueue_style(
			'tlu-headless-admin',
			Config::url() . 'assets/admin/admin.css',
			[],
			Config::version()
		);

		wp_enqueue_script(
			'tlu-headless-admin',
			Config::url() . 'assets/admin/admin.js',
			[ 'jquery' ],
			Config::version(),
			true
		);

		wp_localize_script( 'tlu-headless-admin', 'tluHeadless', [
			'ajaxUrl'         => admin_url( 'admin-ajax.php' ),
			'nonce'           => wp_create_nonce( 'wp_rest' ),
			'apiBase'         => rest_url( 'tlu/v1' ),
			'headlessApiBase' => rest_url( 'headless/v1' ),
		] );
	}
}
