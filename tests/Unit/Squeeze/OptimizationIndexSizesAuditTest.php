<?php
/**
 * RED unit tests for OptimizationIndex::update_sizes_audit() and
 * OptimizationIndex::get_sizes_audit_summary() (SIZE-01 / D-06).
 *
 * These tests are RED until Plan 13-02 adds both methods to OptimizationIndex.
 * Plan 13-02 turns them GREEN.
 *
 * All WP functions are stubbed via unit-bootstrap.php.
 * OptimizationIndex receives an anonymous $wpdb stub that captures SQL queries.
 *
 * @package AssetDrips
 */

declare( strict_types=1 );

namespace AssetDrips\Tests\Unit\Squeeze;

use AssetDrips\Squeeze\OptimizationIndex;
use PHPUnit\Framework\TestCase;

/**
 * RED tests for OptimizationIndex sizes-audit read/write methods (SIZE-01 / D-06).
 */
final class OptimizationIndexSizesAuditTest extends TestCase {

	// -------------------------------------------------------------------------
	// Helper: build a $wpdb stub with configurable query capture
	// -------------------------------------------------------------------------

	/**
	 * Build a $wpdb stub that records query() calls and returns configurable get_col rows.
	 *
	 * @param array<int, string> $col_rows   Rows to return from get_col().
	 * @return object Anonymous wpdb stub.
	 */
	private function make_wpdb_stub( array $col_rows = array() ): object {
		return new class( $col_rows ) {
			/** @var string */
			public string $prefix = 'wp_';
			/** @var array<int, string> */
			public array $queries = array();
			/** @var array<int, string> */
			private array $col_rows;

			/** @param array<int, string> $col_rows Rows for get_col(). */
			public function __construct( array $col_rows ) {
				$this->col_rows = $col_rows;
			}

			/**
			 * @param string $sql  SQL template.
			 * @param mixed  ...$args Bound values.
			 * @return string
			 */
			public function prepare( string $sql, mixed ...$args ): string {
				if ( empty( $args ) ) {
					return $sql;
				}
				// phpcs:ignore WordPress.WP.AlternativeFunctions.sprintf_sprintf -- Stub passthrough.
				return sprintf( str_replace( array( '%d', '%s' ), '%s', $sql ), ...$args );
			}

			/**
			 * @param string $sql SQL to execute.
			 * @return int|false
			 */
			public function query( string $sql ): int|false {
				$this->queries[] = $sql;
				return 1;
			}

			/**
			 * @param string $sql SQL query.
			 * @return array<int, string>
			 */
			public function get_col( string $sql ): array { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- Stub.
				return $this->col_rows;
			}
		};
	}

	// -------------------------------------------------------------------------
	// SIZE-01: update_sizes_audit() issues INSERT … ON DUPLICATE KEY UPDATE
	// -------------------------------------------------------------------------

	/**
	 * update_sizes_audit($id, $json) issues an INSERT … ON DUPLICATE KEY UPDATE
	 * touching only the sizes_audit column for the given attachment_id (SIZE-01 / D-06).
	 *
	 * The method must exist and call $wpdb->query() with an SQL string containing
	 * both INSERT and ON DUPLICATE KEY UPDATE (upsert pattern).
	 *
	 * @return void
	 */
	public function test_update_sizes_audit_upserts(): void {
		$wpdb  = $this->make_wpdb_stub();
		$index = new OptimizationIndex( $wpdb );

		$json = json_encode( array(
			'missing'    => array( 'thumbnail' ),
			'orphaned'   => array(),
			'scanned_at' => '2026-06-12 12:00:00',
		) );

		$this->assertTrue(
			method_exists( $index, 'update_sizes_audit' ),
			'OptimizationIndex must have an update_sizes_audit(int, string) method (SIZE-01 / D-06)'
		);

		$index->update_sizes_audit( 42, (string) $json );

		$this->assertNotEmpty(
			$wpdb->queries,
			'update_sizes_audit() must issue a SQL query via $wpdb->query()'
		);

		$sql = implode( ' ', $wpdb->queries );

		$this->assertStringContainsStringIgnoringCase(
			'INSERT',
			$sql,
			'update_sizes_audit() must use INSERT (upsert) — not bare UPDATE (SIZE-01 / D-06)'
		);

		$this->assertStringContainsStringIgnoringCase(
			'ON DUPLICATE KEY UPDATE',
			$sql,
			'update_sizes_audit() must use ON DUPLICATE KEY UPDATE for upsert semantics (SIZE-01 / D-06)'
		);

		$this->assertStringContainsString(
			'sizes_audit',
			$sql,
			'update_sizes_audit() SQL must reference the sizes_audit column (SIZE-01 / D-06)'
		);
	}

