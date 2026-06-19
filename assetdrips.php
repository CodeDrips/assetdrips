<?php
/**
 * Plugin Name:       AssetDrips
 * Plugin URI:        https://codedrips.com/assetdrips
 * Description:       Find, organise, and optimise large WordPress media libraries — non-destructively. Unused-media detection (Sift), a fast index with faceted search (Find), folders/tags/bulk-edit (Sort), and image optimisation with WebP/AVIF (Squeeze).
 * Version:           0.1.2
 * Requires at least: 6.0
 * Requires PHP:      8.1
 * Author:            CodeDrips
 * Author URI:        https://codedrips.com
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       assetdrips
 *
 * @package AssetDrips
 */

declare( strict_types=1 );

namespace AssetDrips;

defined( 'ABSPATH' ) || exit;

define( 'ASSETDRIPS_VERSION', '0.1.2' );
define( 'ASSETDRIPS_FILE', __FILE__ );
define( 'ASSETDRIPS_DIR', plugin_dir_path( __FILE__ ) );
define( 'ASSETDRIPS_URL', plugin_dir_url( __FILE__ ) );

// Composer autoloader. Required for the PSR-4 AssetDrips\ namespace.
$assetdrips_autoload = __DIR__ . '/vendor/autoload.php';
if ( is_readable( $assetdrips_autoload ) ) {
	require $assetdrips_autoload;
}

/**
 * Activation: install/upgrade custom tables, then kick off the initial index
 * backfill so an existing library is searchable without a manual CLI step.
 *
 * The recurring reconcile (registered in Plugin::boot()) is scheduled to first
 * run on the very next WP-Cron tick instead of the default +1 hour, so the
 * index builds itself shortly after activation. reconcile() backfills every
 * attachment missing from the index (IDX-06) and is idempotent. boot()'s own
 * `wp_next_scheduled()` guard then sees this schedule and does not double-book.
 */
register_activation_hook(
	__FILE__,
	static function (): void {
		Db\Schema::install();

		if ( ! wp_next_scheduled( 'assetdrips_index_reconcile' ) ) {
			wp_schedule_event( time(), 'daily', 'assetdrips_index_reconcile' );
		}
	}
);

/**
 * Deactivation: no destructive teardown of data or tables — they persist so a
 * reactivation resumes cleanly. Only the self-scheduled cron events are cleared
 * so they do not fire while the plugin is inactive; they reschedule on next boot.
 */
register_deactivation_hook(
	__FILE__,
	static function (): void {
		wp_clear_scheduled_hook( 'assetdrips_index_reconcile' );
		wp_clear_scheduled_hook( 'assetdrips_usage_refresh' );
		wp_clear_scheduled_hook( 'assetdrips_squeeze_batch' );
		wp_clear_scheduled_hook( 'assetdrips_squeeze_single' );
	}
);

/**
 * Boot the plugin once WordPress and all plugins are loaded.
 */
add_action(
	'plugins_loaded',
	static function (): void {
		Plugin::instance()->boot();
	}
);
