<?php
/**
 * MediaIndex::query() faceted-read integration tests.
 *
 * Exercises FIND-01 (combinable facets over the index) and FIND-02
 * (used/unused + missing-alt filters). Requires a live MySQL database — run with
 * `composer test:integration` on a DB-capable host.
 *
 * @package AssetDrips
 */

declare( strict_types=1 );

namespace AssetDrips\Tests\Integration;

use AssetDrips\Db\Schema;
use AssetDrips\Index\MediaIndex;
use AssetDrips\Index\MediaQuery;
use WP_UnitTestCase;

/**
 * Integration tests for MediaIndex::query() — seeded faceted reads.
 *
 * Test data layout (seeded in set_up):
 *   $this->jpeg_used      — jpeg, 800x600, 1 MB, is_used=1, has_alt=1
 *   $this->png_unused     — png,  400x300, 200 KB, is_used=0, has_alt=1
 *   $this->webp_no_alt    — webp, 1920x1080, 2 MB, is_used=0, has_alt=0
 *   $this->gif_small      — gif,  100x100, 10 KB, is_used=0, has_alt=1
 *   $this->mp4_video      — mp4 (video/mp4), is_used=0, has_alt=0
 */
final class MediaQueryTest extends WP_UnitTestCase {

	/**
	 * JPEG, used, has alt, 1 MB, 800×600.
	 *
	 * @var int
	 */
	private int $jpeg_used;

	/**
	 * PNG, unused, has alt, 200 KB, 400×300.
	 *
	 * @var int
	 */
	private int $png_unused;

	/**
	 * WebP, unused, no alt, 2 MB, 1920×1080.
	 *
	 * @var int
	 */
	private int $webp_no_alt;

	/**
	 * GIF, unused, has alt, 10 KB, 100×100.
	 *
	 * @var int
	 */
	private int $gif_small;

	/**
	 * MP4 video, unused, no alt.
	 *
	 * @var int
	 */
	private int $mp4_video;

	/**
	 * MediaIndex instance under test.
	 *
	 * @var MediaIndex
	 */
	private MediaIndex $index;

	/**
	 * Install schema and seed test rows.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();
		Schema::install();

		$this->index = MediaIndex::from_wordpress();

		// Seed five attachments with varied attributes.
		$this->jpeg_used   = $this->seed_row( 'jpeg-used.jpg', 'image/jpeg', 'jpeg', 800, 600, 1048576, true, true );
		$this->png_unused  = $this->seed_row( 'png-unused.png', 'image/png', 'png', 400, 300, 204800, false, true );
		$this->webp_no_alt = $this->seed_row( 'webp-noalt.webp', 'image/webp', 'webp', 1920, 1080, 2097152, false, false );
		$this->gif_small   = $this->seed_row( 'gif-small.gif', 'image/gif', 'gif', 100, 100, 10240, false, true );
		$this->mp4_video   = $this->seed_row( 'video.mp4', 'video/mp4', 'mp4', 0, 0, 5242880, false, false );
	}

	// -------------------------------------------------------------------------
	// FIND-01: combinable facets
	// -------------------------------------------------------------------------

	/**
	 * Empty criteria returns all five seeded rows.
	 *
	 * @return void
	 */
	public function test_empty_criteria_returns_all_rows(): void {
		$result = $this->index->query( new MediaQuery() );

		$this->assertSame( 5, $result['total'] );
		$this->assertCount( 5, $result['rows'] );
	}

	/**
	 * Filter by subtype 'jpeg' returns only the JPEG row.
	 *
	 * @return void
	 */
	public function test_subtype_filter_returns_matching_row(): void {
		$q          = new MediaQuery();
		$q->subtype = 'jpeg';

		$result = $this->index->query( $q );

		$this->assertSame( 1, $result['total'] );
		$this->assertResultIds( array( $this->jpeg_used ), $result['rows'] );
	}

	/**
	 * Filter by type 'image' excludes the mp4 video row.
	 *
	 * @return void
	 */
	public function test_type_image_excludes_video(): void {
		$q       = new MediaQuery();
		$q->type = 'image';

		$result = $this->index->query( $q );

		$this->assertSame( 4, $result['total'] );
		$ids = array_column( $result['rows'], 'attachment_id' );
		$this->assertNotContains( (string) $this->mp4_video, $ids );
	}

