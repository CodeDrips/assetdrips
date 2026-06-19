<?php
/**
 * Tests for SqueezeServing: filter selection, wp-admin/feed context exclusion,
 * cache-plugin detection, and toggle independence (SRV-01, SRV-02, SRV-03 / Phase 12).
 *
 * These started as Plan 12-01 RED scaffolding and are turned GREEN by Plan 12-02/12-03.
 * Note: emit_vary_header() calls PHP header() directly, so its live emission is covered
 * by the milestone-end integration UAT (12-VALIDATION.md Manual-Only), not unit tests;
 * the unit tests here assert the surrounding context guards (admin/feed/AJAX) instead.
 *
 * Injectable seams:
 *   - OptimizationIndex: anonymous class stub with configurable $flags_return (mirrors
 *     SqueezeEngineNextGenTest.php lines 181-257 and PATTERNS.md).
 *   - Accept-header source: ?callable constructor param, injected as a closure returning a
 *     controlled string (mirrors RESEARCH.md Pattern 2).
 *
 * No WP hooks, no real DB, no live HTTP — pure unit tests.
 *
 * @package AssetDrips
 */

declare( strict_types=1 );

namespace AssetDrips\Tests\Unit\Squeeze;

use AssetDrips\Squeeze\SqueezeServing;
use PHPUnit\Framework\TestCase;

/**
 * RED tests for SqueezeServing — SRV-01 filter selection, SRV-02 detection, SRV-03 toggle independence.
 */
final class SqueezeServingTest extends TestCase {

	/**
	 * Anonymous OptimizationIndex stub with configurable get_flags() return.
	 *
	 * @var object
	 */
	private object $index_stub;

	/**
	 * Build the OptimizationIndex stub and reset call tracking.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->index_stub = new class() {
			/**
			 * Configurable return value for get_flags(). Default: all false.
			 * Seed individual tests by setting $this->index_stub->flags_return before constructing
			 * the subject, or assign a new stub with different flags_return in the test.
			 *
			 * @var array{has_webp: bool, has_avif: bool, is_oversized: bool}
			 */
			public array $flags_return = array(
				'has_webp'     => false,
				'has_avif'     => false,
				'is_oversized' => false,
			);

			/** @var array<int, int> */
			public array $get_flags_calls = array();

