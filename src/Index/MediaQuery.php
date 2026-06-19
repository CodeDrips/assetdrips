<?php
/**
 * Criteria value object and SQL-builder seam for media index queries.
 *
 * @package AssetDrips
 */

declare( strict_types=1 );

namespace AssetDrips\Index;

defined( 'ABSPATH' ) || exit;

/**
 * Pure SQL-builder seam for faceted media index queries.
 *
 * Converts filter criteria into a `(where_sql, where_args[])` pair that is
 * unit-testable without a database. All enum fields are validated against fixed
 * allow-lists before interpolation — this class is the single SQLi choke point
 * for the faceted query API (threat T-02-01). The search term is escaped via
 * {@see \wpdb::esc_like()} before wrapping in `%…%` (T-02-02).
 *
 * Usage:
 *   $q = new MediaQuery();
 *   $q->subtype = 'jpeg';
 *   $q->search  = 'sunset';
 *   [$where_sql, $where_args] = $q->build_where($wpdb);
 *   $order = $q->order_by_clause();
 *   // pass $where_sql, $where_args, and $order to MediaIndex::query().
 */
final class MediaQuery {

	// -------------------------------------------------------------------------
	// Whitelists (T-02-01 — never interpolate raw input).
	// -------------------------------------------------------------------------

	/**
	 * Allowed ORDER BY column names.
	 */
	private const ALLOWED_ORDERBY = array( 'uploaded_at', 'filesize', 'filename', 'title' );

	/**
	 * Allowed ORDER direction tokens.
	 */
	private const ALLOWED_ORDER = array( 'asc', 'desc' );

	/**
	 * Allowed orientation values.
	 */
	private const ALLOWED_ORIENT = array( 'landscape', 'portrait', 'square' );

	/**
	 * Allowed main MIME type tokens. Maps to `mime LIKE 'type/%'` predicate.
	 */
	private const ALLOWED_TYPE = array( 'image', 'video', 'audio', 'application', 'text' );

	// -------------------------------------------------------------------------
	// Filter criteria (all optional; zero/'' = no filter applied).
	// -------------------------------------------------------------------------

	/**
	 * Substring search across filename, title, alt, caption, description.
	 *
	 * @var string
	 */
	public string $search = '';

	/**
	 * Main MIME type filter (e.g. 'image'). Whitelisted against ALLOWED_TYPE.
	 *
	 * @var string
	 */
	public string $type = '';

	/**
	 * MIME subtype filter (e.g. 'jpeg'). Values are bound via prepared %s placeholder.
	 *
	 * @var string
	 */
	public string $subtype = '';

	/**
	 * Orientation filter. Whitelisted against ALLOWED_ORIENT.
	 *
	 * @var string
	 */
	public string $orientation = '';

	/**
	 * Minimum file size in bytes (0 = no lower bound).
	 *
	 * @var int
	 */
	public int $size_min = 0;

	/**
	 * Maximum file size in bytes (0 = no upper bound).
	 *
	 * @var int
	 */
	public int $size_max = 0;

	/**
	 * Minimum image width in pixels (0 = no lower bound).
	 *
	 * @var int
	 */
	public int $width_min = 0;

	/**
	 * Maximum image width in pixels (0 = no upper bound).
	 *
	 * @var int
	 */
	public int $width_max = 0;

	/**
	 * Minimum image height in pixels (0 = no lower bound).
	 *
	 * @var int
	 */
	public int $height_min = 0;

	/**
	 * Maximum image height in pixels (0 = no upper bound).
	 *
	 * @var int
	 */
	public int $height_max = 0;

	/**
	 * Usage filter: '' = any, 'used' = is_used=1, 'unused' = is_used=0.
	 *
	 * @var string
	 */
	public string $used = '';

	/**
	 * When true, filters to rows where has_alt = 0 (missing alt text).
	 *
	 * @var bool
	 */
	public bool $missing_alt = false;

	/**
	 * Folder filter: '' = any, 'uncategorized' = folder_id IS NULL,
	 * positive integer string = folder_id = %d (D-02).
	 *
	 * @var string
	 */
	public string $folder = '';

	/**
	 * Uploader user ID (0 = any uploader).
	 *
	 * @var int
	 */
	public int $uploader = 0;

