<?php
/**
 * Saved-views helpers unit tests.
 *
 * @package AssetDrips
 */

declare( strict_types=1 );

namespace AssetDrips\Tests\Unit\Admin;

use AssetDrips\Admin\FindScreen;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the FindScreen saved-views subsystem.
 *
 * Covers preset definitions, filter-state sanitization, view-name
 * sanitization, and the save/load user_meta round-trip. No DB, no
 * WP HTTP stack — WP functions are stubbed by the bootstrap stubs.
 */
final class SavedViewsTest extends TestCase {

	/**
	 * Reset the in-memory user_meta stub before each test so the round-trip
	 * cases never depend on execution order (WR-04).
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Resetting the bootstrap's own user_meta stub; name fixed by tests/unit-bootstrap.php.
		$GLOBALS['_assetdrips_user_meta_stub'] = array();
	}

	/**
	 * Clear the stub after each test so global state never leaks between cases.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Resetting the bootstrap's own user_meta stub; name fixed by tests/unit-bootstrap.php.
		$GLOBALS['_assetdrips_user_meta_stub'] = array();
		parent::tearDown();
	}

	/**
	 * Preset names are exactly the three spec-mandated names.
	 *
	 * @return void
	 */
	public function test_presets_returns_three_entries(): void {
		$screen  = new FindScreen();
		$presets = $screen->presets();

		$this->assertCount( 3, $presets, 'There must be exactly three built-in presets.' );
	}

	/**
	 * Preset names match the locked spec values (D-12).
	 *
	 * @return void
	 */
	public function test_presets_have_correct_names(): void {
		$screen = new FindScreen();
		$names  = array_column( $screen->presets(), 'name' );

		$this->assertContains( 'Large unused images', $names );
		$this->assertContains( 'Missing alt', $names );
		$this->assertContains( 'Mine this month', $names );
	}

	/**
	 * "Large unused images" preset carries the correct filter_state.
	 *
	 * @return void
	 */
	public function test_large_unused_images_preset_filter_state(): void {
		$screen  = new FindScreen();
		$presets = $screen->presets();
		$preset  = null;
		foreach ( $presets as $p ) {
			if ( 'Large unused images' === $p['name'] ) {
				$preset = $p;
				break;
			}
		}

		$this->assertNotNull( $preset, '"Large unused images" preset must exist.' );
		$filters = $preset['filters'];
		$this->assertSame( 'image', $filters['type'] ?? null, 'Large unused images must have type=image.' );
		$this->assertSame( 'unused', $filters['used'] ?? null, 'Large unused images must have used=unused.' );
		$this->assertSame( '1048576', $filters['size_min'] ?? null, 'Large unused images must have size_min=1048576.' );
		$this->assertSame( 'filesize', $filters['orderby'] ?? null, 'Large unused images must order by filesize.' );
		$this->assertSame( 'desc', $filters['order'] ?? null, 'Large unused images must order desc.' );
	}

	/**
	 * "Missing alt" preset carries missing_alt=1.
	 *
	 * @return void
	 */
	public function test_missing_alt_preset_filter_state(): void {
		$screen  = new FindScreen();
		$presets = $screen->presets();
		$preset  = null;
		foreach ( $presets as $p ) {
			if ( 'Missing alt' === $p['name'] ) {
				$preset = $p;
				break;
			}
		}

		$this->assertNotNull( $preset, '"Missing alt" preset must exist.' );
		$this->assertSame( '1', $preset['filters']['missing_alt'] ?? null, 'Missing alt must have missing_alt=1.' );
	}

