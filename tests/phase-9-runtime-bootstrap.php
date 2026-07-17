<?php
declare(strict_types=1);

define( 'ABSPATH', __DIR__ . '/wordpress/' );

$GLOBALS['runtime_hooks'] = [];
$GLOBALS['runtime_routes'] = [];
$GLOBALS['runtime_options'] = [];

class WP_HTTP_Response {
	protected array $headers = [];
	public function header( $key, $value, $replace = true ): void { $this->headers[ $key ] = $value; }
	public function remove_header( $key ): void { unset( $this->headers[ $key ] ); }
	public function get_headers(): array { return $this->headers; }
	public function get_header( $key ) { return $this->headers[ $key ] ?? null; }
}
class WP_REST_Response extends WP_HTTP_Response {
	private $data;
	private int $status;
	public function __construct( $data = null, $status = 200 ) { $this->data = $data; $this->status = (int) $status; }
	public function get_data() { return $this->data; }
	public function set_data( $data ): void { $this->data = $data; }
	public function get_status(): int { return $this->status; }
	public function set_status( $status ): void { $this->status = (int) $status; }
}
class WP_REST_Request {
	private string $method;
	private string $route;
	public function __construct( $method = 'GET', $route = '/headless/v1/health' ) { $this->method = $method; $this->route = $route; }
	public function get_method(): string { return $this->method; }
	public function get_route(): string { return $this->route; }
	public function get_params(): array { return []; }
	public function get_headers(): array { return []; }
	public function get_header( $name ): string { return ''; }
}
class WP_REST_Server { public const READABLE = 'GET'; public const CREATABLE = 'POST'; }
class WP_Post {}
class WP_Term {}

function plugin_dir_path( $file ) { return dirname( $file ) . DIRECTORY_SEPARATOR; }
function plugin_dir_url( $file ) { return 'http://example.test/wp-content/plugins/headless-api/'; }
function plugin_basename( $file ) { return 'headless-api/headless-api.php'; }
function add_action( $hook, $callback, $priority = 10, $accepted_args = 1 ) { $GLOBALS['runtime_hooks'][] = [ 'action', $hook, $callback, $priority, $accepted_args ]; return true; }
function add_filter( $hook, $callback, $priority = 10, $accepted_args = 1 ) { $GLOBALS['runtime_hooks'][] = [ 'filter', $hook, $callback, $priority, $accepted_args ]; return true; }
function apply_filters( $hook, $value ) { return $value; }
function register_rest_route( $namespace, $route, $args ) { $GLOBALS['runtime_routes'][] = $namespace . $route; return true; }
function get_option( $key, $default = false ) { return $GLOBALS['runtime_options'][ $key ] ?? $default; }
function update_option( $key, $value, $autoload = null ) { $GLOBALS['runtime_options'][ $key ] = $value; return true; }
function get_transient( $key ) { return false; }
function set_transient( $key, $value, $ttl = 0 ) { return true; }
function delete_transient( $key ) { return true; }
function wp_using_ext_object_cache() { return false; }
function wp_json_encode( $value ) { return json_encode( $value ); }
function wp_parse_url( $url ) { return parse_url( $url ); }
function sanitize_key( $key ) { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $key ) ); }
function esc_url_raw( $url ) { return (string) $url; }
function untrailingslashit( $value ) { return rtrim( (string) $value, '/\\' ); }
function is_admin() { return false; }
function __return_true() { return true; }
function is_user_logged_in() { return false; }
function rest_ensure_response( $result ) { return $result instanceof WP_REST_Response ? $result : new WP_REST_Response( $result ); }

function runtime_assert( bool $condition, string $message ): void {
	if ( ! $condition ) {
		fwrite( STDERR, "FAIL: {$message}\n" );
		exit( 1 );
	}
}

$root = getenv( 'HEADLESS_API_TEST_ROOT' ) ?: dirname( __DIR__ );
$root = rtrim( $root, '/\\' );
require $root . '/headless-api.php';

runtime_assert( class_exists( TLU_Headless_API\Cache\TransientCache::class ), 'TransientCache was not loaded' );
runtime_assert( class_exists( TLU_Headless_API\Integrations\RestHttpIntegration::class ), 'RestHttpIntegration was not loaded' );
runtime_assert( class_exists( TLU_Headless_API\Endpoints\Cache::class ), 'Cache endpoint was not loaded' );
runtime_assert( method_exists( TLU_Headless_API\Cache\TransientCache::class, 'enabled' ), 'TransientCache::enabled() is missing' );

$cache = new TLU_Headless_API\Cache\TransientCache( 'test_', 300, false );
runtime_assert( false === $cache->enabled(), 'enabled() does not reflect constructor configuration' );
$integration = new TLU_Headless_API\Integrations\RestHttpIntegration( null, null, null, null, $cache );

$reflection = new ReflectionClass( $integration );
foreach ( [ 'check_cache', 'store_cache', 'add_validation_headers', 'handle_conditional_get' ] as $method ) {
	$params = $reflection->getMethod( $method )->getParameters();
	runtime_assert( 3 === count( $params ), "{$method} must accept three parameters" );
	runtime_assert( 'server' === $params[1]->getName() && 'WP_REST_Server' === (string) $params[1]->getType(), "{$method} second parameter must be WP_REST_Server" );
	runtime_assert( 'request' === $params[2]->getName() && 'WP_REST_Request' === (string) $params[2]->getType(), "{$method} third parameter must be WP_REST_Request" );
}

$server = new WP_REST_Server();
$request = new WP_REST_Request();
$response = new WP_REST_Response( [ 'ok' => true ] );
$integration->check_cache( null, $server, $request );
$integration->store_cache( $response, $server, $request );
$integration->add_validation_headers( $response, $server, $request );
$integration->handle_conditional_get( null, $server, $request );

$provider = new TLU_Headless_API\Rest_Service_Provider();
$provider->register();
runtime_assert( in_array( 'headless/v1/cache/status', $GLOBALS['runtime_routes'], true ), 'Cache endpoint did not register' );
runtime_assert( count( $provider->registered() ) >= 1, 'No REST endpoints were registered' );

$cors = new TLU_Headless_API\Services\CorsPolicy();
runtime_assert( $cors->is_plugin_route( '/headless/v1/page' ), 'headless/v1 route not recognized' );
runtime_assert( $cors->is_plugin_route( '/tlu/v1/health' ), 'tlu/v1 route not recognized' );
runtime_assert( ! $cors->is_plugin_route( '/wp/v2/posts' ), 'non-plugin route incorrectly recognized' );
runtime_assert( ! $cors->is_plugin_route( '/headless/v10/page' ), 'namespace prefix boundary is unsafe' );

echo "PHASE_9_RUNTIME_BOOTSTRAP PASS\n";
