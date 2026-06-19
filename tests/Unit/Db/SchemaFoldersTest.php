<?php
/**
 * Unit tests for the Schema folders-table additions (DB_VERSION 4, folders_table).
 *
 * @package AssetDrips
 */

declare( strict_types=1 );

namespace AssetDrips\Tests\Unit\Db;

use AssetDrips\Db\Schema;
use PHPUnit\Framework\TestCase;

/**
 * Tests the Schema::folders_table() accessor and the DB_VERSION '4' constant.
 *
 * The DDL / dbDelta behavior requires a live $wpdb and is exercised in
 * tests/Integration/SchemaTest.php. These unit tests cover the public
 * accessor and the private DB_VERSION constant (via ReflectionClass).
 */
final class SchemaFoldersTest extends TestCase {

	/**
	 * Folders_table() returns a string ending in 'assetdrips_folders'.
	 *
	 * The unit harness bootstraps $GLOBALS['wpdb'] with prefix = 'wp_', so the
	 * expected return value is 'wp_assetdrips_folders'.
	 *
	 * @return void
	 */
	public function test_folders_table_returns_string_ending_in_assetdrips_folders(): void {
		$table = Schema::folders_table();

		$this->assertIsString( $table );
		$this->assertStringEndsWith( 'assetdrips_folders', $table );
	}

	/**
	 * Folders_table() includes the wpdb prefix.
	 *
	 * Confirms the accessor prepends the global $wpdb->prefix (stubbed as 'wp_'
	 * in the unit harness).
	 *
	 * @return void
	 */
	public function test_folders_table_includes_wpdb_prefix(): void {
		$table = Schema::folders_table();

		$this->assertSame( 'wp_assetdrips_folders', $table );
	}

	/**
	 * DB_VERSION private constant equals '5' after the Phase 8 bump.
	 *
	 * Uses ReflectionClass to read the private constant so the test does not
	 * require exposing it publicly. This is the same pattern used in
	 * FindScreenTest to inspect private source-level constants.
	 *
	 * DB_VERSION was '4' at Phase 7; bumped to '5' at Phase 8 to trigger
	 * dbDelta for the two new squeeze tables and three media-table flag columns.
	 *
	 * @return void
	 */
	public function test_db_version_is_four(): void {
		$ref     = new \ReflectionClass( Schema::class );
		$version = $ref->getConstant( 'DB_VERSION' );

		$this->assertSame( '5', $version );
	}
}
