<?php
/**
 * RED tests for extended NextGenColumn::render_column() (DASH-06 / D-00).
 *
 * These tests are RED until Wave 3 extends render_column() to emit status
 * badge + bytes-saved + optimize/restore action links.  They define the
 * required behaviour; Wave 3 turns them GREEN.
 *
 * EXIT_EXPECTED_RED: Tests WILL fail because render_column() does not yet emit
 * status badges, bytes-saved, or action links.  Failures must be assertion
 * failures on output strings — NOT parse/bootstrap fatals.
 *
 * Additionally, this file contains the D-00 get_row_queue SMOKE TEST that
 * seeds two entries into the queue and asserts both are consumed in order
 * across two get_row() calls.  This smoke test MUST PASS after Task 1
 * (unit-bootstrap.php update) — it verifies the multi-read seam the
 * extended render_column() relies on.
 *
 * @package AssetDrips
 */

declare( strict_types=1 );

namespace AssetDrips\Tests\Unit\Admin;

use AssetDrips\Admin\NextGenColumn;
use PHPUnit\Framework\TestCase;

/**
 * RED tests: extended render_column() status + bytes-saved + actions (DASH-06).
 * GREEN smoke test: get_row_queue in-order consumption (D-00).
 */
final class NextGenColumnDashTest extends TestCase {

	/**
	 * Reset wpdb stub state and option stub before each test.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		if ( isset( $GLOBALS['wpdb'] ) ) {
			$GLOBALS['wpdb']->next_get_row  = null;
			$GLOBALS['wpdb']->get_row_queue = array();
		}

		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Bootstrap stub.
		$GLOBALS['_assetdrips_options_stub'] = array();
	}

	/**
	 * Tear down stub state after each test.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		if ( isset( $GLOBALS['wpdb'] ) ) {
			$GLOBALS['wpdb']->next_get_row  = null;
			$GLOBALS['wpdb']->get_row_queue = array();
		}
		parent::tearDown();
	}

	// =========================================================================
	// D-00: get_row_queue SMOKE TEST — must be GREEN after Task 1
	// =========================================================================

	/**
	 * The global wpdb stub's get_row_queue dequeues entries in FIFO order across
	 * two successive get_row() calls.  This validates the multi-read seam the
	 * extended render_column() depends on (D-00).
	 *
	 * This test is GREEN once unit-bootstrap.php is updated with the queue pattern
	 * (Task 1 of this plan).
	 *
	 * @return void
	 */
	public function test_get_row_queue_consumes_entries_in_order(): void {
		$entry_a = array( 'has_webp' => 1, 'has_avif' => 0, 'is_oversized' => 0 );
		$entry_b = array( 'status' => 'complete', 'original_bytes' => 2048, 'optimized_bytes' => 1024 );

		// Seed two entries into the queue.
		$GLOBALS['wpdb']->get_row_queue = array( $entry_a, $entry_b );

		// First get_row() call must return entry A.
		$first = $GLOBALS['wpdb']->get_row( 'SELECT 1' );
		$this->assertSame(
			$entry_a,
			$first,
			'First get_row() call must return the first queued entry (entry A)'
		);

		// Second get_row() call must return entry B.
		$second = $GLOBALS['wpdb']->get_row( 'SELECT 2' );
		$this->assertSame(
			$entry_b,
			$second,
			'Second get_row() call must return the second queued entry (entry B)'
		);

		// Queue must be exhausted after two reads.
		$this->assertEmpty(
			$GLOBALS['wpdb']->get_row_queue,
			'Queue must be empty after consuming both entries'
		);
	}

	/**
	 * After queue is exhausted, get_row() falls back to next_get_row (consume-once).
	 * This validates backward-compatibility: existing tests using next_get_row keep
	 * working even when the queue feature is present.
	 *
	 * This test is GREEN once unit-bootstrap.php is updated (Task 1).
	 *
	 * @return void
	 */
	public function test_get_row_falls_back_to_next_get_row_when_queue_empty(): void {
		$fallback = array( 'has_webp' => 0, 'has_avif' => 0, 'is_oversized' => 0 );

		$GLOBALS['wpdb']->get_row_queue = array(); // Empty queue.
		$GLOBALS['wpdb']->next_get_row  = $fallback;

		$result = $GLOBALS['wpdb']->get_row( 'SELECT 1' );

		$this->assertSame(
			$fallback,
			$result,
			'get_row() must fall back to next_get_row when the queue is empty'
		);

		// next_get_row must be consumed (set to null) after use.
		$this->assertNull(
			$GLOBALS['wpdb']->next_get_row,
			'next_get_row must be set to null after being consumed (consume-once)'
		);
	}

