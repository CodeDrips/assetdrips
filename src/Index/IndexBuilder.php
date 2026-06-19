<?php
/**
 * Backfill driver, usage-lane writer and drift reconciliation for the media index.
 *
 * @package AssetDrips
 */

declare( strict_types=1 );

namespace AssetDrips\Index;

use AssetDrips\Admin\ScanProgress;
use AssetDrips\Scan\ScanService;
use AssetDrips\Usage\UsageLocations;
use AssetDrips\Usage\UsageMap;

defined( 'ABSPATH' ) || exit;

/**
 * Builds and reconciles the assetdrips_media index over an existing library.
 *
 * Three responsibilities, one per freshness concern:
 *
 *  - backfill(): walks EVERY attachment with its own resumable keyset cursor and
 *    upserts the structural lane. It deliberately does NOT reuse the
 *    AttachmentCatalogue batch iterator — that helper yields MatchKeys (none of
 *    the structural inputs MediaRow needs) and its bulk-meta reader silently
 *    drops attachments that have neither _wp_attached_file nor
 *    _wp_attachment_metadata, which would undercount the index (IDX-02). This
 *    walk imitates the keyset/bulk-meta TECHNIQUE but skips no attachment.
 *  - sync_usage(): fills the usage lane from a {@see UsageMap}, touching only the
 *    usage columns (IDX-04). Driven by the scan sweep and the usage-refresh cron.
 *  - reconcile(): cheap, idempotent drift insurance that
 *    always computes the attachment-vs-index set difference (the real drift
 *    check) so equal-magnitude drift cannot escape; both diffs are empty on a
 *    healthy index (IDX-06).
 *
 * The checkpoint option key ('assetdrips_index_checkpoint') is distinct from the
 * scan checkpoint so the two resumable jobs never collide. Every value reaches
 * the database through wpdb::prepare(); table names are constants, never input.
 */
final class IndexBuilder {

	/**
	 * Resumable backfill cursor option. Distinct from ScanService's checkpoint.
	 */
	public const CHECKPOINT_OPTION = 'assetdrips_index_checkpoint';

	/**
	 * Postmeta keys read per attachment for structural derivation.
	 */
	private const ATTACHED_FILE = '_wp_attached_file';
	private const METADATA      = '_wp_attachment_metadata';
	private const ALT           = '_wp_attachment_image_alt';

	/**
	 * Database handle.
	 *
	 * @var \wpdb
	 */
	private \wpdb $wpdb;

	/**
	 * Index data-access seam.
	 *
	 * @var MediaIndex
	 */
	private MediaIndex $index;

	/**
	 * Construct with explicit dependencies.
	 *
	 * @param \wpdb      $wpdb  Database handle.
	 * @param MediaIndex $index Index data-access seam.
	 */
	public function __construct( \wpdb $wpdb, MediaIndex $index ) {
		$this->wpdb  = $wpdb;
		$this->index = $index;
	}

	/**
	 * Construct from the live WordPress environment.
	 *
	 * @return self
	 */
	public static function from_wordpress(): self {
		global $wpdb;

		return new self( $wpdb, MediaIndex::from_wordpress() );
	}

	/**
	 * Backfill the structural lane for EVERY attachment.
	 *
	 * Walks attachments by keyset (ID greater than a cursor) so the job is
	 * memory-safe and resumable with no OFFSET drift. Each batch is upserted via
	 * the idempotent structural ODKU, so a re-run — including a resume — never
	 * creates duplicate rows. Usage columns are left at their DDL defaults
	 * (0/NULL) for the first scan to fill. The checkpoint is cleared on clean
	 * completion.
	 *
	 * @param int  $batch  Attachments per batch. Default 500.
	 * @param bool $resume Resume from the stored checkpoint instead of the start.
	 * @return int Number of attachments indexed in this run.
	 */
	public function backfill( int $batch = 500, bool $resume = false ): int {
		$batch = max( 1, $batch );

		$last_id = 0;
		if ( $resume ) {
			$checkpoint = get_option( self::CHECKPOINT_OPTION );
			$last_id    = is_array( $checkpoint ) ? (int) ( $checkpoint['last_id'] ?? 0 ) : 0;
		}

		$total   = $this->attachment_count();
		$done    = 0;
		$updated = 0.0;

		do {
			$ids = $this->next_ids( $last_id, $batch );

			foreach ( $ids as $id ) {
				$this->index->upsert_structural( $this->derive_row( $id ) );
			}

			$fetched = count( $ids );
			if ( 0 === $fetched ) {
				break;
			}

			$last_id = (int) end( $ids );
			$done   += $fetched;

			update_option(
				self::CHECKPOINT_OPTION,
				array( 'last_id' => $last_id ),
				false
			);

			$updated = $this->report_progress( $done, $total, $updated );
		} while ( $fetched === $batch );

		delete_option( self::CHECKPOINT_OPTION );

		return $done;
	}

