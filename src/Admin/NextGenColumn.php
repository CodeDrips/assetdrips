<?php
/**
 * Media Library next-gen format status column.
 *
 * @package AssetDrips
 */

declare( strict_types=1 );

namespace AssetDrips\Admin;

use AssetDrips\Db\Schema;
use AssetDrips\Squeeze\BackupRecord;
use AssetDrips\Squeeze\OptimizationIndex;

defined( 'ABSPATH' ) || exit;

/**
 * Registers and renders the assetdrips_nextgen column on the Media Library
 * list table (upload.php). Shows next-gen format badges, optimization status,
 * bytes-saved, and per-item optimize/restore action links (DASH-06).
 *
 * Hooks (registered inside is_admin() in Plugin::boot()):
 *   - manage_media_columns        (filter): add column header
 *   - manage_media_custom_column  (action): render cell
 *   - admin_head                  (action): print scoped CSS
 */
final class NextGenColumn {

	/**
	 * Register the column filter, action, and style hooks.
	 *
	 * @return void
	 */
	public function register(): void {
		add_filter( 'manage_media_columns', array( $this, 'add_column' ) );
		add_action( 'manage_media_custom_column', array( $this, 'render_column' ), 10, 2 );
		add_action( 'admin_head', array( $this, 'print_styles' ) );
	}

	/**
	 * Add the next-gen column to the Media Library list table.
	 *
	 * @param array<string,string> $columns Existing column definitions.
	 * @return array<string,string>
	 */
	public function add_column( array $columns ): array {
		$columns['assetdrips_nextgen'] = esc_html__( 'Squeeze', 'assetdrips' );
		return $columns;
	}

