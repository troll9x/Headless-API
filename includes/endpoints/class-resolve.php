<?php
/**
 * Resolve Endpoint
 *
 * Resolves WordPress content by ID, slug, path, or URL.
 *
 * @package TLU_Headless_API
 */

namespace TLU_Headless_API\Endpoints;

use TLU_Headless_API\Contracts\Endpoint;
use TLU_Headless_API\Services\ContentResolver;
use TLU_Headless_API\Normalizers\PageNormalizer;

/**
 * Resolve endpoint for content lookup.
 */
class Resolve implements Endpoint {

	/**
	 * Route.
	 *
	 * @var string
	 */
	private string $route = 'resolve';

	/**
	 * HTTP method.
	 *
	 * @var string
	 */
	private string $method = 'GET';

	/**
	 * ContentResolver instance.
	 *
	 * @var ContentResolver
	 */
	private ContentResolver $resolver;

	/**
	 * PageNormalizer instance.
	 *
	 * @var PageNormalizer
	 */
	private PageNormalizer $normalizer;

	/**
	 * Constructor.
	 *
	 * @param ContentResolver|null $resolver Content resolver.
	 * @param PageNormalizer|null $normalizer Page normalizer.
	 */
	public function __construct(
		?ContentResolver $resolver = null,
		?PageNormalizer $normalizer = null
	) {
		$this->resolver   = $resolver ?? new ContentResolver();
		$this->normalizer = $normalizer ?? new PageNormalizer();
	}

	/**
	 * Get route.
	 *
	 * @return string
	 */
	public function get_route(): string {
		return $this->route;
	}

	/**
	 * Get method.
	 *
	 * @return string
	 */
	public function get_method(): string {
		return $this->method;
	}

	/**
	 * Get permission callback.
	 *
	 * @return callable
	 */
	public function permission_callback(): callable {
		return '__return_true';
	}

	/** Register the endpoint using the same provider contract as other endpoints. */
	public function register_routes(): void {
		register_rest_route(
			HEADLESS_API_NAMESPACE,
			'/' . $this->get_route(),
			[
				'methods'             => $this->get_method(),
				'callback'            => [ $this, 'handle' ],
				'permission_callback' => $this->permission_callback(),
				'args'                => $this->get_args(),
			]
		);
	}

	/**
	 * Get args schema.
	 *
	* @return array
	 */
	public function get_args(): array {
		return [
			'id'        => [
				'type'        => 'integer',
				'description' => 'Post ID.',
				'required'    => false,
			],
			'slug'      => [
				'type'        => 'string',
				'description' => 'Post slug.',
				'required'    => false,
			],
			'path'      => [
				'type'        => 'string',
				'description' => 'Post path.',
				'required'    => false,
			],
			'url'       => [
				'type'        => 'string',
				'description' => 'Post URL.',
				'required'    => false,
			],
			'post_type' => [
				'type'        => 'string',
				'description' => 'Post type.',
				'required'    => false,
			],
			'lang'      => [
				'type'        => 'string',
				'description' => 'Language slug or locale.',
				'required'    => false,
				'sanitize_callback' => function( $value ) {
					return preg_replace( '/[^A-Za-z0-9_-]/', '', (string) $value );
				},
			],
		];
	}

	/**
	 * Handle request.
	 *
	 * @param \WP_REST_Request $request Request object.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function handle( \WP_REST_Request $request ) {
		// Build query
		$query = [];

		foreach ( [ 'id', 'slug', 'path', 'url', 'post_type', 'lang' ] as $param ) {
			$value = $request->get_param( $param );
			if ( null !== $value ) {
				$query[ $param ] = $value;
			}
		}

		// Resolve content
		$result = $this->resolver->resolve( $query );

		// Check for errors
		if ( is_wp_error( $result ) ) {
			return rest_ensure_response( $result );
		}

		// Normalize content
		$normalized = $this->normalizer->normalize( $result );

		if ( is_wp_error( $normalized ) ) {
			return rest_ensure_response( $normalized );
		}

		return rest_ensure_response( $normalized );
	}
}
