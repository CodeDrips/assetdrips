<?php
/**
 * Reversible quarantine and restore of attachments.
 *
 * @package AssetDrips
 */

declare( strict_types=1 );

namespace AssetDrips\Quarantine;

use AssetDrips\Db\Schema;
use AssetDrips\Inventory\AttachmentCatalogue;

defined( 'ABSPATH' ) || exit;

/**
 * Moves attachments out of the live site reversibly, and puts them back exactly.
 *
 * The contract is the product's trust promise made physical: nothing is ever
 * deleted by quarantine. Quarantine snapshots the wp_posts row and all postmeta,
 * MOVES (renames) every file into a quarantine directory, then removes the DB
 * rows. Restore re-inserts the snapshot with the original ID and moves the files
 * back — an exact round-trip. Permanent deletion is a separate, explicit purge.
 *
 * Every step rolls back on failure: a half-finished quarantine leaves the site
 * exactly as it was found.
 */
final class QuarantineManager {

	/**
	 * Database handle.
	 *
	 * @var \wpdb
	 */
	private \wpdb $wpdb;

	/**
	 * Catalogue used to resolve an attachment's file paths before removal.
	 *
	 * @var AttachmentCatalogue
	 */
	private AttachmentCatalogue $catalogue;

	/**
	 * Uploads base directory, no trailing slash.
	 *
	 * @var string
	 */
	private string $uploads_basedir;

	/**
	 * Quarantine base directory, no trailing slash.
	 *
	 * @var string
	 */
	private string $quarantine_dir;

