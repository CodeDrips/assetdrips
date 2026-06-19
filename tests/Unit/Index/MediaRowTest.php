<?php
/**
 * MediaRow pure-derivation tests.
 *
 * These guard the testable seam of the media index: raw attachment inputs in,
 * a structural column-keyed array out, with zero WordPress and zero DB. The
 * derivation rules (orientation, has_alt, mime split, dimension defaults) and
 * the contract that usage/nullable columns are NEVER emitted are the invariants.
 *
 * @package AssetDrips
 */

declare( strict_types=1 );

namespace AssetDrips\Tests\Unit\Index;

use AssetDrips\Index\MediaRow;
use PHPUnit\Framework\TestCase;

/**
 * Pure unit tests for MediaRow::from_attachment() and its helpers.
 */
final class MediaRowTest extends TestCase {

	/**
	 * Orientation classifies the four cases from width/height.
	 *
	 * @return void
	 */
	public function test_orientation_directions(): void {
		$this->assertSame( 'landscape', MediaRow::orientation( 2, 1 ) );
		$this->assertSame( 'portrait', MediaRow::orientation( 1, 2 ) );
		$this->assertSame( 'square', MediaRow::orientation( 5, 5 ) );
	}

	/**
	 * Orientation returns '' when either dimension is unknown (0).
	 *
	 * @return void
	 */
	public function test_orientation_zero_dimensions_are_unknown(): void {
		$this->assertSame( '', MediaRow::orientation( 0, 5 ) );
		$this->assertSame( '', MediaRow::orientation( 5, 0 ) );
	}

	/**
	 * Has_alt is 1 for any non-empty trimmed alt, 0 otherwise.
	 *
	 * @return void
	 */
	public function test_has_alt_reflects_trimmed_alt(): void {
		$with = MediaRow::from_attachment(
			10,
			'a.jpg',
			'A',
			'Some alt text',
			'',
			'',
			'image/jpeg',
			array(
				'width'  => 4,
				'height' => 3,
			),
			100,
			1,
			'2026-06-07 00:00:00',
			'2026-06-07 00:00:00'
		);
		$this->assertSame( 1, $with['has_alt'] );

		$empty = MediaRow::from_attachment(
			11,
			'b.jpg',
			'B',
			'   ',
			'',
			'',
			'image/jpeg',
			array(),
			100,
			1,
			'2026-06-07 00:00:00',
			'2026-06-07 00:00:00'
		);
		$this->assertSame( 0, $empty['has_alt'] );
	}

	/**
	 * The mime_subtype is the part after the slash.
	 *
	 * @return void
	 */
	public function test_mime_subtype_is_split_from_mime(): void {
		$png = MediaRow::from_attachment( 1, 'a.png', '', '', '', '', 'image/png', array(), 0, 0, '2026-06-07 00:00:00', '2026-06-07 00:00:00' );
		$this->assertSame( 'png', $png['mime_subtype'] );

		$jpeg = MediaRow::from_attachment( 2, 'a.jpg', '', '', '', '', 'image/jpeg', array(), 0, 0, '2026-06-07 00:00:00', '2026-06-07 00:00:00' );
		$this->assertSame( 'jpeg', $jpeg['mime_subtype'] );
	}

	/**
	 * Dimensions come from $meta when present and default to 0 when absent.
	 *
	 * @return void
	 */
	public function test_dimensions_default_to_zero_when_meta_absent(): void {
		$present = MediaRow::from_attachment(
			1,
			'a.jpg',
			'',
			'',
			'',
			'',
			'image/jpeg',
			array(
				'width'  => 800,
				'height' => 600,
			),
			0,
			0,
			'2026-06-07 00:00:00',
			'2026-06-07 00:00:00'
		);
		$this->assertSame( 800, $present['width'] );
		$this->assertSame( 600, $present['height'] );
		$this->assertSame( 'landscape', $present['orientation'] );

		$absent = MediaRow::from_attachment( 2, 'a.jpg', '', '', '', '', 'image/jpeg', array(), 0, 0, '2026-06-07 00:00:00', '2026-06-07 00:00:00' );
		$this->assertSame( 0, $absent['width'] );
		$this->assertSame( 0, $absent['height'] );
		$this->assertSame( '', $absent['orientation'] );
	}

	/**
	 * Filesize is taken verbatim as the resolved integer passed in.
	 *
	 * @return void
	 */
	public function test_filesize_is_the_passed_in_integer(): void {
		$row = MediaRow::from_attachment( 1, 'a.jpg', '', '', '', '', 'image/jpeg', array(), 123456, 0, '2026-06-07 00:00:00', '2026-06-07 00:00:00' );
		$this->assertSame( 123456, $row['filesize'] );
	}

	/**
	 * The returned array carries exactly the 16 structural columns (incl. caption/description).
	 *
	 * @return void
	 */
	public function test_returns_only_structural_columns(): void {
		$row = MediaRow::from_attachment(
			77,
			'sunset.jpg',
			'Sunset',
			'A sunset',
			'A caption',
			'A description',
			'image/jpeg',
			array(
				'width'  => 1200,
				'height' => 800,
			),
			524288,
			3,
			'2026-06-01 12:00:00',
			'2026-06-08 09:00:00'
		);

		$expected_keys = array(
			'attachment_id',
			'filename',
			'title',
			'alt',
			'caption',
			'description',
			'mime',
			'mime_subtype',
			'width',
			'height',
			'orientation',
			'filesize',
			'has_alt',
			'uploaded_by',
			'uploaded_at',
			'indexed_at',
		);
		$this->assertEqualsCanonicalizing( $expected_keys, array_keys( $row ) );

		$this->assertSame( 77, $row['attachment_id'] );
		$this->assertSame( 'sunset.jpg', $row['filename'] );
		$this->assertSame( 'Sunset', $row['title'] );
		$this->assertSame( 'A sunset', $row['alt'] );
		$this->assertSame( 'A caption', $row['caption'] );
		$this->assertSame( 'A description', $row['description'] );
		$this->assertSame( 'image/jpeg', $row['mime'] );
		$this->assertSame( 3, $row['uploaded_by'] );
		$this->assertSame( '2026-06-01 12:00:00', $row['uploaded_at'] );
		$this->assertSame( '2026-06-08 09:00:00', $row['indexed_at'] );
	}

	/**
	 * Caption and description passed in are returned verbatim.
	 *
	 * @return void
	 */
	public function test_caption_and_description_are_returned_verbatim(): void {
		$row = MediaRow::from_attachment(
			1,
			'a.jpg',
			'Title',
			'alt text',
			'hello',
			'world',
			'image/jpeg',
			array(),
			0,
			0,
			'2026-06-07 00:00:00',
			'2026-06-07 00:00:00'
		);

		$this->assertSame( 'hello', $row['caption'] );
		$this->assertSame( 'world', $row['description'] );
	}

	/**
	 * Usage-lane and nullable columns are NEVER emitted, so their DDL DEFAULTs hold.
	 *
	 * @return void
	 */
	public function test_omits_usage_and_nullable_columns(): void {
		$row = MediaRow::from_attachment(
			1,
			'a.jpg',
			'A',
			'alt',
			'',
			'',
			'image/png',
			array(
				'width'  => 1,
				'height' => 1,
			),
			1,
			1,
			'2026-06-07 00:00:00',
			'2026-06-07 00:00:00'
		);

		foreach ( array( 'usage_synced_at', 'content_hash', 'folder_id', 'usage_count', 'is_used' ) as $forbidden ) {
			$this->assertArrayNotHasKey( $forbidden, $row, "Structural row must not emit {$forbidden}." );
		}
	}
}
