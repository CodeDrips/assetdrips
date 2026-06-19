<?php
/**
 * FolderScreen unit tests — source-inspection scaffolding (Wave 0).
 *
 * These tests are intentionally written BEFORE the source file lands (Plans 02/03).
 * Each test guards with class_exists() + markTestIncomplete() so the unit suite
 * stays green until FolderScreen.php ships, at which point the guard evaluates to
 * false and the assertions run automatically (RED → GREEN flip).
 *
 * @package AssetDrips
 */

declare( strict_types=1 );

namespace AssetDrips\Tests\Unit\Admin;

use PHPUnit\Framework\TestCase;

/**
 * Source-inspection tests for FolderScreen hook registration, cap-before-nonce
 * ordering, AJAX action names, and deep-link URLs.
 *
 * Covers:
 *   - FOLDER-01: CRUD AJAX + admin_post action registration
 *   - FOLDER-02: browse deep-links (&folder= / folder=uncategorized)
 *   - Pitfall 7: distinct AJAX_NONCE constant (assetdrips_folders_ajax)
 *   - T-05-00: %d binding in SQL helpers
 */
final class FolderScreenTest extends TestCase {

	/**
	 * Return the source-file contents for FolderScreen.
	 *
	 * @return string
	 */
	private function folder_screen_source(): string {
		$this->assertTrue(
			class_exists( \AssetDrips\Admin\FolderScreen::class ),
			'FolderScreen class must exist — ensure Plan 03 has run'
		);
		$ref = new \ReflectionClass( \AssetDrips\Admin\FolderScreen::class );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local file read for source inspection.
		return (string) file_get_contents( (string) $ref->getFileName() );
	}

	/**
	 * register() must wire all six AJAX + admin_post actions (FOLDER-01).
	 *
	 * @return void
	 */
	public function test_register_adds_ajax_actions(): void {
		if ( ! class_exists( \AssetDrips\Admin\FolderScreen::class ) ) {
			$this->markTestIncomplete( 'source lands in Plan 03' );
		}
		$contents = $this->folder_screen_source();

		$this->assertStringContainsString(
			"add_action( 'wp_ajax_assetdrips_folder_create'",
			$contents,
			"register() must wire wp_ajax_assetdrips_folder_create"
		);
		$this->assertStringContainsString(
			"add_action( 'wp_ajax_assetdrips_folder_rename'",
			$contents,
			"register() must wire wp_ajax_assetdrips_folder_rename"
		);
		$this->assertStringContainsString(
			"add_action( 'wp_ajax_assetdrips_folder_delete'",
			$contents,
			"register() must wire wp_ajax_assetdrips_folder_delete"
		);
		$this->assertStringContainsString(
			"add_action( 'admin_post_assetdrips_folder_create'",
			$contents,
			"register() must wire admin_post_assetdrips_folder_create (no-JS fallback)"
		);
		$this->assertStringContainsString(
			"add_action( 'admin_post_assetdrips_folder_rename'",
			$contents,
			"register() must wire admin_post_assetdrips_folder_rename (no-JS fallback)"
		);
		$this->assertStringContainsString(
			"add_action( 'admin_post_assetdrips_folder_delete'",
			$contents,
			"register() must wire admin_post_assetdrips_folder_delete (no-JS fallback)"
		);
	}

	/**
	 * AJAX handlers must check capability BEFORE nonce (T-02-03/T-02-04 ordering).
	 *
	 * @return void
	 */
	public function test_ajax_handlers_guard_capability_before_nonce(): void {
		if ( ! class_exists( \AssetDrips\Admin\FolderScreen::class ) ) {
			$this->markTestIncomplete( 'source lands in Plan 03' );
		}
		$contents = $this->folder_screen_source();

		$cap_pos   = (int) strpos( $contents, 'current_user_can( self::CAP )' );
		$nonce_pos = (int) strpos( $contents, 'check_ajax_referer( self::AJAX_NONCE' );

		$this->assertGreaterThan( 0, $cap_pos, 'current_user_can(self::CAP) guard must be present' );
		$this->assertGreaterThan( 0, $nonce_pos, 'check_ajax_referer(self::AJAX_NONCE) must be present' );
		$this->assertLessThan(
			$nonce_pos,
			$cap_pos,
			'capability check must precede nonce check (T-02-03 before T-02-04)'
		);
	}

	/**
	 * AJAX_NONCE must be a distinct constant — 'assetdrips_folders_ajax' (Pitfall 7).
	 *
	 * Ensures FolderScreen does not accidentally reuse FindScreen's 'assetdrips_ajax'
	 * nonce, which would allow cross-screen CSRF.
	 *
	 * @return void
	 */
	public function test_uses_distinct_nonce_constant(): void {
		if ( ! class_exists( \AssetDrips\Admin\FolderScreen::class ) ) {
			$this->markTestIncomplete( 'source lands in Plan 03' );
		}
		$contents = $this->folder_screen_source();

		$this->assertStringContainsString(
			'assetdrips_folders_ajax',
			$contents,
			"AJAX_NONCE must equal 'assetdrips_folders_ajax', not the shared FindScreen nonce (Pitfall 7)"
		);
	}

	/**
	 * Browse deep-links must use &folder= and folder=uncategorized (FOLDER-02).
	 *
	 * @return void
	 */
	public function test_browse_deeplinks_use_folder_arg(): void {
		if ( ! class_exists( \AssetDrips\Admin\FolderScreen::class ) ) {
			$this->markTestIncomplete( 'source lands in Plan 03' );
		}
		$contents = $this->folder_screen_source();

		$this->assertStringContainsString(
			'&folder=',
			$contents,
			'Browse deep-links must append &folder= query param (FOLDER-02)'
		);
		$this->assertStringContainsString(
			'folder=uncategorized',
			$contents,
			'Browse deep-links must include folder=uncategorized for the Uncategorized facet (FOLDER-02)'
		);
	}

	/**
	 * SQL in FolderScreen must bind term IDs via %d, never string interpolation.
	 *
	 * Confirms no direct string concatenation of term IDs into SQL (T-05-00).
	 *
	 * @return void
	 */
	public function test_sql_binds_term_id(): void {
		if ( ! class_exists( \AssetDrips\Admin\FolderScreen::class ) ) {
			$this->markTestIncomplete( 'source lands in Plan 03' );
		}
		$contents = $this->folder_screen_source();

		// At least one of the two bound-parameter forms must appear.
		$has_percent_d  = strpos( $contents, "WHERE folder_id = %d" ) !== false;
		$has_format_arr = strpos( $contents, "array( '%d' )" ) !== false;

		$this->assertTrue(
			$has_percent_d || $has_format_arr,
			"FolderScreen SQL must bind term_id via '%d' or array('%d'), never string interpolation (T-05-00)"
		);
	}
}
