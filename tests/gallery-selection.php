<?php
declare(strict_types=1);

// Isolated contract regression: the 15 curated home images may span categories.
define( 'ABSPATH', __DIR__ . '/wordpress/' );
define( 'HEADLESS_API_NAMESPACE', 'headless/v1' );

class WP_Post {
	public function __construct(
		public int $ID,
		public string $post_type = 'attachment',
		public string $post_status = 'inherit',
		public string $post_title = '',
		public string $post_excerpt = '',
		public string $post_content = ''
	) {}
}
class WP_Term {
	public int $term_id = 10;
	public string $slug = 'moments';
	public string $name = 'Moments';
	public string $description = '';
	public int $count = 15;
	public int $parent = 0;
}
class WP_Error {}
class Sonnh_Gallery {}

$GLOBALS['test_options'] = [
	'sonnh_gallery_selected_category' => 'moments',
	'sonnh_gallery_selected_images' => range( 1, 15 ),
	'sonnh_gallery_featured_image_id' => 7,
];
$GLOBALS['test_posts'] = [];
$GLOBALS['test_urls'] = [];
foreach ( range( 1, 15 ) as $id ) {
	$GLOBALS['test_posts'][ $id ] = new WP_Post( $id );
	$GLOBALS['test_urls'][ $id ] = "https://cms.example.test/uploads/{$id}.jpg";
}

function shortcode_exists( $name ): bool { return true; }
function taxonomy_exists( $name ): bool { return true; }
function get_option( $name, $default = false ) { return $GLOBALS['test_options'][ $name ] ?? $default; }
function absint( $value ): int { return abs( (int) $value ); }
function sanitize_title( $value ): string { return strtolower( (string) $value ); }
function sanitize_text_field( $value ): string { return (string) $value; }
function sanitize_mime_type( $value ): string { return (string) $value; }
function wp_kses_post( $value ): string { return (string) $value; }
function esc_url_raw( $value ): string { return (string) $value; }
function site_url( $path = '' ): string { return 'https://cms.example.test' . $path; }
function rest_url( $path = '' ): string { return 'https://cms.example.test/wp-json/' . $path; }
function add_query_arg( $key, $value, $url ): string { return $url . '?' . $key . '=' . $value; }
function get_term_by( $field, $value, $taxonomy ): WP_Term { return new WP_Term(); }
function is_wp_error( $value ): bool { return $value instanceof WP_Error; }
function get_post( $id ): ?WP_Post { return $GLOBALS['test_posts'][ $id ] ?? null; }
function get_post_mime_type( $value ): string { return 'image/jpeg'; }
function wp_attachment_is_image( $id ): bool { return isset( $GLOBALS['test_posts'][ $id ] ); }
function wp_get_attachment_url( $id ) { return $GLOBALS['test_urls'][ $id ] ?? false; }
function wp_get_attachment_image_url( $id, $size ) { return wp_get_attachment_url( $id ); }
function wp_get_attachment_metadata( $id ): array { return [ 'width' => 100, 'height' => 100 ]; }
function get_post_meta( $id, $key, $single ): string { return ''; }

$root = dirname( __DIR__ );
foreach ( [
	'includes/Contracts/IntegrationInterface.php',
	'includes/Contracts/NormalizerInterface.php',
	'includes/Integrations/MediaGalleryIntegration.php',
	'includes/Integrations/PolylangIntegration.php',
	'includes/Services/UrlTransformer.php',
	'includes/Normalizers/MediaNormalizer.php',
	'includes/Services/MediaGalleryService.php',
] as $file ) {
	require_once $root . '/' . $file;
}

$service = new TLU_Headless_API\Services\MediaGalleryService(
	new TLU_Headless_API\Integrations\MediaGalleryIntegration(),
	new TLU_Headless_API\Integrations\PolylangIntegration(),
	new TLU_Headless_API\Normalizers\MediaNormalizer(
		new TLU_Headless_API\Services\UrlTransformer(
			'https://frontend.example.test',
			[ 'https://cms.example.test' ]
		)
	),
	new TLU_Headless_API\Services\UrlTransformer(
		'https://frontend.example.test',
		[ 'https://cms.example.test' ]
	)
);

function assert_ids( array $expected, array $actual, string $label ): void {
	if ( $expected !== $actual ) {
		fwrite( STDERR, $label . ': expected ' . json_encode( $expected ) . ', got ' . json_encode( $actual ) . PHP_EOL );
		exit( 1 );
	}
}

// Image 15 has no organizer category membership; selection must still show all 15.
$home = $service->get_home_gallery();
assert_ids( range( 1, 15 ), array_column( $home['selected_images'], 'id' ), 'cross-category selection' );
if ( 6 !== $home['featured_index'] ) {
	fwrite( STDERR, "featured index changed\n" );
	exit( 1 );
}

// Reject inaccessible/non-image attachments instead of returning broken media.
$GLOBALS['test_posts'][ 2 ]->post_status = 'private';
unset( $GLOBALS['test_urls'][ 3 ] );
$home = $service->get_home_gallery();
assert_ids( [ 1, 4, 5, 6, 8, 9, 7, 10, 11, 12, 13, 14, 15 ], array_column( $home['selected_images'], 'id' ), 'invalid attachments and featured order' );

echo "GALLERY_SELECTION PASS\n";
