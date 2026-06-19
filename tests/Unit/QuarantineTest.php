<?php
/**
 * Quarantine path-mapping and recovery-record tests.
 *
 * @package AssetDrips
 */

declare( strict_types=1 );

namespace AssetDrips\Tests\Unit;

use AssetDrips\Quarantine\QuarantineManager;
use AssetDrips\Quarantine\RecoveryRecord;
use PHPUnit\Framework\TestCase;

/**
 * Pure tests for the quarantine path mapper and recovery record.
 */
final class QuarantineTest extends TestCase {

	private const BASEDIR = '/var/www/site/wp-content/uploads';
	private const QDIR    = '/var/www/site/wp-content/uploads/assetdrips-quarantine';

	/**
	 * A file under uploads keeps its relative layout inside the quarantine dir.
	 *
	 * @return void
	 */
	public function test_target_path_preserves_relative_layout(): void {
		$target = QuarantineManager::target_path(
			self::BASEDIR,
			self::QDIR,
			123,
			self::BASEDIR . '/2023/05/photo-300x200.jpg'
		);

		$this->assertSame( self::QDIR . '/123/2023/05/photo-300x200.jpg', $target );
	}

	/**
	 * Variant basenames in the same attachment never collide, because the full
	 * relative path is preserved.
	 *
	 * @return void
	 */
	public function test_target_paths_do_not_collide(): void {
		$main = QuarantineManager::target_path( self::BASEDIR, self::QDIR, 7, self::BASEDIR . '/2023/05/photo.jpg' );
		$size = QuarantineManager::target_path( self::BASEDIR, self::QDIR, 7, self::BASEDIR . '/2023/05/photo-150x150.jpg' );

		$this->assertNotSame( $main, $size );
		$this->assertSame( self::QDIR . '/7/2023/05/photo.jpg', $main );
		$this->assertSame( self::QDIR . '/7/2023/05/photo-150x150.jpg', $size );
	}

	/**
	 * A path outside uploads falls back to its basename.
	 *
	 * @return void
	 */
	public function test_target_path_falls_back_to_basename(): void {
		$target = QuarantineManager::target_path( self::BASEDIR, self::QDIR, 9, '/elsewhere/odd.jpg' );

		$this->assertSame( self::QDIR . '/9/odd.jpg', $target );
	}

	/**
	 * A recovery record exposes its snapshot and is restorable only when quarantined.
	 *
	 * @return void
	 */
	public function test_recovery_record_accessors(): void {
		$record = new RecoveryRecord(
			42,
			123,
			RecoveryRecord::QUARANTINED,
			array(
				'post'     => array(
					'ID'        => 123,
					'post_type' => 'attachment',
				),
				'postmeta' => array(
					array(
						'meta_key'   => '_wp_attached_file',
						'meta_value' => '2023/05/photo.jpg',
					),
				),
			),
			array(
				array(
					'from' => '/a/photo.jpg',
					'to'   => '/q/123/photo.jpg',
				),
			)
		);

		$this->assertSame( 42, $record->id() );
		$this->assertSame( 123, $record->attachment_id() );
		$this->assertTrue( $record->is_restorable() );
		$this->assertSame( 'attachment', $record->post_row()['post_type'] );
		$this->assertSame( '_wp_attached_file', $record->postmeta_rows()[0]['meta_key'] );
		$this->assertSame( '/q/123/photo.jpg', $record->file_paths()[0]['to'] );
	}

	/**
	 * A restored or purged record is no longer restorable.
	 *
	 * @return void
	 */
	public function test_non_quarantined_record_is_not_restorable(): void {
		$restored = new RecoveryRecord( 1, 1, RecoveryRecord::RESTORED, array(), array() );
		$purged   = new RecoveryRecord( 2, 2, RecoveryRecord::PURGED, array(), array() );

		$this->assertFalse( $restored->is_restorable() );
		$this->assertFalse( $purged->is_restorable() );
	}
}
