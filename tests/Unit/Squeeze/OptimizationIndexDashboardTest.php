<?php
/**
 * RED tests for OptimizationIndex::query_dashboard() and get_biggest_unoptimized() (DASH-01/02/D-09).
 *
 * These tests are RED until Wave 1 implements query_dashboard() and
 * get_biggest_unoptimized() on OptimizationIndex.  They define the required
 * behaviour; Wave 1 turns them GREEN.
 *
 * EXIT_EXPECTED_RED: Tests WILL fail because query_dashboard() and
 * get_biggest_unoptimized() do not yet exist.  Failures must be
 * method-not-found or assertion failures — NOT parse/bootstrap fatals.
 *
 * @package AssetDrips
 */

declare( strict_types=1 );

namespace AssetDrips\Tests\Unit\Squeeze;

use AssetDrips\Squeeze\OptimizationIndex;
use PHPUnit\Framework\TestCase;

/**
 * RED tests: query_dashboard() + get_biggest_unoptimized() contracts (DASH-01/02/D-09).
 */
final class OptimizationIndexDashboardTest extends TestCase {

	/**
	 * Build a wpdb stub whose get_var() returns a fixed scalar and whose
	 * get_row() returns a fixed associative array.
	 *
	 * @param mixed $var_return  Value for get_var().
	 * @param mixed $row_return  Value for get_row().
	 * @param mixed $results_return Value for get_results().
	 * @return object Anonymous wpdb stub.
	 */
	private function make_wpdb_stub( mixed $var_return = null, mixed $row_return = null, mixed $results_return = array() ): object {
		return new class( $var_return, $row_return, $results_return ) {
			/** @var string */
			public string $prefix = 'wp_';

			/** @var mixed */
			private mixed $var_return;

			/** @var mixed */
			private mixed $row_return;

			/** @var mixed */
			private mixed $results_return;

			/**
			 * Capture SQL calls for assertion in tests.
			 *
			 * @var string[]
			 */
			public array $sql_log = array();

			/**
			 * @param mixed $var_return     Scalar returned by get_var().
			 * @param mixed $row_return     Array returned by get_row().
			 * @param mixed $results_return Array returned by get_results().
			 */
			public function __construct( mixed $var_return, mixed $row_return, mixed $results_return ) {
				$this->var_return     = $var_return;
				$this->row_return     = $row_return;
				$this->results_return = $results_return;
			}

			/**
			 * @param string $sql  SQL template.
			 * @param mixed  ...$args Bound values.
			 * @return string
			 */
			public function prepare( string $sql, mixed ...$args ): string { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- Stub.
				// Interpolate args into sql so the SQL log can be inspected for bound values.
				$result = $sql;
				foreach ( $args as $arg ) {
					$result = preg_replace( '/%[sdf]/', (string) $arg, $result, 1 ) ?? $result;
				}
				$this->sql_log[] = $result;
				return $result;
			}

			/**
			 * @param string $sql Prepared SQL.
			 * @return mixed
			 */
			public function get_var( string $sql ): mixed { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- Stub.
				$this->sql_log[] = $sql;
				return $this->var_return;
			}

			/**
			 * @param string $sql    Prepared SQL.
			 * @param string $output Output format constant.
			 * @return mixed
			 */
			public function get_row( string $sql, string $output = '' ): mixed { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- Stub.
				$this->sql_log[] = $sql;
				return $this->row_return;
			}

			/**
			 * @param string $sql    Prepared SQL.
			 * @param string $output Output format constant.
			 * @return array<int, mixed>
			 */
			public function get_results( string $sql, string $output = '' ): array { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- Stub.
				$this->sql_log[] = $sql;
				return $this->results_return;
			}
		};
	}

	// -------------------------------------------------------------------------
	// DASH-01 / D-09: query_dashboard() returns expected keys
	// -------------------------------------------------------------------------

	/**
	 * query_dashboard() returns an array with the required top-level keys.
	 *
	 * @return void
	 */
	public function test_query_dashboard_returns_expected_keys(): void {
		$wpdb = $this->make_wpdb_stub(
			0,
			array( 'bytes_saved' => 0, 'original_total' => 0 )
		);

		$index  = new OptimizationIndex( $wpdb );
		$result = $index->query_dashboard(); // RED: method does not exist yet.

		$this->assertIsArray( $result, 'query_dashboard() must return an array' );
		$this->assertArrayHasKey( 'optimized', $result, 'Result must contain "optimized" key' );
		$this->assertArrayHasKey( 'total', $result, 'Result must contain "total" key' );
		$this->assertArrayHasKey( 'bytes_saved', $result, 'Result must contain "bytes_saved" key' );
		$this->assertArrayHasKey( 'pct_reduction', $result, 'Result must contain "pct_reduction" key' );
		$this->assertArrayHasKey( 'oversized', $result, 'Result must contain "oversized" key' );
		$this->assertArrayHasKey( 'missing_webp', $result, 'Result must contain "missing_webp" key' );
	}

