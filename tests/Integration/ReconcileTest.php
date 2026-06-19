<?php
/**
 * Drift reconciliation integration test.
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
 * Verifies reconcile() tops up missing rows, drops orphaned rows, and is a no-op
 * when the index already matches the library (IDX-06).
 */
final class ReconcileTest extends WP_UnitTestCase {

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
	 * An attachment whose hooks never fired is topped up by reconcile().
	 *
	 * @return void
	 */
	public function test_reconcile_tops_up_missing_rows(): void {
		// Two backfilled attachments...
		for ( $i = 0; $i < 2; $i++ ) {
			$this->factory->attachment->create_object(
				array(
					'file'           => "2026/06/seeded-{$i}.jpg",
					'post_mime_type' => 'image/jpeg',
				)
			);
		}
		IndexBuilder::from_wordpress()->backfill();

		// ...plus one created AFTER backfill, as if a hook was missed (import).
		$missed = $this->factory->attachment->create_object(
			array(
				'file'           => '2026/06/missed.jpg',
				'post_mime_type' => 'image/jpeg',
			)
		);

		$index = MediaIndex::from_wordpress();
		$this->assertNotContains( $missed, $index->indexed_ids(), 'Sanity: missed row absent before reconcile.' );

		IndexBuilder::reconcile();

		$this->assertContains( $missed, MediaIndex::from_wordpress()->indexed_ids(), 'Reconcile must top up the missing row.' );
	}

	/**
	 * An index row whose attachment no longer exists is dropped by reconcile().
	 *
	 * @return void
	 */
	public function test_reconcile_drops_orphan_rows(): void {
		$keep = $this->factory->attachment->create_object(
			array(
				'file'           => '2026/06/keep.jpg',
				'post_mime_type' => 'image/jpeg',
			)
		);
		$gone = $this->factory->attachment->create_object(
			array(
				'file'           => '2026/06/gone.jpg',
				'post_mime_type' => 'image/jpeg',
			)
		);

		IndexBuilder::from_wordpress()->backfill();

		// Remove the attachment row directly, leaving the index row orphaned.
		wp_delete_post( $gone, true );

		$this->assertContains( $gone, MediaIndex::from_wordpress()->indexed_ids(), 'Sanity: orphan present before reconcile.' );

		IndexBuilder::reconcile();

		$ids = MediaIndex::from_wordpress()->indexed_ids();
		$this->assertNotContains( $gone, $ids, 'Reconcile must drop the orphan row.' );
		$this->assertContains( $keep, $ids, 'Reconcile must keep live rows.' );
	}

	/**
	 * Reconcile is a no-op when the index already matches the library.
	 *
	 * @return void
	 */
	public function test_reconcile_is_idempotent_when_in_sync(): void {
		for ( $i = 0; $i < 3; $i++ ) {
			$this->factory->attachment->create_object(
				array(
					'file'           => "2026/06/sync-{$i}.jpg",
					'post_mime_type' => 'image/jpeg',
				)
			);
		}
		IndexBuilder::from_wordpress()->backfill();

		$before = MediaIndex::from_wordpress()->count_rows();

		IndexBuilder::reconcile();
		IndexBuilder::reconcile();

		$this->assertSame(
			$before,
			MediaIndex::from_wordpress()->count_rows(),
			'Reconcile on an in-sync index must change nothing.'
		);
	}

	/**
	 * Equal-magnitude drift (one import + one deletion) is repaired by reconcile().
	 *
	 * One attachment is created after backfill (a missed import, absent from the
	 * index) and one already-indexed attachment is deleted (an orphan row that
	 * lingers). Attachment COUNT(*) therefore equals the index row count, yet the
	 * ID sets differ — the exact drift the removed COUNT(*) short-circuit hid
	 * (WR-01). Reconcile must top up the new live attachment AND drop the orphan.
	 *
	 * @return void
	 */
	public function test_reconcile_repairs_equal_magnitude_drift(): void {
		// Seed three attachments and index them all.
		for ( $i = 0; $i < 3; $i++ ) {
			$this->factory->attachment->create_object(
				array(
					'file'           => "2026/06/drift-{$i}.jpg",
					'post_mime_type' => 'image/jpeg',
				)
			);
		}
		// Capture one indexed ID that will become an orphan.
		$gone = $this->factory->attachment->create_object(
			array(
				'file'           => '2026/06/drift-gone.jpg',
				'post_mime_type' => 'image/jpeg',
			)
		);
		IndexBuilder::from_wordpress()->backfill();

		// One import AFTER backfill: a missed-hook attachment absent from the index.
		$added = $this->factory->attachment->create_object(
			array(
				'file'           => '2026/06/drift-added.jpg',
				'post_mime_type' => 'image/jpeg',
			)
		);

		// One deletion of an already-indexed attachment: leaves an orphan index row.
		wp_delete_post( $gone, true );

		// Pre-conditions: counts are EQUAL (equal-magnitude) but the ID sets DIFFER.
		global $wpdb;
		$attachment_count = (int) $wpdb->get_var(
			"SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'attachment'"
		);

		$index = MediaIndex::from_wordpress();
		$this->assertSame(
			$attachment_count,
			$index->count_rows(),
			'Pre-reconcile: one add + one delete must net to an equal count (the case the COUNT short-circuit would hide).'
		);
		$before_ids = $index->indexed_ids();
		$this->assertNotContains( $added, $before_ids, 'Pre-reconcile: the imported attachment is NOT yet indexed (missing row).' );
		$this->assertContains( $gone, $before_ids, 'Pre-reconcile: the deleted attachment is still indexed (orphan row).' );

		IndexBuilder::reconcile();

		$after_ids = MediaIndex::from_wordpress()->indexed_ids();
		$this->assertContains( $added, $after_ids, 'Equal-magnitude drift: reconcile must top up the imported attachment.' );
		$this->assertNotContains( $gone, $after_ids, 'Equal-magnitude drift: reconcile must drop the orphan row.' );
	}
}
