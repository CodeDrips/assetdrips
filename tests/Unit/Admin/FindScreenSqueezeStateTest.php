<?php
/**
 * RED tests for the three FindScreen squeeze_state controller thread-through sites (DASH-05).
 *
 * Site 2 — sanitize_filter_state(): squeeze_state key must survive round-trip.
 * Site 3 — handle_save_view(): squeeze_state from POST must persist into the
 *           saved-view filter_state (Pitfall 6 — saved views silently lose facet).
 * Site 4 — print_filter_form(): output must include <select name="squeeze_state">
 *           with the option values; active value must be marked selected.
 *
 * These tests are RED until Wave 1 threads squeeze_state through all four sites
 * in FindScreen.  They define the required behaviour; Wave 1 turns them GREEN.
 *
 * EXIT_EXPECTED_RED: Tests WILL fail because squeeze_state is not yet in the
 * $string_keys list (Site 2), not in the POST-key foreach (Site 3), and
 * print_filter_form() does not yet accept a $squeeze_state parameter (Site 4).
 * Failures must be assertion failures — NOT parse/bootstrap fatals.
 *
 * @package AssetDrips
 */

declare( strict_types=1 );

namespace AssetDrips\Tests\Unit\Admin;

use AssetDrips\Admin\FindScreen;
use PHPUnit\Framework\TestCase;

/**
 * RED tests: squeeze_state thread-through Sites 2/3/4 in FindScreen (DASH-05).
 */
final class FindScreenSqueezeStateTest extends TestCase {

	/**
	 * Reset user_meta stub and superglobals before each test.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Bootstrap stub.
		$GLOBALS['_assetdrips_user_meta_stub'] = array();

		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Bootstrap stub.
		$GLOBALS['_assetdrips_current_user_can_stub'] = true;

		// Clean superglobals so tests never bleed into each other.
		$_POST = array();
		$_GET  = array();
	}

	/**
	 * Tear down stubs and superglobals after each test.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		$_POST = array();
		$_GET  = array();

		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Bootstrap stub.
		$GLOBALS['_assetdrips_user_meta_stub'] = array();
		parent::tearDown();
	}

	// =========================================================================
	// Site 2: sanitize_filter_state() — squeeze_state round-trip
	// =========================================================================

	/**
	 * sanitize_filter_state() preserves the squeeze_state key and value when
	 * the key is present in the input array.
	 *
	 * RED: Fails because 'squeeze_state' is not in the $string_keys whitelist yet.
	 *
	 * @return void
	 */
	public function test_sanitize_filter_state_preserves_squeeze_state_key(): void {
		$screen = new FindScreen();

		$result = $screen->sanitize_filter_state(
			array( 'squeeze_state' => 'oversized' ) // RED: key dropped until Site 2 is wired.
		);

		$this->assertArrayHasKey(
			'squeeze_state',
			$result,
			'sanitize_filter_state() must preserve the squeeze_state key (Pitfall 6 / DASH-05 Site 2)'
		);

		$this->assertSame(
			'oversized',
			$result['squeeze_state'],
			'sanitize_filter_state() must preserve the squeeze_state value unchanged'
		);
	}

	/**
	 * sanitize_filter_state() preserves all four valid squeeze_state values.
	 *
	 * @return void
	 */
	public function test_sanitize_filter_state_preserves_all_valid_squeeze_state_values(): void {
		$screen = new FindScreen();

		foreach ( array( 'not-optimized', 'oversized', 'missing-webp', 'has-backup' ) as $value ) {
			$result = $screen->sanitize_filter_state( array( 'squeeze_state' => $value ) );

			$this->assertArrayHasKey(
				'squeeze_state',
				$result,
				"sanitize_filter_state() must preserve squeeze_state='$value' (DASH-05 Site 2)"
			);

			$this->assertSame(
				$value,
				$result['squeeze_state'],
				"sanitize_filter_state() must preserve squeeze_state value '$value' unchanged"
			);
		}
	}

