<?php
/**
 * Scans options for attachment references.
 *
 * @package AssetDrips
 */

declare( strict_types=1 );

namespace AssetDrips\Scan;

use AssetDrips\Usage\UsageMap;

defined( 'ABSPATH' ) || exit;

/**
 * Options scanner.
 *
 * Covers theme_mods (custom_logo, header/background images), widgets (media
 * widgets, text/HTML widgets with embedded URLs), the site icon and site logo,
 * and any other option whose value embeds an uploads URL.
 *
 * Targeted option names plus a value LIKE-prefilter keep this from walking the
 * entire options table; matched options are then unserialised and walked
 * properly — never string-matched as serialised data.
 */
final class OptionsScanner extends AbstractScanner {

	/**
	 * Options whose value is a bare attachment ID.
	 *
	 * @var string[]
	 */
	private const ID_OPTIONS = array( 'site_icon', 'site_logo' );

	/**
	 * {@inheritDoc}
	 *
	 * @return string
	 */
	public function source(): string {
		return 'options';
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param UsageMap                              $into     Shared evidence store.
	 * @param callable(string, int, ?int):void|null $progress Optional progress reporter.
	 * @return void
	 */
	public function scan( UsageMap $into, ?callable $progress = null ): void {
		$wpdb = $this->db();
		$this->report( $progress, 0, null );

		foreach ( self::ID_OPTIONS as $option ) {
			$value = get_option( $option );
			if ( is_numeric( $value ) ) {
				$this->walker->walk( (int) $value, 'option:' . $option, $this->source(), $into, true );
			}
		}

		$where  = array( "option_name LIKE 'theme_mods_%'", "option_name LIKE 'widget_%'" );
		$params = array();
		foreach ( $this->index->prefixes() as $prefix ) {
			$where[]  = 'option_value LIKE %s';
			$params[] = '%' . $wpdb->esc_like( $prefix ) . '/%';
		}
		$clause = implode( ' OR ', $where );

		// $clause is built from fixed LIKE fragments plus one %s per prefix; every
		// runtime value passes through prepare(). An options scan is inherently
		// direct and uncached.
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT option_name, option_value FROM {$wpdb->options} WHERE {$clause}",
				$params
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		foreach ( (array) $rows as $row ) {
			$name    = (string) $row['option_name'];
			$context = 'option:' . $name;
			$scope   = $this->walker->looks_like_image_key( $name );

			$this->walker->walk( maybe_unserialize( $row['option_value'] ), $context, $this->source(), $into, $scope );
		}

		$this->report( $progress, count( (array) $rows ), null );
	}
}
