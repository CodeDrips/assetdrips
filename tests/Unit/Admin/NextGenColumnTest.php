<?php
/**
 * RED tests for Admin\NextGenColumn (D-09 / Phase 10).
 *
 * These tests are RED until Plan 10-03 creates NextGenColumn. They define the
 * required behaviour; Plan 10-03 turns them GREEN.
 *
 * EXIT_EXPECTED_RED: Tests WILL fail because class AssetDrips\Admin\NextGenColumn
 * does not yet exist. Failures must be class-does-not-exist errors or assertion
 * failures on return values — NOT parse/bootstrap fatals.
 *
 * Uses:
 * - Source inspection for column/hook registration contracts (add_column)
 * - ob_start()/ob_get_clean() output capture for render_column() output states
 * - $GLOBALS['wpdb'] seeding for OptimizationIndex::from_wordpress()->get_flags()
 *
 * @package AssetDrips
 */

declare( strict_types=1 );

namespace AssetDrips\Tests\Unit\Admin;

use AssetDrips\Admin\NextGenColumn;
use PHPUnit\Framework\TestCase;

/**
 * RED tests: NextGenColumn callback contracts (D-09, UI-SPEC).
 */
final class NextGenColumnTest extends TestCase {

	/**
	 * Reset wpdb stub state before each test.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		// Ensure the global wpdb stub's next_get_row and get_row_queue are cleared between tests.
		if ( isset( $GLOBALS['wpdb'] ) ) {
			if ( property_exists( $GLOBALS['wpdb'], 'next_get_row' ) ) {
				$GLOBALS['wpdb']->next_get_row = null;
			}
			if ( property_exists( $GLOBALS['wpdb'], 'get_row_queue' ) ) {
				$GLOBALS['wpdb']->get_row_queue = array();
			}
		}
	}

	/**
	 * Reset wpdb stub state after each test.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		if ( isset( $GLOBALS['wpdb'] ) ) {
			if ( property_exists( $GLOBALS['wpdb'], 'next_get_row' ) ) {
				$GLOBALS['wpdb']->next_get_row = null;
			}
			if ( property_exists( $GLOBALS['wpdb'], 'get_row_queue' ) ) {
				$GLOBALS['wpdb']->get_row_queue = array();
			}
		}
	}

	// -------------------------------------------------------------------------
	// D-09 / PITFALL 5: render_column early-return for non-matching column name
	// -------------------------------------------------------------------------

	/**
	 * render_column() returns immediately (emits no output) for any column name
	 * other than 'assetdrips_nextgen' (PITFALL 5 early-return guard).
	 *
	 * Without this guard, the action fires for every column in the Media Library
	 * list table and would emit HTML for 'title', 'author', 'parent', etc.
	 *
	 * @return void
	 */
	public function test_render_column_returns_immediately_for_non_nextgen_column(): void {
		ob_start();
		( new NextGenColumn() )->render_column( 'title', 1 );
		$output = ob_get_clean();

		$this->assertSame( '', $output, 'render_column() must emit no output for column names other than assetdrips_nextgen' );

		ob_start();
		( new NextGenColumn() )->render_column( 'author', 99 );
		$output = ob_get_clean();

		$this->assertSame( '', $output, 'render_column() must emit no output for "author" column' );
	}

	// -------------------------------------------------------------------------
	// D-09 / UI-SPEC: WebP-only badge state (has_webp=true, has_avif=false)
	// -------------------------------------------------------------------------

	/**
	 * render_column() for 'assetdrips_nextgen' with has_webp=true outputs a
	 * string containing "WebP" and NOT "AVIF" (UI-SPEC State B).
	 *
	 * @return void
	 */
	public function test_render_column_outputs_webp_badge_when_has_webp_true(): void {
		// Seed wpdb stub to return has_webp=1, has_avif=0.
		$GLOBALS['wpdb']->next_get_row = array(
			'has_webp'     => 1,
			'has_avif'     => 0,
			'is_oversized' => 0,
		);

		ob_start();
		( new NextGenColumn() )->render_column( 'assetdrips_nextgen', 1 );
		$output = ob_get_clean();

		$this->assertStringContainsString( 'WebP', $output, 'render_column() must output "WebP" badge when has_webp=true' );
		$this->assertStringNotContainsString( 'AVIF', $output, 'render_column() must NOT output "AVIF" badge when has_avif=false' );
	}

	// -------------------------------------------------------------------------
	// D-09 / UI-SPEC: both-badges state (has_webp=true, has_avif=true)
	// -------------------------------------------------------------------------

