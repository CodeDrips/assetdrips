<?php
/**
 * Custom table schema install and migration.
 *
 * @package AssetDrips
 */

declare( strict_types=1 );

namespace AssetDrips\Db;

defined( 'ABSPATH' ) || exit;

/**
 * Owns the AssetDrips custom tables.
 *
 * Tables:
 *  - {prefix}assetdrips_results         one row per scanned attachment (tier + evidence).
 *  - {prefix}assetdrips_quarantine      recovery records for reversible cleanup.
 *  - {prefix}assetdrips_media           one denormalised index row per attachment.
 *  - {prefix}assetdrips_usage_locations derived lookup for "Used on …" inversion.
 *
 * Schema changes go through dbDelta and are versioned via an option so updates
 * migrate without requiring a manual reactivation.
 */
final class Schema {

	/**
	 * Bump when any table definition changes. Drives maybe_upgrade().
	 */
	private const DB_VERSION = '5';

	/**
	 * Option key holding the installed schema version.
	 */
	private const VERSION_OPTION = 'assetdrips_db_version';

	/**
	 * Unqualified results table name (without the wpdb prefix).
	 */
	private const RESULTS_TABLE = 'assetdrips_results';

	/**
	 * Unqualified quarantine table name (without the wpdb prefix).
	 */
	private const QUARANTINE_TABLE = 'assetdrips_quarantine';

	/**
	 * Unqualified media index table name (without the wpdb prefix).
	 */
	private const MEDIA_TABLE = 'assetdrips_media';

	/**
	 * Unqualified usage locations table name (without the wpdb prefix).
	 */
	private const USAGE_LOCATIONS_TABLE = 'assetdrips_usage_locations';

	/**
	 * Unqualified folders sort-order table name (without the wpdb prefix).
	 */
	private const FOLDERS_TABLE = 'assetdrips_folders';

	/**
	 * Unqualified squeeze optimization-state table name (without the wpdb prefix).
	 */
	private const SQUEEZE_TABLE = 'assetdrips_squeeze';

	/**
	 * Unqualified squeeze backup-records table name (without the wpdb prefix).
	 */
	private const SQUEEZE_BACKUPS_TABLE = 'assetdrips_squeeze_backups';

	/**
	 * Install or upgrade the tables, then record the version.
	 *
	 * Called from the activation hook.
	 *
	 * @return void
	 */
	public static function install(): void {
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		global $wpdb;
		$charset_collate = $wpdb->get_charset_collate();

		foreach ( self::table_definitions( $charset_collate ) as $sql ) {
			dbDelta( $sql );
		}

		update_option( self::VERSION_OPTION, self::DB_VERSION );
	}

	/**
	 * Run install() only when the stored schema version is behind.
	 *
	 * Cheap to call on every load: a single option read short-circuits when the
	 * schema is current.
	 *
	 * @return void
	 */
	public static function maybe_upgrade(): void {
		if ( get_option( self::VERSION_OPTION ) === self::DB_VERSION ) {
			return;
		}

		self::install();
	}

	/**
	 * Fully-qualified results table name.
	 *
	 * @return string
	 */
	public static function results_table(): string {
		global $wpdb;

		return $wpdb->prefix . self::RESULTS_TABLE;
	}

	/**
	 * Fully-qualified quarantine table name.
	 *
	 * @return string
	 */
	public static function quarantine_table(): string {
		global $wpdb;

		return $wpdb->prefix . self::QUARANTINE_TABLE;
	}

	/**
	 * Fully-qualified media index table name.
	 *
	 * @return string
	 */
	public static function media_table(): string {
		global $wpdb;

		return $wpdb->prefix . self::MEDIA_TABLE;
	}

	/**
	 * Fully-qualified usage locations table name.
	 *
	 * @return string
	 */
	public static function usage_locations_table(): string {
		global $wpdb;

		return $wpdb->prefix . self::USAGE_LOCATIONS_TABLE;
	}

