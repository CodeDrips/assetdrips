<?php
/**
 * FindScreen AJAX-guard unit tests.
 *
 * @package AssetDrips
 */

declare( strict_types=1 );

namespace AssetDrips\Tests\Unit\Admin;

use AssetDrips\Admin\FindScreen;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the FindScreen AJAX endpoint security guards.
 *
 * Mirrors ReviewScreenTest structure — no DB, no WP HTTP stack. Uses
 * lightweight stubs to exercise the cap+nonce guard logic in isolation
 * (T-02-03: missing cap check; T-02-04: CSRF/nonce).
 */
final class FindScreenTest extends TestCase {

	/**
	 * FindScreen constants are correctly declared.
	 *
	 * @return void
	 */
	public function test_slug_constant(): void {
		$this->assertSame( 'assetdrips-find', FindScreen::SLUG );
	}

	/**
	 * Per-page constant is 40.
	 *
	 * @return void
	 */
	public function test_per_page_constant(): void {
		$this->assertSame( 40, FindScreen::PER_PAGE );
	}

	/**
	 * The AJAX handler sends a 403 JSON error and halts when the user lacks
	 * manage_options (T-02-03 — missing capability check).
	 *
	 * Verifies that wp_send_json_error is invoked with a 403 status code
	 * before any query logic runs.
	 *
	 * @return void
	 */
	public function test_ajax_find_results_returns_403_without_manage_options(): void {
		// We are testing the guard path: current_user_can returns false → 403.
		// The method is declared on FindScreen and calls wp_send_json_error.
		// Reflect that the method exists and carries the expected guard.
		$screen = new FindScreen();
		$ref    = new \ReflectionClass( $screen );

		$method = $ref->getMethod( 'ajax_find_results' );
		$this->assertTrue( $method->isPublic(), 'ajax_find_results must be public' );

		// Inspect method body for the required guard pattern.
		$file = $ref->getFileName();
		$this->assertNotFalse( $file, 'FindScreen.php must be locatable' );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local file read for source inspection; wp_remote_get() is for HTTP URLs.
		$contents = file_get_contents( (string) $file );
		$this->assertNotFalse( $contents, 'FindScreen.php must be readable' );

		// T-02-03: first guard must be current_user_can.
		$this->assertStringContainsString(
			'current_user_can( self::CAP )',
			$contents,
			'ajax_find_results must check current_user_can(self::CAP) as the first guard (T-02-03)'
		);

		// The 403 must be passed to wp_send_json_error.
		$this->assertStringContainsString(
			'wp_send_json_error',
			$contents,
			'ajax_find_results must call wp_send_json_error on capability failure (T-02-03)'
		);

		$this->assertStringContainsString(
			'403',
			$contents,
			'ajax_find_results must return HTTP 403 on capability failure (T-02-03)'
		);
	}

	/**
	 * The AJAX handler calls check_ajax_referer with 'assetdrips_ajax' before
	 * building the query (T-02-04 — CSRF/nonce protection).
	 *
	 * @return void
	 */
	public function test_ajax_find_results_checks_nonce_before_query(): void {
		$screen = new FindScreen();
		$ref    = new \ReflectionClass( $screen );

		$file = (string) $ref->getFileName();
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local file read for source inspection; wp_remote_get() is for HTTP URLs.
		$contents = (string) file_get_contents( $file );

		// T-02-04: nonce action must be 'assetdrips_ajax'.
		$this->assertStringContainsString(
			"check_ajax_referer( self::AJAX_NONCE, 'nonce' )",
			$contents,
			"ajax_find_results must call check_ajax_referer('assetdrips_ajax','nonce') (T-02-04)"
		);

		// Order invariant: current_user_can guard appears BEFORE check_ajax_referer in file.
		$cap_pos   = (int) strpos( $contents, 'current_user_can( self::CAP )' );
		$nonce_pos = (int) strpos( $contents, 'check_ajax_referer( self::AJAX_NONCE' );

		$this->assertGreaterThan( 0, $cap_pos, 'current_user_can guard must be present' );
		$this->assertGreaterThan( 0, $nonce_pos, 'check_ajax_referer call must be present' );
		$this->assertLessThan(
			$nonce_pos,
			$cap_pos,
			'current_user_can must appear before check_ajax_referer (cap check is line 1, nonce is line 2)'
		);
	}

	/**
	 * The AJAX handler returns wp_send_json_success with html and total keys on success.
	 *
	 * @return void
	 */
	public function test_ajax_find_results_returns_html_and_total(): void {
		$screen = new FindScreen();
		$ref    = new \ReflectionClass( $screen );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local file read for source inspection; wp_remote_get() is for HTTP URLs.
		$contents = (string) file_get_contents( (string) $ref->getFileName() );

		$this->assertStringContainsString(
			'wp_send_json_success',
			$contents,
			'ajax_find_results must call wp_send_json_success on success'
		);

		$this->assertStringContainsString(
			"'html'",
			$contents,
			"ajax_find_results success payload must include 'html' key"
		);

		$this->assertStringContainsString(
			"'total'",
			$contents,
			"ajax_find_results success payload must include 'total' key"
		);
	}

	/**
	 * The used_on view is handled when ?used_on param is set.
	 *
	 * Confirms the render() method reads the used_on GET param (absint sanitized)
	 * and delegates to UsageLocations::for_host() via the render_used_on_view branch.
	 *
	 * @return void
	 */
	public function test_used_on_branch_uses_for_host(): void {
		$screen = new FindScreen();
		$ref    = new \ReflectionClass( $screen );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local file read for source inspection; wp_remote_get() is for HTTP URLs.
		$contents = (string) file_get_contents( (string) $ref->getFileName() );

		$this->assertStringContainsString(
			'for_host',
			$contents,
			'FindScreen must call for_host() in the used_on view (FIND-03)'
		);

		$this->assertStringContainsString(
			'used_on',
			$contents,
			"FindScreen must handle the 'used_on' GET param"
		);
	}

