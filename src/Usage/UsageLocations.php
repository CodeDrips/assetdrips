<?php
/**
 * Read/write seam over the assetdrips_usage_locations table.
 *
 * @package AssetDrips
 */

declare( strict_types=1 );

namespace AssetDrips\Usage;

use AssetDrips\Db\Schema;

defined( 'ABSPATH' ) || exit;

/**
 * The data-access seam for the usage-locations lookup table.
 *
 * Enables the "Used on …" inversion: given a host post or term, return
 * the attachment IDs used there — via a single indexed query on
 * KEY host (host_type, host_id), not an O(library) JSON decode.
 *
 * Security contracts (T-02-01):
 * - The table name comes exclusively from {@see Schema::usage_locations_table()}.
 * - Every value is bound through wpdb::prepare(); no value is interpolated raw.
 *
 * Population contract (D-09 additive):
 * - {@see self::populate_from_usage()} reads the in-memory UsageMap that the
 *   usage lane already gathers and derives location rows by parsing each
 *   hit's context via {@see UsageLocator::parse()}. No scanner change, no
 *   extra scan — the same evidence is reused.
 */
final class UsageLocations {

	/**
	 * Database handle.
	 *
	 * @var \wpdb
	 */
	private \wpdb $wpdb;

	/**
	 * Construct with an explicit database handle.
	 *
	 * @param \wpdb $wpdb Database handle.
	 */
	public function __construct( \wpdb $wpdb ) {
		$this->wpdb = $wpdb;
	}

	/**
	 * Construct from the live WordPress environment.
	 *
	 * @return self
	 */
	public static function from_wordpress(): self {
		global $wpdb;

		return new self( $wpdb );
	}

	/**
	 * Atomically replace all location rows for one attachment.
	 *
	 * Deletes existing rows for the attachment, then batch-inserts the
	 * new ones. Idempotent: a re-run for the same attachment produces the
	 * same rows with no duplication (T-02-06).
	 *
	 * @param int                                                                                 $attachment_id Attachment post ID.
	 * @param array<int, array{host_type: string, host_id: int, source: string, context: string}> $locations Location rows to insert.
	 * @return void
	 */
	public function replace_for_attachment( int $attachment_id, array $locations ): void {
		$table = Schema::usage_locations_table();

		// Delete prior rows for this attachment (idempotent replace).
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Attachment-keyed DELETE; table name is a Schema constant (never input) and the value is bound via prepare().
		$this->wpdb->query(
			$this->wpdb->prepare(
				"DELETE FROM {$table} WHERE attachment_id = %d",
				$attachment_id
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		if ( empty( $locations ) ) {
			return;
		}

		// Batch INSERT — one placeholder group per row.
		$placeholders = array();
		$values       = array();

		foreach ( $locations as $loc ) {
			$placeholders[] = '(%d, %s, %d, %s, %s)';
			$values[]       = (int) $attachment_id;
			$values[]       = (string) $loc['host_type'];
			$values[]       = (int) $loc['host_id'];
			$values[]       = (string) $loc['source'];
			$values[]       = (string) $loc['context'];
		}

		$sql = "INSERT INTO {$table} (attachment_id, host_type, host_id, source, context) VALUES "
			. implode( ', ', $placeholders );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Bulk insert into usage_locations; table name is a Schema constant (never input) and every value is bound via prepare().
		$this->wpdb->query(
			$this->wpdb->prepare( $sql, ...$values )
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * Return attachment IDs used on a given host (the "Used on …" query).
	 *
	 * Uses KEY host (host_type, host_id) — single indexed read.
	 *
	 * @param string $host_type Host type: 'post' or 'term'.
	 * @param int    $host_id   Host post/term ID.
	 * @return int[] Distinct attachment IDs found on this host.
	 */
	public function for_host( string $host_type, int $host_id ): array {
		$table = Schema::usage_locations_table();

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Indexed read on KEY host; table name from Schema constant (never input); host_type and host_id bound via prepare().
		$ids = $this->wpdb->get_col(
			$this->wpdb->prepare(
				"SELECT DISTINCT attachment_id FROM {$table} WHERE host_type = %s AND host_id = %d",
				$host_type,
				$host_id
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		return array_map( 'intval', (array) $ids );
	}

	/**
	 * Return all location rows for one attachment (the "Where is this used" panel).
	 *
	 * @param int $attachment_id Attachment post ID.
	 * @return array<int, array<string, mixed>> Location rows as associative arrays.
	 */
	public function for_attachment( int $attachment_id ): array {
		$table = Schema::usage_locations_table();

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Reads all locations for one attachment; table name from Schema constant (never input); value bound via prepare().
		$rows = $this->wpdb->get_results(
			$this->wpdb->prepare(
				"SELECT * FROM {$table} WHERE attachment_id = %d",
				$attachment_id
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		return (array) $rows;
	}

	/**
	 * Count rows in the table. Used for tests and diagnostics.
	 *
	 * @return int
	 */
	public function count_rows(): int {
		$table = Schema::usage_locations_table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Row count over the table; table name is a Schema constant, no user input.
		return (int) $this->wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );
	}

	/**
	 * Populate location rows from the already-gathered UsageMap (D-09 additive).
	 *
	 * For each attachment in the map, parses every hit's context via
	 * {@see UsageLocator::parse()} and writes the resulting location rows via
	 * {@see self::replace_for_attachment()}. Hits whose context does not resolve
	 * to a post/term host (e.g. option:* forms) are silently skipped.
	 *
	 * This reads the same in-memory UsageMap that the usage lane already
	 * gathers — no scanner change, no extra scan (honors D-09 additive).
	 *
	 * @param UsageMap $usage Usage evidence from a scan or usage refresh.
	 * @return int Number of attachments processed (location rows replaced).
	 */
	public function populate_from_usage( UsageMap $usage ): int {
		$processed = 0;

		foreach ( $usage->used_ids() as $attachment_id ) {
			$locations = array();

			foreach ( $usage->hits_for( $attachment_id ) as $hit ) {
				$host = UsageLocator::parse( $hit->context() );

				if ( null === $host ) {
					continue;
				}

				$locations[] = array(
					'host_type' => $host['host_type'],
					'host_id'   => $host['host_id'],
					'source'    => $hit->source(),
					'context'   => $hit->context(),
				);
			}

			$this->replace_for_attachment( $attachment_id, $locations );
			++$processed;
		}

		return $processed;
	}
}
