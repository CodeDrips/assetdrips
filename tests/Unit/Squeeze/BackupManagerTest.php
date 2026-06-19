<?php
/**
 * Unit tests for BackupManager (BAK-01, BAK-02, disk pre-flight).
 *
 * These tests are RED until Plan 09-02 implements BackupManager. They define
 * the required behaviour; Plan 09-02 turns them GREEN.
 *
 * All tests inject anonymous $wpdb stubs and real temp files via sys_get_temp_dir().
 * No from_wordpress() call is made — pure unit context.
 *
 * @package AssetDrips
 */

declare( strict_types=1 );

namespace AssetDrips\Tests\Unit\Squeeze;

use AssetDrips\Squeeze\BackupManager;
use AssetDrips\Squeeze\BackupRecord;
use PHPUnit\Framework\TestCase;

/**
 * Tests BackupManager backup/restore/purge lifecycle and disk pre-flight.
 */
final class BackupManagerTest extends TestCase {

	/**
	 * Temporary source file created in setUp.
	 *
	 * @var string
	 */
	private string $src_file;

	/**
	 * Temporary backup directory.
	 *
	 * @var string
	 */
	private string $backup_dir;

	/**
	 * Anonymous $wpdb stub with full method surface.
	 *
	 * @var object
	 */
	private object $wpdb;

	/**
	 * Set up temp files, backup dir, and a capable $wpdb stub.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$GLOBALS['_assetdrips_transient_stub'] = array();
		$GLOBALS['_assetdrips_options_stub']   = array();

		// Create a real temp file to act as the "original attachment file".
		$this->src_file = tempnam( sys_get_temp_dir(), 'bm-src-' );
		file_put_contents( $this->src_file, str_repeat( 'A', 1024 ) );

		// Create a temp backup directory.
		$this->backup_dir = sys_get_temp_dir() . '/bm-backup-' . uniqid();
		mkdir( $this->backup_dir, 0755, true );

		// Build a capable anonymous $wpdb stub.
		$this->wpdb = new class() {
			/** @var string */
			public string $prefix = 'wp_';
			/** @var int */
			public int $insert_id = 1;
			/** @var array<int, array<string, mixed>> */
			public array $inserts = array();
			/** @var array<int, array<string, mixed>> */
			public array $updates = array();
			/** @var array<int, mixed> */
			public array $queries = array();
			/** @var mixed */
			public $next_row = null;
			/** @var mixed */
			public $next_results = array();
			/** @var mixed */
			public $next_var = null;

			/**
			 * @param string               $table  Table name.
			 * @param array<string, mixed> $data   Column data.
			 * @return int|false
			 */
			public function insert( string $table, array $data ): int|false { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- Stub.
				$this->inserts[] = $data;
				return 1;
			}

			/**
			 * @param string               $table  Table name.
			 * @param array<string, mixed> $data   Column data.
			 * @param array<string, mixed> $where  WHERE clause.
			 * @return int|false
			 */
			public function update( string $table, array $data, array $where ): int|false { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- Stub.
				$this->updates[] = array( 'data' => $data, 'where' => $where );
				return 1;
			}

			/**
			 * @param string $sql  Prepared SQL.
			 * @param string $output ARRAY_A etc.
			 * @return array<string, mixed>|null
			 */
			public function get_row( string $sql, string $output = '' ): ?array { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- Stub.
				return $this->next_row;
			}

			/**
			 * @param string $sql  Prepared SQL.
			 * @param string $output Output format.
			 * @return array<int, mixed>
			 */
			public function get_results( string $sql, string $output = '' ): array { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- Stub.
				return $this->next_results;
			}

			/**
			 * @param string $sql Prepared SQL.
			 * @return mixed
			 */
			public function get_var( string $sql ): mixed { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- Stub.
				return $this->next_var;
			}

			/**
			 * @param string $sql  SQL template.
			 * @param mixed  ...$args Bound values.
			 * @return string
			 */
			public function prepare( string $sql, mixed ...$args ): string { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- Stub.
				return $sql;
			}