	// -------------------------------------------------------------------------
	// D-09: bytes_saved SQL uses GREATEST + CAST AS SIGNED, NOT webp_bytes/avif_bytes
	// -------------------------------------------------------------------------

	/**
	 * The SQL used by query_dashboard() for bytes_saved contains GREATEST, CAST,
	 * and SIGNED (the unsigned-bigint-safe arithmetic pattern), and does NOT
	 * reference webp_bytes or avif_bytes (D-09 savings honesty).
	 *
	 * @return void
	 */
	public function test_query_dashboard_bytes_saved_sql_uses_greatest_cast_signed(): void {
		$wpdb = $this->make_wpdb_stub(
			'5',
			array( 'bytes_saved' => '1024', 'original_total' => '4096' )
		);

		$index = new OptimizationIndex( $wpdb );
		$index->query_dashboard(); // RED: method does not exist yet.

		// At least one of the SQL statements in the log must contain the D-09-safe pattern.
		$combined_sql = implode( ' ', $wpdb->sql_log );

		$this->assertStringContainsString(
			'GREATEST',
			$combined_sql,
			'bytes_saved SQL must use GREATEST() to clamp negatives (Pitfall 1 + D-09)'
		);

		$this->assertStringContainsString(
			'CAST',
			$combined_sql,
			'bytes_saved SQL must use CAST() to prevent unsigned bigint wrap (Pitfall 1)'
		);

		$this->assertStringContainsString(
			'SIGNED',
			$combined_sql,
			'bytes_saved SQL must cast AS SIGNED to prevent unsigned bigint wrap (Pitfall 1)'
		);

		$this->assertStringNotContainsString(
			'webp_bytes',
			$combined_sql,
			'bytes_saved SQL must NOT reference webp_bytes — additive sibling, not disk savings (D-09)'
		);

		$this->assertStringNotContainsString(
			'avif_bytes',
			$combined_sql,
			'bytes_saved SQL must NOT reference avif_bytes — additive sibling, not disk savings (D-09)'
		);
	}

	// -------------------------------------------------------------------------
	// DASH-02: get_biggest_unoptimized() scopes to image mime, excludes complete rows
	// -------------------------------------------------------------------------

	/**
	 * get_biggest_unoptimized() SQL scopes to image/* mime rows, excludes
	 * status='complete' rows, orders by filesize DESC, and applies LIMIT.
	 *
	 * @return void
	 */
	public function test_get_biggest_unoptimized_sql_excludes_complete_rows_and_scopes_to_images(): void {
		$wpdb = $this->make_wpdb_stub(
			null,
			null,
			array() // get_results returns empty
		);

		$index = new OptimizationIndex( $wpdb );
		$index->get_biggest_unoptimized( 10 ); // RED: method does not exist yet.

		$combined_sql = implode( ' ', $wpdb->sql_log );

		// Must scope to image mime rows (Pitfall 4 — missing-WebP count scope).
		$this->assertStringContainsString(
			'image/%',
			$combined_sql,
			'get_biggest_unoptimized() SQL must scope to mime LIKE image/% (Pitfall 4)'
		);

		// Must exclude rows that already have a complete squeeze record.
		$this->assertStringContainsString(
			'complete',
			$combined_sql,
			'get_biggest_unoptimized() SQL must reference status=complete to exclude optimized rows (DASH-02)'
		);

		// Must order by filesize descending so biggest offenders come first.
		$this->assertMatchesRegularExpression(
			'/filesize\s+DESC/i',
			$combined_sql,
			'get_biggest_unoptimized() SQL must order by filesize DESC (DASH-02)'
		);

		// Must apply a LIMIT.
		$this->assertStringContainsString(
			'LIMIT',
			$combined_sql,
			'get_biggest_unoptimized() SQL must apply a LIMIT (DASH-02)'
		);
	}

	/**
	 * get_biggest_unoptimized() returns an array (empty when no DB rows).
	 *
	 * @return void
	 */
	public function test_get_biggest_unoptimized_returns_array(): void {
		$wpdb  = $this->make_wpdb_stub( null, null, array() );
		$index = new OptimizationIndex( $wpdb );

		$result = $index->get_biggest_unoptimized( 10 ); // RED: method does not exist yet.

		$this->assertIsArray( $result, 'get_biggest_unoptimized() must return an array' );
	}
}
