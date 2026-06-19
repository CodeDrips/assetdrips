<?php
/**
 * BuilderDetector tests.
 *
 * @package AssetDrips
 */

declare( strict_types=1 );

namespace AssetDrips\Tests\Unit;

use AssetDrips\Coverage\BuilderDetector;
use AssetDrips\Coverage\CoverageFlag;
use PHPUnit\Framework\TestCase;

/**
 * Pure tests for coverage-gap detection rules.
 */
final class BuilderDetectorTest extends TestCase {

	/**
	 * Map a report to the set of flag codes it contains.
	 *
	 * @param \AssetDrips\Coverage\CoverageReport $report Report.
	 * @return string[]
	 */
	private function codes( $report ): array {
		return array_map(
			static fn( CoverageFlag $flag ): string => $flag->code(),
			$report->flags()
		);
	}

	/**
	 * A clean native site has no coverage gaps.
	 *
	 * @return void
	 */
	public function test_clean_site_has_no_gaps(): void {
		$report = BuilderDetector::detect(
			array( 'akismet/akismet.php', 'woocommerce/woocommerce.php' ),
			array( 'twentytwentyfour' )
		);

		$this->assertFalse( $report->has_gaps() );
		$this->assertFalse( $report->has_significant_gaps() );
	}

	/**
	 * An active page-builder plugin is a significant gap.
	 *
	 * @return void
	 */
	public function test_builder_plugin_is_significant(): void {
		$report = BuilderDetector::detect( array( 'elementor/elementor.php' ), array( 'hello-elementor' ) );

		$this->assertTrue( $report->has_significant_gaps() );
		$this->assertContains( 'builder.elementor', $this->codes( $report ) );
	}

	/**
	 * A builder theme is detected even with no builder plugin active.
	 *
	 * @return void
	 */
	public function test_builder_theme_is_significant(): void {
		$report = BuilderDetector::detect( array(), array( 'Divi' ) );

		$this->assertTrue( $report->has_significant_gaps() );
		$this->assertContains( 'builder.divi', $this->codes( $report ) );
	}

	/**
	 * Custom-table plugins are flagged as significant.
	 *
	 * @return void
	 */
	public function test_custom_table_plugin_is_significant(): void {
		$report = BuilderDetector::detect( array( 'jet-engine/jet-engine.php' ), array() );

		$this->assertTrue( $report->has_significant_gaps() );
		$codes = $this->codes( $report );
		$this->assertContains( 'custom_table.jet-engine', $codes );
	}

	/**
	 * Media offload plugins and an offloaded host are significant.
	 *
	 * @return void
	 */
	public function test_offloaded_media_is_significant(): void {
		$report = BuilderDetector::detect(
			array( 'amazon-s3-and-cloudfront/wordpress-s3.php' ),
			array(),
			false,
			true
		);

		$codes = $this->codes( $report );
		$this->assertContains( 'offloaded.amazon-s3-and-cloudfront', $codes );
		$this->assertContains( 'offloaded.host', $codes );
		$this->assertTrue( $report->has_significant_gaps() );
	}

	/**
	 * ACF local JSON is a minor gap, not a blocker.
	 *
	 * @return void
	 */
	public function test_acf_local_json_is_minor(): void {
		$report = BuilderDetector::detect( array(), array( 'twentytwentyfour' ), true, false );

		$this->assertTrue( $report->has_gaps() );
		$this->assertFalse( $report->has_significant_gaps() );
		$this->assertContains( 'acf.local_json', $this->codes( $report ) );
	}

	/**
	 * A builder under two plugin slugs is recorded once.
	 *
	 * @return void
	 */
	public function test_duplicate_builder_recorded_once(): void {
		$report = BuilderDetector::detect(
			array( 'beaver-builder-lite-version/fl-builder.php', 'bb-plugin/fl-builder.php' ),
			array()
		);

		$codes = $this->codes( $report );
		$this->assertSame( array( 'builder.beaver-builder-lite-version', 'builder.bb-plugin' ), $codes );
		// Two distinct slugs, both Beaver Builder; both significant.
		$this->assertCount( 2, $report->significant_flags() );
	}
}