	/**
	 * Fully-qualified folders sort-order table name.
	 *
	 * @return string
	 */
	public static function folders_table(): string {
		global $wpdb;

		return $wpdb->prefix . self::FOLDERS_TABLE;
	}

	/**
	 * Fully-qualified squeeze optimization-state table name.
	 *
	 * @return string
	 */
	public static function squeeze_table(): string {
		global $wpdb;

		return $wpdb->prefix . self::SQUEEZE_TABLE;
	}

	/**
	 * Fully-qualified squeeze backup-records table name.
	 *
	 * @return string
	 */
	public static function squeeze_backups_table(): string {
		global $wpdb;

		return $wpdb->prefix . self::SQUEEZE_BACKUPS_TABLE;
	}

	/**
	 * Build the dbDelta-formatted CREATE TABLE statements.
	 *
	 * Formatting matters: dbDelta is whitespace- and format-sensitive — two
	 * spaces after PRIMARY KEY, one definition per line, lowercase types. Do not
	 * "tidy" this.
	 *
	 * @param string $charset_collate Result of wpdb::get_charset_collate().
	 * @return array<int, string>
	 */
	private static function table_definitions( string $charset_collate ): array {
		global $wpdb;

		$results    = $wpdb->prefix . self::RESULTS_TABLE;
		$quarantine = $wpdb->prefix . self::QUARANTINE_TABLE;
		$media      = $wpdb->prefix . self::MEDIA_TABLE;

		$definitions = array();

		// One row per scanned attachment: its tier, score and evidence.
		// tier        USED | HIGH | MEDIUM | LOW
		// confidence  0-100 numeric score backing the tier
		// evidence    JSON array of UsageHit records ([] when zero hits)
		// coverage    JSON of coverage-gap flags active for this scan.
		$definitions[] = "CREATE TABLE {$results} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			attachment_id bigint(20) unsigned NOT NULL,
			tier varchar(10) NOT NULL,
			confidence smallint(5) unsigned NOT NULL DEFAULT 0,
			evidence longtext NOT NULL,
			coverage_flags longtext NOT NULL,
			scanned_at datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY attachment_id (attachment_id),
			KEY tier (tier),
			KEY scanned_at (scanned_at)
		) {$charset_collate};";

