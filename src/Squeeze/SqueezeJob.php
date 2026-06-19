<?php
/**
 * Library-wide resumable Squeeze batch and single-attachment async entry point.
 *
 * @package AssetDrips
 */

declare( strict_types=1 );

namespace AssetDrips\Squeeze;

use AssetDrips\Admin\ScanProgress;

defined( 'ABSPATH' ) || exit;

/**
 * Orchestrates the library-wide resumable Squeeze batch (TRG-01) and the
 * single-attachment async cron entry point.
 *
 * Structural analog to IndexBuilder: keyset cursor over wp_posts attachments,
 * CHECKPOINT_OPTION per chunk, ScanProgress throttled progress, static cron
 * callbacks that are thin wrappers over instance methods.
 *
 * THIN GLUE — this class MUST NOT call BackupManager::backup() or
 * OptimizationIndex::upsert()/update_media_index_flags() directly.
 * Those bookkeeping calls live inside SqueezeEngine::recompress(),
 * generate_webp(), generate_avif(), and resize_original() respectively
 * (verified: recompress() lines 185+261, resize_original() lines 341+402,
 * generate_webp() lines 579+582, generate_avif() lines 784–795).
 *
 * Threat mitigations:
 *  - T-11-eop: run_single() validates $id > 0 and defers to get_attached_file()
 *    as the attachment-existence authority (returns early on no-file).
 *  - T-11-disk: backfill() gates on preflight_disk() before any encode;
 *    BackupManager::estimate_batch_space() already applies a 20% buffer (D-12).
 *  - T-11-dos: run_single() never re-schedules cron events; scheduling belongs
 *    exclusively in SqueezeHooks (Pitfall 6 — avoids cron queue explosion).
 */
final class SqueezeJob {

	/**
	 * Resumable backfill cursor option — distinct from 'assetdrips_index_checkpoint'.
	 */
	public const CHECKPOINT_OPTION = 'assetdrips_squeeze_checkpoint';

	/**
	 * Option key used to store the final batch savings summary for CLI/admin to read.
	 */
	private const SAVINGS_OPTION = 'assetdrips_squeeze_last_batch_savings';

	/**
	 * Option key used to store the per-batch history log (newest-first, capped).
	 */
	private const HISTORY_OPTION = 'assetdrips_squeeze_history';

	/**
	 * Maximum number of history entries retained in HISTORY_OPTION.
	 */
	private const HISTORY_CAP = 20;

	/**
	 * Database handle.
	 *
	 * Typed as `object` (not `\wpdb`) so unit tests can inject an anonymous stub
	 * without loading the full WordPress test suite — same approach as SqueezeEngine,
	 * BackupManager, and OptimizationIndex (verified from their constructors).
	 *
	 * @var object
	 */
	private object $wpdb;

	/**
	 * Squeeze encoding engine.
	 *
	 * @var object
	 */
	private object $engine;

	/**
	 * Backup manager — used only for disk pre-flight (estimate_batch_space).
	 *
	 * @var object
	 */
	private object $backup;

	/**
	 * Optimization index — used only for update_status() on per-item failure.
	 *
	 * @var object
	 */
	private object $index;

	/**
	 * Construct with explicit dependencies.
	 *
	 * Accepts `object` for all service params so unit tests can inject anonymous
	 * stubs without loading the full WordPress environment (same pattern as
	 * SqueezeEngine, BackupManager, OptimizationIndex constructors).
	 *
	 * @param object $wpdb   Database handle (production: \wpdb; tests: anonymous stub).
	 * @param object $engine SqueezeEngine instance (or stub).
	 * @param object $backup BackupManager instance (or stub).
	 * @param object $index  OptimizationIndex instance (or stub).
	 */
	public function __construct( object $wpdb, object $engine, object $backup, object $index ) {
		$this->wpdb   = $wpdb;
		$this->engine = $engine;
		$this->backup = $backup;
		$this->index  = $index;
	}

	/**
	 * Construct from the live WordPress environment.
	 *
	 * @return self
	 */
	public static function from_wordpress(): self {
		global $wpdb;

		return new self(
			$wpdb,
			SqueezeEngine::from_wordpress(),
			BackupManager::from_wordpress(),
			OptimizationIndex::from_wordpress()
		);
	}

