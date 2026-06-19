<?php
/**
 * The `wp assetdrips squeeze` command.
 *
 * @package AssetDrips
 */

declare( strict_types=1 );

namespace AssetDrips\Cli;

use AssetDrips\Admin\ScanProgress;
use AssetDrips\Squeeze\SqueezeJob;
use WP_CLI;

defined( 'ABSPATH' ) || exit;

/**
 * Optimizes every attachment in the library, with live progress.
 *
 * Mirrors {@see IndexCommand}: a resumable batch job driven from the CLI with a
 * progress bar, the same --resume/--batch flag shape, and the same checkpoint
 * read-back — but against the distinct 'assetdrips_squeeze_checkpoint' cursor.
 * Adds --ops for narrowing the operation set and a savings summary at completion.
 */
final class SqueezeCommand {

	/**
	 * Optimize every attachment in the library.
	 *
	 * ## OPTIONS
	 *
	 * [--resume]
	 * : Continue optimizing from the last checkpoint instead of starting over.
	 *
	 * [--batch=<n>]
	 * : Attachments to optimize per batch. Default 100 (smaller than index's 500
	 *   because AVIF encoding is CPU-intensive — assumption A3).
	 *
	 * [--ops=<ops>]
	 * : Comma-separated list of operations to run: recompress,webp,avif,resize
	 *   Omitting --ops uses the ops enabled in Squeeze settings.
	 *
	 * ## EXAMPLES
	 *
	 *     wp assetdrips squeeze
	 *     wp assetdrips squeeze --resume
	 *     wp assetdrips squeeze --batch=50
	 *     wp assetdrips squeeze --ops=recompress,webp
	 *     wp assetdrips squeeze --resume --batch=50 --ops=recompress,webp,avif,resize
	 *
	 * @param array<int, string>    $args       Positional args (unused).
	 * @param array<string, string> $assoc_args Flags and options.
	 * @return void
	 */
	public function __invoke( array $args, array $assoc_args ): void {
		// D-08: lift the PHP execution time cap so long AVIF backfills are not killed.
		if ( function_exists( 'set_time_limit' ) ) {
			set_time_limit( 0 );
		}

		$resume = isset( $assoc_args['resume'] );
		$batch  = (int) ( $assoc_args['batch'] ?? 100 );

		// Parse --ops: split on comma, trim whitespace, drop empty tokens.
		$ops = isset( $assoc_args['ops'] )
			? array_values( array_filter( array_map( 'trim', explode( ',', (string) $assoc_args['ops'] ) ) ) )
			: null;

		// T-11-input: validate every --ops token against the whitelist.
		if ( null !== $ops ) {
			$whitelist = array( 'recompress', 'webp', 'avif', 'resize' );
			foreach ( $ops as $op ) {
				if ( ! in_array( $op, $whitelist, true ) ) {
					WP_CLI::error(
						sprintf(
							"'%s' is not a valid op. Allowed values: %s.",
							$op,
							implode( ', ', $whitelist )
						)
					);
				}
			}
		}

		// D-12: the disk pre-flight is performed per-chunk inside SqueezeJob::backfill()
		// against the REAL resolved source paths of each batch (WR-01). The former
		// empty-path estimate here always reported sufficient=true and gated nothing,
		// so it is intentionally NOT duplicated. A near-full disk now aborts the batch
		// at the chunk boundary (ScanProgress status 'aborted_disk'); the savings
		// summary below reflects whatever was processed before the abort.

		if ( $resume ) {
			$checkpoint  = get_option( SqueezeJob::CHECKPOINT_OPTION );
			$start_after = is_array( $checkpoint ) ? (int) ( $checkpoint['last_id'] ?? 0 ) : 0;
			WP_CLI::log( sprintf( 'Resuming after attachment #%d.', $start_after ) );
		}

		$total = $this->attachment_count();
		WP_CLI::log( sprintf( 'Optimizing %s attachments…', number_format_i18n( $total ) ) );

		$bar = ( $total > 0 )
			? \WP_CLI\Utils\make_progress_bar( '  Optimizing', $total )
			: null;

		// Drive the bar in lockstep with SqueezeJob's throttled progress option,
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

		$processed = SqueezeJob::from_wordpress()->backfill( $batch, $resume, $ops );
		$advance();

		if ( null !== $bar ) {
			$bar->tick( max( 0, $processed - $done ) );
			$bar->finish();
		}

		// TRG-06 / D-11: print savings summary from the option SqueezeJob writes on completion.
		$savings     = get_option( 'assetdrips_squeeze_last_batch_savings' );
		$bytes_saved = is_array( $savings ) ? (int) ( $savings['bytes_saved'] ?? 0 ) : 0;

		WP_CLI::success(
			sprintf(
				'Squeeze complete. %s attachments processed, %s bytes saved.',
				number_format_i18n( $processed ),
				number_format_i18n( $bytes_saved )
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
