<?php
/**
 * ReferenceExtractor tests.
 *
 * @package AssetDrips
 */

declare( strict_types=1 );

namespace AssetDrips\Tests\Unit;

use AssetDrips\Inventory\ReferenceExtractor;
use PHPUnit\Framework\TestCase;

/**
 * Pure tests for reference extraction from text and values.
 */
final class ReferenceExtractorTest extends TestCase {

	private const PREFIXES = array( '/wp-content/uploads', '/var/www/site/wp-content/uploads' );

	/**
	 * Build an extractor with the test prefixes.
	 *
	 * @return ReferenceExtractor
	 */
	private function extractor(): ReferenceExtractor {
		return new ReferenceExtractor( self::PREFIXES );
	}

	/**
	 * Every URL shape and a srcset reduce to uploads-relative paths.
	 *
	 * @return void
	 */
	public function test_relative_candidates_from_mixed_urls(): void {
		$html = '<img src="https://example.com/wp-content/uploads/2023/05/a.jpg" '
			. 'srcset="/wp-content/uploads/2023/05/a-300x200.jpg 300w, //cdn.test/wp-content/uploads/2023/05/a-768x512.jpg 768w">';

		$found = $this->extractor()->relative_candidates( $html );

		$this->assertContains( '2023/05/a.jpg', $found );
		$this->assertContains( '2023/05/a-300x200.jpg', $found );
		$this->assertContains( '2023/05/a-768x512.jpg', $found );
	}

	/**
	 * CSS url() and query strings are handled; the query is dropped.
	 *
	 * @return void
	 */
	public function test_relative_candidates_from_css_and_query(): void {
		$css = ".hero{background:url('https://example.com/wp-content/uploads/2024/01/bg.png?ver=2');}";

		$this->assertSame(
			array( '2024/01/bg.png' ),
			$this->extractor()->relative_candidates( $css )
		);
	}

	/**
	 * An absolute filesystem path reduces via the filesystem prefix.
	 *
	 * @return void
	 */
	public function test_relative_candidates_from_filesystem_path(): void {
		$value = '/var/www/site/wp-content/uploads/2022/09/doc.pdf';

		$this->assertContains(
			'2022/09/doc.pdf',
			$this->extractor()->relative_candidates( $value )
		);
	}

	/**
	 * URL-encoded paths are decoded.
	 *
	 * @return void
	 */
	public function test_relative_candidates_url_decoded(): void {
		$value = 'https://example.com/wp-content/uploads/2021/03/my%20file.jpg';

		$this->assertContains(
			'2021/03/my file.jpg',
			$this->extractor()->relative_candidates( $value )
		);
	}

	/**
	 * A single value is reduced to its uploads-relative path.
	 *
	 * @return void
	 */
	public function test_normalize_resolves_to_relative(): void {
		$ext = $this->extractor();

		$this->assertSame( '2023/05/a.jpg', $ext->normalize( 'https://example.com/wp-content/uploads/2023/05/a.jpg' ) );
		$this->assertSame( '2023/05/a.jpg', $ext->normalize( '/wp-content/uploads/2023/05/a.jpg' ) );
		$this->assertSame( '2023/05/a.jpg', $ext->normalize( '2023/05/a.jpg' ) );
	}

	/**
	 * A URL to another host that is not under uploads is not normalised.
	 *
	 * @return void
	 */
	public function test_normalize_rejects_foreign_url(): void {
		$this->assertNull( $this->extractor()->normalize( 'https://cdn.other.com/assets/a.jpg' ) );
		$this->assertNull( $this->extractor()->normalize( '//cdn.other.com/assets/a.jpg' ) );
	}

	/**
	 * ID candidates come only from media-denoting patterns.
	 *
	 * @return void
	 */
	public function test_id_candidates_from_known_patterns(): void {
		$content = '<!-- wp:image {"id":12,"sizeSlug":"large"} -->'
			. '<figure class="wp-block-image"><img class="wp-image-12"/></figure>'
			. '<!-- wp:gallery {"ids":[34,56]} -->'
			. '[gallery ids="78, 90"]'
			. '[caption id="attachment_21" align="alignnone"]';

		$ids = ReferenceExtractor::id_candidates( $content );

		foreach ( array( 12, 34, 56, 78, 90, 21 ) as $expected ) {
			$this->assertContains( $expected, $ids );
		}
	}

	/**
	 * Arbitrary numbers in prose are not treated as IDs.
	 *
	 * @return void
	 */
	public function test_id_candidates_ignores_arbitrary_numbers(): void {
		$this->assertSame(
			array(),
			ReferenceExtractor::id_candidates( 'Order #4521 shipped on 2023, total 99 items.' )
		);
	}
}
