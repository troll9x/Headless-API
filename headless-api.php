<?php
/**
 * Plugin Name: Headless API
 * Description: Bộ chuyển đổi REST API generic cho WordPress + ACF Pro + Polylang + Rank Math.
 * Plugin URI: https://nguyenhongson.vn/headless-api/
 * Version: 2.0.8
 * Author: Nguyen Hong Son
 * Author URI: https://nguyenhongson.vn/
 * Update URI: https://nguyenhongson.vn/headless-api/
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: tlu-headless-api
 * Requires at least: 6.0
 * Requires PHP: 8.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Keep activation safe when an older copy of Headless API is still active
// under a different directory name. The installer is deliberately loaded
// before the version constants and application classes to avoid redeclaration
// fatals during the activation sandbox request.
require_once plugin_dir_path( __FILE__ ) . 'includes/class-installer.php';
\TLU_Headless_API\Installer::register( __FILE__ );

if (
	defined( 'TLU_HEADLESS_API_VERSION' )
	|| \TLU_Headless_API\Installer::has_active_duplicate( __FILE__ )
) {
	return;
}

// Phiên bản plugin — tăng mỗi khi có thay đổi giao diện API hoặc kiến trúc lớn.
define( 'TLU_HEADLESS_API_VERSION',        '2.0.8' );

// Phiên bản schema JSON — tăng khi cấu trúc response thay đổi không tương thích.
define( 'TLU_HEADLESS_API_SCHEMA_VERSION', '4.9' );

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
