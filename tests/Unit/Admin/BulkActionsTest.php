<?php
/**
 * BulkActions unit tests — source-inspection assertions (Plan 02).
 *
 * Tests verify the transport contract, nonce approach, op whitelist,
 * per-item capability guard, and SEL-02 id-resolution path via direct
 * source inspection (ReflectionClass / file_get_contents).
 *
 * @package AssetDrips
 */

declare( strict_types=1 );

namespace AssetDrips\Tests\Unit\Admin;

use PHPUnit\Framework\TestCase;

/**
 * Source-inspection tests for BulkActions.
 *
 * Five tests for Task 1 (transport/nonce/whitelist/cap/ids) and five tests for
 * Task 2 (op bodies + Plugin wiring). All assertions are purely source-level
 * (no WP bootstrap required).
 */
final class BulkActionsTest extends TestCase {

	/**
	 * Return the BulkActions source file contents for inspection.
	 *
	 * Fails with a clear message if the class has not been created yet.
	 *
	 * @return string
	 */
	private function bulk_actions_source(): string {
		$this->assertTrue(
			class_exists( \AssetDrips\Admin\BulkActions::class ),
			'BulkActions class must exist — ensure Plan 02 has run'
		);
		$ref = new \ReflectionClass( \AssetDrips\Admin\BulkActions::class );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local file read for source inspection.
		return (string) file_get_contents( (string) $ref->getFileName() );
	}

	// -----------------------------------------------------------------------
	// Task 1: Transport, nonce, whitelist, cap, ids resolution
	// -----------------------------------------------------------------------

	/**
	 * BulkActions must read the nonce from the JSON body and verify it with
	 * wp_verify_nonce(), NOT check_ajax_referer().
	 *
	 * JSON POST body leaves $_POST empty, so check_ajax_referer() would always
	 * fail (07-RESEARCH Pitfall 6 / D-15).
	 *
	 * @return void
	 */
	public function test_handler_uses_json_body_nonce(): void {
		$contents = $this->bulk_actions_source();
		$this->assertStringContainsString(
			'php://input',
			$contents,
			'BulkActions must read the request body via php://input (JSON body path)'
		);
		$this->assertStringContainsString(
			'wp_verify_nonce',
			$contents,
			'BulkActions must verify the nonce with wp_verify_nonce() from the decoded JSON body'
		);
		$this->assertStringNotContainsString(
			'check_ajax_referer',
			$contents,
			'BulkActions must NOT use check_ajax_referer() — it reads empty $_POST for application/json requests (Pitfall 6)'
		);
	}

	/**
	 * The op param must be whitelisted against exactly four allowed operations.
	 *
	 * The whitelist must use strict-mode in_array (third arg true) to prevent
	 * type-juggling bypasses (07-PATTERNS §"Enum whitelisting").
	 *
	 * @return void
	 */
	public function test_op_whitelist(): void {
		$contents = $this->bulk_actions_source();
		$this->assertStringContainsString(
			'folder_assign',
			$contents,
			'Op whitelist must include folder_assign (FOLDER-04)'
		);
		$this->assertStringContainsString(
			'tag_add',
			$contents,
			'Op whitelist must include tag_add (TAG-02)'
		);
		$this->assertStringContainsString(
			'tag_remove',
			$contents,
			'Op whitelist must include tag_remove (TAG-02)'
		);
		$this->assertStringContainsString(
			'meta_edit',
			$contents,
			'Op whitelist must include meta_edit (BULK-01/02)'
		);
		$this->assertStringContainsString(
			'in_array',
			$contents,
			'Op whitelist must use in_array() for membership check'
		);
		// Strict mode (third argument = true).
		$this->assertMatchesRegularExpression(
			'/in_array\s*\(.*true\s*\)/s',
			$contents,
			'Op in_array check must use strict=true to prevent type-juggling bypass'
		);
	}

