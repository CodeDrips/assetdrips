<?php
/**
 * Main plugin orchestrator.
 *
 * @package AssetDrips
 */

declare( strict_types=1 );

namespace AssetDrips;

use AssetDrips\Admin\BulkActions;
use AssetDrips\Admin\Dashboard;
use AssetDrips\Admin\FindScreen;
use AssetDrips\Admin\FolderScreen;
use AssetDrips\Admin\IndexBuildNotice;
use AssetDrips\Admin\NextGenColumn;
use AssetDrips\Admin\ReviewScreen;
use AssetDrips\Admin\SqueezeScreen;
use AssetDrips\Sort\FolderFields;
use AssetDrips\Sort\TagFields;
use AssetDrips\Cli\CommandRegistrar;
use AssetDrips\Db\Schema;
use AssetDrips\Index\IndexBuilder;
use AssetDrips\Index\IndexHooks;
use AssetDrips\Sort\SortHooks;
use AssetDrips\Sort\TaxonomyRegistrar;
use AssetDrips\Squeeze\BackupManager;
use AssetDrips\Squeeze\SqueezeHooks;
use AssetDrips\Squeeze\SqueezeJob;
use AssetDrips\Squeeze\SqueezeServing;
use AssetDrips\Squeeze\SqueezeSettings;

defined( 'ABSPATH' ) || exit;

/**
 * Singleton entry point. Wires shared services and module bootstrapping.
 *
 * Kept deliberately thin: AssetDrips is a suite, and modules (Sift in v1, then
 * Sort / Squeeze / Find) register against shared services from here. No
 * Sift-specific assumptions belong in this class.
 */
final class Plugin {

	/**
	 * Singleton instance.
	 *
	 * @var Plugin|null
	 */
	private static ?Plugin $instance = null;

	/**
	 * Whether boot() has already run.
	 *
	 * @var bool
	 */
	private bool $booted = false;

	/**
	 * Private constructor — use instance().
	 */
	private function __construct() {}

	/**
	 * Resolve the singleton.
	 *
	 * @return Plugin
	 */
	public static function instance(): Plugin {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Boot the plugin. Idempotent.
	 *
	 * @return void
	 */
	public function boot(): void {
		if ( $this->booted ) {
			return;
		}
		$this->booted = true;

		// Self-heal: if the plugin was updated without a reactivation, bring the
		// schema up to date on load.
		Schema::maybe_upgrade();

		// Register CLI commands when running under WP-CLI.
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			CommandRegistrar::register();
		}

		// Structural-lane freshness hooks. Registered for EVERY request context
		// (not just admin): uploads and edits happen in admin AND via the REST
		// API, so these must fire outside the is_admin() block below.
		( new IndexHooks() )->register();

		// Register the organization taxonomies (assetdrips_folder, assetdrips_tag)
		// on init for every request context. REST-API term assignment (Phase 5) is
		// not is_admin(), so registration must happen unconditionally here.
		( new TaxonomyRegistrar() )->register();

		// Folder-lane freshness hook: mirrors folder term assignments into
		// assetdrips_media.folder_id in real time (D-03). REST/CLI term assignment
		// is not is_admin(), so this must be registered outside the is_admin() block.
		( new SortHooks() )->register();

		// Backup cleanup: purge all backup files when an attachment is deleted.
		// Registered OUTSIDE is_admin() so REST/CLI deletes (not just admin UI) also
		// clean up orphan backup files, preventing disk accumulation (T-09-13 / BAK-01).
		// Priority 10 matches IndexHooks::on_delete (see IndexHooks.php:83).
		add_action(
			'delete_attachment',
			static function ( $post_id ) {
				BackupManager::from_wordpress()->purge_all( (int) $post_id );
			},
			10,
			1
		);

		// Drift-reconciliation cron (IDX-06). Self-scheduling and idempotent: the
		// callback is a no-op when the index already matches the library.
		add_action( 'assetdrips_index_reconcile', array( IndexBuilder::class, 'reconcile' ) );
		if ( ! wp_next_scheduled( 'assetdrips_index_reconcile' ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', 'assetdrips_index_reconcile' );
		}

		// Usage-refresh cron (the "+ WP-Cron" half of IDX-04). Drives
		// IndexBuilder::sync_usage via a named static callback so deactivation can
		// clear it unambiguously. No pre-existing scheduled scan exists (Open Q1).
		add_action( 'assetdrips_usage_refresh', array( IndexBuilder::class, 'run_usage_refresh' ) );
		if ( ! wp_next_scheduled( 'assetdrips_usage_refresh' ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', 'assetdrips_usage_refresh' );
		}

		// Auto-on-upload Squeeze scheduling hook. Registered OUTSIDE is_admin(): uploads
		// arrive via REST from Gutenberg, not just the admin media uploader (TRG-03, D-06).
		( new SqueezeHooks() )->register();

		// Next-gen serving filter. Registered OUTSIDE is_admin() because boot() runs on
		// every request and the image-API filter must be available on front-end page
		// renders. boot() running outside is_admin() does NOT by itself exclude wp-admin —
		// SqueezeServing::filter_image_src()/emit_vary_header() carry their own is_admin()
		// and is_feed() runtime guards so the Edit Image UI and feeds keep original URLs (D-08, D-04).
		// Gated on enable_serving so a toggled-off state means zero filter overhead and
		// leaves no residue on the front end — residue-free rollback (D-06).
		$serving_settings = SqueezeSettings::load();
		if ( $serving_settings->enable_serving ) {
			( SqueezeServing::from_wordpress() )->register();
		}

		// Library-wide Squeeze batch cron (TRG-01). Self-scheduling and idempotent: mirrors
		// the index crons above — daily cadence, dedup-guarded by wp_next_scheduled.
		add_action( 'assetdrips_squeeze_batch', array( SqueezeJob::class, 'run_library_batch' ) );
		if ( ! wp_next_scheduled( 'assetdrips_squeeze_batch' ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', 'assetdrips_squeeze_batch' );
		}

		// Single-fire Squeeze event callback (D-05). NOT scheduled here — SqueezeHooks
		// schedules this per-upload via a single-event cron (D-05).
		add_action( 'assetdrips_squeeze_single', array( SqueezeJob::class, 'run_single' ) );

		// Register the admin screens. Dashboard owns the top-level menu and the
		// suite landing page; each module registers its own submenu. Sift (the
		// scanner) is the first module — no longer the whole plugin.
		if ( is_admin() ) {
			( new Dashboard() )->register();
			( new ReviewScreen() )->register();
			( new FindScreen() )->register();
			( new IndexBuildNotice() )->register();
			( new FolderScreen() )->register();
			( new FolderFields() )->register();
			( new TagFields() )->register();
			( new BulkActions() )->register();
			( new SqueezeScreen() )->register();
			( new NextGenColumn() )->register();
		}
	}
}
