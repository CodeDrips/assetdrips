<?php
/**
 * Read/write API for the squeeze optimization table and media flag columns.
 *
 * @package AssetDrips
 */

declare( strict_types=1 );

namespace AssetDrips\Squeeze;

use AssetDrips\Db\Schema;

defined( 'ABSPATH' ) || exit;

/**
 * Data-access seam for the assetdrips_squeeze table and the has_webp /
 * has_avif / is_oversized flag columns on assetdrips_media.
 *
 * Mirrors the MediaIndex pattern: final class, private $wpdb, constructor
 * injection for testability, from_wordpress() factory for production callers.
 *
 * Phase-8 minimal contract surface only (RESEARCH Open Question 1):
 * - upsert()                  — INSERT … ON DUPLICATE KEY UPDATE for a squeeze row
 * - update_status()           — scoped STATUS UPDATE
 * - get()                     — single-row SELECT → OptimizationRecord or null
 * - get_flags()               — SELECT has_webp/has_avif/is_oversized from assetdrips_media
 * - update_media_index_flags() — UPDATE the three flag columns on assetdrips_media
 *
 * Deferred to Phase 14 (Dashboard): query_dashboard(), ids_by_status().
 * Do NOT add those methods here.
 *
 * Security contract (T-08-05): all SQL routes through $wpdb->prepare(); table
 * names come exclusively from Schema accessors (constants, never user input).
 */
final class OptimizationIndex {

	/**
	 * Database handle.
	 *
	 * @var object
	 */
	private object $wpdb;

	/**
	 * Construct with an explicit database handle.
	 *
	 * Accepts `object` rather than the concrete `\wpdb` type so unit tests can
	 * inject an anonymous stub without loading the full WordPress test suite.
	 * Production callers always pass the real `\wpdb` via from_wordpress().
	 *
	 * @param object $wpdb Database handle (production: \wpdb; tests: anonymous stub).
	 */
	public function __construct( object $wpdb ) {
		$this->wpdb = $wpdb;
	}

	/**
	 * Construct from the live WordPress environment.
	 *
	 * @return self
	 */
	public static function from_wordpress(): self {
		global $wpdb;

		return new self( $wpdb );
	}