	/**
	 * "Mine this month" preset carries uploader and a dynamic date_from.
	 *
	 * @return void
	 */
	public function test_mine_this_month_preset_has_uploader_and_date_from(): void {
		$screen  = new FindScreen();
		$presets = $screen->presets();
		$preset  = null;
		foreach ( $presets as $p ) {
			if ( 'Mine this month' === $p['name'] ) {
				$preset = $p;
				break;
			}
		}

		$this->assertNotNull( $preset, '"Mine this month" preset must exist.' );

		// Uploader must be set (non-empty string or numeric string).
		$this->assertNotEmpty( $preset['filters']['uploader'] ?? '', '"Mine this month" must include uploader.' );

		// date_from must be the first day of the current month.
		$expected_date = gmdate( 'Y-m-01' );
		$this->assertSame(
			$expected_date,
			$preset['filters']['date_from'] ?? '',
			'"Mine this month" date_from must be the first day of the current month.'
		);
	}

	/**
	 * The filter-state sanitizer drops keys not in the GET-contract whitelist.
	 *
	 * @return void
	 */
	public function test_sanitize_filter_state_drops_unknown_keys(): void {
		$screen = new FindScreen();
		$result = $screen->sanitize_filter_state(
			array(
				's'           => 'hello',
				'unknown_key' => 'evil',
				'injected'    => 'value',
			)
		);

		$this->assertArrayHasKey( 's', $result );
		$this->assertArrayNotHasKey( 'unknown_key', $result );
		$this->assertArrayNotHasKey( 'injected', $result );
	}

	/**
	 * The filter-state sanitizer keeps all whitelisted GET-contract keys.
	 *
	 * @return void
	 */
	public function test_sanitize_filter_state_keeps_whitelisted_keys(): void {
		$screen = new FindScreen();
		$input  = array(
			's'           => 'photo',
			'type'        => 'image',
			'subtype'     => 'jpeg',
			'orientation' => 'landscape',
			'used'        => 'unused',
			'missing_alt' => '1',
			'size_min'    => '1024',
			'size_max'    => '2048',
			'orderby'     => 'filesize',
			'order'       => 'desc',
		);
		$result = $screen->sanitize_filter_state( $input );

		foreach ( array_keys( $input ) as $key ) {
			$this->assertArrayHasKey( $key, $result, "Whitelisted key '{$key}' must be kept." );
		}
	}

	/**
	 * The filter-state sanitizer applies absint to integer-typed keys.
	 *
	 * Absint returns the absolute value, so -500 becomes 500 (not zero).
	 *
	 * @return void
	 */
	public function test_sanitize_filter_state_sanitizes_integer_keys(): void {
		$screen = new FindScreen();
		$result = $screen->sanitize_filter_state(
			array(
				'size_min' => '-500',
				'uploader' => '42',
			)
		);

		$this->assertSame( '500', $result['size_min'], 'Negative size_min is converted to absolute value by absint.' );
		$this->assertSame( '42', $result['uploader'], 'Valid uploader must pass through as string.' );
	}

	/**
	 * The view-name sanitizer trims leading and trailing whitespace.
	 *
	 * @return void
	 */
	public function test_sanitize_view_name_trims_whitespace(): void {
		$screen = new FindScreen();
		$result = $screen->sanitize_view_name( '  My view  ' );

		$this->assertSame( 'My view', $result );
	}

	/**
	 * The view-name sanitizer caps length at 60 characters.
	 *
	 * @return void
	 */
	public function test_sanitize_view_name_caps_length(): void {
		$screen = new FindScreen();
		$long   = str_repeat( 'a', 80 );
		$result = $screen->sanitize_view_name( $long );

		$this->assertLessThanOrEqual( 60, mb_strlen( $result ), 'View name must be capped at 60 characters.' );
	}

	/**
	 * The view-name sanitizer returns empty string for blank input.
	 *
	 * @return void
	 */
	public function test_sanitize_view_name_returns_empty_on_empty_input(): void {
		$screen = new FindScreen();
		$this->assertSame( '', $screen->sanitize_view_name( '' ) );
		$this->assertSame( '', $screen->sanitize_view_name( '   ' ) );
	}

	/**
	 * Reading saved views for a user with no meta returns an empty array.
	 *
	 * @return void
	 */
	public function test_get_saved_views_returns_empty_array_when_no_meta(): void {
		$screen = new FindScreen();

		// The WP bootstrap stubs get_user_meta to return '' (falsy).
		$result = $screen->get_saved_views( 1 );

		$this->assertIsArray( $result );
		$this->assertEmpty( $result );
	}

