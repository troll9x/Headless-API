<?php
namespace TLU_Headless_API;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Quản lý toàn bộ lệnh require_once của plugin.
 *
 * Plugin không cần biết file nằm ở đâu — tất cả đường dẫn
 * được tập trung tại đây để dễ bảo trì và kiểm soát thứ tự nạp.
 *
 * Thứ tự nạp quan trọng:
 *   1. Contracts (interface) — không phụ thuộc gì
 *   2. Cache — phụ thuộc CacheInterface + Config
 *   3. Integrations — phụ thuộc IntegrationInterface
 *   4. Normalizers cơ bản (Media, Link, Taxonomy, Post)
 *   5. Normalizers phức hợp (phụ thuộc normalizers khác)
 *   6. Services (phụ thuộc Integrations, Normalizers, Cache)
 *   7. Core helpers (Config, Response, Helpers, RestServiceProvider)
 *   8. Endpoints (phụ thuộc Services, Response)
 */
class Loader {

		public function load_core(): void {
		$base = TLU_HEADLESS_API_PATH . 'includes/';

		// ── 1. Contracts ─────────────────────────────────────────────────────
		$c = $base . 'Contracts/';
		require_once $c . 'NormalizerInterface.php';
		require_once $c . 'IntegrationInterface.php';
		require_once $c . 'CacheInterface.php';
		require_once $c . 'Service.php';
		require_once $c . 'Endpoint.php';
		// Security helpers
		$helpers = $base . 'Helpers/';
		require_once $helpers . 'ContentVisibility.php';

		// ── 2. Cache ──────────────────────────────────────────────────────────
		require_once $base . 'Cache/TransientCache.php';

		// ── 3. Integrations ───────────────────────────────────────────────────
		$i = $base . 'Integrations/';
require_once $i . 'PermalinkManagerIntegration.php';
require_once $i . 'AcfIntegration.php';
require_once $i . 'PolylangIntegration.php';
require_once $i . 'RankMathIntegration.php';
require_once $i . 'PreviewLinkIntegration.php';
		
		// ── 3.1. Share services used by Normalizers and Endpoints ─────────────────────────────
		$s = $base . 'Services/';
		require_once $s . 'UrlTransformer.php';
		require_once $s . 'ContentTypeRegistry.php';
		require_once $s . 'ContentResolver.php';
		require_once $s . 'ArchiveResolver.php';
		require_once $s . 'ArchiveService.php';
		require_once $s . 'TaxonomyService.php';
 require_once $s . 'PreviewTokenService.php';
 require_once $s . 'PreviewService.php';
 require_once $s . 'RevalidationSigner.php';
 require_once $s . 'RevalidationEventBuilder.php';
 require_once $s . 'RevalidationQueue.php';
	require_once $s . 'RevalidationDispatcher.php';
	require_once $i . 'RevalidationHooksIntegration.php';
	require_once $s . 'CorsPolicy.php';
	require_once $s . 'HttpCachePolicy.php';
	require_once $s . 'CacheVersionStore.php';
	require_once $s . 'CacheKeyBuilder.php';
	require_once $i . 'RestHttpIntegration.php';
	require_once $i . 'CacheInvalidationIntegration.php';

		// ── 4. Normalizers — cơ bản ───────────────────────────────────────────
		$n = $base . 'Normalizers/';
		require_once $n . 'MediaNormalizer.php';
		require_once $n . 'LinkNormalizer.php';
		require_once $n . 'TaxonomyNormalizer.php';
		require_once $n . 'PostNormalizer.php';

		// ── 5. Normalizers — phức hợp ─────────────────────────────────────────
		require_once $n . 'RelationshipNormalizer.php';
		require_once $n . 'MenuNormalizer.php';
		require_once $n . 'UserNormalizer.php';
		require_once $n . 'AcfNormalizer.php';
		require_once $n . 'SeoNormalizer.php';
		require_once $n . 'PageNormalizer.php';
require_once $n . 'TermNormalizer.php';
require_once $n . 'ArchiveNormalizer.php';
require_once $n . 'PreviewNormalizer.php';

		// ── 6. Services ───────────────────────────────────────────────────────
		$s = $base . 'Services/';
		require_once $s . 'PageService.php';
		require_once $s . 'OptionsService.php';
		require_once $s . 'MenuService.php';
		require_once $s . 'SeoService.php';
		require_once $s . 'SearchService.php';
		// Note: ArchiveService and TaxonomyService are loaded above

		// ── 7. Core ───────────────────────────────────────────────────────────
		require_once $base . 'class-config.php';
		require_once $base . 'class-helpers.php';
		require_once $base . 'class-response.php';
		require_once $base . 'class-rest-service-provider.php';

		// ── 8. Endpoints ──────────────────────────────────────────────────────
		$ep = $base . 'endpoints/';
		require_once $ep . 'class-health.php';
		require_once $ep . 'class-settings.php';
		require_once $ep . 'class-schema.php';
		require_once $ep . 'class-page.php';
		require_once $ep . 'class-page-blocks.php';
		require_once $ep . 'class-options.php';
		require_once $ep . 'class-menus.php';
		require_once $ep . 'class-seo.php';
		require_once $ep . 'class-search.php';
		require_once $ep . 'class-resolve.php';
require_once $ep . 'class-archive.php';
require_once $ep . 'class-term.php';
		require_once $ep . 'class-content-types.php';
 require_once $ep . 'class-preview-token.php';
 require_once $ep . 'class-preview.php';
	 require_once $ep . 'class-revalidation.php';
	 require_once $ep . 'class-cache.php';
 	}

	public function load_admin(): void {
		$base = TLU_HEADLESS_API_PATH . 'includes/admin/';

		require_once $base . 'class-admin-menu.php';
		require_once $base . 'class-dashboard-page.php';
		require_once $base . 'class-api-explorer-page.php';
		require_once $base . 'class-integrations-page.php';
		require_once $base . 'class-cache-page.php';
		require_once $base . 'class-settings-page.php';
	}
}
