<?php
/**
 * MediaIndex::health_counts() unit tests.
 *
 * @package AssetDrips
 */

declare( strict_types=1 );

namespace AssetDrips\Tests\Unit\Index;

use AssetDrips\Index\MediaIndex;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for MediaIndex::health_counts().
 *
 * A call-order wpdb stub is injected so the four COUNT(*) reads return
 * predictable values without a database. The 0th call maps to `indexed`,
 * 1st to `missing_alt`, 2nd to `unused`, 3rd to `usage_scanned`.
 */
final class MediaIndexHealthTest extends TestCase {

	/**
	 * Builds a wpdb stub that returns values by call order.
	 *
	 * @param array<int, int> $return_values Ordered return values for get_var() calls.
	 * @return object
	 */
	private function wpdb_stub( array $return_values ): object {
		return new class( $return_values ) {
			/**
			 * Number of get_var() calls made so far (call-order cursor).
			 *
			 * @var int
			 */
			private int $call = 0;

			/**
			 * Ordered return values, indexed by get_var() call number.
			 *
			 * @var array<int, int>
			 */
			private array $values;

			/**
			 * Every SQL string passed to get_var(), in call order — lets tests
			 * assert WHERE-clause scoping (e.g. the unused-count CR-01 fix).
			 *
			 * @var array<int, string>
			 */
			public array $sql_log = array();

			/**
			 * Seed the stub with the ordered get_var() return values.
			 *
			 * @param array<int, int> $values Ordered return values.
			 */
			public function __construct( array $values ) {
				$this->values = $values;
			}

			/**
			 * Record the SQL and return the next call-ordered value.
			 *
			 * @param string $sql The query (recorded for scoping assertions).
			 * @return string|null The next stubbed value, or null when exhausted.
			 */
			public function get_var( string $sql ): ?string {
				$this->sql_log[] = $sql;
				$key             = $this->call++;
				return isset( $this->values[ $key ] ) ? (string) $this->values[ $key ] : null;
			}
		};
	}

	/**
	 * Returns indexed, missing_alt, unused counts and is_usage_scanned=true
	 * when at least one row has usage_synced_at set.
	 *
	 * @return void
	 */
	public function test_health_counts_returns_expected_counts(): void {
		$stub  = $this->wpdb_stub( array( 120, 15, 8, 1 ) );
		$index = new MediaIndex( $stub );

		$result = $index->health_counts();

		$this->assertSame( 120, $result['indexed'] );
		$this->assertSame( 15, $result['missing_alt'] );
		$this->assertSame( 8, $result['unused'] );
		$this->assertTrue( $result['is_usage_scanned'] );
	}

	/**
	 * Returns is_usage_scanned=false when no rows have usage_synced_at set.
	 *
	 * @return void
	 */
	public function test_health_counts_returns_false_for_is_usage_scanned_when_zero_synced(): void {
		$stub  = $this->wpdb_stub( array( 50, 3, 50, 0 ) );
		$index = new MediaIndex( $stub );

		$result = $index->health_counts();

		$this->assertFalse( $result['is_usage_scanned'] );
	}

	/**
	 * The unused-count query MUST be scoped to usage-scanned rows
	 * (`usage_synced_at IS NOT NULL`). Without this scoping, never-scanned rows
	 * — which carry the `is_used = 0` default — inflate the unused count in a
	 * partially-scanned library (CR-01 / D-08 usage-lane honesty).
	 *
	 * @return void
	 */
	public function test_unused_query_is_scoped_to_scanned_rows(): void {
		$stub  = $this->wpdb_stub( array( 120, 15, 8, 1 ) );
		$index = new MediaIndex( $stub );

		$index->health_counts();

		// Call order: 0=indexed, 1=missing_alt, 2=unused, 3=usage_scanned.
		$unused_sql = $stub->sql_log[2];
		$this->assertStringContainsString( 'is_used = 0', $unused_sql );
		$this->assertStringContainsString( 'usage_synced_at IS NOT NULL', $unused_sql );
	}
}
