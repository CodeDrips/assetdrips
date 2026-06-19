<?php
/**
 * Tier enum tests.
 *
 * @package AssetDrips
 */

declare( strict_types=1 );

namespace AssetDrips\Tests\Unit;

use AssetDrips\Score\Tier;
use PHPUnit\Framework\TestCase;

/**
 * Pure tests for the tier enum behaviour.
 */
final class TierTest extends TestCase {

	/**
	 * Only HIGH is self-serve deletable.
	 *
	 * @return void
	 */
	public function test_only_high_is_self_serve(): void {
		$this->assertTrue( Tier::HIGH->is_self_serve() );
		$this->assertFalse( Tier::USED->is_self_serve() );
		$this->assertFalse( Tier::MEDIUM->is_self_serve() );
		$this->assertFalse( Tier::LOW->is_self_serve() );
	}

	/**
	 * Everything except USED is a deletion candidate.
	 *
	 * @return void
	 */
	public function test_candidate_excludes_used(): void {
		$this->assertFalse( Tier::USED->is_candidate() );
		$this->assertTrue( Tier::HIGH->is_candidate() );
		$this->assertTrue( Tier::MEDIUM->is_candidate() );
		$this->assertTrue( Tier::LOW->is_candidate() );
	}

	/**
	 * MEDIUM and LOW require explicit human action.
	 *
	 * @return void
	 */
	public function test_human_action_required(): void {
		$this->assertTrue( Tier::MEDIUM->requires_human_action() );
		$this->assertTrue( Tier::LOW->requires_human_action() );
		$this->assertFalse( Tier::HIGH->requires_human_action() );
		$this->assertFalse( Tier::USED->requires_human_action() );
	}

	/**
	 * Confidence decreases from USED through LOW.
	 *
	 * @return void
	 */
	public function test_confidence_is_monotonic(): void {
		$this->assertGreaterThan( Tier::HIGH->confidence(), Tier::USED->confidence() );
		$this->assertGreaterThan( Tier::MEDIUM->confidence(), Tier::HIGH->confidence() );
		$this->assertGreaterThan( Tier::LOW->confidence(), Tier::MEDIUM->confidence() );
	}

	/**
	 * The backing values are the documented tier names.
	 *
	 * @return void
	 */
	public function test_backed_values(): void {
		$this->assertSame( 'USED', Tier::USED->value );
		$this->assertSame( 'HIGH', Tier::HIGH->value );
		$this->assertSame( 'MEDIUM', Tier::MEDIUM->value );
		$this->assertSame( 'LOW', Tier::LOW->value );
	}
}
