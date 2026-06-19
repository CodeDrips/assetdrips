<?php
/**
 * UsageMap tests.
 *
 * @package AssetDrips
 */

declare( strict_types=1 );

namespace AssetDrips\Tests\Unit;

use AssetDrips\Usage\UsageHit;
use AssetDrips\Usage\UsageMap;
use PHPUnit\Framework\TestCase;

/**
 * Pure tests for the evidence store.
 */
final class UsageMapTest extends TestCase {

	/**
	 * Build a hit for an attachment.
	 *
	 * @param int $id Attachment ID.
	 * @return UsageHit
	 */
	private function hit( int $id ): UsageHit {
		return new UsageHit( $id, 'content', 'post:1:post_content', UsageHit::MATCH_URL, '2023/01/a.jpg' );
	}

	/**
	 * Adding hits records usage and groups by attachment.
	 *
	 * @return void
	 */
	public function test_add_and_query(): void {
		$map = new UsageMap();
		$map->add( $this->hit( 5 ) );
		$map->add( $this->hit( 5 ) );

		$this->assertTrue( $map->is_used( 5 ) );
		$this->assertFalse( $map->is_used( 6 ) );
		$this->assertSame( 2, $map->count_for( 5 ) );
		$this->assertSame( array( 5 ), $map->used_ids() );
	}

	/**
	 * Merge folds another map's hits in.
	 *
	 * @return void
	 */
	public function test_merge(): void {
		$a = new UsageMap();
		$a->add( $this->hit( 5 ) );

		$b = new UsageMap();
		$b->add( $this->hit( 5 ) );
		$b->add( $this->hit( 9 ) );

		$a->merge( $b );

		$this->assertSame( 2, $a->count_for( 5 ) );
		$this->assertTrue( $a->is_used( 9 ) );
	}

	/**
	 * Evidence is exported as plain arrays for storage.
	 *
	 * @return void
	 */
	public function test_evidence_for(): void {
		$map = new UsageMap();
		$map->add( $this->hit( 5 ) );

		$evidence = $map->evidence_for( 5 );

		$this->assertCount( 1, $evidence );
		$this->assertSame( 'content', $evidence[0]['source'] );
		$this->assertSame( UsageHit::MATCH_URL, $evidence[0]['match_type'] );
		$this->assertSame( '2023/01/a.jpg', $evidence[0]['evidence'] );
	}
}
