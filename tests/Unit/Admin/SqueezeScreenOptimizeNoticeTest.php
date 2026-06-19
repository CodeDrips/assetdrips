<?php
/**
 * Regression tests for the SqueezeScreen single-optimize PRG feedback fix.
 *
 * The dashboard "Biggest Offenders → Optimize now" button used to redirect to the
 * Media Library and report "Image optimized." unconditionally, with the notice only
 * rendered on the Squeeze dashboard — so a no-op (no operations enabled, or all
 * enabled operations skipped at runtime) appeared to "do nothing". These tests lock
 * in the honest-feedback behavior:
 *
 *   1. No operations enabled  → "No optimization operations are enabled" notice,
 *      returned to the originating screen (wp_get_referer, fallback dashboard).
 *   2. summarize_no_change()  → maps engine skip/no-op reasons to an honest message
 *      instead of claiming success.
 *
 * @package AssetDrips
 */

declare( strict_types=1 );

namespace AssetDrips\Tests\Unit\Admin;

use AssetDrips\Admin\SqueezeScreen;
use PHPUnit\Framework\TestCase;

/**
 * Honest single-optimize feedback (Optimize-now PRG fix).
 */
final class SqueezeScreenOptimizeNoticeTest extends TestCase {

	/**
	 * Reset stubs and superglobals before each test.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$GLOBALS['_assetdrips_current_user_can_stub'] = true;
		$GLOBALS['_assetdrips_options_stub']          = array();
		unset( $GLOBALS['_assetdrips_wp_get_referer_stub'] );
		$_GET = array();
	}

	/**
	 * Clean up after each test.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		$GLOBALS['_assetdrips_options_stub'] = array();
		unset( $GLOBALS['_assetdrips_wp_get_referer_stub'] );
		$_GET = array();
	}

	/**
	 * Invoke the private static summarize_no_change() helper.
	 *
	 * @param array<int,array<string,mixed>> $results Per-op result arrays.
	 * @return string
	 */
	private function summarize( array $results ): string {
		$ref = new \ReflectionMethod( SqueezeScreen::class, 'summarize_no_change' );
		$ref->setAccessible( true );
		return (string) $ref->invoke( null, $results );
	}

	// -----------------------------------------------------------------------
	// No-op guard: nothing enabled must NOT claim success.
	// -----------------------------------------------------------------------

	/**
	 * With no operations enabled (default settings), handle_single_optimize() must
	 * redirect with the "no operations enabled" notice and never claim success.
	 *
	 * @return void
	 */
	public function test_optimize_with_no_ops_enabled_reports_nothing_enabled(): void {
		$_GET = array( 'id' => 123 ); // options stub empty → all enable_* default false.

		$screen = new SqueezeScreen();

		try {
			$screen->handle_single_optimize();
			$this->fail( 'handle_single_optimize() must redirect, not return.' );
		} catch ( \AssetDripsTestRedirectException $e ) {
			$decoded = urldecode( urldecode( $e->location ) );
			$this->assertStringContainsString(
				'No optimization operations are enabled',
				$decoded,
				'A no-op optimize must report that no operations are enabled.'
			);
			$this->assertStringNotContainsString(
				'Image optimized',
				$decoded,
				'A no-op optimize must NOT claim "Image optimized."'
			);
		}
	}

	/**
	 * The no-op redirect must return to wp_get_referer() when present (so the
	 * dashboard "biggest offenders" click lands back on the dashboard).
	 *
	 * @return void
	 */
	public function test_optimize_returns_to_referer_when_present(): void {
		$referer = 'http://example.com/wp-admin/admin.php?page=assetdrips-squeeze';
		$GLOBALS['_assetdrips_wp_get_referer_stub'] = $referer;
		$_GET = array( 'id' => 123 );

		$screen = new SqueezeScreen();

		try {
			$screen->handle_single_optimize();
			$this->fail( 'handle_single_optimize() must redirect, not return.' );
		} catch ( \AssetDripsTestRedirectException $e ) {
			$this->assertStringStartsWith(
				$referer,
				$e->location,
				'Optimize should return the user to the screen they came from (wp_get_referer).'
			);
		}
	}

	/**
	 * With no referer, the no-op redirect must fall back to the Squeeze dashboard.
	 *
	 * @return void
	 */
	public function test_optimize_falls_back_to_dashboard_without_referer(): void {
		$_GET = array( 'id' => 123 ); // referer stub unset → wp_get_referer() returns false.

		$screen = new SqueezeScreen();

		try {
			$screen->handle_single_optimize();
			$this->fail( 'handle_single_optimize() must redirect, not return.' );
		} catch ( \AssetDripsTestRedirectException $e ) {
			$this->assertStringContainsString(
				'page=assetdrips-squeeze',
				$e->location,
				'Without a referer the optimize redirect must fall back to the Squeeze dashboard.'
			);
		}
	}

	// -----------------------------------------------------------------------
	// summarize_no_change(): honest reason mapping.
	// -----------------------------------------------------------------------

	/**
	 * already_optimized maps to a clear "already optimized" message.
	 *
	 * @return void
	 */
	public function test_summarize_already_optimized(): void {
		$msg = $this->summarize( array( array( 'ok' => false, 'skipped' => true, 'reason' => 'already_optimized' ) ) );
		$this->assertStringContainsString( 'already optimized', $msg );
		$this->assertStringNotContainsString( 'Image optimized', $msg );
	}

	/**
	 * Unsupported WebP/AVIF skips map to server-capability messages.
	 *
	 * @return void
	 */
	public function test_summarize_unsupported_formats(): void {
		$msg = $this->summarize(
			array(
				array( 'ok' => false, 'skipped' => true, 'reason' => 'webp_unsupported' ),
				array( 'ok' => false, 'skipped' => true, 'reason' => 'avif_unsupported' ),
			)
		);
		$this->assertStringContainsString( 'WebP encoding not available on this server', $msg );
		$this->assertStringContainsString( 'AVIF encoding not available on this server', $msg );
	}

	/**
	 * A not-oversized resize (ok=true, was_oversized=false) is reported as a no-op,
	 * never as a successful optimization.
	 *
	 * @return void
	 */
	public function test_summarize_resize_within_limit(): void {
		$msg = $this->summarize( array( array( 'ok' => true, 'was_oversized' => false ) ) );
		$this->assertStringContainsString( 'already within the size limit', $msg );
	}

	/**
	 * Repeated reasons are de-duplicated in the summary.
	 *
	 * @return void
	 */
	public function test_summarize_deduplicates_reasons(): void {
		$msg = $this->summarize(
			array(
				array( 'ok' => false, 'reason' => 'already_optimized' ),
				array( 'ok' => false, 'reason' => 'already_optimized' ),
			)
		);
		$this->assertSame( 1, substr_count( $msg, 'already optimized' ), 'Duplicate reasons must collapse to one.' );
	}

	/**
	 * Empty results (no ops attempted) yield a generic, honest fallback.
	 *
	 * @return void
	 */
	public function test_summarize_empty_results_generic_message(): void {
		$msg = $this->summarize( array() );
		$this->assertStringContainsString( 'Nothing to optimize', $msg );
	}
}
