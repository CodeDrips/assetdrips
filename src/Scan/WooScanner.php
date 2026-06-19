<?php
/**
 * Scans WooCommerce structures for attachment references.
 *
 * @package AssetDrips
 */

declare( strict_types=1 );

namespace AssetDrips\Scan;

use AssetDrips\Usage\UsageHit;
use AssetDrips\Usage\UsageMap;

defined( 'ABSPATH' ) || exit;

/**
 * WooCommerce scanner.
 *
 * Pins down Woo's signature media structures explicitly, so coverage does not
 * depend on the generic meta heuristics:
 *  - product galleries (`_product_image_gallery`, a CSV of attachment IDs);
 *  - product and variation featured images (`_thumbnail_id`);
 *  - downloadable product files (`_downloadable_files`, URLs).
 *
 * Category, brand, and other term images are stored as term meta and covered by
 * the term-meta scanner.
 */
final class WooScanner extends AbstractScanner {

	/**
	 * Rows fetched per batch.
	 */
	private const BATCH_SIZE = 1000;

	/**
	 * {@inheritDoc}
	 *
	 * @return string
	 */
	public function source(): string {
		return 'woo';
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param UsageMap                              $into     Shared evidence store.
	 * @param callable(string, int, ?int):void|null $progress Optional progress reporter.
	 * @return void
	 */
	public function scan( UsageMap $into, ?callable $progress = null ): void {
		$done = 0;
		$this->report( $progress, 0, null );

		$done += $this->scan_galleries( $into );
		$this->report( $progress, $done, null );

		$done += $this->scan_featured_images( $into );
		$this->report( $progress, $done, null );

		$done += $this->scan_downloadable_files( $into );
		$this->report( $progress, $done, null );
	}

	/**
	 * Resolve product gallery CSV IDs.
	 *
	 * @param UsageMap $into Shared evidence store.
	 * @return int Rows processed.
	 */
	private function scan_galleries( UsageMap $into ): int {
		$wpdb      = $this->db();
		$last      = 0;
		$processed = 0;

		do {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Targeted gallery-meta scan is inherently direct and uncached.
			$rows    = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT meta_id, post_id, meta_value FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_id > %d ORDER BY meta_id ASC LIMIT %d",
					'_product_image_gallery',
					$last,
					self::BATCH_SIZE
				),
				ARRAY_A
			);
			$fetched = count( (array) $rows );

			foreach ( (array) $rows as $row ) {
				$last    = (int) $row['meta_id'];
				$context = 'woo:product:' . (int) $row['post_id'] . ':gallery';
				// A CSV of IDs in a media scope resolves each ID.
				$this->walker->walk( (string) $row['meta_value'], $context, $this->source(), $into, true );
			}

			$processed += $fetched;
		} while ( self::BATCH_SIZE === $fetched );

		return $processed;
	}

	/**
	 * Resolve product and variation featured images.
	 *
	 * @param UsageMap $into Shared evidence store.
	 * @return int Rows processed.
	 */
	private function scan_featured_images( UsageMap $into ): int {
		$wpdb      = $this->db();
		$last      = 0;
		$processed = 0;

		do {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Targeted featured-image scan is inherently direct and uncached.
			$rows    = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT p.ID, p.post_type, pm.meta_value
					FROM {$wpdb->posts} p
					INNER JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID AND pm.meta_key = %s
					WHERE p.post_type IN ( %s, %s ) AND p.ID > %d
					ORDER BY p.ID ASC LIMIT %d",
					'_thumbnail_id',
					'product',
					'product_variation',
					$last,
					self::BATCH_SIZE
				),
				ARRAY_A
			);
			$fetched = count( (array) $rows );

			foreach ( (array) $rows as $row ) {
				$last    = (int) $row['ID'];
				$id      = (int) $row['meta_value'];
				$label   = 'product_variation' === $row['post_type'] ? 'variation' : 'product';
				$context = 'woo:' . $label . ':' . (int) $row['ID'] . ':featured';

				if ( $id > 0 && $this->index->is_attachment( $id ) ) {
					$into->add( new UsageHit( $id, $this->source(), $context, UsageHit::MATCH_ID, (string) $id ) );
				}
			}

			$processed += $fetched;
		} while ( self::BATCH_SIZE === $fetched );

		return $processed;
	}

	/**
	 * Resolve downloadable product file URLs.
	 *
	 * @param UsageMap $into Shared evidence store.
	 * @return int Rows processed.
	 */
	private function scan_downloadable_files( UsageMap $into ): int {
		$wpdb      = $this->db();
		$last      = 0;
		$processed = 0;

		do {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Targeted downloadable-files scan is inherently direct and uncached.
			$rows    = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT meta_id, post_id, meta_value FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_id > %d ORDER BY meta_id ASC LIMIT %d",
					'_downloadable_files',
					$last,
					self::BATCH_SIZE
				),
				ARRAY_A
			);
			$fetched = count( (array) $rows );

			foreach ( (array) $rows as $row ) {
				$last    = (int) $row['meta_id'];
				$context = 'woo:product:' . (int) $row['post_id'] . ':downloads';
				$this->walker->walk( maybe_unserialize( $row['meta_value'] ), $context, $this->source(), $into, false );
			}

			$processed += $fetched;
		} while ( self::BATCH_SIZE === $fetched );

		return $processed;
	}
}
