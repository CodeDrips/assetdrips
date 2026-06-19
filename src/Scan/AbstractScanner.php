<?php
/**
 * Shared base for DB-backed scanners.
 *
 * @package AssetDrips
 */

declare( strict_types=1 );

namespace AssetDrips\Scan;

use AssetDrips\Inventory\ReferenceIndex;

defined( 'ABSPATH' ) || exit;

/**
 * Common wiring for scanners: the database handle, the shared reference index,
 * and a pure {@see MetaWalker}. Concrete scanners implement source() and scan().
 */
abstract class AbstractScanner implements ScannerInterface {

	/**
	 * Database handle. Nullable so pure unit tests can construct a scanner.
	 *
	 * @var \wpdb|null
	 */
	protected ?\wpdb $wpdb;

	/**
	 * Shared reference resolver.
	 *
	 * @var ReferenceIndex
	 */
	protected ReferenceIndex $index;

	/**
	 * Recursive value walker.
	 *
	 * @var MetaWalker
	 */
	protected MetaWalker $walker;

	/**
	 * Construct with a database handle and the shared index.
	 *
	 * @param \wpdb|null     $wpdb  Database handle (null for pure unit use).
	 * @param ReferenceIndex $index Shared reference resolver.
	 */
	public function __construct( ?\wpdb $wpdb, ReferenceIndex $index ) {
		$this->wpdb   = $wpdb;
		$this->index  = $index;
		$this->walker = new MetaWalker( $index );
	}

	/**
	 * Emit a progress report, if a reporter was provided.
	 *
	 * @param callable(string, int, ?int):void|null $progress Reporter.
	 * @param int                                   $done     Rows processed so far.
	 * @param int|null                              $total    Total rows, or null if unknown.
	 * @return void
	 */
	protected function report( ?callable $progress, int $done, ?int $total ): void {
		if ( null !== $progress ) {
			$progress( $this->source(), $done, $total );
		}
	}

	/**
	 * Count rows for a simple aggregate query, for progress totals.
	 *
	 * @param string $sql A COUNT(*) query.
	 * @return int
	 */
	protected function count( string $sql ): int {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared -- Fixed COUNT query for a progress total; no user input.
		return (int) $this->db()->get_var( $sql );
	}

	/**
	 * Guard DB-backed work against pure-unit construction.
	 *
	 * @return \wpdb
	 *
	 * @throws \RuntimeException When constructed without a database handle.
	 */
	protected function db(): \wpdb {
		if ( null === $this->wpdb ) {
			throw new \RuntimeException( static::class . ' requires a database handle to scan.' );
		}

		return $this->wpdb;
	}
}
