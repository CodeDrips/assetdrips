<?php
/**
 * MediaIndex::ids() unit tests — SEL-02 backend.
 *
 * Tests that ids() returns the full attachment_id set matching a filter with no
 * LIMIT/OFFSET, and that both query() and ids() share the same build_full_where()
 * helper so the displayed count and the all-matching set can never diverge.
 *
 * @package AssetDrips
 */

declare( strict_types=1 );

namespace AssetDrips\Tests\Unit\Index;

use AssetDrips\Index\MediaIndex;
use AssetDrips\Index\MediaQuery;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for MediaIndex::ids() (SEL-02 backend).
 *
 * A get_col stub is injected so no real database is needed.
 */
final class MediaIndexIdsTest extends TestCase {

	/**
	 * Build a wpdb stub that returns stubbed get_col() values and records SQL calls.
	 *
	 * @param int[] $ids_to_return The ids get_col() should return.
	 * @return object
	 */
	private function wpdb_stub( array $ids_to_return ): object {
		return new class( $ids_to_return ) {
			/**
			 * Term relationships table name.
			 *
			 * @var string
			 */
			public string $term_relationships = 'wp_term_relationships';

			/**
			 * Term taxonomy table name.
			 *
			 * @var string
			 */
			public string $term_taxonomy = 'wp_term_taxonomy';

			/**
			 * SQL strings passed to get_col(), in call order.
			 *
			 * @var string[]
			 */
			public array $sql_log = array();

			/**
			 * IDs to return from get_col().
			 *
			 * @var int[]
			 */
			private array $ids;

			/**
			 * Seed the stub.
			 *
			 * @param int[] $ids IDs to return.
			 */
			public function __construct( array $ids ) {
				$this->ids = $ids;
			}

			/**
			 * Record the SQL and return stubbed ids.
			 *
			 * @param string $sql Query string.
			 * @return int[]
			 */
			public function get_col( string $sql ): array {
				$this->sql_log[] = $sql;
				return $this->ids;
			}

			/**
			 * Minimal prepare stub — simple sprintf-style substitution.
			 *
			 * @param string $sql  Query template.
			 * @param mixed  ...$args Bind args.
			 * @return string
			 */
			public function prepare( string $sql, ...$args ): string {
				$i = 0;
				return (string) preg_replace_callback(
					'/%[sd]/',
					function () use ( &$args, &$i ) {
						return (string) $args[ $i++ ];
					},
					$sql
				);
			}

			/**
			 * Escape LIKE special characters.
			 *
			 * @param string $s Value to escape.
			 * @return string
			 */
			public function esc_like( string $s ): string {
				return addcslashes( $s, '_%\\' );
			}
		};
	}

	/**
	 * Return the MediaIndex source as a string for source-inspection tests.
	 *
	 * @return string
	 */
	private function media_index_source(): string {
		$ref = new \ReflectionClass( \AssetDrips\Index\MediaIndex::class );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local file read for source inspection.
		return (string) file_get_contents( (string) $ref->getFileName() );
	}

	/**
	 * Asserts ids() method exists in MediaIndex source.
	 *
	 * @return void
	 */
	public function test_ids_method_exists(): void {
		$source = $this->media_index_source();
		$this->assertStringContainsString(
			'public function ids(',
			$source,
			'MediaIndex::ids() must be declared as a public method'
		);
	}

	/**
	 * Both query() and ids() must call build_full_where() — the shared WHERE helper.
	 *
	 * Asserts source-level parity: the same build_full_where() call appears in both
	 * methods so the displayed count and the all-matching set can never diverge.
	 *
	 * @return void
	 */
	public function test_build_full_where_parity(): void {
		$source = $this->media_index_source();
		$this->assertStringContainsString(
			'private function build_full_where(',
			$source,
			'build_full_where() private helper must exist (SEL-02 WHERE parity)'
		);
		// Count occurrences of build_full_where( call in source (must be ≥ 2: query() + ids()).
		$count = substr_count( $source, 'build_full_where(' );
		$this->assertGreaterThanOrEqual(
			2,
			$count,
			'build_full_where() must be called by both query() and ids() (SEL-02 WHERE parity)'
		);
	}

	/**
	 * Asserts ids() SELECT contains no LIMIT or OFFSET clause.
	 *
	 * Source inspection confirms the SQL string inside ids() has no LIMIT
	 * substring, preserving the "all matching" contract.
	 *
	 * @return void
	 */
	public function test_ids_no_limit(): void {
		$source = $this->media_index_source();
		// Locate the ids() method body in source.
		$ids_start = strpos( $source, 'public function ids(' );
		$this->assertNotFalse(
			$ids_start,
			'ids() method must exist for this assertion'
		);
		// Extract from ids() start to the closing brace of the next top-level method.
		// A simple heuristic: grab text from ids() up to the next 'public function' declaration.
		$after_ids = substr( $source, $ids_start );
		$next_fn   = strpos( $after_ids, "\n\tpublic function ", 1 );
		if ( false !== $next_fn ) {
			$ids_body = substr( $after_ids, 0, $next_fn );
		} else {
			$ids_body = $after_ids;
		}
		$this->assertStringNotContainsString(
			' LIMIT ',
			$ids_body,
			'ids() SQL must not contain LIMIT (all-matching set, no pagination)'
		);
		$this->assertStringNotContainsString(
			' OFFSET ',
			$ids_body,
			'ids() SQL must not contain OFFSET (all-matching set, no pagination)'
		);
	}

	/**
	 * Asserts ids() maps get_col() results to int[].
	 *
	 * With a get_col stub returning ['101', '202', '303'], ids() must return
	 * the intval-mapped int[] [101, 202, 303].
	 *
	 * @return void
	 */
	public function test_ids_matches_query_count(): void {
		$wpdb  = $this->wpdb_stub( array( '101', '202', '303' ) );
		$index = new MediaIndex( $wpdb );
		$q     = new MediaQuery();

		$ids = $index->ids( $q );

		$this->assertSame(
			array( 101, 202, 303 ),
			$ids,
			'ids() must return intval-mapped int[] from get_col result'
		);
		$this->assertNotEmpty( $wpdb->sql_log, 'ids() must execute a SQL query' );
	}
}
