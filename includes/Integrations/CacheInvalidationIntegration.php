<?php
namespace TLU_Headless_API\Integrations;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use TLU_Headless_API\Services\CacheVersionStore;
use WP_Post;
use WP_Term;

final class CacheInvalidationIntegration {
	private CacheVersionStore $versions;
	private PolylangIntegration $polylang;

	public function __construct( ?CacheVersionStore $versions = null, ?PolylangIntegration $polylang = null ) {
		$this->versions = $versions ?? new CacheVersionStore();
		$this->polylang = $polylang ?? new PolylangIntegration();
	}

	public function register(): void {
		// Post changes
		add_action( 'post_updated', [ $this, 'on_post_updated' ], 10, 3 );
		add_action( 'transition_post_status', [ $this, 'on_post_status_change' ], 10, 3 );
		add_action( 'deleted_post', [ $this, 'on_post_deleted' ], 10 );

		// Term changes
		add_action( 'set_object_terms', [ $this, 'on_term_assignment' ], 10, 6 );
		add_action( 'created_term', [ $this, 'on_term_created' ], 10, 3 );
		add_action( 'edited_term', [ $this, 'on_term_edited' ], 10, 3 );
		add_action( 'delete_term', [ $this, 'on_term_deleted' ], 10, 4 );

		// Menu changes
		add_action( 'wp_update_nav_menu', [ $this, 'on_menu_updated' ], 10, 2 );
		add_action( 'wp_delete_nav_menu', [ $this, 'on_menu_deleted' ], 10 );
		add_action( 'wp_update_nav_menu_item', [ $this, 'on_menu_item_updated' ], 10, 3 );

		// Options
		add_action( 'updated_option', [ $this, 'on_option_updated' ], 10, 3 );
	}

	public function on_post_updated( int $post_id, WP_Post $post_after, WP_Post $post_before ): void {
		if ( $this->should_skip_post( $post_after ) ) {
			return;
		}

		$this->versions->bump_many( $this->get_post_domains( $post_after ) );
	}

	public function on_post_status_change( string $new_status, string $old_status, WP_Post $post ): void {
		if ( $this->should_skip_post( $post ) ) {
			return;
		}

		if ( $old_status === 'publish' && $new_status !== 'publish' ) {
			// Unpublish - bump content
			$this->versions->bump_many( $this->get_post_domains( $post ) );
		} elseif ( $new_status === 'publish' && $old_status !== 'publish' ) {
			// Publish - bump content
			$this->versions->bump_many( $this->get_post_domains( $post ) );
		}
	}

	public function on_post_deleted( int $post_id ): void {
		$post = get_post( $post_id );
		if ( $post && ! $this->should_skip_post( $post ) ) {
			$this->versions->bump_many( $this->get_post_domains( $post ) );
		}
	}

	public function on_term_assignment( int $object_id, array $terms, array $tt_ids, string $taxonomy, bool $append, array $old_terms ): void {
		if ( $append ) {
			return;
		}
		$this->versions->bump_many( [ 'content', 'taxonomy:' . $taxonomy ] );
	}

	public function on_term_created( int $term_id, int $tt_id, string $taxonomy ): void {
		$this->versions->bump_many( [ 'content', 'taxonomy:' . $taxonomy ] );
	}

	public function on_term_edited( int $term_id, int $tt_id, string $taxonomy ): void {
		$this->versions->bump_many( [ 'content', 'taxonomy:' . $taxonomy ] );
	}

	public function on_term_deleted( int $term_id, int $tt_id, string $taxonomy, $deleted_term ): void {
		$this->versions->bump_many( [ 'content', 'taxonomy:' . $taxonomy ] );
	}

	public function on_menu_updated( int $menu_id, \WP_Term $menu ): void {
		$this->versions->bump( 'menus' );
	}

	public function on_menu_deleted( int $menu_id ): void {
		$this->versions->bump( 'menus' );
	}

	public function on_menu_item_updated( int $menu_id, int $menu_item_id, ?array $args = null ): void {
		$this->versions->bump( 'menus' );
	}

	public function on_option_updated( string $option, mixed $old_value, mixed $value ): void {
		$allowlist = [
			'tlu_headless_options',
			'page_on_front',
			'page_for_posts',
			'blogname',
			'blogdescription',
			'site_logo',
			'avatar_defaults',
			'avatar_rating',
			'default_post_format',
			'default_category',
			'default_ping_status',
			'default_comment_status',
		];

		if ( in_array( $option, $allowlist, true ) || str_starts_with( $option, 'acf_' ) ) {
			$this->versions->bump( 'options' );
		}
	}

	private function should_skip_post( WP_Post $post ): bool {
		return in_array( $post->post_type, [ 'revision', 'autosave' ], true );
	}

	private function get_post_domains( WP_Post $post ): array {
		$domains = [ 'content', 'post_type:' . $post->post_type ];

		$lang = $this->polylang->get_post_language( $post->ID );
		if ( $lang ) {
			$domains[] = 'language:' . $lang;
		}

		return $domains;
	}
}
