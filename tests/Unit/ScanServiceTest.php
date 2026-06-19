<?php
/**
 * ScanService pure-helper tests.
 *
 * @package AssetDrips
 */

declare( strict_types=1 );

namespace AssetDrips\Tests\Unit;

use AssetDrips\Scan\ScanService;
use AssetDrips\Score\Tier;
use PHPUnit\Framework\TestCase;

/**
 * Pure tests for the result-row builder and timestamp parser.
 */
final class ScanServiceTest extends TestCase {

	/**
	 * A result row maps tier to its value and confidence and carries the JSON.
	 *
	 * @return void
	 */
	public function test_result_row(): void {
		$row = ScanService::result_row( 42, Tier::HIGH, '[]', '[{"code":"x"}]', '2026-06-07 00:00:00' );

		$this->assertSame(
			array(
				'attachment_id'  => 42,
				'tier'           => 'HIGH',
				'confidence'     => Tier::HIGH->confidence(),
				'evidence'       => '[]',
				'coverage_flags' => '[{"code":"x"}]',
				'scanned_at'     => '2026-06-07 00:00:00',
			),
			$row
		);
	}

	/**
	 * A GMT MySQL datetime parses to the correct unix timestamp.
	 *
	 * @return void
	 */
	public function test_to_timestamp_parses_gmt(): void {
		$this->assertSame( 1_700_000_000, ScanService::to_timestamp( '2023-11-14 22:13:20' ) );
	}

	/**
	 * Empty, zero, and null datetimes yield null.
	 *
	 * @return void
	 */
	public function test_to_timestamp_handles_missing(): void {
		$this->assertNull( ScanService::to_timestamp( null ) );
		$this->assertNull( ScanService::to_timestamp( '' ) );
		$this->assertNull( ScanService::to_timestamp( '0000-00-00 00:00:00' ) );
	}
}
