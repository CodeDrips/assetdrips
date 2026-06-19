<?php
/**
 * Unit tests for BulkActions squeeze_restore dispatch (BAK-03).
 *
 * These tests are RED until Plan 09-02/03 adds squeeze_restore to BulkActions.
 * They verify the op is whitelisted and dispatches to BackupManager::restore_all(),
 * and that per-item failures are recorded without aborting the batch.
 *
 * Uses source inspection (file_get_contents on the class file) so no WP bootstrap
 * or full HTTP round-trip is needed.
 *
 * @package AssetDrips
 */

declare( strict_types=1 );

namespace AssetDrips\Tests\Unit\Admin;

use PHPUnit\Framework\TestCase;

/**
 * Source-inspection tests for BulkActions squeeze_restore integration (BAK-03).
 */
final class BulkActionsSqueezeTest extends TestCase {

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
	// BAK-03: squeeze_restore is an allowed op
	// -------------------------------------------------------------------------

	/**
	 * The $allowed_ops array must include 'squeeze_restore'.
	 *
	 * @return void
	 */
	public function test_squeeze_restore_is_in_allowed_ops(): void {
		$contents = $this->bulk_actions_source();
		$this->assertStringContainsString(
			'squeeze_restore',
			$contents,
			'BulkActions $allowed_ops must include squeeze_restore (BAK-03 / D-11)'
		);
	}

	// -------------------------------------------------------------------------
	// BAK-03: squeeze_restore dispatches to BackupManager::restore_all()
	// -------------------------------------------------------------------------

	/**
	 * The dispatch_op() switch must have a 'squeeze_restore' case that calls
	 * a method delegating to BackupManager::restore_all().
	 *
	 * @return void
	 */
	public function test_squeeze_restore_dispatches_to_restore_all(): void {
		$contents = $this->bulk_actions_source();
		$this->assertStringContainsString(
			'restore_all',
			$contents,
			'BulkActions squeeze_restore branch must call BackupManager::restore_all() (BAK-03)'
		);
	}

	/**
	 * BulkActions must import BackupManager with a use statement.
	 *
	 * @return void
	 */
	public function test_bulk_actions_imports_backup_manager(): void {
		$contents = $this->bulk_actions_source();
		$this->assertStringContainsString(
			'use AssetDrips\\Squeeze\\BackupManager',
			$contents,
			'BulkActions must import BackupManager via use statement (BAK-03)'
		);
	}

	// -------------------------------------------------------------------------
	// BAK-03: per-item failure is recorded without aborting the batch
	// -------------------------------------------------------------------------

	/**
	 * The squeeze_restore op handler must catch RuntimeException and return
	 * an error string (not throw), so a per-item failure is recorded as a
	 * result string without aborting the batch.
	 *
	 * @return void
	 */
	public function test_squeeze_restore_per_item_failure_does_not_abort_batch(): void {
		$contents = $this->bulk_actions_source();
		// The handler must catch RuntimeException and return an error string.
		$this->assertStringContainsString(
			'RuntimeException',
			$contents,
			'op_squeeze_restore() must catch RuntimeException to prevent per-item failure from aborting the batch (BAK-03)'
		);
		$this->assertStringContainsString(
			'getMessage',
			$contents,
			'op_squeeze_restore() must return the exception message as the per-item error string'
		);
	}

	/**
	 * The squeeze_restore handler must check has_backup() before calling restore_all()
	 * and return a non-aborting error string when no backup exists.
	 *
	 * @return void
	 */
	public function test_squeeze_restore_returns_error_string_when_no_backup(): void {
		$contents = $this->bulk_actions_source();
		$this->assertStringContainsString(
			'has_backup',
			$contents,
			'op_squeeze_restore() must call has_backup() to check for an active backup before restore (BAK-03)'
		);
		$this->assertStringContainsString(
			'No active backup',
			$contents,
			'op_squeeze_restore() must return a "No active backup" error string (not throw) when has_backup() is false'
		);
	}

	// -------------------------------------------------------------------------
	// TRG-02: squeeze_optimize stub is removed (RED until 11-03 ships)
	// -------------------------------------------------------------------------

	/**
	 * The placeholder "squeeze_optimize not yet active." stub must be removed once
	 * the op is wired to its handler.
	 *
	 * @return void
	 */
	public function test_squeeze_optimize_stub_is_removed(): void {
		$contents = $this->bulk_actions_source();
		$this->assertStringNotContainsString(
			'squeeze_optimize not yet active',
			$contents,
			'BulkActions must remove the "squeeze_optimize not yet active." stub once the op is wired (TRG-02)'
		);
	}

	// -------------------------------------------------------------------------
	// TRG-02 + TRG-04: squeeze_optimize dispatches to a dedicated op method
	// -------------------------------------------------------------------------

	/**
	 * The squeeze_optimize case must dispatch to op_squeeze_optimize() — mirroring
	 * the op_squeeze_restore() shape.
	 *
	 * @return void
	 */
	public function test_squeeze_optimize_dispatches_to_op_method(): void {
		$contents = $this->bulk_actions_source();
		$this->assertStringContainsString(
			'op_squeeze_optimize',
			$contents,
			'BulkActions squeeze_optimize case must dispatch to op_squeeze_optimize() (TRG-02, TRG-04)'
		);
	}

	// -------------------------------------------------------------------------
	// TRG-02 + D-03: op handler calls the engine per enabled op via SqueezeSettings
	// -------------------------------------------------------------------------

	/**
	 * The op_squeeze_optimize() handler must resolve SqueezeEngine and consult
	 * SqueezeSettings to run only the enabled ops (D-03).
	 *
	 * @return void
	 */
	public function test_squeeze_optimize_calls_engine_per_enabled_op(): void {
		$contents = $this->bulk_actions_source();
		$this->assertStringContainsString(
			'SqueezeEngine',
			$contents,
			'op_squeeze_optimize() must call SqueezeEngine to optimize the attachment (TRG-02)'
		);
		$this->assertStringContainsString(
			'SqueezeSettings',
			$contents,
			'op_squeeze_optimize() must consult SqueezeSettings to run only enabled ops (TRG-02, D-03)'
		);
		$this->assertStringContainsString(
			'enable_recompress',
			$contents,
			'op_squeeze_optimize() must gate on the enable_recompress setting (D-03)'
		);
		$this->assertStringContainsString(
			'enable_webp',
			$contents,
			'op_squeeze_optimize() must gate on the enable_webp setting (D-03)'
		);
		$this->assertStringContainsString(
			'enable_avif',
			$contents,
			'op_squeeze_optimize() must gate on the enable_avif setting (D-03)'
		);
		$this->assertStringContainsString(
			'enable_resize',
			$contents,
			'op_squeeze_optimize() must gate on the enable_resize setting (D-03)'
		);
	}

	// -------------------------------------------------------------------------
	// TRG-02: per-item failure is recorded without aborting the batch
	// -------------------------------------------------------------------------

	/**
	 * The op_squeeze_optimize() handler must catch \Throwable and return the message
	 * string so a single-item failure does not abort the bulk batch.
	 *
	 * @return void
	 */
	public function test_squeeze_optimize_per_item_failure_does_not_abort_batch(): void {
		$contents = $this->bulk_actions_source();
		$this->assertStringContainsString(
			'Throwable',
			$contents,
			'op_squeeze_optimize() must catch \\Throwable so a per-item failure does not abort the batch (TRG-02)'
		);
		$this->assertStringContainsString(
			'getMessage',
			$contents,
			'op_squeeze_optimize() must return the exception message as the per-item error string (TRG-02)'
		);
	}
}
