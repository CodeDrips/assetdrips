<?php
/**
 * MatchKeys value-object tests.
 *
 * @package AssetDrips
 */

declare( strict_types=1 );

namespace AssetDrips\Tests\Unit;

use AssetDrips\Inventory\MatchKeys;
use PHPUnit\Framework\TestCase;

/**
 * Pure unit tests for the MatchKeys value object.
 */
final class MatchKeysTest extends TestCase {

	/**
	 * Accessors return what was passed in, deduplicated.
	 *
	 * @return void
	 */
	public function test_accessors_and_dedup(): void {
		$keys = new MatchKeys(
			42,
			array( 'https://e.com/a.jpg', 'https://e.com/a.jpg', '/a.jpg' ),
			array( '/srv/a.jpg', '/srv/a.jpg' ),
			array( '2023/05/a.jpg' )
		);

		$this->assertSame( 42, $keys->id() );
		$this->assertSame( array( 'https://e.com/a.jpg', '/a.jpg' ), $keys->urls() );
		$this->assertSame( array( '/srv/a.jpg' ), $keys->paths() );
		$this->assertSame( array( '2023/05/a.jpg' ), $keys->relative_paths() );
	}

	/**
	 * Empty only with no URL and no path tokens.
	 *
	 * @return void
	 */
	public function test_is_empty(): void {
		$this->assertTrue( ( new MatchKeys( 1, array(), array(), array() ) )->is_empty() );
		$this->assertFalse( ( new MatchKeys( 1, array( '/a.jpg' ), array(), array() ) )->is_empty() );
		$this->assertFalse( ( new MatchKeys( 1, array(), array( '/srv/a.jpg' ), array() ) )->is_empty() );
	}

	/**
	 * Array form round-trips the data.
	 *
	 * @return void
	 */
	public function test_to_array(): void {
		$keys = new MatchKeys( 7, array( 'u' ), array( 'p' ), array( 'r' ) );

		$this->assertSame(
			array(
				'id'             => 7,
				'urls'           => array( 'u' ),
				'paths'          => array( 'p' ),
				'relative_paths' => array( 'r' ),
			),
			$keys->to_array()
		);
	}
}
