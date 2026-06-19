<?php
/**
 * Unit tests for LossyAck helper (FND-06).
 *
 * These tests are RED until Plan 02 implements LossyAck. They define the
 * required behaviour; Plan 02 turns them GREEN.
 *
 * @package AssetDrips
 */

declare( strict_types=1 );

namespace AssetDrips\Tests\Unit\Squeeze;

use AssetDrips\Squeeze\LossyAck;
use PHPUnit\Framework\TestCase;

/**
 * Tests the LossyAck one-time lossy-bulk acknowledgement helper.
 *
 * All state is backed by the in-memory option stub from unit-bootstrap.php.
 */
final class LossyAckTest extends TestCase {

	/**
	 * Reset the in-memory options stub before each test.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$GLOBALS['_assetdrips_options_stub'] = array();
	}

	/**
	 * is_acknowledged() returns false before record() is called (FND-06).
	 *
	 * @return void
	 */
	public function test_is_not_acknowledged_before_record(): void {
		$this->assertFalse( LossyAck::is_acknowledged() );
	}

	/**
	 * record() persists the acknowledgement; is_acknowledged() then returns true (FND-06).
	 *
	 * @return void
	 */
	public function test_record_and_check(): void {
		LossyAck::record( 1 );

		$this->assertTrue( LossyAck::is_acknowledged() );
	}

	/**
	 * get() returns null before record() is called (FND-06).
	 *
	 * @return void
	 */
	public function test_get_returns_null_before_record(): void {
		$this->assertNull( LossyAck::get() );
	}

	/**
	 * get() returns an array with user_id and acknowledged_at after record() (FND-06).
	 *
	 * @return void
	 */
	public function test_get_returns_array_after_record(): void {
		LossyAck::record( 42 );

		$data = LossyAck::get();

		$this->assertIsArray( $data );
		$this->assertArrayHasKey( 'user_id', $data );
		$this->assertArrayHasKey( 'acknowledged_at', $data );
		$this->assertSame( 42, $data['user_id'] );
	}

	/**
	 * revoke() clears the acknowledgement; is_acknowledged() returns false again (FND-06).
	 *
	 * @return void
	 */
	public function test_revoke_clears_acknowledgement(): void {
		LossyAck::record( 1 );
		$this->assertTrue( LossyAck::is_acknowledged() );

		LossyAck::revoke();

		$this->assertFalse( LossyAck::is_acknowledged() );
	}
}
