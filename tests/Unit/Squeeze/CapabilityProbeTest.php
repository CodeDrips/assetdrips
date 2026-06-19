<?php
/**
 * Unit tests for CapabilityProbe (FND-05).
 *
 * These tests are RED until Plan 02 implements CapabilityProbe. They define
 * the required behaviour; Plan 02 turns them GREEN.
 *
 * @package AssetDrips
 */

declare( strict_types=1 );

namespace AssetDrips\Tests\Unit\Squeeze;

use AssetDrips\Squeeze\CapabilityProbe;
use PHPUnit\Framework\TestCase;

/**
 * Tests CapabilityProbe cache behaviour and editor-factory injection.
 *
 * The live encode probe (WebP/AVIF round-trip) is exercised at integration
 * level. Unit tests cover the transient cache layer and the injectable
 * editor-factory parameter.
 */
final class CapabilityProbeTest extends TestCase {

	/**
	 * Reset the in-memory transient stub before each test.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$GLOBALS['_assetdrips_transient_stub'] = array();
	}

	/**
	 * get() returns an array with the expected keys (FND-05).
	 *
	 * Injects a minimal mock factory so no real encode is attempted.
	 *
	 * @return void
	 */
	public function test_get_returns_array_with_expected_keys(): void {
		$factory = static function () {
			return new \WP_Error( 'no_editor', 'No editor available in unit context.' );
		};

		$caps = CapabilityProbe::run( $factory );

		$this->assertIsArray( $caps );
		$this->assertArrayHasKey( 'webp', $caps );
		$this->assertArrayHasKey( 'avif', $caps );
		$this->assertArrayHasKey( 'imagick', $caps );
		$this->assertArrayHasKey( 'gd', $caps );
	}

	/**
	 * get() returns the cached value on a second call without re-probing (FND-05).
	 *
	 * Manually pre-populates the transient stub with a known value, then verifies
	 * that get() returns the cached data without invoking the probe.
	 *
	 * @return void
	 */
	public function test_caches_result(): void {
		$expected = array( 'webp' => true, 'avif' => false, 'imagick' => false, 'gd' => true );
		// Pre-seed the transient directly — simulates a cache hit.
		set_transient( 'assetdrips_squeeze_caps', $expected, 0 );

		// get() should return the cached value; probe factory must NOT be called.
		$probe_called = false;
		$factory      = static function () use ( &$probe_called ) {
			$probe_called = true;
			return new \WP_Error( 'no_editor', 'Should not be called when cache is warm.' );
		};

		$caps = CapabilityProbe::get( $factory );

		$this->assertFalse( $probe_called, 'Probe factory must not be called on a cache hit.' );
		$this->assertSame( $expected, $caps );
	}

	/**
	 * invalidate() clears the transient so the next get() re-probes (FND-05).
	 *
	 * @return void
	 */
	public function test_invalidate_clears_cache(): void {
		// Seed the transient.
		set_transient( 'assetdrips_squeeze_caps', array( 'webp' => true, 'avif' => false, 'imagick' => false, 'gd' => true ), 0 );

		CapabilityProbe::invalidate();

		// After invalidation the transient is gone.
		$this->assertFalse( get_transient( 'assetdrips_squeeze_caps' ) );
	}

	/**
	 * run() accepts an optional editor factory callable (FND-05).
	 *
	 * Verifies that the factory is invoked during the probe and that the return
	 * value is a correctly-shaped capability map regardless of factory outcome.
	 *
	 * @return void
	 */
	public function test_run_accepts_editor_factory(): void {
		$invoked = 0;
		$factory = static function () use ( &$invoked ) {
			++$invoked;
			return new \WP_Error( 'no_editor', 'Mock factory.' );
		};

		$caps = CapabilityProbe::run( $factory );

		$this->assertGreaterThan( 0, $invoked, 'Editor factory must be invoked during run().' );
		$this->assertIsArray( $caps );
		$this->assertArrayHasKey( 'webp', $caps );
		$this->assertArrayHasKey( 'avif', $caps );
	}

	/**
	 * run() produces a valid source PNG via the hardcoded fallback when GD is absent (CR-01).
	 *
	 * Simulates a GD-less host by patching the factory to record whether it was
	 * invoked (which it can only be when the source PNG was created successfully).
	 * The probe must call the factory at least twice (WebP + AVIF probes) because
	 * the source PNG was created via the base64 fallback, not via GD.
	 *
	 * This test runs under a normal PHP environment (GD may or may not be loaded).
	 * It does NOT mock extension_loaded — instead it verifies that the factory IS
	 * still invoked (probe ran past source creation) even when the GD branch fails
	 * to write the file (which can happen if imagecreatetruecolor returns false, or
	 * GD is absent). We achieve this by running the probe and asserting the factory
	 * was called — if the fallback PNG branch were absent and GD was also absent,
	 * run() would have returned early with the factory never called.
	 *
	 * @return void
	 */
	public function test_run_fallback_png_allows_probe_to_proceed(): void {
		$invoked = 0;
		$factory = static function () use ( &$invoked ) {
			++$invoked;
			// Return WP_Error so no real encode is attempted; we just need the factory called.
			return new \WP_Error( 'no_editor', 'Mock factory for GD-less fallback test.' );
		};

		$caps = CapabilityProbe::run( $factory );

		// Factory must have been invoked — proves probe proceeded past source creation.
		$this->assertGreaterThanOrEqual( 2, $invoked, 'Factory must be invoked for both WebP and AVIF probes; GD-less fallback must have created the source PNG.' );
		$this->assertIsArray( $caps );
		$this->assertArrayHasKey( 'webp', $caps );
		$this->assertArrayHasKey( 'avif', $caps );
		$this->assertFalse( $caps['webp'], 'webp must be false when factory returns WP_Error.' );
		$this->assertFalse( $caps['avif'], 'avif must be false when factory returns WP_Error.' );
	}

	/**
	 * get() falls through to re-probe when the cached array is missing required keys (WR-07).
	 *
	 * A malformed/truncated transient value (e.g. from a partial object-cache write)
	 * must NOT be returned — get() must re-probe instead. Verifies the shape guard
	 * added to the cache-hit path.
	 *
	 * @return void
	 */
	public function test_get_rejects_malformed_cache_and_reprobes(): void {
		// Seed a malformed cache entry — missing the required keys.
		set_transient( 'assetdrips_squeeze_caps', array( 'webp' => true ), 0 );

		$probe_called = false;
		$factory      = static function () use ( &$probe_called ) {
			$probe_called = true;
			return new \WP_Error( 'no_editor', 'Re-probe factory.' );
		};

		$caps = CapabilityProbe::get( $factory );

		$this->assertTrue( $probe_called, 'Probe must run when cached array is missing required keys.' );
		$this->assertArrayHasKey( 'webp', $caps );
		$this->assertArrayHasKey( 'avif', $caps );
		$this->assertArrayHasKey( 'imagick', $caps );
		$this->assertArrayHasKey( 'gd', $caps );
	}
}
