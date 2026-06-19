<?php
/**
 * Unit tests for SqueezeSettings value object (FND-01, FND-02, FND-03, FND-04).
 *
 * These tests are RED until Plan 02 implements SqueezeSettings. They define
 * the required behaviour; Plan 02 turns them GREEN.
 *
 * @package AssetDrips
 */

declare( strict_types=1 );

namespace AssetDrips\Tests\Unit\Squeeze;

use AssetDrips\Squeeze\SqueezeSettings;
use PHPUnit\Framework\TestCase;

/**
 * Tests the SqueezeSettings value object: defaults, preset resolution,
 * sanitizer, and round-trip persistence via the in-memory option stubs.
 */
final class SqueezeSettingsTest extends TestCase {

	/**
	 * Reset the in-memory options stub before each test.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$GLOBALS['_assetdrips_options_stub'] = array();
	}

	/**
	 * All auto_* operation toggles default to false (FND-04).
	 *
	 * A fresh SqueezeSettings::load() with no saved option must return all
	 * auto-on-upload toggles as false — never auto-opt-in on first install.
	 *
	 * @return void
	 */
	public function test_defaults_are_safe(): void {
		$settings = SqueezeSettings::load();

		$this->assertFalse( $settings->auto_recompress, 'auto_recompress should default to false.' );
		$this->assertFalse( $settings->auto_webp, 'auto_webp should default to false.' );
		$this->assertFalse( $settings->auto_avif, 'auto_avif should default to false.' );
		$this->assertFalse( $settings->auto_resize, 'auto_resize should default to false.' );
	}

	/**
	 * Preset key resolves to the expected numeric JPEG quality (FND-01).
	 *
	 * conservative => 88, balanced => 82, aggressive => 75.
	 *
	 * @return void
	 */
	public function test_preset_resolves_to_numeric(): void {
		$this->assertSame( 88, SqueezeSettings::PRESETS['conservative'] );
		$this->assertSame( 82, SqueezeSettings::PRESETS['balanced'] );
		$this->assertSame( 75, SqueezeSettings::PRESETS['aggressive'] );
	}

	/**
	 * Sanitizing a custom numeric quality sets preset to 'custom' (FND-01).
	 *
	 * When the user provides a raw numeric quality that doesn't match a preset
	 * and the preset input is 'custom', the sanitized settings carry preset=custom
	 * and the provided numeric quality.
	 *
	 * @return void
	 */
	public function test_custom_override_sets_preset_custom(): void {
		$raw      = array(
			'preset'       => 'custom',
			'jpeg_quality' => '79',
		);
		$settings = SqueezeSettings::sanitize( $raw );

		$this->assertSame( 'custom', $settings->preset );
		$this->assertSame( 79, $settings->jpeg_quality );
	}

	/**
	 * png_lossless is always true after sanitize regardless of POST input (FND-02).
	 *
	 * The sanitizer enforces this hard-gate: lossy compression must never be
	 * applied to PNGs by accident.
	 *
	 * @return void
	 */
	public function test_png_lossless_always_true(): void {
		// Attempt to disable lossless via POST — should be ignored.
		$settings = SqueezeSettings::sanitize( array( 'png_lossless' => '0' ) );

		$this->assertTrue( $settings->png_lossless );
	}

	/**
	 * max_dimension defaults to 2560 (FND-03).
	 *
	 * @return void
	 */
	public function test_max_dimension_default_is_2560(): void {
		$settings = SqueezeSettings::load();

		$this->assertSame( 2560, $settings->max_dimension );
	}

	/**
	 * Sanitizer clamps max_dimension to [100, 10000] (FND-03).
	 *
	 * @return void
	 */
	public function test_sanitizer_clamps_max_dimension(): void {
		$too_low  = SqueezeSettings::sanitize( array( 'max_dimension' => '5' ) );
		$too_high = SqueezeSettings::sanitize( array( 'max_dimension' => '99999' ) );
		$valid    = SqueezeSettings::sanitize( array( 'max_dimension' => '1920' ) );

		$this->assertSame( 100, $too_low->max_dimension );
		$this->assertSame( 10000, $too_high->max_dimension );
		$this->assertSame( 1920, $valid->max_dimension );
	}

	/**
	 * load() returns safe defaults when no option is saved.
	 *
	 * @return void
	 */
	public function test_load_returns_defaults_when_no_option(): void {
		$settings = SqueezeSettings::load();

		$this->assertSame( SqueezeSettings::DEFAULT_PRESET, $settings->preset );
		$this->assertSame( SqueezeSettings::DEFAULT_JPEG_QUALITY, $settings->jpeg_quality );
		$this->assertSame( SqueezeSettings::DEFAULT_MAX_DIMENSION, $settings->max_dimension );
		$this->assertTrue( $settings->png_lossless );
	}

	/**
	 * save() persists values that load() retrieves exactly (round-trip) (FND-04).
	 *
	 * @return void
	 */
	public function test_save_and_load_round_trip(): void {
		$original = SqueezeSettings::sanitize( array(
			'preset'            => 'aggressive',
			'max_dimension'     => '1280',
			'enable_recompress' => '1',
			'enable_webp'       => '1',
		) );

		SqueezeSettings::save( $original );

		$loaded = SqueezeSettings::load();

		$this->assertSame( 'aggressive', $loaded->preset );
		$this->assertSame( 75, $loaded->jpeg_quality );
		$this->assertSame( 1280, $loaded->max_dimension );
		$this->assertTrue( $loaded->enable_recompress );
		$this->assertTrue( $loaded->enable_webp );
	}
}
