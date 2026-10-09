<?php
namespace TLU_Headless_API\Integrations;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use TLU_Headless_API\Contracts\IntegrationInterface;

/**
 * Đọc cấu hình công khai từ SonNH Media Gallery và Son NH Template Gallery.
 */
final class MediaGalleryIntegration implements IntegrationInterface {

	public const TAXONOMY = 'mlo-category';

	public function is_active(): bool {
		return $this->is_category_gallery_active() || $this->is_selected_gallery_active();
	}

	public function is_category_gallery_active(): bool {
		return shortcode_exists( 'media_gallery' );
	}

	public function is_selected_gallery_active(): bool {
		return class_exists( 'Sonnh_Gallery' ) || shortcode_exists( 'sonnh_selected_gallery' );
	}

	public function is_taxonomy_available(): bool {
		return taxonomy_exists( self::TAXONOMY );
	}

	public function get_back_url(): string {
		$default = site_url( '/thu-vien-anh' );
		return esc_url_raw( (string) get_option( 'sonnguyen_media_gallery_back_url', $default ) );
	}

	public function get_selected_category_slug(): string {
		return sanitize_title( (string) get_option( 'sonnh_gallery_selected_category', '' ) );
	}

	/** @return int[] */
	public function get_selected_image_ids(): array {
		$ids = array_map( 'absint', (array) get_option( 'sonnh_gallery_selected_images', [] ) );
		$ids = array_values( array_unique( array_filter( $ids ) ) );
		return array_slice( $ids, 0, 15 );
	}

	public function get_featured_image_id(): int {
		return absint( get_option( 'sonnh_gallery_featured_image_id', 0 ) );
	}
}