	/**
	 * render_column() with both flags true outputs both "WebP" and "AVIF"
	 * (UI-SPEC State A: both formats present).
	 *
	 * @return void
	 */
	public function test_render_column_outputs_both_badges_when_both_flags_true(): void {
		// Seed wpdb stub to return has_webp=1, has_avif=1.
		$GLOBALS['wpdb']->next_get_row = array(
			'has_webp'     => 1,
			'has_avif'     => 1,
			'is_oversized' => 0,
		);

		ob_start();
		( new NextGenColumn() )->render_column( 'assetdrips_nextgen', 2 );
		$output = ob_get_clean();

		$this->assertStringContainsString( 'WebP', $output, 'render_column() must output "WebP" badge when both flags true' );
		$this->assertStringContainsString( 'AVIF', $output, 'render_column() must output "AVIF" badge when both flags true' );
	}

	// -------------------------------------------------------------------------
	// D-09 / UI-SPEC: absent state (both flags false → em dash + aria-label)
	// -------------------------------------------------------------------------

	/**
	 * render_column() with both flags false and no squeeze row outputs the
	 * em-dash absent marker (&#8212; / —) and the aria-label "Not yet optimized"
	 * (UI-SPEC Surface 4 absent state — DASH-06).
	 *
	 * Updated in Plan 06 (DASH-06): column renamed "Squeeze"; aria-label updated
	 * from "No next-gen formats generated" to "Not yet optimized" to match the
	 * expanded column scope.
	 *
	 * @return void
	 */
	public function test_render_column_outputs_em_dash_and_aria_label_when_both_flags_false(): void {
		// Seed queue: first entry = get_flags() (no formats), second = no squeeze row.
		$GLOBALS['wpdb']->get_row_queue = array(
			array( 'has_webp' => 0, 'has_avif' => 0, 'is_oversized' => 0 ),
			null, // no squeeze row — absent state.
		);

		ob_start();
		( new NextGenColumn() )->render_column( 'assetdrips_nextgen', 3 );
		$output = ob_get_clean();

		$this->assertStringNotContainsString( 'WebP', $output, 'render_column() must NOT output WebP badge in absent state' );
		$this->assertStringNotContainsString( 'AVIF', $output, 'render_column() must NOT output AVIF badge in absent state' );

		// Absent state must contain the em dash (either as HTML entity or UTF-8).
		$has_em_dash = str_contains( $output, '&#8212;' ) || str_contains( $output, '—' );
		$this->assertTrue( $has_em_dash, 'render_column() must output em dash (&#8212;) in absent state' );

		// Absent state must contain the updated aria-label for screen readers (DASH-06).
		$this->assertStringContainsString(
			'Not yet optimized',
			$output,
			'render_column() must include aria-label "Not yet optimized" in absent state (DASH-06)'
		);
	}

	// -------------------------------------------------------------------------
	// D-09 / UI-SPEC: absent state when no DB row (get_flags() returns defaults)
	// -------------------------------------------------------------------------

	/**
	 * render_column() renders the absent state gracefully when the DB row is
	 * absent (OptimizationIndex::get_flags() returns all-false defaults).
	 *
	 * @return void
	 */
	public function test_render_column_renders_absent_state_when_no_db_row(): void {
		// null means no row in DB → get_flags() returns all-false defaults.
		$GLOBALS['wpdb']->next_get_row = null;

		ob_start();
		( new NextGenColumn() )->render_column( 'assetdrips_nextgen', 4 );
		$output = ob_get_clean();

		$has_em_dash = str_contains( $output, '&#8212;' ) || str_contains( $output, '—' );
		$this->assertTrue( $has_em_dash, 'render_column() must render absent state (em dash) when no DB row exists' );
	}

	// -------------------------------------------------------------------------
	// D-09: add_column() adds 'assetdrips_nextgen' key with "Next-Gen" label
	// -------------------------------------------------------------------------

	/**
	 * add_column() adds the key 'assetdrips_nextgen' to the columns array
	 * with the label "Squeeze" (UI-SPEC column header — relabeled in DASH-06 Plan 06).
	 *
	 * Updated in Plan 06 (DASH-06): column header relabeled from "Next-Gen" to
	 * "Squeeze" to reflect the expanded column scope (status + bytes-saved + actions).
	 *
	 * @return void
	 */
	public function test_add_column_adds_assetdrips_nextgen_key_to_columns_array(): void {
		$initial_columns = array(
			'cb'     => '<input type="checkbox">',
			'title'  => 'Title',
			'author' => 'Author',
		);

		$result = ( new NextGenColumn() )->add_column( $initial_columns );

		$this->assertArrayHasKey(
			'assetdrips_nextgen',
			$result,
			'add_column() must add key "assetdrips_nextgen" to the columns array'
		);

		$this->assertStringContainsString(
			'Squeeze',
			$result['assetdrips_nextgen'],
			'add_column() column label must contain "Squeeze" (relabeled in DASH-06)'
		);

		// Existing columns must be preserved.
		$this->assertArrayHasKey( 'cb', $result, 'add_column() must preserve existing columns' );
		$this->assertArrayHasKey( 'title', $result, 'add_column() must preserve existing columns' );
		$this->assertArrayHasKey( 'author', $result, 'add_column() must preserve existing columns' );
	}
}
