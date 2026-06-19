<?php
/**
 * Folder CRUD + sort-weight row lifecycle integration tests.
 *
 * Exercises FOLDER-01: create/rename/delete term API and the associated
 * assetdrips_folders sort-weight row lifecycle. Requires a live WP+DB test
 * harness (composer test:integration). Discovered but skipped automatically
 * when WP_TESTS_DIR is absent because the integration bootstrap exits before
 * WP_UnitTestCase is available.
 *
 * @package AssetDrips
 */

declare( strict_types=1 );

namespace AssetDrips\Tests\Integration;

use AssetDrips\Db\Schema;
use WP_UnitTestCase;

/**
 * Live-DB integration tests for the assetdrips_folder taxonomy CRUD and the
 * corresponding assetdrips_folders sort-weight row lifecycle (FOLDER-01).
 *
 * Test data:
 *   - $this->parent_term_id — top-level folder created via wp_insert_term
 *   - child terms created inline per test as needed
 */
final class FolderCrudTest extends WP_UnitTestCase {

	/**
	 * Top-level folder term ID created in setUp for each test.
	 *
	 * @var int
	 */
	private int $parent_term_id;

	/**
	 * Install schema and create a top-level folder for each test.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();
		Schema::install();

		$result = wp_insert_term( 'Test Parent Folder', 'assetdrips_folder', array( 'parent' => 0 ) );
		$this->assertFalse(
			is_wp_error( $result ),
			'wp_insert_term should succeed for the parent folder in setUp'
		);
		$this->parent_term_id = (int) $result['term_id'];
	}

	/**
	 * wp_insert_term creates the WP term AND a sort-weight row in assetdrips_folders.
	 *
	 * This test documents the expected contract: when FolderScreen (Plan 03)
	 * calls wp_insert_term it must also insert a row into assetdrips_folders
	 * with sort_weight = 0. This live-DB test verifies that row lifecycle
	 * by inserting it directly (simulating what FolderScreen will do).
	 *
	 * @return void
	 */
	public function test_create_inserts_term_and_sort_weight_row(): void {
		global $wpdb;

		$result = wp_insert_term( 'Alpha Folder', 'assetdrips_folder', array( 'parent' => 0 ) );
		$this->assertFalse( is_wp_error( $result ), 'wp_insert_term must succeed' );
		$term_id = (int) $result['term_id'];

		// Simulate the sort-weight insert that FolderScreen::ajax_folder_create will perform.
		$wpdb->insert(
			Schema::folders_table(),
			array(
				'term_id'     => $term_id,
				'sort_weight' => 0,
			),
			array( '%d', '%d' )
		);

		$table = Schema::folders_table();

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Test fixture assertion; table name is a Schema constant; term_id bound via prepare() %d.
		$sort_weight = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT sort_weight FROM {$table} WHERE term_id = %d",
				$term_id
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		$this->assertSame( '0', (string) $sort_weight, 'Newly created folder must have sort_weight = 0 in assetdrips_folders' );

		// Cleanup: remove the sort-weight row.
		$wpdb->delete( Schema::folders_table(), array( 'term_id' => $term_id ), array( '%d' ) );
	}

	/**
	 * wp_update_term changes the folder name and parent.
	 *
	 * @return void
	 */
	public function test_rename_updates_name_and_parent(): void {
		// Create a child folder under parent.
		$child = wp_insert_term(
			'Original Child Name',
			'assetdrips_folder',
			array( 'parent' => $this->parent_term_id )
		);
		$this->assertFalse( is_wp_error( $child ), 'Child folder insert must succeed' );
		$child_id = (int) $child['term_id'];

		// Rename and reparent to top-level.
		$updated = wp_update_term(
			$child_id,
			'assetdrips_folder',
			array(
				'name'   => 'Renamed Child',
				'parent' => 0,
			)
		);
		$this->assertFalse( is_wp_error( $updated ), 'wp_update_term must succeed' );

		$term = get_term( $child_id, 'assetdrips_folder' );
		$this->assertInstanceOf( \WP_Term::class, $term, 'Term must be retrievable after rename' );
		$this->assertSame( 'Renamed Child', $term->name, 'Term name must be updated' );
		$this->assertSame( 0, (int) $term->parent, 'Term parent must be updated to 0 (top-level)' );
	}