	/**
	 * Walk every attachment via keyset cursor, dispatching the enabled Squeeze
	 * operations per item. Resumable via CHECKPOINT_OPTION.
	 *
	 * Per-item failures are caught and recorded without aborting the batch (TRG-01).
	 * Savings accumulate from recompress/resize bytes only — never additive
	 * webp/avif bytes (TRG-06, D-11).
	 *
	 * @param int        $batch  Attachments per chunk. Default 100.
	 * @param bool       $resume Resume from the stored checkpoint. Default false.
	 * @param array|null $ops    Op list; null = use enabled ops from SqueezeSettings (D-03).
	 * @return int Number of attachments processed.
	 */
	public function backfill( int $batch = 100, bool $resume = false, ?array $ops = null ): int {
		if ( function_exists( 'set_time_limit' ) ) {
			set_time_limit( 0 ); // D-08: extend PHP time limit for long-running batch.
		}

		$batch = max( 1, $batch );

		$last_id = 0;
		if ( $resume ) {
			$checkpoint = get_option( self::CHECKPOINT_OPTION );
			if ( is_array( $checkpoint ) ) {
				$last_id = (int) ( $checkpoint['last_id'] ?? 0 );

				// WR-02: a resumed batch must continue with the SAME operation set it
				// started with. When no explicit --ops was passed, prefer the ops the
				// batch persisted into its own checkpoint rather than re-resolving from
				// settings/flags (which may have changed since the batch began).
				if ( null === $ops && isset( $checkpoint['ops'] ) && is_array( $checkpoint['ops'] ) ) {
					$ops = $checkpoint['ops'];
				}
			}
		}

		$ops = $ops ?? $this->enabled_ops();

		$total       = $this->attachment_count();
		$done        = 0;
		$updated     = 0.0;
		$bytes_saved = 0; // TRG-06 in-memory accumulator (recompress + resize only).

		$destructive = ! empty( array_intersect( $ops, array( 'recompress', 'resize' ) ) );

		do {
			$ids     = $this->next_ids( $last_id, $batch );
			$fetched = count( $ids );
			if ( 0 === $fetched ) {
				break;
			}

			// D-12: disk pre-flight before any encode in THIS chunk. The estimate is
			// computed from the chunk's actual resolved source paths (WR-01) — never an
			// empty list — so a destructive batch on a near-full disk aborts instead of
			// overwriting originals whose backups cannot be written. Driven off the IDs
			// already fetched for the chunk so it consumes no extra keyset query (this
			// preserves the unit-test wpdb page sequence — see SqueezeJobTest).
			if ( $destructive && ! $this->preflight_disk( $ids ) ) {
				ScanProgress::set(
					array(
						'phase'   => 'squeeze',
						'label'   => 'Optimizing library',
						'done'    => $done,
						'total'   => $total,
						'percent' => $total > 0 ? (int) floor( $done / $total * 100 ) : 0,
						'status'  => 'aborted_disk',
					)
				);
				return $done;
			}

			foreach ( $ids as $id ) {
				$result       = $this->process_single( $id, $ops );
				$bytes_saved += max( 0, $result['bytes_saved'] ?? 0 );
			}

			$last_id = (int) end( $ids );
			$done   += $fetched;

			update_option(
				self::CHECKPOINT_OPTION,
				array(
					'last_id' => $last_id,
					'ops'     => $ops,
				),
				false
			);

			$updated = $this->report_progress( $done, $total, $updated );
		} while ( $fetched === $batch );

		delete_option( self::CHECKPOINT_OPTION );

		// Store final savings for CLI / admin to read (TRG-06).
		update_option(
			self::SAVINGS_OPTION,
			array(
				'processed'   => $done,
				'bytes_saved' => max( 0, $bytes_saved ),
			),
			false
		);

		// Append capped history entry (DASH-03 / D-04).
		$history = get_option( self::HISTORY_OPTION, array() );
		if ( ! is_array( $history ) ) {
			$history = array();
		}
		array_unshift(
			$history,
			array(
				'date'             => gmdate( 'Y-m-d H:i:s' ),
				'ops'              => $ops,
				'images_processed' => $done,
				'bytes_saved'      => max( 0, $bytes_saved ),
			)
		);
		if ( count( $history ) > self::HISTORY_CAP ) {
			$history = array_slice( $history, 0, self::HISTORY_CAP );
		}
		update_option( self::HISTORY_OPTION, $history, false );

		return $done;
	}

