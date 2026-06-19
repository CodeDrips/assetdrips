<?php
/**
 * TagFields unit tests — source-inspection scaffolding (Wave 0).
 *
 * Written BEFORE the source file lands (Task 2). Each test guards with
 * class_exists() + markTestIncomplete() so the unit suite stays green until
 * TagFields.php ships.
 *
 * @package AssetDrips
 */

declare( strict_types=1 );

namespace AssetDrips\Tests\Unit\Sort;

use PHPUnit\Framework\TestCase;

/**
 * Source-inspection tests for TagFields:
 *   - save_tag_field key guard (missing assetdrips_tags key → early return)
 *   - clear path (empty string → wp_set_object_terms with [])
 *   - assign path (comma-split names, not absint — freeform tags)
 *   - <input> name attribute convention: attachments[…][assetdrips_tags] (D-03/D-04)
 *   - append=false ensures full-set replace (not additive)
 *
 * Covers TAG-01 / T-06-02 / T-06-03.
 */
final class TagFieldsTest extends TestCase {

	/**
	 * Return the source-file contents for TagFields.
	 *
	 * @return string
	 */
	private function tag_fields_source(): string {
		$this->assertTrue(
			class_exists( \AssetDrips\Sort\TagFields::class ),
			'TagFields class must exist'
		);
		$ref = new \ReflectionClass( \AssetDrips\Sort\TagFields::class );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local file read for source inspection.
		return (string) file_get_contents( (string) $ref->getFileName() );
	}

	/**
	 * Save_tag_field must early-return when assetdrips_tags key is missing.
	 *
	 * @return void
	 */
	public function test_save_tag_field_guards_missing_key(): void {
		if ( ! class_exists( \AssetDrips\Sort\TagFields::class ) ) {
			$this->markTestIncomplete( 'source lands in Task 2' );
		}
		$contents = $this->tag_fields_source();

		$this->assertStringContainsString(
			"isset( \$attachment['assetdrips_tags'] )",
			$contents,
			"save_tag_field must guard on isset(\$attachment['assetdrips_tags']) (TAG-01)"
		);
	}

	/**
	 * Clear path: empty tag value clears all tags via wp_set_object_terms([]).
	 *
	 * @return void
	 */
	public function test_clear_path_uses_empty_array(): void {
		if ( ! class_exists( \AssetDrips\Sort\TagFields::class ) ) {
			$this->markTestIncomplete( 'source lands in Task 2' );
		}
		$contents = $this->tag_fields_source();

		$this->assertStringContainsString(
			"wp_set_object_terms( \$attachment_id, array(), 'assetdrips_tag', false )",
			$contents,
			'empty tag value must clear all tags via wp_set_object_terms([], ...) (TAG-01, D-05)'
		);
	}

	/**
	 * Assign path: names must be parsed from a comma-separated string (not absint/IDs).
	 *
	 * Freeform tags are stored as names so wp_set_object_terms auto-creates new terms.
	 * This test asserts explode(',', ...) is used rather than absint for term resolution.
	 *
	 * @return void
	 */
	public function test_assign_path_uses_names_not_ids(): void {
		if ( ! class_exists( \AssetDrips\Sort\TagFields::class ) ) {
			$this->markTestIncomplete( 'source lands in Task 2' );
		}
		$contents = $this->tag_fields_source();

		$this->assertStringContainsString(
			"explode( ',',",
			$contents,
			'assign path must parse names via explode(",", ...) not absint (TAG-01, D-04/D-05)'
		);
	}

	/**
	 * The <input> name attribute must follow the attachments[…][assetdrips_tags]
	 * convention so wp_ajax_save_attachment_compat populates $_POST['attachments'].
	 *
	 * @return void
	 */
	public function test_input_name_attribute_convention(): void {
		if ( ! class_exists( \AssetDrips\Sort\TagFields::class ) ) {
			$this->markTestIncomplete( 'source lands in Task 2' );
		}
		$contents = $this->tag_fields_source();

		$this->assertStringContainsString(
			'attachments[',
			$contents,
			"<input> name must start with 'attachments[' for the attachment edit form (D-03)"
		);
		$this->assertStringContainsString(
			'][assetdrips_tags]',
			$contents,
			"<input> name must end with '][assetdrips_tags]' (D-03)"
		);
	}

	/**
	 * Append=false must be used in wp_set_object_terms so full-set replace removes tags.
	 *
	 * @return void
	 */
	public function test_append_false_used(): void {
		if ( ! class_exists( \AssetDrips\Sort\TagFields::class ) ) {
			$this->markTestIncomplete( 'source lands in Task 2' );
		}
		$contents = $this->tag_fields_source();

		$this->assertStringContainsString(
			"'assetdrips_tag', false",
			$contents,
			"wp_set_object_terms must use append=false ('assetdrips_tag', false) to enable tag removal (D-04)"
		);
	}
}
