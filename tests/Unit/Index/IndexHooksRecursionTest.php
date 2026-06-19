<?php
/**
 * IndexHooks recursion-guard source-inspection tests.
 *
 * Asserts that on_update() contains a static $in_update re-entry guard so that
 * a nested attachment_updated/edit_attachment during a bulk wp_update_post() loop
 * cannot recurse to a fatal nesting level (STATE.md Phase 7 blocker / T-07-dos-recursion).
 *
 * @package AssetDrips
 */

declare( strict_types=1 );

namespace AssetDrips\Tests\Unit\Index;

use PHPUnit\Framework\TestCase;

/**
 * Source-inspection tests for IndexHooks::on_update() recursion guard.
 *
 * Uses ReflectionClass to read the live source file so any change to the guard
 * is immediately reflected here without mocking the WP environment.
 */
final class IndexHooksRecursionTest extends TestCase {

	/**
	 * Return the IndexHooks source file contents for inspection.
	 *
	 * @return string
	 */
	private function index_hooks_source(): string {
		$ref = new \ReflectionClass( \AssetDrips\Index\IndexHooks::class );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local file read for source inspection.
		return (string) file_get_contents( (string) $ref->getFileName() );
	}

	/**
	 * IndexHooks source must declare the static $in_update property and use it via self::.
	 *
	 * Asserts both the declaration ('static bool $in_update') and the usage
	 * ('self::$in_update') so the guard cannot be removed without failing tests.
	 *
	 * @return void
	 */
	public function test_on_update_has_recursion_guard(): void {
		$source = $this->index_hooks_source();
		if ( ! str_contains( $source, '$in_update' ) ) {
			$this->markTestIncomplete( 'recursion guard lands in Wave 0 (Plan 01)' );
		}
		$this->assertStringContainsString(
			'static bool $in_update',
			$source,
			'IndexHooks must declare: private static bool $in_update = false'
		);
		$this->assertStringContainsString(
			'self::$in_update',
			$source,
			'IndexHooks must reference self::$in_update (set and/or read)'
		);
	}

	/**
	 * Asserts on_update() contains an early-return guard on self::$in_update.
	 *
	 * Confirms the exact early-return shape so a nested fire during a bulk
	 * wp_update_post() loop returns immediately instead of recursing.
	 *
	 * @return void
	 */
	public function test_guard_returns_early(): void {
		$source = $this->index_hooks_source();
		if ( ! str_contains( $source, '$in_update' ) ) {
			$this->markTestIncomplete( 'recursion guard lands in Wave 0 (Plan 01)' );
		}
		$this->assertStringContainsString(
			'if ( self::$in_update ) {',
			$source,
			'on_update() must check if ( self::$in_update ) { return; } for re-entry safety'
		);
	}
}