	/**
	 * Dispatch the enabled Squeeze ops for a single attachment.
	 *
	 * Does NOT call BackupManager::backup() or OptimizationIndex::upsert() —
	 * the engine methods handle those internally (Pitfalls 1/2, D-04).
	 *
	 * Savings = max(0, bytes_before − bytes_after) from recompress + resize only.
	 * WebP/AVIF bytes are additive (never counted as saved — D-11, Pitfall 7).
	 *
	 * @param int        $id  Attachment ID.
	 * @param array|null $ops Op list; null = use enabled ops from SqueezeSettings.
	 * @return array{ok: bool, bytes_saved: int, error?: string}
	 */
	public function process_single( int $id, ?array $ops = null ): array {
		$ops = $ops ?? $this->enabled_ops();

		try {
			$recompress_result = null;
			$resize_result     = null;

			// D-04 ordering: recompress → webp → avif → resize.
			if ( in_array( 'recompress', $ops, true ) ) {
				$recompress_result = $this->engine->recompress( $id );
			}

			if ( in_array( 'webp', $ops, true ) ) {
				$this->engine->generate_webp( $id );
			}

			if ( in_array( 'avif', $ops, true ) ) {
				$this->engine->generate_avif( $id );
			}

			if ( in_array( 'resize', $ops, true ) ) {
				$resize_result = $this->engine->resize_original( $id );
			}

			// Accumulate honest savings: only recompress + resize reclaimed bytes.
			// max(0, ...) clamps per-item deltas so a growing file never yields
			// negative savings (TRG-06, D-11).
			$bytes_saved = 0;

			if ( null !== $recompress_result ) {
				$bytes_saved += max(
					0,
					( (int) ( $recompress_result['bytes_before'] ?? 0 ) ) - ( (int) ( $recompress_result['bytes_after'] ?? 0 ) )
				);
			}

			if ( null !== $resize_result ) {
				$bytes_saved += max(
					0,
					( (int) ( $resize_result['bytes_before'] ?? 0 ) ) - ( (int) ( $resize_result['bytes_after'] ?? 0 ) )
				);
			}

			return array(
				'ok'          => true,
				'bytes_saved' => $bytes_saved,
			);
		} catch ( \Throwable $e ) {
			// Per-item failure: record status, return ok=false, let caller continue (TRG-01).
			// Best-effort status update — update_status may itself fail; that is acceptable.
			try {
				$this->index->update_status( $id, OptimizationRecord::FAILED );
			} catch ( \Throwable $status_error ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- Best-effort; nested failure must not abort the batch.
				unset( $status_error ); // Intentional: silently ignore nested status-write failure.
			}

			return array(
				'ok'          => false,
				'bytes_saved' => 0,
				'error'       => $e->getMessage(),
			);
		}
	}

	/**
	 * Library-wide batch cron callback (assetdrips_squeeze_batch).
	 *
	 * Thin static wrapper so the callback can be registered as a named static
	 * method rather than a closure (cron clearing on deactivation requires a
	 * stable reference — same reasoning as IndexBuilder::reconcile).
	 *
	 * @return void
	 */
	public static function run_library_batch(): void {
		if ( function_exists( 'set_time_limit' ) ) {
			set_time_limit( 0 ); // D-08.
		}

		self::from_wordpress()->backfill();
	}

	/**
	 * Single-attachment async cron callback (assetdrips_squeeze_single).
	 *
	 * T-11-eop security gate: validates $id > 0 and lets get_attached_file() be
	 * the attachment-existence authority (returns early on no-file) before any
	 * processing is done.
	 *
	 * Does NOT re-schedule cron events — scheduling lives only in SqueezeHooks
	 * (avoids cron queue explosion).
	 *
	 * Accepts a permissive `mixed` arg and normalizes internally: under
	 * declare(strict_types=1) a non-int queued cron arg (e.g. a stringy payload
	 * from a stale/serialized event) would throw a TypeError against an `int`
	 * hint BEFORE the guard could run, aborting the entire cron tick and starving
	 * other due events. Casting via absint() makes a malformed arg a safe no-op
	 * instead of a fatal (WR-04).
	 *
	 * @param mixed $id Attachment ID passed by WP-Cron from the single-event args.
	 * @return void
	 */
	public static function run_single( $id = 0 ): void {
		if ( function_exists( 'set_time_limit' ) ) {
			set_time_limit( 0 ); // D-08.
		}

		// T-11-eop / WR-04: normalize the cron arg before any guard. A non-scalar or
		// non-numeric value collapses to 0 and returns cleanly rather than fataling.
		$id = is_scalar( $id ) ? absint( $id ) : 0;
		if ( $id <= 0 ) {
			return;
		}

		$path = get_attached_file( $id );
		if ( false === $path || '' === $path ) {
			return;
		}

		self::from_wordpress()->process_single( $id );
	}

