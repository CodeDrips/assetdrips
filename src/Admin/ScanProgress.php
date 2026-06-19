<?php
/**
 * Shared scan-progress status, written during a scan and polled by the browser.
 *
 * @package AssetDrips
 */

declare( strict_types=1 );

namespace AssetDrips\Admin;

defined( 'ABSPATH' ) || exit;

/**
 * A tiny status board for an in-flight admin scan.
 *
 * The long-running scan request writes its current phase and counts here; a
 * separate polling request reads them to drive the progress bar. Stored as a
 * non-autoloaded option so it never bloats the alloptions cache, and read fresh
 * each poll (separate request) so the bar reflects live state.
 */
final class ScanProgress {

	/**
	 * Option key.
	 */
	private const OPTION = 'assetdrips_scan_progress';

	/**
	 * Record the current progress state.
	 *
	 * @param array<string, mixed> $data Progress fields (phase, label, done, total, percent, step, steps).
	 * @return void
	 */
	public static function set( array $data ): void {
		$data['running'] = true;
		$data['updated'] = time();
		update_option( self::OPTION, $data, false );
	}

	/**
	 * Read the current progress state.
	 *
	 * @return array<string, mixed>
	 */
	public static function get(): array {
		$data = get_option( self::OPTION );

		return is_array( $data ) ? $data : array( 'running' => false );
	}

	/**
	 * Clear the progress state.
	 *
	 * @return void
	 */
	public static function clear(): void {
		delete_option( self::OPTION );
	}
}
