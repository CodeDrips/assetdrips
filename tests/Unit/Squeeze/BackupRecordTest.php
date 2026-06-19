<?php
/**
 * Unit tests for BackupRecord.
 *
 * @package AssetDrips
 */

declare( strict_types=1 );

namespace AssetDrips\Tests\Unit\Squeeze;

use AssetDrips\Squeeze\BackupRecord;
use PHPUnit\Framework\TestCase;

/**
 * Tests BackupRecord immutability, status constants, and predicate.
 *
 * BackupRecord is a dependency-free value object; these tests are GREEN
 * as soon as BackupRecord.php is created (Wave 0, Task 1).
 */
final class BackupRecordTest extends TestCase {

	/**
	 * Helper to build a default BackupRecord for getter tests.
	 *
	 * @return BackupRecord
	 */
	private function make_record(
		int $id = 1,
		int $attachment_id = 42,
		string $op = 'recompress',
		string $original_path = '/uploads/photo.jpg',
		string $backup_path = '/uploads/assetdrips-squeeze-backups/42/photo.jpg',
		int $original_bytes = 204800,
		string $status = BackupRecord::ACTIVE,
		string $backed_up_at = '2026-06-11 12:00:00',
		?string $restored_at = null
	): BackupRecord {
		return new BackupRecord(
			$id,
			$attachment_id,
			$op,
			$original_path,
			$backup_path,
			$original_bytes,
			$status,
			$backed_up_at,
			$restored_at
		);
	}

	// -------------------------------------------------------------------------
	// Status constant values
	// -------------------------------------------------------------------------

	/**
	 * ACTIVE constant equals 'active'.
	 *
	 * @return void
	 */
	public function test_active_constant_equals_active(): void {
		$this->assertSame( 'active', BackupRecord::ACTIVE );
	}

	/**
	 * RESTORED constant equals 'restored'.
	 *
	 * @return void
	 */
	public function test_restored_constant_equals_restored(): void {
		$this->assertSame( 'restored', BackupRecord::RESTORED );
	}

	/**
	 * PURGED constant equals 'purged'.
	 *
	 * @return void
	 */
	public function test_purged_constant_equals_purged(): void {
		$this->assertSame( 'purged', BackupRecord::PURGED );
	}

	// -------------------------------------------------------------------------
	// Getters return constructor-assigned values
	// -------------------------------------------------------------------------

	/**
	 * id() returns the value passed to the constructor.
	 *
	 * @return void
	 */
	public function test_id_getter(): void {
		$record = $this->make_record( id: 7 );
		$this->assertSame( 7, $record->id() );
	}

	/**
	 * attachment_id() returns the value passed to the constructor.
	 *
	 * @return void
	 */
	public function test_attachment_id_getter(): void {
		$record = $this->make_record( attachment_id: 99 );
		$this->assertSame( 99, $record->attachment_id() );
	}

	/**
	 * op() returns the value passed to the constructor.
	 *
	 * @return void
	 */
	public function test_op_getter(): void {
		$record = $this->make_record( op: 'resize' );
		$this->assertSame( 'resize', $record->op() );
	}

	/**
	 * original_path() returns the value passed to the constructor.
	 *
	 * @return void
	 */
	public function test_original_path_getter(): void {
		$record = $this->make_record( original_path: '/var/www/uploads/img.png' );
		$this->assertSame( '/var/www/uploads/img.png', $record->original_path() );
	}

	/**
	 * backup_path() returns the value passed to the constructor.
	 *
	 * @return void
	 */
	public function test_backup_path_getter(): void {
		$record = $this->make_record( backup_path: '/backups/img.png' );
		$this->assertSame( '/backups/img.png', $record->backup_path() );
	}

	/**
	 * original_bytes() returns the value passed to the constructor.
	 *
	 * @return void
	 */
	public function test_original_bytes_getter(): void {
		$record = $this->make_record( original_bytes: 512000 );
		$this->assertSame( 512000, $record->original_bytes() );
	}

	/**
	 * status() returns the value passed to the constructor.
	 *
	 * @return void
	 */
	public function test_status_getter(): void {
		$record = $this->make_record( status: BackupRecord::RESTORED );
		$this->assertSame( BackupRecord::RESTORED, $record->status() );
	}

	/**
	 * backed_up_at() returns the value passed to the constructor.
	 *
	 * @return void
	 */
	public function test_backed_up_at_getter(): void {
		$record = $this->make_record( backed_up_at: '2026-01-15 08:30:00' );
		$this->assertSame( '2026-01-15 08:30:00', $record->backed_up_at() );
	}

	/**
	 * restored_at() returns null when not set.
	 *
	 * @return void
	 */
	public function test_restored_at_getter_null(): void {
		$record = $this->make_record( restored_at: null );
		$this->assertNull( $record->restored_at() );
	}

	/**
	 * restored_at() returns the value passed to the constructor.
	 *
	 * @return void
	 */
	public function test_restored_at_getter_with_value(): void {
		$record = $this->make_record( restored_at: '2026-06-12 09:00:00' );
		$this->assertSame( '2026-06-12 09:00:00', $record->restored_at() );
	}

	// -------------------------------------------------------------------------
	// is_active() predicate
	// -------------------------------------------------------------------------

	/**
	 * is_active() returns true when status is ACTIVE.
	 *
	 * @return void
	 */
	public function test_is_active_true_for_active_status(): void {
		$record = $this->make_record( status: BackupRecord::ACTIVE );
		$this->assertTrue( $record->is_active() );
	}

	/**
	 * is_active() returns false when status is RESTORED.
	 *
	 * @return void
	 */
	public function test_is_active_false_for_restored_status(): void {
		$record = $this->make_record( status: BackupRecord::RESTORED );
		$this->assertFalse( $record->is_active() );
	}

	/**
	 * is_active() returns false when status is PURGED.
	 *
	 * @return void
	 */
	public function test_is_active_false_for_purged_status(): void {
		$record = $this->make_record( status: BackupRecord::PURGED );
		$this->assertFalse( $record->is_active() );
	}
}
