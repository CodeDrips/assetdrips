<?php
/**
 * UsageLocator pure parser tests.
 *
 * @package AssetDrips
 */

declare( strict_types=1 );

namespace AssetDrips\Tests\Unit\Usage;

use AssetDrips\Usage\UsageLocator;
use PHPUnit\Framework\TestCase;

/**
 * Tests every scanner context format that UsageLocator must parse.
 *
 * Covers: post, postmeta, woo:product, woo:{label}:featured, acf:post,
 * acf:term, acf:option, option, termmeta, and malformed/unbound inputs.
 */
final class UsageLocatorTest extends TestCase {

	// -------------------------------------------------------------------------
	// post: format
	// -------------------------------------------------------------------------

	/**
	 * ContentScanner context resolves to post host with correct ID.
	 *
	 * Format: post:{ID}:post_content.
	 *
	 * @return void
	 */
	public function test_post_content_context_resolves_to_post(): void {
		$result = UsageLocator::parse( 'post:42:post_content' );

		$this->assertNotNull( $result );
		$this->assertSame( 'post', $result['host_type'] );
		$this->assertSame( 42, $result['host_id'] );
	}

	/**
	 * A post context with an arbitrary field name still resolves to the post ID.
	 *
	 * @return void
	 */
	public function test_post_context_with_arbitrary_field(): void {
		$result = UsageLocator::parse( 'post:99:some_field' );

		$this->assertNotNull( $result );
		$this->assertSame( 'post', $result['host_type'] );
		$this->assertSame( 99, $result['host_id'] );
	}

	// -------------------------------------------------------------------------
	// postmeta: format
	// -------------------------------------------------------------------------

	/**
	 * PostmetaScanner context resolves to a post host with the post ID.
	 *
	 * Format: postmeta:{post_id}:{key}.
	 *
	 * @return void
	 */
	public function test_postmeta_context_resolves_to_post(): void {
		$result = UsageLocator::parse( 'postmeta:42:_thumbnail_id' );

		$this->assertNotNull( $result );
		$this->assertSame( 'post', $result['host_type'] );
		$this->assertSame( 42, $result['host_id'] );
	}

	// -------------------------------------------------------------------------
	// woo: formats
	// -------------------------------------------------------------------------

	/**
	 * WooScanner gallery context resolves to a post host with the product ID.
	 *
	 * Format: woo:product:{post_id}:gallery.
	 *
	 * @return void
	 */
	public function test_woo_product_gallery_context_resolves_to_post(): void {
		$result = UsageLocator::parse( 'woo:product:99:gallery' );

		$this->assertNotNull( $result );
		$this->assertSame( 'post', $result['host_type'] );
		$this->assertSame( 99, $result['host_id'] );
	}

	/**
	 * WooScanner downloads context resolves to a post host with the product ID.
	 *
	 * Format: woo:product:{post_id}:downloads.
	 *
	 * @return void
	 */
	public function test_woo_product_downloads_context_resolves_to_post(): void {
		$result = UsageLocator::parse( 'woo:product:99:downloads' );

		$this->assertNotNull( $result );
		$this->assertSame( 'post', $result['host_type'] );
		$this->assertSame( 99, $result['host_id'] );
	}

	/**
	 * WooScanner featured context resolves to a post host with the product ID.
	 *
	 * Format: woo:{label}:{ID}:featured.
	 *
	 * @return void
	 */
	public function test_woo_featured_context_resolves_to_post(): void {
		$result = UsageLocator::parse( 'woo:Sale:7:featured' );

		$this->assertNotNull( $result );
		$this->assertSame( 'post', $result['host_type'] );
		$this->assertSame( 7, $result['host_id'] );
	}

	// -------------------------------------------------------------------------
	// acf: formats
	// -------------------------------------------------------------------------

	/**
	 * AcfScanner post context resolves to a post host with the post ID.
	 *
	 * Format: acf:post:{post_id}:{meta_key}.
	 *
	 * @return void
	 */
	public function test_acf_post_context_resolves_to_post(): void {
		$result = UsageLocator::parse( 'acf:post:55:hero' );

		$this->assertNotNull( $result );
		$this->assertSame( 'post', $result['host_type'] );
		$this->assertSame( 55, $result['host_id'] );
	}

