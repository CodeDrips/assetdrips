<?php
/**
 * FolderFields unit tests — source-inspection scaffolding (Wave 0).
 *
 * Written BEFORE the source file lands (Plan 02). Each test guards with
 * class_exists() + markTestIncomplete() so the unit suite stays green until
 * FolderFields.php ships.
 *
 * @package AssetDrips
 */

declare( strict_types=1 );

namespace AssetDrips\Tests\Unit\Sort;

use PHPUnit\Framework\TestCase;

/**
 * Source-inspection tests for FolderFields:
 *   - save_folder_field key guard (missing assetdrips_folder key → early return)
 *   - clear path (empty string → wp_set_object_terms with [])
 *   - assign path (valid term_id → absint + wp_set_object_terms with [$term_id])
 *   - <select> name attribute convention: attachments[…][assetdrips_folder] (D-10)
 *
 * Covers FOLDER-03 / T-05-02 / T-05-04.
 */
final class FolderFieldsTest extends TestCase {

	/**
	 * Return the source-file contents for FolderFields.
	 *
	 * @return string
	 */
	private function folder_fields_source(): string {
		$this->assertTrue(
			class_exists( \AssetDrips\Sort\FolderFields::class ),
			'FolderFields class must exist — ensure Plan 02 has run'
		);
		$ref = new \ReflectionClass( \AssetDrips\Sort\FolderFields::class );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local file read for source inspection.
		return (string) file_get_contents( (string) $ref->getFileName() );
	}

	/**
	 * save_folder_field must early-return when assetdrips_folder key is missing.
	 *
	 * Also asserts the clear path (empty value → wp_set_object_terms([], ...)) and
	 * the assign path (valid term_id → absint + wp_set_object_terms([$term_id], ...)).
	 *
	 * @return void
	 */
	public function test_save_folder_field_guards_missing_key(): void {
		if ( ! class_exists( \AssetDrips\Sort\FolderFields::class ) ) {
			$this->markTestIncomplete( 'source lands in Plan 02' );
		}
		$contents = $this->folder_fields_source();

		// Guard: early return when key is absent.
		$this->assertStringContainsString(
			"isset( \$attachment['assetdrips_folder'] )",
			$contents,
			'save_folder_field must guard on isset($attachment[\'assetdrips_folder\']) (FOLDER-03)'
		);
	}

	/**
	 * Clear path: empty folder value clears assignment via wp_set_object_terms([]).
	 *
	 * @return void
	 */
	public function test_clear_path_uses_empty_array(): void {
		if ( ! class_exists( \AssetDrips\Sort\FolderFields::class ) ) {
			$this->markTestIncomplete( 'source lands in Plan 02' );
		}
		$contents = $this->folder_fields_source();

		$this->assertStringContainsString(
			"wp_set_object_terms( \$attachment_id, array(), 'assetdrips_folder', false )",
			$contents,
			'empty folder value must clear assignment via wp_set_object_terms([], ...) (FOLDER-03)'
		);
	}

	/**
	 * Assign path: valid term_id must be sanitized with absint and assigned via
	 * wp_set_object_terms([$term_id], ...).
	 *
	 * @return void
	 */
	public function test_assign_path_uses_absint_term_id(): void {
		if ( ! class_exists( \AssetDrips\Sort\FolderFields::class ) ) {
			$this->markTestIncomplete( 'source lands in Plan 02' );
		}
		$contents = $this->folder_fields_source();

		$this->assertStringContainsString(
			'absint(',
			$contents,
			'assign path must sanitize term_id via absint() (T-05-04)'
		);
		$this->assertStringContainsString(
			"wp_set_object_terms( \$attachment_id, array( \$term_id ), 'assetdrips_folder', false )",
			$contents,
			'valid term_id must assign via wp_set_object_terms([$term_id], ...) (FOLDER-03)'
		);
	}

	/**
	 * The <select> name attribute must follow the attachments[…][assetdrips_folder]
	 * convention so wp_ajax_save_attachment_compat populates $_POST['attachments'].
	 *
	 * @return void
	 */
	public function test_select_name_attribute_convention(): void {
		if ( ! class_exists( \AssetDrips\Sort\FolderFields::class ) ) {
			$this->markTestIncomplete( 'source lands in Plan 02' );
		}
		$contents = $this->folder_fields_source();

		$this->assertStringContainsString(
			'attachments[',
			$contents,
			"<select> name must start with 'attachments[' for the attachment edit form (D-10)"
		);
		$this->assertStringContainsString(
			'][assetdrips_folder]',
			$contents,
			"<select> name must end with '][assetdrips_folder]' (D-10)"
		);
	}
}