	/**
	 * Every item in the batch must be gated by current_user_can('edit_post',$id).
	 *
	 * A failure must be recorded as {id,ok:false} and the loop must continue
	 * (never abort the batch) — D-07.
	 *
	 * @return void
	 */
	public function test_per_item_cap_check(): void {
		$contents = $this->bulk_actions_source();
		$this->assertStringContainsString(
			"'edit_post'",
			$contents,
			'Per-item current_user_can(\'edit_post\',$id) gate must be present in the loop (D-07)'
		);
		$this->assertStringContainsString(
			"'ok'",
			$contents,
			'Failed items must record {ok: false} in the results array — ok key must exist'
		);
		// The formatter may add alignment spaces; check for => false on its own line.
		$this->assertMatchesRegularExpression(
			"/'ok'\s+=>\s+false/",
			$contents,
			'Failed items must record {id, ok: false} in the results array'
		);
		$this->assertStringContainsString(
			'continue',
			$contents,
			'Per-item failure must use continue — the batch must never abort (D-07)'
		);
	}

	/**
	 * The select_all_matching branch must call MediaIndex::ids() to re-derive the
	 * full id set server-side (SEL-02 — never trust client id blob as authorization).
	 *
	 * The explicit-ids branch must map each value through absint().
	 *
	 * @return void
	 */
	public function test_ids_resolution_reuses_index(): void {
		$contents = $this->bulk_actions_source();
		$this->assertStringContainsString(
			'->ids(',
			$contents,
			'BulkActions must call MediaIndex::ids() for the select_all_matching branch (SEL-02 / D-03)'
		);
		$this->assertStringContainsString(
			'absint',
			$contents,
			'BulkActions must apply absint() to explicit id values (T-07-sqli)'
		);
	}

	/**
	 * The AJAX_NONCE constant must share FindScreen's 'assetdrips_ajax' value.
	 *
	 * This ensures the same nonce token issued by FindScreen works for bulk-op
	 * requests without a separate wp_create_nonce() call on the JS side.
	 *
	 * @return void
	 */
	public function test_shares_find_nonce(): void {
		$contents = $this->bulk_actions_source();
		$this->assertStringContainsString(
			"'assetdrips_ajax'",
			$contents,
			'BulkActions AJAX_NONCE constant must equal \'assetdrips_ajax\' (shares FindScreen nonce)'
		);
	}

	// -----------------------------------------------------------------------
	// Task 2: Op bodies — folder/tag/meta — and Plugin wiring
	// -----------------------------------------------------------------------

	/**
	 * Bulk folder assign must use wp_set_object_terms with append=false
	 * (replace-set to a single folder, or empty array for Uncategorized).
	 *
	 * @return void
	 */
	public function test_folder_assign_uses_set_object_terms(): void {
		$contents = $this->bulk_actions_source();
		$this->assertStringContainsString(
			"'assetdrips_folder', false",
			$contents,
			'folder_assign must use wp_set_object_terms($id, [...], \'assetdrips_folder\', false) — replace-set single folder (D-13)'
		);
	}

	/**
	 * Bulk tag add must use append=true (preserves other tags).
	 * Bulk tag remove must use wp_remove_object_terms.
	 *
	 * TagFields uses append=false (replace-set) — bulk MUST diverge (D-14 / Pitfall 4).
	 *
	 * @return void
	 */
	public function test_tag_add_appends_not_replaces(): void {
		$contents = $this->bulk_actions_source();
		$this->assertStringContainsString(
			"'assetdrips_tag', true",
			$contents,
			'tag_add must use wp_set_object_terms($id, [...], \'assetdrips_tag\', true) — append=true preserves other tags (D-14)'
		);
		$this->assertStringContainsString(
			'wp_remove_object_terms',
			$contents,
			'tag_remove must use wp_remove_object_terms() to remove only the named tag (D-14)'
		);
		$this->assertStringNotContainsString(
			"'assetdrips_tag', false",
			$contents,
			'Bulk tag must NEVER use replace-set (append=false) — destroys existing tags (Pitfall 4)'
		);
	}

	/**
	 * Meta_edit must build the $post_data array conditionally (blank = unchanged)
	 * and call wp_update_post only when there is at least one field to write.
	 *
	 * @return void
	 */
	public function test_meta_blank_leaves_unchanged(): void {
		$contents = $this->bulk_actions_source();
		$this->assertStringContainsString(
			"'' !==",
			$contents,
			'meta_edit must guard each field with \'\' !== $value before adding it to $post_data (D-09 blank=unchanged)'
		);
		$this->assertStringContainsString(
			'count( $post_data ) > 1',
			$contents,
			'wp_update_post must only be called when count($post_data) > 1 (skips ID-only array — D-09)'
		);
	}

