<?php
/**
 * ConfidenceScorer tiering tests.
 *
 * @package AssetDrips
 */

declare( strict_types=1 );

namespace AssetDrips\Tests\Unit;

use AssetDrips\Coverage\CoverageFlag;
use AssetDrips\Coverage\CoverageReport;
use AssetDrips\Score\ConfidenceScorer;
use AssetDrips\Score\Tier;
use AssetDrips\Usage\UsageHit;
use AssetDrips\Usage\UsageMap;
use PHPUnit\Framework\TestCase;

/**
 * Pure tests for the USED / HIGH / MEDIUM / LOW decision rules.
 */
final class ConfidenceScorerTest extends TestCase {

	private const NOW       = 1_700_000_000;
	private const RECENT_AT = 1_699_900_000; // ~1 day before NOW.
	private const OLD_AT    = 1_600_000_000; // long before NOW.

	/**
	 * Build a usage map marking the given IDs as used.
	 *
	 * @param int ...$ids Attachment IDs to mark used.
	 * @return UsageMap
	 */
	private function usage( int ...$ids ): UsageMap {
		$map = new UsageMap();
		foreach ( $ids as $id ) {
			$map->add( new UsageHit( $id, 'content', 'post:1', UsageHit::MATCH_URL, 'x' ) );
		}

		return $map;
	}

	/**
	 * Build a report with an optional flag of the given severity.
	 *
	 * @param string|null $severity Severity constant, or null for no gaps.
	 * @return CoverageReport
	 */
	private function coverage( ?string $severity = null ): CoverageReport {
		$report = new CoverageReport();
		if ( null !== $severity ) {
			$report->add( new CoverageFlag( 'c', CoverageFlag::BUILDER, $severity, 'Gap.' ) );
		}

		return $report;
	}

	/**
	 * Any usage hit yields USED, regardless of gaps or recency.
	 *
	 * @return void
	 */
	public function test_used_wins_outright(): void {
		$scorer = new ConfidenceScorer( $this->usage( 5 ), $this->coverage( CoverageFlag::SIGNIFICANT ) );

		$this->assertSame( Tier::USED, $scorer->score( 5, self::RECENT_AT, self::NOW ) );
	}

	/**
	 * Zero hits, no gaps, old upload → HIGH.
	 *
	 * @return void
	 */
	public function test_high_when_clean_and_old(): void {
		$scorer = new ConfidenceScorer( $this->usage(), $this->coverage() );

		$this->assertSame( Tier::HIGH, $scorer->score( 9, self::OLD_AT, self::NOW ) );
	}

	/**
	 * Zero hits, no gaps, recent upload → MEDIUM.
	 *
	 * @return void
	 */
	public function test_medium_when_recent(): void {
		$scorer = new ConfidenceScorer( $this->usage(), $this->coverage(), 30 );

		$this->assertSame( Tier::MEDIUM, $scorer->score( 9, self::RECENT_AT, self::NOW ) );
	}

	/**
	 * Zero hits and a minor gap → MEDIUM even for an old upload.
	 *
	 * @return void
	 */
	public function test_medium_when_minor_gap(): void {
		$scorer = new ConfidenceScorer( $this->usage(), $this->coverage( CoverageFlag::MINOR ) );

		$this->assertSame( Tier::MEDIUM, $scorer->score( 9, self::OLD_AT, self::NOW ) );
	}

	/**
	 * A significant gap forces LOW, even for an old upload with no other issue.
	 *
	 * @return void
	 */
	public function test_low_when_significant_gap(): void {
		$scorer = new ConfidenceScorer( $this->usage(), $this->coverage( CoverageFlag::SIGNIFICANT ) );

		$this->assertSame( Tier::LOW, $scorer->score( 9, self::OLD_AT, self::NOW ) );
	}

	/**
	 * A significant gap outranks a recent upload (LOW, not MEDIUM).
	 *
	 * @return void
	 */
	public function test_significant_gap_outranks_recency(): void {
		$scorer = new ConfidenceScorer( $this->usage(), $this->coverage( CoverageFlag::SIGNIFICANT ), 30 );

		$this->assertSame( Tier::LOW, $scorer->score( 9, self::RECENT_AT, self::NOW ) );
	}

	/**
	 * A zero recency window disables the recent-upload rule.
	 *
	 * @return void
	 */
	public function test_recency_window_can_be_disabled(): void {
		$scorer = new ConfidenceScorer( $this->usage(), $this->coverage(), 0 );

		$this->assertSame( Tier::HIGH, $scorer->score( 9, self::RECENT_AT, self::NOW ) );
	}

	/**
	 * An unknown upload time is never treated as recent.
	 *
	 * @return void
	 */
	public function test_unknown_upload_time_is_not_recent(): void {
		$scorer = new ConfidenceScorer( $this->usage(), $this->coverage(), 30 );

		$this->assertSame( Tier::HIGH, $scorer->score( 9, null, self::NOW ) );
	}
}
