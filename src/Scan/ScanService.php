<?php
/**
 * Orchestrates a full scan: index, coverage, scanners, scoring, persistence.
 *
 * @package AssetDrips
 */

declare( strict_types=1 );

namespace AssetDrips\Scan;

use AssetDrips\Coverage\BuilderDetector;
use AssetDrips\Coverage\CoverageReport;
use AssetDrips\Db\Schema;
use AssetDrips\Inventory\ReferenceIndex;
use AssetDrips\Inventory\ReferenceIndexBuilder;
use AssetDrips\Score\ConfidenceScorer;
use AssetDrips\Score\Tier;
use AssetDrips\Usage\UsageMap;

defined( 'ABSPATH' ) || exit;

/**
 * The end-to-end scan pipeline.
 *
 * Wires the parts built across the suite: builds the reference index from the
 * catalogue, detects coverage gaps, runs every scanner into one shared usage
 * map, then scores and persists a tier for every attachment. Writing is batched
 * and checkpointed so a long scan is memory-safe and resumable.
 *
 * The CLI is the primary caller; the admin UI reuses the same service.
 */
final class ScanService {

	/**
	 * Option key holding scan progress for resumption.
	 */
	public const CHECKPOINT_OPTION = 'assetdrips_scan_checkpoint';

	/**
	 * Database handle.
	 *
	 * @var \wpdb
	 */
	private \wpdb $wpdb;

	/**
	 * Shared reference resolver.
	 *
	 * @var ReferenceIndex
	 */
	private ReferenceIndex $index;

	/**
	 * Coverage gaps for the scan.
	 *
	 * @var CoverageReport
	 */
	private CoverageReport $coverage;

	/**
	 * Scanners to run, in order.
	 *
	 * @var ScannerInterface[]
	 */
	private array $scanners;

	/**
	 * Recency window in days passed to the scorer.
	 *
	 * @var int
	 */
	private int $recent_days;

	/**
	 * Construct with explicit dependencies.
	 *
	 * @param \wpdb              $wpdb        Database handle.
	 * @param ReferenceIndex     $index       Shared reference resolver.
	 * @param CoverageReport     $coverage    Coverage gaps.
	 * @param ScannerInterface[] $scanners    Scanners to run.
	 * @param int                $recent_days Recency window in days.
	 */
	public function __construct( \wpdb $wpdb, ReferenceIndex $index, CoverageReport $coverage, array $scanners, int $recent_days = 30 ) {
		$this->wpdb        = $wpdb;
		$this->index       = $index;
		$this->coverage    = $coverage;
		$this->scanners    = $scanners;
		$this->recent_days = $recent_days;
	}

	/**
	 * Wire the full pipeline from the live WordPress environment.
	 *
	 * @param int                     $recent_days    Recency window in days.
	 * @param callable(int):void|null $index_progress Called per batch while indexing.
	 * @return self
	 */
	public static function from_wordpress( int $recent_days = 30, ?callable $index_progress = null ): self {
		global $wpdb;

		$index    = ReferenceIndexBuilder::from_wordpress( 500, $index_progress );
		$coverage = BuilderDetector::from_wordpress();

		$scanners = array(
			new ContentScanner( $wpdb, $index ),
			new PostmetaScanner( $wpdb, $index ),
			new AcfScanner( $wpdb, $index ),
			new WooScanner( $wpdb, $index ),
			new OptionsScanner( $wpdb, $index ),
			new TermMetaScanner( $wpdb, $index ),
		);

		return new self( $wpdb, $index, $coverage, $scanners, $recent_days );
	}

	/**
	 * The coverage report for this scan.
	 *
	 * @return CoverageReport
	 */
	public function coverage(): CoverageReport {
		return $this->coverage;
	}

	/**
	 * Run every scanner into one shared usage map.
	 *
	 * @param callable(string, int, ?int):void|null $on_progress Called as (source, done, total).
	 * @return UsageMap
	 */
	public function gather_usage( ?callable $on_progress = null ): UsageMap {
		$usage = new UsageMap();

		foreach ( $this->scanners as $scanner ) {
			$scanner->scan( $usage, $on_progress );
		}

		return $usage;
	}