	/**
	 * AcfScanner term context resolves to a term host with the term ID.
	 *
	 * Format: acf:term:{term_id}:{meta_key}.
	 *
	 * @return void
	 */
	public function test_acf_term_context_resolves_to_term(): void {
		$result = UsageLocator::parse( 'acf:term:12:image' );

		$this->assertNotNull( $result );
		$this->assertSame( 'term', $result['host_type'] );
		$this->assertSame( 12, $result['host_id'] );
	}

	/**
	 * AcfScanner option context is not post/term-bound and returns null.
	 *
	 * Format: acf:option:{name}.
	 *
	 * @return void
	 */
	public function test_acf_option_context_returns_null(): void {
		$this->assertNull( UsageLocator::parse( 'acf:option:site_logo' ) );
	}

	// -------------------------------------------------------------------------
	// option: format
	// -------------------------------------------------------------------------

	/**
	 * OptionsScanner context is not post-bound and returns null.
	 *
	 * Format: option:{name}.
	 *
	 * @return void
	 */
	public function test_option_context_returns_null(): void {
		$this->assertNull( UsageLocator::parse( 'option:custom_logo' ) );
	}

	// -------------------------------------------------------------------------
	// termmeta: format
	// -------------------------------------------------------------------------

	/**
	 * TermMetaScanner context resolves to a term host with the term ID.
	 *
	 * Format: termmeta:{term_id}:{key}.
	 *
	 * @return void
	 */
	public function test_termmeta_context_resolves_to_term(): void {
		$result = UsageLocator::parse( 'termmeta:12:thumb' );

		$this->assertNotNull( $result );
		$this->assertSame( 'term', $result['host_type'] );
		$this->assertSame( 12, $result['host_id'] );
	}

	// -------------------------------------------------------------------------
	// Malformed and unrecognised inputs
	// -------------------------------------------------------------------------

	/**
	 * Empty string returns null.
	 *
	 * @return void
	 */
	public function test_empty_string_returns_null(): void {
		$this->assertNull( UsageLocator::parse( '' ) );
	}

	/**
	 * Garbage/unknown prefix returns null.
	 *
	 * @return void
	 */
	public function test_garbage_context_returns_null(): void {
		$this->assertNull( UsageLocator::parse( 'garbage' ) );
	}

	/**
	 * Non-numeric ID in a post context returns null.
	 *
	 * @return void
	 */
	public function test_post_context_with_non_numeric_id_returns_null(): void {
		$this->assertNull( UsageLocator::parse( 'post:notanumber:x' ) );
	}

	/**
	 * Zero ID in a post context returns null because IDs must be positive.
	 *
	 * @return void
	 */
	public function test_post_context_with_zero_id_returns_null(): void {
		$this->assertNull( UsageLocator::parse( 'post:0:post_content' ) );
	}

	/**
	 * Non-numeric ID in a postmeta context returns null.
	 *
	 * @return void
	 */
	public function test_postmeta_context_with_non_numeric_id_returns_null(): void {
		$this->assertNull( UsageLocator::parse( 'postmeta:notanumber:_thumbnail_id' ) );
	}

	/**
	 * Non-numeric ID in a woo product context returns null.
	 *
	 * @return void
	 */
	public function test_woo_product_context_with_non_numeric_id_returns_null(): void {
		$this->assertNull( UsageLocator::parse( 'woo:product:notanumber:gallery' ) );
	}

	/**
	 * Non-numeric ID in an acf:post context returns null.
	 *
	 * @return void
	 */
	public function test_acf_post_context_with_non_numeric_id_returns_null(): void {
		$this->assertNull( UsageLocator::parse( 'acf:post:notanumber:field' ) );
	}

	/**
	 * Non-numeric ID in a termmeta context returns null.
	 *
	 * @return void
	 */
	public function test_termmeta_context_with_non_numeric_id_returns_null(): void {
		$this->assertNull( UsageLocator::parse( 'termmeta:notanumber:key' ) );
	}

	/**
	 * A post context with too few parts (missing field) returns null.
	 *
	 * @return void
	 */
	public function test_post_context_with_too_few_parts_returns_null(): void {
		$this->assertNull( UsageLocator::parse( 'post:42' ) );
	}

	/**
	 * Unknown woo sub-type with only two parts returns null.
	 *
	 * @return void
	 */
	public function test_woo_unknown_subtype_returns_null(): void {
		$this->assertNull( UsageLocator::parse( 'woo:unknown' ) );
	}
}
