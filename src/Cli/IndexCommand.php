<?php
/**
 * The `wp assetdrips index` command.
 *
 * @package AssetDrips
 */

declare( strict_types=1 );

namespace AssetDrips\Cli;

use AssetDrips\Admin\ScanProgress;
use AssetDrips\Index\IndexBuilder;
use WP_CLI;

defined( 'ABSPATH' ) || exit;

/**
 * Backfills the media index over the existing library, with live progress.
 *
 * Mirrors {@see ScanCommand}: a resumable batch job driven from the CLI with a
 * progress bar, the same --resume/--batch flag shape, and the same checkpoint
 * read-back — but against the distinct 'assetdrips_index_checkpoint' cursor.
 */
final class IndexCommand {

	/**
	 * Build the media index for every attachment in the library.
	 *
	 * ## OPTIONS
	 *
	 * [--resume]
	 * : Continue indexing from the last checkpoint instead of starting over.
	 *
	 * [--batch=<n>]
	 * : Attachments to index per batch. Default 500.
	 *
	 * ## EXAMPLES
	 *
	 *     wp assetdrips index
	 *     wp assetdrips index --resume
	 *     wp assetdrips index --batch=1000
	 *
	 * ## RE-BACKFILL AFTER DB_VERSION '3' MIGRATION
	 *
	 * After deploying the caption/description schema change (DB_VERSION '3'),
	 * run `wp assetdrips index` once to back-populate the caption and description
	 * columns for all pre-existing rows. The ON DUPLICATE KEY UPDATE in
	 * MediaIndex::upsert_structural() ensures idempotency — re-running is safe.
	 *
	 * The drift-reconciliation cron (assetdrips_index_reconcile) tops up MISSING
	 * rows but does NOT re-upsert existing rows' newly-added columns, so a
	 * one-time `wp assetdrips index` is the intended back-population path.
	 *
	 * Until this is run, pre-existing rows will have empty caption/description
	 * values and will not appear in caption/description search results.
	 *
	 * @param array<int, string>    $args       Positional args (unused).
	 * @param array<string, string> $assoc_args Flags and options.
	 * @return void
	 */
	public function __invoke( array $args, array $assoc_args ): void {
		$resume = isset( $assoc_args['resume'] );
		$batch  = (int) ( $assoc_args['batch'] ?? 500 );

		if ( $resume ) {
			$checkpoint  = get_option( IndexBuilder::CHECKPOINT_OPTION );
			$start_after = is_array( $checkpoint ) ? (int) ( $checkpoint['last_id'] ?? 0 ) : 0;
			WP_CLI::log( sprintf( 'Resuming after attachment #%d.', $start_after ) );
		}

		$total = $this->attachment_count();
		WP_CLI::log( sprintf( 'Indexing %s attachments…', number_format_i18n( $total ) ) );

		$bar = ( $total > 0 )
			? \WP_CLI\Utils\make_progress_bar( '  Indexing', $total )
			: null;

		// Drive the bar in lockstep with the builder's throttled progress option,
		// which it refreshes after every batch via Admin\ScanProgress::set().
		$done    = 0;
		$advance = function () use ( $bar, &$done ): void {
			if ( null === $bar ) {
				return;
			}

			$progress = ScanProgress::get();
			$indexed  = isset( $progress['done'] ) ? (int) $progress['done'] : $done;
			$delta    = $indexed - $done;
			if ( $delta > 0 ) {
				$bar->tick( $delta );
				$done = $indexed;
			}
		};

		$indexed = IndexBuilder::from_wordpress()->backfill( $batch, $resume );
		$advance();

		if ( null !== $bar ) {
			$bar->tick( max( 0, $indexed - $done ) );
			$bar->finish();
		}

		WP_CLI::success( sprintf( 'Index complete. %s attachments indexed.', number_format_i18n( $indexed ) ) );
	}

	/**
	 * Count attachments in the library, for the progress total.
	 *
	 * @return int
	 */
	private function attachment_count(): int {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Cheap COUNT for the CLI progress total; {$wpdb->posts} is a core table name with no user input.
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'attachment'" );
	}
}
