<?php
/**
 * RED unit tests for SqueezeHooks::set_bypass() static bypass guard (D-03 / Pitfall 1).
 *
 * These tests are RED until Plan 13-03 adds the static bypass flag and set_bypass()
 * method to SqueezeHooks.  They define the required behaviour; Plan 13-03 turns
 * them GREEN.
 *
 * The bypass guard prevents wp_update_image_subsizes() (fired during
 * repair_missing_sizes()) from triggering SqueezeHooks::on_meta() to re-schedule
 * an unnecessary optimization cron event.
 *
 * Strategy: these tests directly call SqueezeHooks::on_meta() and verify whether
 * wp_schedule_single_event is called via a stub registry.  We add per-test stubs for
 * wp_next_scheduled and wp_schedule_single_event backed by $GLOBALS registries.
 *
 * @package AssetDrips
 */

declare( strict_types=1 );

namespace AssetDrips\Tests\Unit\Squeeze;

use AssetDrips\Squeeze\SqueezeHooks;
use PHPUnit\Framework\TestCase;

/**
 * RED tests for SqueezeHooks bypass guard (set_bypass / on_meta suppression).
 */
final class SqueezeHooksBypassTest extends TestCase {

	/**
	 * Set up global stubs for WP scheduling functions and enable auto_* toggle.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$GLOBALS['_assetdrips_options_stub']        = array();
		$GLOBALS['_assetdrips_post_mime_stub']      = array();
		$GLOBALS['_assetdrips_scheduled_events']    = array(); // records wp_schedule_single_event calls
		$GLOBALS['_assetdrips_next_scheduled_stub'] = false;   // wp_next_scheduled return value

		// Enable auto_recompress so on_meta() would normally schedule.
		$GLOBALS['_assetdrips_options_stub']['assetdrips_squeeze_settings'] = array(
			'auto_recompress' => true,
			'auto_webp'       => false,
			'auto_avif'       => false,
			'auto_resize'     => false,
		);

		// Set image MIME for the test attachment.
		$GLOBALS['_assetdrips_post_mime_stub'][100] = 'image/jpeg';

		// Ensure bypass is reset before each test.
		if ( class_exists( SqueezeHooks::class ) ) {
			SqueezeHooks::set_bypass( false );
		}
	}

	/**
	 * Reset bypass and scheduling registry after each test to avoid state leaks.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		if ( class_exists( SqueezeHooks::class ) ) {
			SqueezeHooks::set_bypass( false );
		}
		$GLOBALS['_assetdrips_scheduled_events']    = array();
		$GLOBALS['_assetdrips_next_scheduled_stub'] = false;
	}

	// -------------------------------------------------------------------------
	// D-03 / Pitfall 1: bypass set_bypass(true) suppresses on_meta scheduling
	// -------------------------------------------------------------------------

	/**
	 * With auto_* toggle enabled and bypass set true, on_meta() must NOT call
	 * wp_schedule_single_event (the schedule call is suppressed) (D-03 / Pitfall 1).
	 *
	 * @return void
	 */
	public function test_bypass_suppresses_scheduling(): void {
		// set_bypass must exist on SqueezeHooks.
		$this->assertTrue(
			method_exists( SqueezeHooks::class, 'set_bypass' ),
			'SqueezeHooks must have a static set_bypass(bool) method (D-03)'
		);

		// Activate bypass.
		SqueezeHooks::set_bypass( true );

		$hooks = new SqueezeHooks();
		$meta  = array( 'width' => 1920, 'height' => 1080 );

		// on_meta() must return $meta unchanged even with auto enabled.
		$returned = $hooks->on_meta( $meta, 100, 'update' );

		$this->assertSame(
			$meta,
			$returned,
			'on_meta() must return $meta unchanged when bypass is active (TRG-03)'
		);

		$this->assertEmpty(
			$GLOBALS['_assetdrips_scheduled_events'],
			'on_meta() must NOT schedule when bypass is true (D-03, Pitfall 1)'
		);
	}

	/**
	 * With bypass false, on_meta() DOES call wp_schedule_single_event when
	 * auto_* is enabled and no event is already scheduled (baseline: bypass=false = normal).
	 *
	 * @return void
	 */
	public function test_bypass_false_allows_scheduling(): void {
		$this->assertTrue(
			method_exists( SqueezeHooks::class, 'set_bypass' ),
			'SqueezeHooks must have a static set_bypass(bool) method'
		);

		// Bypass is false by default (reset in setUp).
		// wp_next_scheduled returns false (no event queued yet).
		$GLOBALS['_assetdrips_next_scheduled_stub'] = false;

		$hooks = new SqueezeHooks();
		$meta  = array( 'width' => 1920, 'height' => 1080 );

		$hooks->on_meta( $meta, 100, 'create' );

		$this->assertNotEmpty(
			$GLOBALS['_assetdrips_scheduled_events'],
			'on_meta() must schedule when bypass is false and auto_* is enabled (D-03)'
		);
	}

	// -------------------------------------------------------------------------
	// D-03: set_bypass(false) resets state — no state leak between calls
	// -------------------------------------------------------------------------

	/**
	 * set_bypass(true) then set_bypass(false) restores normal scheduling behaviour.
	 * This verifies the static flag has no state leak between calls (D-03 / Pitfall 1).
	 *
	 * @return void
	 */
	public function test_bypass_resets(): void {
		$this->assertTrue(
			method_exists( SqueezeHooks::class, 'set_bypass' ),
			'SqueezeHooks must have a static set_bypass(bool) method'
		);

		// Set bypass true then immediately reset.
		SqueezeHooks::set_bypass( true );
		SqueezeHooks::set_bypass( false );

		// After reset, on_meta() must schedule normally.
		$GLOBALS['_assetdrips_next_scheduled_stub'] = false;

		$hooks = new SqueezeHooks();
		$meta  = array( 'width' => 800, 'height' => 600 );

		$hooks->on_meta( $meta, 100, 'create' );

		$this->assertNotEmpty(
			$GLOBALS['_assetdrips_scheduled_events'],
			'After set_bypass(false), on_meta() must schedule normally — no state leak (D-03)'
		);
	}
}
