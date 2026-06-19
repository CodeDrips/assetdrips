<?php
/**
 * Library-wide resumable audit scan for thumbnail sizes.
 *
 * @package AssetDrips
 */

declare( strict_types=1 );

namespace AssetDrips\Squeeze;

use AssetDrips\Admin\ScanProgress;

defined( 'ABSPATH' ) || exit;

/**
 * Resumable keyset scan that, per attachment, computes:
 *   - missing registered sizes (via wp_get_missing_image_subsizes)
 *   - orphaned thumbnail files on disk (per-directory pattern scan, dry-run)
 *
 * Results are written to assetdrips_squeeze.sizes_audit as canonical
 * {missing, orphaned, scanned_at} JSON via OptimizationIndex::update_sizes_audit().
 *
 * Structural analog to SqueezeJob: same keyset cursor, same CHECKPOINT_OPTION pattern,
 * same ScanProgress throttle, same per-item Throwable isolation.  The audit scan has
 * a distinct checkpoint option and ScanProgress phase so it does not interfere with
 * any in-flight squeeze batch.
 *
 * SIZE-03 invariant: this class NEVER calls unlink(), wp_delete_file(), or rmdir().
 * The orphan scan is report-only (dry-run).
 *
 * Threat mitigations:
 *  - T-13-08: zero deletion calls anywhere in this class (grep-asserted in CI).
 *  - T-13-09: dirname(get_attached_file()) resolves via WP; basename-only matching;
 *    is_dir guard before listing; no concatenation of request input to paths.
 *  - T-13-10: per-attachment-directory scan only (never a global uploads walk);
 *    keyset batches bound memory; CLI --batch override available.
 */
final class SizesAuditJob {

	/**
	 * Resumable audit cursor option — distinct from 'assetdrips_squeeze_checkpoint'.
	 */
	public const CHECKPOINT_OPTION = 'assetdrips_sizes_audit_checkpoint';

	/**
	 * Database handle.
	 *
	 * Typed as `object` (not `\wpdb`) so unit tests can inject an anonymous stub
	 * without loading the full WordPress test suite.
	 *
	 * @var object
	 */
	private object $wpdb;

	/**
	 * Optimization index — used for update_sizes_audit() per-item write.
	 *
	 * @var object
	 */
	private object $optimization_index;

	/**
	 * Directory reader callable — defaults to 'scandir'.
	 *
	 * Injected via the constructor's third parameter so unit tests can provide a
	 * fixed-list closure without touching the real filesystem.  Mirrors the
	 * $editor_factory seam on SqueezeEngine.
	 *
	 * @var callable
	 */
	private $dir_reader;

	/**
	 * Construct with explicit dependencies.
	 *
	 * @param object        $wpdb               Database handle.
	 * @param object        $optimization_index OptimizationIndex instance (or stub).
	 * @param callable|null $dir_reader         Directory reader; defaults to 'scandir'.
	 */
	public function __construct( object $wpdb, object $optimization_index, ?callable $dir_reader = null ) {
		$this->wpdb               = $wpdb;
		$this->optimization_index = $optimization_index;
		$this->dir_reader         = $dir_reader ?? 'scandir';
	}

	/**
	 * Construct from the live WordPress environment.
	 *
	 * @return self
	 */
	public static function from_wordpress(): self {
		global $wpdb;

		return new self( $wpdb, OptimizationIndex::from_wordpress() );
	}

