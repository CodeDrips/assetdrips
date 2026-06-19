<?php
/**
 * Squeeze schema integration tests (D-03, D-04).
 *
 * @package AssetDrips
 */

declare( strict_types=1 );

namespace AssetDrips\Tests\Integration;

use AssetDrips\Db\Schema;
use WP_UnitTestCase;

/**
 * Verifies that after Schema::install():
 *  - The assetdrips_squeeze and assetdrips_squeeze_backups tables exist.
 *  - The has_webp, has_avif, and is_oversized columns are present on assetdrips_media.
 *  - DB_VERSION is stored as '5'.
 *  - maybe_upgrade() is a no-op when already at version '5'.
 */
final class SqueezeSchemaTest extends WP_UnitTestCase {

	/**
	 * install() creates both squeeze tables (D-04).
	 *
	 * @return void
	 */
	public function test_install_creates_squeeze_tables(): void {
		global $wpdb;

		Schema::install();

		foreach ( array( Schema::squeeze_table(), Schema::squeeze_backups_table() ) as $table ) {
			$found = $wpdb->get_var(
				$wpdb->prepare( 'SHOW TABLES LIKE %s', $table )
			);
			$this->assertSame( $table, $found, "Expected table {$table} to exist." );
		}
	}

	/**
	 * The three flag columns exist on the media table after install() (D-03).
	 *
	 * Verifies that dbDelta non-destructively added has_webp, has_avif, and
	 * is_oversized to the existing assetdrips_media table.
	 *
	 * @return void
	 */
	public function test_flag_columns_exist_on_media_table(): void {
		global $wpdb;

		Schema::install();

		$table = Schema::media_table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Integration assertion; table name is a Schema constant, no user input.
		$cols = $wpdb->get_col( "SHOW COLUMNS FROM {$table}" );

		$this->assertContains( 'has_webp', $cols, 'Expected has_webp column on media table.' );
		$this->assertContains( 'has_avif', $cols, 'Expected has_avif column on media table.' );
		$this->assertContains( 'is_oversized', $cols, 'Expected is_oversized column on media table.' );
	}

	/**
	 * DB_VERSION '5' is stored after install() (D-03, D-04).
	 *
	 * @return void
	 */
	public function test_version_is_five(): void {
		Schema::install();

		$this->assertSame( '5', get_option( 'assetdrips_db_version' ) );
	}

	/**
	 * maybe_upgrade() is a no-op when the version is already '5' (D-03, D-04).
	 *
	 * @return void
	 */
	public function test_maybe_upgrade_no_op(): void {
		Schema::install();
		$this->assertSame( '5', get_option( 'assetdrips_db_version' ) );

		// Calling maybe_upgrade() again must not throw or change the version.
		Schema::maybe_upgrade();
		$this->assertSame( '5', get_option( 'assetdrips_db_version' ) );
	}

	/**
	 * dbDelta ALTER path: flag columns are added to a pre-existing v4 media table (WR-01).
	 *
	 * Simulates an upgrade from DB_VERSION 4 to 5 by:
	 *  1. Creating the media table *without* the three Phase-8 flag columns
	 *     (has_webp, has_avif, is_oversized) to represent the v4 schema state.
	 *  2. Running Schema::install() (which calls dbDelta on the full v5 definition).
	 *  3. Asserting that all three columns and their indexes now exist — verifying
	 *     that dbDelta correctly issued ALTER TABLE statements rather than only
	 *     handling a fresh CREATE.
	 *
	 * This test covers the production upgrade path that the other schema tests do
	 * not exercise (they always run on a clean DB, hitting the CREATE path).
	 *
	 * @return void
	 */
	public function test_flag_columns_added_on_upgrade_from_v4_schema(): void {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$media            = Schema::media_table();
		$charset_collate  = $wpdb->get_charset_collate();

		// Drop and recreate the media table without the three Phase-8 flag columns,
		// mimicking the v4 schema state so dbDelta must ALTER to add them.
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Integration test scaffolding; no user input.
		$wpdb->query( "DROP TABLE IF EXISTS {$media}" );

		dbDelta( "CREATE TABLE {$media} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			attachment_id bigint(20) unsigned NOT NULL,
			filename varchar(255) NOT NULL DEFAULT '',
			title text NOT NULL,
			alt text NOT NULL,
			caption text NOT NULL,
			description longtext NOT NULL,
			mime varchar(100) NOT NULL DEFAULT '',
			mime_subtype varchar(40) NOT NULL DEFAULT '',
			width mediumint(8) unsigned NOT NULL DEFAULT 0,
			height mediumint(8) unsigned NOT NULL DEFAULT 0,
			orientation varchar(10) NOT NULL DEFAULT '',
			filesize bigint(20) unsigned NOT NULL DEFAULT 0,
			has_alt tinyint(1) NOT NULL DEFAULT 0,
			folder_id bigint(20) unsigned DEFAULT NULL,
			usage_count int(10) unsigned NOT NULL DEFAULT 0,
			is_used tinyint(1) NOT NULL DEFAULT 0,
			content_hash char(40) DEFAULT NULL,
			uploaded_by bigint(20) unsigned NOT NULL DEFAULT 0,
			uploaded_at datetime NOT NULL,
			indexed_at datetime NOT NULL,
			usage_synced_at datetime DEFAULT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY attachment_id (attachment_id),
			KEY mime_subtype (mime_subtype),
			KEY is_used (is_used),
			KEY filesize (filesize),
			KEY content_hash (content_hash),
			KEY folder_id (folder_id),
			KEY uploaded_at (uploaded_at),
			KEY has_alt (has_alt)
		) {$charset_collate};" );
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		// Confirm the flag columns are absent in the v4 table to validate the test setup.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Integration assertion.
		$cols_before = $wpdb->get_col( "SHOW COLUMNS FROM {$media}" );
		$this->assertNotContains( 'has_webp',     $cols_before, 'Test setup: has_webp must be absent before upgrade.' );
		$this->assertNotContains( 'has_avif',     $cols_before, 'Test setup: has_avif must be absent before upgrade.' );
		$this->assertNotContains( 'is_oversized', $cols_before, 'Test setup: is_oversized must be absent before upgrade.' );

		// Run the full v5 install — dbDelta should ALTER the existing table.
		Schema::install();

		// Assert the three flag columns now exist (dbDelta ALTER path verified).
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Integration assertion.
		$cols_after = $wpdb->get_col( "SHOW COLUMNS FROM {$media}" );
		$this->assertContains( 'has_webp',     $cols_after, 'dbDelta must add has_webp on upgrade from v4.' );
		$this->assertContains( 'has_avif',     $cols_after, 'dbDelta must add has_avif on upgrade from v4.' );
		$this->assertContains( 'is_oversized', $cols_after, 'dbDelta must add is_oversized on upgrade from v4.' );

		// Assert the three new indexes also exist after the upgrade.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Integration assertion.
		$indexes = $wpdb->get_col( "SHOW INDEX FROM {$media} WHERE Key_name IN ('has_webp','has_avif','is_oversized')", 2 );
		$this->assertContains( 'has_webp',     $indexes, 'dbDelta must add KEY has_webp on upgrade from v4.' );
		$this->assertContains( 'has_avif',     $indexes, 'dbDelta must add KEY has_avif on upgrade from v4.' );
		$this->assertContains( 'is_oversized', $indexes, 'dbDelta must add KEY is_oversized on upgrade from v4.' );
	}
}