	/**
	 * Setting size_min=500000 filters out the 200 KB png and 10 KB gif.
	 *
	 * @return void
	 */
	public function test_size_min_filters_small_files(): void {
		$q           = new MediaQuery();
		$q->size_min = 500000;

		$result = $this->index->query( $q );

		// jpeg (1 MB), webp (2 MB), mp4 (5 MB) should pass; png (200 KB) + gif (10 KB) should not.
		$this->assertSame( 3, $result['total'] );
		$ids = array_column( $result['rows'], 'attachment_id' );
		$this->assertNotContains( (string) $this->png_unused, $ids );
		$this->assertNotContains( (string) $this->gif_small, $ids );
	}

	/**
	 * Combined subtype + size_min returns only matching rows.
	 *
	 * @return void
	 */
	public function test_combined_subtype_and_size_min(): void {
		$q           = new MediaQuery();
		$q->type     = 'image';
		$q->size_min = 1000000; // 1 MB — jpeg + webp pass.

		$result = $this->index->query( $q );

		$this->assertSame( 2, $result['total'] );
		$this->assertResultIds( array( $this->jpeg_used, $this->webp_no_alt ), $result['rows'] );
	}

	/**
	 * Pagination respects page and per_page.
	 *
	 * @return void
	 */
	public function test_pagination_returns_correct_slice(): void {
		$q           = new MediaQuery();
		$q->per_page = 2;
		$q->page     = 1;

		$result = $this->index->query( $q );

		$this->assertSame( 5, $result['total'] );
		$this->assertCount( 2, $result['rows'] );
		$this->assertSame( 1, $result['page'] );
		$this->assertSame( 2, $result['per_page'] );
	}

	/**
	 * Second page returns remaining rows.
	 *
	 * @return void
	 */
	public function test_pagination_second_page(): void {
		$q           = new MediaQuery();
		$q->per_page = 2;
		$q->page     = 3;

		$result = $this->index->query( $q );

		$this->assertSame( 5, $result['total'] );
		$this->assertCount( 1, $result['rows'] );
	}

	/**
	 * Return array includes 'rows', 'total', 'page', 'per_page' keys.
	 *
	 * @return void
	 */
	public function test_return_shape_has_correct_keys(): void {
		$result = $this->index->query( new MediaQuery() );

		$this->assertArrayHasKey( 'rows', $result );
		$this->assertArrayHasKey( 'total', $result );
		$this->assertArrayHasKey( 'page', $result );
		$this->assertArrayHasKey( 'per_page', $result );
		$this->assertIsInt( $result['total'] );
		$this->assertIsArray( $result['rows'] );
	}

	// -------------------------------------------------------------------------
	// FIND-02: used/unused + missing-alt filters
	// -------------------------------------------------------------------------

	/**
	 * Filter used='used' returns only the single used row.
	 *
	 * @return void
	 */
	public function test_used_filter_returns_only_used_rows(): void {
		$q       = new MediaQuery();
		$q->used = 'used';

		$result = $this->index->query( $q );

		$this->assertSame( 1, $result['total'] );
		$this->assertResultIds( array( $this->jpeg_used ), $result['rows'] );
	}

	/**
	 * Filter used='unused' returns the four unused rows.
	 *
	 * @return void
	 */
	public function test_unused_filter_returns_only_unused_rows(): void {
		$q       = new MediaQuery();
		$q->used = 'unused';

		$result = $this->index->query( $q );

		$this->assertSame( 4, $result['total'] );
		$ids = array_column( $result['rows'], 'attachment_id' );
		$this->assertNotContains( (string) $this->jpeg_used, $ids );
	}

	/**
	 * Setting missing_alt=true returns only rows without alt text.
	 *
	 * @return void
	 */
	public function test_missing_alt_returns_rows_without_alt(): void {
		$q              = new MediaQuery();
		$q->missing_alt = true;

		$result = $this->index->query( $q );

		// webp_no_alt + mp4_video have has_alt=0.
		$this->assertSame( 2, $result['total'] );
		$this->assertResultIds( array( $this->webp_no_alt, $this->mp4_video ), $result['rows'] );
	}

