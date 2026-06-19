<?php
/**
 * Copy-verify backup and restore of attachment originals before destructive ops.
 *
 * @package AssetDrips
 */

declare( strict_types=1 );

namespace AssetDrips\Squeeze;

use AssetDrips\Db\Schema;
use AssetDrips\Index\MediaIndex;
use AssetDrips\Index\MediaRow;

defined( 'ABSPATH' ) || exit;

/**
 * Backs up attachment originals before any destructive Squeeze operation and
 * restores them on demand. Parallel to QuarantineManager but uses copy() (not
 * rename()) so the original stays live during the copy, and never touches
 * wp_posts or wp_postmeta.
 *
 * Threat mitigations:
 *  - T-09-02: ensure_web_inaccessible() writes .htaccess + index.php to backup root (D-02).
 *  - T-09-03: target_path() constrains to {backup_dir}/{attachment_id}/<uploads-relative>; strlen guard.
 *  - T-09-04: estimate_disk_requirement() gates bulk paths against disk_free_space + 20% buffer.
 *  - T-09-05: filesize(backup)===filesize(source) check before any record is written.
 */
final class BackupManager {

	/**
	 * Database handle.
	 *
	 * @var object
	 */
	private object $wpdb;

	/**
	 * Uploads base directory, no trailing slash.
	 *
	 * @var string
	 */
	private string $uploads_basedir;

	/**
	 * Backup base directory, no trailing slash.
	 *
	 * @var string
	 */
	private string $backup_dir;

	/**
	 * Optional SqueezeEngine instance for regenerate_sizes + cleanup_alternates (D-08).
	 *
	 * Null until first accessed; resolved via squeeze_engine() lazy accessor.
	 *
	 * @var SqueezeEngine|null
	 */
	private ?SqueezeEngine $squeeze_engine_instance = null;

	/**
	 * Construct with explicit dependencies.
	 *
	 * Accepts `object` rather than `\wpdb` so unit tests can inject an anonymous
	 * stub without loading the full WordPress test suite.
	 *
	 * The optional $squeeze_engine parameter allows tests to inject a stub;
	 * production callers omit it and rely on the lazy from_wordpress() accessor.
	 * It is the LAST parameter so callers from Plan 02 that omit it continue to work.
	 *
	 * @param object             $wpdb            Database handle (production: \wpdb; tests: anonymous stub).
	 * @param string             $uploads_basedir Uploads base directory (no trailing slash).
	 * @param string             $backup_dir      Backup base directory (no trailing slash).
	 * @param SqueezeEngine|null $squeeze_engine Optional SqueezeEngine; null triggers lazy from_wordpress().
	 */
	public function __construct( object $wpdb, string $uploads_basedir, string $backup_dir, ?SqueezeEngine $squeeze_engine = null ) {
		$this->wpdb                    = $wpdb;
		$this->uploads_basedir         = rtrim( $uploads_basedir, '/' );
		$this->backup_dir              = rtrim( $backup_dir, '/' );
		$this->squeeze_engine_instance = $squeeze_engine;
	}

	/**
	 * Construct from the live WordPress environment.
	 *
	 * @return self
	 */
	public static function from_wordpress(): self {
		global $wpdb;

		$uploads = wp_get_upload_dir();
		$basedir = (string) $uploads['basedir'];

		return new self(
			$wpdb,
			$basedir,
			$basedir . '/assetdrips-squeeze-backups'
		);
	}

	/**
	 * Compute the backup target path for an original file, preserving its
	 * uploads-relative layout so variant basenames never collide.
	 *
	 * @param string $uploads_basedir Uploads base directory.
	 * @param string $backup_dir      Backup base directory.
	 * @param int    $attachment_id   Attachment post ID.
	 * @param string $original_abs    Original absolute file path.
	 * @return string
	 */
	public static function target_path( string $uploads_basedir, string $backup_dir, int $attachment_id, string $original_abs ): string {
		$basedir = rtrim( $uploads_basedir, '/' );
		$prefix  = $basedir . '/';

		if ( str_starts_with( $original_abs, $prefix ) ) {
			$relative = substr( $original_abs, strlen( $prefix ) );
		} else {
			$relative = basename( $original_abs );
		}

		// Strip traversal components so a crafted attachment path (e.g. containing
		// '../') cannot escape the backup root (WR-02 / T-09-03).
		$relative = implode(
			'/',
			array_filter(
				explode( '/', $relative ),
				static fn( string $part ): bool => '.' !== $part && '..' !== $part && '' !== $part
			)
		);

		return rtrim( $backup_dir, '/' ) . '/' . $attachment_id . '/' . $relative;
	}