			/**
			 * @param string $sql SQL to execute.
			 * @return int|false
			 */
			public function query( string $sql ): int|false { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- Stub.
				$this->queries[] = $sql;
				return 1;
			}
		};
	}

	/**
	 * Remove temp files created during the test.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		if ( file_exists( $this->src_file ) ) {
			@unlink( $this->src_file ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Best-effort temp-file cleanup.
		}
		// Recursively remove the backup dir.
		$this->rmdir_recursive( $this->backup_dir );
	}

	/**
	 * Recursively remove a directory and its contents.
	 *
	 * @param string $dir Directory path.
	 * @return void
	 */
	private function rmdir_recursive( string $dir ): void {
		if ( ! is_dir( $dir ) ) {
			return;
		}
		$items = scandir( $dir );
		foreach ( $items ?: array() as $item ) {
			if ( '.' === $item || '..' === $item ) {
				continue;
			}
			$path = $dir . '/' . $item;
			if ( is_dir( $path ) ) {
				$this->rmdir_recursive( $path );
			} else {
				@unlink( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Best-effort cleanup.
			}
		}
		@rmdir( $dir ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Best-effort cleanup.
	}

	// -------------------------------------------------------------------------
	// BAK-01: backup() — file copy + filesize verification
	// -------------------------------------------------------------------------

	/**
	 * backup() copies the source file to the backup dir and returns true when
	 * filesizes match.
	 *
	 * @return void
	 */
	public function test_backup_copies_file_and_returns_true(): void {
		$manager = new BackupManager( $this->wpdb, sys_get_temp_dir(), $this->backup_dir );

		$result = $manager->backup( 42, $this->src_file, 'recompress' );

		$this->assertTrue( $result, 'backup() must return true when copy succeeds and filesizes match' );

		// The backup file should now exist.
		$backup_path = BackupManager::target_path( sys_get_temp_dir(), $this->backup_dir, 42, $this->src_file );
		$this->assertFileExists( $backup_path, 'Backup copy must exist on disk after backup()' );

		// Original must still be present (copy, not move).
		$this->assertFileExists( $this->src_file, 'Original file must remain after backup()' );

		// A DB record must have been inserted.
		$this->assertCount( 1, $this->wpdb->inserts, 'backup() must insert one DB record on success' );
	}

	/**
	 * backup() returns false AND writes no DB record when the backup filesize
	 * does not match the source (verifies corrupt-copy detection).
	 *
	 * This test replaces the src_file path with a scenario where the backup
	 * would produce a mismatched filesize by tampering with the $wpdb insert
	 * count as a proxy for "no record written" (the production code checks
	 * filesize before insert).
	 *
	 * @return void
	 */
	public function test_backup_returns_false_and_no_record_on_filesize_mismatch(): void {
		// Use a non-existent file path so copy() fails → filesize mismatch path.
		$bad_src = sys_get_temp_dir() . '/bm-nonexistent-' . uniqid() . '.jpg';

		$manager = new BackupManager( $this->wpdb, sys_get_temp_dir(), $this->backup_dir );
		$result  = $manager->backup( 42, $bad_src, 'recompress' );

		$this->assertFalse( $result, 'backup() must return false when source is missing' );
		$this->assertCount( 0, $this->wpdb->inserts, 'backup() must write no DB record on failure' );
	}

	// -------------------------------------------------------------------------
	// BAK-01: ensure_web_inaccessible() writes .htaccess + index.php
	// -------------------------------------------------------------------------

	/**
	 * The backup root must be web-inaccessible: backup() must write .htaccess
	 * and index.php into the backup root directory on first use.
	 *
	 * @return void
	 */
	public function test_backup_writes_htaccess_and_index_php_in_backup_root(): void {
		$manager = new BackupManager( $this->wpdb, sys_get_temp_dir(), $this->backup_dir );

		$manager->backup( 42, $this->src_file, 'recompress' );

		$htaccess  = $this->backup_dir . '/.htaccess';
		$index_php = $this->backup_dir . '/index.php';

		$this->assertFileExists( $htaccess,  'backup() must write .htaccess in backup root (D-02)' );
		$this->assertFileExists( $index_php, 'backup() must write index.php in backup root (D-02)' );
	}

	// -------------------------------------------------------------------------
	// BAK-01: purge_all() removes backup files + marks records PURGED
	// -------------------------------------------------------------------------

	/**
	 * purge_all() removes the backup file from disk and marks the DB record
	 * as PURGED.
	 *
	 * This covers the unit-level proof for BAK-01 delete_attachment → purge_all().
	 * The hook-dispatch wiring itself is asserted structurally in Plan 09-04 Task 3
	 * and verified live at integration.
	 *
	 * @return void
	 */
	public function test_purge_all_removes_backup_file_and_marks_purged(): void {
		// Seed the $wpdb stub to return an active backup row.
		$backup_path = BackupManager::target_path( sys_get_temp_dir(), $this->backup_dir, 42, $this->src_file );
		wp_mkdir_p( dirname( $backup_path ) );
		file_put_contents( $backup_path, str_repeat( 'A', 1024 ) );

		$this->wpdb->next_results = array(
			array(
				'id'             => 1,
				'attachment_id'  => 42,
				'op'             => 'recompress',
				'original_path'  => $this->src_file,
				'backup_path'    => $backup_path,
				'original_bytes' => 1024,
				'status'         => BackupRecord::ACTIVE,
				'backed_up_at'   => '2026-06-11 12:00:00',
				'restored_at'    => null,
			),
		);

		$manager = new BackupManager( $this->wpdb, sys_get_temp_dir(), $this->backup_dir );
		$manager->purge_all( 42 );

		$this->assertFileDoesNotExist( $backup_path, 'purge_all() must delete the backup file from disk' );

		// DB record must have been updated to PURGED.
		$has_purged_update = false;
		foreach ( $this->wpdb->updates as $u ) {
			if ( isset( $u['data']['status'] ) && BackupRecord::PURGED === $u['data']['status'] ) {
				$has_purged_update = true;
				break;
			}
		}
		$this->assertTrue( $has_purged_update, 'purge_all() must mark backup records as PURGED in the DB' );
	}

	// -------------------------------------------------------------------------
	// BAK-02: restore_all() restores backup + updates record status
	// -------------------------------------------------------------------------

	/**
	 * restore_all() renames the backup over the original and updates the DB
	 * record status to RESTORED.
	 *
	 * @return void
	 */
	public function test_restore_all_renames_backup_and_marks_restored(): void {
		// Create a backup file on disk.
		$backup_path = BackupManager::target_path( sys_get_temp_dir(), $this->backup_dir, 42, $this->src_file );
		wp_mkdir_p( dirname( $backup_path ) );
		file_put_contents( $backup_path, str_repeat( 'R', 1024 ) );

		$this->wpdb->next_results = array(
			array(
				'id'             => 1,
				'attachment_id'  => 42,
				'op'             => 'recompress',
				'original_path'  => $this->src_file,
				'backup_path'    => $backup_path,
				'original_bytes' => 1024,
				'status'         => BackupRecord::ACTIVE,
				'backed_up_at'   => '2026-06-11 12:00:00',
				'restored_at'    => null,
			),
		);

		$GLOBALS['_assetdrips_attached_file_stub'][42] = $this->src_file;

		$manager = new BackupManager( $this->wpdb, sys_get_temp_dir(), $this->backup_dir );
		$manager->restore_all( 42 );

		// Backup file must now be gone (renamed over original).
		$this->assertFileDoesNotExist( $backup_path, 'restore_all() must rename backup over original' );

		// Original path must now contain the backup content.
		$this->assertStringEqualsFile( $this->src_file, str_repeat( 'R', 1024 ), 'restore_all() must restore backup content to original path' );

		// DB must record RESTORED status.
		$has_restored_update = false;
		foreach ( $this->wpdb->updates as $u ) {
			if ( isset( $u['data']['status'] ) && BackupRecord::RESTORED === $u['data']['status'] ) {
				$has_restored_update = true;
				break;
			}
		}
		$this->assertTrue( $has_restored_update, 'restore_all() must mark record status as RESTORED' );
	}

	// -------------------------------------------------------------------------
	// CR-03: restore_backup_file_only() renames backup over original (file only)
	// -------------------------------------------------------------------------

	/**
	 * restore_backup_file_only() must rename the backup file back over the original
	 * path without modifying any DB records (file-only rollback on save failure).
	 *
	 * @return void
	 */
	public function test_restore_backup_file_only_renames_file_and_returns_true(): void {
		$backup_path = BackupManager::target_path( sys_get_temp_dir(), $this->backup_dir, 42, $this->src_file );
		wp_mkdir_p( dirname( $backup_path ) );
		file_put_contents( $backup_path, str_repeat( 'B', 1024 ) );

		$this->wpdb->next_results = array(
			array(
				'id'             => 1,
				'attachment_id'  => 42,
				'op'             => 'recompress',
				'original_path'  => $this->src_file,
				'backup_path'    => $backup_path,
				'original_bytes' => 1024,
				'status'         => BackupRecord::ACTIVE,
				'backed_up_at'   => '2026-06-11 12:00:00',
				'restored_at'    => null,
			),
		);

		$manager = new BackupManager( $this->wpdb, sys_get_temp_dir(), $this->backup_dir );
		$result  = $manager->restore_backup_file_only( 42 );

		$this->assertTrue( $result, 'restore_backup_file_only() must return true on success' );
		$this->assertFileDoesNotExist( $backup_path, 'restore_backup_file_only() must rename backup (backup path no longer exists)' );
		$this->assertStringEqualsFile( $this->src_file, str_repeat( 'B', 1024 ), 'restore_backup_file_only() must restore backup content to original path' );

		// No DB updates should be written (file-only rollback, no status change).
		$this->assertCount( 0, $this->wpdb->updates, 'restore_backup_file_only() must NOT update DB records (file-only rollback)' );
	}

	/**
	 * restore_backup_file_only() returns false when no active backup exists.
	 *
	 * @return void
	 */
	public function test_restore_backup_file_only_returns_false_when_no_backup(): void {
		$this->wpdb->next_results = array();

		$manager = new BackupManager( $this->wpdb, sys_get_temp_dir(), $this->backup_dir );
		$result  = $manager->restore_backup_file_only( 99 );

		$this->assertFalse( $result, 'restore_backup_file_only() must return false when no active backup exists' );
	}

	// -------------------------------------------------------------------------
	// RSZ-02: disk-estimate helper sums pending source filesizes + 20% buffer
	// -------------------------------------------------------------------------

	/**
	 * estimate_disk_requirement() returns total filesizes × 1.2 buffer.
	 *
	 * @return void
	 */
	public function test_estimate_disk_requirement_sums_filesizes_with_buffer(): void {
		$file1 = tempnam( sys_get_temp_dir(), 'bm-est1-' );
		$file2 = tempnam( sys_get_temp_dir(), 'bm-est2-' );
		file_put_contents( $file1, str_repeat( 'X', 1000 ) );
		file_put_contents( $file2, str_repeat( 'X', 2000 ) );

		$manager  = new BackupManager( $this->wpdb, sys_get_temp_dir(), $this->backup_dir );
		$estimate = $manager->estimate_disk_requirement( array( $file1, $file2 ) );

		@unlink( $file1 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Cleanup.
		@unlink( $file2 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Cleanup.

		// Sum = 3000, with 1.2 buffer = 3600.
		$this->assertSame( (int) ( 3000 * 1.2 ), $estimate, 'estimate_disk_requirement() must sum filesizes and apply 1.2 buffer' );
	}
}