	/**
	 * Upsert a squeeze row for the given attachment.
	 *
	 * Uses INSERT … ON DUPLICATE KEY UPDATE so the call is idempotent:
	 * inserting a new row on first optimization and refreshing on re-runs.
	 *
	 * ops_completed and sizes_audit default to '[]' / '{}' when absent from
	 * $row — MySQL < 8.0 cannot use expression DEFAULTs so the PHP layer sets
	 * safe empty-JSON defaults before INSERT (RESEARCH Pattern 2 note).
	 *
	 * @param array<string, mixed> $row Squeeze row fields (attachment_id required).
	 * @return void
	 */
	public function upsert( array $row ): void {
		$table = Schema::squeeze_table();

		$attachment_id   = (int) ( $row['attachment_id'] ?? 0 );
		$status          = (string) ( $row['status'] ?? OptimizationRecord::PENDING );
		$original_bytes  = (int) ( $row['original_bytes'] ?? 0 );
		$optimized_bytes = (int) ( $row['optimized_bytes'] ?? 0 );
		$webp_bytes      = (int) ( $row['webp_bytes'] ?? 0 );
		$avif_bytes      = (int) ( $row['avif_bytes'] ?? 0 );
		// MySQL < 8.0 has no expression DEFAULT — set safe empty JSON in PHP.
		$ops_completed = (string) ( $row['ops_completed'] ?? '[]' );
		$sizes_audit   = (string) ( $row['sizes_audit'] ?? '{}' );
		// error_message is intentionally nullable: use a SQL-level NULL rather than
		// binding null into a %s placeholder (which wpdb->prepare() coerces to '').
		// The column is declared `text DEFAULT NULL`, so NULL means "no error".
		$error_message = isset( $row['error_message'] ) ? (string) $row['error_message'] : null;
		// Normalise empty string to null so the nullable contract is maintained
		// consistently regardless of call-site convention.
		if ( '' === $error_message ) {
			$error_message = null;
		}

		// Build the error_message fragment: NULL literal when absent, %s when present.
		// This writes a true SQL NULL for "no error" instead of an empty string.
		if ( null === $error_message ) {
			$error_msg_placeholder = 'NULL';
			$prepare_args          = array( $attachment_id, $status, $original_bytes, $optimized_bytes, $webp_bytes, $avif_bytes, $ops_completed, $sizes_audit );
		} else {
			$error_msg_placeholder = '%s';
			$prepare_args          = array( $attachment_id, $status, $original_bytes, $optimized_bytes, $webp_bytes, $avif_bytes, $ops_completed, $sizes_audit, $error_message );
		}

		$sql = "INSERT INTO {$table}
				(attachment_id, status, original_bytes, optimized_bytes,
				 webp_bytes, avif_bytes, ops_completed, sizes_audit, error_message)
			VALUES (%d, %s, %d, %d, %d, %d, %s, %s, {$error_msg_placeholder})
			ON DUPLICATE KEY UPDATE
				status          = VALUES(status),
				original_bytes  = VALUES(original_bytes),
				optimized_bytes = VALUES(optimized_bytes),
				webp_bytes      = VALUES(webp_bytes),
				avif_bytes      = VALUES(avif_bytes),
				ops_completed   = VALUES(ops_completed),
				sizes_audit     = VALUES(sizes_audit),
				error_message   = VALUES(error_message)";

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Squeeze upsert; table name is a Schema constant (never input), error_message NULL branch uses a literal NULL (no injection surface), and all other values are bound via prepare().
		$this->wpdb->query(
			$this->wpdb->prepare( $sql, $prepare_args ) // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- $prepare_args matches placeholders exactly; NULL branch reduces arg count by one to match the NULL literal.
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * Update the status column for a squeeze row.
	 *
	 * @param int    $attachment_id Attachment post ID.
	 * @param string $status        New status (use OptimizationRecord status constants).
	 * @return void
	 */
	public function update_status( int $attachment_id, string $status ): void {
		$table = Schema::squeeze_table();

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Scoped status update; table name is a Schema constant, values are bound via prepare().
		$this->wpdb->query(
			$this->wpdb->prepare(
				"UPDATE {$table} SET status = %s WHERE attachment_id = %d",
				$status,
				$attachment_id
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * Fetch a single squeeze row by attachment ID.
	 *
	 * @param int $attachment_id Attachment post ID.
	 * @return OptimizationRecord|null The record, or null if no row exists.
	 */
	public function get( int $attachment_id ): ?OptimizationRecord {
		$table = Schema::squeeze_table();

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Single-row select by attachment_id; table name is a Schema constant, value is bound via prepare().
		$row = $this->wpdb->get_row(
			$this->wpdb->prepare(
				"SELECT * FROM {$table} WHERE attachment_id = %d LIMIT 1",
				$attachment_id
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		if ( ! is_array( $row ) ) {
			return null;
		}

		// Normalise error_message: map both absent and empty-string to null so the
		// OptimizationRecord ?string contract holds (NULL means "no error", not '').
		$err = isset( $row['error_message'] ) ? (string) $row['error_message'] : null;
		$err = ( '' === $err ) ? null : $err;

		return new OptimizationRecord(
			(int) $row['id'],
			(int) $row['attachment_id'],
			(string) $row['status'],
			(int) $row['original_bytes'],
			(int) $row['optimized_bytes'],
			(int) $row['webp_bytes'],
			(int) $row['avif_bytes'],
			(string) ( $row['ops_completed'] ?? '[]' ),
			(string) ( $row['sizes_audit'] ?? '{}' ),
			isset( $row['last_optimized_at'] ) ? (string) $row['last_optimized_at'] : null,
			$err
		);
	}

	/**
	 * Read the three flag columns on assetdrips_media for a given attachment.
	 *
	 * Returns safe defaults (all false) when no row exists, matching the
	 * additive-no-harm principle: missing row = no flags set.
	 *
	 * Used by generate_webp() / generate_avif() to preserve is_oversized before
	 * calling update_media_index_flags() (D-08 / Phase 10), and by the serving
	 * layer (Phase 12).
	 *
	 * @param int $attachment_id Attachment post ID.
	 * @return array{has_webp: bool, has_avif: bool, is_oversized: bool}
	 */
	public function get_flags( int $attachment_id ): array {
		$table = Schema::media_table();
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Flag read; table name is a Schema constant (never input), value is bound via prepare().
		$row = $this->wpdb->get_row(
			$this->wpdb->prepare(
				"SELECT has_webp, has_avif, is_oversized FROM {$table} WHERE attachment_id = %d LIMIT 1",
				$attachment_id
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( ! is_array( $row ) ) {
			return array(
				'has_webp'     => false,
				'has_avif'     => false,
				'is_oversized' => false,
			);
		}
		return array(
			'has_webp'     => (bool) $row['has_webp'],
			'has_avif'     => (bool) $row['has_avif'],
			'is_oversized' => (bool) $row['is_oversized'],
		);
	}

	/**
	 * Update the has_webp / has_avif / is_oversized flag columns on assetdrips_media.
	 *
	 * These serving-layer fast-lookup columns (D-03) are written here after each
	 * Squeeze operation by Phase 9/10. They are created in the schema by Plan 01
	 * and read by the serving layer in Phase 12.
	 *
	 * WR-01: written as an idempotent INSERT … ON DUPLICATE KEY UPDATE (mirroring
	 * upsert()) so the flag write is never silently lost when no assetdrips_media
	 * row exists yet for the attachment (e.g. an attachment not yet indexed, or whose
	 * row was reconciled away). A bare UPDATE would affect 0 rows and discard the
	 * has_webp / has_avif result, leaving get_flags() returning all-false even though
	 * the sibling files exist on disk.
	 *
	 * The INSERT branch supplies the two NOT-NULL-without-DEFAULT datetime columns
	 * (uploaded_at, indexed_at) so a row created here is schema-valid under strict SQL
	 * mode; every other column carries a schema DEFAULT. A row materialised this way is
	 * a minimal flag-only stub that MediaIndex::upsert_structural() later fills in — the
	 * additive flag write must not be lost in the meantime.
	 *
	 * @param int  $attachment_id Attachment post ID.
	 * @param bool $has_webp      Whether a WebP alternate exists.
	 * @param bool $has_avif      Whether an AVIF alternate exists.
	 * @param bool $is_oversized  Whether the original exceeded the max dimension cap.
	 * @return void
	 */
	public function update_media_index_flags(
		int $attachment_id,
		bool $has_webp,
		bool $has_avif,
		bool $is_oversized
	): void {
		$table = Schema::media_table();
		$now   = current_time( 'mysql' );

		$sql = "INSERT INTO {$table} (attachment_id, uploaded_at, indexed_at, has_webp, has_avif, is_oversized)
			VALUES (%d, %s, %s, %d, %d, %d)
			ON DUPLICATE KEY UPDATE
				has_webp     = VALUES(has_webp),
				has_avif     = VALUES(has_avif),
				is_oversized = VALUES(is_oversized)";

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Flag-column upsert; table name is a Schema constant (never input) and all values are bound via prepare().
		$this->wpdb->query(
			$this->wpdb->prepare(
				$sql,
				$attachment_id,
				$now,
				$now,
				$has_webp ? 1 : 0,
				$has_avif ? 1 : 0,
				$is_oversized ? 1 : 0
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * Return aggregate sibling coverage counts for the status indicator (D-07, Phase 12).
	 *
	 * Single pass over assetdrips_media — returns total row count plus WebP and AVIF sums.
	 * Table scan is acceptable: this method is called only on the admin settings page render
	 * inside is_admin(), never on the hot front-end path. No caching needed (D-07).
	 *
	 * The table name comes from Schema::media_table() (a constant accessor, never user input),
	 * so no prepare() binding is required for the table reference (T-08-05 satisfied).
	 *
	 * @return array{total: int, webp_count: int, avif_count: int}
	 */
	public function get_coverage_counts(): array {
		$table = Schema::media_table();

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Aggregate query; table name is a Schema constant (never user input); no user-supplied values are bound.
		$row = $this->wpdb->get_row(
			"SELECT COUNT(*) AS total, SUM(has_webp) AS webp_count, SUM(has_avif) AS avif_count FROM {$table}",
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		return array(
			'total'      => (int) ( $row['total'] ?? 0 ),
			'webp_count' => (int) ( $row['webp_count'] ?? 0 ),
			'avif_count' => (int) ( $row['avif_count'] ?? 0 ),
		);
	}

	/**
	 * Upsert the sizes_audit column for a squeeze row.
	 *
	 * Inserts a minimal PENDING stub row (status=PENDING, ops_completed='[]') with
	 * the supplied JSON when no row exists for the attachment yet, and on duplicate
	 * key updates ONLY the sizes_audit column — never touching status, bytes, or
	 * ops_completed of an already-optimised row. This is the scoped writer for the
	 * audit scan (D-06 column reuse / SIZE-01 storage guarantee).
	 *
	 * Security contract (T-13-03): attachment_id bound as %d, JSON string bound as
	 * %s. The JSON is produced internally by SizesAuditJob (json_encode on a PHP
	 * array) — never user input.
	 *
	 * @param int    $attachment_id    Attachment post ID.
	 * @param string $sizes_audit_json JSON-encoded sizes_audit blob.
	 * @return void
	 */
	public function update_sizes_audit( int $attachment_id, string $sizes_audit_json ): void {
		$table = Schema::squeeze_table();

		$sql = "INSERT INTO {$table} (attachment_id, status, ops_completed, sizes_audit)
				VALUES (%d, %s, %s, %s)
				ON DUPLICATE KEY UPDATE sizes_audit = VALUES(sizes_audit)";

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Scoped sizes_audit upsert; table name is a Schema constant (never input); all values bound via prepare(); only sizes_audit column updated on duplicate key to preserve existing optimisation state.
		$this->wpdb->query(
			$this->wpdb->prepare(
				$sql,
				$attachment_id,
				OptimizationRecord::PENDING,
				'[]',
				$sizes_audit_json
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * Return aggregate sizes-audit counts for the Squeeze admin screen (SIZE-01).
	 *
	 * Runs a single SELECT over assetdrips_squeeze.sizes_audit (filtering out the
	 * default '{}' unaudited rows), then decodes each JSON string in PHP to count:
	 *   - audited_count:  rows that were genuinely scanned (have a scanned_at key)
	 *   - missing_count:  attachments with at least one missing registered size
	 *   - orphaned_count: attachments with at least one orphaned file on disk
	 *
	 * PHP-side aggregation is used because MySQL < 5.7.8 has no JSON_LENGTH(), and
	 * the column is longtext (no JSON indexing). This method is called only at admin
	 * render time inside is_admin() — a full table scan on sparse audited rows is
	 * acceptable (SIZE-01 / D-02 "single efficient pass, not per-row postmeta").
	 *
	 * This method MUST NOT call wp_get_attachment_metadata() or get_post_meta().
	 * It reads exclusively from assetdrips_squeeze (T-13-04 / D-02).
	 *
	 * @return array{audited_count: int, missing_count: int, orphaned_count: int}
	 */
	public function get_sizes_audit_summary(): array {
		$table = Schema::squeeze_table();

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Aggregate read; runs only at admin render time; table name is a Schema constant (never user input); no user-supplied values bound.
		$rows = $this->wpdb->get_col(
			"SELECT sizes_audit FROM {$table} WHERE sizes_audit != '{}' AND sizes_audit IS NOT NULL"
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		$audited_count  = 0;
		$missing_count  = 0;
		$orphaned_count = 0;

		foreach ( (array) $rows as $json ) {
			$data = json_decode( (string) $json, true );
			// Skip non-arrays and rows without scanned_at (not-yet-audited default '{}' slipping through).
			if ( ! is_array( $data ) || ! isset( $data['scanned_at'] ) ) {
				continue;
			}
			++$audited_count;
			if ( ! empty( $data['missing'] ) ) {
				++$missing_count;
			}
			if ( ! empty( $data['orphaned'] ) ) {
				++$orphaned_count;
			}
		}

		return array(
			'audited_count'  => $audited_count,
			'missing_count'  => $missing_count,
			'orphaned_count' => $orphaned_count,
		);
	}

	/**
	 * Return scalar top-line stats for the Squeeze dashboard band (DASH-01, D-02, D-09).
	 *
	 * Returns:
	 *   optimized     — COUNT of status='complete' rows in assetdrips_squeeze.
	 *   total         — COUNT of all rows in assetdrips_media.
	 *   bytes_saved   — SUM of per-row reclaimed bytes over complete rows, using
	 *                   GREATEST(0, CAST(original_bytes AS SIGNED) - CAST(optimized_bytes AS SIGNED))
	 *                   to prevent unsigned-bigint wrap (Pitfall 1). webp_bytes and avif_bytes
	 *                   are NEVER included — they are additive sibling bytes, not reclaimed disk (D-09).
	 *   pct_reduction — bytes_saved / SUM(original_bytes) * 100 rounded to 1 dp; 0.0 when no
	 *                   complete rows exist (zero-division guard).
	 *   oversized     — COUNT of rows with is_oversized = 1 in assetdrips_media.
	 *   missing_webp  — COUNT of image/* rows with has_webp = 0 in assetdrips_media (Pitfall 4).
	 *
	 * All table names come from Schema::squeeze_table() / Schema::media_table() — never user input.
	 * Runs only at admin render time inside is_admin(); no caching needed.
	 *
	 * @return array{optimized: int, total: int, bytes_saved: int, pct_reduction: float, oversized: int, missing_webp: int}
	 */
	public function query_dashboard(): array {
		$sq    = Schema::squeeze_table();
		$media = Schema::media_table();

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Aggregate reads; table names are Schema constants (never user input); all bound values use prepare() with %s/%d placeholders.

		// 1. Optimized count (rows with status='complete').
		$optimized = (int) $this->wpdb->get_var(
			$this->wpdb->prepare( "SELECT COUNT(*) FROM {$sq} WHERE status = %s", 'complete' )
		);

		// 2. Total indexed media rows.
		$total = (int) $this->wpdb->get_var( "SELECT COUNT(*) FROM {$media}" );

		// 3. Honest disk saved (recompress + resize reclaimed bytes only — D-09).
		// CAST AS SIGNED prevents unsigned-bigint wrap when optimized_bytes > original_bytes
		// (Pitfall 1). GREATEST clamps negative differences to 0.
		// webp_bytes and avif_bytes are NEVER referenced here — they are additive (D-09).
		$row            = $this->wpdb->get_row(
			$this->wpdb->prepare(
				"SELECT SUM(GREATEST(0, CAST(original_bytes AS SIGNED) - CAST(optimized_bytes AS SIGNED))) AS bytes_saved,
				        SUM(original_bytes) AS original_total
				 FROM {$sq} WHERE status = %s",
				'complete'
			),
			ARRAY_A
		);
		$bytes_saved    = (int) ( $row['bytes_saved'] ?? 0 );
		$original_total = (int) ( $row['original_total'] ?? 0 );
		$pct_reduction  = $original_total > 0 ? round( $bytes_saved / $original_total * 100, 1 ) : 0.0;

		// 4. Oversized count (is_oversized flag column — indexed).
		$oversized = (int) $this->wpdb->get_var(
			$this->wpdb->prepare( "SELECT COUNT(*) FROM {$media} WHERE is_oversized = %d", 1 )
		);

		// 5. Missing-WebP count — scoped to image/* rows only (Pitfall 4).
		// Counting has_webp = 0 on video/audio/PDF rows is meaningless.
		$missing_webp = (int) $this->wpdb->get_var(
			$this->wpdb->prepare( "SELECT COUNT(*) FROM {$media} WHERE mime LIKE %s AND has_webp = %d", 'image/%', 0 )
		);

		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		return compact( 'optimized', 'total', 'bytes_saved', 'pct_reduction', 'oversized', 'missing_webp' );
	}

	/**
	 * Return the top-N unoptimized image attachments ordered by filesize DESC (DASH-02, D-03).
	 *
	 * "Unoptimized" means no status='complete' row exists in assetdrips_squeeze for the
	 * attachment. Only image/* mime rows are considered (PDFs, videos, audio files are excluded
	 * — they are not WebP/AVIF candidates).
	 *
	 * Uses a LEFT JOIN / IS NULL pattern (preferred over NOT EXISTS for consistency with the
	 * tag/used_on subquery pattern in build_full_where()). The filesize column is indexed,
	 * so ORDER BY filesize DESC with LIMIT N is efficient.
	 *
	 * All values bound through $wpdb->prepare() with %s / %d placeholders. Table names come
	 * from Schema::squeeze_table() / Schema::media_table() only — never user input (T-14-02-01).
	 *
	 * @param int $limit Maximum number of rows to return. Defaults to 10.
	 * @return array<int, array{attachment_id: int, filename: string, filesize: int}>
	 */
	public function get_biggest_unoptimized( int $limit = 10 ): array {
		$sq    = Schema::squeeze_table();
		$media = Schema::media_table();

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Top-N aggregate read; table names are Schema constants (never user input); 'complete', 'image/%', and $limit are all bound via prepare().
		$rows = $this->wpdb->get_results(
			$this->wpdb->prepare(
				"SELECT m.attachment_id, m.filename, m.filesize
				 FROM {$media} m
				 LEFT JOIN {$sq} sq
				     ON m.attachment_id = sq.attachment_id AND sq.status = %s
				 WHERE sq.attachment_id IS NULL
				   AND m.mime LIKE %s
				 ORDER BY m.filesize DESC
				 LIMIT %d",
				'complete',
				'image/%',
				$limit
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Write the content_hash column on assetdrips_media.
	 *
	 * Called explicitly from SqueezeEngine post-op, outside IndexHooks scope (D-06).
	 * IndexHooks is explicitly prohibited from writing this column (IndexHooks.php:24).
	 * MediaIndex::upsert_structural() also deliberately omits content_hash (MediaIndex.php:74-96).
	 *
	 * @param int    $attachment_id Attachment post ID.
	 * @param string $hash          SHA-1 hex string (40 chars) of the post-encode file.
	 * @return void
	 */
	public function update_content_hash( int $attachment_id, string $hash ): void {
		$table = Schema::media_table();

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Scoped content_hash update; table name is a Schema constant (never input) and all values are bound via prepare().
		$this->wpdb->query(
			$this->wpdb->prepare(
				"UPDATE {$table} SET content_hash = %s WHERE attachment_id = %d",
				$hash,
				$attachment_id
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}
}