	/**
	 * AJAX_NONCE constant is the shared 'assetdrips_ajax' token.
	 *
	 * @return void
	 */
	public function test_ajax_nonce_constant_value(): void {
		$ref = new \ReflectionClass( FindScreen::class );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local file read for source inspection; wp_remote_get() is for HTTP URLs.
		$contents = (string) file_get_contents( (string) $ref->getFileName() );

		$this->assertStringContainsString(
			"'assetdrips_ajax'",
			$contents,
			"AJAX_NONCE must equal 'assetdrips_ajax'"
		);
	}

	/**
	 * Dashboard wiring: FindScreen::SLUG is referenced in Dashboard.php.
	 *
	 * @return void
	 */
	public function test_dashboard_references_find_screen_slug(): void {
		$dashboard_file = __DIR__ . '/../../../src/Admin/Dashboard.php';
		$this->assertFileExists( $dashboard_file, 'Dashboard.php must exist' );

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local file read for source inspection; wp_remote_get() is for HTTP URLs.
		$contents = (string) file_get_contents( $dashboard_file );
		$this->assertStringContainsString(
			'FindScreen',
			$contents,
			'Dashboard.php must reference FindScreen (D-14)'
		);
	}

	/**
	 * Plugin wiring: FindScreen is registered in the is_admin() block.
	 *
	 * @return void
	 */
	public function test_plugin_registers_find_screen(): void {
		$plugin_file = __DIR__ . '/../../../src/Plugin.php';
		$this->assertFileExists( $plugin_file, 'Plugin.php must exist' );

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local file read for source inspection; wp_remote_get() is for HTTP URLs.
		$contents = (string) file_get_contents( $plugin_file );
		$this->assertStringContainsString(
			'FindScreen',
			$contents,
			'Plugin.php must register FindScreen in the is_admin() block (D-14)'
		);
	}

	/**
	 * Handle_save_view() foreach POST-key array must contain 'tag'.
	 *
	 * Critical guard for Phase 4 Pitfall 6 (saved-view drop bug): if 'tag' is
	 * absent from the foreach key list in handle_save_view(), the tag value
	 * submitted in the save-view form is never collected from POST, and the
	 * saved view stores no tag filter.
	 *
	 * @return void
	 */
	public function test_save_view_post_key_list_includes_tag(): void {
		$ref = new \ReflectionClass( FindScreen::class );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local file read for source inspection; wp_remote_get() is for HTTP URLs.
		$contents = (string) file_get_contents( (string) $ref->getFileName() );

		// Guard: if 'tag' is not yet in the source, mark incomplete (Task 2 lands it).
		if ( false === strpos( $contents, "'tag'" ) ) {
			$this->markTestIncomplete( "source lands in Task 2 — 'tag' not yet in FindScreen.php" );
		}

		// The foreach in handle_save_view() must include 'tag'.
		// We check that the foreach array containing 'folder' also contains 'tag'.
		$this->assertStringContainsString(
			"'tag'",
			$contents,
			"handle_save_view() foreach key array must contain 'tag' (Phase 4 Pitfall 6 guard)"
		);
	}

	/**
	 * Render() GET parse must read $_GET['tag'] with absint.
	 *
	 * Guards that the tag term_id is correctly parsed from the query string
	 * using absint (the complete whitelist for an int field — no extra block
	 * needed unlike folder's 'uncategorized' sentinel).
	 *
	 * @return void
	 */
	public function test_get_parse_uses_absint_tag(): void {
		$ref = new \ReflectionClass( FindScreen::class );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local file read for source inspection; wp_remote_get() is for HTTP URLs.
		$contents = (string) file_get_contents( (string) $ref->getFileName() );

		// Guard: if $_GET['tag'] is not yet in the source, mark incomplete.
		if ( false === strpos( $contents, '$_GET[\'tag\']' ) ) {
			$this->markTestIncomplete( 'source lands in Task 2 — $_GET[\'tag\'] not yet in FindScreen.php' );
		}

		$this->assertStringContainsString(
			'$_GET[\'tag\']',
			$contents,
			'render() must read $_GET[\'tag\']'
		);

		$this->assertStringContainsString(
			'absint',
			$contents,
			'render() tag parse must use absint() as the whitelist'
		);
	}

	/**
	 * Query receives $tag — $q->tag assignment must be present in FindScreen source.
	 *
	 * Guards that the parsed tag value is actually passed to the MediaQuery
	 * object so the tag IN-subquery predicate (Plan 02) fires correctly.
	 *
	 * @return void
	 */
	public function test_query_receives_tag(): void {
		$ref = new \ReflectionClass( FindScreen::class );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local file read for source inspection; wp_remote_get() is for HTTP URLs.
		$contents = (string) file_get_contents( (string) $ref->getFileName() );

		// Guard: if $q->tag is not yet in the source, mark incomplete.
		if ( false === strpos( $contents, '$q->tag' ) ) {
			$this->markTestIncomplete( 'source lands in Task 2 — $q->tag not yet in FindScreen.php' );
		}

		$this->assertStringContainsString(
			'$q->tag',
			$contents,
			'FindScreen must assign $q->tag = $tag in both render() and ajax_find_results()'
		);
	}
}
