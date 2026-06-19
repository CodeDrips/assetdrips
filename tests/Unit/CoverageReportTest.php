<?php
/**
 * CoverageReport tests.
 *
 * @package AssetDrips
 */

declare( strict_types=1 );

namespace AssetDrips\Tests\Unit;

use AssetDrips\Coverage\CoverageFlag;
use AssetDrips\Coverage\CoverageReport;
use PHPUnit\Framework\TestCase;

/**
 * Pure tests for the coverage report aggregate.
 */
final class CoverageReportTest extends TestCase {

	/**
	 * An empty report has no gaps.
	 *
	 * @return void
	 */
	public function test_empty_report(): void {
		$report = new CoverageReport();

		$this->assertFalse( $report->has_gaps() );
		$this->assertFalse( $report->has_significant_gaps() );
		$this->assertSame( array(), $report->flags() );
	}

	/**
	 * Significant and minor flags are distinguished.
	 *
	 * @return void
	 */
	public function test_significance(): void {
		$report = new CoverageReport();
		$report->add( new CoverageFlag( 'a.minor', CoverageFlag::OTHER, CoverageFlag::MINOR, 'Minor.' ) );

		$this->assertTrue( $report->has_gaps() );
		$this->assertFalse( $report->has_significant_gaps() );

		$report->add( new CoverageFlag( 'b.sig', CoverageFlag::BUILDER, CoverageFlag::SIGNIFICANT, 'Big.' ) );

		$this->assertTrue( $report->has_significant_gaps() );
		$this->assertCount( 1, $report->significant_flags() );
	}

	/**
	 * A repeated code is recorded once.
	 *
	 * @return void
	 */
	public function test_dedup_by_code(): void {
		$report = new CoverageReport();
		$report->add( new CoverageFlag( 'builder.x', CoverageFlag::BUILDER, CoverageFlag::SIGNIFICANT, 'First.' ) );
		$report->add( new CoverageFlag( 'builder.x', CoverageFlag::BUILDER, CoverageFlag::SIGNIFICANT, 'Second.' ) );

		$this->assertCount( 1, $report->flags() );
		$this->assertSame( 'First.', $report->flags()[0]->label() );
	}

	/**
	 * The report exports to plain arrays for storage.
	 *
	 * @return void
	 */
	public function test_to_array(): void {
		$report = new CoverageReport();
		$report->add( new CoverageFlag( 'builder.x', CoverageFlag::BUILDER, CoverageFlag::SIGNIFICANT, 'Big.', 'x' ) );

		$this->assertSame(
			array(
				array(
					'code'     => 'builder.x',
					'category' => CoverageFlag::BUILDER,
					'severity' => CoverageFlag::SIGNIFICANT,
					'label'    => 'Big.',
					'detail'   => 'x',
				),
			),
			$report->to_array()
		);
	}
}
