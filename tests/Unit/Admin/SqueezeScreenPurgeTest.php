<?php
/**
 * Security regression tests for SqueezeScreen destructive-delete handlers (BAK-04 / CR-04).
 *
 * CR-04 identified that the two-step confirmation for "purge all backups" was JS-only.
 * The fix adds a server-side guard at SqueezeScreen.php:671:
 *
 *   if ( empty( $_POST['purge_all_confirm'] ) ) { wp_safe_redirect(...); exit; }
 *
 * These tests lock in that server-side gate so it cannot be silently removed.
 * They are written to FAIL if the guard is deleted from the handler.
 *
 * @package AssetDrips
 */

declare( strict_types=1 );

namespace AssetDrips\Tests\Unit\Admin;

use AssetDrips\Admin\SqueezeScreen;
use PHPUnit\Framework\TestCase;

/**
 * BAK-04 / CR-04 regression: server-side two-step-confirmation gate on purge handlers.
 */
final class SqueezeScreenPurgeTest extends TestCase {

	/**
	 * Reset global stubs before each test.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$GLOBALS['_assetdrips_current_user_can_stub'] = true;
		// Ensure $_POST is clean before each test.
		$_POST = array();
	}

	/**
	 * Reset $_POST after each test so no state leaks to the next test.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		$_POST = array();
	}

	// -----------------------------------------------------------------------
	// CR-04 / BAK-04: server-side confirmation gate on handle_purge_all_backups
	// -----------------------------------------------------------------------

	/**
	 * When purge_all_confirm is absent from $_POST, handle_purge_all_backups() must
	 * redirect with the "must check confirmation box" error notice and NOT proceed to
	 * purge any backups.
	 *
	 * This test fails if the server-side guard (SqueezeScreen.php:671) is removed.
	 *
	 * @return void
	 */
	public function test_purge_all_backups_refuses_when_confirm_field_absent(): void {
		// Arrange: no purge_all_confirm field in POST.
		$_POST = array();
		// current_user_can returns true (set in setUp).

		$screen = new SqueezeScreen();

		// Act + Assert: the handler must redirect (not proceed to BackupManager).
		try {
			$screen->handle_purge_all_backups();
			$this->fail( 'handle_purge_all_backups() must redirect when purge_all_confirm is absent; it returned instead.' );
		} catch ( \AssetDripsTestRedirectException $e ) {
			// The redirect URL must contain the error notice.
			// The add_query_arg stub uses http_build_query() which double-encodes the
			// rawurlencode()'d value from the handler, producing %2520 for each space.
			$this->assertStringContainsString(
				'confirmation%2520box',
				$e->location,
				'Redirect URL must include the "confirmation box" error notice when purge_all_confirm is absent (CR-04 server-side gate).'
			);
			// Safety: must NOT contain "Backups deleted" — that would mean the purge ran.
			$this->assertStringNotContainsString(
				'Backups%2520deleted',
				$e->location,
				'Redirect URL must NOT contain the success notice when purge_all_confirm is absent — purge must NOT have run.'
			);
		}
	}

	/**
	 * When purge_all_confirm is present but empty string, the server-side guard must
	 * still refuse (empty() is true for '').
	 *
	 * @return void
	 */
	public function test_purge_all_backups_refuses_when_confirm_field_is_empty_string(): void {
		$_POST = array( 'purge_all_confirm' => '' );

		$screen = new SqueezeScreen();

		try {
			$screen->handle_purge_all_backups();
			$this->fail( 'handle_purge_all_backups() must redirect when purge_all_confirm is empty string.' );
		} catch ( \AssetDripsTestRedirectException $e ) {
			$this->assertStringContainsString(
				'confirmation%2520box',
				$e->location,
				'Empty-string purge_all_confirm must trigger the error-redirect (CR-04 server-side gate).'
			);
		}
	}

	/**
	 * When purge_all_confirm is set to a truthy value, the handler must pass the
	 * server-side gate and proceed past it (it will then attempt BackupManager::from_wordpress()
	 * which calls global $wpdb — but at minimum it must NOT redirect with the error notice).
	 *
	 * Because BackupManager::from_wordpress() calls global $wpdb->get_col() (not stubbed
	 * for this test), the handler may throw or redirect with the success notice; either
	 * way the error-redirect must not appear. We assert the absence of the error notice
	 * URL fragment regardless of what the downstream path does.
	 *
	 * @return void
	 */
	public function test_purge_all_backups_passes_gate_when_confirm_field_is_truthy(): void {
		$_POST = array( 'purge_all_confirm' => '1' );

		$screen = new SqueezeScreen();

		try {
			$screen->handle_purge_all_backups();
			// If we reach here without exception the handler ran to completion (unlikely
			// without a real DB, but not impossible if wpdb stubs return empty arrays).
		} catch ( \AssetDripsTestRedirectException $e ) {
			// A redirect is expected (either success or an error from the DB path).
			// The important assertion: the CR-04 error notice must NOT be in the URL.
			$this->assertStringNotContainsString(
				'confirmation%2520box',
				$e->location,
				'When purge_all_confirm is truthy the server-side gate must NOT fire the error redirect (CR-04).'
			);
		} catch ( \Throwable $e ) {
			// Any non-redirect throwable (e.g. from wpdb stub) is acceptable here;
			// it means the handler passed the gate and reached the DB/BackupManager path.
			// The test passes — the gate did not block a legitimate request.
			$this->assertStringNotContainsString(
				'confirmation%2520box',
				$e->getMessage(),
				'When purge_all_confirm is truthy the CR-04 error redirect must not appear in any exception.'
			);
		}
	}
}