	/**
	 * The alt fill-empty-only branch must read the current alt value and skip
	 * the update when the current value is non-empty.
	 *
	 * @return void
	 */
	public function test_fill_empty_only_skips_alt(): void {
		$contents = $this->bulk_actions_source();
		$this->assertStringContainsString(
			'fill_empty_only',
			$contents,
			'meta_edit must check the fill_empty_only flag for the alt field (D-10 / BULK-01)'
		);
		$this->assertStringContainsString(
			'get_post_meta',
			$contents,
			'fill_empty_only path must read current alt via get_post_meta() before deciding to skip (D-10)'
		);
	}

	/**
	 * Plugin.php must register BulkActions inside the is_admin() block.
	 *
	 * @return void
	 */
	public function test_plugin_wires_bulkactions(): void {
		$ref = new \ReflectionClass( \AssetDrips\Plugin::class );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local file read for source inspection.
		$plugin_source = (string) file_get_contents( (string) $ref->getFileName() );
		$this->assertStringContainsString(
			'new BulkActions()',
			$plugin_source,
			'Plugin.php must instantiate BulkActions inside is_admin() (07-PATTERNS §Plugin wiring)'
		);
		$this->assertStringContainsString(
			'use AssetDrips\\Admin\\BulkActions',
			$plugin_source,
			'Plugin.php must import BulkActions with a use statement'
		);
	}

	// -----------------------------------------------------------------------
	// Fix CR-01: build_media_query() must use correct MediaQuery field names
	// -----------------------------------------------------------------------

	/**
	 * Build_media_query() must set $q->type, $q->subtype, $q->missing_alt,
	 * $q->width_min, $q->width_max, $q->height_min, $q->height_max,
	 * $q->size_min, $q->size_max — not the bogus mime/has_alt properties that
	 * MediaQuery does not define (CR-01).
	 *
	 * @return void
	 */
	public function test_build_media_query_uses_correct_field_names(): void {
		$contents = $this->bulk_actions_source();

		// Must set real MediaQuery fields.
		$this->assertStringContainsString(
			'$q->type',
			$contents,
			'build_media_query() must set $q->type (real MediaQuery field) not $q->mime (CR-01)'
		);
		$this->assertStringContainsString(
			'$q->subtype',
			$contents,
			'build_media_query() must set $q->subtype (real MediaQuery field) (CR-01)'
		);
		$this->assertStringContainsString(
			'$q->missing_alt',
			$contents,
			'build_media_query() must set $q->missing_alt (real MediaQuery field) not $q->has_alt (CR-01)'
		);
		$this->assertStringContainsString(
			'$q->width_min',
			$contents,
			'build_media_query() must set $q->width_min from w_min filter key (CR-01)'
		);
		$this->assertStringContainsString(
			'$q->width_max',
			$contents,
			'build_media_query() must set $q->width_max from w_max filter key (CR-01)'
		);
		$this->assertStringContainsString(
			'$q->height_min',
			$contents,
			'build_media_query() must set $q->height_min from h_min filter key (CR-01)'
		);
		$this->assertStringContainsString(
			'$q->height_max',
			$contents,
			'build_media_query() must set $q->height_max from h_max filter key (CR-01)'
		);
		$this->assertStringContainsString(
			'$q->size_min',
			$contents,
			'build_media_query() must set $q->size_min (CR-01)'
		);
		$this->assertStringContainsString(
			'$q->size_max',
			$contents,
			'build_media_query() must set $q->size_max (CR-01)'
		);

		// Must NOT set dynamic/non-existent properties.
		$this->assertStringNotContainsString(
			'$q->mime',
			$contents,
			'build_media_query() must NOT set $q->mime — MediaQuery has no mime property; use $q->type (CR-01)'
		);
		$this->assertStringNotContainsString(
			'$q->has_alt',
			$contents,
			'build_media_query() must NOT set $q->has_alt — MediaQuery has no has_alt property; use $q->missing_alt (CR-01)'
		);
	}