	/**
	 * Date-from filter (Y-m-d, '' = no lower bound).
	 *
	 * @var string
	 */
	public string $date_from = '';

	/**
	 * Date-to filter (Y-m-d, '' = no upper bound).
	 *
	 * @var string
	 */
	public string $date_to = '';

	/**
	 * "Used on" host post ID. 0 = no filter.
	 * The actual JOIN is wired in MediaIndex::query() against usage_locations.
	 *
	 * @var int
	 */
	public int $used_on = 0;

	/**
	 * Tag filter: 0 = any, positive integer = that tag's term_id.
	 * The actual IN-subquery predicate is wired in MediaIndex::query() (like used_on),
	 * not in build_where() — tag membership lives in wp_term_relationships, not
	 * in the assetdrips_media table.
	 *
	 * @var int
	 */
	public int $tag = 0;

	/**
	 * Squeeze state filter. '' = any, values: 'not-optimized' | 'oversized' |
	 * 'missing-webp' | 'has-backup'.
	 *
	 * The actual WHERE predicate is built in MediaIndex::build_full_where() via
	 * subqueries against assetdrips_squeeze / assetdrips_squeeze_backups. Not
	 * included in build_where() because it spans multiple tables.
	 *
	 * @var string
	 */
	public string $squeeze_state = '';

	/**
	 * ORDER BY column. Whitelisted against ALLOWED_ORDERBY; fallback = 'uploaded_at'.
	 *
	 * @var string
	 */
	public string $orderby = 'uploaded_at';

	/**
	 * Sort direction ('asc'|'desc'). Fallback = 'desc'.
	 *
	 * @var string
	 */
	public string $order = 'desc';

	/**
	 * Results page number (1-based).
	 *
	 * @var int
	 */
	public int $page = 1;

	/**
	 * Rows per page.
	 *
	 * @var int
	 */
	public int $per_page = 40;

	// -------------------------------------------------------------------------
	// Public API
	// -------------------------------------------------------------------------

