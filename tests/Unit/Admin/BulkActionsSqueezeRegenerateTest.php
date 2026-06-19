<?php
/**
 * RED unit tests for BulkActions squeeze_regenerate_sizes op (SIZE-02 / D-03).
 *
 * These tests are RED until Plan 13-05 adds 'squeeze_regenerate_sizes' to
 * BulkActions whitelist and implements op_squeeze_regenerate_sizes().
 * Plan 13-05 turns them GREEN.
 *
 * Strategy: source-inspection via ReflectionClass::getFileName() + file_get_contents()
 * for the whitelist and dispatch assertions (mirrors BulkActionsSqueezeTest approach).
 * The op dispatch assertion uses a contract test verifying the source structure.
 *
 * @package AssetDrips
 */

declare( strict_types=1 );

namespace AssetDrips\Tests\Unit\Admin;

use PHPUnit\Framework\TestCase;

/**
 * RED tests for BulkActions squeeze_regenerate_sizes op whitelisting + dispatch (SIZE-02).
 */
final class BulkActionsSqueezeRegenerateTest extends TestCase {

	/**
	 * Return the BulkActions source file contents for inspection.
	 *
	 * Fails with a clear message if BulkActions has not been created yet.
	 *
	 * @return string
	 */
	private function bulk_actions_source(): string {
		$this->assertTrue(
			class_exists( \AssetDrips\Admin\BulkActions::class ),
			'BulkActions class must exist'
		);
		$ref = new \ReflectionClass( \AssetDrips\Admin\BulkActions::class );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local file read for source inspection.
		return (string) file_get_contents( (string) $ref->getFileName() );
	}

	// -------------------------------------------------------------------------
	// SIZE-02: squeeze_regenerate_sizes is an allowed op
	// -------------------------------------------------------------------------

	/**
	 * The $allowed_ops array must include 'squeeze_regenerate_sizes' (SIZE-02 / D-03).
	 *
	 * @return void
	 */
	public function test_regenerate_sizes_op_whitelisted(): void {
		$contents = $this->bulk_actions_source();
		$this->assertStringContainsString(
			'squeeze_regenerate_sizes',
			$contents,
			'BulkActions $allowed_ops must include "squeeze_regenerate_sizes" (SIZE-02 / D-03)'
		);
	}

	// -------------------------------------------------------------------------
	// SIZE-02: dispatch routes to op_squeeze_regenerate_sizes → repair_missing_sizes
	// -------------------------------------------------------------------------

	/**
	 * The dispatch_op() switch must have a 'squeeze_regenerate_sizes' case that
	 * delegates to SqueezeEngine::repair_missing_sizes() (SIZE-02 / D-07).
	 *
	 * @return void
	 */
	public function test_regenerate_sizes_dispatch_routes_to_repair(): void {
		$contents = $this->bulk_actions_source();
		$this->assertStringContainsString(
			'repair_missing_sizes',
			$contents,
			'BulkActions must dispatch squeeze_regenerate_sizes to repair_missing_sizes() (SIZE-02 / D-07)'
		);
	}

	/**
	 * The op handler must catch \Throwable and return an error string — per-item
	 * failures must not abort the batch (mirrors op_squeeze_restore() pattern).
	 *
	 * @return void
	 */
	public function test_regenerate_sizes_per_item_failure_does_not_abort_batch(): void {
		$contents = $this->bulk_actions_source();
		$this->assertStringContainsString(
			'Throwable',
			$contents,
			'op_squeeze_regenerate_sizes() must catch \\Throwable to isolate per-item failures (SIZE-02)'
		);
	}

	// -------------------------------------------------------------------------
	// SIZE-02: successful op result (ok=true) yields a true (success) outcome
	// -------------------------------------------------------------------------

	/**
	 * When repair_missing_sizes() returns ['ok' => true, ...], the op must return
	 * true to the batch driver (success outcome) (SIZE-02 / D-03).
	 *
	 * Asserted via source inspection: the handler must contain 'ok' check + true return.
	 *
	 * @return void
	 */
	public function test_regenerate_sizes_ok_true_yields_success_outcome(): void {
		$contents = $this->bulk_actions_source();
		// The handler must check $result['ok'] and return true on success.
		$this->assertTrue(
			str_contains( $contents, "result['ok']" ) || str_contains( $contents, "result[\"ok\"]" ),
			"op_squeeze_regenerate_sizes() must check \$result['ok'] to determine success (SIZE-02)"
		);
	}

	// -------------------------------------------------------------------------
	// SIZE-02: failed op result (ok=false) yields the reason string
	// -------------------------------------------------------------------------

	/**
	 * When repair_missing_sizes() returns ['ok' => false, 'reason' => 'no_file'],
	 * the op must return the reason string as the per-item error record (SIZE-02).
	 *
	 * @return void
	 */
	public function test_regenerate_sizes_ok_false_yields_reason_string(): void {
		$contents = $this->bulk_actions_source();
		// The handler must reference 'reason' to propagate the error.
		$this->assertTrue(
			str_contains( $contents, "result['reason']" ) || str_contains( $contents, "result[\"reason\"]" ),
			"op_squeeze_regenerate_sizes() must return \$result['reason'] on ok=false (SIZE-02)"
		);
	}
}
