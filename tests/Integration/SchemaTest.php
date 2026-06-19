<?php
/**
 * Schema install smoke test.
 *
 * @package AssetDrips
 */

declare( strict_types=1 );

namespace AssetDrips\Tests\Integration;

use AssetDrips\Db\Schema;
use WP_UnitTestCase;

/**
 * Verifies the custom tables install and the version option is recorded.
 */
final class SchemaTest extends WP_UnitTestCase {

	/**
	 * Both custom tables exist after install().
	 *
	 * @return void
	 */
	public function test_install_creates_tables(): void {
		global $wpdb;

		Schema::install();

		foreach ( array( Schema::results_table(), Schema::quarantine_table() ) as $table ) {
			$found = $wpdb->get_var(
				$wpdb->prepare( 'SHOW TABLES LIKE %s', $table )
			);
			$this->assertSame( $table, $found, "Expected table {$table} to exist." );
		}
	}

	/**
	 * Records the schema version, and maybe_upgrade() is a no-op when current.
	 *
	 * @return void
	 */
	public function test_version_is_recorded(): void {
		Schema::install();

		$this->assertSame( '1', get_option( 'assetdrips_db_version' ) );

		// Should not throw or change anything when already at the current version.
		Schema::maybe_upgrade();
		$this->assertSame( '1', get_option( 'assetdrips_db_version' ) );
	}

	/**
	 * The results table enforces one row per attachment.
	 *
	 * @return void
	 */
	public function test_results_attachment_id_is_unique(): void {
		global $wpdb;

		Schema::install();
		$table = Schema::results_table();

		$row = array(
			'attachment_id'  => 123,
			'tier'           => 'HIGH',
			'confidence'     => 90,
			'evidence'       => '[]',
			'coverage_flags' => '[]',
			'scanned_at'     => '2026-06-07 00:00:00',
		);

		$first = $wpdb->insert( $table, $row );
		$this->assertSame( 1, $first );

		// Suppress the expected duplicate-key warning for the assertion.
		$wpdb->suppress_errors( true );
		$second = $wpdb->insert( $table, $row );
		$wpdb->suppress_errors( false );

		$this->assertFalse( (bool) $second, 'Duplicate attachment_id should be rejected.' );
	}
}
