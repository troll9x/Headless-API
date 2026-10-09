<?php
declare(strict_types=1);

namespace TLU_Headless_API\Integrations {
	class AcfIntegration {
		public array $fields = [];
		public function is_active(): bool { return true; }
		public function get_options_field_objects( string $key ): array { return $this->fields; }
	}
	class PolylangIntegration {
		public function is_active(): bool { return false; }
	}
}
namespace TLU_Headless_API\Normalizers {
	class AcfNormalizer {
		public function normalize_field_objects( array $fields ): array {
			return array_map( static fn( array $field ) => $field['value'], $fields );
		}
	}
}
namespace TLU_Headless_API\Cache {
	class TransientCache {
		private array $items = [];
		public function make_key( string ...$parts ): string { return implode( ':', $parts ); }
		public function get( string $key ) { return $this->items[ $key ] ?? null; }
		public function set( string $key, array $value ): void { $this->items[ $key ] = $value; }
	}
}
namespace {
	define( 'ABSPATH', __DIR__ . '/wordpress/' );
	function apply_filters( string $hook, $value, ...$args ) { return $value; }
	require dirname( __DIR__ ) . '/includes/Services/OptionsService.php';

	$acf = new TLU_Headless_API\Integrations\AcfIntegration();
	$acf->fields = [
		'hero_slides_vi' => [ 'name' => 'hero_slides_vi', 'value' => [ 'slide' ] ],
		'secret' => [ 'name' => 'api_secret', 'value' => 'must-not-appear' ],
	];
	$cache = new TLU_Headless_API\Cache\TransientCache();
	$service = new TLU_Headless_API\Services\OptionsService(
		$acf,
		new TLU_Headless_API\Integrations\PolylangIntegration(),
		new TLU_Headless_API\Normalizers\AcfNormalizer(),
		$cache
	);
	$expected = [ 'hero_slides_vi' => [ 'slide' ] ];
	if ( $expected !== $service->get_options( 'tlu_site_hero', 'vi' ) ) {
		fwrite( STDERR, "Public field filtering failed\n" );
		exit( 1 );
	}
	if ( [] !== $service->get_options( 'unknown_options', 'vi' ) ) {
		fwrite( STDERR, "Unknown options page exposed fields\n" );
		exit( 1 );
	}
	$cache->set( 'options:tlu_site_hero:en', [ 'hero_slides_en' => [], 'api_secret' => 'legacy-transient' ] );
	if ( [ 'hero_slides_en' => [] ] !== $service->get_options( 'tlu_site_hero', 'en' ) ) {
		fwrite( STDERR, "Legacy transient leaked a non-public field\n" );
		exit( 1 );
	}
	echo "OPTIONS_PUBLIC_FIELDS PASS\n";
}
