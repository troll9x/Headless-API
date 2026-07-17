<?php
/**
 * Plugin Name: Headless API
 * Description: Bộ chuyển đổi REST API generic cho WordPress + ACF Pro + Polylang + Rank Math.
 * Version: 1.5.0
 * Author: Nguyen Hong Son
 * Author URI: nguyenhongson.vn
 * Requires at least: 6.0
 * Requires PHP: 8.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Phiên bản plugin — tăng mỗi khi có thay đổi giao diện API hoặc kiến trúc lớn.
define( 'TLU_HEADLESS_API_VERSION',        '1.5.0' );

// Phiên bản schema JSON — tăng khi cấu trúc response thay đổi không tương thích.
define( 'TLU_HEADLESS_API_SCHEMA_VERSION', '3.0' );

define( 'TLU_HEADLESS_API_PATH',     plugin_dir_path( __FILE__ ) );
define( 'TLU_HEADLESS_API_URL',      plugin_dir_url( __FILE__ ) );
define( 'TLU_HEADLESS_API_BASENAME', plugin_basename( __FILE__ ) );

// Namespace legacy — giữ nguyên để không phá vỡ frontend cũ.
define( 'TLU_HEADLESS_API_NAMESPACE', 'tlu/v1' );

// Namespace generic chính — tất cả endpoint mới sẽ nằm đây.
define( 'HEADLESS_API_NAMESPACE', 'headless/v1' );

// Loader phải được nạp trước khi Plugin khởi tạo.
require_once TLU_HEADLESS_API_PATH . 'includes/class-loader.php';
require_once TLU_HEADLESS_API_PATH . 'includes/class-plugin.php';

TLU_Headless_API\Plugin::instance();
