<?php
/**
 * Contract for all scanners.
 *
 * @package AssetDrips
 */

declare( strict_types=1 );

namespace AssetDrips\Scan;

use AssetDrips\Usage\UsageMap;

defined( 'ABSPATH' ) || exit;

/**
 * A scanner inspects one class of data source and records usage evidence.
 *
 * Scanners are independent and additive: each resolves references against a
 * shared {@see \AssetDrips\Inventory\ReferenceIndex} (injected at construction)
 * and writes {@see \AssetDrips\Usage\UsageHit}s into the shared map. Adding a
 * scanner never requires touching the scorer. A later crawl-based source can
 * implement this same contract.
 */
interface ScannerInterface {

	/**
	 * Stable source identifier recorded on every hit (e.g. "content").
	 *
	 * @return string
	 */
	public function source(): string;

	/**
	 * Scan the data source and append evidence to the map.
	 *
	 * The optional progress callback is invoked periodically with the scanner's
	 * source, the number of rows processed so far, and the total when known
	 * (null when the total is not cheaply countable).
	 *
	 * @param UsageMap                              $into     Shared evidence store to append to.
	 * @param callable(string, int, ?int):void|null $progress Optional progress reporter.
	 * @return void
	 */
	public function scan( UsageMap $into, ?callable $progress = null ): void;
}
