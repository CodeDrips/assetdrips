<?php
/**
 * Lossy acknowledgement helper.
 *
 * @package AssetDrips
 */

declare( strict_types=1 );

namespace AssetDrips\Squeeze;

defined( 'ABSPATH' ) || exit;

/**
 * One-time acknowledgement flag for the first lossy bulk run.
 *
 * Stores the user ID and UTC timestamp of the acknowledgement in the
 * `assetdrips_squeeze_lossy_ack` WP option (D-10).
 *
 * This class only manages the flag. The actual bulk-run gate (which blocks a
 * lossy run until acknowledgement is recorded) is enforced by Phase 11. This
 * keeps the data layer decoupled from the job orchestration layer.
 *
 * Mirrors the ScanProgress static-option shape: all methods are static, the
 * single option is not autoloaded (checked only during bulk-run gate), and
 * get_option / update_option / delete_option are used directly (no WP Settings
 * API — D-01).
 */
final class LossyAck {

	/**
	 * WP option key for the acknowledgement record.
	 */
	private const OPTION = 'assetdrips_squeeze_lossy_ack';

	/**
	 * Returns true when the user has acknowledged the lossy-bulk warning.
	 *
	 * @return bool
	 */
	public static function is_acknowledged(): bool {
		return (bool) get_option( self::OPTION );
	}

	/**
	 * Records the acknowledgement with the acknowledging user ID and a UTC timestamp.
	 *
	 * Idempotent: calling again overwrites with the latest user + timestamp, which
	 * is the correct behaviour when a different admin user acknowledges a reset.
	 *
	 * Not autoloaded — this option is checked only during bulk-run gate in Phase 11.
	 *
	 * @param int $user_id The ID of the user granting the acknowledgement.
	 * @return void
	 */
	public static function record( int $user_id ): void {
		update_option(
			self::OPTION,
			array(
				'acknowledged_at' => current_time( 'mysql', true ), // GMT timestamp (D-10).
				'user_id'         => $user_id,
			),
			false // Not autoloaded.
		);
	}

	/**
	 * Clears the acknowledgement (e.g., when settings are reset to defaults).
	 *
	 * After revoke(), is_acknowledged() returns false and the next lossy bulk run
	 * will require a fresh acknowledgement (Phase 11 gate).
	 *
	 * @return void
	 */
	public static function revoke(): void {
		delete_option( self::OPTION );
	}

	/**
	 * Returns the stored acknowledgement record, or null if not acknowledged.
	 *
	 * @return array{acknowledged_at: string, user_id: int}|null
	 */
	public static function get(): ?array {
		$raw = get_option( self::OPTION );
		return is_array( $raw ) ? $raw : null;
	}
}
