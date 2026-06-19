<?php
/**
 * Media index table schema integration test.
 *
 * @package AssetDrips
 */

declare( strict_types=1 );

namespace AssetDrips\Tests\Integration;

use AssetDrips\Db\Schema;
use WP_UnitTestCase;

/**
 * Verifies the assetdrips_media table installs, the version option is bumped to
 * '3', the new caption/description columns are present, the usage_locations table
 * is created, and the UNIQUE attachment_id key enforces one row per attachment.
 */
final class MediaIndexSchemaTest extends WP_UnitTestCase {

	/**
	 * The media index table exists after install().
	 *
	 * @return void
	 */
	public function test_install_creates_table(): void {
		global $wpdb;

		Schema::install();

		$table = Schema::media_table();
		$found = $wpdb->get_var(
			$wpdb->prepare( 'SHOW TABLES LIKE %s', $table )
		);

		$this->assertSame( $table, $found, "Expected table {$table} to exist." );
	}

	/**
	 * Records the bumped schema version, and maybe_upgrade() is a no-op when current.
	 *
	 * @return void
	 */
	public function test_version_is_recorded(): void {
		Schema::install();

		$this->assertSame( '3', get_option( 'assetdrips_db_version' ) );

		// Should not throw or change anything when already at the current version.
		Schema::maybe_upgrade();
		$this->assertSame( '3', get_option( 'assetdrips_db_version' ) );
	}

	/**
	 * DB_VERSION '3' is stored after install().
	 *
	 * This assertion is the primary integration gate for the Phase 2 schema migration.
	 * Run composer test:integration against a live MySQL before the verify gate.
	 *
	 * @return void
	 */
	public function test_version_is_3_after_install(): void {
		Schema::install();

		$this->assertSame( '3', get_option( 'assetdrips_db_version' ) );
	}

	/**
	 * The caption and description columns exist in the media table after install.
	 *
	 * Verifies that dbDelta non-destructively adds both columns to an existing table
	 * and that NOT NULL text/longtext is accepted by the target MySQL version.
	 * Run composer test:integration against a live MySQL before the verify gate.
	 *
	 * @return void
	 */
	public function test_caption_and_description_columns_exist(): void {
		global $wpdb;

		Schema::install();

		$table = Schema::media_table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Integration assertion; table name is a Schema constant, no user input.
		$cols = $wpdb->get_col( "SHOW COLUMNS FROM {$table}" );

		$this->assertContains( 'caption', $cols, 'Expected caption column in media table.' );
		$this->assertContains( 'description', $cols, 'Expected description column in media table.' );
	}

	/**
	 * The assetdrips_usage_locations table exists after install.
	 *
	 * Verifies that dbDelta creates the new 4th custom table on the same DB_VERSION
	 * '3' upgrade pass. Run composer test:integration against a live MySQL before
	 * the verify gate.
	 *
	 * @return void
	 */
	public function test_usage_locations_table_exists(): void {
		global $wpdb;

		Schema::install();

		$table = Schema::usage_locations_table();

		$found = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );

		$this->assertSame( $table, $found, "Expected table {$table} to exist." );
	}

	/**
	 * The media table enforces one row per attachment via UNIQUE KEY attachment_id.
	 *
	 * @return void
	 */
	public function test_media_attachment_id_is_unique(): void {
		global $wpdb;

		Schema::install();
		$table = Schema::media_table();

		$row = array(
			'attachment_id' => 4242,
			'filename'      => 'sunset.jpg',
			'title'         => 'Sunset',
			'alt'           => 'A sunset over the sea',
			'mime'          => 'image/jpeg',
			'mime_subtype'  => 'jpeg',
			'width'         => 2560,
			'height'        => 1707,
			'orientation'   => 'landscape',
			'filesize'      => 524288,
			'has_alt'       => 1,
			'uploaded_by'   => 1,
			'uploaded_at'   => '2026-06-07 00:00:00',
			'indexed_at'    => '2026-06-07 00:00:00',
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
