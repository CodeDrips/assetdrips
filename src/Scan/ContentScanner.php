<?php
/**
 * Scans post_content (including reusable blocks) for attachment references.
 *
 * @package AssetDrips
 */

declare( strict_types=1 );

namespace AssetDrips\Scan;

use AssetDrips\Usage\UsageHit;
use AssetDrips\Usage\UsageMap;

defined( 'ABSPATH' ) || exit;

/**
 * Content scanner.
 *
 * Streams every row of wp_posts that carries content — posts, pages, custom
 * post types, reusable blocks (wp_block), the customizer's Additional CSS
 * (custom_css), and revisions — and records both URL/path references and
 * ID references (block attributes, wp-image-{id}, gallery/caption shortcodes).
 *
 * Revisions and all post statuses are included on purpose: a reference anywhere
 * keeps an attachment in the USED tier, which is the safe direction.
 */
final class ContentScanner extends AbstractScanner {

	/**
	 * Rows fetched per batch.
	 */
	private const BATCH_SIZE = 200;

	/**
	 * {@inheritDoc}
	 *
	 * @return string
	 */
	public function source(): string {
		return 'content';
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param UsageMap                              $into     Shared evidence store.
	 * @param callable(string, int, ?int):void|null $progress Optional progress reporter.
	 * @return void
	 */
	public function scan( UsageMap $into, ?callable $progress = null ): void {
		$wpdb    = $this->db();
		$last_id = 0;
		$done    = 0;
		$total   = $this->count( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_content <> ''" );
		$this->report( $progress, 0, $total );

		do {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Full-content scan is inherently direct and uncached.
			$rows    = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT ID, post_content FROM {$wpdb->posts} WHERE ID > %d AND post_content <> '' ORDER BY ID ASC LIMIT %d",
					$last_id,
					self::BATCH_SIZE
				),
				ARRAY_A
			);
			$fetched = count( (array) $rows );

			foreach ( (array) $rows as $row ) {
				$post_id = (int) $row['ID'];
				$content = (string) $row['post_content'];
				$context = 'post:' . $post_id . ':post_content';

				foreach ( $this->index->find_in_text( $content ) as $id => $token ) {
					$into->add( new UsageHit( $id, $this->source(), $context, UsageHit::MATCH_URL, (string) $token ) );
				}

				foreach ( $this->index->ids_in_text( $content ) as $id ) {
					$into->add( new UsageHit( $id, $this->source(), $context, UsageHit::MATCH_ID, (string) $id ) );
				}

				$last_id = $post_id;
			}

			$done += $fetched;
			$this->report( $progress, $done, $total );
		} while ( self::BATCH_SIZE === $fetched );
	}
}
