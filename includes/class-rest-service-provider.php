<?php
namespace TLU_Headless_API;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use TLU_Headless_API\Endpoints\Health;
use TLU_Headless_API\Endpoints\Settings;
use TLU_Headless_API\Endpoints\Schema;
use TLU_Headless_API\Endpoints\Page;
use TLU_Headless_API\Endpoints\Page_Blocks;
use TLU_Headless_API\Endpoints\Options;
use TLU_Headless_API\Endpoints\Menus;
use TLU_Headless_API\Endpoints\Seo;
use TLU_Headless_API\Endpoints\Search;
use TLU_Headless_API\Endpoints\Archive;
use TLU_Headless_API\Endpoints\Term;
use TLU_Headless_API\Endpoints\PreviewToken;
use TLU_Headless_API\Endpoints\Preview;
use TLU_Headless_API\Endpoints\Revalidation;
use TLU_Headless_API\Endpoints\Cache;

/**
 * Đăng ký tất cả endpoint class với WordPress REST API.
 *
 * Thay thế class REST_API cũ (class-rest-api.php đã không còn được nạp).
 * Mỗi endpoint tự đăng ký route của mình qua register_routes().
 */
class Rest_Service_Provider {

	/** @var object[] Danh sách instance endpoint đã được đăng ký trong request này. */
	private array $registered = [];

	public function register(): void {
		$endpoints = [
			// tlu/v1 — legacy, đóng băng, đảm bảo backward compatibility
			new Health(),
			new Settings(),
			new Schema(),

			// headless/v1 — generic, namespace chính
			new Page(),
			new Page_Blocks(),
			new Options(),
			new Menus(),
			new Seo(),
			new Search(),
			new Endpoints\Resolve(),
			new Archive(),
			new Term(),
			new Endpoints\Content_Types(),
 			new PreviewToken(),
  			new Preview(),
  			new Revalidation(),
  			new Cache(),
  		];

		foreach ( $endpoints as $endpoint ) {
			$endpoint->register_routes();
			$this->registered[] = $endpoint;
		}
	}

	/** Trả về tất cả instance endpoint đã đăng ký — dùng cho kiểm tra và introspection. */
	public function registered(): array {
		return $this->registered;
	}
}
