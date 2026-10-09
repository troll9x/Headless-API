<?php
namespace TLU_Headless_API\CLI;

if ( ! defined( 'ABSPATH' ) || ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	exit;
}

use TLU_Headless_API\Services\CanonicalUrlBuilder;

/** Snapshot PM Pro URLs into Headless API-owned metadata before removal. */
final class PermalinkSnapshotCommand {
	/**
	 * ## OPTIONS
	 *
	 * [--apply]
	 * : Write snapshots to post meta. Omit for a dry run.
	 */
	public function snapshot( array $args, array $assoc_args ): void {
		$write = isset( $assoc_args['apply'] );
		$page  = 1;
		$count = 0;
		$aliases = 0;
		do {
			$posts = get_posts( [
				'post_type'      => 'post',
				'post_status'    => 'publish',
				'posts_per_page' => 200,
				'paged'          => $page,
				'fields'         => 'all',
				'no_found_rows'  => true,
			] );
			foreach ( $posts as $post ) {
				$result = CanonicalUrlBuilder::snapshot_post( $post, $write );
				$count++;
				if ( $result['legacy_path'] !== $result['canonical_path'] ) {
					$aliases++;
				}
			}
			$page++;
		} while ( count( $posts ) === 200 );

		\WP_CLI::success( sprintf( '%s %d articles; %d need redirects.', $write ? 'Snapshotted' : 'Dry run checked', $count, $aliases ) );
	}

	/** Export legacy article aliases after snapshot. ## OPTIONS: --file=<absolute-path> */
	public function export_redirects( array $args, array $assoc_args ): void {
		$file = (string) ( $assoc_args['file'] ?? '' );
		if ( '' === $file ) {
			\WP_CLI::error( 'Pass --file=/absolute/path/redirect-map.json.' );
		}
		$redirects = [];
		$page      = 1;
		do {
			$posts = get_posts( [
				'post_type'      => 'post',
				'post_status'    => 'publish',
				'posts_per_page' => 200,
				'paged'          => $page,
				'fields'         => 'ids',
				'no_found_rows'  => true,
			] );
			foreach ( $posts as $id ) {
				$legacy    = (string) get_post_meta( $id, CanonicalUrlBuilder::LEGACY_META_KEY, true );
				$canonical = (string) get_post_meta( $id, CanonicalUrlBuilder::CANONICAL_META_KEY, true );
				if ( '' === $legacy || '' === $canonical ) {
					\WP_CLI::error( 'Missing URL snapshot for post ID ' . (int) $id . '. Run snapshot-permalinks --apply first.' );
				}
				if ( $legacy === $canonical ) {
					continue;
				}
				if ( isset( $redirects[ $legacy ] ) && $redirects[ $legacy ] !== $canonical ) {
					\WP_CLI::error( 'Conflicting legacy path: ' . $legacy );
				}
				$redirects[ $legacy ] = $canonical;
			}
			$page++;
		} while ( count( $posts ) === 200 );

		$written = file_put_contents( $file, wp_json_encode( (object) $redirects, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . PHP_EOL );
		if ( false === $written ) {
			\WP_CLI::error( 'Unable to write redirect map.' );
		}
		\WP_CLI::success( sprintf( 'Exported %d redirects to %s.', count( $redirects ), $file ) );
	}
}
