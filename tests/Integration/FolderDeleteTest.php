<?php
/**
 * pre_delete_term folder_id NULL reversion integration test (D-08 primary risk).
 *
 * Exercises the core safety guarantee: when a folder term is deleted,
 * SortHooks::on_pre_delete_term must NULL out folder_id in assetdrips_media
 * for all affected attachments BEFORE WP removes the term_relationships. This
 * is the primary risk scenario for FOLDER-01.
 *
 * Requires a live WP+DB test harness (composer test:integration). Discovered
 * but skipped automatically when WP_TESTS_DIR is absent because the integration
 * bootstrap exits before WP_UnitTestCase is available.
 *
 * @package AssetDrips
 */

declare( strict_types=1 );

namespace AssetDrips\Tests\Integration;

use AssetDrips\Db\Schema;
use AssetDrips\Sort\SortHooks;
use WP_UnitTestCase;

/**
 * Integration tests for the pre_delete_term → folder_id NULL reversion path (D-08).
 *
 * Test invariants:
 *   - A seeded assetdrips_media row with folder_id = {term_id} must have
 *     folder_id set to NULL after wp_delete_term fires.
 *   - Deleting a term in a non-folder taxonomy must NOT affect folder_id.
 */
final class FolderDeleteTest extends WP_UnitTestCase {

	/**
	 * Install schema for each test.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();
		Schema::install();
	}

	/**
	 * wp_delete_term('assetdrips_folder') triggers folder_id = NULL for affected rows.
	 *
	 * Seeds one assetdrips_media row with a known folder_id, registers SortHooks
	 * (which wires pre_delete_term → on_pre_delete_term), deletes the folder term,
	 * then asserts folder_id became NULL.
	 *
	 * @return void
	 */
	public function test_pre_delete_term_nulls_folder_id(): void {
		global $wpdb;

		// Skip gracefully if on_pre_delete_term has not landed yet (Plan 02).
		if ( ! method_exists( SortHooks::class, 'on_pre_delete_term' ) ) {
			$this->markTestSkipped( 'SortHooks::on_pre_delete_term not yet implemented (lands in Plan 02)' );
		}

		// Create a folder term.
		$term_result = wp_insert_term( 'Delete Target', 'assetdrips_folder' );
		$this->assertFalse( is_wp_error( $term_result ), 'Folder term insert must succeed' );
		$term_id = (int) $term_result['term_id'];

		// Create an attachment and seed it in assetdrips_media with this folder_id.
		$attachment_id = $this->factory->attachment->create_object(
			array(
				'file'           => '2026/06/folder-delete-test.jpg',
				'post_mime_type' => 'image/jpeg',
			)
		);

		$media_table = Schema::media_table();
		$now         = current_time( 'mysql' );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Test fixture seed; table name is a Schema constant.
		$wpdb->query(
			$wpdb->prepare(
				"INSERT INTO {$media_table}
					(attachment_id, filename, title, alt, caption, description, mime, mime_subtype,
					width, height, orientation, filesize, has_alt, is_used, folder_id, uploaded_by, uploaded_at, indexed_at)
				VALUES (%d, %s, %s, %s, %s, %s, %s, %s, %d, %d, %s, %d, %d, %d, %d, %d, %s, %s)
				ON DUPLICATE KEY UPDATE folder_id = VALUES(folder_id)",
				$attachment_id,
				'folder-delete-test.jpg',
				'Folder Delete Test',
				'',
				'',
				'',
				'image/jpeg',
				'jpeg',
				800,
				600,
				'landscape',
				102400,
				0,
				0,
				$term_id,
				1,
				$now,
				$now
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		// Register SortHooks so the pre_delete_term action fires.
		( new SortHooks() )->register();

		// Delete the folder term — this must trigger on_pre_delete_term.
		wp_delete_term( $term_id, 'assetdrips_folder' );

		// Assert folder_id is NULL.
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Assertion query; table name is a Schema constant; attachment_id bound via prepare() %d.
		$folder_id_after = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT folder_id FROM {$media_table} WHERE attachment_id = %d",
				$attachment_id
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		$this->assertNull(
			$folder_id_after,
			'folder_id must be NULL after wp_delete_term fires pre_delete_term → on_pre_delete_term (D-08)'
		);
	}

	/**
	 * Deleting a non-folder taxonomy term must NOT null out folder_id.
	 *
	 * Ensures on_pre_delete_term's taxonomy guard works correctly: only
	 * 'assetdrips_folder' deletions trigger the folder_id sweep.
	 *
	 * @return void
	 */
	public function test_pre_delete_term_ignores_non_folder_taxonomy(): void {
		global $wpdb;

		// Skip gracefully if on_pre_delete_term has not landed yet (Plan 02).
		if ( ! method_exists( SortHooks::class, 'on_pre_delete_term' ) ) {
			$this->markTestSkipped( 'SortHooks::on_pre_delete_term not yet implemented (lands in Plan 02)' );
		}

		// Create a real folder term and assign it to an attachment row.
		$folder_term = wp_insert_term( 'Retained Folder', 'assetdrips_folder' );
		$this->assertFalse( is_wp_error( $folder_term ), 'Folder term insert must succeed' );
		$folder_id = (int) $folder_term['term_id'];

		// Create an attachment and seed it in assetdrips_media with the folder_id.
		$attachment_id = $this->factory->attachment->create_object(
			array(
				'file'           => '2026/06/non-folder-delete-test.jpg',
				'post_mime_type' => 'image/jpeg',
			)
		);

		$media_table = Schema::media_table();
		$now         = current_time( 'mysql' );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Test fixture seed; table name is a Schema constant.
		$wpdb->query(
			$wpdb->prepare(
				"INSERT INTO {$media_table}
					(attachment_id, filename, title, alt, caption, description, mime, mime_subtype,
					width, height, orientation, filesize, has_alt, is_used, folder_id, uploaded_by, uploaded_at, indexed_at)
				VALUES (%d, %s, %s, %s, %s, %s, %s, %s, %d, %d, %s, %d, %d, %d, %d, %d, %s, %s)
				ON DUPLICATE KEY UPDATE folder_id = VALUES(folder_id)",
				$attachment_id,
				'non-folder-delete-test.jpg',
				'Non-Folder Delete Test',
				'',
				'',
				'',
				'image/jpeg',
				'jpeg',
				800,
				600,
				'landscape',
				102400,
				0,
				0,
				$folder_id,
				1,
				$now,
				$now
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		// Register SortHooks.
		( new SortHooks() )->register();

		// Register a temporary non-folder taxonomy and delete a term from it.
		register_taxonomy( 'assetdrips_test_tag', array( 'attachment' ), array() );
		$tag_term = wp_insert_term( 'Test Tag', 'assetdrips_test_tag' );
		if ( ! is_wp_error( $tag_term ) ) {
			wp_delete_term( (int) $tag_term['term_id'], 'assetdrips_test_tag' );
		}

		// folder_id must remain unchanged.
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Assertion query; table name is a Schema constant; attachment_id bound via prepare() %d.
		$folder_id_after = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT folder_id FROM {$media_table} WHERE attachment_id = %d",
				$attachment_id
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		$this->assertSame(
			(string) $folder_id,
			(string) $folder_id_after,
			'folder_id must NOT be affected when a non-folder taxonomy term is deleted (taxonomy guard)'
		);

		// Cleanup: remove the folder assignment.
		wp_delete_term( $folder_id, 'assetdrips_folder' );
	}
}