	// =========================================================================
	// DASH-06 RED TESTS — render_column() extended behaviour (fails until Wave 3)
	// =========================================================================

	/**
	 * render_column() emits an "Optimize now" action link when there is no
	 * status='complete' squeeze row for the attachment.
	 *
	 * RED: Fails because render_column() does not yet emit "Optimize now".
	 *
	 * Seeding strategy: first queue entry = get_flags() (has_webp / has_avif / is_oversized),
	 * second queue entry = get() or get_squeeze_summary() (status + bytes).
	 *
	 * @return void
	 */
	public function test_render_column_emits_optimize_now_link_when_no_complete_row(): void {
		// get_flags() read: no next-gen formats generated.
		$GLOBALS['wpdb']->get_row_queue = array(
			// Entry consumed by get_flags().
			array( 'has_webp' => 0, 'has_avif' => 0, 'is_oversized' => 0 ),
			// Entry consumed by get() / get_squeeze_summary() — null → no complete row.
			null,
		);

		ob_start();
		( new NextGenColumn() )->render_column( 'assetdrips_nextgen', 10 );
		$output = ob_get_clean();

		$this->assertStringContainsString(
			'Optimize now',
			$output,
			'render_column() must emit "Optimize now" action link when no complete squeeze row exists (DASH-06)'
		);
	}

	/**
	 * render_column() emits an "Optimized" status badge and the bytes-saved
	 * figure when a status='complete' row exists for the attachment.
	 *
	 * RED: Fails because render_column() does not yet emit the status badge or bytes-saved.
	 *
	 * @return void
	 */
	public function test_render_column_emits_status_badge_and_bytes_saved_when_complete(): void {
		// get_flags(): has_webp=1 (next-gen present).
		// get() / get_squeeze_summary(): complete row with savings.
		$GLOBALS['wpdb']->get_row_queue = array(
			array( 'has_webp' => 1, 'has_avif' => 0, 'is_oversized' => 0 ),
			array(
				'status'          => 'complete',
				'original_bytes'  => 204800,
				'optimized_bytes' => 102400,
			),
		);

		ob_start();
		( new NextGenColumn() )->render_column( 'assetdrips_nextgen', 11 );
		$output = ob_get_clean();

		$this->assertStringContainsString(
			'Optimized',
			$output,
			'render_column() must emit "Optimized" status badge when status=complete (DASH-06)'
		);

		// The column must show bytes-saved in some human-readable form.
		// 204800 - 102400 = 102400 bytes = 100 KB.  Accept any positive numeric output.
		$this->assertMatchesRegularExpression(
			'/\d+/',
			$output,
			'render_column() must emit a numeric bytes-saved value when status=complete (DASH-06)'
		);
	}

	/**
	 * render_column() emits a "Restore original" action link only when the
	 * attachment has an active backup (BackupManager::has_backup() returns true).
	 *
	 * RED: Fails because render_column() does not yet emit "Restore original".
	 *
	 * @return void
	 */
	public function test_render_column_emits_restore_original_link_only_when_backup_exists(): void {
		// Scenario A: backup exists → "Restore original" must appear.
		// Queue: get_flags() → complete row with backup flag.
		$GLOBALS['wpdb']->get_row_queue = array(
			array( 'has_webp' => 1, 'has_avif' => 0, 'is_oversized' => 0 ),
			array(
				'status'          => 'complete',
				'original_bytes'  => 204800,
				'optimized_bytes' => 102400,
				'has_backup'      => 1,
			),
		);

		ob_start();
		( new NextGenColumn() )->render_column( 'assetdrips_nextgen', 12 );
		$output_with_backup = ob_get_clean();

		$this->assertStringContainsString(
			'Restore original',
			$output_with_backup,
			'render_column() must emit "Restore original" link when has_backup=true (DASH-06)'
		);

		// Scenario B: no backup → "Restore original" must NOT appear.
		$GLOBALS['wpdb']->get_row_queue = array(
			array( 'has_webp' => 1, 'has_avif' => 0, 'is_oversized' => 0 ),
			array(
				'status'          => 'complete',
				'original_bytes'  => 204800,
				'optimized_bytes' => 102400,
				'has_backup'      => 0,
			),
		);

		ob_start();
		( new NextGenColumn() )->render_column( 'assetdrips_nextgen', 13 );
		$output_without_backup = ob_get_clean();

		$this->assertStringNotContainsString(
			'Restore original',
			$output_without_backup,
			'render_column() must NOT emit "Restore original" link when has_backup=false (DASH-06)'
		);
	}
}
