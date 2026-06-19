<?php
/**
 * The `wp assetdrips scan` command.
 *
 * @package AssetDrips
 */

declare( strict_types=1 );

namespace AssetDrips\Cli;

use AssetDrips\Scan\ScanService;
use WP_CLI;

defined( 'ABSPATH' ) || exit;

/**
 * Runs a full scan and writes confidence tiers for every attachment.
 */
final class ScanCommand {

	/**
	 * Scan the media library and tier every attachment.
	 *
	 * ## OPTIONS
	 *
	 * [--resume]
	 * : Continue writing from the last checkpoint instead of starting over.
	 *
	 * [--dry-run]
	 * : Compute and report tiers without writing any results.
	 *
	 * [--batch=<n>]
	 * : Attachments to score per batch. Default 500.
	 *
	 * [--recent-days=<n>]
	 * : Treat uploads newer than this as MEDIUM. Default 30. Zero disables it.
	 *
	 * ## EXAMPLES
	 *
	 *     wp assetdrips scan
	 *     wp assetdrips scan --dry-run
	 *     wp assetdrips scan --resume --batch=1000
	 *
	 * @param array<int, string>    $args       Positional args (unused).
	 * @param array<string, string> $assoc_args Flags and options.
	 * @return void
	 */
	public function __invoke( array $args, array $assoc_args ): void {
		$resume      = isset( $assoc_args['resume'] );
		$dry_run     = isset( $assoc_args['dry-run'] );
		$batch       = (int) ( $assoc_args['batch'] ?? 500 );
		$recent_days = (int) ( $assoc_args['recent-days'] ?? 30 );

		$start_after = 0;
		if ( $resume ) {
			$checkpoint  = get_option( ScanService::CHECKPOINT_OPTION );
			$start_after = is_array( $checkpoint ) ? (int) ( $checkpoint['last_id'] ?? 0 ) : 0;
			WP_CLI::log( sprintf( 'Resuming after attachment #%d.', $start_after ) );
		}

		WP_CLI::log( 'Building reference index…' );
		$service = ScanService::from_wordpress(
			$recent_days,
			static function ( int $indexed ): void {
				WP_CLI::log( sprintf( '  indexed %s attachments…', number_format_i18n( $indexed ) ) );
			}
		);

		$this->report_coverage( $service->coverage() );

		// Live per-phase progress so a long scan never looks hung.
		$state    = (object) array(
			'label' => null,
			'bar'   => null,
			'done'  => 0,
		);
		$progress = function ( string $event, array $payload ) use ( &$state ): void {
			if ( 'scan' === $event ) {
				list( $source, $done, $total ) = $payload;
				$this->advance( $state, 'scan:' . $source, (int) $done, $total, $source );
			} elseif ( 'score' === $event ) {
				list( $done, $total ) = $payload;
				$this->advance( $state, 'score', (int) $done, (int) $total, 'scoring' );
			}
		};

		WP_CLI::log( 'Running scanners…' );
		$counts = $service->run(
			array(
				'batch'          => $batch,
				'start_after_id' => $start_after,
				'dry_run'        => $dry_run,
			),
			$progress
		);
		$this->finish_bar( $state );

		$summary = sprintf(
			'USED %d · HIGH %d · MEDIUM %d · LOW %d',
			$counts['USED'] ?? 0,
			$counts['HIGH'] ?? 0,
			$counts['MEDIUM'] ?? 0,
			$counts['LOW'] ?? 0
		);

		if ( $dry_run ) {
			WP_CLI::success( 'Dry run complete (nothing written). ' . $summary );
			return;
		}

		WP_CLI::success( 'Scan complete. ' . $summary );
	}

	/**
	 * Advance the progress display for a phase, starting a new bar when the
	 * phase changes. Totals drive a progress bar; an unknown total falls back to
	 * a periodic count so the phase still shows life.
	 *
	 * @param object   $state Mutable display state (label, bar, done).
	 * @param string   $key   Unique key for the current phase.
	 * @param int      $done  Items processed so far.
	 * @param int|null $total Total items, or null when unknown.
	 * @param string   $label Human label for the phase.
	 * @return void
	 */
	private function advance( object $state, string $key, int $done, ?int $total, string $label ): void {
		if ( $state->label !== $key ) {
			$this->finish_bar( $state );
			$state->label = $key;
			$state->done  = 0;
			$state->bar   = ( null !== $total && $total > 0 )
				? \WP_CLI\Utils\make_progress_bar( '  ' . $label, $total )
				: null;
			if ( null === $state->bar ) {
				WP_CLI::log( '  ' . $label . '…' );
			}
		}

		if ( null !== $state->bar ) {
			$delta = $done - $state->done;
			if ( $delta > 0 ) {
				$state->bar->tick( $delta );
				$state->done = $done;
			}
		} elseif ( $done >= $state->done + 5000 ) {
			WP_CLI::log( sprintf( '    %s rows…', number_format_i18n( $done ) ) );
			$state->done = $done;
		}
	}

	/**
	 * Finish the active progress bar, if any.
	 *
	 * @param object $state Mutable display state.
	 * @return void
	 */
	private function finish_bar( object $state ): void {
		if ( null !== $state->bar ) {
			$state->bar->finish();
			$state->bar = null;
		}
	}

	/**
	 * Print the coverage gaps that will shape the verdicts.
	 *
	 * @param \AssetDrips\Coverage\CoverageReport $coverage Coverage report.
	 * @return void
	 */
	private function report_coverage( $coverage ): void {
		if ( ! $coverage->has_gaps() ) {
			WP_CLI::log( 'Coverage: no gaps detected; HIGH verdicts are available.' );
			return;
		}

		if ( $coverage->has_significant_gaps() ) {
			WP_CLI::warning( 'Significant coverage gaps detected; unreferenced media will be held at LOW:' );
		} else {
			WP_CLI::log( 'Minor coverage gaps detected:' );
		}

		foreach ( $coverage->flags() as $flag ) {
			WP_CLI::log( sprintf( '  [%s] %s', $flag->severity(), $flag->label() ) );
		}
	}
}
