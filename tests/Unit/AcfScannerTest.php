<?php
/**
 * AcfScanner field-type scoping tests.
 *
 * @package AssetDrips
 */

declare( strict_types=1 );

namespace AssetDrips\Tests\Unit;

use AssetDrips\Scan\AcfScanner;
use PHPUnit\Framework\TestCase;

/**
 * Pure tests for ACF field-type media scoping.
 */
final class AcfScannerTest extends TestCase {

	/**
	 * Media and post-reference field types resolve in media scope.
	 *
	 * @return void
	 */
	public function test_media_types_are_in_scope(): void {
		foreach ( array( 'image', 'file', 'gallery', 'post_object', 'relationship' ) as $type ) {
			$this->assertTrue( AcfScanner::is_media_scope_type( $type ), "{$type} should be media scope." );
		}
	}

	/**
	 * Known non-media field types are out of media scope (URLs only).
	 *
	 * @return void
	 */
	public function test_non_media_types_are_out_of_scope(): void {
		foreach ( array( 'text', 'textarea', 'number', 'email', 'true_false', 'select' ) as $type ) {
			$this->assertFalse( AcfScanner::is_media_scope_type( $type ), "{$type} should not be media scope." );
		}
	}

	/**
	 * Unknown (local JSON / PHP) field types default to media scope — the safe,
	 * conservative direction.
	 *
	 * @return void
	 */
	public function test_unknown_type_defaults_to_media_scope(): void {
		$this->assertTrue( AcfScanner::is_media_scope_type( null ) );
	}
}