		// Recovery records for reversible cleanup. Files are moved, not deleted,
		// and the original DB rows are snapshotted so a restore is exact.
		// post_snapshot JSON of the original wp_posts row for the attachment
		// file_paths    JSON of original absolute file path(s) incl. variants
		// status        quarantined | restored | purged.
		$definitions[] = "CREATE TABLE {$quarantine} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			attachment_id bigint(20) unsigned NOT NULL,
			post_snapshot longtext NOT NULL,
			file_paths longtext NOT NULL,
			status varchar(20) NOT NULL DEFAULT 'quarantined',
			quarantined_at datetime NOT NULL,
			restored_at datetime DEFAULT NULL,
			PRIMARY KEY  (id),
			KEY attachment_id (attachment_id),
			KEY status (status)
		) {$charset_collate};";

		// Denormalised index: one row per attachment. Two freshness lanes —
		// structural columns (filename..indexed_at) kept current in real time by
		// hooks; usage columns (usage_count, is_used, usage_synced_at) refreshed by
		// the Sift scan + cron. folder_id/content_hash are nullable, populated by
		// later phases. dbDelta formatting is sacred here too: do not "tidy".
		$definitions[] = "CREATE TABLE {$media} (
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
			has_webp tinyint(1) NOT NULL DEFAULT 0,
			has_avif tinyint(1) NOT NULL DEFAULT 0,
			is_oversized tinyint(1) NOT NULL DEFAULT 0,
			PRIMARY KEY  (id),
			UNIQUE KEY attachment_id (attachment_id),
			KEY mime_subtype (mime_subtype),
			KEY is_used (is_used),
			KEY filesize (filesize),
			KEY content_hash (content_hash),
			KEY folder_id (folder_id),
			KEY uploaded_at (uploaded_at),
			KEY has_alt (has_alt),
			KEY has_webp (has_webp),
			KEY has_avif (has_avif),
			KEY is_oversized (is_oversized)
		) {$charset_collate};";

		// Derived usage-location lookup table. Populated by parsing scanner evidence
		// context strings (Plan 02-03). Enables the "Used on …" inversion without
		// a per-request evidence JSON scan. host_id is 0 for non-post/term contexts.
		$usage_locations = $wpdb->prefix . self::USAGE_LOCATIONS_TABLE;

		$definitions[] = "CREATE TABLE {$usage_locations} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			attachment_id bigint(20) unsigned NOT NULL,
			host_type varchar(20) NOT NULL DEFAULT '',
			host_id bigint(20) unsigned NOT NULL DEFAULT 0,
			source varchar(20) NOT NULL DEFAULT '',
			context varchar(191) NOT NULL DEFAULT '',
			PRIMARY KEY  (id),
			KEY attachment_id (attachment_id),
			KEY host (host_type,host_id)
		) {$charset_collate};";

		// Sort-order metadata for the folder tree. term_id is a FK to wp_terms.
		// Parent/hierarchy lives in wp_term_taxonomy; membership in wp_term_relationships.
		// This table stores only ordering metadata (sort_weight among siblings).
		$folders = $wpdb->prefix . self::FOLDERS_TABLE;

		$definitions[] = "CREATE TABLE {$folders} (
			term_id bigint(20) unsigned NOT NULL,
			sort_weight int(11) NOT NULL DEFAULT 0,
			PRIMARY KEY  (term_id)
		) {$charset_collate};";

		// Squeeze optimization-state table. One row per attachment; authoritative
		// record of optimization progress and byte savings for each op.
		// ops_completed / sizes_audit: set to '[]' / '{}' before insert (MySQL < 8.0
		// does not support expression DEFAULTs on longtext columns).
		$squeeze = $wpdb->prefix . self::SQUEEZE_TABLE;

		$definitions[] = "CREATE TABLE {$squeeze} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			attachment_id bigint(20) unsigned NOT NULL,
			status varchar(20) NOT NULL DEFAULT 'pending',
			original_bytes bigint(20) unsigned NOT NULL DEFAULT 0,
			optimized_bytes bigint(20) unsigned NOT NULL DEFAULT 0,
			webp_bytes bigint(20) unsigned NOT NULL DEFAULT 0,
			avif_bytes bigint(20) unsigned NOT NULL DEFAULT 0,
			ops_completed longtext NOT NULL,
			sizes_audit longtext NOT NULL,
			last_optimized_at datetime DEFAULT NULL,
			error_message text DEFAULT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY attachment_id (attachment_id),
			KEY status (status),
			KEY last_optimized_at (last_optimized_at)
		) {$charset_collate};";

		// Squeeze backup-records table. One row per backed-up file; an attachment
		// with both recompress and resize ops may have two rows.
		// op: 'recompress' or 'resize' — WebP/AVIF alternates are additive (no backup).
		// status: 'active', 'restored', 'purged'.
		$backups = $wpdb->prefix . self::SQUEEZE_BACKUPS_TABLE;

		$definitions[] = "CREATE TABLE {$backups} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			attachment_id bigint(20) unsigned NOT NULL,
			op varchar(20) NOT NULL DEFAULT '',
			original_path varchar(1000) NOT NULL DEFAULT '',
			backup_path varchar(1000) NOT NULL DEFAULT '',
			original_bytes bigint(20) unsigned NOT NULL DEFAULT 0,
			status varchar(20) NOT NULL DEFAULT 'active',
			backed_up_at datetime NOT NULL,
			restored_at datetime DEFAULT NULL,
			PRIMARY KEY  (id),
			KEY attachment_id (attachment_id),
			KEY status (status)
		) {$charset_collate};";

		return $definitions;
	}
}
