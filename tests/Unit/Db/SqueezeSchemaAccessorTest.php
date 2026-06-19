<?php
/**
 * Unit tests for the Schema squeeze-table accessor methods and DB_VERSION '5' constant.
 *
 * @package AssetDrips
 */

declare( strict_types=1 );

namespace AssetDrips\Tests\Unit\Db;

use AssetDrips\Db\Schema;
use PHPUnit\Framework\TestCase;

/**
 * Tests Schema::squeeze_table(), Schema::squeeze_backups_table(), and DB_VERSION '5'.
 *
 * The DDL / dbDelta behaviour requires a live $wpdb and is exercised in
 * tests/Integration/SqueezeSchemaTest.php. These unit tests cover the public
 * accessors and the private DB_VERSION constant (via ReflectionClass).
 */
final class SqueezeSchemaAccessorTest extends TestCase {

	/**
	 * squeeze_table() returns a string ending in 'assetdrips_squeeze'.
	 *
	 * The unit harness bootstraps $GLOBALS['wpdb'] with prefix = 'wp_', so the
	 * expected return value is 'wp_assetdrips_squeeze'.
	 *
	 * @return void
	 */
	public function test_squeeze_table_returns_string_ending_in_assetdrips_squeeze(): void {
		$table = Schema::squeeze_table();

		$this->assertIsString( $table );
		$this->assertStringEndsWith( 'assetdrips_squeeze', $table );
	}

	/**
	 * squeeze_table() includes the wpdb prefix.
	 *
	 * Confirms the accessor prepends the global $wpdb->prefix (stubbed as 'wp_'
	 * in the unit harness).
	 *
	 * @return void
	 */
	public function test_squeeze_table_includes_wpdb_prefix(): void {
		$table = Schema::squeeze_table();

		$this->assertSame( 'wp_assetdrips_squeeze', $table );
	}

	/**
	 * squeeze_backups_table() returns a string ending in 'assetdrips_squeeze_backups'.
	 *
	 * @return void
	 */
	public function test_squeeze_backups_table_returns_string_ending_in_assetdrips_squeeze_backups(): void {
		$table = Schema::squeeze_backups_table();

		$this->assertIsString( $table );
		$this->assertStringEndsWith( 'assetdrips_squeeze_backups', $table );
	}

	/**
	 * squeeze_backups_table() includes the wpdb prefix.
	 *
	 * @return void
	 */
	public function test_squeeze_backups_table_includes_wpdb_prefix(): void {
		$table = Schema::squeeze_backups_table();

		$this->assertSame( 'wp_assetdrips_squeeze_backups', $table );
	}

	/**
	 * DB_VERSION private constant equals '5'.
	 *
	 * Uses ReflectionClass to read the private constant so the test does not
	 * require exposing it publicly. Phase 8 bumps DB_VERSION 4→5.
	 *
	 * @return void
	 */
	public function test_db_version_is_five(): void {
		$ref     = new \ReflectionClass( Schema::class );
		$version = $ref->getConstant( 'DB_VERSION' );

		$this->assertSame( '5', $version );
	}
}
