<?php
/**
 * Scans term meta for attachment references.
 *
 * @package AssetDrips
 */

declare( strict_types=1 );

namespace AssetDrips\Scan;

use AssetDrips\Usage\UsageMap;

defined( 'ABSPATH' ) || exit;

/**
 * Term meta scanner.
 *
 * Streams wp_termmeta and walks each value. Category/term images are commonly
 * stored as thumbnail_id (WooCommerce product categories and others); those
 * resolve by ID, while any stored URL resolves by URL.
 */
final class TermMetaScanner extends AbstractScanner {

	/**
	 * Rows fetched per batch.
	 */
	private const BATCH_SIZE = 1000;

	/**
	 * Term meta key whose value is an attachment ID.
	 */
	private const THUMBNAIL_KEY = 'thumbnail_id';

	/**
	 * {@inheritDoc}
	 *
	 * @return string
	 */
	public function source(): string {
		return 'termmeta';
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param UsageMap                              $into     Shared evidence store.
	 * @param callable(string, int, ?int):void|null $progress Optional progress reporter.
	 * @return void
	 */
	public function scan( UsageMap $into, ?callable $progress = null ): void {
		$wpdb  = $this->db();
		$last  = 0;
		$done  = 0;
		$total = $this->count( "SELECT COUNT(*) FROM {$wpdb->termmeta}" );
		$this->report( $progress, 0, $total );

		do {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Full term-meta scan is inherently direct and uncached.
			$rows    = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT meta_id, term_id, meta_key, meta_value FROM {$wpdb->termmeta} WHERE meta_id > %d ORDER BY meta_id ASC LIMIT %d",
					$last,
					self::BATCH_SIZE
				),
				ARRAY_A
			);
			$fetched = count( (array) $rows );

			foreach ( (array) $rows as $row ) {
				$key         = (string) $row['meta_key'];
				$context     = 'termmeta:' . (int) $row['term_id'] . ':' . $key;
				$image_scope = self::THUMBNAIL_KEY === $key || $this->walker->looks_like_image_key( $key );

				$this->walker->walk( maybe_unserialize( $row['meta_value'] ), $context, $this->source(), $into, $image_scope );

				$last = (int) $row['meta_id'];
			}

			$done += $fetched;
			$this->report( $progress, $done, $total );
		} while ( self::BATCH_SIZE === $fetched );
	}
}