	/**
	 * Score and persist a tier for every attachment, in resumable batches.
	 *
	 * @param UsageMap                     $usage    Evidence from the scanners.
	 * @param array<string, mixed>         $options  batch, start_after_id, dry_run, now.
	 * @param callable(int, int):void|null $on_batch Called after each batch with (done, total).
	 * @return array<string, int> Tier counts.
	 */
	public function persist( UsageMap $usage, array $options = array(), ?callable $on_batch = null ): array {
		$wpdb  = $this->wpdb;
		$batch = max( 1, (int) ( $options['batch'] ?? 500 ) );
		$last  = (int) ( $options['start_after_id'] ?? 0 );
		$dry   = (bool) ( $options['dry_run'] ?? false );
		$now   = (int) ( $options['now'] ?? time() );

		$scorer        = new ConfidenceScorer( $usage, $this->coverage, $this->recent_days );
		$coverage_json = (string) wp_json_encode( $this->coverage->to_array() );
		$scanned_at    = current_time( 'mysql' );
		$table         = Schema::results_table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- One-off COUNT for the scoring progress total.
		$total  = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'attachment'" );
		$counts = array(
			Tier::USED->value   => 0,
			Tier::HIGH->value   => 0,
			Tier::MEDIUM->value => 0,
			Tier::LOW->value    => 0,
		);

		do {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Full attachment sweep for scoring; inherently direct and uncached.
			$rows    = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT ID, post_date_gmt FROM {$wpdb->posts} WHERE post_type = 'attachment' AND ID > %d ORDER BY ID ASC LIMIT %d",
					$last,
					$batch
				),
				ARRAY_A
			);
			$fetched = count( (array) $rows );

			foreach ( (array) $rows as $row ) {
				$id          = (int) $row['ID'];
				$uploaded_at = self::to_timestamp( $row['post_date_gmt'] ?? null );
				$tier        = $scorer->score( $id, $uploaded_at, $now );

				++$counts[ $tier->value ];

				if ( ! $dry ) {
					$evidence_json = (string) wp_json_encode( $usage->evidence_for( $id ) );
					// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Upserting a scan result row.
					$wpdb->replace( $table, self::result_row( $id, $tier, $evidence_json, $coverage_json, $scanned_at ) );
				}

				$last = $id;
			}

			if ( ! $dry ) {
				update_option(
					self::CHECKPOINT_OPTION,
					array(
						'last_id' => $last,
						'counts'  => $counts,
						'now'     => $now,
					),
					false
				);
			}

			if ( null !== $on_batch ) {
				$on_batch( (int) array_sum( $counts ), $total );
			}
		} while ( $fetched === $batch );

		return $counts;
	}

	/**
	 * Run the whole pipeline: gather usage, then score and persist.
	 *
	 * @param array<string, mixed>              $options  Passed to persist().
	 * @param callable(string, array):void|null $progress Called as ('scan', [source,done,total]) and ('score', [done,total]).
	 * @return array<string, int> Tier counts.
	 */
	public function run( array $options = array(), ?callable $progress = null ): array {
		$usage = $this->gather_usage(
			null === $progress ? null : static function ( string $source, int $done, ?int $total ) use ( $progress ): void {
				$progress( 'scan', array( $source, $done, $total ) );
			}
		);

		$counts = $this->persist(
			$usage,
			$options,
			null === $progress ? null : static function ( int $done, int $total ) use ( $progress ): void {
				$progress( 'score', array( $done, $total ) );
			}
		);

		if ( ! (bool) ( $options['dry_run'] ?? false ) ) {
			delete_option( self::CHECKPOINT_OPTION );
		}

		return $counts;
	}

	/**
	 * Build a result-table row. Pure: all inputs are scalars or encoded JSON.
	 *
	 * @param int    $attachment_id Attachment post ID.
	 * @param Tier   $tier          Assigned tier.
	 * @param string $evidence_json JSON-encoded evidence.
	 * @param string $coverage_json JSON-encoded coverage flags.
	 * @param string $scanned_at    MySQL datetime string.
	 * @return array<string, mixed>
	 */
	public static function result_row( int $attachment_id, Tier $tier, string $evidence_json, string $coverage_json, string $scanned_at ): array {
		return array(
			'attachment_id'  => $attachment_id,
			'tier'           => $tier->value,
			'confidence'     => $tier->confidence(),
			'evidence'       => $evidence_json,
			'coverage_flags' => $coverage_json,
			'scanned_at'     => $scanned_at,
		);
	}

	/**
	 * Convert a GMT MySQL datetime to a unix timestamp. Pure.
	 *
	 * @param string|null $mysql_gmt MySQL datetime in GMT, or null.
	 * @return int|null
	 */
	public static function to_timestamp( ?string $mysql_gmt ): ?int {
		if ( ! is_string( $mysql_gmt ) || '' === $mysql_gmt || str_starts_with( $mysql_gmt, '0000' ) ) {
			return null;
		}

		$timestamp = strtotime( $mysql_gmt . ' UTC' );

		return false === $timestamp ? null : $timestamp;
	}
}
