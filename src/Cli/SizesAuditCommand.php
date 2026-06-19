<?php
/**
 * The `wp assetdrips sizes-audit` command.
 *
 * @package AssetDrips
 */

declare( strict_types=1 );

namespace AssetDrips\Cli;

use AssetDrips\Admin\ScanProgress;
use AssetDrips\Squeeze\SizesAuditJob;
use WP_CLI;

defined( 'ABSPATH' ) || exit;

/**
 * Audits every attachment in the library for missing registered sizes and orphaned
 * thumbnail files, with live progress.
 *
 * Mirrors {@see SqueezeCommand}: a resumable batch job driven from the CLI with a
 * progress bar, --resume/--batch flag shape, and the same ScanProgress read-back —
 * but against the distinct 'assetdrips_sizes_audit_checkpoint' cursor (D-08).
 *
 * The audit is non-destructive: no files are deleted, no metadata is modified.
 * Results are written to assetdrips_squeeze.sizes_audit for review in SqueezeScreen.
 */
final class SizesAuditCommand {

	/**
	 * Audit every attachment in the library for missing registered sizes and orphaned files.
	 *
	 * ## OPTIONS
	 *
	 * [--resume]
	 * : Continue auditing from the last checkpoint instead of starting over.
	 *
	 * [--batch=<n>]
	 * : Attachments to audit per batch. Default 100.
	 *
	 * ## EXAMPLES
	 *
	 *     wp assetdrips sizes-audit
	 *     wp assetdrips sizes-audit --resume
	 *     wp assetdrips sizes-audit --batch=50
	 *     wp assetdrips sizes-audit --resume --batch=50
	 *
	 * @param array<int, string>    $args       Positional args (unused).
	 * @param array<string, string> $assoc_args Flags and options.
	 * @return void
	 */
	public function __invoke( array $args, array $assoc_args ): void {
		// Lift the PHP execution time cap for long-running audit batches (D-08).
		if ( function_exists( 'set_time_limit' ) ) {
			set_time_limit( 0 );
		}

		$resume = isset( $assoc_args['resume'] );
		$batch  = (int) ( $assoc_args['batch'] ?? 100 );

		if ( $resume ) {
			$checkpoint  = get_option( SizesAuditJob::CHECKPOINT_OPTION );
			$start_after = is_array( $checkpoint ) ? (int) ( $checkpoint['last_id'] ?? 0 ) : 0;
			WP_CLI::log( sprintf( 'Resuming after attachment #%d.', $start_after ) );
		}

		$total = $this->attachment_count();
		WP_CLI::log( sprintf( 'Auditing %s attachments…', number_format_i18n( $total ) ) );

		$bar = ( $total > 0 )
			? \WP_CLI\Utils\make_progress_bar( '  Auditing', $total )
			: null;

		// Drive the bar in lockstep with SizesAuditJob's throttled progress option,
		// which it refreshes after every batch via Admin\ScanProgress::set().
		$done    = 0;
		$advance = function () use ( $bar, &$done ): void {
			if ( null === $bar ) {
				return;
			}

			$progress  = ScanProgress::get();
			$processed = isset( $progress['done'] ) ? (int) $progress['done'] : $done;
			$delta     = $processed - $done;
			if ( $delta > 0 ) {
				$bar->tick( $delta );
				$done = $processed;
			}
		};

		$processed = SizesAuditJob::from_wordpress()->run( $batch, $resume );
		$advance();

		if ( null !== $bar ) {
			$bar->tick( max( 0, $processed - $done ) );
			$bar->finish();
		}

		WP_CLI::success(
			sprintf(
				'Sizes audit complete. %s attachments scanned.',
				number_format_i18n( $processed )
			)
		);
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
