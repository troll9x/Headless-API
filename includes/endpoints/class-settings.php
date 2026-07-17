<?php
namespace TLU_Headless_API\Endpoints;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use TLU_Headless_API\Config;
use TLU_Headless_API\Response;
use TLU_Headless_API\Normalizers\MediaNormalizer;
use TLU_Headless_API\Integrations\AcfIntegration;
use TLU_Headless_API\Integrations\PolylangIntegration;
use TLU_Headless_API\Integrations\RankMathIntegration;

/**
 * GET /tlu/v1/settings
 *
 * Thông tin chung về site, branding và các plugin tích hợp đang hoạt động.
 * Không phụ thuộc vào ACF options page.
 */
class Settings {

	private MediaNormalizer     $media;
	private AcfIntegration      $acf;
	private PolylangIntegration $polylang;
	private RankMathIntegration $rank_math;

	public function __construct() {
		$this->media     = new MediaNormalizer();
		$this->acf       = new AcfIntegration();
		$this->polylang  = new PolylangIntegration();
		$this->rank_math = new RankMathIntegration();
	}

	public function register_routes(): void {
		register_rest_route(
			TLU_HEADLESS_API_NAMESPACE,
			'/settings',
			[
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => [ $this, 'handle' ],
				'permission_callback' => '__return_true',
			]
		);
	}

	public function handle( \WP_REST_Request $request ) {
		return Response::success( [
			'api_version' => Config::schema_version(),
			'site'        => $this->build_site(),
			'branding'    => $this->build_branding(),
			'features'    => $this->build_features(),
			'frontend'    => $this->build_frontend(),
		] );
	}

	// ── Private ───────────────────────────────────────────────────────────────

	private function build_site(): array {
		return [
			'name'        => sanitize_text_field( get_bloginfo( 'name' ) ),
			'description' => sanitize_text_field( get_bloginfo( 'description' ) ),
			'url'         => esc_url_raw( site_url() ),
			'home_url'    => esc_url_raw( home_url() ),
			'language'    => sanitize_text_field( get_bloginfo( 'language' ) ),
			'charset'     => sanitize_text_field( get_bloginfo( 'charset' ) ),
		];
	}

	private function build_branding(): array {
		$logo_id     = (int) get_theme_mod( 'custom_logo' );
		$favicon_url = get_site_icon_url( 512 );

		return [
			'logo'    => $this->media->normalize( $logo_id ?: null ),
			'favicon' => $this->media->normalize( $favicon_url ?: null ),
		];
	}

	private function build_features(): array {
		return [
			'acf'       => $this->acf->is_active(),
			'polylang'  => $this->polylang->is_active(),
			'rank_math' => $this->rank_math->is_active(),
		];
	}

	private function build_frontend(): array {
		return [
			'url'             => Config::frontend_url(),
			'cache_enabled'   => Config::cache_enabled(),
			'cache_ttl'       => Config::cache_ttl(),
		];
	}
}
