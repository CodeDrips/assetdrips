<?php
/**
 * AttachmentCatalogue variant-derivation tests.
 *
 * These are the regression guard for the prime directive: a reference to ANY
 * variant must be derivable, or we risk a false "unused" verdict. They run as
 * pure unit tests (no WordPress, no DB) against realistic metadata fixtures.
 *
 * @package AssetDrips
 */

declare( strict_types=1 );

namespace AssetDrips\Tests\Unit;

use AssetDrips\Inventory\AttachmentCatalogue;
use PHPUnit\Framework\TestCase;

/**
 * Pure unit tests for AttachmentCatalogue::build_keys().
 */
final class AttachmentCatalogueTest extends TestCase {

	private const BASE_DIR = '/var/www/example/wp-content/uploads';
	private const BASE_URL = 'https://example.com/wp-content/uploads';

	/**
	 * Build a catalogue with no DB handle (pure mode).
	 *
	 * @return AttachmentCatalogue
	 */
	private function catalogue(): AttachmentCatalogue {
		return new AttachmentCatalogue( null, self::BASE_DIR, self::BASE_URL );
	}

	/**
	 * A scaled image yields the scaled main, the original, and every sub-size —
	 * across relative paths, absolute URLs, root-relative URLs, and FS paths.
	 *
	 * @return void
	 */
	public function test_scaled_image_covers_original_and_every_size(): void {
		$metadata = array(
			'file'           => '2023/05/sunset-scaled.jpg',
			'width'          => 2560,
			'height'         => 1707,
			'sizes'          => array(
				'thumbnail' => array( 'file' => 'sunset-scaled-150x150.jpg' ),
				'medium'    => array( 'file' => 'sunset-scaled-300x200.jpg' ),
				'large'     => array( 'file' => 'sunset-scaled-1024x683.jpg' ),
			),
			'original_image' => 'sunset.jpg',
		);

		$keys = $this->catalogue()->build_keys( 101, '2023/05/sunset-scaled.jpg', $metadata );

		$expected_relatives = array(
			'2023/05/sunset-scaled.jpg',
			'2023/05/sunset.jpg',
			'2023/05/sunset-scaled-150x150.jpg',
			'2023/05/sunset-scaled-300x200.jpg',
			'2023/05/sunset-scaled-1024x683.jpg',
		);
		$this->assertEqualsCanonicalizing( $expected_relatives, $keys->relative_paths() );

		// The original (pre-scaled) file is reachable — the -scaled regression case.
		$this->assertContains( 'https://example.com/wp-content/uploads/2023/05/sunset.jpg', $keys->urls() );
		$this->assertContains( '/wp-content/uploads/2023/05/sunset.jpg', $keys->urls() );
		$this->assertContains( self::BASE_DIR . '/2023/05/sunset.jpg', $keys->paths() );

		// A generated size is reachable in every token family.
		$this->assertContains( 'https://example.com/wp-content/uploads/2023/05/sunset-scaled-300x200.jpg', $keys->urls() );
		$this->assertContains( '/wp-content/uploads/2023/05/sunset-scaled-300x200.jpg', $keys->urls() );
		$this->assertContains( self::BASE_DIR . '/2023/05/sunset-scaled-300x200.jpg', $keys->paths() );
	}

	/**
	 * A plain (non-scaled) image: main file plus its sub-sizes, no original.
	 *
	 * @return void
	 */
	public function test_plain_image_main_and_subsizes(): void {
		$metadata = array(
			'file'  => '2024/01/logo.png',
			'sizes' => array(
				'thumbnail' => array( 'file' => 'logo-150x150.png' ),
				'medium'    => array( 'file' => 'logo-300x169.png' ),
			),
		);

		$keys = $this->catalogue()->build_keys( 102, '2024/01/logo.png', $metadata );

		$this->assertEqualsCanonicalizing(
			array(
				'2024/01/logo.png',
				'2024/01/logo-150x150.png',
				'2024/01/logo-300x169.png',
			),
			$keys->relative_paths()
		);
	}

	/**
	 * A PDF carries generated preview images under 'sizes' and is handled
	 * generically — no image-specific assumptions.
	 *
	 * @return void
	 */
	public function test_pdf_preview_sizes_are_included(): void {
		$metadata = array(
			'file'  => '2024/02/brochure.pdf',
			'sizes' => array(
				'full'      => array( 'file' => 'brochure-pdf.jpg' ),
				'thumbnail' => array( 'file' => 'brochure-pdf-116x150.jpg' ),
			),
		);

		$keys = $this->catalogue()->build_keys( 103, '2024/02/brochure.pdf', $metadata );

		$this->assertEqualsCanonicalizing(
			array(
				'2024/02/brochure.pdf',
				'2024/02/brochure-pdf.jpg',
				'2024/02/brochure-pdf-116x150.jpg',
			),
			$keys->relative_paths()
		);
	}

	/**
	 * Backup (pre-edit) sizes are included so references to a since-edited
	 * image still mark the attachment used — the safe, conservative direction.
	 *
	 * @return void
	 */
	public function test_backup_sizes_are_included(): void {
		$metadata = array(
			'file'  => '2023/03/photo-e1700000000.jpg',
			'sizes' => array(
				'thumbnail' => array( 'file' => 'photo-e1700000000-150x150.jpg' ),
			),
		);
		$backup   = array(
			'full-orig'      => array( 'file' => 'photo.jpg' ),
			'thumbnail-orig' => array( 'file' => 'photo-150x150.jpg' ),
		);

		$keys = $this->catalogue()->build_keys( 104, '2023/03/photo-e1700000000.jpg', $metadata, $backup );

		$this->assertContains( '2023/03/photo.jpg', $keys->relative_paths() );
		$this->assertContains( '2023/03/photo-150x150.jpg', $keys->relative_paths() );
		$this->assertContains( '2023/03/photo-e1700000000.jpg', $keys->relative_paths() );
		$this->assertContains( '2023/03/photo-e1700000000-150x150.jpg', $keys->relative_paths() );
	}

	/**
	 * A top-level file (no year/month directory) builds clean tokens.
	 *
	 * @return void
	 */
	public function test_top_level_file_without_directory(): void {
		$keys = $this->catalogue()->build_keys( 105, 'jingle.mp3', array() );

		$this->assertSame( array( 'jingle.mp3' ), $keys->relative_paths() );
		$this->assertContains( 'https://example.com/wp-content/uploads/jingle.mp3', $keys->urls() );
		$this->assertContains( self::BASE_DIR . '/jingle.mp3', $keys->paths() );
	}

	/**
	 * A file with no usable metadata still produces ID-addressable keys, and
	 * emptiness reflects whether any reference token exists.
	 *
	 * @return void
	 */
	public function test_empty_attached_file_is_id_only(): void {
		$keys = $this->catalogue()->build_keys( 106, '', array() );

		$this->assertSame( 106, $keys->id() );
		$this->assertTrue( $keys->is_empty() );
	}

	/**
	 * The DB-backed methods refuse to run without a database handle.
	 *
	 * @return void
	 */
	public function test_batch_requires_database_handle(): void {
		$this->expectException( \RuntimeException::class );
		$this->catalogue()->each_batch( 50, static function (): void {} );
	}
}
