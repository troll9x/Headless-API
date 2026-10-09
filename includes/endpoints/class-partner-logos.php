<?php
namespace TLU_Headless_API\Endpoints;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use TLU_Headless_API\Response;

/** Public, read-only projection of the ACF partner-logo repeater. */
final class Partner_Logos {

	private const FIELD_NAME = 'danh_sach_doi_tac';

	public function register_routes(): void {
		foreach ( [ HEADLESS_API_NAMESPACE, TLU_HEADLESS_API_NAMESPACE ] as $namespace ) {
			register_rest_route(
			$namespace,
			'/partner-logos',
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
		if ( ! function_exists( 'get_field' ) ) {
			return Response::service_unavailable(
				'acf_required',
				'Advanced Custom Fields is required for partner logos.'
			);
		}

		$lang = (string) $request->get_param( 'lang' );
		if ( '' !== $lang ) {
			do_action( 'headless_api_before_options_lang_switch', $lang );
		}

		try {
			$rows = get_field( self::FIELD_NAME, 'option' );
		} finally {
			if ( '' !== $lang ) {
				do_action( 'headless_api_after_options_lang_switch', $lang );
			}
		}

		$rows  = is_array( $rows ) ? $rows : [];
		$items = [];

		foreach ( $rows as $index => $row ) {
			$item = $this->normalize_row( $row, (int) $index );
			if ( null !== $item ) {
				$items[] = $item;
			}
		}

		return Response::success( [
			'source'      => 'acf_options',
			'field'       => self::FIELD_NAME,
			'lang'        => $lang,
			'total_rows'  => count( $rows ),
			'total'       => count( $items ),
			'skipped'     => count( $rows ) - count( $items ),
			'items'       => $items,
		] );
	}

	private function normalize_row( $row, int $index ): ?array {
		if ( ! is_array( $row ) ) {
			return null;
		}

		$image = $this->normalize_image( $row['logo_cong_ty'] ?? null, $index );
		if ( null === $image ) {
			return null;
		}

		return [
			'position'     => $index,
			'logo_cong_ty' => $image,
			'link_doi_tac' => $this->normalize_link( $row['link_doi_tac'] ?? '' ),
		];
	}

	private function normalize_image( $image, int $index ): ?array {
		$image_id  = 0;
		$image_url = '';
		$image_alt = '';
		$title     = '';

		if ( is_array( $image ) ) {
			$image_id  = (int) ( $image['ID'] ?? $image['id'] ?? 0 );
			$image_url = (string) ( $image['url'] ?? '' );
			$image_alt = (string) ( $image['alt'] ?? '' );
			$title     = (string) ( $image['title'] ?? '' );
		} elseif ( is_numeric( $image ) ) {
			$image_id = (int) $image;
		} elseif ( is_string( $image ) ) {
			$image_url = $image;
		}

		if ( $image_id > 0 ) {
			$image_url = $image_url ?: (string) wp_get_attachment_image_url( $image_id, 'full' );
			$image_alt = $image_alt ?: (string) get_post_meta( $image_id, '_wp_attachment_image_alt', true );
			$title     = $title ?: (string) get_the_title( $image_id );
		}

		$image_url = esc_url_raw( $image_url, [ 'http', 'https' ] );
		if ( '' === $image_url ) {
			return null;
		}

		return [
			'id'    => $image_id ?: null,
			'url'   => $image_url,
			'alt'   => sanitize_text_field( $image_alt ),
			'title' => sanitize_text_field( $title ?: sprintf( 'Logo đối tác %d', $index + 1 ) ),
		];
	}

	private function normalize_link( $link ): string {
		if ( is_array( $link ) ) {
			$link = $link['url'] ?? '';
		}

		return is_scalar( $link )
			? esc_url_raw( trim( (string) $link ), [ 'http', 'https' ] )
			: '';
	}
}
