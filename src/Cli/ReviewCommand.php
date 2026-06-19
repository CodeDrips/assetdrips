<?php
/**
 * The `wp assetdrips list|restore|quarantine|purge` commands.
 *
 * @package AssetDrips
 */

declare( strict_types=1 );

namespace AssetDrips\Cli;

use AssetDrips\Db\Schema;
use AssetDrips\Quarantine\QuarantineManager;
use AssetDrips\Quarantine\RecoveryRecord;
use AssetDrips\Score\Tier;
use WP_CLI;

defined( 'ABSPATH' ) || exit;

/**
 * Reviews scan results and runs the reversible cleanup actions.
 */
final class ReviewCommand {

	/**
	 * List scan results.
	 *
	 * ## OPTIONS
	 *
	 * [--tier=<tier>]
	 * : Show attachments in this tier (USED, HIGH, MEDIUM, LOW). Omit for a summary.
	 *
	 * [--limit=<n>]
	 * : Maximum rows to show with --tier. Default 50.
	 *
	 * [--format=<format>]
	 * : Output format: table, csv, json, ids. Default table.
	 *
	 * ## EXAMPLES
	 *
	 *     wp assetdrips list
	 *     wp assetdrips list --tier=HIGH
	 *     wp assetdrips list --tier=HIGH --format=ids
	 *
	 * @param array<int, string>    $args       Positional args (unused).
	 * @param array<string, string> $assoc_args Flags and options.
	 * @return void
	 */
	public function list_results( array $args, array $assoc_args ): void {
		if ( ! isset( $assoc_args['tier'] ) ) {
			$this->print_summary();
			return;
		}

		$tier   = strtoupper( (string) $assoc_args['tier'] );
		$limit  = max( 1, (int) ( $assoc_args['limit'] ?? 50 ) );
		$format = (string) ( $assoc_args['format'] ?? 'table' );

		if ( null === Tier::tryFrom( $tier ) ) {
			WP_CLI::error( "Unknown tier '{$tier}'. Use USED, HIGH, MEDIUM, or LOW." );
		}

		$wpdb  = $GLOBALS['wpdb'];
		$table = Schema::results_table();

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name from Schema; tier and limit are placeholders.
		$sql = $wpdb->prepare( "SELECT attachment_id, tier, confidence FROM {$table} WHERE tier = %s ORDER BY attachment_id ASC LIMIT %d", $tier, $limit );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared -- Pre-prepared read of scan results.
		$rows = (array) $wpdb->get_results( $sql, ARRAY_A );

		if ( array() === $rows ) {
			WP_CLI::log( "No attachments in tier {$tier}." );
			return;
		}

		\WP_CLI\Utils\format_items( $format, $rows, array( 'attachment_id', 'tier', 'confidence' ) );
	}

	/**
	 * Quarantine attachments (reversible move + DB snapshot).
	 *
	 * Provide attachment IDs, or use --tier=HIGH to quarantine every HIGH-tier
	 * attachment. USED attachments are always refused.
	 *
	 * ## OPTIONS
	 *
	 * [<id>...]
	 * : Attachment IDs to quarantine.
	 *
	 * [--tier=<tier>]
	 * : Quarantine every attachment in this tier. Only HIGH is allowed without --force.
	 *
	 * [--force]
	 * : Allow quarantining MEDIUM or LOW tiers with --tier.
	 *
	 * [--yes]
	 * : Skip the confirmation prompt.
	 *
	 * ## EXAMPLES
	 *
	 *     wp assetdrips quarantine 1234 1235
	 *     wp assetdrips quarantine --tier=HIGH --yes
	 *
	 * @param array<int, string>    $args       Attachment IDs.
	 * @param array<string, string> $assoc_args Flags and options.
	 * @return void
	 */
	public function quarantine( array $args, array $assoc_args ): void {
		$ids = array_map( 'intval', $args );

		if ( isset( $assoc_args['tier'] ) ) {
			$tier = strtoupper( (string) $assoc_args['tier'] );
			if ( Tier::HIGH->value !== $tier && ! isset( $assoc_args['force'] ) ) {
				WP_CLI::error( 'Only HIGH is self-serve. Pass --force to quarantine MEDIUM or LOW by tier.' );
			}
			$ids = array_merge( $ids, $this->ids_in_tier( $tier ) );
		}

		$ids = array_values( array_unique( array_filter( $ids ) ) );
		if ( array() === $ids ) {
			WP_CLI::error( 'No attachment IDs given.' );
		}

		WP_CLI::confirm( sprintf( 'Quarantine %d attachment(s)? Files are moved, not deleted, and can be restored.', count( $ids ) ), $assoc_args );

		$manager = QuarantineManager::from_wordpress();
		$done    = 0;

		foreach ( $ids as $id ) {
			try {
				$record_id = $manager->quarantine( $id );
				WP_CLI::log( sprintf( '  quarantined attachment #%d (recovery #%d)', $id, $record_id ) );
				++$done;
			} catch ( \Throwable $error ) {
				WP_CLI::warning( sprintf( 'Skipped #%d: %s', $id, $error->getMessage() ) );
			}
		}

		WP_CLI::success( sprintf( 'Quarantined %d of %d.', $done, count( $ids ) ) );
	}

