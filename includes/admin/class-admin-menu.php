<?php
namespace TLU_Headless_API\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Admin_Menu {

	public function init() {
		add_action( 'admin_menu', [ $this, 'register_menus' ] );
	}

	public function register_menus() {
		add_menu_page(
			'Headless',
			'Headless',
			'manage_options',
			'tlu-headless',
			[ $this, 'render_dashboard' ],
			'dashicons-rest-api',
			80
		);

		add_submenu_page(
			'tlu-headless',
			'Tổng quan',
			'Tổng quan',
			'manage_options',
			'tlu-headless',
			[ $this, 'render_dashboard' ]
		);

		add_submenu_page(
			'tlu-headless',
			'Khám phá API',
			'Khám phá API',
			'manage_options',
			'tlu-headless-explorer',
			[ $this, 'render_explorer' ]
		);

		add_submenu_page(
			'tlu-headless',
			'Tích hợp',
			'Tích hợp',
			'manage_options',
			'tlu-headless-integrations',
			[ $this, 'render_integrations' ]
		);

		add_submenu_page(
			'tlu-headless',
			'Bộ nhớ đệm',
			'Bộ nhớ đệm',
			'manage_options',
			'tlu-headless-cache',
			[ $this, 'render_cache' ]
		);

		add_submenu_page(
			'tlu-headless',
			'Cài đặt',
			'Cài đặt',
			'manage_options',
			'tlu-headless-settings',
			[ $this, 'render_settings' ]
		);
	}

	public function render_dashboard() {
		( new Dashboard_Page() )->render();
	}

	public function render_explorer() {
		( new API_Explorer_Page() )->render();
	}

	public function render_integrations() {
		( new Integrations_Page() )->render();
	}

	public function render_cache() {
		( new Cache_Page() )->render();
	}

	public function render_settings() {
		( new Settings_Page() )->render();
	}
}