	/**
	 * Fill the USAGE lane from a usage map. Usage columns only (IDX-04).
	 *
	 * Every currently indexed attachment is refreshed: used ones take their hit
	 * count from the map, the rest are reset to zero/unused. The map is the
	 * authority for "as of the last scan". Structural columns are never touched.
	 *
	 * @param UsageMap $usage Usage evidence from a scan.
	 * @return int Number of attachments whose usage lane was written.
	 */
	public function sync_usage( UsageMap $usage ): int {
		$written = 0;

		foreach ( $this->index->indexed_ids() as $id ) {
			$this->index->update_usage( $id, $usage->count_for( $id ), $usage->is_used( $id ) );
			++$written;
		}

		return $written;
	}

	/**
	 * Refresh the usage lane for every indexed attachment. Cron entry point.
	 *
	 * Gathers usage evidence with a headless scan ({@see ScanService}) — there is
	 * no pre-existing scheduled scan (RESEARCH Open Q1) — then writes only the
	 * usage columns via {@see self::sync_usage()}. Registered as the named
	 * 'assetdrips_usage_refresh' cron callback in Plan 03 so scheduling and
	 * clearing on deactivation are unambiguous (a closure could not be cleared).
	 *
	 * @return void
	 */
	public static function run_usage_refresh(): void {
		$usage = ScanService::from_wordpress()->gather_usage();

		self::from_wordpress()->sync_usage( $usage );

		// Populate the usage-locations lookup table from the same in-memory
		// UsageMap — no extra scan, no scanner change (D-09 additive).
		UsageLocations::from_wordpress()->populate_from_usage( $usage );
	}

	/**
	 * Reconcile index drift against the live library. Cheap and idempotent.
	 *
	 * Always computes the attachment-vs-index set difference — the only signal
	 * that actually detects drift. COUNT(*) equality is deliberately NOT used as
	 * a gate because it is blind to equal-magnitude drift: one import (a row
	 * missing from the index) plus one deletion (an orphan row) between cron runs
	 * nets to an equal count but a different ID set, and a count-equality guard
	 * would let that escape unrepaired (WR-01). Attachment IDs missing from the
	 * index are backfilled (including metadata-less ones); index rows whose
	 * attachment no longer exists are deleted. On a healthy index both array_diff
	 * passes are empty arrays so the method is a near-free, idempotent no-op
	 * (IDX-06).
	 *
	 * Registered as the 'assetdrips_index_reconcile' cron callback in Plan 03.
	 *
	 * @return void
	 */
	public static function reconcile(): void {
		self::from_wordpress()->run_reconcile();
	}

	/**
	 * Instance reconciliation, so the static cron entry point stays thin.
	 *
	 * Unconditionally computes the set difference between live attachments and
	 * indexed rows. Both array_diff passes are empty and near-free on a healthy
	 * index, so the method stays cheap and idempotent while still catching
	 * equal-magnitude drift that a COUNT(*) short-circuit would hide (WR-01).
	 *
	 * @return void
	 */
	public function run_reconcile(): void {
		$attachment_ids = $this->all_attachment_ids();
		$indexed_ids    = $this->index->indexed_ids();

		$missing = array_diff( $attachment_ids, $indexed_ids );
		foreach ( $missing as $id ) {
			$this->index->upsert_structural( $this->derive_row( (int) $id ) );
		}

		$orphans = array_diff( $indexed_ids, $attachment_ids );
		foreach ( $orphans as $id ) {
			$this->index->delete( (int) $id );
		}
	}

