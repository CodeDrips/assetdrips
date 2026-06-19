<?php
/**
 * RED tests for MediaQuery::$squeeze_state + MediaIndex::build_full_where()
 * squeeze-state WHERE clauses (DASH-05).
 *
 * These tests are RED until Wave 1 adds $squeeze_state to MediaQuery and the
 * corresponding switch block to MediaIndex::build_full_where().  Wave 1 turns
 * them GREEN.
 *
 * EXIT_EXPECTED_RED: Tests WILL fail because $squeeze_state does not exist on
 * MediaQuery yet.  Failures must be property-access or assertion failures —
 * NOT parse/bootstrap fatals.
 *
 * Strategy: construct a MediaIndex with a capturing wpdb stub, set
 * $q->squeeze_state, call ids($q) (which internally calls build_full_where()),
 * and assert the SQL captured by get_col() contains the expected fragment.
 * This mirrors the MediaIndexIdsTest capture pattern.
 *
 * @package AssetDrips
 */

declare( strict_types=1 );

namespace AssetDrips\Tests\Unit\Index;

use AssetDrips\Index\MediaIndex;
use AssetDrips\Index\MediaQuery;
use PHPUnit\Framework\TestCase;

/**
 * RED tests: squeeze_state WHERE clause contracts in build_full_where() (DASH-05).
 */
final class MediaQuerySqueezeStateTest extends TestCase {

	/**
	 * Reset wpdb stub state before each test.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		if ( isset( $GLOBALS['wpdb'] ) ) {
			$GLOBALS['wpdb']->next_get_row  = null;
			$GLOBALS['wpdb']->get_row_queue = array();
		}
	}

	/**
	 * Build a wpdb stub that captures SQL passed to get_col().
	 *
	 * @return object Stub with public sql_log property.
	 */
	private function make_capturing_wpdb(): object {
		return new class() {
			/** @var string */
			public string $prefix = 'wp_';
			/** @var string */
			public string $term_relationships = 'wp_term_relationships';
			/** @var string */
			public string $term_taxonomy = 'wp_term_taxonomy';

			/**
			 * SQL strings passed to get_col(), in call order.
			 *
			 * @var string[]
			 */
			public array $sql_log = array();

			/**
			 * @param string $sql  SQL template.
			 * @param mixed  ...$args Bound values.
			 * @return string
			 */
			public function prepare( string $sql, mixed ...$args ): string {
				// Interpolate args so sql_log contains bound values for assertion.
				$result = $sql;
				foreach ( $args as $arg ) {
					$result = preg_replace( '/%[sdf]/', (string) $arg, $result, 1 ) ?? $result;
				}
				return $result;
			}

			/**
			 * Escape LIKE special characters.
			 *
			 * @param string $s Value.
			 * @return string
			 */
			public function esc_like( string $s ): string {
				return addcslashes( $s, '_%\\' );
			}

			/**
			 * Record the SQL and return empty array (no DB in unit tests).
			 *
			 * @param string $sql Query string.
			 * @return int[]
			 */
			public function get_col( string $sql ): array {
				$this->sql_log[] = $sql;
				return array();
			}
		};
	}

	// -------------------------------------------------------------------------
	// DASH-05: 'not-optimized' — NOT IN subquery against assetdrips_squeeze
	// -------------------------------------------------------------------------

	/**
	 * squeeze_state='not-optimized' produces a WHERE clause with NOT IN and
	 * the 'complete' status bound as the filter value.
	 *
	 * RED: Fails because $squeeze_state does not exist on MediaQuery yet.
	 *
	 * @return void
	 */
	public function test_not_optimized_produces_not_in_subquery_with_complete_status(): void {
		$wpdb  = $this->make_capturing_wpdb();
		$index = new MediaIndex( $wpdb );

		$q                = new MediaQuery();
		$q->squeeze_state = 'not-optimized'; // RED: property does not exist yet.

		$index->ids( $q );

		$sql = implode( ' ', $wpdb->sql_log );

		$this->assertStringContainsString(
			'NOT IN',
			$sql,
			'squeeze_state=not-optimized must produce a NOT IN subquery (DASH-05)'
		);

		$this->assertStringContainsString(
			'complete',
			$sql,
			'squeeze_state=not-optimized NOT IN subquery must bind status=complete (DASH-05)'
		);
	}

	// -------------------------------------------------------------------------
	// DASH-05: 'oversized' — is_oversized = 1 direct flag column
	// -------------------------------------------------------------------------