	/**
	 * All known op keys.
	 */
	private const ALL_OPS = array( 'recompress', 'webp', 'avif', 'resize' );

	/**
	 * Build the op list from SqueezeSettings enabled flags (D-03).
	 *
	 * When the user has explicitly disabled every `enable_*` toggle the method
	 * returns an EMPTY set — the batch/per-item path then treats "no ops enabled"
	 * as a clean no-op, exactly as BulkActions::op_squeeze_optimize() already does.
	 * This honors an all-off configuration instead of silently running all four
	 * destructive/additive ops against the entire library (WR-03).
	 *
	 * @return string[] Op keys to run (may be empty when all toggles are off).
	 */
	private function enabled_ops(): array {
		$settings = SqueezeSettings::load();
		$ops      = array();

		if ( $settings->enable_recompress ) {
			$ops[] = 'recompress';
		}
		if ( $settings->enable_webp ) {
			$ops[] = 'webp';
		}
		if ( $settings->enable_avif ) {
			$ops[] = 'avif';
		}
		if ( $settings->enable_resize ) {
			$ops[] = 'resize';
		}

		// All toggles off => respect the user's intent and run nothing. The caller
		// (backfill / process_single) treats an empty op set as a clean no-op,
		// consistent with op_squeeze_optimize() (WR-03).
		return $ops;
	}

	/**
	 * Disk pre-flight gate for one chunk of attachments (D-12 / WR-01).
	 *
	 * Resolves the real source-file path for each attachment ID in the chunk via
	 * get_attached_file() and asks BackupManager::estimate_batch_space() whether the
	 * backup copies of those originals fit on disk (it applies a 20% buffer — D-12).
	 * This is a genuine accounting of the bytes the upcoming destructive ops will
	 * need to back up, NOT the former empty-list no-op that always reported
	 * sufficient=true.
	 *
	 * Caller is responsible for short-circuiting additive-only (webp/avif) runs;
	 * this method is only invoked when the op set contains recompress or resize.
	 *
	 * IMPORTANT: this method issues NO database query — it operates solely on the
	 * IDs the keyset walk already fetched for the current chunk. That keeps the
	 * unit-test wpdb stub's get_col page sequence intact (the pre-flight must not
	 * consume a page from the keyset cursor — see SqueezeJobTest).
	 *
	 * @param int[] $ids Attachment IDs for the current chunk.
	 * @return bool True when sufficient disk space exists for the chunk's backups.
	 */
	private function preflight_disk( array $ids ): bool {
		$source_paths = array();

		foreach ( $ids as $id ) {
			$path = get_attached_file( (int) $id );
			if ( is_string( $path ) && '' !== $path ) {
				$source_paths[] = $path;
			}
		}

		$space = $this->backup->estimate_batch_space( $source_paths );
		return (bool) $space['sufficient'];
	}

	/**
	 * Fetch the next keyset batch of attachment IDs after $last_id.
	 *
	 * @param int $last_id Cursor; only IDs strictly greater than this are returned.
	 * @param int $batch   Maximum number of IDs to return.
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
	 * Count all attachments in the library for progress reporting.
	 *
	 * @return int
	 */
	private function attachment_count(): int {
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Cheap COUNT for progress total; {$wpdb->posts} is a core table name with no user input.
		$count = (int) $this->wpdb->get_var(
			"SELECT COUNT(*) FROM {$this->wpdb->posts} WHERE post_type = 'attachment'"
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		return $count;
	}

	/**
	 * Emit a throttled progress update via ScanProgress (phase='squeeze').
	 *
	 * Mirrors IndexBuilder::report_progress() — throttle to ~0.4 s but always
	 * emit the final tick (D-10).
	 *
	 * @param int   $done       Attachments processed so far.
	 * @param int   $total      Total attachments in the library.
	 * @param float $updated_at Last write time (microtime) for throttling.
	 * @return float The (possibly unchanged) last write time to carry forward.
	 */
	private function report_progress( int $done, int $total, float $updated_at ): float {
		$now = microtime( true );

		// Throttle to ~0.4 s, but always emit the final tick.
		if ( $now - $updated_at < 0.4 && $done < $total ) {
			return $updated_at;
		}

		ScanProgress::set(
			array(
				'phase'   => 'squeeze',
				'label'   => 'Optimizing library',
				'done'    => $done,
				'total'   => $total,
				'percent' => $total > 0 ? (int) floor( $done / $total * 100 ) : 0,
			)
		);

		return $now;
	}
}
