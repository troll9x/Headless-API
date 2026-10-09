<?php
namespace TLU_Headless_API\Endpoints;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use TLU_Headless_API\Response;

/** Public, read-only projection of the HOT/NEW homepage post selections. */
final class Priority_Posts {

	private const LABEL_KEYS  = [ 'post_priority_label', '_priority_label' ];
	private const ORDER_KEYS  = [ 'post_priority_order', '_priority_order' ];
	private const EXPIRE_KEYS = [ 'post_priority_expire_date', '_priority_expire' ];

	public function register_routes(): void {
		foreach ( [ HEADLESS_API_NAMESPACE, TLU_HEADLESS_API_NAMESPACE ] as $namespace ) {
			register_rest_route(
				$namespace,
				'/priority-posts',
				[
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => [ $this, 'handle' ],
					'permission_callback' => '__return_true',
					'args'                => [
						'lang' => [
							'default'           => '',
							'sanitize_callback' => 'sanitize_key',
							'validate_callback' => fn( $value ): bool => is_string( $value ) && preg_match( '/^[a-z]{0,10}$/', $value ),
						],
					],
				]
			);
		}
	}

	public function handle( \WP_REST_Request $request ) {
		$lang = sanitize_key( (string) $request->get_param( 'lang' ) );
		$args = [
			'post_type'           => 'post',
			'post_status'         => 'publish',
			'posts_per_page'      => 100,
			'orderby'             => [ 'date' => 'DESC', 'ID' => 'DESC' ],
			'ignore_sticky_posts' => true,
			'no_found_rows'       => true,
			'meta_query'          => [
				'relation' => 'OR',
				[
					'key'     => self::LABEL_KEYS[0],
					'value'   => [ 'hot', 'new' ],
					'compare' => 'IN',
				],
				[
					'key'     => self::LABEL_KEYS[1],
					'value'   => [ 'hot', 'new' ],
					'compare' => 'IN',
				],
			],
		];

		if ( '' !== $lang ) {
			// Polylang consumes this argument through its normal WP_Query filters.
			$args['lang'] = $lang;
		}

		$query = new \WP_Query( $args );
		$items = [];

		foreach ( $query->posts as $post ) {
			$item = $this->normalize_post( $post );
			if ( null !== $item ) {
				$items[] = $item;
			}
		}

		usort(
			$items,
			static fn( array $left, array $right ): int =>
				( $left['order'] <=> $right['order'] )
				?: ( $right['id'] <=> $left['id'] )
		);

		return Response::success( [
			'source' => 'post_meta',
			'lang'   => $lang,
			'total'  => count( $items ),
			'items'  => $items,
		] );
	}

	private function normalize_post( \WP_Post $post ): ?array {
		$label = strtolower( trim( $this->first_meta_value( $post->ID, self::LABEL_KEYS ) ) );
		$order = absint( $this->first_meta_value( $post->ID, self::ORDER_KEYS ) );

		if ( ! in_array( $label, [ 'hot', 'new' ], true ) || $order < 1 ) {
			return null;
		}

		$expire_date = trim( $this->first_meta_value( $post->ID, self::EXPIRE_KEYS ) );

		return [
			'id'          => (int) $post->ID,
			'slug'        => (string) $post->post_name,
			'title'       => wp_strip_all_tags( get_the_title( $post ) ),
			'link'        => (string) get_permalink( $post ),
			'label'       => $label,
			'order'       => $order,
			'expire_date' => '' !== $expire_date ? sanitize_text_field( $expire_date ) : null,
		];
	}

	/** @param string[] $keys */
	private function first_meta_value( int $post_id, array $keys ): string {
		foreach ( $keys as $key ) {
			$value = get_post_meta( $post_id, $key, true );
			if ( is_scalar( $value ) && '' !== trim( (string) $value ) ) {
				return (string) $value;
			}
		}

		return '';
	}
}