	/**
	 * Render the Squeeze column cell for an attachment row (DASH-06).
	 *
	 * PITFALL 5 (D-09): First statement is an early-return guard so this callback
	 * acts only on its own column and does not double-print content for 'title',
	 * 'author', or any other column registered on the same list table.
	 *
	 * Output states (UI-SPEC Surface 4 / NextGenColumn — Per-row states):
	 *   Not indexed  : WebP/AVIF badges (existing); "Optimize now" link.
	 *   complete     : WebP/AVIF badges; "Optimized" green badge; "{N} KB saved";
	 *                  "Restore original" link only when has_backup=1.
	 *   pending/failed: WebP/AVIF badges; muted/red status badge; "Optimize now" link.
	 *
	 * All dynamic output is escaped:
	 *   - status/bytes text  → esc_html()
	 *   - action hrefs       → esc_url()
	 *   - aria-labels        → esc_attr__()
	 * (T-14-06-04 XSS mitigation)
	 *
	 * The second get_row() read returns the squeeze row joined with backup status
	 * (has_backup) so both reads use the get_row_queue seam added in Plan 01.
	 *
	 * @param string $column_name   The column key fired by manage_media_custom_column.
	 * @param int    $attachment_id The attachment post ID for this row.
	 * @return void
	 */
	public function render_column( string $column_name, int $attachment_id ): void {
		if ( 'assetdrips_nextgen' !== $column_name ) {
			return; // PITFALL 5: early return for every other column.
		}

		// ---- Read 1: flag columns (has_webp / has_avif / is_oversized) ----------
		$flags = OptimizationIndex::from_wordpress()->get_flags( $attachment_id );
		$parts = array();

		if ( $flags['has_webp'] ) {
			$parts[] = '<span class="ad-nextgen-badge ad-nextgen-webp">' . esc_html( 'WebP' ) . '</span>';
		}
		if ( $flags['has_avif'] ) {
			$parts[] = '<span class="ad-nextgen-badge ad-nextgen-avif">' . esc_html( 'AVIF' ) . '</span>';
		}

		if ( $parts ) {
			echo implode( '', $parts ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Each part is already esc_html()-escaped above.
		}

		// ---- Read 2: squeeze row + backup status (single broader read, Open Q3) ---
		// Query returns status, original_bytes, optimized_bytes, has_backup in one row
		// so only one get_row() call is needed after get_flags() (D-00 seam).
		global $wpdb;
		$sq_table = Schema::squeeze_table();
		$bk_table = Schema::squeeze_backups_table();
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Single-row read for column render; table names are Schema constants (never user input); attachment_id bound via prepare().
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT sq.status, sq.original_bytes, sq.optimized_bytes,
				        CASE WHEN bk.attachment_id IS NOT NULL THEN 1 ELSE 0 END AS has_backup
				 FROM {$sq_table} sq
				 LEFT JOIN {$bk_table} bk
				        ON bk.attachment_id = sq.attachment_id AND bk.status = %s
				 WHERE sq.attachment_id = %d
				 LIMIT 1",
				BackupRecord::ACTIVE,
				$attachment_id
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		$status     = is_array( $row ) ? (string) ( $row['status'] ?? '' ) : '';
		$has_backup = is_array( $row ) && ! empty( $row['has_backup'] );

		// ---- Status badge (T-14-06-04) ------------------------------------------
		if ( 'complete' === $status ) {
			echo '<span class="ad-squeeze-status-badge ad-squeeze-status-optimized">' . esc_html( 'Optimized' ) . '</span>';

			// Bytes saved — inline computation (D-09: no webp/avif bytes).
			$orig     = is_array( $row ) ? (int) ( $row['original_bytes'] ?? 0 ) : 0;
			$opt      = is_array( $row ) ? (int) ( $row['optimized_bytes'] ?? 0 ) : 0;
			$saved_kb = max( 0, $orig - $opt ) / 1024;
			echo '<span class="ad-squeeze-bytes-saved">' . esc_html( number_format_i18n( $saved_kb, 0 ) . ' KB saved' ) . '</span>';
		} elseif ( 'pending' === $status ) {
			echo '<span class="ad-squeeze-status-badge ad-squeeze-status-pending">' . esc_html( 'Pending' ) . '</span>';
		} elseif ( 'failed' === $status ) {
			echo '<span class="ad-squeeze-status-badge ad-squeeze-status-failed">' . esc_html( 'Failed' ) . '</span>';
		}

		// ---- Action links (T-14-06-02 / T-14-06-03: id-bound nonce) -------------
		echo '<span class="ad-squeeze-action-links">';
		if ( 'complete' !== $status ) {
			// "Optimize now" — shown when no complete row (not indexed, pending, or failed).
			$opt_url = esc_url(
				add_query_arg(
					array(
						'action'   => 'assetdrips_squeeze_single_optimize',
						'id'       => $attachment_id,
						'_wpnonce' => wp_create_nonce( 'assetdrips_squeeze_single_optimize_' . $attachment_id ),
					),
					admin_url( 'admin-post.php' )
				)
			);
			echo '<a href="' . $opt_url . '">' . esc_html( 'Optimize now' ) . '</a>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $opt_url already passed through esc_url() above.
		}
		if ( $has_backup ) {
			// "Restore original" — shown only when BackupManager has an active backup.
			$restore_url = esc_url(
				add_query_arg(
					array(
						'action'   => 'assetdrips_squeeze_single_restore',
						'id'       => $attachment_id,
						'_wpnonce' => wp_create_nonce( 'assetdrips_squeeze_single_restore_' . $attachment_id ),
					),
					admin_url( 'admin-post.php' )
				)
			);
			echo '<a href="' . $restore_url . '">' . esc_html( 'Restore original' ) . '</a>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $restore_url already passed through esc_url() above.
		}
		echo '</span>';

		// If no badges and no squeeze row, show the em-dash absent placeholder.
		if ( ! $parts && '' === $status ) {
			echo '<span class="ad-nextgen-absent" aria-label="' . esc_attr__( 'Not yet optimized', 'assetdrips' ) . '">&#8212;</span>';
		}
	}

	/**
	 * Print the inline CSS for the next-gen column badges.
	 *
	 * Scoped to the Media Library screen (upload.php) only via get_current_screen().
	 * Follows the print_styles() pattern from SqueezeScreen.php (same echo '<style>'
	 * convention). Colors and dimensions per UI-SPEC / D-09:
	 *   - Badge: #46b450 green text, #f1f1f1 bg, 11px/700, pill border-radius 999px.
	 *   - Absent indicator: #777 muted color, 13px (wp-admin default, no override needed).
	 *
	 * @return void
	 */
	public function print_styles(): void {
		// Scope to upload.php screen only (T-10-23 — admin-only, read-only display).
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || 'upload' !== $screen->id ) {
			return;
		}
		echo '<style>
.ad-nextgen-badge{display:inline-block;background:#f1f1f1;color:#46b450;border-radius:999px;padding:1px 8px;font-size:11px;font-weight:700;letter-spacing:.03em;margin-right:4px;}
.ad-nextgen-absent{color:#777;font-size:13px;}
.ad-squeeze-status-badge{display:inline-block;border-radius:999px;padding:4px 8px;font-size:12px;font-weight:600;margin-right:4px;}
.ad-squeeze-status-optimized{background:#f1f1f1;color:#46b450;}
.ad-squeeze-status-pending{background:#f1f1f1;color:#777;}
.ad-squeeze-status-failed{background:#fff0f0;color:#a00;}
.ad-squeeze-bytes-saved{color:#777;font-size:12px;display:block;margin-top:4px;}
.ad-squeeze-action-links{display:block;margin-top:4px;font-size:12px;}
</style>';
	}
}
