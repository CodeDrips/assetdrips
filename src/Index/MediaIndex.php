<?php
/**
 * Read/query and per-lane write API over the assetdrips_media table.
 *
 * @package AssetDrips
 */

declare( strict_types=1 );

namespace AssetDrips\Index;

use AssetDrips\Db\Schema;

defined( 'ABSPATH' ) || exit;

/**
 * The data-access seam for the media index.
 *
 * Writes obey the two-lane freshness contract (CON-two-lane-freshness): the
 * structural lane and the usage lane each update ONLY their own columns so
 * neither clobbers the other. Structural writes use INSERT ... ON DUPLICATE KEY
 * UPDATE (never wpdb::replace(), which is DELETE+INSERT and would reset the
 * usage lane); usage writes are a scoped UPDATE.
 *
 * The table name is sourced exclusively from {@see Schema::media_table()} — a
 * constant prefix, never user input (threat T-01-01) — and every value is bound
 * through wpdb::prepare().
 */
final class MediaIndex {

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
	 * Production callers always pass the real `\wpdb` instance via
	 * {@see self::from_wordpress()}.
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
	 * Upsert the STRUCTURAL lane for one attachment.
	 *
	 * Writes only the 16 structural columns produced by
	 * {@see MediaRow::from_attachment()}. The ON DUPLICATE KEY UPDATE clause
	 * refreshes those same columns and leaves usage_count, is_used,
	 * usage_synced_at, folder_id and content_hash untouched.
	 *
	 * @param array<string, int|string> $row Structural columns from MediaRow::from_attachment().
	 * @return void
	 */
	public function upsert_structural( array $row ): void {
		$table = Schema::media_table();

		$sql = "INSERT INTO {$table}
				(attachment_id, filename, title, alt, caption, description, mime, mime_subtype,
				width, height, orientation, filesize, has_alt, uploaded_by, uploaded_at, indexed_at)
			VALUES (%d, %s, %s, %s, %s, %s, %s, %s, %d, %d, %s, %d, %d, %d, %s, %s)
			ON DUPLICATE KEY UPDATE
				filename = VALUES(filename),
				title = VALUES(title),
				alt = VALUES(alt),
				caption = VALUES(caption),
				description = VALUES(description),
				mime = VALUES(mime),
				mime_subtype = VALUES(mime_subtype),
				width = VALUES(width),
				height = VALUES(height),
				orientation = VALUES(orientation),
				filesize = VALUES(filesize),
				has_alt = VALUES(has_alt),
				uploaded_by = VALUES(uploaded_by),
				uploaded_at = VALUES(uploaded_at),
				indexed_at = VALUES(indexed_at)";

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Single-row index upsert; the table name is a Schema constant (never input) and every value is bound via prepare().
		$this->wpdb->query(
			$this->wpdb->prepare(
				$sql,
				(int) $row['attachment_id'],
				(string) $row['filename'],
				(string) $row['title'],
				(string) $row['alt'],
				(string) $row['caption'],
				(string) $row['description'],
				(string) $row['mime'],
				(string) $row['mime_subtype'],
				(int) $row['width'],
				(int) $row['height'],
				(string) $row['orientation'],
				(int) $row['filesize'],
				(int) $row['has_alt'],
				(int) $row['uploaded_by'],
				(string) $row['uploaded_at'],
				(string) $row['indexed_at']
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * Update the USAGE lane for one attachment.
	 *
	 * Writes only usage_count, is_used and usage_synced_at; structural columns
	 * are never touched. usage_synced_at is stamped now so UI staleness stays
	 * honest.
	 *
	 * @param int  $attachment_id Attachment post ID.
	 * @param int  $usage_count   Number of references found by the scan.
	 * @param bool $is_used       Whether the attachment is used anywhere.
	 * @return void
	 */
	public function update_usage( int $attachment_id, int $usage_count, bool $is_used ): void {
		$table = Schema::media_table();

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Usage-lane update by attachment_id; table name is a Schema constant (never input) and all values are bound via prepare().
		$this->wpdb->query(
			$this->wpdb->prepare(
				"UPDATE {$table} SET usage_count = %d, is_used = %d, usage_synced_at = %s WHERE attachment_id = %d",
				$usage_count,
				$is_used ? 1 : 0,
				current_time( 'mysql' ),
				$attachment_id
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * Delete the index row for one attachment.
	 *
	 * @param int $attachment_id Attachment post ID.
	 * @return void
	 */
	public function delete( int $attachment_id ): void {
		$table = Schema::media_table();

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Single-row delete by attachment_id; table name is a Schema constant (never input) and the value is bound via prepare().
		$this->wpdb->query(
			$this->wpdb->prepare(
				"DELETE FROM {$table} WHERE attachment_id = %d",
				$attachment_id
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * Count index rows. Used by reconcile and tests.
	 *
	 * @return int
	 */
	public function count_rows(): int {
		$table = Schema::media_table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Row count over the index; table name is a Schema constant, no user input in the query.
		return (int) $this->wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );
	}

	/**
	 * Return aggregate health counts for the media index.
	 *
	 * Runs four separate single-row COUNT(*) reads — one per metric — off the
	 * assetdrips_media table. The table name is sourced exclusively from
	 * {@see Schema::media_table()} (threat T-01-01); predicates are literals with
	 * no user input, so no wpdb::prepare() call is needed.
	 *
	 * Return keys:
	 * - indexed (int)          — total rows in the index.
	 * - missing_alt (int)      — rows where has_alt = 0.
	 * - unused (int)           — rows where is_used = 0 AND the row has been usage-scanned
	 *                            (usage_synced_at IS NOT NULL). Scoping to scanned rows keeps
	 *                            the count honest in a partially-scanned library, where
	 *                            never-scanned rows still carry the is_used = 0 default (D-08).
	 * - is_usage_scanned (bool)— true when at least one row has usage_synced_at set;
	 *                            false means the usage lane has never been populated
	 *                            and the unused count should not be presented as
	 *                            authoritative (D-08 usage-lane honesty).
	 *
	 * @return array{indexed: int, missing_alt: int, unused: int, is_usage_scanned: bool}
	 */
	public function health_counts(): array {
		$table = Schema::media_table();

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Aggregate reads off the media index; table name is a Schema constant (never input); no user data in predicates.
		$indexed       = (int) $this->wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );
		$missing_alt   = (int) $this->wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE has_alt = 0" );
		$unused        = (int) $this->wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE is_used = 0 AND usage_synced_at IS NOT NULL" );
		$usage_scanned = (int) $this->wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE usage_synced_at IS NOT NULL" );
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		return array(
			'indexed'          => $indexed,
			'missing_alt'      => $missing_alt,
			'unused'           => $unused,
			'is_usage_scanned' => $usage_scanned > 0,
		);
	}

	/**
	 * List every indexed attachment_id, for reconcile set-difference.
	 *
	 * @return int[]
	 */
	public function indexed_ids(): array {
		$table = Schema::media_table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Reads all indexed IDs for reconciliation; table name is a Schema constant, no user input in the query.
		$ids = $this->wpdb->get_col( "SELECT attachment_id FROM {$table}" );

		return array_map( 'intval', (array) $ids );
	}

	/**
	 * Run a faceted query and return paged rows plus a separate total count.
	 *
	 * Security contracts (T-02-01, T-02-02):
	 * - All values are bound through wpdb::prepare(); table name only from Schema constants.
	 * - Enum whitelisting and esc_like() are applied inside {@see MediaQuery::build_where()}.
	 * - Total via a SEPARATE SELECT COUNT(*) with the same WHERE (deprecated aggregate hint avoided per MySQL 8.0.17).
	 *
	 * When {@see MediaQuery::$used_on} is > 0, the query JOINs against
	 * {@see Schema::usage_locations_table()} to filter by host post. The
	 * usage_locations table is created by plan 02-01 and populated by 02-03.
	 *
	 * @param MediaQuery $q Query criteria.
	 * @return array{rows: array<int, array<string, mixed>>, total: int, page: int, per_page: int}
	 */
	public function query( MediaQuery $q ): array {
		$table    = Schema::media_table();
		$order    = $q->order_by_clause();
		$per_page = max( 1, $q->per_page );
		$page     = max( 1, $q->page );
		$offset   = ( $page - 1 ) * $per_page;

		[ $full_where, $combined_args ] = $this->build_full_where( $q );

		// ---- SELECT rows -------------------------------------------------------
		$select_cols = implode(
			', ',
			array(
				'attachment_id',
				'filename',
				'title',
				'mime',
				'mime_subtype',
				'width',
				'height',
				'orientation',
				'filesize',
				'has_alt',
				'is_used',
				'usage_count',
				'usage_synced_at',
				'uploaded_by',
				'uploaded_at',
			)
		);

		$data_sql  = "SELECT {$select_cols} FROM {$table}{$full_where} ORDER BY {$order} LIMIT %d OFFSET %d";
		$count_sql = "SELECT COUNT(*) FROM {$table}{$full_where}";

		$data_args  = array_merge( $combined_args, array( $per_page, $offset ) );
		$count_args = $combined_args;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Faceted read; table names from Schema constants (never input); all values bound via prepare(). Two separate queries: data rows + COUNT(*) with the same WHERE.
		if ( ! empty( $data_args ) ) {
			$prepared_data = $this->wpdb->prepare( $data_sql, ...$data_args );
		} else {
			$prepared_data = $data_sql;
		}
		$rows = (array) $this->wpdb->get_results( $prepared_data, ARRAY_A );

		if ( ! empty( $count_args ) ) {
			$prepared_count = $this->wpdb->prepare( $count_sql, ...$count_args );
		} else {
			$prepared_count = $count_sql;
		}
		$total = (int) $this->wpdb->get_var( $prepared_count );
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		return array(
			'rows'     => $rows,
			'total'    => $total,
			'page'     => $page,
			'per_page' => $per_page,
		);
	}

	/**
	 * Build the full WHERE clause and argument list for a MediaQuery.
	 *
	 * Shared by both {@see self::query()} and {@see self::ids()} so the
	 * displayed count and the "select all matching" id set can never diverge
	 * (SEL-02 / D-03 / D-04).
	 *
	 * The arg-merge order MUST remain `array_merge( $where_args, $join_args,
	 * $tag_args )` to match the $where_parts predicate order — join_args before
	 * tag_args (07-RESEARCH §1 args-ordering pitfall).
	 *
	 * Security contracts (T-07-sqli): all values are bound through
	 * wpdb::prepare(); table names come exclusively from Schema constants and
	 * built-in $wpdb properties (never user input).
	 *
	 * @param MediaQuery $q Query criteria.
	 * @return array{0: string, 1: mixed[]} Two-element array: [WHERE SQL ('' when empty), args array].
	 */
	private function build_full_where( MediaQuery $q ): array {
		$table = Schema::media_table();

		[ $where_sql, $where_args ] = $q->build_where( $this->wpdb );

		// ---- Build the used_on predicate (optional) --------------------------
		$join_pred = '';
		$join_args = array();

		if ( $q->used_on > 0 ) {
			$loc_table = Schema::usage_locations_table();
			$join_pred = "{$table}.attachment_id IN (
				SELECT attachment_id FROM {$loc_table}
				WHERE host_type = %s AND host_id = %d
			)";
			$join_args = array( 'post', $q->used_on );
		}

		// ---- Build the tag predicate (optional) ------------------------------
		// Slots alongside used_on. Resolves term_id → term_taxonomy_id via INNER
		// JOIN to wp_term_taxonomy (multisite-safe: term_id != term_taxonomy_id
		// after WP 4.2 term-splitting). Built-in $wpdb properties always produce
		// the correct prefixed table names. AND tt.taxonomy = %s scopes to
		// assetdrips_tag only (prevents cross-taxonomy false positives, T-06-07).
		$tag_pred = '';
		$tag_args = array();

		if ( $q->tag > 0 ) {
			$tr_table = $this->wpdb->term_relationships; // Built-in wpdb property, always prefix-correct.
			$tt_table = $this->wpdb->term_taxonomy;      // Built-in wpdb property.
			$tag_pred = "{$table}.attachment_id IN (
				SELECT object_id FROM {$tr_table} tr
				INNER JOIN {$tt_table} tt ON tr.term_taxonomy_id = tt.term_taxonomy_id
				WHERE tt.term_id = %d AND tt.taxonomy = %s
			)";
			$tag_args = array( $q->tag, 'assetdrips_tag' );
		}

		// ---- Build the squeeze-state predicate (optional, D-05) ----------------
		// Subqueries against assetdrips_squeeze / assetdrips_squeeze_backups.
		// All bound values use %s / %d placeholders — $q->squeeze_state is NEVER
		// interpolated into SQL (T-14-04-01 defense-in-depth). Unknown values are
		// silently dropped via the default branch.
		$squeeze_pred = '';
		$squeeze_args = array();

		if ( '' !== $q->squeeze_state ) {
			$sq_table = Schema::squeeze_table();
			$bk_table = Schema::squeeze_backups_table();

			switch ( $q->squeeze_state ) {
				case 'not-optimized':
					// Has no complete row in assetdrips_squeeze.
					$squeeze_pred = "{$table}.attachment_id NOT IN (
						SELECT attachment_id FROM {$sq_table} WHERE status = %s
					)";
					$squeeze_args = array( 'complete' );
					break;

				case 'oversized':
					// is_oversized flag on assetdrips_media (direct column, indexed).
					$squeeze_pred = "{$table}.is_oversized = %d";
					$squeeze_args = array( 1 );
					break;

				case 'missing-webp':
					// Image rows without a WebP sibling (Pitfall 4: scoped to mime LIKE image/%).
					$squeeze_pred = "{$table}.has_webp = %d AND {$table}.mime LIKE %s";
					$squeeze_args = array( 0, 'image/%' );
					break;

				case 'has-backup':
					// Has at least one active backup row in assetdrips_squeeze_backups.
					$squeeze_pred = "{$table}.attachment_id IN (
						SELECT attachment_id FROM {$bk_table} WHERE status = %s
					)";
					$squeeze_args = array( 'active' );
					break;

				default:
					// Unknown value — silently dropped (T-14-04-01 defense-in-depth).
					break;
			}
		}

		// ---- Combine every predicate into a single WHERE clause --------------
		// Each fragment is a bare predicate; only this step decides whether a
		// WHERE keyword is emitted, so an empty facet set with a used_on filter
		// (or vice versa) can never produce `FROM {table} AND ...` (CR-01).
		$where_parts = array();
		if ( '' !== $where_sql ) {
			$where_parts[] = $where_sql;
		}
		if ( '' !== $join_pred ) {
			$where_parts[] = $join_pred;
		}
		if ( '' !== $tag_pred ) {
			$where_parts[] = $tag_pred;
		}
		if ( '' !== $squeeze_pred ) {
			$where_parts[] = $squeeze_pred;
		}
		$full_where = empty( $where_parts ) ? '' : ' WHERE ' . implode( ' AND ', $where_parts );
		// Args ordering: where_args → join_args → tag_args → squeeze_args (matches predicate order).
		$combined_args = array_merge( $where_args, $join_args, $tag_args, $squeeze_args );

		return array( $full_where, $combined_args );
	}

	/**
	 * Return every attachment_id matching a filter with NO LIMIT/OFFSET.
	 *
	 * Used by the bulk-operations handler (SEL-02 backend) to re-derive the
	 * full "select all matching" id set server-side, authoritatively, without
	 * trusting the client-supplied list (D-03 / D-04). Shares
	 * {@see self::build_full_where()} with {@see self::query()} so the
	 * displayed count and the returned id set can never diverge.
	 *
	 * Security contract (T-07-sqli): reuses the same bound WHERE assembly;
	 * no new interpolation surface; table name from Schema constant only.
	 *
	 * @param MediaQuery $q Query criteria (used_on, tag, column filters — no page/per_page).
	 * @return int[] Attachment IDs matching the filter, as integers.
	 */
	public function ids( MediaQuery $q ): array {
		$table                          = Schema::media_table();
		[ $full_where, $combined_args ] = $this->build_full_where( $q );
		$sql                            = "SELECT attachment_id FROM {$table}{$full_where}";

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Faceted read; table names from Schema constants (never input); all values bound via prepare().
		$ids = ! empty( $combined_args )
			? $this->wpdb->get_col( $this->wpdb->prepare( $sql, ...$combined_args ) )
			: $this->wpdb->get_col( $sql );
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		return array_map( 'intval', (array) $ids );
	}
}
