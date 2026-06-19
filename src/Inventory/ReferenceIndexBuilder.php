<?php
/**
 * Builds a ReferenceIndex from the live attachment catalogue.
 *
 * @package AssetDrips
 */

declare( strict_types=1 );

namespace AssetDrips\Inventory;

defined( 'ABSPATH' ) || exit;

/**
 * Assembles a {@see ReferenceIndex} by streaming the catalogue in batches.
 *
 * Kept separate from the index so the index itself stays pure and testable; this
 * is the thin WordPress-facing wrapper.
 */
final class ReferenceIndexBuilder {

	/**
	 * Build the index from the current site's attachments.
	 *
	 * @param int                     $batch_size Attachments per batch while streaming.
	 * @param callable(int):void|null $progress   Called per batch with the cumulative count.
	 * @return ReferenceIndex
	 */
	public static function from_wordpress( int $batch_size = 500, ?callable $progress = null ): ReferenceIndex {
		$uploads  = wp_get_upload_dir();
		$prefixes = ReferenceIndex::prefixes_from_uploads(
			(string) $uploads['baseurl'],
			(string) $uploads['basedir']
		);

		$index     = new ReferenceIndex( $prefixes );
		$catalogue = AttachmentCatalogue::from_wordpress();
		$counted   = 0;

		$catalogue->each_batch(
			$batch_size,
			static function ( array $keys ) use ( $index, &$counted, $progress ): void {
				foreach ( $keys as $match_keys ) {
					$index->register( $match_keys );
				}
				$counted += count( $keys );
				if ( null !== $progress ) {
					$progress( $counted );
				}
			}
		);

		return $index;
	}
}
