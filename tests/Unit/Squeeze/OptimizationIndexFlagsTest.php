<?php
/**
 * Unit tests for OptimizationIndex::get_flags() (D-08 / Phase 10).
 *
 * These tests are GREEN: get_flags() is implemented in Plan 10-01 and this
 * test validates the two key contracts:
 *   (a) all-false defaults when no DB row exists (null from get_row)
 *   (b) correct boolean casting when get_row returns a 1/0/1 row
 *
 * @package AssetDrips
 */

declare( strict_types=1 );

namespace AssetDrips\Tests\Unit\Squeeze;

use AssetDrips\Squeeze\OptimizationIndex;
use PHPUnit\Framework\TestCase;

/**
 * Tests OptimizationIndex::get_flags() behaviour (D-08).
 */
final class OptimizationIndexFlagsTest extends TestCase {

	/**
	 * Build a $wpdb stub whose get_row() returns $return_value.
	 *
	 * @param mixed $return_value Value to return from get_row().
	 * @return object Anonymous wpdb stub.
	 */
	private function make_wpdb_stub( mixed $return_value ): object {
		return new class( $return_value ) {
			/** @var string */
			public string $prefix = 'wp_';
			/** @var mixed */
			private mixed $row_return;

			/** @param mixed $row_return Value get_row() will return. */
			public function __construct( mixed $row_return ) {
				$this->row_return = $row_return;
			}

			/**
			 * @param string $sql  SQL template.
			 * @param mixed  ...$args Bound values.
			 * @return string
			 */
			public function prepare( string $sql, mixed ...$args ): string { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- Stub.
				return $sql;
			}

			/**
			 * @param string $sql    Prepared SQL.
			 * @param string $output Output format constant.
			 * @return mixed
			 */
			public function get_row( string $sql, string $output = '' ): mixed { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- Stub.
				return $this->row_return;
			}
		};
	}

	// -------------------------------------------------------------------------
	// D-08 (a): all-false defaults when no DB row exists
	// -------------------------------------------------------------------------

	/**
	 * get_flags() returns all-false defaults when get_row() returns null
	 * (no assetdrips_media row for the given attachment_id).
	 *
	 * @return void
	 */
	public function test_get_flags_returns_all_false_defaults_when_row_is_null(): void {
		$wpdb  = $this->make_wpdb_stub( null );
		$index = new OptimizationIndex( $wpdb );

		$flags = $index->get_flags( 99 );

		$this->assertIsArray( $flags, 'get_flags() must return an array' );
		$this->assertFalse( $flags['has_webp'], 'has_webp must default to false when no row' );
		$this->assertFalse( $flags['has_avif'], 'has_avif must default to false when no row' );
		$this->assertFalse( $flags['is_oversized'], 'is_oversized must default to false when no row' );
	}

	// -------------------------------------------------------------------------
	// D-08 (b): correct boolean casting when get_row returns 1/0/1 row
	// -------------------------------------------------------------------------

	/**
	 * get_flags() correctly casts integer DB values to booleans:
	 * has_webp=1 → true, has_avif=0 → false, is_oversized=1 → true.
	 *
	 * @return void
	 */
	public function test_get_flags_casts_integer_values_to_booleans(): void {
		$row   = array(
			'has_webp'     => 1,
			'has_avif'     => 0,
			'is_oversized' => 1,
		);
		$wpdb  = $this->make_wpdb_stub( $row );
		$index = new OptimizationIndex( $wpdb );

		$flags = $index->get_flags( 42 );

		$this->assertIsArray( $flags, 'get_flags() must return an array' );
		$this->assertTrue( $flags['has_webp'], 'has_webp=1 must cast to true' );
		$this->assertFalse( $flags['has_avif'], 'has_avif=0 must cast to false' );
		$this->assertTrue( $flags['is_oversized'], 'is_oversized=1 must cast to true' );
		$this->assertIsBool( $flags['has_webp'], 'has_webp must be a bool, not an int' );
		$this->assertIsBool( $flags['has_avif'], 'has_avif must be a bool, not an int' );
		$this->assertIsBool( $flags['is_oversized'], 'is_oversized must be a bool, not an int' );
	}

	// -------------------------------------------------------------------------
	// D-08 (c): all-false defaults when get_row returns a non-array value
	// -------------------------------------------------------------------------

	/**
	 * get_flags() returns all-false defaults when get_row() returns a non-array
	 * (e.g. a stdClass object), matching the additive-no-harm principle.
	 *
	 * @return void
	 */
	public function test_get_flags_returns_all_false_defaults_when_row_is_not_array(): void {
		$wpdb  = $this->make_wpdb_stub( new \stdClass() );
		$index = new OptimizationIndex( $wpdb );

		$flags = $index->get_flags( 7 );

		$this->assertFalse( $flags['has_webp'], 'has_webp must default to false for non-array row' );
		$this->assertFalse( $flags['has_avif'], 'has_avif must default to false for non-array row' );
		$this->assertFalse( $flags['is_oversized'], 'is_oversized must default to false for non-array row' );
	}
}