	/**
	 * squeeze_state='oversized' produces a WHERE clause checking is_oversized = 1.
	 *
	 * RED: Fails because $squeeze_state does not exist on MediaQuery yet.
	 *
	 * @return void
	 */
	public function test_oversized_produces_is_oversized_equals_one_clause(): void {
		$wpdb  = $this->make_capturing_wpdb();
		$index = new MediaIndex( $wpdb );

		$q                = new MediaQuery();
		$q->squeeze_state = 'oversized'; // RED: property does not exist yet.

		$index->ids( $q );

		$sql = implode( ' ', $wpdb->sql_log );

		$this->assertStringContainsString(
			'is_oversized',
			$sql,
			'squeeze_state=oversized must check the is_oversized flag column (DASH-05)'
		);

		$this->assertMatchesRegularExpression(
			'/is_oversized\s*=\s*1/',
			$sql,
			'squeeze_state=oversized must bind is_oversized = 1 (DASH-05)'
		);
	}

	// -------------------------------------------------------------------------
	// DASH-05: 'missing-webp' — has_webp = 0 AND mime LIKE 'image/%'
	// -------------------------------------------------------------------------

	/**
	 * squeeze_state='missing-webp' produces a WHERE clause checking has_webp = 0
	 * AND mime LIKE 'image/%' (scoped to image rows only — Pitfall 4 guard).
	 *
	 * RED: Fails because $squeeze_state does not exist on MediaQuery yet.
	 *
	 * @return void
	 */
	public function test_missing_webp_produces_has_webp_zero_and_image_mime_scope(): void {
		$wpdb  = $this->make_capturing_wpdb();
		$index = new MediaIndex( $wpdb );

		$q                = new MediaQuery();
		$q->squeeze_state = 'missing-webp'; // RED: property does not exist yet.

		$index->ids( $q );

		$sql = implode( ' ', $wpdb->sql_log );

		$this->assertStringContainsString(
			'has_webp',
			$sql,
			'squeeze_state=missing-webp must check the has_webp flag column (DASH-05)'
		);

		$this->assertMatchesRegularExpression(
			'/has_webp\s*=\s*0/',
			$sql,
			'squeeze_state=missing-webp must bind has_webp = 0 (DASH-05)'
		);

		$this->assertStringContainsString(
			'image/%',
			$sql,
			'squeeze_state=missing-webp must scope to mime LIKE image/% (Pitfall 4 — missing-WebP count scope)'
		);
	}

	// -------------------------------------------------------------------------
	// DASH-05: 'has-backup' — IN subquery against assetdrips_squeeze_backups status='active'
	// -------------------------------------------------------------------------

	/**
	 * squeeze_state='has-backup' produces a WHERE clause with an IN subquery
	 * against assetdrips_squeeze_backups with status='active'.
	 *
	 * RED: Fails because $squeeze_state does not exist on MediaQuery yet.
	 *
	 * @return void
	 */
	public function test_has_backup_produces_in_subquery_against_backups_with_active_status(): void {
		$wpdb  = $this->make_capturing_wpdb();
		$index = new MediaIndex( $wpdb );

		$q                = new MediaQuery();
		$q->squeeze_state = 'has-backup'; // RED: property does not exist yet.

		$index->ids( $q );

		$sql = implode( ' ', $wpdb->sql_log );

		// Must use IN subquery (not NOT IN — has-backup means the attachment HAS a backup).
		$this->assertMatchesRegularExpression(
			'/\bIN\b/i',
			$sql,
			'squeeze_state=has-backup must use an IN subquery (DASH-05)'
		);

		$this->assertStringContainsString(
			'active',
			$sql,
			'squeeze_state=has-backup IN subquery must filter by status=active (DASH-05)'
		);
	}

	// -------------------------------------------------------------------------
	// DASH-05: unknown value — silently dropped (no WHERE clause added)
	// -------------------------------------------------------------------------

	/**
	 * An unknown squeeze_state value is silently dropped — no WHERE clause is
	 * appended, matching the switch-default defence-in-depth pattern.
	 *
	 * RED: Fails because $squeeze_state does not exist on MediaQuery yet.
	 *
	 * @return void
	 */
	public function test_unknown_squeeze_state_is_silently_dropped(): void {
		$wpdb  = $this->make_capturing_wpdb();
		$index = new MediaIndex( $wpdb );

		$q                = new MediaQuery();
		$q->squeeze_state = 'this-value-does-not-exist'; // RED: property does not exist yet.

		$index->ids( $q );

		$sql = implode( ' ', $wpdb->sql_log );

		// Should produce no extra WHERE clause — the SQL must look like a plain
		// SELECT with no squeeze-specific fragments.
		$this->assertStringNotContainsString(
			'NOT IN',
			$sql,
			'Unknown squeeze_state must not produce a NOT IN clause'
		);

		$this->assertStringNotContainsString(
			'is_oversized',
			$sql,
			'Unknown squeeze_state must not produce an is_oversized clause'
		);

		$this->assertStringNotContainsString(
			'has_webp',
			$sql,
			'Unknown squeeze_state must not produce a has_webp clause'
		);
	}
}
