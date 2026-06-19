<?php
/**
 * Scans Advanced Custom Fields data for attachment references.
 *
 * @package AssetDrips
 */

declare( strict_types=1 );

namespace AssetDrips\Scan;

use AssetDrips\Usage\UsageMap;

defined( 'ABSPATH' ) || exit;

/**
 * ACF scanner.
 *
 * ACF stores field values as flattened meta rows — including repeater and
 * flexible-content sub-fields as `parent_0_image`, `parent_1_image`, … — each
 * paired with an underscore-prefixed companion meta whose value is the field
 * definition key (`field_xxxx`). This scanner uses that companion to find
 * ACF-managed values precisely, then resolves them by the field's type rather
 * than guessing from the field name. That closes the gap the generic meta scan
 * leaves: media fields whose names contain no image-ish token.
 *
 * Field types are read from DB-registered ACF field definitions. Fields defined
 * via local JSON or PHP are not in the database, so their type is unknown; those
 * are treated as media scope (the conservative direction) and also flagged as a
 * coverage consideration elsewhere.
 *
 * Covered storage: postmeta, termmeta, and ACF options (`options_*`).
 */
final class AcfScanner extends AbstractScanner {

	/**
	 * Rows fetched per batch.
	 */
	private const BATCH_SIZE = 1000;

	/**
	 * ACF field types whose values reference media (or posts that may be media).
	 *
	 * @var string[]
	 */
	private const MEDIA_TYPES = array( 'image', 'file', 'gallery', 'post_object', 'relationship' );

	/**
	 * Field key to field type, lazily loaded from DB-registered definitions.
	 *
	 * @var array<string, string>|null
	 */
	private ?array $field_types = null;

	/**
	 * Whether a field type should be resolved in media scope.
	 *
	 * Unknown types (null) are treated as media scope: a locally-defined field
	 * holding an attachment ID is almost always a media field, and marking it
	 * used is the safe direction.
	 *
	 * @param string|null $type ACF field type, or null when unknown.
	 * @return bool
	 */
	public static function is_media_scope_type( ?string $type ): bool {
		if ( null === $type ) {
			return true;
		}

		return in_array( $type, self::MEDIA_TYPES, true );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @return string
	 */
	public function source(): string {
		return 'acf';
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param UsageMap                              $into     Shared evidence store.
	 * @param callable(string, int, ?int):void|null $progress Optional progress reporter.
	 * @return void
	 */
	public function scan( UsageMap $into, ?callable $progress = null ): void {
		$done = 0;
		$this->report( $progress, 0, null );
		$this->scan_postmeta( $into, $progress, $done );
		$this->scan_termmeta( $into, $progress, $done );
		$this->scan_options( $into, $progress, $done );
	}

	/**
	 * Scan ACF field values stored in postmeta.
	 *
	 * @param UsageMap                              $into     Shared evidence store.
	 * @param callable(string, int, ?int):void|null $progress Progress reporter.
	 * @param int                                   $done     Running processed count (by reference).
	 * @return void
	 */
	private function scan_postmeta( UsageMap $into, ?callable $progress, int &$done ): void {
		$wpdb = $this->db();
		$like = $wpdb->esc_like( 'field_' ) . '%';
		$last = 0;

		do {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- A full ACF scan is inherently direct and uncached.
			$rows    = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT d.meta_id, d.post_id, d.meta_key, d.meta_value, f.meta_value AS field_key
					FROM {$wpdb->postmeta} d
					INNER JOIN {$wpdb->postmeta} f ON f.post_id = d.post_id AND f.meta_key = CONCAT( '_', d.meta_key )
					WHERE d.meta_id > %d AND f.meta_value LIKE %s
					ORDER BY d.meta_id ASC LIMIT %d",
					$last,
					$like,
					self::BATCH_SIZE
				),
				ARRAY_A
			);
			$fetched = count( (array) $rows );

			foreach ( (array) $rows as $row ) {
				$last    = (int) $row['meta_id'];
				$context = 'acf:post:' . (int) $row['post_id'] . ':' . (string) $row['meta_key'];
				$this->resolve_field( $row, $context, $into );
			}

			$done += $fetched;
			$this->report( $progress, $done, null );
		} while ( self::BATCH_SIZE === $fetched );
	}