	/**
	 * Build a prepared WHERE clause from the current criteria.
	 *
	 * Returns a two-element array:
	 *   [0] string  — the WHERE fragment (without "WHERE "), e.g. `mime_subtype = %s AND is_used = %d`
	 *                 An empty string when no criteria are active (caller should omit WHERE entirely).
	 *   [1] array   — the positional placeholder values, ready for wpdb::prepare().
	 *
	 * Security contracts (T-02-01, T-02-02):
	 * - All scalar values appear as %s / %d placeholders, NEVER interpolated.
	 * - Enum fields (orientation, type) are whitelist-checked; invalid values are dropped silently.
	 * - The search term is escaped via {@see \wpdb::esc_like()} before being wrapped in %…%.
	 * - The caller (MediaIndex) must pass the returned args to wpdb::prepare() together with the SQL.
	 *
	 * @param object $wpdb An object exposing esc_like( string ): string. In production
	 *                     this is the global \wpdb instance; in tests a lightweight stub suffices.
	 * @return array{0: string, 1: array<int, mixed>} [where_sql, where_args].
	 */
	public function build_where( object $wpdb ): array {
		$clauses = array();
		$args    = array();

		// ---- type (main mime prefix, whitelisted) ----------------------------
		if ( '' !== $this->type ) {
			if ( in_array( $this->type, self::ALLOWED_TYPE, true ) ) {
				$clauses[] = 'mime LIKE %s';
				$args[]    = $this->type . '/%';
			}
			// Invalid type: silently dropped (T-02-01).
		}

		// ---- subtype (bound via %s placeholder, no whitelist needed) ---------
		if ( '' !== $this->subtype ) {
			$clauses[] = 'mime_subtype = %s';
			$args[]    = $this->subtype;
		}

		// ---- orientation (whitelisted) ---------------------------------------
		if ( '' !== $this->orientation ) {
			if ( in_array( $this->orientation, self::ALLOWED_ORIENT, true ) ) {
				$clauses[] = 'orientation = %s';
				$args[]    = $this->orientation;
			}
			// Invalid orientation: silently dropped (T-02-01).
		}

		// ---- size range -------------------------------------------------------
		if ( $this->size_min > 0 ) {
			$clauses[] = 'filesize >= %d';
			$args[]    = $this->size_min;
		}
		if ( $this->size_max > 0 ) {
			$clauses[] = 'filesize <= %d';
			$args[]    = $this->size_max;
		}

		// ---- dimension ranges ------------------------------------------------
		if ( $this->width_min > 0 ) {
			$clauses[] = 'width >= %d';
			$args[]    = $this->width_min;
		}
		if ( $this->width_max > 0 ) {
			$clauses[] = 'width <= %d';
			$args[]    = $this->width_max;
		}
		if ( $this->height_min > 0 ) {
			$clauses[] = 'height >= %d';
			$args[]    = $this->height_min;
		}
		if ( $this->height_max > 0 ) {
			$clauses[] = 'height <= %d';
			$args[]    = $this->height_max;
		}

		// ---- used / is_used --------------------------------------------------
		if ( 'unused' === $this->used ) {
			$clauses[] = 'is_used = %d';
			$args[]    = 0;
		} elseif ( 'used' === $this->used ) {
			$clauses[] = 'is_used = %d';
			$args[]    = 1;
		}

		// ---- missing_alt / has_alt -------------------------------------------
		if ( $this->missing_alt ) {
			$clauses[] = 'has_alt = %d';
			$args[]    = 0;
		}

		// ---- folder / folder_id (D-02) ----------------------------------------
		if ( 'uncategorized' === $this->folder ) {
			// Uncategorized sentinel: folder_id IS NULL takes no placeholder.
			$clauses[] = 'folder_id IS NULL';
		} elseif ( '' !== $this->folder ) {
			$folder_int = (int) $this->folder;
			if ( $folder_int > 0 ) {
				$clauses[] = 'folder_id = %d';
				$args[]    = $folder_int;
			}
			// Non-positive or non-numeric: silently dropped (T-02-01).
		}

		// ---- uploader / uploaded_by ------------------------------------------
		if ( $this->uploader > 0 ) {
			$clauses[] = 'uploaded_by = %d';
			$args[]    = $this->uploader;
		}

		// ---- date range ------------------------------------------------------
		if ( '' !== $this->date_from ) {
			$clauses[] = 'uploaded_at >= %s';
			$args[]    = $this->date_from;
		}
		if ( '' !== $this->date_to ) {
			$clauses[] = 'uploaded_at <= %s';
			// A bare Y-m-d compares as midnight, silently excluding same-day uploads
			// after 00:00:00 (WR-01). Extend to end-of-day so the range is inclusive.
			$args[] = preg_match( '/^\d{4}-\d{2}-\d{2}$/', $this->date_to )
				? $this->date_to . ' 23:59:59'
				: $this->date_to;
		}

		// ---- search (T-02-02: esc_like before wrapping) ----------------------
		if ( '' !== $this->search ) {
			$safe_term    = $wpdb->esc_like( $this->search );
			$like_value   = '%' . $safe_term . '%';
			$search_parts = array();
			foreach ( array( 'filename', 'title', 'alt', 'caption', 'description' ) as $col ) {
				$search_parts[] = $col . ' LIKE %s';
				$args[]         = $like_value;
			}
			// Parenthesise the OR-group so it AND-joins with the facet predicates.
			$clauses[] = '(' . implode( ' OR ', $search_parts ) . ')';
		}

		return array(
			implode( ' AND ', $clauses ),
			$args,
		);
	}

	/**
	 * Return a safe `column DIR` ORDER BY string.
	 *
	 * The column is validated against {@see self::ALLOWED_ORDERBY}; any invalid
	 * value falls back to `uploaded_at`. The direction is validated against
	 * {@see self::ALLOWED_ORDER}; any invalid value falls back to `DESC`.
	 * Neither raw input is ever interpolated (T-02-01).
	 *
	 * @return string e.g. 'uploaded_at DESC', 'filesize ASC'.
	 */
	public function order_by_clause(): string {
		$orderby = in_array( $this->orderby, self::ALLOWED_ORDERBY, true )
			? $this->orderby
			: 'uploaded_at';

		$order = in_array( strtolower( $this->order ), self::ALLOWED_ORDER, true )
			? strtoupper( $this->order )
			: 'DESC';

		return $orderby . ' ' . $order;
	}
}