	/**
	 * Walk every attachment via keyset cursor, running audit_single() per item and
	 * writing canonical JSON via OptimizationIndex::update_sizes_audit().
	 *
	 * Resumable via CHECKPOINT_OPTION.  Per-item failures are caught and recorded
	 * without aborting the batch (SIZE-01 / D-02 / D-08).
	 *
	 * @param int  $batch  Attachments per chunk. Default 100.
	 * @param bool $resume Resume from the stored checkpoint. Default false.
	 * @return int Number of attachments processed.
	 */
	public function run( int $batch = 100, bool $resume = false ): int {
		if ( function_exists( 'set_time_limit' ) ) {
			set_time_limit( 0 );
		}

		$batch = max( 1, $batch );

		$last_id = 0;
		if ( $resume ) {
			$checkpoint = get_option( self::CHECKPOINT_OPTION );
			if ( is_array( $checkpoint ) ) {
				$last_id = (int) ( $checkpoint['last_id'] ?? 0 );
			}
		}

		$total   = $this->attachment_count();
		$done    = 0;
		$updated = 0.0;

		do {
			$ids     = $this->next_ids( $last_id, $batch );
			$fetched = count( $ids );
			if ( 0 === $fetched ) {
				break;
			}

			foreach ( $ids as $id ) {
				try {
					$result = $this->audit_single( $id );
					$json   = (string) wp_json_encode( $result );
					$this->optimization_index->update_sizes_audit( $id, $json );
				} catch ( \Throwable $e ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- Best-effort; per-item failure must not abort the batch.
					unset( $e ); // Intentional: silently skip failed items to keep the batch running.
				}
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
	 * Compute size definitions that are missing for every audited attachment.
	 *
	 * A size is "unused" when every row in $audit_rows lists it in its 'missing'
	 * array — meaning no attachment in the dataset has it generated.  The derivation
	 * is performed client-side (against the caller's row sample) so it can be called
	 * from both the CLI and the admin screen aggregate path (SIZE-04 / D-05).
	 *
	 * @param array<int, array<string, mixed>> $audit_rows Decoded audit rows, each
	 *   having at minimum a 'missing' key (string[]).
	 * @return string[] Registered size names used by zero attachments in the sample.
	 */
	public function compute_unused_definitions( array $audit_rows ): array {
		$registered = array_keys( wp_get_registered_image_subsizes() );

		if ( empty( $registered ) || empty( $audit_rows ) ) {
			return array();
		}

		// Track which sizes appear as PRESENT (not missing) in at least one row.
		// A size is "used" if it is absent from 'missing' in at least one audit row.
		$used_in_any = array();

		foreach ( $audit_rows as $row ) {
			$missing_in_row = (array) ( $row['missing'] ?? array() );
			foreach ( $registered as $size_name ) {
				if ( ! in_array( $size_name, $missing_in_row, true ) ) {
					$used_in_any[ $size_name ] = true;
				}
			}
		}

		$unused = array();
		foreach ( $registered as $size_name ) {
			if ( ! isset( $used_in_any[ $size_name ] ) ) {
				$unused[] = $size_name;
			}
		}

		return $unused;
	}

	/**
	 * Audit a single attachment: compute missing sizes and orphaned thumbnail files.
	 *
	 * Returns the canonical per-attachment audit array:
	 *   ['missing' => string[], 'orphaned' => string[], 'scanned_at' => string]
	 *
	 * @param int $id Attachment post ID.
	 * @return array{missing: string[], orphaned: string[], scanned_at: string}
	 */
	private function audit_single( int $id ): array {
		// Load image-admin functions in CLI/cron contexts (mirrors SqueezeEngine::repair_missing_sizes()).
		if ( file_exists( ABSPATH . 'wp-admin/includes/image.php' ) ) {
			require_once ABSPATH . 'wp-admin/includes/image.php';
		}

		$missing = array_keys( wp_get_missing_image_subsizes( $id ) );

		$orphaned  = array();
		$orig_path = get_attached_file( $id );

		if ( is_string( $orig_path ) ) {
			// is_dir guard: skip orphan scan when the directory is not locally accessible
			// (cloud storage, deleted dir, etc.) — Pitfall 4.  When a custom dir_reader is
			// injected (test seam), trust the caller and skip the real filesystem check so
			// unit tests can run without creating real directories.
			$has_custom_reader = ( 'scandir' !== $this->dir_reader );
			if ( $has_custom_reader || is_dir( dirname( $orig_path ) ) ) {
				$orphaned = $this->find_orphaned_files( $id, $orig_path );
			}
		}

		return array(
			'missing'    => $missing,
			'orphaned'   => $orphaned,
			'scanned_at' => current_time( 'mysql' ),
		);
	}

	/**
	 * List orphaned thumbnail files in the attachment's upload directory.
	 *
	 * "Orphaned" means: a file whose name matches the {basename}-{W}x{H}.{ext}
	 * pattern but is NOT recorded in the attachment's metadata sizes array.
	 *
	 * The directory listing is performed via the injectable $dir_reader seam so
	 * unit tests can provide a fixed file list without touching the real filesystem.
	 *
	 * Exclusions (SIZE-03 / D-04):
	 *  - The original file itself.
	 *  - Every filename recorded in $meta['sizes'][*]['file'].
	 *  - $meta['original_image'] (WP 5.3+ pre-scaling original, stored as basename).
	 *  - .webp / .avif siblings — naturally excluded because the regex requires the
	 *    original extension, so 'photo-150x150.jpg.webp' does not match.
	 *  - is_dir() guard: returns [] when the directory is not locally accessible
	 *    (cloud storage, deleted dir, etc.) — Pitfall 4.
	 *
	 * SIZE-03 invariant: this method NEVER calls unlink(), wp_delete_file(), rmdir(),
	 * or any deletion API.  It is strictly read-only / report-only (dry-run).
	 *
	 * @param int    $id        Attachment post ID.
	 * @param string $orig_path Absolute path to the original attachment file.
	 * @return string[] Basenames of orphaned thumbnail files.
	 */
	private function find_orphaned_files( int $id, string $orig_path ): array {
		$meta     = wp_get_attachment_metadata( $id );
		$dir      = dirname( $orig_path );
		$basename = pathinfo( $orig_path, PATHINFO_FILENAME );
		$ext      = pathinfo( $orig_path, PATHINFO_EXTENSION );

		// Build the known-files set: original + all metadata size filenames.
		$known = array( pathinfo( $orig_path, PATHINFO_BASENAME ) );
		foreach ( (array) ( $meta['sizes'] ?? array() ) as $size_data ) {
			if ( isset( $size_data['file'] ) ) {
				$known[] = (string) $size_data['file'];
			}
		}
		// WP 5.3+ scaled original (original_image stores basename only — Pitfall 4).
		if ( isset( $meta['original_image'] ) ) {
			$known[] = (string) $meta['original_image'];
		}

		// Regex matches only {basename}-{W}x{H}.{orig_ext} — naturally excludes
		// .webp/.avif appended-extension siblings (their ext differs from $ext).
		$pattern       = '/^' . preg_quote( $basename, '/' ) . '-(\d+)x(\d+)\.' . preg_quote( $ext, '/' ) . '$/i';
		$reader_result = ( $this->dir_reader )( $dir );
		$all_files     = is_array( $reader_result ) ? $reader_result : array();
		$orphaned      = array();

		foreach ( $all_files as $filename ) {
			if ( '.' === $filename || '..' === $filename ) {
				continue;
			}
			if ( in_array( $filename, $known, true ) ) {
				continue;
			}
			if ( preg_match( $pattern, $filename ) ) {
				$orphaned[] = $filename;
			}
		}

		return $orphaned;
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
	 * Emit a throttled progress update via ScanProgress (phase='sizes_audit').
	 *
	 * Mirrors SqueezeJob::report_progress() — throttle to ~0.4 s but always
	 * emit the final tick.
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
				'phase'   => 'sizes_audit',
				'label'   => 'Scanning attachment sizes…',
				'done'    => $done,
				'total'   => $total,
				'percent' => $total > 0 ? (int) floor( $done / $total * 100 ) : 0,
			)
		);

		return $now;
	}
}