	/**
	 * sanitize_filter_state() still drops keys that are not in the whitelist
	 * (regression guard — adding squeeze_state must not open a free-for-all).
	 *
	 * @return void
	 */
	public function test_sanitize_filter_state_still_drops_unknown_keys_after_squeeze_state_added(): void {
		$screen = new FindScreen();

		$result = $screen->sanitize_filter_state(
			array(
				'squeeze_state' => 'oversized',
				'evil_key'      => 'malicious',
			)
		);

		$this->assertArrayHasKey( 'squeeze_state', $result );
		$this->assertArrayNotHasKey( 'evil_key', $result, 'Unknown keys must still be dropped' );
	}

	// =========================================================================
	// Site 3: handle_save_view() — POST squeeze_state persists into saved view
	// =========================================================================

	/**
	 * handle_save_view() with $_POST['squeeze_state'] = 'oversized' persists the
	 * squeeze_state key into the saved-view filter_state array.
	 *
	 * RED: Fails because 'squeeze_state' is not in the POST-key foreach yet (Site 3).
	 *
	 * Strategy:
	 * 1. Seed $_POST with squeeze_state + a valid view_name.
	 * 2. check_admin_referer() is a no-op stub — always passes.
	 * 3. current_user_can() stub returns true.
	 * 4. handle_save_view() redirects on success → catch AssetDripsTestRedirectException.
	 * 5. Read back the saved view from user_meta and assert filter_state contains squeeze_state.
	 *
	 * @return void
	 */
	public function test_handle_save_view_persists_squeeze_state_into_saved_view_filter_state(): void {
		$_POST = array(
			'view_name'     => 'My Squeeze View',
			'squeeze_state' => 'oversized', // RED: not in POST-key foreach yet.
			'type'          => 'image',
		);

		$screen = new FindScreen();

		// handle_save_view() calls wp_safe_redirect() + exit on success.
		// The wp_safe_redirect stub throws AssetDripsTestRedirectException instead of exit.
		try {
			$screen->handle_save_view();
			$this->fail( 'handle_save_view() must redirect (throw AssetDripsTestRedirectException) on success' );
		} catch ( \AssetDripsTestRedirectException $e ) {
			// Success path — redirect was triggered as expected.
		}

		// Read back the persisted views from user_meta.
		$user_id = 1; // get_current_user_id() stub returns 1.
		$views   = $screen->get_saved_views( $user_id );

		$this->assertNotEmpty( $views, 'At least one view must have been saved by handle_save_view()' );

		// Find the view named 'My Squeeze View'.
		$saved_view = null;
		foreach ( $views as $view ) {
			if ( 'My Squeeze View' === $view['name'] ) {
				$saved_view = $view;
				break;
			}
		}

		$this->assertNotNull( $saved_view, '"My Squeeze View" must have been saved to user_meta' );

		$this->assertArrayHasKey(
			'squeeze_state',
			$saved_view['filters'],
			'Saved view filter_state must contain squeeze_state key (Pitfall 6 / DASH-05 Site 3)'
		);

		$this->assertSame(
			'oversized',
			$saved_view['filters']['squeeze_state'],
			'Saved view filter_state squeeze_state must equal the POST value "oversized"'
		);
	}

	/**
	 * handle_save_view() without squeeze_state in POST does not inject a
	 * squeeze_state key into the saved view (empty = omitted, not '').
	 *
	 * @return void
	 */
	public function test_handle_save_view_without_squeeze_state_in_post_omits_key(): void {
		$_POST = array(
			'view_name' => 'Plain View',
			'type'      => 'image',
		);

		$screen = new FindScreen();

		try {
			$screen->handle_save_view();
			$this->fail( 'handle_save_view() must redirect on success' );
		} catch ( \AssetDripsTestRedirectException $e ) {
			// Expected.
		}

		$views      = $screen->get_saved_views( 1 );
		$saved_view = null;
		foreach ( $views as $view ) {
			if ( 'Plain View' === $view['name'] ) {
				$saved_view = $view;
				break;
			}
		}

		$this->assertNotNull( $saved_view, '"Plain View" must have been saved' );
		$this->assertArrayNotHasKey(
			'squeeze_state',
			$saved_view['filters'],
			'Saved view must not contain squeeze_state when it was not in POST'
		);
	}