	/**
	 * Back up an attachment's original file before a destructive op.
	 *
	 * Uses copy() (not rename()) so the original stays live during the operation.
	 * Verifies filesize(backup) === filesize(source) before inserting a record (D-03).
	 * Returns false and writes no record on copy failure or filesize mismatch.
	 *
	 * @param int    $attachment_id Attachment post ID.
	 * @param string $original_path Absolute path to the original file.
	 * @param string $op            Operation: 'recompress' or 'resize'.
	 * @return bool True on success; false on failure (no DB record written on false).
	 */
	public function backup( int $attachment_id, string $original_path, string $op ): bool {
		$backup_path = self::target_path( $this->uploads_basedir, $this->backup_dir, $attachment_id, $original_path );

		// Path-length guard (Pitfall 4 / T-09-03): varchar(1000) column limit.
		if ( strlen( $backup_path ) >= 1000 ) {
			return false;
		}

		wp_mkdir_p( dirname( $backup_path ) );
		$this->ensure_web_inaccessible();

		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.copy_copy -- Backup copy within uploads; boolean return checked; silencing avoids duplicate warning.
		if ( ! @copy( $original_path, $backup_path ) ) {
			return false;
		}

		// Filesize verification (D-03 / T-09-05): abort and remove partial copy on mismatch.
		clearstatcache( true, $backup_path );
		if ( filesize( $backup_path ) !== filesize( $original_path ) ) {
			// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.unlink_unlink -- Best-effort cleanup of partial backup copy; wp_delete_file is not available during bootstrap.
			@unlink( $backup_path );
			return false;
		}

		$this->wpdb->insert(
			Schema::squeeze_backups_table(),
			array(
				'attachment_id'  => $attachment_id,
				'op'             => $op,
				'original_path'  => $original_path,
				'backup_path'    => $backup_path,
				'original_bytes' => (int) filesize( $original_path ),
				'status'         => BackupRecord::ACTIVE,
				'backed_up_at'   => current_time( 'mysql' ),
			)
		);

		return (bool) $this->wpdb->insert_id;
	}