	/**
	 * Restore quarantined attachments exactly.
	 *
	 * ## OPTIONS
	 *
	 * <record_id>...
	 * : Recovery record IDs to restore (from `wp assetdrips list` / quarantine output).
	 *
	 * ## EXAMPLES
	 *
	 *     wp assetdrips restore 12 13
	 *
	 * @param array<int, string> $args Recovery record IDs.
	 * @return void
	 */
	public function restore( array $args ): void {
		$record_ids = array_filter( array_map( 'intval', $args ) );
		if ( array() === $record_ids ) {
			WP_CLI::error( 'Provide one or more recovery record IDs.' );
		}

		$manager = QuarantineManager::from_wordpress();
		$done    = 0;

		foreach ( $record_ids as $record_id ) {
			try {
				$manager->restore( $record_id );
				WP_CLI::log( sprintf( '  restored recovery #%d', $record_id ) );
				++$done;
			} catch ( \Throwable $error ) {
				WP_CLI::warning( sprintf( 'Skipped #%d: %s', $record_id, $error->getMessage() ) );
			}
		}

		WP_CLI::success( sprintf( 'Restored %d of %d.', $done, count( $record_ids ) ) );
	}

	/**
	 * Permanently delete quarantined files (irreversible).
	 *
	 * ## OPTIONS
	 *
	 * <record_id>...
	 * : Recovery record IDs to purge.
	 *
	 * [--yes]
	 * : Skip the confirmation prompt.
	 *
	 * @param array<int, string>    $args       Recovery record IDs.
	 * @param array<string, string> $assoc_args Flags and options.
	 * @return void
	 */
	public function purge( array $args, array $assoc_args ): void {
		$record_ids = array_filter( array_map( 'intval', $args ) );
		if ( array() === $record_ids ) {
			WP_CLI::error( 'Provide one or more recovery record IDs.' );
		}

		WP_CLI::confirm( sprintf( 'Permanently delete files for %d record(s)? This cannot be undone.', count( $record_ids ) ), $assoc_args );

		$manager = QuarantineManager::from_wordpress();
		$done    = 0;

		foreach ( $record_ids as $record_id ) {
			try {
				$manager->purge( $record_id );
				WP_CLI::log( sprintf( '  purged recovery #%d', $record_id ) );
				++$done;
			} catch ( \Throwable $error ) {
				WP_CLI::warning( sprintf( 'Skipped #%d: %s', $record_id, $error->getMessage() ) );
			}
		}

		WP_CLI::success( sprintf( 'Purged %d of %d.', $done, count( $record_ids ) ) );
	}

	/**
	 * Print a tier-count summary of the latest scan.
	 *
	 * @return void
	 */
	private function print_summary(): void {
		$wpdb  = $GLOBALS['wpdb'];
		$table = Schema::results_table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared -- Aggregate read of scan results; table name from Schema.
		$rows = (array) $wpdb->get_results( 'SELECT tier, COUNT(*) AS n FROM ' . $table . ' GROUP BY tier', ARRAY_A );

		if ( array() === $rows ) {
			WP_CLI::log( 'No scan results yet. Run `wp assetdrips scan` first.' );
			return;
		}

		$counts = array();
		foreach ( $rows as $row ) {
			$counts[ (string) $row['tier'] ] = (int) $row['n'];
		}

		$items = array();
		foreach ( array( Tier::USED, Tier::HIGH, Tier::MEDIUM, Tier::LOW ) as $tier ) {
			$items[] = array(
				'tier'  => $tier->value,
				'count' => $counts[ $tier->value ] ?? 0,
				'note'  => $tier->is_self_serve() ? 'self-serve delete' : ( $tier->is_candidate() ? 'human review' : 'in use' ),
			);
		}

		\WP_CLI\Utils\format_items( 'table', $items, array( 'tier', 'count', 'note' ) );
	}

	/**
	 * Attachment IDs recorded in a given tier.
	 *
	 * @param string $tier Tier value.
	 * @return int[]
	 */
	private function ids_in_tier( string $tier ): array {
		$wpdb  = $GLOBALS['wpdb'];
		$table = Schema::results_table();

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name from Schema; tier is a placeholder.
		$sql = $wpdb->prepare( "SELECT attachment_id FROM {$table} WHERE tier = %s", $tier );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared -- Pre-prepared read of scan results.
		$ids = (array) $wpdb->get_col( $sql );

		return array_map( 'intval', $ids );
	}
}