	/**
	 * Derive the structural row for one attachment, resolving impure inputs.
	 *
	 * Fetches the post row and the meta MediaRow needs, resolves the filesize
	 * fallback (Pitfall 1), then delegates to the pure {@see MediaRow}. A
	 * metadata-less attachment still yields a row (empty filename/alt, 0 dims) —
	 * it is never skipped.
	 *
	 * @param int $id Attachment post ID.
	 * @return array<string, int|string> Structural columns.
	 */
	private function derive_row( int $id ): array {
		$post = get_post( $id );

		$title       = ( $post instanceof \WP_Post ) ? (string) $post->post_title : '';
		$caption     = ( $post instanceof \WP_Post ) ? (string) $post->post_excerpt : '';
		$description = ( $post instanceof \WP_Post ) ? (string) $post->post_content : '';
		$mime        = ( $post instanceof \WP_Post ) ? (string) $post->post_mime_type : '';
		$uploaded_by = ( $post instanceof \WP_Post ) ? (int) $post->post_author : 0;
		$uploaded_at = ( $post instanceof \WP_Post && '' !== (string) $post->post_date )
			? (string) $post->post_date
			: current_time( 'mysql' );

		$attached = get_post_meta( $id, self::ATTACHED_FILE, true );
		$attached = is_string( $attached ) ? $attached : '';
		$filename = '' !== $attached ? basename( $attached ) : '';

		$alt = get_post_meta( $id, self::ALT, true );
		$alt = is_string( $alt ) ? $alt : '';

		$meta = get_post_meta( $id, self::METADATA, true );
		$meta = is_array( $meta ) ? $meta : array();

		$filesize = $this->resolve_filesize( $id, $meta );

		return MediaRow::from_attachment(
			$id,
			$filename,
			$title,
			$alt,
			$caption,
			$description,
			$mime,
			$meta,
			$filesize,
			$uploaded_by,
			$uploaded_at,
			current_time( 'mysql' )
		);
	}

	/**
	 * Resolve the file size, preferring metadata and falling back to the file.
	 *
	 * WordPress only reliably carries 'filesize' in attachment metadata from WP
	 * 6.0 and not for every path (Pitfall 1), so fall back to the original file
	 * on disk when the meta value is absent or zero.
	 *
	 * @param int                  $id   Attachment post ID.
	 * @param array<string, mixed> $meta Unserialised attachment metadata.
	 * @return int File size in bytes (0 when unknown).
	 */
	private function resolve_filesize( int $id, array $meta ): int {
		if ( isset( $meta['filesize'] ) && (int) $meta['filesize'] > 0 ) {
			return (int) $meta['filesize'];
		}

		$path = get_attached_file( $id );
		if ( is_string( $path ) && '' !== $path && file_exists( $path ) ) {
			return (int) filesize( $path );
		}

		return 0;
	}

	/**
	 * Fetch the next keyset batch of attachment IDs after a cursor.
	 *
	 * @param int $last_id Cursor; rows with a greater ID are returned.
	 * @param int $batch   Maximum rows to return.
	 * @return int[] Ascending attachment IDs.
	 */
	private function next_ids( int $last_id, int $batch ): array {
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Keyset walk over the full library; {$wpdb->posts} is a core table name and the cursor/limit are bound via prepare().
		$ids = $this->wpdb->get_col(
			$this->wpdb->prepare(
				"SELECT ID FROM {$this->wpdb->posts} WHERE post_type = 'attachment' AND ID > %d ORDER BY ID ASC LIMIT %d",
				$last_id,
				$batch
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		return array_map( 'intval', (array) $ids );
	}

	/**
	 * All attachment IDs in the library, for reconciliation set-difference.
	 *
	 * @return int[]
	 */
	private function all_attachment_ids(): array {
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Reconciliation needs every attachment ID; {$wpdb->posts} is a core table name with no user input.
		$ids = $this->wpdb->get_col(
			"SELECT ID FROM {$this->wpdb->posts} WHERE post_type = 'attachment'"
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		return array_map( 'intval', (array) $ids );
	}

	/**
	 * Count attachments in the library.
	 *
	 * @return int
	 */
	private function attachment_count(): int {
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Cheap COUNT for progress and reconcile; {$wpdb->posts} is a core table name with no user input.
		$count = (int) $this->wpdb->get_var(
			"SELECT COUNT(*) FROM {$this->wpdb->posts} WHERE post_type = 'attachment'"
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		return $count;
	}

	/**
	 * Emit a throttled progress update, mirroring ReviewScreen's 0.4s throttle.
	 *
	 * @param int   $done       Attachments indexed so far.
	 * @param int   $total      Total attachments.
	 * @param float $updated_at Last write time (microtime) for throttling.
	 * @return float The (possibly unchanged) last write time to carry forward.
	 */
	private function report_progress( int $done, int $total, float $updated_at ): float {
		$now = microtime( true );

		// Throttle to ~0.4s, but always emit the final tick.
		if ( $now - $updated_at < 0.4 && $done < $total ) {
			return $updated_at;
		}

		ScanProgress::set(
			array(
				'phase'   => 'index',
				'label'   => 'Indexing attachments',
				'done'    => $done,
				'total'   => $total,
				'percent' => $total > 0 ? (int) floor( $done / $total * 100 ) : 0,
			)
		);

		return $now;
	}
}
