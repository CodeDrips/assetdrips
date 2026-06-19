<?php
/**
 * ReferenceIndex tests.
 *
 * @package AssetDrips
 */

declare( strict_types=1 );

namespace AssetDrips\Tests\Unit;

use AssetDrips\Inventory\MatchKeys;
use AssetDrips\Inventory\ReferenceIndex;
use PHPUnit\Framework\TestCase;

/**
 * Pure tests for resolving references to attachment IDs.
 */
final class ReferenceIndexTest extends TestCase {

	/**
	 * Build an index seeded with one variant-rich attachment.
	 *
	 * @return ReferenceIndex
	 */
	private function index(): ReferenceIndex {
		$index = new ReferenceIndex( array( '/wp-content/uploads', '/var/www/site/wp-content/uploads' ) );
		$index->register(
			new MatchKeys(
				101,
				array(),
				array(),
				array( '2023/05/sunset.jpg', '2023/05/sunset-300x200.jpg', '2023/05/sunset-1024x683.jpg' )
			)
		);

		return $index;
	}

	/**
	 * A reference to any variant resolves to the parent attachment.
	 *
	 * @return void
	 */
	public function test_resolves_any_variant_to_parent(): void {
		$index = $this->index();

		$this->assertSame( 101, $index->match( 'https://example.com/wp-content/uploads/2023/05/sunset.jpg' ) );
		$this->assertSame( 101, $index->match( '/wp-content/uploads/2023/05/sunset-300x200.jpg' ) );
		$this->assertSame( 101, $index->match( '2023/05/sunset-1024x683.jpg' ) );
	}

	/**
	 * Unknown references resolve to null.
	 *
	 * @return void
	 */
	public function test_unknown_reference_is_null(): void {
		$this->assertNull( $this->index()->match( 'https://example.com/wp-content/uploads/2023/05/other.jpg' ) );
	}

	/**
	 * Filesystem case differences still resolve (conservative direction).
	 *
	 * @return void
	 */
	public function test_case_insensitive_fallback(): void {
		$this->assertSame(
			101,
			$this->index()->match( 'https://example.com/wp-content/uploads/2023/05/Sunset-300x200.JPG' )
		);
	}

	/**
	 * Embedded URLs in text resolve to attachment IDs with evidence.
	 *
	 * @return void
	 */
	public function test_find_in_text(): void {
		$html = '<img src="https://example.com/wp-content/uploads/2023/05/sunset-300x200.jpg">';

		$this->assertSame(
			array( 101 => '2023/05/sunset-300x200.jpg' ),
			$this->index()->find_in_text( $html )
		);
	}

	/**
	 * Only IDs that are known attachments are returned from text.
	 *
	 * @return void
	 */
	public function test_ids_in_text_gates_on_known_attachments(): void {
		$index = $this->index();

		// 101 is an attachment; 999 is not.
		$content = '<img class="wp-image-101"> [gallery ids="101, 999"]';

		$this->assertSame( array( 101 ), $index->ids_in_text( $content ) );
	}

	/**
	 * Attachment membership reflects registration; size counts attachments.
	 *
	 * @return void
	 */
	public function test_is_attachment_and_size(): void {
		$index = $this->index();

		$this->assertTrue( $index->is_attachment( 101 ) );
		$this->assertFalse( $index->is_attachment( 102 ) );
		$this->assertSame( 1, $index->size() );
	}

	/**
	 * Prefixes are derived from an uploads location.
	 *
	 * @return void
	 */
	public function test_prefixes_from_uploads(): void {
		$prefixes = ReferenceIndex::prefixes_from_uploads(
			'https://example.com/wp-content/uploads',
			'/var/www/site/wp-content/uploads'
		);

		$this->assertSame(
			array( '/wp-content/uploads', '/var/www/site/wp-content/uploads' ),
			$prefixes
		);
	}
}
