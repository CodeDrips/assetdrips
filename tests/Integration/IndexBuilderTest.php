<?php
/**
 * Backfill driver integration test.
 *
 * @package AssetDrips
 */

declare( strict_types=1 );

namespace AssetDrips\Tests\Integration;

use AssetDrips\Db\Schema;
use AssetDrips\Index\IndexBuilder;
use AssetDrips\Index\MediaIndex;
use WP_UnitTestCase;

/**
 * Verifies the backfill indexes EVERY attachment — including metadata-less ones
 * the each_batch helper would silently drop — and resumes without duplicates.
 */
final class IndexBuilderTest extends WP_UnitTestCase {

	/**
	 * Install the media table fresh for every test.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();
		Schema::install();
	}

	/**
	 * Backfill row count equals the attachment count, counting a metadata-less
	 * attachment that AttachmentCatalogue::each_batch would drop.
	 *
	 * @return void
	 */
	public function test_backfill_indexes_every_attachment_including_metadata_less(): void {
		// Three ordinary attachments with metadata.
		for ( $i = 0; $i < 3; $i++ ) {
			$this->factory->attachment->create_object(
				array(
					'file'           => "2026/06/image-{$i}.jpg",
					'post_mime_type' => 'image/jpeg',
				)
			);
		}

		// One metadata-less attachment: a post_type=attachment row with NEITHER
		// _wp_attached_file NOR _wp_attachment_metadata. each_batch would skip it.
		$bare = self::factory()->post->create( array( 'post_type' => 'attachment' ) );
		delete_post_meta( $bare, '_wp_attached_file' );
		delete_post_meta( $bare, '_wp_attachment_metadata' );

		$expected = $this->attachment_count();

		$indexed = IndexBuilder::from_wordpress()->backfill( 2 );

		$this->assertSame( $expected, $indexed, 'Backfill should index every attachment.' );
		$this->assertSame(
			$expected,
			MediaIndex::from_wordpress()->count_rows(),
			'Index row count must equal the attachment count, including the metadata-less row.'
		);

		// The metadata-less attachment must have its own row.
		$this->assertContains( $bare, MediaIndex::from_wordpress()->indexed_ids() );
	}

	/**
	 * Re-running backfill (resume) creates no duplicate rows.
	 *
	 * @return void
	 */
	public function test_resume_creates_no_duplicate_rows(): void {
		$ids = array();
		for ( $i = 0; $i < 5; $i++ ) {
			$ids[] = $this->factory->attachment->create_object(
				array(
					'file'           => "2026/06/resume-{$i}.jpg",
					'post_mime_type' => 'image/jpeg',
				)
			);
		}
		sort( $ids );
		$total = $this->attachment_count();

		// Seed a checkpoint mid-range, as if a prior run was killed after id #2.
		update_option( IndexBuilder::CHECKPOINT_OPTION, array( 'last_id' => $ids[1] ), false );

		// Resume from the checkpoint, then run a full backfill again.
		IndexBuilder::from_wordpress()->backfill( 2, true );
		IndexBuilder::from_wordpress()->backfill( 2 );

		$this->assertSame(
			$total,
			MediaIndex::from_wordpress()->count_rows(),
			'Resume + re-run must not create duplicate rows (idempotent ODKU).'
		);
	}

	/**
	 * Count attachments in the library.
	 *
	 * @return int
	 */
	private function attachment_count(): int {
		global $wpdb;

		return (int) $wpdb->get_var(
			"SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'attachment'"
		);
	}
}