	// =========================================================================
	// Site 4: print_filter_form() — <select name="squeeze_state"> output
	// =========================================================================

	/**
	 * print_filter_form() output includes a <select name="squeeze_state"> control.
	 *
	 * RED: Fails because print_filter_form() does not yet accept $squeeze_state
	 * parameter and does not render the <select> control.
	 *
	 * Strategy: invoke the private method via ReflectionMethod with all required
	 * args + the new $squeeze_state = '' arg; capture output via ob_start.
	 *
	 * @return void
	 */
	public function test_print_filter_form_emits_squeeze_state_select_control(): void {
		$screen = new FindScreen();
		$ref    = new \ReflectionClass( $screen );

		$method = $ref->getMethod( 'print_filter_form' );
		$method->setAccessible( true );

		// Build the current parameter list by introspection so this test stays
		// aligned when new params are added.
		$params = $method->getParameters();
		$args   = array();
		foreach ( $params as $p ) {
			$name = $p->getName();
			if ( 'squeeze_state' === $name ) {
				$args[] = ''; // empty = Any state selected.
			} elseif ( $p->isDefaultValueAvailable() ) {
				$args[] = $p->getDefaultValue();
			} else {
				// Provide zero-value defaults by type.
				$type = $p->getType();
				if ( $type instanceof \ReflectionNamedType ) {
					$args[] = match ( $type->getName() ) {
						'int'    => 0,
						'bool'   => false,
						default  => '',
					};
				} else {
					$args[] = '';
				}
			}
		}

		ob_start();
		$method->invokeArgs( $screen, $args );
		$output = ob_get_clean();

		$this->assertStringContainsString(
			'<select name="squeeze_state"',
			$output,
			'print_filter_form() must emit a <select name="squeeze_state"> control (DASH-05 Site 4)'
		);

		// Must include all four option values.
		$this->assertStringContainsString(
			'not-optimized',
			$output,
			'print_filter_form() select must include option value "not-optimized"'
		);

		$this->assertStringContainsString(
			'oversized',
			$output,
			'print_filter_form() select must include option value "oversized"'
		);

		$this->assertStringContainsString(
			'missing-webp',
			$output,
			'print_filter_form() select must include option value "missing-webp"'
		);

		$this->assertStringContainsString(
			'has-backup',
			$output,
			'print_filter_form() select must include option value "has-backup"'
		);
	}

	/**
	 * print_filter_form() marks the active squeeze_state option as selected.
	 *
	 * RED: Fails because $squeeze_state parameter does not exist yet on
	 * print_filter_form() and the selected() call for squeeze_state is absent.
	 *
	 * @return void
	 */
	public function test_print_filter_form_marks_active_squeeze_state_option_as_selected(): void {
		$screen = new FindScreen();
		$ref    = new \ReflectionClass( $screen );

		$method = $ref->getMethod( 'print_filter_form' );
		$method->setAccessible( true );

		// Build args: set squeeze_state to 'oversized'.
		$params = $method->getParameters();
		$args   = array();
		foreach ( $params as $p ) {
			$name = $p->getName();
			if ( 'squeeze_state' === $name ) {
				$args[] = 'oversized'; // Active value — must be marked selected.
			} elseif ( $p->isDefaultValueAvailable() ) {
				$args[] = $p->getDefaultValue();
			} else {
				$type = $p->getType();
				if ( $type instanceof \ReflectionNamedType ) {
					$args[] = match ( $type->getName() ) {
						'int'    => 0,
						'bool'   => false,
						default  => '',
					};
				} else {
					$args[] = '';
				}
			}
		}

		ob_start();
		$method->invokeArgs( $screen, $args );
		$output = ob_get_clean();

		// The option with value="oversized" must be marked selected.
		// WordPress selected() function emits ' selected="selected"' or ' selected'.
		// We check that "oversized" and "selected" appear in proximity.
		$this->assertMatchesRegularExpression(
			'/oversized[^>]*selected|selected[^>]*oversized/i',
			$output,
			'print_filter_form() must mark the oversized option as selected when $squeeze_state=oversized (DASH-05 Site 4)'
		);
	}
}