	/**
	 * GET keys w_min/w_max/h_min/h_max must map to MediaQuery width_X/height_X
	 * fields (not w_X/h_X which do not exist) — mirrors FindScreen's mapping (CR-01).
	 *
	 * @return void
	 */
	public function test_build_media_query_maps_wh_keys_to_width_height_fields(): void {
		$contents = $this->bulk_actions_source();

		// The filter key is 'w_min' but the MediaQuery field is 'width_min'.
		$this->assertMatchesRegularExpression(
			"/width_min\s*=.*\bw_min\b/s",
			$contents,
			"build_media_query() must map filter key 'w_min' → \$q->width_min (CR-01)"
		);
		$this->assertMatchesRegularExpression(
			"/height_min\s*=.*\bh_min\b/s",
			$contents,
			"build_media_query() must map filter key 'h_min' → \$q->height_min (CR-01)"
		);
	}

	// -----------------------------------------------------------------------
	// Fix CR-02: select-all-matching must paginate via batch_offset
	// -----------------------------------------------------------------------

	/**
	 * The select_all_matching branch must NOT hard-cap the entire matching set at 50.
	 * The ids() call must resolve the full set; array_slice uses batch_offset for
	 * per-request pagination so the JS driver can loop to completion (CR-02).
	 *
	 * @return void
	 */
	public function test_select_all_matching_is_offset_paginated(): void {
		$contents = $this->bulk_actions_source();

		// Response must include total_matching and has_more for the JS loop.
		$this->assertStringContainsString(
			'total_matching',
			$contents,
			'handle_bulk_op() must return total_matching in select_all_matching responses (CR-02)'
		);
		$this->assertStringContainsString(
			'has_more',
			$contents,
			'handle_bulk_op() must return has_more in select_all_matching responses (CR-02)'
		);
		$this->assertStringContainsString(
			'next_offset',
			$contents,
			'handle_bulk_op() must return next_offset in select_all_matching responses (CR-02)'
		);
		$this->assertStringContainsString(
			'batch_offset',
			$contents,
			'handle_bulk_op() must read batch_offset from the request for select_all_matching pagination (CR-02)'
		);

		// The full id set must be sliced by offset, NOT hard-limited before slicing.
		// Confirm array_slice uses batch_offset (not a fixed 0 start on the full set).
		$this->assertMatchesRegularExpression(
			'/array_slice\s*\(\s*\$all_ids\s*,\s*\$batch_offset/s',
			$contents,
			'select_all_matching must slice $all_ids from $batch_offset, not array_slice($ids, 0, 50) (CR-02)'
		);
	}

	// -----------------------------------------------------------------------
	// Fix WR-01: meta_edit must fail closed when get_post() returns null
	// -----------------------------------------------------------------------

	/**
	 * When fill_empty_only is set and get_post() returns null (attachment
	 * trashed/deleted mid-batch), op_meta_edit must bail with an error
	 * rather than treating "no post" as "no existing value" and overwriting (WR-01).
	 *
	 * @return void
	 */
	public function test_meta_edit_fails_closed_on_null_post(): void {
		$contents = $this->bulk_actions_source();

		// A null-post guard must be present in op_meta_edit.
		$this->assertStringContainsString(
			'Attachment not found.',
			$contents,
			'op_meta_edit() must return an error when get_post() returns null under fill_empty_only (WR-01)'
		);
		// The guard must specifically check null === $post.
		$this->assertMatchesRegularExpression(
			'/null\s*===\s*\$post/',
			$contents,
			'op_meta_edit() must guard null === $post and bail with an error (WR-01)'
		);
	}

	// -----------------------------------------------------------------------
	// Fix WR-04: op_folder_assign must require folder_id key to be present
	// -----------------------------------------------------------------------

	/**
	 * Op_folder_assign must check array_key_exists('folder_id', $params) before
	 * interpreting the value. A missing key must return an error, not silently
	 * clear all folder assignments (WR-04).
	 *
	 * @return void
	 */
	public function test_folder_assign_requires_folder_id_key(): void {
		$contents = $this->bulk_actions_source();

		$this->assertStringContainsString(
			"array_key_exists( 'folder_id', \$params )",
			$contents,
			"op_folder_assign() must check array_key_exists('folder_id', \$params) — missing key must not silently clear folders (WR-04)"
		);
		$this->assertStringContainsString(
			'Missing folder_id.',
			$contents,
			"op_folder_assign() must return 'Missing folder_id.' when the key is absent (WR-04)"
		);
	}
}