	// -------------------------------------------------------------------------
	// SIZE-01: get_sizes_audit_summary() returns correct counts, ignoring '{}' rows
	// -------------------------------------------------------------------------

	/**
	 * get_sizes_audit_summary() decodes each non-empty JSON row and returns:
	 *   - audited_count: number of rows with non-empty sizes_audit JSON
	 *   - missing_count: number of attachments with at least one missing size
	 *   - orphaned_count: number of attachments with at least one orphaned file
	 *
	 * '{}' rows (not-yet-audited column default) must be ignored (SIZE-01 / Pitfall 2).
	 *
	 * @return void
	 */
	public function test_get_sizes_audit_summary(): void {
		// Three seeded rows: two audited (one with missing, one with orphaned),
		// plus one '{}' (not yet audited — must be filtered by the WHERE clause,
		// but this test also validates the PHP-level decode loop handles it gracefully).
		$rows = array(
			json_encode( array(
				'missing'    => array( 'thumbnail', 'medium_large' ),
				'orphaned'   => array(),
				'scanned_at' => '2026-06-12 10:00:00',
			) ),
			json_encode( array(
				'missing'    => array(),
				'orphaned'   => array( 'photo-400x300.jpg' ),
				'scanned_at' => '2026-06-12 11:00:00',
			) ),
			// '{}' should be ignored by the WHERE clause; but if it slips through,
			// the PHP loop must also handle it gracefully.
			'{}',
		);

		$wpdb  = $this->make_wpdb_stub( $rows );
		$index = new OptimizationIndex( $wpdb );

		$this->assertTrue(
			method_exists( $index, 'get_sizes_audit_summary' ),
			'OptimizationIndex must have a get_sizes_audit_summary() method (SIZE-01 / D-06)'
		);

		$summary = $index->get_sizes_audit_summary();

		$this->assertIsArray( $summary, 'get_sizes_audit_summary() must return an array' );

		$this->assertArrayHasKey(
			'audited_count',
			$summary,
			'get_sizes_audit_summary() must return "audited_count" key (SIZE-01)'
		);
		$this->assertArrayHasKey(
			'missing_count',
			$summary,
			'get_sizes_audit_summary() must return "missing_count" key (SIZE-01)'
		);
		$this->assertArrayHasKey(
			'orphaned_count',
			$summary,
			'get_sizes_audit_summary() must return "orphaned_count" key (SIZE-01)'
		);

		// Two rows have scanned_at (genuinely audited); '{}' row has no scanned_at.
		$this->assertSame(
			2,
			$summary['audited_count'],
			'audited_count must equal 2 (two rows with scanned_at); {} row ignored (SIZE-01)'
		);

		// One row has a non-empty missing array.
		$this->assertSame(
			1,
			$summary['missing_count'],
			'missing_count must equal 1 (one row with non-empty missing array) (SIZE-01)'
		);

		// One row has a non-empty orphaned array.
		$this->assertSame(
			1,
			$summary['orphaned_count'],
			'orphaned_count must equal 1 (one row with non-empty orphaned array) (SIZE-01)'
		);
	}
}