	/**
	 * Write .htaccess and index.php to the backup root to prevent web access.
	 *
	 * Guards are written only when missing — two file_exists() checks.
	 * Written to the backup ROOT (not per-attachment subdir — Pitfall 6 / D-02).
	 *
	 * @return void
	 */
	private function ensure_web_inaccessible(): void {
		$htaccess  = $this->backup_dir . '/.htaccess';
		$index_php = $this->backup_dir . '/index.php';

		if ( ! file_exists( $htaccess ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Lightweight guard-file write; WP_Filesystem is not bootstrapped in cron/CLI contexts where backup() may be called.
			file_put_contents( $htaccess, "Require all denied\nDeny from all\n" );
		}

		if ( ! file_exists( $index_php ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Lightweight guard-file write; WP_Filesystem is not bootstrapped in cron/CLI contexts where backup() may be called.
			file_put_contents( $index_php, '' );
		}
	}

	/**
	 * Whether an active backup exists for the given attachment.
	 *
	 * @param int $attachment_id Attachment post ID.
	 * @return bool
	 */
	public function has_backup( int $attachment_id ): bool {
		return ! empty( $this->get_active_backups( $attachment_id ) );
	}

	/**
	 * Return all backup records for an attachment, ordered by creation (oldest first).
	 *
	 * @param int $attachment_id Attachment post ID.
	 * @return BackupRecord[]
	 */
	public function list_for_attachment( int $attachment_id ): array {
		$table = Schema::squeeze_backups_table();

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Backup record query; table name is a Schema constant (never input) and the id is bound via prepare().
		$rows = $this->wpdb->get_results(
			$this->wpdb->prepare(
				"SELECT * FROM {$table} WHERE attachment_id = %d ORDER BY id ASC",
				$attachment_id
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		return array_map( array( $this, 'hydrate' ), (array) $rows );
	}

	/**
	 * Return all ACTIVE backup records for an attachment, ordered oldest-first.
	 *
	 * @param int $attachment_id Attachment post ID.
	 * @return BackupRecord[]
	 */
	public function get_active_backups( int $attachment_id ): array {
		$table = Schema::squeeze_backups_table();

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Active backup select; table name is a Schema constant (never input) and values are bound via prepare().
		$rows = $this->wpdb->get_results(
			$this->wpdb->prepare(
				"SELECT * FROM {$table} WHERE attachment_id = %d AND status = %s ORDER BY id ASC",
				$attachment_id,
				BackupRecord::ACTIVE
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		return array_map( array( $this, 'hydrate' ), (array) $rows );
	}

	/**
	 * File-only rollback: rename each active backup file back over its original
	 * path without sub-size regeneration, metadata updates, or DB status changes.
	 *
	 * Called by SqueezeEngine on WP_Image_Editor::save() failure so a
	 * partially-written original is immediately replaced by the backup copy
	 * (D-03 best-effort file restore). The backup record intentionally stays
	 * ACTIVE — the caller decides whether to invoke restore_all() for a full
	 * restore or to surface save_failed to the user.
	 *
	 * Mirrors QuarantineManager's atomic rename-back discipline: already-renamed
	 * files are reversed on failure so the attachment is never left in a split
	 * state.
	 *
	 * @param int $attachment_id Attachment post ID.
	 * @return bool True when all backup files were renamed back; false on failure.
	 */
	public function restore_backup_file_only( int $attachment_id ): bool {
		$records = $this->get_active_backups( $attachment_id );

		if ( empty( $records ) ) {
			return false;
		}

		$restored = array();

		foreach ( $records as $record ) {
			wp_mkdir_p( dirname( $record->original_path() ) );

			// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.rename_rename -- Atomic same-filesystem rename within uploads; boolean return checked; silencing avoids a duplicate warning on the handled failure path.
			if ( ! @rename( $record->backup_path(), $record->original_path() ) ) {
				// Rollback: reverse already-renamed files (QuarantineManager move_back discipline).
				foreach ( array_reverse( $restored ) as $r ) {
					// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.rename_rename -- Best-effort reversal of an already-failed batch.
					@rename( $r->original_path(), $r->backup_path() );
				}
				return false;
			}

			$restored[] = $record;
		}

		return true;
	}

	/**
	 * Remove all backup files for an attachment and mark records PURGED.
	 *
	 * Called on the delete_attachment hook to clean up orphan backup files.
	 *
	 * @param int $attachment_id Attachment post ID.
	 * @return void
	 */
	public function purge_all( int $attachment_id ): void {
		$records = $this->list_for_attachment( $attachment_id );

		foreach ( $records as $record ) {
			$path = $record->backup_path();
			if ( '' !== $path && file_exists( $path ) ) {
				wp_delete_file( $path );
			}

			$this->wpdb->update(
				Schema::squeeze_backups_table(),
				array( 'status' => BackupRecord::PURGED ),
				array( 'id' => $record->id() )
			);
		}
	}

	/**
	 * Restore active backups for an attachment — four-step atomic sequence (D-08).
	 *
	 * Ordered contract (D-08):
	 *  1. Rename each backup file back over its original path.
	 *     On failure: reverse already-renamed files (QuarantineManager::move_back discipline).
	 *  2. SqueezeEngine::regenerate_sizes() rebuilds sub-sizes via wp_create_image_subsizes
	 *     (NOT wp_generate_attachment_metadata — prevents SqueezeHooks re-queue, T-09-09).
	 *  3. MediaIndex::upsert_structural() — explicit structural lane sync (D-09).
	 *  4. OptimizationIndex::update_status(RESTORED) + update_media_index_flags(false×3).
	 *  5. SqueezeEngine::cleanup_alternates() — delete .webp/.avif siblings (D-05).
	 *  6. Mark backup records RESTORED in the DB.
	 *
	 * @param int $attachment_id Attachment post ID.
	 * @return void
	 *
	 * @throws \RuntimeException When no active backup exists or a rename fails.
	 * @throws \Throwable        Propagated after rolling back already-renamed files on failure.
	 */
	public function restore_all( int $attachment_id ): void {
		// 1a. Verify active backups exist.
		$records = $this->get_active_backups( $attachment_id );

		if ( empty( $records ) ) {
			throw new \RuntimeException( esc_html( "No active backup for {$attachment_id}." ) );
		}

		$restored = array();

		// 1b. Rename backup files back over originals (atomic on same filesystem).
		try {
			foreach ( $records as $record ) {
				wp_mkdir_p( dirname( $record->original_path() ) );

				// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.rename_rename -- Atomic same-filesystem move within uploads; the boolean return is checked and silencing avoids a duplicate warning on the handled failure path.
				if ( ! @rename( $record->backup_path(), $record->original_path() ) ) {
					throw new \RuntimeException( esc_html( "Could not restore {$record->backup_path()}." ) );
				}

				$restored[] = $record;
			}
		} catch ( \Throwable $error ) {
			// Rollback: reverse already-renamed files (QuarantineManager move_back discipline).
			foreach ( array_reverse( $restored ) as $r ) {
				// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.rename_rename -- Best-effort reversal of an already-failed batch.
				@rename( $r->original_path(), $r->backup_path() );
			}
			throw $error;
		}

		// 2. Rebuild sub-sizes from the restored original (T-09-09 / D-08).
		$engine = $this->squeeze_engine();
		$engine->regenerate_sizes( $attachment_id );

		// 3. Explicit structural index sync (D-09) — outside hook scope.
		MediaIndex::from_wordpress()->upsert_structural(
			MediaRow::from_attachment(
				$attachment_id,
				basename( (string) get_attached_file( $attachment_id ) ),
				'',
				'',
				'',
				'',
				(string) get_post_mime_type( $attachment_id ),
				(array) wp_get_attachment_metadata( $attachment_id ),
				is_string( get_attached_file( $attachment_id ) ) && file_exists( get_attached_file( $attachment_id ) )
					? (int) filesize( (string) get_attached_file( $attachment_id ) )
					: 0,
				0,
				'',
				current_time( 'mysql' )
			)
		);

		// 4. Update squeeze status and clear media flag columns.
		$optimization_index = OptimizationIndex::from_wordpress();
		$optimization_index->update_status( $attachment_id, OptimizationRecord::RESTORED );
		$optimization_index->update_media_index_flags( $attachment_id, false, false, false );

		// 5. Delete .webp/.avif alternates (no-op-safe; Phase 10 may not have generated any).
		$engine->cleanup_alternates( $attachment_id );

		// 6. Mark backup records as restored.
		foreach ( $restored as $r ) {
			// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Status update for a specific record row; table name is a Schema constant (never input) and values are defensive literals.
			$this->wpdb->update(
				Schema::squeeze_backups_table(),
				array(
					'status'      => BackupRecord::RESTORED,
					'restored_at' => current_time( 'mysql' ),
				),
				array( 'id' => $r->id() )
			);
			// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		}
	}

	/**
	 * Lazy accessor for the SqueezeEngine instance.
	 *
	 * Returns the constructor-injected instance when present (tests can inject a stub);
	 * falls back to SqueezeEngine::from_wordpress() for production callers that omit the param.
	 *
	 * @return SqueezeEngine
	 */
	private function squeeze_engine(): SqueezeEngine {
		if ( null === $this->squeeze_engine_instance ) {
			$this->squeeze_engine_instance = SqueezeEngine::from_wordpress();
		}
		return $this->squeeze_engine_instance;
	}

	/**
	 * Sum of original_bytes for all ACTIVE backup records.
	 *
	 * Used for the BAK-04 backup disk usage display on SqueezeScreen.
	 *
	 * @return int Total bytes across all active backups.
	 */
	public function total_active_bytes(): int {
		$table = Schema::squeeze_backups_table();

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Aggregate query; table name is a Schema constant (never input) and status is bound via prepare().
		$sum = $this->wpdb->get_var(
			$this->wpdb->prepare(
				"SELECT SUM(original_bytes) FROM {$table} WHERE status = %s",
				BackupRecord::ACTIVE
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		return (int) $sum;
	}

	/**
	 * Estimate the disk space required to back up the given source files.
	 *
	 * Returns estimated total bytes × 1.2 buffer (D-07 / RSZ-02).
	 *
	 * @param string[] $source_paths Absolute paths to source files.
	 * @return int Required bytes (estimate × 1.2 buffer).
	 */
	public function estimate_disk_requirement( array $source_paths ): int {
		$total = 0;
		foreach ( $source_paths as $path ) {
			if ( file_exists( $path ) ) {
				$total += (int) filesize( $path );
			}
		}

		return (int) ( $total * 1.2 );
	}

	/**
	 * Estimate batch backup space including free-space check (D-07 / RSZ-02).
	 *
	 * @param string[] $source_paths Absolute paths to source files.
	 * @return array{estimate:int,required:int,free:int|float,sufficient:bool}
	 */
	public function estimate_batch_space( array $source_paths ): array {
		$estimate = $this->estimate_disk_requirement( $source_paths );
		$required = (int) ( $estimate );

		$free = function_exists( 'disk_free_space' )
			? disk_free_space( $this->uploads_basedir )
			: PHP_INT_MAX;

		return array(
			'estimate'   => $estimate,
			'required'   => $required,
			'free'       => $free,
			'sufficient' => ( $free >= $required ),
		);
	}

	/**
	 * Build a BackupRecord from a raw table row, using defensive casts.
	 *
	 * @param array<string, mixed> $row Table row (ARRAY_A from wpdb).
	 * @return BackupRecord
	 */
	private function hydrate( array $row ): BackupRecord {
		return new BackupRecord(
			(int) ( $row['id'] ?? 0 ),
			(int) ( $row['attachment_id'] ?? 0 ),
			(string) ( $row['op'] ?? '' ),
			(string) ( $row['original_path'] ?? '' ),
			(string) ( $row['backup_path'] ?? '' ),
			(int) ( $row['original_bytes'] ?? 0 ),
			(string) ( $row['status'] ?? BackupRecord::ACTIVE ),
			(string) ( $row['backed_up_at'] ?? '' ),
			isset( $row['restored_at'] ) && '' !== $row['restored_at'] ? (string) $row['restored_at'] : null
		);
	}
}