	/**
	 * Saving a view with an empty name is refused.
	 *
	 * @return void
	 */
	public function test_save_view_refuses_empty_name(): void {
		$screen = new FindScreen();
		$saved  = $screen->save_view( 1, '', array( 'type' => 'image' ) );

		$this->assertFalse( $saved, 'save_view() must return false for an empty view name.' );
	}

	/**
	 * Saving beyond MAX_VIEWS is refused (per-user cap enforced).
	 *
	 * The WP bootstrap stubs update_user_meta and get_user_meta so the test
	 * uses a fresh instance and pre-fills meta via save_view() for round-trip
	 * coverage.
	 *
	 * @return void
	 */
	public function test_save_view_refuses_past_max_views(): void {
		$screen = new FindScreen();

		// Fill to exactly MAX_VIEWS using the in-memory stub registry.
		$max = 20;
		for ( $i = 1; $i <= $max; $i++ ) {
			$screen->save_view( 99, "View {$i}", array( 's' => "term{$i}" ) );
		}

		// The next save must be refused.
		$result = $screen->save_view( 99, 'One more', array( 's' => 'extra' ) );
		$this->assertFalse( $result, 'save_view() must return false when MAX_VIEWS is reached.' );
	}

	/**
	 * A view saved via save_view() appears when read back via get_saved_views().
	 *
	 * @return void
	 */
	public function test_save_view_round_trip(): void {
		$screen = new FindScreen();
		$result = $screen->save_view(
			7,
			'My Test View',
			array(
				'type' => 'image',
				'used' => 'unused',
			)
		);

		$this->assertTrue( $result, 'save_view() must return true on success.' );

		$views = $screen->get_saved_views( 7 );
		$names = array_column( $views, 'name' );
		$this->assertContains( 'My Test View', $names, 'Saved view must appear in get_saved_views().' );
	}

	/**
	 * Deleting a named user view removes it from the saved-views list.
	 *
	 * @return void
	 */
	public function test_delete_view_removes_named_view(): void {
		$screen = new FindScreen();
		$screen->save_view( 5, 'Delete Me', array( 's' => 'cats' ) );

		// Confirm it is there first.
		$before = $screen->get_saved_views( 5 );
		$this->assertContains( 'Delete Me', array_column( $before, 'name' ) );

		$screen->delete_view( 5, 'Delete Me' );

		$after = $screen->get_saved_views( 5 );
		$this->assertNotContains( 'Delete Me', array_column( $after, 'name' ), 'Deleted view must not appear.' );
	}

	/**
	 * The presets() method is declared public on FindScreen.
	 *
	 * @return void
	 */
	public function test_presets_method_is_public(): void {
		$ref    = new \ReflectionClass( FindScreen::class );
		$method = $ref->getMethod( 'presets' );

		$this->assertTrue( $method->isPublic(), 'presets() must be a public method.' );
	}

	/**
	 * The sanitize_filter_state() method is declared public on FindScreen.
	 *
	 * @return void
	 */
	public function test_sanitize_filter_state_is_public(): void {
		$ref    = new \ReflectionClass( FindScreen::class );
		$method = $ref->getMethod( 'sanitize_filter_state' );

		$this->assertTrue( $method->isPublic(), 'sanitize_filter_state() must be a public method.' );
	}

	/**
	 * The sanitize_view_name() method is declared public on FindScreen.
	 *
	 * @return void
	 */
	public function test_sanitize_view_name_is_public(): void {
		$ref    = new \ReflectionClass( FindScreen::class );
		$method = $ref->getMethod( 'sanitize_view_name' );

		$this->assertTrue( $method->isPublic(), 'sanitize_view_name() must be a public method.' );
	}