	/**
	 * Construct with explicit dependencies.
	 *
	 * @param \wpdb               $wpdb            Database handle.
	 * @param AttachmentCatalogue $catalogue       Catalogue for path resolution.
	 * @param string              $uploads_basedir Uploads base directory.
	 * @param string              $quarantine_dir  Quarantine base directory.
	 */
	public function __construct( \wpdb $wpdb, AttachmentCatalogue $catalogue, string $uploads_basedir, string $quarantine_dir ) {
		$this->wpdb            = $wpdb;
		$this->catalogue       = $catalogue;
		$this->uploads_basedir = rtrim( $uploads_basedir, '/' );
		$this->quarantine_dir  = rtrim( $quarantine_dir, '/' );
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
			AttachmentCatalogue::from_wordpress(),
			$basedir,
			$basedir . '/assetdrips-quarantine'
		);
	}

	/**
	 * Compute the quarantine target path for an original file, preserving its
	 * uploads-relative layout so variant basenames never collide.
	 *
	 * @param string $uploads_basedir Uploads base directory.
	 * @param string $quarantine_dir  Quarantine base directory.
	 * @param int    $attachment_id   Attachment post ID.
	 * @param string $original_abs    Original absolute file path.
	 * @return string
	 */
	public static function target_path( string $uploads_basedir, string $quarantine_dir, int $attachment_id, string $original_abs ): string {
		$basedir = rtrim( $uploads_basedir, '/' );
		$prefix  = $basedir . '/';

		if ( str_starts_with( $original_abs, $prefix ) ) {
			$relative = substr( $original_abs, strlen( $prefix ) );
		} else {
			$relative = basename( $original_abs );
		}

		return rtrim( $quarantine_dir, '/' ) . '/' . $attachment_id . '/' . ltrim( $relative, '/' );
	}

	/**
	 * Quarantine an attachment: snapshot, move files, remove DB rows.
	 *
	 * @param int $attachment_id Attachment post ID.
	 * @return int The recovery record ID.
	 *
	 * @throws \RuntimeException When the attachment is missing or a move fails.
	 */
	public function quarantine( int $attachment_id ): int {
		$post = $this->fetch_post_row( $attachment_id );
		if ( null === $post || 'attachment' !== ( $post['post_type'] ?? '' ) ) {
			throw new \RuntimeException( esc_html( "Attachment {$attachment_id} not found." ) );
		}

		$meta  = $this->fetch_postmeta_rows( $attachment_id );
		$files = $this->existing_files( $attachment_id );

		$moved = $this->move_files( $attachment_id, $files );

		$inserted = $this->wpdb->insert(
			Schema::quarantine_table(),
			array(
				'attachment_id'  => $attachment_id,
				'post_snapshot'  => (string) wp_json_encode(
					array(
						'post'     => $post,
						'postmeta' => $meta,
					)
				),
				'file_paths'     => (string) wp_json_encode( $moved ),
				'status'         => RecoveryRecord::QUARANTINED,
				'quarantined_at' => current_time( 'mysql' ),
			)
		);

		if ( false === $inserted ) {
			$this->move_back( $moved );
			throw new \RuntimeException( esc_html( "Could not record quarantine for {$attachment_id}." ) );
		}

		$record_id = (int) $this->wpdb->insert_id;

		$this->delete_attachment_rows( $attachment_id );
		clean_post_cache( $attachment_id );

		return $record_id;
	}

	/**
	 * Restore a quarantined attachment exactly: re-insert rows, move files back.
	 *
	 * @param int $record_id Recovery record ID.
	 * @return void
	 *
	 * @throws \RuntimeException When the record is missing, not restorable, or the ID is taken.
	 * @throws \Throwable When moving files back fails (DB re-insert is rolled back).
	 */
	public function restore( int $record_id ): void {
		$record = $this->get( $record_id );
		if ( null === $record ) {
			throw new \RuntimeException( esc_html( "Recovery record {$record_id} not found." ) );
		}
		if ( ! $record->is_restorable() ) {
			throw new \RuntimeException( esc_html( "Record {$record_id} is {$record->status()}, not restorable." ) );
		}

		$attachment_id = $record->attachment_id();
		if ( null !== $this->fetch_post_row( $attachment_id ) ) {
			throw new \RuntimeException( esc_html( "Cannot restore: post {$attachment_id} already exists." ) );
		}

		$this->reinsert_attachment_rows( $record );

		try {
			$this->move_back( $record->file_paths() );
		} catch ( \Throwable $error ) {
			$this->delete_attachment_rows( $attachment_id );
			throw $error;
		}

		clean_post_cache( $attachment_id );

		$this->wpdb->update(
			Schema::quarantine_table(),
			array(
				'status'      => RecoveryRecord::RESTORED,
				'restored_at' => current_time( 'mysql' ),
			),
			array( 'id' => $record_id )
		);
	}

	/**
	 * Permanently delete the quarantined files for a record. Explicit and
	 * irreversible — the only place a file is removed.
	 *
	 * @param int $record_id Recovery record ID.
	 * @return void
	 *
	 * @throws \RuntimeException When the record is missing or not quarantined.
	 */
	public function purge( int $record_id ): void {
		$record = $this->get( $record_id );
		if ( null === $record ) {
			throw new \RuntimeException( esc_html( "Recovery record {$record_id} not found." ) );
		}
		if ( ! $record->is_restorable() ) {
			throw new \RuntimeException( esc_html( "Record {$record_id} is {$record->status()}; only quarantined records can be purged." ) );
		}

		foreach ( $record->file_paths() as $pair ) {
			$path = $pair['to'] ?? '';
			if ( '' !== $path && file_exists( $path ) ) {
				wp_delete_file( $path );
			}
		}

		$this->wpdb->update(
			Schema::quarantine_table(),
			array( 'status' => RecoveryRecord::PURGED ),
			array( 'id' => $record_id )
		);
	}

	/**
	 * Load a recovery record by ID.
	 *
	 * @param int $record_id Recovery record ID.
	 * @return RecoveryRecord|null
	 */
	public function get( int $record_id ): ?RecoveryRecord {
		$wpdb  = $this->wpdb;
		$table = Schema::quarantine_table();

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name comes from Schema, not input; the id is a %d placeholder.
		$sql = $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $record_id );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared -- Pre-prepared query; recovery-state read where caching would be wrong.
		$row = $wpdb->get_row( $sql, ARRAY_A );

		return is_array( $row ) ? $this->hydrate( $row ) : null;
	}

	/**
	 * List recovery records with the given status.
	 *
	 * @param string $status One of the RecoveryRecord status constants.
	 * @return RecoveryRecord[]
	 */
	public function list_by_status( string $status ): array {
		$wpdb  = $this->wpdb;
		$table = Schema::quarantine_table();

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name comes from Schema, not input; the status is a %s placeholder.
		$sql = $wpdb->prepare( "SELECT * FROM {$table} WHERE status = %s ORDER BY id DESC", $status );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared -- Pre-prepared query; recovery-state read where caching would be wrong.
		$rows = $wpdb->get_results( $sql, ARRAY_A );

		return array_map( array( $this, 'hydrate' ), (array) $rows );
	}

	/**
	 * Build a RecoveryRecord from a raw table row.
	 *
	 * @param array<string, mixed> $row Table row.
	 * @return RecoveryRecord
	 */
	private function hydrate( array $row ): RecoveryRecord {
		$snapshot = json_decode( (string) ( $row['post_snapshot'] ?? '' ), true );
		$paths    = json_decode( (string) ( $row['file_paths'] ?? '' ), true );

		return new RecoveryRecord(
			(int) ( $row['id'] ?? 0 ),
			(int) ( $row['attachment_id'] ?? 0 ),
			(string) ( $row['status'] ?? '' ),
			is_array( $snapshot ) ? $snapshot : array(),
			is_array( $paths ) ? $paths : array()
		);
	}

	/**
	 * Move an attachment's files into quarantine, rolling back on any failure.
	 *
	 * @param int      $attachment_id Attachment post ID.
	 * @param string[] $files         Absolute source paths.
	 * @return array<int, array<string, string>> The from/to map of moved files.
	 *
	 * @throws \RuntimeException When a move fails.
	 * @throws \Throwable Propagated after rolling back already-moved files.
	 */
	private function move_files( int $attachment_id, array $files ): array {
		$moved = array();

		try {
			foreach ( $files as $from ) {
				$to = self::target_path( $this->uploads_basedir, $this->quarantine_dir, $attachment_id, $from );
				wp_mkdir_p( dirname( $to ) );

				// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.rename_rename -- Atomic same-filesystem move within uploads; the boolean return is checked and silencing avoids a duplicate warning on the handled failure path.
				if ( ! @rename( $from, $to ) ) {
					throw new \RuntimeException( esc_html( "Could not move {$from}." ) );
				}

				$moved[] = array(
					'from' => $from,
					'to'   => $to,
				);
			}
		} catch ( \Throwable $error ) {
			$this->move_back( $moved );
			throw $error;
		}

		return $moved;
	}

	/**
	 * Move files back to their original locations (restore or rollback).
	 *
	 * @param array<int, array<string, string>> $pairs From/to map.
	 * @return void
	 *
	 * @throws \RuntimeException When a move fails (already-moved files are reversed).
	 * @throws \Throwable Propagated after reversing the already-moved files.
	 */
	private function move_back( array $pairs ): void {
		$done = array();

		try {
			foreach ( $pairs as $pair ) {
				$from = $pair['to'] ?? '';
				$to   = $pair['from'] ?? '';
				if ( '' === $from || '' === $to || ! file_exists( $from ) ) {
					continue;
				}

				wp_mkdir_p( dirname( $to ) );

				// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.rename_rename -- Atomic same-filesystem move within uploads; the boolean return is checked and silencing avoids a duplicate warning on the handled failure path.
				if ( ! @rename( $from, $to ) ) {
					throw new \RuntimeException( esc_html( "Could not move {$from} back." ) );
				}

				$done[] = $pair;
			}
		} catch ( \Throwable $error ) {
			foreach ( array_reverse( $done ) as $pair ) {
				// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.rename_rename -- Best-effort reversal of an already-failed batch.
				@rename( $pair['from'], $pair['to'] );
			}
			throw $error;
		}
	}

	/**
	 * The absolute paths of an attachment's files that currently exist.
	 *
	 * @param int $attachment_id Attachment post ID.
	 * @return string[]
	 */
	private function existing_files( int $attachment_id ): array {
		$keys = $this->catalogue->keys_for( $attachment_id );
		if ( null === $keys ) {
			return array();
		}

		return array_values( array_filter( $keys->paths(), 'file_exists' ) );
	}

	/**
	 * Fetch the wp_posts row for an attachment.
	 *
	 * @param int $attachment_id Attachment post ID.
	 * @return array<string, mixed>|null
	 */
	private function fetch_post_row( int $attachment_id ): ?array {
		$wpdb = $this->wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Reading exact row state to snapshot; caching would be wrong here.
		$row = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$wpdb->posts} WHERE ID = %d", $attachment_id ),
			ARRAY_A
		);

		return is_array( $row ) ? $row : null;
	}

	/**
	 * Fetch the postmeta rows for an attachment.
	 *
	 * @param int $attachment_id Attachment post ID.
	 * @return array<int, array<string, mixed>>
	 */
	private function fetch_postmeta_rows( int $attachment_id ): array {
		$wpdb = $this->wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Reading exact meta state to snapshot; caching would be wrong here.
		$rows = $wpdb->get_results(
			$wpdb->prepare( "SELECT meta_key, meta_value FROM {$wpdb->postmeta} WHERE post_id = %d", $attachment_id ),
			ARRAY_A
		);

		return (array) $rows;
	}

	/**
	 * Delete an attachment's wp_posts and postmeta rows.
	 *
	 * @param int $attachment_id Attachment post ID.
	 * @return void
	 */
	private function delete_attachment_rows( int $attachment_id ): void {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Targeted row removal for reversible quarantine.
		$this->wpdb->delete( $this->wpdb->postmeta, array( 'post_id' => $attachment_id ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Targeted row removal for reversible quarantine.
		$this->wpdb->delete( $this->wpdb->posts, array( 'ID' => $attachment_id ) );
	}

	/**
	 * Re-insert an attachment's snapshotted rows with the original ID.
	 *
	 * @param RecoveryRecord $record Recovery record.
	 * @return void
	 */
	private function reinsert_attachment_rows( RecoveryRecord $record ): void {
		$post = $record->post_row();
		if ( array() !== $post ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Re-inserting the snapshotted attachment row, original ID included.
			$this->wpdb->insert( $this->wpdb->posts, $post );
		}

		foreach ( $record->postmeta_rows() as $meta ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Re-inserting snapshotted postmeta.
			$this->wpdb->insert(
				$this->wpdb->postmeta,
				array(
					'post_id'    => $record->attachment_id(),
					// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Insert column, not a meta query.
					'meta_key'   => $meta['meta_key'] ?? '',
					// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- Insert column, not a meta query.
					'meta_value' => $meta['meta_value'] ?? '',
				)
			);
		}
	}
}