			/**
			 * Return the configured flags for any attachment ID.
			 *
			 * @param int $id Attachment ID.
			 * @return array{has_webp: bool, has_avif: bool, is_oversized: bool}
			 */
			public function get_flags( int $id ): array {
				$this->get_flags_calls[] = $id;
				return $this->flags_return;
			}
		};
	}

	/**
	 * Reset the is_admin()/is_feed() stub toggles so context guards don't leak
	 * across tests (the bootstrap stubs read these globals).
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		unset( $GLOBALS['__ad_test_is_admin'], $GLOBALS['__ad_test_is_feed'] );
	}

	/**
	 * Build a SqueezeServing subject with the given Accept string and (optionally) a
	 * custom index stub.  The callable seam ensures no $_SERVER reads occur in tests.
	 *
	 * @param string      $accept       Simulated HTTP_ACCEPT value.
	 * @param object|null $index_stub   Custom index stub; defaults to $this->index_stub.
	 * @return SqueezeServing
	 */
	private function make_subject( string $accept, ?object $index_stub = null ): SqueezeServing {
		/** @var \AssetDrips\Squeeze\OptimizationIndex $real_index_type */
		$real_index_type = $index_stub ?? $this->index_stub;
		return new SqueezeServing(
			$real_index_type,
			static fn() => $accept
		);
	}

	// =========================================================================
	// SRV-01 — filter_image_src: false passthrough
	// =========================================================================

	/**
	 * filter_image_src() must return its first argument unchanged when $image is false
	 * (e.g. WP icon/SVG attachment — has no URL array).
	 *
	 * @return void
	 */
	public function test_filter_image_src_returns_unchanged_when_image_is_false(): void {
		$subject = $this->make_subject( 'image/avif,image/webp' );

		$result = $subject->filter_image_src( false, 1, 'full', false );

		$this->assertFalse( $result, 'filter_image_src() must return false unchanged when $image is false' );
	}

	// =========================================================================
	// SRV-02 / D-08 — filter_image_src: wp-admin and feed contexts are excluded
	// =========================================================================

	/**
	 * filter_image_src() must NOT rewrite the URL in wp-admin (D-08) — even when the
	 * attachment has an AVIF sibling and the Accept header supports it — so the Edit
	 * Image UI keeps the original extension.
	 *
	 * @return void
	 */
	public function test_filter_image_src_returns_unchanged_in_wp_admin(): void {
		$GLOBALS['__ad_test_is_admin'] = true;

		$this->index_stub->flags_return = array(
			'has_webp'     => true,
			'has_avif'     => true,
			'is_oversized' => false,
		);
		$subject = $this->make_subject( 'image/avif,image/webp,image/png,*/*' );

		$image  = array( 'https://example.com/photo.jpg', 800, 600, false );
		$result = $subject->filter_image_src( $image, 42, 'full', false );

		$this->assertSame(
			$image,
			$result,
			'filter_image_src() must return $image unchanged in wp-admin (D-08 exclusion)'
		);
	}

	/**
	 * filter_image_src() must NOT rewrite the URL on feed requests (D-04) so feed items
	 * keep stable original URLs.
	 *
	 * @return void
	 */
	public function test_filter_image_src_returns_unchanged_on_feed(): void {
		$GLOBALS['__ad_test_is_feed'] = true;

		$this->index_stub->flags_return = array(
			'has_webp'     => true,
			'has_avif'     => true,
			'is_oversized' => false,
		);
		$subject = $this->make_subject( 'image/avif,image/webp,image/png,*/*' );

		$image  = array( 'https://example.com/photo.jpg', 800, 600, false );
		$result = $subject->filter_image_src( $image, 42, 'full', false );

		$this->assertSame(
			$image,
			$result,
			'filter_image_src() must return $image unchanged on feed requests (D-04 exclusion)'
		);
	}

	// =========================================================================
	// SRV-01 — filter_image_src: fast-exit when no flags set
	// =========================================================================

	/**
	 * filter_image_src() must return $image unchanged (fast-exit) when get_flags()
	 * shows neither has_webp nor has_avif — even if Accept contains image/avif.
	 *
	 * @return void
	 */
	public function test_filter_image_src_returns_unchanged_when_no_flags_set(): void {
		// Default stub has both flags false.
		$subject = $this->make_subject( 'image/avif,image/webp,image/png,*/*' );

		$image  = array( 'https://example.com/photo.jpg', 800, 600, false );
		$result = $subject->filter_image_src( $image, 42, 'full', false );

		$this->assertSame(
			$image,
			$result,
			'filter_image_src() must return $image unchanged when both flags are false (fast-exit)'
		);
	}

	// =========================================================================
	// SRV-01 — filter_image_src: AVIF preferred when has_avif + Accept:image/avif
	// =========================================================================

	/**
	 * filter_image_src() must rewrite $image[0] to append '.avif' when has_avif is true
	 * and the Accept header contains 'image/avif'.
	 *
	 * @return void
	 */
	public function test_filter_image_src_rewrites_to_avif_when_has_avif_and_accept_contains_avif(): void {
		$this->index_stub->flags_return = array(
			'has_webp'     => false,
			'has_avif'     => true,
			'is_oversized' => false,
		);
		$subject = $this->make_subject( 'image/avif,image/webp,image/png,*/*' );

		$image  = array( 'https://example.com/photo.jpg', 800, 600, false );
		$result = $subject->filter_image_src( $image, 1, 'full', false );

		$this->assertIsArray( $result, 'filter_image_src() must return an array when rewriting' );
		$this->assertSame(
			'https://example.com/photo.jpg.avif',
			$result[0],
			'filter_image_src() must append .avif to $image[0] when has_avif=true and Accept contains image/avif'
		);
	}

	// =========================================================================
	// SRV-01 — filter_image_src: AVIF preferred over WebP when both flags + both in Accept
	// =========================================================================

	/**
	 * filter_image_src() must prefer AVIF over WebP when has_avif=true, has_webp=true,
	 * and Accept contains both 'image/avif' and 'image/webp'.
	 *
	 * @return void
	 */
	public function test_filter_image_src_prefers_avif_over_webp_when_both_flags_and_both_in_accept(): void {
		$this->index_stub->flags_return = array(
			'has_webp'     => true,
			'has_avif'     => true,
			'is_oversized' => false,
		);
		$subject = $this->make_subject( 'image/avif,image/webp,image/png,*/*' );

		$image  = array( 'https://example.com/photo.jpg', 1200, 900, false );
		$result = $subject->filter_image_src( $image, 5, 'large', false );

		$this->assertIsArray( $result );
		$this->assertStringEndsWith(
			'.avif',
			$result[0],
			'filter_image_src() must prefer AVIF over WebP when both flags are true and both are in Accept'
		);
		$this->assertStringNotContainsString(
			'.webp',
			$result[0],
			'filter_image_src() must not append .webp when AVIF is available and preferred'
		);
	}

	// =========================================================================
	// SRV-01 — filter_image_src: WebP fallback when has_webp=true, has_avif=false, Accept:webp
	// =========================================================================

	/**
	 * filter_image_src() must rewrite to '.webp' when has_webp=true, has_avif=false,
	 * and Accept contains 'image/webp' (but NOT 'image/avif').
	 *
	 * @return void
	 */
	public function test_filter_image_src_rewrites_to_webp_when_has_webp_and_accept_contains_webp_only(): void {
		$this->index_stub->flags_return = array(
			'has_webp'     => true,
			'has_avif'     => false,
			'is_oversized' => false,
		);
		$subject = $this->make_subject( 'image/webp,image/png,*/*' );

		$image  = array( 'https://example.com/banner.png', 400, 200, false );
		$result = $subject->filter_image_src( $image, 7, 'medium', false );

		$this->assertIsArray( $result );
		$this->assertSame(
			'https://example.com/banner.png.webp',
			$result[0],
			'filter_image_src() must append .webp when has_webp=true and Accept contains image/webp'
		);
	}

	// =========================================================================
	// SRV-01 — filter_image_src: no rewrite when Accept supports neither format
	// =========================================================================

	/**
	 * filter_image_src() must return $image unchanged when has_webp=true but Accept
	 * contains neither 'image/webp' nor 'image/avif' (e.g. legacy 'image/jpeg,*\/*').
	 *
	 * @return void
	 */
	public function test_filter_image_src_returns_original_when_accept_supports_neither_format(): void {
		$this->index_stub->flags_return = array(
			'has_webp'     => true,
			'has_avif'     => false,
			'is_oversized' => false,
		);
		$subject = $this->make_subject( 'image/jpeg,image/gif,*/*' );

		$image  = array( 'https://example.com/photo.jpg', 800, 600, false );
		$result = $subject->filter_image_src( $image, 9, 'full', false );

		$this->assertSame(
			$image,
			$result,
			'filter_image_src() must return $image unchanged when Accept supports neither webp nor avif'
		);
	}

	// =========================================================================
	// SRV-01 — swap behavior: query string stripped before raster check, restored after
	// =========================================================================

	/**
	 * filter_image_src() must handle URLs with query strings correctly:
	 * 'photo.jpg?ver=2' → 'photo.jpg.webp?ver=2' (strip before raster check, restore after).
	 *
	 * @return void
	 */
	public function test_filter_image_src_strips_query_string_before_raster_check_and_restores_after(): void {
		$this->index_stub->flags_return = array(
			'has_webp'     => true,
			'has_avif'     => false,
			'is_oversized' => false,
		);
		$subject = $this->make_subject( 'image/webp,image/png,*/*' );

		$image  = array( 'https://example.com/photo.jpg?ver=2', 800, 600, false );
		$result = $subject->filter_image_src( $image, 11, 'full', false );

		$this->assertIsArray( $result );
		$this->assertSame(
			'https://example.com/photo.jpg.webp?ver=2',
			$result[0],
			'filter_image_src() must strip query string before raster check and restore it after appending the next-gen extension'
		);
	}

	// =========================================================================
	// SRV-01 — swap behavior: no double-rewrite for already-next-gen URLs
	// =========================================================================

	/**
	 * filter_image_src() must NOT double-append when $image[0] already ends in '.webp'.
	 *
	 * @return void
	 */
	public function test_filter_image_src_does_not_double_rewrite_already_webp_url(): void {
		$this->index_stub->flags_return = array(
			'has_webp'     => true,
			'has_avif'     => false,
			'is_oversized' => false,
		);
		$subject = $this->make_subject( 'image/webp,image/png,*/*' );

		$already_webp = 'https://example.com/photo.jpg.webp';
		$image        = array( $already_webp, 800, 600, false );
		$result       = $subject->filter_image_src( $image, 13, 'full', false );

		$this->assertIsArray( $result );
		$this->assertSame(
			$already_webp,
			$result[0],
			'filter_image_src() must not double-append .webp when URL already ends in .webp'
		);
	}

	// =========================================================================
	// SRV-01 — swap behavior: non-raster URL returned unchanged (SVG, GIF)
	// =========================================================================

	/**
	 * filter_image_src() must return $image unchanged (no rewrite) when $image[0] ends in
	 * a non-raster extension such as '.svg' or '.gif', even when flags indicate WebP available.
	 *
	 * @return void
	 */
	public function test_filter_image_src_does_not_rewrite_non_raster_url(): void {
		$this->index_stub->flags_return = array(
			'has_webp'     => true,
			'has_avif'     => true,
			'is_oversized' => false,
		);
		$subject = $this->make_subject( 'image/avif,image/webp,image/png,*/*' );

		$svg_url = 'https://example.com/logo.svg';
		$image   = array( $svg_url, 200, 100, false );
		$result  = $subject->filter_image_src( $image, 15, 'full', false );

		$this->assertIsArray( $result );
		$this->assertSame(
			$svg_url,
			$result[0],
			'filter_image_src() must not rewrite non-raster URLs (SVG, GIF, etc.) — swap_extension() must guard by URL extension'
		);
	}

	// =========================================================================
	// SRV-02 — detect_cache_plugins: known constants
	// =========================================================================

	/**
	 * detect_cache_plugins() must return 'WP Rocket' when WP_ROCKET_VERSION is defined.
	 *
	 * @return void
	 */
	public function test_detect_cache_plugins_returns_wp_rocket_when_constant_defined(): void {
		if ( ! defined( 'WP_ROCKET_VERSION' ) ) {
			define( 'WP_ROCKET_VERSION', '3.0' );
		}

		// detect_cache_plugins is called on a fresh instance; the method must reflect
		// the runtime-defined constants.
		$subject = $this->make_subject( '' );
		$plugins = $subject->detect_cache_plugins();

		$this->assertContains(
			'WP Rocket',
			$plugins,
			'detect_cache_plugins() must include "WP Rocket" when WP_ROCKET_VERSION is defined'
		);
	}

	/**
	 * detect_cache_plugins() must return 'Cloudflare' when CLOUDFLARE_PLUGIN_DIR is defined.
	 *
	 * @return void
	 */
	public function test_detect_cache_plugins_returns_cloudflare_when_constant_defined(): void {
		if ( ! defined( 'CLOUDFLARE_PLUGIN_DIR' ) ) {
			define( 'CLOUDFLARE_PLUGIN_DIR', '/path/to/cloudflare' );
		}

		$subject = $this->make_subject( '' );
		$plugins = $subject->detect_cache_plugins();

		$this->assertContains(
			'Cloudflare',
			$plugins,
			'detect_cache_plugins() must include "Cloudflare" when CLOUDFLARE_PLUGIN_DIR is defined'
		);
	}

	// =========================================================================
	// SRV-03 — toggle independence: no SqueezeSettings dependency to instantiate/filter
	// =========================================================================

	/**
	 * SqueezeServing can be instantiated and filter_image_src() called without any
	 * SqueezeSettings dependency — the serving gate lives in Plugin::boot(), not here.
	 *
	 * This test asserts that SqueezeServing accepts only an OptimizationIndex +
	 * callable and does NOT require a SqueezeSettings injection at construction time.
	 *
	 * @return void
	 */
	public function test_squeeze_serving_instantiates_and_filters_without_squeeze_settings(): void {
		// Construct with only the two required/optional params — no SqueezeSettings arg.
		$subject = $this->make_subject( 'image/webp,image/png,*/*' );

		// filter_image_src with no-flag stub must return unchanged (fast-exit).
		$image  = array( 'https://example.com/photo.jpg', 800, 600, false );
		$result = $subject->filter_image_src( $image, 99, 'full', false );

		$this->assertSame(
			$image,
			$result,
			'SqueezeServing must operate (and fast-exit) without any SqueezeSettings dependency; enable_serving gate is in Plugin::boot() not in this class'
		);
	}
}
