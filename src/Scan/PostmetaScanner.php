<?php
/**
 * Scans postmeta for attachment references.
 *
 * @package AssetDrips
 */

declare( strict_types=1 );

namespace AssetDrips\Scan;

use AssetDrips\Usage\UsageMap;

defined( 'ABSPATH' ) || exit;

/**
 * Postmeta scanner.
 *
 * Streams wp_postmeta and walks each value for references: _thumbnail_id and
 * image-keyed meta resolve by ID; any meta value containing an uploads URL or
 * path resolves by URL. ACF and WooCommerce meta are handled by their own
 * scanners; this covers native featured images and generic image meta.
 *
 * The attachment's own descriptor meta is excluded — counting an attachment's
 * _wp_attached_file as usage would mark every attachment used.
 */
final class PostmetaScanner extends AbstractScanner {

	/**
	 * Rows fetched per batch.
	 */
	private const BATCH_SIZE = 1000;

	/**
	 * Meta keys to skip: attachment self-descriptors and noisy internals.
	 *
	 * @var string[]
	 */
	private const EXCLUDED_KEYS = array(
		'_wp_attached_file',
		'_wp_attachment_metadata',
		'_wp_attachment_backup_sizes',
		'_edit_lock',
		'_edit_last',
	);

	/**
	 * Meta key whose value is always an attachment ID.
	 */
	private const FEATURED_KEY = '_thumbnail_id';

	/**
	 * {@inheritDoc}
	 *
	 * @return string
	 */
	public function source(): string {
		return 'postmeta';
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
		$total = $this->count( "SELECT COUNT(*) FROM {$wpdb->postmeta}" );
		$this->report( $progress, 0, $total );

		do {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Full meta scan is inherently direct and uncached.
			$rows    = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT meta_id, post_id, meta_key, meta_value FROM {$wpdb->postmeta} WHERE meta_id > %d ORDER BY meta_id ASC LIMIT %d",
					$last,
					self::BATCH_SIZE
				),
				ARRAY_A
			);
			$fetched = count( (array) $rows );

			foreach ( (array) $rows as $row ) {
				$last = (int) $row['meta_id'];
				$key  = (string) $row['meta_key'];

				// Skip the attachment's own descriptor meta, or it marks every
				// attachment used; skip noisy internals while here.
				if ( in_array( $key, self::EXCLUDED_KEYS, true ) ) {
					continue;
				}

				$context     = 'postmeta:' . (int) $row['post_id'] . ':' . $key;
				$image_scope = self::FEATURED_KEY === $key || $this->walker->looks_like_image_key( $key );

				$this->walker->walk( maybe_unserialize( $row['meta_value'] ), $context, $this->source(), $into, $image_scope );
			}

			$done += $fetched;
			$this->report( $progress, $done, $total );
		} while ( self::BATCH_SIZE === $fetched );
	}
}
