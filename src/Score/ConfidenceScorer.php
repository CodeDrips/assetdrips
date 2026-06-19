<?php
/**
 * Assigns a confidence tier to each attachment.
 *
 * @package AssetDrips
 */

declare( strict_types=1 );

namespace AssetDrips\Score;

use AssetDrips\Coverage\CoverageReport;
use AssetDrips\Usage\UsageMap;

defined( 'ABSPATH' ) || exit;

/**
 * Turns evidence and coverage into a per-attachment {@see Tier}.
 *
 * The rules are the heart of the trust promise, applied in strict order:
 *  1. Any usage hit at all wins outright — the attachment is USED. This is the
 *     anti-goal guard: a reference reachable by any scanner is never deletable.
 *  2. With zero hits, a significant coverage gap forces LOW. We cannot see into
 *     a builder / custom table / offloaded store, so we never clear to HIGH.
 *  3. Still zero hits and no significant gap, but a minor gap or a recent upload
 *     means MEDIUM — plausible, but review first.
 *  4. Otherwise HIGH — zero hits, full coverage, settled. Safe to self-serve.
 *
 * Coverage gaps are site-wide in v1, so the only per-attachment input beyond
 * usage is the upload time, used for the recency window.
 */
final class ConfidenceScorer {

	/**
	 * Seconds in a day. Kept local so scoring stays free of WordPress constants.
	 */
	private const DAY = 86400;

	/**
	 * Evidence store for the scan.
	 *
	 * @var UsageMap
	 */
	private UsageMap $usage;

	/**
	 * Coverage gaps for the scan.
	 *
	 * @var CoverageReport
	 */
	private CoverageReport $coverage;

	/**
	 * Recency window in days; a zero-hit upload newer than this is MEDIUM.
	 *
	 * @var int
	 */
	private int $recent_days;

	/**
	 * Construct the scorer for a scan.
	 *
	 * @param UsageMap       $usage       Evidence store.
	 * @param CoverageReport $coverage    Coverage gaps.
	 * @param int            $recent_days Recency window in days (0 disables it).
	 */
	public function __construct( UsageMap $usage, CoverageReport $coverage, int $recent_days = 30 ) {
		$this->usage       = $usage;
		$this->coverage    = $coverage;
		$this->recent_days = $recent_days;
	}

	/**
	 * Score one attachment.
	 *
	 * @param int      $attachment_id Attachment post ID.
	 * @param int|null $uploaded_at   Upload time (unix seconds), or null if unknown.
	 * @param int|null $now           Reference time (unix seconds); defaults to now.
	 * @return Tier
	 */
	public function score( int $attachment_id, ?int $uploaded_at = null, ?int $now = null ): Tier {
		if ( $this->usage->is_used( $attachment_id ) ) {
			return Tier::USED;
		}

		if ( $this->coverage->has_significant_gaps() ) {
			return Tier::LOW;
		}

		if ( $this->coverage->has_gaps() || $this->is_recent( $uploaded_at, $now ) ) {
			return Tier::MEDIUM;
		}

		return Tier::HIGH;
	}

	/**
	 * Whether an upload falls inside the recency window.
	 *
	 * @param int|null $uploaded_at Upload time (unix seconds), or null.
	 * @param int|null $now         Reference time (unix seconds); defaults to now.
	 * @return bool
	 */
	private function is_recent( ?int $uploaded_at, ?int $now ): bool {
		if ( null === $uploaded_at || $this->recent_days <= 0 ) {
			return false;
		}

		$now = $now ?? time();

		return ( $now - $uploaded_at ) < ( $this->recent_days * self::DAY );
	}
}