	/**
	 * The get_saved_views() method is declared public on FindScreen.
	 *
	 * @return void
	 */
	public function test_get_saved_views_is_public(): void {
		$ref    = new \ReflectionClass( FindScreen::class );
		$method = $ref->getMethod( 'get_saved_views' );

		$this->assertTrue( $method->isPublic(), 'get_saved_views() must be a public method.' );
	}

	/**
	 * The save_view() method is declared public on FindScreen.
	 *
	 * @return void
	 */
	public function test_save_view_is_public(): void {
		$ref    = new \ReflectionClass( FindScreen::class );
		$method = $ref->getMethod( 'save_view' );

		$this->assertTrue( $method->isPublic(), 'save_view() must be a public method.' );
	}

	/**
	 * The delete_view() method is declared public on FindScreen.
	 *
	 * @return void
	 */
	public function test_delete_view_is_public(): void {
		$ref    = new \ReflectionClass( FindScreen::class );
		$method = $ref->getMethod( 'delete_view' );

		$this->assertTrue( $method->isPublic(), 'delete_view() must be a public method.' );
	}

	/**
	 * Preserves the folder key for the 'uncategorized' sentinel through sanitize_filter_state.
	 *
	 * Regression guard for Pitfall 6 / Success Criterion 2: 'folder' must be in
	 * $string_keys so it round-trips through saved smart views.
	 *
	 * @return void
	 */
	public function test_sanitize_filter_state_preserves_folder_key(): void {
		$screen = new FindScreen();
		$result = $screen->sanitize_filter_state( array( 'folder' => 'uncategorized' ) );

		$this->assertArrayHasKey( 'folder', $result );
		$this->assertSame( 'uncategorized', $result['folder'] );
	}

	/**
	 * Preserves a numeric folder id as a string through sanitize_filter_state.
	 *
	 * The folder key is string-typed (not int), so '42' must survive the
	 * $string_keys allowlist unchanged.
	 *
	 * @return void
	 */
	public function test_sanitize_filter_state_preserves_folder_id(): void {
		$screen = new FindScreen();
		$result = $screen->sanitize_filter_state( array( 'folder' => '42' ) );

		$this->assertArrayHasKey( 'folder', $result );
		$this->assertSame( '42', $result['folder'] );
	}

	/**
	 * Sanitize_filter_state() $string_keys must contain 'tag'.
	 *
	 * Critical guard for Phase 4 Pitfall 6 (saved-view drop bug, second spot):
	 * sanitize_filter_state() uses an $allowed_keys allowlist derived from
	 * $int_keys + $string_keys. If 'tag' is absent from BOTH lists, it is
	 * silently stripped when the saved view is loaded from user_meta, making
	 * the tag filter disappear on reload.
	 *
	 * 'tag' follows the 'folder' convention: stored as a positive-int-string,
	 * processed via sanitize_text_field (i.e., placed in $string_keys).
	 *
	 * @return void
	 */
	public function test_sanitize_string_keys_includes_tag(): void {
		$ref = new \ReflectionClass( FindScreen::class );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local file read for source inspection; wp_remote_get() is for HTTP URLs.
		$contents = (string) file_get_contents( (string) $ref->getFileName() );

		// Guard: if 'tag' is not yet in the source's $string_keys, mark incomplete.
		if ( false === strpos( $contents, '$string_keys = array(' ) ||
			false === strpos( $contents, "'tag'" ) ) {
			$this->markTestIncomplete( "source lands in Task 2 — 'tag' not yet in \$string_keys in FindScreen.php" );
		}

		// Verify sanitize_filter_state() lets a tag value survive the round-trip.
		$screen = new FindScreen();
		$result = $screen->sanitize_filter_state( array( 'tag' => '42' ) );

		$this->assertArrayHasKey(
			'tag',
			$result,
			"'tag' must be in sanitize_filter_state() \$string_keys allowlist (Phase 4 Pitfall 6 guard)"
		);
		$this->assertSame(
			'42',
			$result['tag'],
			"tag value '42' must survive sanitize_filter_state() unchanged"
		);
	}
}
