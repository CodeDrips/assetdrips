<?php
/**
 * Source-inspection tests for Find-grid bulk squeeze UI entry points (TRG-02).
 *
 * @package AssetDrips
 */

declare( strict_types=1 );

namespace AssetDrips\Tests\Unit\Admin;

use AssetDrips\Admin\FindScreen;
use PHPUnit\Framework\TestCase;

/**
 * Verifies that FindScreen emits the three squeeze bulk-bar buttons, three
 * op-group panels (including the restore confirm checkbox), and the two JS
 * extensions (collectOpParams branch + change listener) required by TRG-02.
 */
final class FindScreenBulkSqueezeTest extends TestCase {

	/**
	 * Returns the FindScreen source file contents for inspection.
	 *
	 * @return string
	 */
	private function find_screen_source(): string {
		$ref = new \ReflectionClass( FindScreen::class );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local file read for source inspection.
		return (string) file_get_contents( (string) $ref->getFileName() );
	}

	// -------------------------------------------------------------------------
	// Bulk-bar buttons (print_bulk_bar)
	// -------------------------------------------------------------------------

	/**
	 * Asserts that print_bulk_bar() emits the squeeze_optimize button.
	 *
	 * @return void
	 */
	public function test_squeeze_optimize_button_in_bulk_bar(): void {
		$this->assertStringContainsString(
			'data-op="squeeze_optimize"',
			$this->find_screen_source(),
			'print_bulk_bar() must emit squeeze_optimize button (TRG-02)'
		);
	}

	/**
	 * Asserts that print_bulk_bar() emits the squeeze_restore button.
	 *
	 * @return void
	 */
	public function test_squeeze_restore_button_in_bulk_bar(): void {
		$this->assertStringContainsString(
			'data-op="squeeze_restore"',
			$this->find_screen_source(),
			'print_bulk_bar() must emit squeeze_restore button (TRG-02)'
		);
	}

	/**
	 * Asserts that print_bulk_bar() emits the squeeze_regenerate_sizes button.
	 *
	 * @return void
	 */
	public function test_squeeze_regenerate_sizes_button_in_bulk_bar(): void {
		$this->assertStringContainsString(
			'data-op="squeeze_regenerate_sizes"',
			$this->find_screen_source(),
			'print_bulk_bar() must emit squeeze_regenerate_sizes button (TRG-02)'
		);
	}

	// -------------------------------------------------------------------------
	// Op-group panels (print_bulk_panel)
	// -------------------------------------------------------------------------

	/**
	 * Asserts that print_bulk_panel() emits the squeeze_optimize op-group div.
	 *
	 * @return void
	 */
	public function test_squeeze_optimize_op_group_in_bulk_panel(): void {
		$this->assertStringContainsString(
			'ad-bulk-op-group" data-op="squeeze_optimize"',
			$this->find_screen_source(),
			'print_bulk_panel() must emit squeeze_optimize op-group (TRG-02)'
		);
	}

	/**
	 * Asserts that print_bulk_panel() emits the squeeze_restore op-group div.
	 *
	 * @return void
	 */
	public function test_squeeze_restore_op_group_in_bulk_panel(): void {
		$this->assertStringContainsString(
			'ad-bulk-op-group" data-op="squeeze_restore"',
			$this->find_screen_source(),
			'print_bulk_panel() must emit squeeze_restore op-group (TRG-02)'
		);
	}

	/**
	 * Asserts that print_bulk_panel() emits the squeeze_regenerate_sizes op-group div.
	 *
	 * @return void
	 */
	public function test_squeeze_regenerate_sizes_op_group_in_bulk_panel(): void {
		$this->assertStringContainsString(
			'ad-bulk-op-group" data-op="squeeze_regenerate_sizes"',
			$this->find_screen_source(),
			'print_bulk_panel() must emit squeeze_regenerate_sizes op-group (TRG-02)'
		);
	}

	// -------------------------------------------------------------------------
	// Restore confirm checkbox
	// -------------------------------------------------------------------------

	/**
	 * Asserts that print_bulk_panel() emits the restore confirm checkbox.
	 *
	 * @return void
	 */
	public function test_restore_confirm_checkbox_present(): void {
		$this->assertStringContainsString(
			'id="ad-bulk-restore-confirm"',
			$this->find_screen_source(),
			'print_bulk_panel() must emit restore confirm checkbox (TRG-02)'
		);
	}

	// -------------------------------------------------------------------------
	// JS extensions (print_find_script)
	// -------------------------------------------------------------------------

	/**
	 * Asserts that collectOpParams() includes a squeeze_restore branch.
	 *
	 * @return void
	 */
	public function test_collect_op_params_has_squeeze_restore_branch(): void {
		$this->assertStringContainsString(
			'squeeze_restore',
			$this->find_screen_source(),
			'collectOpParams() must include squeeze_restore branch (TRG-02)'
		);
	}

	/**
	 * Asserts that print_find_script() includes the ad-bulk-restore-confirm change listener.
	 *
	 * @return void
	 */
	public function test_restore_confirm_change_listener_present(): void {
		$this->assertStringContainsString(
			'ad-bulk-restore-confirm',
			$this->find_screen_source(),
			'print_find_script() must include ad-bulk-restore-confirm change listener (TRG-02)'
		);
	}
}