	/**
	 * After deleting a folder, its sort-weight row must not remain in assetdrips_folders.
	 *
	 * This test documents the contract: FolderScreen::ajax_folder_delete must explicitly
	 * delete the assetdrips_folders row BEFORE calling wp_delete_term (Pitfall 2 — WP
	 * does not cascade to plugin tables). This live-DB test verifies the row removal.
	 *
	 * @return void
	 */
	public function test_delete_removes_sort_weight_row(): void {
		global $wpdb;

		$result = wp_insert_term( 'Folder To Delete', 'assetdrips_folder', array( 'parent' => 0 ) );
		$this->assertFalse( is_wp_error( $result ), 'Insert must succeed' );
		$term_id = (int) $result['term_id'];

		// Simulate the sort-weight insert.
		$wpdb->insert(
			Schema::folders_table(),
			array(
				'term_id'     => $term_id,
				'sort_weight' => 0,
			),
			array( '%d', '%d' )
		);

		// Simulate the FolderScreen delete sequence:
		// Step 1: delete sort-weight row.
		$wpdb->delete( Schema::folders_table(), array( 'term_id' => $term_id ), array( '%d' ) );

		// Step 2: delete the term.
		wp_delete_term( $term_id, 'assetdrips_folder' );

		$table = Schema::folders_table();

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Test assertion; table name is a Schema constant; term_id bound via prepare() %d.
		$row_count = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$table} WHERE term_id = %d",
				$term_id
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		$this->assertSame( 0, $row_count, 'No sort-weight row must remain in assetdrips_folders after delete' );
	}

	/**
	 * Deleting a folder reparents its direct children to the deleted folder's parent (Pitfall 6).
	 *
	 * This test documents the D-07 reparent contract: direct children must be promoted
	 * to the deleted folder's parent, NOT necessarily to top-level (0).
	 * FolderScreen::ajax_folder_delete must use get_terms(['parent' => $term_id]),
	 * NOT get_term_children() which returns ALL descendants.
	 *
	 * @return void
	 */
	public function test_delete_reparents_direct_children(): void {
		// Create a two-level tree: grandparent -> parent_term_id -> child.
		$grandparent = wp_insert_term( 'Grandparent', 'assetdrips_folder', array( 'parent' => 0 ) );
		$this->assertFalse( is_wp_error( $grandparent ), 'Grandparent insert must succeed' );
		$grandparent_id = (int) $grandparent['term_id'];

		// Make $parent_term_id a child of grandparent.
		wp_update_term( $this->parent_term_id, 'assetdrips_folder', array( 'parent' => $grandparent_id ) );

		$child = wp_insert_term(
			'Direct Child',
			'assetdrips_folder',
			array( 'parent' => $this->parent_term_id )
		);
		$this->assertFalse( is_wp_error( $child ), 'Child insert must succeed' );
		$child_id = (int) $child['term_id'];

		// Simulate the reparent step from FolderScreen::ajax_folder_delete (D-07).
		$direct_children = get_terms(
			array(
				'taxonomy'   => 'assetdrips_folder',
				'parent'     => $this->parent_term_id,
				'hide_empty' => false,
				'fields'     => 'ids',
			)
		);
		$this->assertFalse( is_wp_error( $direct_children ) );
		$deleted_term   = get_term( $this->parent_term_id, 'assetdrips_folder' );
		$new_parent     = ( $deleted_term instanceof \WP_Term ) ? (int) $deleted_term->parent : 0;

		foreach ( (array) $direct_children as $child_term_id ) {
			wp_update_term( (int) $child_term_id, 'assetdrips_folder', array( 'parent' => $new_parent ) );
		}

		// Now delete the parent.
		wp_delete_term( $this->parent_term_id, 'assetdrips_folder' );

		// Child must now belong to grandparent (not top-level 0).
		$child_after = get_term( $child_id, 'assetdrips_folder' );
		$this->assertInstanceOf( \WP_Term::class, $child_after, 'Child term must still exist after parent delete' );
		$this->assertSame(
			$grandparent_id,
			(int) $child_after->parent,
			'Direct child must be reparented to the deleted folder\'s parent (grandparent), not to 0 (Pitfall 6 / D-07)'
		);

		// Cleanup.
		wp_delete_term( $grandparent_id, 'assetdrips_folder' );
		wp_delete_term( $child_id, 'assetdrips_folder' );
	}
}