	/**
	 * Scan ACF field values stored in termmeta.
	 *
	 * @param UsageMap                              $into     Shared evidence store.
	 * @param callable(string, int, ?int):void|null $progress Progress reporter.
	 * @param int                                   $done     Running processed count (by reference).
	 * @return void
	 */
	private function scan_termmeta( UsageMap $into, ?callable $progress, int &$done ): void {
		$wpdb = $this->db();
		$like = $wpdb->esc_like( 'field_' ) . '%';
		$last = 0;

		do {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- A full ACF scan is inherently direct and uncached.
			$rows    = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT d.meta_id, d.term_id, d.meta_key, d.meta_value, f.meta_value AS field_key
					FROM {$wpdb->termmeta} d
					INNER JOIN {$wpdb->termmeta} f ON f.term_id = d.term_id AND f.meta_key = CONCAT( '_', d.meta_key )
					WHERE d.meta_id > %d AND f.meta_value LIKE %s
					ORDER BY d.meta_id ASC LIMIT %d",
					$last,
					$like,
					self::BATCH_SIZE
				),
				ARRAY_A
			);
			$fetched = count( (array) $rows );

			foreach ( (array) $rows as $row ) {
				$last    = (int) $row['meta_id'];
				$context = 'acf:term:' . (int) $row['term_id'] . ':' . (string) $row['meta_key'];
				$this->resolve_field( $row, $context, $into );
			}

			$done += $fetched;
			$this->report( $progress, $done, null );
		} while ( self::BATCH_SIZE === $fetched );
	}

	/**
	 * Scan ACF field values stored as options (`options_*`).
	 *
	 * @param UsageMap                              $into     Shared evidence store.
	 * @param callable(string, int, ?int):void|null $progress Progress reporter.
	 * @param int                                   $done     Running processed count (by reference).
	 * @return void
	 */
	private function scan_options( UsageMap $into, ?callable $progress, int &$done ): void {
		$wpdb        = $this->db();
		$option_like = $wpdb->esc_like( 'options_' ) . '%';
		$field_like  = $wpdb->esc_like( 'field_' ) . '%';
		$last        = 0;

		do {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- A full ACF options scan is inherently direct and uncached.
			$rows    = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT d.option_id, d.option_name, d.option_value, f.option_value AS field_key
					FROM {$wpdb->options} d
					INNER JOIN {$wpdb->options} f ON f.option_name = CONCAT( '_', d.option_name )
					WHERE d.option_id > %d AND d.option_name LIKE %s AND f.option_value LIKE %s
					ORDER BY d.option_id ASC LIMIT %d",
					$last,
					$option_like,
					$field_like,
					self::BATCH_SIZE
				),
				ARRAY_A
			);
			$fetched = count( (array) $rows );

			foreach ( (array) $rows as $row ) {
				$last    = (int) $row['option_id'];
				$context = 'acf:option:' . (string) $row['option_name'];
				$this->resolve_field(
					array(
						'field_key'  => $row['field_key'],
						// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Internal row-array key, not a meta query argument.
						'meta_key'   => $row['option_name'],
						// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- Internal row-array key, not a meta query argument.
						'meta_value' => $row['option_value'],
					),
					$context,
					$into
				);
			}

			$done += $fetched;
			$this->report( $progress, $done, null );
		} while ( self::BATCH_SIZE === $fetched );
	}

	/**
	 * Resolve one ACF field row into usage hits, scoped by the field's type.
	 *
	 * @param array<string, mixed> $row     Row with field_key, meta_key, meta_value.
	 * @param string               $context Evidence locator.
	 * @param UsageMap             $into    Shared evidence store.
	 * @return void
	 */
	private function resolve_field( array $row, string $context, UsageMap $into ): void {
		$field_key = (string) $row['field_key'];
		$type      = $this->field_types()[ $field_key ] ?? null;

		// Known media type (or unknown) → resolve IDs and URLs. A media field
		// whose name has no image token is still covered here. Known non-media
		// types still resolve any embedded URL, never a bare ID.
		$scope = self::is_media_scope_type( $type );

		$this->walker->walk( maybe_unserialize( $row['meta_value'] ), $context, $this->source(), $into, $scope );
	}

	/**
	 * Field key to field type, from DB-registered ACF field definitions.
	 *
	 * @return array<string, string>
	 */
	private function field_types(): array {
		if ( null !== $this->field_types ) {
			return $this->field_types;
		}

		$wpdb              = $this->db();
		$this->field_types = array();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- One-off load of the field-definition map for the scan.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT post_name, post_content FROM {$wpdb->posts} WHERE post_type = %s",
				'acf-field'
			),
			ARRAY_A
		);

		foreach ( (array) $rows as $row ) {
			$definition = maybe_unserialize( $row['post_content'] );
			if ( is_array( $definition ) && isset( $definition['type'] ) && is_string( $definition['type'] ) ) {
				$this->field_types[ (string) $row['post_name'] ] = $definition['type'];
			}
		}

		return $this->field_types;
	}
}
