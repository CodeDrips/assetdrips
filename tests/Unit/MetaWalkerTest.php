<?php
/**
 * MetaWalker tests.
 *
 * @package AssetDrips
 */

declare( strict_types=1 );

namespace AssetDrips\Tests\Unit;

use AssetDrips\Inventory\MatchKeys;
use AssetDrips\Inventory\ReferenceIndex;
use AssetDrips\Scan\MetaWalker;
use AssetDrips\Usage\UsageHit;
use AssetDrips\Usage\UsageMap;
use PHPUnit\Framework\TestCase;

/**
 * Pure tests for recursive meta/option traversal.
 */
final class MetaWalkerTest extends TestCase {

	/**
	 * Build a walker over an index seeded with attachments 7 and 8.
	 *
	 * @return MetaWalker
	 */
	private function walker(): MetaWalker {
		$index = new ReferenceIndex( array( '/wp-content/uploads' ) );
		$index->register( new MatchKeys( 7, array(), array(), array( '2023/01/a.jpg' ) ) );
		$index->register( new MatchKeys( 8, array(), array(), array( '2023/01/b.jpg' ) ) );

		return new MetaWalker( $index );
	}

	/**
	 * A URL string leaf resolves to a URL hit regardless of key scope.
	 *
	 * @return void
	 */
	public function test_url_string_leaf(): void {
		$map = new UsageMap();
		$this->walker()->walk( 'see https://example.com/wp-content/uploads/2023/01/a.jpg here', 'meta:1', 'postmeta', $map );

		$this->assertTrue( $map->is_used( 7 ) );
		$this->assertSame( UsageHit::MATCH_URL, $map->hits_for( 7 )[0]->match_type() );
	}

	/**
	 * Embedded media markup in a rich-text leaf resolves by ID.
	 *
	 * @return void
	 */
	public function test_embedded_markup_in_string_leaf(): void {
		$map = new UsageMap();
		$this->walker()->walk( '<figure><img class="wp-image-8" /></figure>', 'meta:1', 'acf', $map );

		$this->assertTrue( $map->is_used( 8 ) );
		$this->assertSame( UsageHit::MATCH_ID, $map->hits_for( 8 )[0]->match_type() );
	}

	/**
	 * A numeric leaf is an ID reference only inside a media-related key scope.
	 *
	 * @return void
	 */
	public function test_numeric_leaf_requires_image_scope(): void {
		$in_scope = new UsageMap();
		$this->walker()->walk( 8, 'meta:1', 'postmeta', $in_scope, true );
		$this->assertTrue( $in_scope->is_used( 8 ) );

		$out_of_scope = new UsageMap();
		$this->walker()->walk( 8, 'meta:1', 'postmeta', $out_of_scope, false );
		$this->assertFalse( $out_of_scope->is_used( 8 ) );
	}

	/**
	 * A media-named key brings its nested IDs into scope; a neutral key does not.
	 *
	 * @return void
	 */
	public function test_image_key_scopes_nested_ids(): void {
		$used = new UsageMap();
		$this->walker()->walk( array( 'gallery_ids' => array( 7, 8 ) ), 'meta:1', 'postmeta', $used );
		$this->assertTrue( $used->is_used( 7 ) );
		$this->assertTrue( $used->is_used( 8 ) );

		$unused = new UsageMap();
		$this->walker()->walk( array( 'sort_order' => array( 7, 8 ) ), 'meta:1', 'postmeta', $unused );
		$this->assertFalse( $unused->is_used( 7 ) );
		$this->assertFalse( $unused->is_used( 8 ) );
	}

	/**
	 * A CSV of IDs inside a media-named key resolves each ID.
	 *
	 * @return void
	 */
	public function test_csv_ids_in_image_key(): void {
		$map = new UsageMap();
		$this->walker()->walk( array( 'hero_image' => '7, 8' ), 'meta:1', 'postmeta', $map );

		$this->assertTrue( $map->is_used( 7 ) );
		$this->assertTrue( $map->is_used( 8 ) );
	}

	/**
	 * Unknown IDs in a media key are not recorded.
	 *
	 * @return void
	 */
	public function test_unknown_ids_not_recorded(): void {
		$map = new UsageMap();
		$this->walker()->walk( array( 'image_id' => 999 ), 'meta:1', 'postmeta', $map );

		$this->assertSame( array(), $map->used_ids() );
	}

	/**
	 * Key heuristic recognises common media key names.
	 *
	 * @return void
	 */
	public function test_looks_like_image_key(): void {
		$walker = $this->walker();

		$this->assertTrue( $walker->looks_like_image_key( '_thumbnail_id' ) );
		$this->assertTrue( $walker->looks_like_image_key( 'hero_background' ) );
		$this->assertTrue( $walker->looks_like_image_key( 'company_logo' ) );
		$this->assertFalse( $walker->looks_like_image_key( 'sort_order' ) );
		$this->assertFalse( $walker->looks_like_image_key( 'price' ) );
	}
}