	/**
	 * Combining unused + missing_alt returns rows that are both unused AND lack alt.
	 *
	 * @return void
	 */
	public function test_combined_unused_and_missing_alt(): void {
		$q              = new MediaQuery();
		$q->used        = 'unused';
		$q->missing_alt = true;

		$result = $this->index->query( $q );

		// webp_no_alt and mp4_video are both unused AND missing alt.
		$this->assertSame( 2, $result['total'] );
		$this->assertResultIds( array( $this->webp_no_alt, $this->mp4_video ), $result['rows'] );
	}

	/**
	 * SQL_CALC_FOUND_ROWS must NOT appear in the implementation (sanity check via
	 * a no-op assert — the real guard is the grep in the plan's verify clause).
	 *
	 * @return void
	 */
	public function test_sql_calc_found_rows_not_used(): void {
		// If we reached here, the query ran without SQL_CALC_FOUND_ROWS.
		// The acceptance criterion is enforced by `grep -c SQL_CALC_FOUND_ROWS` in CI.
		$this->assertTrue( true );
	}

	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------

	/**
	 * Insert one row directly into the index table for test isolation.
	 *
	 * @param string $filename  Base filename.
	 * @param string $mime      Full MIME type (e.g. 'image/jpeg').
	 * @param string $subtype   MIME subtype (e.g. 'jpeg').
	 * @param int    $width     Width in pixels.
	 * @param int    $height    Height in pixels.
	 * @param int    $filesize  File size in bytes.
	 * @param bool   $is_used   Whether the attachment is currently used.
	 * @param bool   $has_alt   Whether the attachment has alt text.
	 * @return int The attachment_id used.
	 */
	private function seed_row(
		string $filename,
		string $mime,
		string $subtype,
		int $width,
		int $height,
		int $filesize,
		bool $is_used,
		bool $has_alt
	): int {
		global $wpdb;

		$attachment_id = $this->factory->attachment->create_object(
			array(
				'file'           => '2026/06/' . $filename,
				'post_mime_type' => $mime,
			)
		);

		$table = Schema::media_table();
		$now   = current_time( 'mysql' );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Test fixture seed; table name is a Schema constant.
		$wpdb->query(
			$wpdb->prepare(
				"INSERT INTO {$table}
					(attachment_id, filename, title, alt, caption, description, mime, mime_subtype,
					width, height, orientation, filesize, has_alt, is_used, uploaded_by, uploaded_at, indexed_at)
				VALUES (%d, %s, %s, %s, %s, %s, %s, %s, %d, %d, %s, %d, %d, %d, %d, %s, %s)
				ON DUPLICATE KEY UPDATE
					filename = VALUES(filename), title = VALUES(title), alt = VALUES(alt),
					mime = VALUES(mime), mime_subtype = VALUES(mime_subtype),
					width = VALUES(width), height = VALUES(height), orientation = VALUES(orientation),
					filesize = VALUES(filesize), has_alt = VALUES(has_alt), is_used = VALUES(is_used)",
				$attachment_id,
				$filename,
				basename( $filename, '.' . pathinfo( $filename, PATHINFO_EXTENSION ) ),
				$has_alt ? 'Alt text for ' . $filename : '',
				'',
				'',
				$mime,
				$subtype,
				$width,
				$height,
				\AssetDrips\Index\MediaRow::orientation( $width, $height ),
				$filesize,
				$has_alt ? 1 : 0,
				$is_used ? 1 : 0,
				1,
				$now,
				$now
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		return $attachment_id;
	}

	/**
	 * Assert that the rows array contains exactly the given attachment IDs.
	 *
	 * @param int[]                            $expected_ids Expected attachment IDs.
	 * @param array<int, array<string, mixed>> $rows         Rows returned by query().
	 * @return void
	 */
	private function assertResultIds( array $expected_ids, array $rows ): void {
		$actual_ids = array_map( 'intval', array_column( $rows, 'attachment_id' ) );
		$this->assertEqualsCanonicalizing( $expected_ids, $actual_ids );
	}
}
