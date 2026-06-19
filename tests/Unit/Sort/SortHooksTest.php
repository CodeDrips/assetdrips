<?php
/**
 * SortHooks unit tests.
 *
 * @package AssetDrips
 */

declare( strict_types=1 );

namespace AssetDrips\Tests\Unit\Sort;

use AssetDrips\Sort\SortHooks;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for SortHooks guard and hook-registration behavior.
 *
 * Uses source inspection (the same technique as FindScreenTest) because the
 * Unit suite runs without a live WordPress environment. Tests that require
 * wp_get_object_terms() and a real DB belong in a future Integration suite.
 */
final class SortHooksTest extends TestCase {

	/**
	 * Non-folder taxonomies are silently ignored — no DB write is triggered.
	 *
	 * Verifies via source inspection that the guard (if 'assetdrips_folder' !==
	 * $taxonomy return) appears BEFORE any call to update_folder_id or $wpdb,
	 * mirroring FindScreenTest's source-inspection pattern for guard ordering.
	 *
	 * @return void
	 */
	public function test_non_folder_taxonomy_is_ignored(): void {
		$hooks = new SortHooks();
		$ref   = new \ReflectionClass( $hooks );

		$method = $ref->getMethod( 'on_set_object_terms' );
		$this->assertTrue( $method->isPublic(), 'on_set_object_terms must be public' );

		$file = $ref->getFileName();
		$this->assertNotFalse( $file, 'SortHooks.php must be locatable' );

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local file read for source inspection; wp_remote_get() is for HTTP URLs.
		$contents = file_get_contents( (string) $file );
		$this->assertNotFalse( $contents, 'SortHooks.php must be readable' );

		// Guard must check the taxonomy — look for the if-statement form to avoid
		// matching occurrences in docblock comments.
		$this->assertStringContainsString(
			"if ( 'assetdrips_folder' !== \$taxonomy )",
			$contents,
			"on_set_object_terms must guard on if ( 'assetdrips_folder' !== \$taxonomy )"
		);

		// Guard if-statement must appear BEFORE the wp_get_object_terms resolve call.
		$guard_pos   = (int) strpos( $contents, "if ( 'assetdrips_folder' !== \$taxonomy )" );
		$resolve_pos = (int) strpos( $contents, 'wp_get_object_terms(' );

		$this->assertGreaterThan( 0, $guard_pos, 'Taxonomy guard if-statement must be present' );
		$this->assertGreaterThan( 0, $resolve_pos, 'wp_get_object_terms() call must be present' );
		$this->assertLessThan(
			$resolve_pos,
			$guard_pos,
			'Taxonomy guard must appear before wp_get_object_terms (guard-first pattern)'
		);

		// Guard return must also appear before any update_folder_id delegation.
		$write_pos = (int) strpos( $contents, '$this->update_folder_id(' );
		$this->assertGreaterThan( 0, $write_pos, 'update_folder_id call must be present' );
		$this->assertLessThan(
			$write_pos,
			$guard_pos,
			'Taxonomy guard must appear before update_folder_id (no write on non-folder taxonomy)'
		);
	}

	/**
	 * The register() method wires the set_object_terms action with 6 accepted arguments.
	 *
	 * Verifies via source inspection that register() contains the canonical
	 * add_action call for the set_object_terms hook, mirroring FindScreenTest's
	 * hook-registration inspection pattern.
	 *
	 * @return void
	 */
	public function test_register_adds_set_object_terms_hook(): void {
		$hooks = new SortHooks();
		$ref   = new \ReflectionClass( $hooks );

		$file = (string) $ref->getFileName();
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local file read for source inspection; wp_remote_get() is for HTTP URLs.
		$contents = (string) file_get_contents( $file );

		// register() must call add_action with the set_object_terms hook.
		$this->assertStringContainsString(
			"add_action( 'set_object_terms'",
			$contents,
			"register() must call add_action('set_object_terms', ...)"
		);

		// Must pass 10 as priority and 6 as accepted-args count.
		$this->assertStringContainsString(
			'10, 6',
			$contents,
			'set_object_terms must be registered with priority 10 and 6 accepted args'
		);

		// The callback must be on_set_object_terms.
		$this->assertStringContainsString(
			"'on_set_object_terms'",
			$contents,
			'set_object_terms callback must be on_set_object_terms'
		);
	}

	/**
	 * register() must wire pre_delete_term hook for D-08 folder_id NULL reversion.
	 *
	 * @return void
	 */
	public function test_register_adds_pre_delete_term_hook(): void {
		if ( ! method_exists( SortHooks::class, 'on_pre_delete_term' ) ) {
			$this->markTestIncomplete( 'on_pre_delete_term method lands in Plan 02' );
		}
		$hooks = new SortHooks();
		$ref   = new \ReflectionClass( $hooks );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local file read for source inspection; wp_remote_get() is for HTTP URLs.
		$contents = (string) file_get_contents( (string) $ref->getFileName() );

		$this->assertStringContainsString(
			"add_action( 'pre_delete_term'",
			$contents,
			"register() must call add_action('pre_delete_term', ...) for D-08"
		);
		$this->assertStringContainsString(
			"'on_pre_delete_term'",
			$contents,
			'pre_delete_term callback must be on_pre_delete_term'
		);
	}

	/**
	 * on_pre_delete_term ignores non-folder taxonomies (taxonomy guard).
	 *
	 * Verifies via source inspection that the taxonomy guard appears BEFORE the
	 * folder_id = NULL UPDATE query (same guard-first pattern as on_set_object_terms).
	 *
	 * @return void
	 */
	public function test_on_pre_delete_term_guards_non_folder_taxonomy(): void {
		if ( ! method_exists( SortHooks::class, 'on_pre_delete_term' ) ) {
			$this->markTestIncomplete( 'on_pre_delete_term method lands in Plan 02' );
		}
		$hooks = new SortHooks();
		$ref   = new \ReflectionClass( $hooks );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local file read for source inspection; wp_remote_get() is for HTTP URLs.
		$contents = (string) file_get_contents( (string) $ref->getFileName() );

		// Guard must be the same pattern as on_set_object_terms.
		$this->assertStringContainsString(
			"if ( 'assetdrips_folder' !== \$taxonomy )",
			$contents,
			"on_pre_delete_term must guard on if ( 'assetdrips_folder' !== \$taxonomy )"
		);

		// Guard return must appear before the UPDATE query.
		$guard_pos  = strrpos( $contents, "if ( 'assetdrips_folder' !== \$taxonomy )" );
		$update_pos = strrpos( $contents, "folder_id = NULL WHERE folder_id = %d" );
		$this->assertNotFalse( $guard_pos, 'Taxonomy guard if-statement must be present' );
		$this->assertNotFalse( $update_pos, 'folder_id = NULL UPDATE must be present in on_pre_delete_term' );
		$this->assertLessThan(
			$update_pos,
			$guard_pos,
			'taxonomy guard must precede the UPDATE query (guard-first pattern)'
		);
	}
}
