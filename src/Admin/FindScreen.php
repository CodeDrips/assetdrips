<?php
/**
 * Admin Find screen — faceted media search.
 *
 * @package AssetDrips
 */

declare( strict_types=1 );

namespace AssetDrips\Admin;

use AssetDrips\Db\Schema;
use AssetDrips\Index\MediaIndex;
use AssetDrips\Index\MediaQuery;
use AssetDrips\Usage\UsageLocations;

defined( 'ABSPATH' ) || exit;

/**
 * The AssetDrips Find admin screen.
 *
 * A GET-driven filter bar maps to the published query-arg contract, runs
 * MediaIndex::query(), and renders a brand-styled result grid with classic
 * LIMIT/OFFSET pagination and a total count. An AJAX endpoint serves paged
 * results with a no-JS fallback. The ?used_on={post_id} view shows exactly
 * the media used on a given post via UsageLocations::for_host().
 */
final class FindScreen {

	/**
	 * Page slug. Public so other screens can deep-link into Find.
	 */
	public const SLUG = 'assetdrips-find';

	/**
	 * Default result page size.
	 */
	public const PER_PAGE = 40;

	/**
	 * Capability required to view and act.
	 */
	private const CAP = 'manage_options';

	/**
	 * Nonce action for AJAX requests.
	 */
	private const AJAX_NONCE = 'assetdrips_ajax';

	/**
	 * User-meta key for saved views.
	 */
	private const VIEWS_META_KEY = 'assetdrips_find_views';

	/**
	 * Maximum number of saved views per user.
	 */
	private const MAX_VIEWS = 20;

	/**
	 * Allowed orderby values (mirrors MediaQuery::ALLOWED_ORDERBY).
	 *
	 * @var array<string>
	 */
	private const ALLOWED_ORDERBY = array( 'uploaded_at', 'filesize', 'filename', 'title' );

	/**
	 * Allowed order direction values.
	 *
	 * @var array<string>
	 */
	private const ALLOWED_ORDER = array( 'asc', 'desc' );

	/**
	 * Allowed usage filter values.
	 *
	 * @var array<string>
	 */
	private const ALLOWED_USED = array( 'used', 'unused' );

	/**
	 * Allowed main MIME type tokens.
	 *
	 * @var array<string>
	 */
	private const ALLOWED_TYPE = array( 'image', 'video', 'audio', 'application', 'text' );

	/**
	 * Allowed orientation values.
	 *
	 * @var array<string>
	 */
	private const ALLOWED_ORIENT = array( 'landscape', 'portrait', 'square' );

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'admin_menu', array( $this, 'add_menu' ) );
		add_action( 'wp_ajax_assetdrips_find_results', array( $this, 'ajax_find_results' ) );
		add_action( 'admin_post_assetdrips_find_save_view', array( $this, 'handle_save_view' ) );
		add_action( 'admin_post_assetdrips_find_delete_view', array( $this, 'handle_delete_view' ) );
	}

	/**
	 * Add the Find submenu under the AssetDrips top-level menu.
	 *
	 * @return void
	 */
	public function add_menu(): void {
		add_submenu_page(
			Dashboard::SLUG,
			'Find — Media Search',
			'Find',
			self::CAP,
			self::SLUG,
			array( $this, 'render' )
		);
	}

	/**
	 * Render the Find screen.
	 *
	 * @return void
	 */
	public function render(): void {
		if ( ! current_user_can( self::CAP ) ) {
			return;
		}

		// Read every GET filter param per the §6 contract.
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only display filter; no state change.
		$s           = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';
		$type        = isset( $_GET['type'] ) ? sanitize_text_field( wp_unslash( $_GET['type'] ) ) : '';
		$subtype     = isset( $_GET['subtype'] ) ? sanitize_text_field( wp_unslash( $_GET['subtype'] ) ) : '';
		$orientation = isset( $_GET['orientation'] ) ? sanitize_text_field( wp_unslash( $_GET['orientation'] ) ) : '';
		$used        = isset( $_GET['used'] ) ? sanitize_text_field( wp_unslash( $_GET['used'] ) ) : '';
		$folder      = isset( $_GET['folder'] ) ? sanitize_text_field( wp_unslash( $_GET['folder'] ) ) : '';
		$tag         = isset( $_GET['tag'] ) ? absint( wp_unslash( $_GET['tag'] ) ) : 0;
		$missing_alt = isset( $_GET['missing_alt'] ) ? absint( wp_unslash( $_GET['missing_alt'] ) ) : 0;
		$size_min    = isset( $_GET['size_min'] ) ? absint( wp_unslash( $_GET['size_min'] ) ) : 0;
		$size_max    = isset( $_GET['size_max'] ) ? absint( wp_unslash( $_GET['size_max'] ) ) : 0;
		$w_min       = isset( $_GET['w_min'] ) ? absint( wp_unslash( $_GET['w_min'] ) ) : 0;
		$w_max       = isset( $_GET['w_max'] ) ? absint( wp_unslash( $_GET['w_max'] ) ) : 0;
		$h_min       = isset( $_GET['h_min'] ) ? absint( wp_unslash( $_GET['h_min'] ) ) : 0;
		$h_max       = isset( $_GET['h_max'] ) ? absint( wp_unslash( $_GET['h_max'] ) ) : 0;
		$uploader    = isset( $_GET['uploader'] ) ? absint( wp_unslash( $_GET['uploader'] ) ) : 0;
		$date_from   = isset( $_GET['date_from'] ) ? sanitize_text_field( wp_unslash( $_GET['date_from'] ) ) : '';
		$date_to     = isset( $_GET['date_to'] ) ? sanitize_text_field( wp_unslash( $_GET['date_to'] ) ) : '';
		$used_on     = isset( $_GET['used_on'] ) ? absint( wp_unslash( $_GET['used_on'] ) ) : 0;
		$orderby     = isset( $_GET['orderby'] ) ? sanitize_text_field( wp_unslash( $_GET['orderby'] ) ) : 'uploaded_at';
		$order       = isset( $_GET['order'] ) ? sanitize_text_field( wp_unslash( $_GET['order'] ) ) : 'desc';
		$paged       = isset( $_GET['paged'] ) ? max( 1, absint( wp_unslash( $_GET['paged'] ) ) ) : 1;
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		$squeeze_state = isset( $_GET['squeeze_state'] ) ? sanitize_key( wp_unslash( $_GET['squeeze_state'] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
		// Whitelist — T-14-05-01: silent drop for unknown values.
		if ( ! in_array( $squeeze_state, array( 'not-optimized', 'oversized', 'missing-webp', 'has-backup' ), true ) ) {
			$squeeze_state = '';
		}

		// Whitelist-check enum values.
		if ( ! in_array( $type, self::ALLOWED_TYPE, true ) ) {
			$type = '';
		}
		if ( ! in_array( $orientation, self::ALLOWED_ORIENT, true ) ) {
			$orientation = '';
		}
		if ( ! in_array( $used, self::ALLOWED_USED, true ) ) {
			$used = '';
		}
		// Whitelist folder to: empty string, 'uncategorized', or positive integer string.
		if ( '' !== $folder && 'uncategorized' !== $folder ) {
			$folder_int = absint( $folder );
			$folder     = $folder_int > 0 ? (string) $folder_int : '';
		}
		if ( ! in_array( $orderby, self::ALLOWED_ORDERBY, true ) ) {
			$orderby = 'uploaded_at';
		}
		if ( ! in_array( strtolower( $order ), self::ALLOWED_ORDER, true ) ) {
			$order = 'desc';
		}

		// Validate date format (Y-m-d).
		if ( '' !== $date_from && ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date_from ) ) {
			$date_from = '';
		}
		if ( '' !== $date_to && ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date_to ) ) {
			$date_to = '';
		}

		// Build active filter args for pagination link preservation.
		$filter_args = array_filter(
			array(
				'page'        => self::SLUG,
				's'           => $s,
				'type'        => $type,
				'subtype'     => $subtype,
				'orientation' => $orientation,
				'used'        => $used,
				'folder'      => $folder,
				'tag'         => $tag > 0 ? (string) $tag : '',
				'missing_alt' => $missing_alt ? '1' : '',
				'size_min'    => $size_min > 0 ? (string) $size_min : '',
				'size_max'    => $size_max > 0 ? (string) $size_max : '',
				'w_min'       => $w_min > 0 ? (string) $w_min : '',
				'w_max'       => $w_max > 0 ? (string) $w_max : '',
				'h_min'       => $h_min > 0 ? (string) $h_min : '',
				'h_max'       => $h_max > 0 ? (string) $h_max : '',
				'uploader'    => $uploader > 0 ? (string) $uploader : '',
				'date_from'   => $date_from,
				'date_to'     => $date_to,
				'used_on'     => $used_on > 0 ? (string) $used_on : '',
				'orderby'       => $orderby,
				'order'         => $order,
				'squeeze_state' => $squeeze_state,
			),
			static function ( string $v ): bool {
				return '' !== $v;
			}
		);

		// Handle the ?used_on view specially.
		if ( $used_on > 0 ) {
			$this->render_used_on_view( $used_on );
			return;
		}

		// Build and run the query.
		$q              = new MediaQuery();
		$q->search      = $s;
		$q->type        = $type;
		$q->subtype     = $subtype;
		$q->orientation = $orientation;
		$q->used        = $used;
		$q->folder      = $folder;
		$q->tag         = $tag;
		$q->missing_alt = (bool) $missing_alt;
		$q->size_min    = $size_min;
		$q->size_max    = $size_max;
		$q->width_min   = $w_min;
		$q->width_max   = $w_max;
		$q->height_min  = $h_min;
		$q->height_max  = $h_max;
		$q->uploader      = $uploader;
		$q->date_from     = $date_from;
		$q->date_to       = $date_to;
		$q->orderby       = $orderby;
		$q->order         = $order;
		$q->squeeze_state = $squeeze_state;
		$q->page          = $paged;
		$q->per_page      = self::PER_PAGE;

		$result      = MediaIndex::from_wordpress()->query( $q );
		$rows        = (array) $result['rows'];
		$total       = (int) $result['total'];
		$total_pages = max( 1, (int) ceil( $total / self::PER_PAGE ) );

		// Determine staleness for used/unused filter.
		$staleness_html = '';
		if ( '' !== $used ) {
			$staleness_html = $this->build_staleness_html( $rows );
		}

		// Determine if any filter is active (beyond defaults).
		$is_filtered = '' !== $s || '' !== $type || '' !== $subtype || '' !== $orientation
			|| '' !== $used || '' !== $folder || $tag > 0 || $missing_alt > 0 || $size_min > 0 || $size_max > 0
			|| $w_min > 0 || $w_max > 0 || $h_min > 0 || $h_max > 0
			|| $uploader > 0 || '' !== $date_from || '' !== $date_to || '' !== $squeeze_state;

		echo '<div class="wrap assetdrips-wrap">';
		$this->print_styles();
		$this->print_header();
		$this->print_notice();

		$this->print_saved_views_bar( $filter_args );
		$this->print_filter_form( $s, $type, $subtype, $orientation, $used, $folder, $tag, $missing_alt, $size_min, $size_max, $w_min, $w_max, $h_min, $h_max, $uploader, $date_from, $date_to, $orderby, $order, $squeeze_state );

		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Staleness HTML is built internally with proper escaping.
		echo $staleness_html;

		// Bulk bar + panel (Plan 04): placed below filter form, above toolbar/grid.
		$this->print_bulk_bar();
		$this->print_bulk_panel();

		$this->print_toolbar( $total, $is_filtered, $s );
		$this->print_results_grid( $rows, $total, $is_filtered );
		$this->print_pagination( $paged, $total_pages, $filter_args );
		$this->print_find_script();
		echo '</div>';
	}

	/**
	 * Render the "Used on …" view for a given host post.
	 *
	 * @param int $post_id Host post ID.
	 * @return void
	 */
	private function render_used_on_view( int $post_id ): void {
		$post_title = get_the_title( $post_id );
		if ( '' === $post_title ) {
			$post_title = sprintf( 'Post #%d (not found)', $post_id );
		}

		$attachment_ids = UsageLocations::from_wordpress()->for_host( 'post', $post_id );

		echo '<div class="wrap assetdrips-wrap">';
		$this->print_styles();

		// Used-on breadcrumb.
		$home = add_query_arg( 'page', Dashboard::SLUG, admin_url( 'admin.php' ) );
		$find = add_query_arg( 'page', self::SLUG, admin_url( 'admin.php' ) );
		echo '<a class="ad-crumb" href="' . esc_url( $home ) . '">&larr; AssetDrips &middot; Find</a>';

		echo '<div class="assetdrips-head"><h1>Find</h1>';
		echo '<span class="ad-mod" style="background:#080808;">';
		echo 'Used on: ' . esc_html( mb_strimwidth( $post_title, 0, 48, '…' ) );
		echo '</span></div>';

		echo '<p class="assetdrips-tag">Showing ' . esc_html( (string) count( $attachment_ids ) )
			. ' media file' . ( 1 === count( $attachment_ids ) ? '' : 's' )
			. ' used on &ldquo;' . esc_html( $post_title ) . '&rdquo;.</p>';

		$this->print_notice();

		// Back-link + view-post link.
		$permalink = get_permalink( $post_id );
		echo '<div style="margin:0 0 16px;display:flex;gap:16px;align-items:center;">';
		echo '<a class="ad-crumb" href="' . esc_url( $find ) . '">&larr; Back to all media</a>';
		if ( $permalink ) {
			echo '<a href="' . esc_url( (string) $permalink ) . '" target="_blank" rel="noopener" style="font-size:12px;color:#777;">View post &rarr;</a>';
		}
		echo '</div>';

		if ( empty( $attachment_ids ) ) {
			$sift_url = add_query_arg( 'page', 'assetdrips-sift', admin_url( 'admin.php' ) );
			echo '<div class="ad-empty ad-find-empty" style="border-left:4px solid var(--ad-orange);">';
			echo '<p>' . esc_html( 'No media recorded on "' . $post_title . '".' ) . '</p>';
			echo '<p>This view requires a completed Sift scan.</p>';
			echo '<a href="' . esc_url( $sift_url ) . '">Run a scan &rarr;</a>';
			echo '</div>';
			echo '</div>';
			return;
		}

		// Render thumbnails grid for found attachment IDs.
		echo '<div class="ad-find-grid">';
		foreach ( $attachment_ids as $attachment_id ) {
			$row = $this->get_row_for_attachment( $attachment_id );
			$this->print_card( $row );
		}
		echo '</div>';

		echo '</div>';
	}

	/**
	 * Build a minimal row array for a single attachment ID.
	 *
	 * @param int $attachment_id Attachment post ID.
	 * @return array<string, mixed>
	 */
	private function get_row_for_attachment( int $attachment_id ): array {
		global $wpdb;
		$table = Schema::media_table();

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Single row lookup by attachment_id; table name is a Schema constant (never input) and value is bound via prepare().
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT attachment_id, filename, title, mime, mime_subtype, width, height, filesize, has_alt, is_used, usage_count, usage_synced_at FROM {$table} WHERE attachment_id = %d",
				$attachment_id
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		if ( null === $row ) {
			return array(
				'attachment_id'   => $attachment_id,
				'filename'        => '(not indexed)',
				'title'           => '',
				'mime'            => '',
				'mime_subtype'    => '',
				'width'           => 0,
				'height'          => 0,
				'filesize'        => 0,
				'has_alt'         => 0,
				'is_used'         => null,
				'usage_count'     => null,
				'usage_synced_at' => null,
			);
		}

		return (array) $row;
	}

	/**
	 * AJAX handler: return paged results as HTML + total.
	 *
	 * Security (T-02-03, T-02-04): capability check first, then nonce.
	 *
	 * @return void
	 */
	public function ajax_find_results(): void {
		if ( ! current_user_can( self::CAP ) ) {
			wp_send_json_error( array( 'message' => 'Insufficient permissions.' ), 403 );
		}
		check_ajax_referer( self::AJAX_NONCE, 'nonce' );

		// Read filter params from $_POST (same contract as GET).
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- Nonce verified via check_ajax_referer() above.
		$s           = isset( $_POST['s'] ) ? sanitize_text_field( wp_unslash( $_POST['s'] ) ) : '';
		$type        = isset( $_POST['type'] ) ? sanitize_text_field( wp_unslash( $_POST['type'] ) ) : '';
		$subtype     = isset( $_POST['subtype'] ) ? sanitize_text_field( wp_unslash( $_POST['subtype'] ) ) : '';
		$orientation = isset( $_POST['orientation'] ) ? sanitize_text_field( wp_unslash( $_POST['orientation'] ) ) : '';
		$used        = isset( $_POST['used'] ) ? sanitize_text_field( wp_unslash( $_POST['used'] ) ) : '';
		$folder      = isset( $_POST['folder'] ) ? sanitize_text_field( wp_unslash( $_POST['folder'] ) ) : '';
		$tag         = isset( $_POST['tag'] ) ? absint( wp_unslash( $_POST['tag'] ) ) : 0;
		$missing_alt = isset( $_POST['missing_alt'] ) ? absint( wp_unslash( $_POST['missing_alt'] ) ) : 0;
		$size_min    = isset( $_POST['size_min'] ) ? absint( wp_unslash( $_POST['size_min'] ) ) : 0;
		$size_max    = isset( $_POST['size_max'] ) ? absint( wp_unslash( $_POST['size_max'] ) ) : 0;
		$w_min       = isset( $_POST['w_min'] ) ? absint( wp_unslash( $_POST['w_min'] ) ) : 0;
		$w_max       = isset( $_POST['w_max'] ) ? absint( wp_unslash( $_POST['w_max'] ) ) : 0;
		$h_min       = isset( $_POST['h_min'] ) ? absint( wp_unslash( $_POST['h_min'] ) ) : 0;
		$h_max       = isset( $_POST['h_max'] ) ? absint( wp_unslash( $_POST['h_max'] ) ) : 0;
		$uploader    = isset( $_POST['uploader'] ) ? absint( wp_unslash( $_POST['uploader'] ) ) : 0;
		$date_from   = isset( $_POST['date_from'] ) ? sanitize_text_field( wp_unslash( $_POST['date_from'] ) ) : '';
		$date_to     = isset( $_POST['date_to'] ) ? sanitize_text_field( wp_unslash( $_POST['date_to'] ) ) : '';
		$orderby     = isset( $_POST['orderby'] ) ? sanitize_text_field( wp_unslash( $_POST['orderby'] ) ) : 'uploaded_at';
		$order       = isset( $_POST['order'] ) ? sanitize_text_field( wp_unslash( $_POST['order'] ) ) : 'desc';
		$paged         = isset( $_POST['paged'] ) ? max( 1, absint( wp_unslash( $_POST['paged'] ) ) ) : 1;
		// phpcs:disable WordPress.Security.NonceVerification.Missing
		$squeeze_state = isset( $_POST['squeeze_state'] ) ? sanitize_key( wp_unslash( $_POST['squeeze_state'] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Missing
		// Whitelist — T-14-05-01: silent drop for unknown values.
		if ( ! in_array( $squeeze_state, array( 'not-optimized', 'oversized', 'missing-webp', 'has-backup' ), true ) ) {
			$squeeze_state = '';
		}

		// Whitelist enum values.
		if ( ! in_array( $type, self::ALLOWED_TYPE, true ) ) {
			$type = '';
		}
		if ( ! in_array( $orientation, self::ALLOWED_ORIENT, true ) ) {
			$orientation = '';
		}
		if ( ! in_array( $used, self::ALLOWED_USED, true ) ) {
			$used = '';
		}
		// Whitelist folder to: empty string, 'uncategorized', or positive integer string.
		if ( '' !== $folder && 'uncategorized' !== $folder ) {
			$folder_int = absint( $folder );
			$folder     = $folder_int > 0 ? (string) $folder_int : '';
		}
		if ( ! in_array( $orderby, self::ALLOWED_ORDERBY, true ) ) {
			$orderby = 'uploaded_at';
		}
		if ( ! in_array( strtolower( $order ), self::ALLOWED_ORDER, true ) ) {
			$order = 'desc';
		}
		if ( '' !== $date_from && ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date_from ) ) {
			$date_from = '';
		}
		if ( '' !== $date_to && ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date_to ) ) {
			$date_to = '';
		}

		$q              = new MediaQuery();
		$q->search      = $s;
		$q->type        = $type;
		$q->subtype     = $subtype;
		$q->orientation = $orientation;
		$q->used        = $used;
		$q->folder      = $folder;
		$q->tag         = $tag;
		$q->missing_alt = (bool) $missing_alt;
		$q->size_min    = $size_min;
		$q->size_max    = $size_max;
		$q->width_min   = $w_min;
		$q->width_max   = $w_max;
		$q->height_min  = $h_min;
		$q->height_max  = $h_max;
		$q->uploader      = $uploader;
		$q->date_from     = $date_from;
		$q->date_to       = $date_to;
		$q->orderby       = $orderby;
		$q->order         = $order;
		$q->squeeze_state = $squeeze_state;
		$q->page          = $paged;
		$q->per_page      = self::PER_PAGE;

		$result      = MediaIndex::from_wordpress()->query( $q );
		$rows        = (array) $result['rows'];
		$total       = (int) $result['total'];
		$total_pages = max( 1, (int) ceil( $total / self::PER_PAGE ) );

		// Include squeeze_state in $is_filtered so toolbar count and select-all-matching
		// affordance render correctly when only a squeeze_state filter is active (CR-04).
		$is_filtered = '' !== $s || '' !== $type || '' !== $subtype || '' !== $orientation
			|| '' !== $used || '' !== $folder || $tag > 0 || $missing_alt > 0 || $size_min > 0 || $size_max > 0
			|| $w_min > 0 || $w_max > 0 || $h_min > 0 || $h_max > 0
			|| $uploader > 0 || '' !== $date_from || '' !== $date_to || '' !== $squeeze_state;

		$filter_args = array_filter(
			array(
				'page'          => self::SLUG,
				's'             => $s,
				'type'          => $type,
				'subtype'       => $subtype,
				'orientation'   => $orientation,
				'used'          => $used,
				'folder'        => $folder,
				'tag'           => $tag > 0 ? (string) $tag : '',
				'missing_alt'   => $missing_alt ? '1' : '',
				'size_min'      => $size_min > 0 ? (string) $size_min : '',
				'size_max'      => $size_max > 0 ? (string) $size_max : '',
				'w_min'         => $w_min > 0 ? (string) $w_min : '',
				'w_max'         => $w_max > 0 ? (string) $w_max : '',
				'h_min'         => $h_min > 0 ? (string) $h_min : '',
				'h_max'         => $h_max > 0 ? (string) $h_max : '',
				'uploader'      => $uploader > 0 ? (string) $uploader : '',
				'date_from'     => $date_from,
				'date_to'       => $date_to,
				'orderby'       => $orderby,
				'order'         => $order,
				'squeeze_state' => $squeeze_state,
			),
			static function ( string $v ): bool {
				return '' !== $v;
			}
		);

		ob_start();
		$this->print_results_grid( $rows, $total, $is_filtered );
		$grid_html = (string) ob_get_clean();

		ob_start();
		$this->print_pagination( $paged, $total_pages, $filter_args );
		$pagination_html = (string) ob_get_clean();

		wp_send_json_success(
			array(
				'html'       => $grid_html,
				'pagination' => $pagination_html,
				'total'      => $total,
			)
		);
	}

	/**
	 * Handle the "save view" admin-post action.
	 *
	 * Security: check_admin_referer() verifies the nonce (T-02-04);
	 * current_user_can() enforces the capability gate (T-02-06).
	 * Validation and user_meta write are delegated to save_view().
	 *
	 * @return void
	 */
	public function handle_save_view(): void {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( 'Insufficient permissions.' );
		}
		check_admin_referer( 'assetdrips_find_save_view' );

		$user_id = get_current_user_id();

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- Nonce verified via check_admin_referer() above.
		$raw_name = isset( $_POST['view_name'] ) ? sanitize_text_field( wp_unslash( $_POST['view_name'] ) ) : '';

		// Collect filter state from POST; sanitize_filter_state() in save_view() whitelists keys.
		$raw_filters = array();
		foreach ( array( 's', 'type', 'subtype', 'orientation', 'used', 'folder', 'tag', 'missing_alt', 'size_min', 'size_max', 'w_min', 'w_max', 'h_min', 'h_max', 'uploader', 'date_from', 'date_to', 'orderby', 'order', 'squeeze_state' ) as $key ) {
			if ( isset( $_POST[ $key ] ) && '' !== $_POST[ $key ] ) {
				$raw_filters[ $key ] = sanitize_text_field( wp_unslash( (string) $_POST[ $key ] ) );
			}
		}
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		$saved = $this->save_view( $user_id, $raw_name, $raw_filters );

		if ( ! $saved ) {
			$views = $this->get_saved_views( $user_id );
			if ( count( $views ) >= self::MAX_VIEWS ) {
				$notice      = rawurlencode( 'You have reached the 20 saved view limit. Delete one to save a new view.' );
				$notice_type = 'error';
			} else {
				$notice      = rawurlencode( 'View name cannot be empty.' );
				$notice_type = 'error';
			}
			wp_safe_redirect(
				add_query_arg(
					array(
						'page'                   => self::SLUG,
						'assetdrips_notice'      => $notice,
						'assetdrips_notice_type' => $notice_type,
					),
					admin_url( 'admin.php' )
				)
			);
			exit;
		}

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'              => self::SLUG,
					'assetdrips_notice' => rawurlencode( 'View saved.' ),
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	/**
	 * Handle the "delete view" admin-post action.
	 *
	 * Security: check_admin_referer() verifies the nonce (T-02-04);
	 * current_user_can() enforces the capability gate. The removal of
	 * user views is delegated to delete_view(); presets are never passed here.
	 *
	 * @return void
	 */
	public function handle_delete_view(): void {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( 'Insufficient permissions.' );
		}
		check_admin_referer( 'assetdrips_find_delete_view' );

		$user_id = get_current_user_id();

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified via check_admin_referer() above.
		$view_name = isset( $_POST['view_name'] ) ? sanitize_text_field( wp_unslash( $_POST['view_name'] ) ) : '';

		$this->delete_view( $user_id, $view_name );

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'              => self::SLUG,
					'assetdrips_notice' => rawurlencode( 'View removed.' ),
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	/**
	 * Build the staleness banner HTML for the current result set.
	 *
	 * Shown only when a used/unused filter is active. If all rows have null
	 * usage_synced_at: shows the "no scan yet" variant. Otherwise shows the
	 * oldest sync time to be maximally honest (T-02-07 / CON-two-lane-freshness).
	 *
	 * @param array<int, array<string, mixed>> $rows Query result rows.
	 * @return string HTML.
	 */
	private function build_staleness_html( array $rows ): string {
		if ( empty( $rows ) ) {
			return '';
		}

		$min_synced_at = null;
		$has_null      = false;
		$has_value     = false;

		foreach ( $rows as $row ) {
			$synced = $row['usage_synced_at'] ?? null;
			if ( null === $synced || '' === $synced ) {
				$has_null = true;
			} else {
				$has_value = true;
				$synced_ts = strtotime( (string) $synced );
				if ( false !== $synced_ts ) {
					if ( null === $min_synced_at || $synced_ts < $min_synced_at ) {
						$min_synced_at = $synced_ts;
					}
				}
			}
		}

		if ( $has_null && ! $has_value ) {
			// No rows have been synced.
			return '';
		}

		if ( $has_null && $has_value ) {
			// Mixed: some rows never synced.
			return '<div class="assetdrips-gap ad-staleness">'
				. '<span class="dashicons dashicons-clock" style="color:var(--ad-orange);vertical-align:middle;margin-right:6px;"></span>'
				. '<strong>Some files have never been scanned for usage.</strong>'
				. '</div>';
		}

		if ( null !== $min_synced_at ) {
			$diff     = human_time_diff( $min_synced_at, time() );
			$sift_url = esc_url( add_query_arg( 'page', 'assetdrips-sift', admin_url( 'admin.php' ) ) );
			return '<div class="assetdrips-gap ad-staleness">'
				. '<span class="dashicons dashicons-clock" style="color:var(--ad-orange);vertical-align:middle;margin-right:6px;"></span>'
				. esc_html( 'Usage data last synced ' . $diff . ' ago. Results reflect the most recent Sift scan.' )
				. ' <a href="' . $sift_url . '">Refresh usage data &rarr;</a>'
				. '</div>';
		}

		return '';
	}

	/**
	 * Return the built-in preset views (non-deletable).
	 *
	 * Preset filter states use the canonical §6 GET-contract param map so that
	 * applying a preset is identical to navigating with those query args.
	 * "Mine this month" is computed at call time — never a frozen date.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function presets(): array {
		$user_id    = get_current_user_id();
		$month_from = gmdate( 'Y-m-01' );

		return array(
			array(
				'name'    => 'Large unused images',
				'filters' => array(
					'type'     => 'image',
					'used'     => 'unused',
					'size_min' => '1048576',
					'orderby'  => 'filesize',
					'order'    => 'desc',
				),
			),
			array(
				'name'    => 'Missing alt',
				'filters' => array( 'missing_alt' => '1' ),
			),
			array(
				'name'    => 'Mine this month',
				'filters' => array(
					'uploader'  => (string) $user_id,
					'date_from' => $month_from,
				),
			),
		);
	}

	/**
	 * Sanitize a raw filter-state array against the §6 GET-contract whitelist.
	 *
	 * Unknown keys are dropped (T-02-06 tampering mitigation). Integer-typed
	 * keys are normalised with absint; string-typed keys are normalised with
	 * sanitize_text_field.
	 *
	 * @param array<string, mixed> $raw Raw filter-state array (e.g. from $_POST or user_meta).
	 * @return array<string, string> Sanitized filter-state array.
	 */
	public function sanitize_filter_state( array $raw ): array {
		// Integer-typed keys from the §6 contract.
		$int_keys = array( 'missing_alt', 'size_min', 'size_max', 'w_min', 'w_max', 'h_min', 'h_max', 'uploader' );

		// String-typed and enum keys from the §6 contract (excluding paged / used_on, which are transient).
		$string_keys = array( 's', 'type', 'subtype', 'orientation', 'used', 'folder', 'tag', 'date_from', 'date_to', 'orderby', 'order', 'squeeze_state' );

		$allowed_keys = array_merge( $int_keys, $string_keys );
		$sanitized    = array();

		foreach ( $allowed_keys as $key ) {
			if ( ! isset( $raw[ $key ] ) || '' === $raw[ $key ] ) {
				continue;
			}
			if ( in_array( $key, $int_keys, true ) ) {
				$sanitized[ $key ] = (string) absint( $raw[ $key ] );
			} else {
				$sanitized[ $key ] = sanitize_text_field( (string) $raw[ $key ] );
			}
		}

		// Enum guard: squeeze_state must be a known facet value (CR-02).
		// An invalid saved-view value must not silently reach build_media_query()
		// as an unknown string, which would fall into the default: branch and
		// drop the filter clause — potentially expanding a bulk op to the whole library.
		if ( isset( $sanitized['squeeze_state'] ) &&
			! in_array( $sanitized['squeeze_state'], array( 'not-optimized', 'oversized', 'missing-webp', 'has-backup' ), true ) ) {
			unset( $sanitized['squeeze_state'] );
		}

		return $sanitized;
	}

	/**
	 * Sanitize a view name: trim, sanitize, and cap at 60 characters.
	 *
	 * Returns an empty string when the sanitized result is empty (caller must
	 * reject an empty name).
	 *
	 * @param string $name Raw view name submitted by the user.
	 * @return string Sanitized view name (may be empty).
	 */
	public function sanitize_view_name( string $name ): string {
		$name = sanitize_text_field( $name );

		return mb_substr( $name, 0, 60 );
	}

	/**
	 * Persist a new saved view to the current user's user_meta.
	 *
	 * Sanitizes name and filter_state before storing. Returns false when the
	 * name is empty or the user has reached MAX_VIEWS.
	 *
	 * @param int                  $user_id User ID.
	 * @param string               $name    View name (will be sanitized).
	 * @param array<string, mixed> $filters Raw filter-state array.
	 * @return bool True on success, false when validation fails or cap reached.
	 */
	public function save_view( int $user_id, string $name, array $filters ): bool {
		$name = $this->sanitize_view_name( $name );
		if ( '' === $name ) {
			return false;
		}

		$views = $this->get_saved_views( $user_id );
		if ( count( $views ) >= self::MAX_VIEWS ) {
			return false;
		}

		$views[] = array(
			'name'    => $name,
			'filters' => $this->sanitize_filter_state( $filters ),
		);

		update_user_meta( $user_id, self::VIEWS_META_KEY, $views );

		return true;
	}

	/**
	 * Remove a saved view by name from the current user's user_meta.
	 *
	 * Presets are never removed here (callers must never pass a preset name).
	 *
	 * @param int    $user_id   User ID.
	 * @param string $view_name Name of the view to remove.
	 * @return void
	 */
	public function delete_view( int $user_id, string $view_name ): void {
		$views = $this->get_saved_views( $user_id );

		$views = array_values(
			array_filter(
				$views,
				static function ( array $v ) use ( $view_name ): bool {
					return $v['name'] !== $view_name;
				}
			)
		);

		update_user_meta( $user_id, self::VIEWS_META_KEY, $views );
	}

	/**
	 * Get saved views for a user.
	 *
	 * @param int $user_id User ID.
	 * @return array<int, array<string, mixed>>
	 */
	public function get_saved_views( int $user_id ): array {
		$raw = get_user_meta( $user_id, self::VIEWS_META_KEY, true );

		return is_array( $raw ) ? $raw : array();
	}

	/**
	 * Return the built-in preset views using the private alias (for internal render methods).
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function preset_views(): array {
		return $this->presets();
	}

	/**
	 * Print the branded page header.
	 *
	 * @return void
	 */
	private function print_header(): void {
		$home = add_query_arg( 'page', Dashboard::SLUG, admin_url( 'admin.php' ) );
		echo '<a class="ad-crumb" href="' . esc_url( $home ) . '">&larr; AssetDrips</a>';
		echo '<div class="assetdrips-head">';
		echo '<h1>Find</h1>';
		echo '<span class="ad-mod">Media Search</span>';
		echo '</div>';
		echo '<p class="assetdrips-tag">Instant search across your whole media library &mdash; by filename, alt text, type or where a file is used.</p>';
	}

	/**
	 * Print an action notice from the redirect, if present.
	 *
	 * @return void
	 */
	private function print_notice(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display of a post-redirect message; no state change.
		if ( ! isset( $_GET['assetdrips_notice'] ) ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display only.
		$message = sanitize_text_field( wp_unslash( $_GET['assetdrips_notice'] ) );
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display only.
		$type  = isset( $_GET['assetdrips_notice_type'] ) && 'error' === $_GET['assetdrips_notice_type'] ? 'error' : 'success';
		$class = 'error' === $type ? 'notice-error' : 'notice-success';

		printf(
			'<div class="notice %s is-dismissible"><p>%s</p></div>',
			esc_attr( $class ),
			esc_html( $message )
		);
	}

	/**
	 * Print the saved-views pill bar.
	 *
	 * @param array<string, string> $current_filter_args Current active filter state.
	 * @return void
	 */
	private function print_saved_views_bar( array $current_filter_args ): void {
		$presets   = $this->preset_views();
		$user_id   = get_current_user_id();
		$saved     = $this->get_saved_views( $user_id );
		$all_views = array_merge( $presets, $saved );

		if ( empty( $all_views ) ) {
			return;
		}

		$find_base = admin_url( 'admin.php' );

		echo '<div class="ad-saved-views">';
		echo '<span class="ad-saved-views-label">Saved views</span>';

		foreach ( $presets as $view ) {
			$filters   = $view['filters'];
			$url       = add_query_arg(
				array_merge( array( 'page' => self::SLUG ), $filters ),
				$find_base
			);
			$is_active = $this->is_view_active( (array) $filters, $current_filter_args );
			echo '<a href="' . esc_url( $url ) . '" class="ad-view-pill' . ( $is_active ? ' is-active' : '' ) . '">';
			echo esc_html( (string) $view['name'] );
			echo '</a>';
		}

		foreach ( $saved as $view ) {
			$filters   = $view['filters'];
			$url       = add_query_arg(
				array_merge( array( 'page' => self::SLUG ), $filters ),
				$find_base
			);
			$is_active = $this->is_view_active( (array) $filters, $current_filter_args );
			$view_name = (string) $view['name'];
			echo '<span class="ad-view-pill' . ( $is_active ? ' is-active' : '' ) . '" style="display:inline-flex;align-items:center;gap:4px;">';
			echo '<a href="' . esc_url( $url ) . '" style="text-decoration:none;color:inherit;">' . esc_html( $view_name ) . '</a>';
			// Delete form.
			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="display:inline;">';
			echo '<input type="hidden" name="action" value="assetdrips_find_delete_view" />';
			echo '<input type="hidden" name="view_name" value="' . esc_attr( $view_name ) . '" />';
			wp_nonce_field( 'assetdrips_find_delete_view' );
			echo '<button type="submit" class="ad-view-delete" aria-label="' . esc_attr( 'Remove ' . $view_name . ' saved view' ) . '">&times;</button>';
			echo '</form>';
			echo '</span>';
		}

		// Save-current-view affordance.
		echo '<a href="#" class="ad-save-view-trigger">+ Save this view</a>';
		echo '<div class="ad-save-view-form" style="display:none;">';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="display:inline-flex;gap:6px;align-items:center;">';
		echo '<input type="hidden" name="action" value="assetdrips_find_save_view" />';
		foreach ( $current_filter_args as $k => $v ) {
			if ( 'page' !== $k ) {
				echo '<input type="hidden" name="' . esc_attr( $k ) . '" value="' . esc_attr( (string) $v ) . '" />';
			}
		}
		wp_nonce_field( 'assetdrips_find_save_view' );
		echo '<input type="text" name="view_name" placeholder="Name this view&hellip;" class="regular-text" style="width:180px;" />';
		echo '<button type="submit" class="button">Save view</button>';
		echo '</form>';
		echo '</div>';

		echo '</div>';
	}

	/**
	 * Check whether a set of view filter args matches the current filter state.
	 *
	 * @param array<string, string> $view_filters View filter state.
	 * @param array<string, string> $current      Current active filter args.
	 * @return bool
	 */
	private function is_view_active( array $view_filters, array $current ): bool {
		foreach ( $view_filters as $k => $v ) {
			if ( ( $current[ $k ] ?? '' ) !== $v ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Print the GET filter form.
	 *
	 * @param string $s           Search term.
	 * @param string $type        Main MIME type.
	 * @param string $subtype     MIME subtype.
	 * @param string $orientation Orientation.
	 * @param string $used        Used/unused.
	 * @param string $folder      Folder filter: '' | 'uncategorized' | positive-int-string.
	 * @param int    $tag         Tag filter: 0 = any, positive integer = that tag's term_id.
	 * @param int    $missing_alt Missing alt flag.
	 * @param int    $size_min    Min file size (bytes).
	 * @param int    $size_max    Max file size (bytes).
	 * @param int    $w_min       Min width.
	 * @param int    $w_max       Max width.
	 * @param int    $h_min       Min height.
	 * @param int    $h_max       Max height.
	 * @param int    $uploader    Uploader user ID.
	 * @param string $date_from   Date from (Y-m-d).
	 * @param string $date_to     Date to (Y-m-d).
	 * @param string $orderby       Order by column.
	 * @param string $order         Order direction.
	 * @param string $squeeze_state Squeeze-state facet value (empty = any).
	 * @return void
	 */
	private function print_filter_form(
		string $s,
		string $type,
		string $subtype,
		string $orientation,
		string $used,
		string $folder,
		int $tag,
		int $missing_alt,
		int $size_min,
		int $size_max,
		int $w_min,
		int $w_max,
		int $h_min,
		int $h_max,
		int $uploader,
		string $date_from,
		string $date_to,
		string $orderby,
		string $order,
		string $squeeze_state = ''
	): void {
		$find_url = admin_url( 'admin.php' );

		echo '<div class="ad-find-filters">';
		echo '<form method="get" action="' . esc_url( $find_url ) . '" id="ad-find-form">';
		echo '<input type="hidden" name="page" value="' . esc_attr( self::SLUG ) . '" />';

		// Primary filter row.
		echo '<div class="ad-filter-row">';

		// Search.
		echo '<input type="search" name="s" value="' . esc_attr( $s ) . '" placeholder="Search filename, alt, caption&hellip;" class="regular-text" />';

		// Type select.
		echo '<select name="type">';
		echo '<option value=""' . selected( '', $type, false ) . '>Any type</option>';
		foreach ( array( 'image', 'video', 'audio', 'application' ) as $t ) {
			echo '<option value="' . esc_attr( $t ) . '"' . selected( $t, $type, false ) . '>' . esc_html( ucfirst( $t ) ) . '</option>';
		}
		echo '</select>';

		// Subtype select.
		echo '<select name="subtype">';
		echo '<option value=""' . selected( '', $subtype, false ) . '>Any format</option>';
		foreach ( array( 'jpeg', 'png', 'webp', 'gif', 'svg+xml', 'mp4', 'pdf', 'zip', 'mpeg' ) as $st ) {
			echo '<option value="' . esc_attr( $st ) . '"' . selected( $st, $subtype, false ) . '>' . esc_html( $st ) . '</option>';
		}
		echo '</select>';

		// Used/unused select.
		echo '<select name="used">';
		echo '<option value=""' . selected( '', $used, false ) . '>Any usage</option>';
		echo '<option value="used"' . selected( 'used', $used, false ) . '>Used</option>';
		echo '<option value="unused"' . selected( 'unused', $used, false ) . '>Unused</option>';
		echo '</select>';

		// Folder select.
		echo '<label>Folder <select name="folder">';
		echo '<option value=""' . selected( '', $folder, false ) . '>Any folder</option>';
		echo '<option value="uncategorized"' . selected( 'uncategorized', $folder, false ) . '>&mdash; Uncategorized</option>';
		$folder_terms = get_terms(
			array(
				'taxonomy'   => 'assetdrips_folder',
				'hide_empty' => false,
				'orderby'    => 'id',
				'order'      => 'ASC',
			)
		);
		if ( ! is_wp_error( $folder_terms ) && is_array( $folder_terms ) ) {
			foreach ( $folder_terms as $term ) {
				$depth = 0;
				$p     = $term->parent;
				// Cap the ancestor walk so a circular parent reference (only reachable
				// via direct DB corruption — the term API forbids cycles) can never hang
				// the Find screen render. Real folder trees are only a few levels deep.
				while ( $p > 0 && $depth < 100 ) {
					++$depth;
					$parent_term = get_term( $p, 'assetdrips_folder' );
					$p           = ( $parent_term instanceof \WP_Term ) ? (int) $parent_term->parent : 0;
				}
				$indent = str_repeat( '&nbsp;&nbsp;&nbsp;', $depth );
				// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Name is escaped via esc_html(); &nbsp; indent is a safe literal.
				echo '<option value="' . esc_attr( (string) $term->term_id ) . '"' . selected( (string) $term->term_id, $folder, false ) . '>' . $indent . esc_html( $term->name ) . '</option>';
			}
		}
		echo '</select></label>';

		// Tag select — flat taxonomy, alphabetical, no depth-indent walk, no uncategorized sentinel.
		echo '<label>Tag <select name="tag">';
		echo '<option value=""' . selected( 0, $tag, false ) . '>Any tag</option>';
		$tag_terms = get_terms(
			array(
				'taxonomy'   => 'assetdrips_tag',
				'hide_empty' => false,
				'orderby'    => 'name',
				'order'      => 'ASC',
			)
		);
		if ( ! is_wp_error( $tag_terms ) && is_array( $tag_terms ) ) {
			foreach ( $tag_terms as $term ) {
				$count_label = $term->count > 0 ? ' (' . (int) $term->count . ')' : '';
				echo '<option value="' . esc_attr( (string) $term->term_id ) . '"'
					. selected( $term->term_id, $tag, false ) . '>'
					. esc_html( $term->name . $count_label ) . '</option>';
			}
		}
		echo '</select></label>';

		// Squeeze-state facet (DASH-05): filter by optimization state.
		echo '<select name="squeeze_state">';
		echo '<option value=""' . selected( '', $squeeze_state, false ) . '>Any state</option>';
		echo '<option value="not-optimized"' . selected( 'not-optimized', $squeeze_state, false ) . '>Not optimized</option>';
		echo '<option value="oversized"' . selected( 'oversized', $squeeze_state, false ) . '>Oversized</option>';
		echo '<option value="missing-webp"' . selected( 'missing-webp', $squeeze_state, false ) . '>Missing WebP</option>';
		echo '<option value="has-backup"' . selected( 'has-backup', $squeeze_state, false ) . '>Has backup</option>';
		echo '</select>';

		// Missing alt checkbox.
		echo '<label style="display:inline-flex;align-items:center;gap:4px;white-space:nowrap;">';
		echo '<input type="checkbox" name="missing_alt" value="1"' . checked( 1, $missing_alt, false ) . ' />';
		echo 'Missing alt text only';
		echo '</label>';

		// Submit.
		echo '<button type="submit" class="button button-primary">Search media</button>';
		echo '<a href="' . esc_url( add_query_arg( 'page', self::SLUG, admin_url( 'admin.php' ) ) ) . '" style="font-size:13px;color:#777;">Clear filters</a>';

		echo '</div>';

		// Advanced filter row.
		echo '<a href="#" class="ad-filter-toggle">More filters &#9660;</a>';
		echo '<div class="ad-filter-row is-advanced">';

		// Orientation.
		echo '<label>Orientation <select name="orientation">';
		echo '<option value=""' . selected( '', $orientation, false ) . '>Any orientation</option>';
		echo '<option value="landscape"' . selected( 'landscape', $orientation, false ) . '>Landscape</option>';
		echo '<option value="portrait"' . selected( 'portrait', $orientation, false ) . '>Portrait</option>';
		echo '<option value="square"' . selected( 'square', $orientation, false ) . '>Square</option>';
		echo '</select></label>';

		// Size range (KB display, bytes stored).
		$size_min_kb = $size_min > 0 ? (int) round( $size_min / 1024 ) : 0;
		$size_max_kb = $size_max > 0 ? (int) round( $size_max / 1024 ) : 0;
		echo '<label>Size (KB) <input type="number" name="size_min" value="' . esc_attr( $size_min_kb > 0 ? (string) $size_min_kb : '' ) . '" placeholder="Min KB" style="width:70px;" /></label>';
		echo '<input type="number" name="size_max" value="' . esc_attr( $size_max_kb > 0 ? (string) $size_max_kb : '' ) . '" placeholder="Max KB" style="width:70px;" />';

		// Width range.
		echo '<label>Width (px) <input type="number" name="w_min" value="' . esc_attr( $w_min > 0 ? (string) $w_min : '' ) . '" placeholder="Min px" style="width:70px;" /></label>';
		echo '<input type="number" name="w_max" value="' . esc_attr( $w_max > 0 ? (string) $w_max : '' ) . '" placeholder="Max px" style="width:70px;" />';

		// Height range.
		echo '<label>Height (px) <input type="number" name="h_min" value="' . esc_attr( $h_min > 0 ? (string) $h_min : '' ) . '" placeholder="Min px" style="width:70px;" /></label>';
		echo '<input type="number" name="h_max" value="' . esc_attr( $h_max > 0 ? (string) $h_max : '' ) . '" placeholder="Max px" style="width:70px;" />';

		// Uploader.
		echo '<label>Uploader <select name="uploader">';
		echo '<option value=""' . selected( 0, $uploader, false ) . '>Any uploader</option>';
		$uploaders = get_users( array( 'fields' => array( 'ID', 'display_name' ) ) );
		foreach ( $uploaders as $user ) {
			echo '<option value="' . esc_attr( (string) $user->ID ) . '"' . selected( (int) $user->ID, $uploader, false ) . '>' . esc_html( $user->display_name ) . '</option>';
		}
		echo '</select></label>';

		// Date range.
		echo '<label>From <input type="date" name="date_from" value="' . esc_attr( $date_from ) . '" /></label>';
		echo '<label>To <input type="date" name="date_to" value="' . esc_attr( $date_to ) . '" /></label>';

		// Sort controls.
		echo '<label>Sort by <select name="orderby">';
		$orderby_opts = array(
			'uploaded_at' => 'Uploaded date',
			'filesize'    => 'File size',
			'filename'    => 'Filename',
			'title'       => 'Title',
		);
		foreach ( $orderby_opts as $val => $label ) {
			echo '<option value="' . esc_attr( $val ) . '"' . selected( $val, $orderby, false ) . '>' . esc_html( $label ) . '</option>';
		}
		echo '</select></label>';

		echo '<select name="order">';
		echo '<option value="desc"' . selected( 'desc', $order, false ) . '>&darr; Descending</option>';
		echo '<option value="asc"' . selected( 'asc', $order, false ) . '>&uarr; Ascending</option>';
		echo '</select>';

		echo '</div>'; // .is-advanced
		echo '</form>';
		echo '</div>'; // .ad-find-filters
	}

	/**
	 * Print the bulk action bar (4 op buttons).
	 *
	 * Hidden by default (display:none); JS shows it when selectedIds.size > 0
	 * or selectAllMatching is active (Plan 04 bulk driver).
	 *
	 * @return void
	 */
	private function print_bulk_bar(): void {
		// Bulk action bar (UI-SPEC §3) — orange-strip pattern, 4 op buttons.
		echo '<div class="ad-bulk-bar" style="display:none;" aria-label="Bulk actions">';
		echo '<span class="ad-bulk-bar-label">Bulk actions:</span>';
		echo '<button type="button" class="button ad-bulk-btn" data-op="folder_assign">Assign folder</button>';
		echo '<button type="button" class="button ad-bulk-btn" data-op="tag_add">Add tag</button>';
		echo '<button type="button" class="button ad-bulk-btn" data-op="tag_remove">Remove tag</button>';
		echo '<button type="button" class="button ad-bulk-btn" data-op="meta_edit">Edit metadata</button>';
		echo '<button type="button" class="button ad-bulk-btn" data-op="squeeze_optimize">Optimize images</button>';
		echo '<button type="button" class="button ad-bulk-btn" data-op="squeeze_restore">Restore originals</button>';
		echo '<button type="button" class="button ad-bulk-btn" data-op="squeeze_regenerate_sizes">Regenerate sizes</button>';
		echo '</div>';
	}

	/**
	 * Print the bulk operation configuration panel (inline, 4 op field groups).
	 *
	 * Hidden by default; JS reveals the panel and the matching field group when
	 * an op button is clicked. Also contains the progress/results containers that
	 * the batch driver populates (Plan 04 bulk driver).
	 *
	 * @return void
	 */
	private function print_bulk_panel(): void {
		echo '<div class="ad-bulk-panel" style="display:none;" role="region" aria-label="Bulk operation settings">';
		echo '<div class="ad-bulk-panel-inner">';

		// ── 4a: Folder assign (folder_assign) ───────────────────────────────────
		echo '<div class="ad-bulk-op-group" data-op="folder_assign" style="display:none;">';
		echo '<h3 class="ad-bulk-panel-heading">Assign folder to <span class="ad-bulk-count">0</span> items</h3>';
		echo '<label for="ad-bulk-folder">Folder</label>';
		echo '<select id="ad-bulk-folder" name="folder_id">';
		// Uncategorized sentinel (D-13 / UI-SPEC 4a).
		echo '<option value="">' . esc_html( '— Uncategorized —' ) . '</option>';
		// Render folder options mirroring the print_filter_form pattern (L1065–L1089).
		$folder_terms = get_terms(
			array(
				'taxonomy'   => 'assetdrips_folder',
				'hide_empty' => false,
				'orderby'    => 'id',
				'order'      => 'ASC',
			)
		);
		if ( ! is_wp_error( $folder_terms ) && is_array( $folder_terms ) ) {
			foreach ( $folder_terms as $term ) {
				$depth = 0;
				$p     = $term->parent;
				while ( $p > 0 && $depth < 100 ) {
					++$depth;
					$parent_term = get_term( $p, 'assetdrips_folder' );
					$p           = ( $parent_term instanceof \WP_Term ) ? (int) $parent_term->parent : 0;
				}
				$indent = str_repeat( '&nbsp;&nbsp;&nbsp;', $depth );
				// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Name is escaped via esc_html(); &nbsp; indent is a safe literal.
				echo '<option value="' . esc_attr( (string) $term->term_id ) . '">' . $indent . esc_html( $term->name ) . '</option>';
			}
		}
		echo '</select>';
		echo '<p class="ad-field-note">All selected items will be moved to this folder. Items already in this folder are unaffected.</p>';
		echo '</div>'; // .ad-bulk-op-group[folder_assign]

		// ── 4b: Tag add (tag_add) ───────────────────────────────────────────────
		echo '<div class="ad-bulk-op-group" data-op="tag_add" style="display:none;">';
		echo '<h3 class="ad-bulk-panel-heading">Add tag to <span class="ad-bulk-count">0</span> items</h3>';
		echo '<label for="ad-bulk-tag-add">Tag</label>';
		echo '<select id="ad-bulk-tag-add" name="tag_id">';
		$tag_terms = get_terms(
			array(
				'taxonomy'   => 'assetdrips_tag',
				'hide_empty' => false,
				'orderby'    => 'name',
				'order'      => 'ASC',
			)
		);
		if ( ! is_wp_error( $tag_terms ) && is_array( $tag_terms ) ) {
			foreach ( $tag_terms as $term ) {
				echo '<option value="' . esc_attr( (string) $term->term_id ) . '">' . esc_html( $term->name ) . '</option>';
			}
		}
		echo '</select>';
		echo '<p class="ad-field-note">This tag will be added to each selected item. Existing tags are preserved.</p>';
		echo '</div>'; // .ad-bulk-op-group[tag_add]

		// ── 4c: Tag remove (tag_remove) ─────────────────────────────────────────
		echo '<div class="ad-bulk-op-group" data-op="tag_remove" style="display:none;">';
		echo '<h3 class="ad-bulk-panel-heading">Remove tag from <span class="ad-bulk-count">0</span> items</h3>';
		echo '<label for="ad-bulk-tag-remove">Tag</label>';
		echo '<select id="ad-bulk-tag-remove" name="tag_id">';
		// Reuse tag terms already fetched; get_terms is cached by WP.
		if ( ! is_wp_error( $tag_terms ) && is_array( $tag_terms ) ) {
			foreach ( $tag_terms as $term ) {
				echo '<option value="' . esc_attr( (string) $term->term_id ) . '">' . esc_html( $term->name ) . '</option>';
			}
		}
		echo '</select>';
		echo '<p class="ad-field-note">This tag will be removed from each selected item that carries it. Other tags are preserved.</p>';
		echo '</div>'; // .ad-bulk-op-group[tag_remove]

		// ── 4d: Metadata edit (meta_edit) ───────────────────────────────────────
		echo '<div class="ad-bulk-op-group" data-op="meta_edit" style="display:none;">';
		echo '<h3 class="ad-bulk-panel-heading">Edit metadata for <span class="ad-bulk-count">0</span> items</h3>';

		// Global fill-empty-only toggle (D-10 / BULK-01).
		echo '<label>';
		echo '<input type="checkbox" id="ad-bulk-fill-empty-only" name="fill_empty_only" value="1">';
		echo ' Only fill empty fields &#8212; skip items that already have a value';
		echo '</label>';
		echo '<p class="ad-field-note" style="margin-top:4px;margin-bottom:16px;">When checked, each field is only written to items where that field is currently blank.</p>';

		// Alt text (D-09 — blank = leave unchanged).
		echo '<label for="ad-bulk-alt">Alt text</label>';
		echo '<input type="text" id="ad-bulk-alt" name="alt" placeholder="Describe the image for screen readers">';
		echo '<p class="ad-field-note">Leave blank to leave alt text unchanged on all items.</p>';

		// Title.
		echo '<label for="ad-bulk-title">Title</label>';
		echo '<input type="text" id="ad-bulk-title" name="title" placeholder="">';
		echo '<p class="ad-field-note">Leave blank to leave titles unchanged.</p>';

		// Caption.
		echo '<label for="ad-bulk-caption">Caption</label>';
		echo '<input type="text" id="ad-bulk-caption" name="caption" placeholder="">';
		echo '<p class="ad-field-note">Leave blank to leave captions unchanged.</p>';

		// Description.
		echo '<label for="ad-bulk-description">Description</label>';
		echo '<textarea id="ad-bulk-description" name="description" rows="3" placeholder=""></textarea>';
		echo '<p class="ad-field-note">Leave blank to leave descriptions unchanged. HTML is stripped on save.</p>';

		echo '</div>'; // .ad-bulk-op-group[meta_edit]

		// ── 4e: Optimize images (squeeze_optimize) ──────────────────────────────
		echo '<div class="ad-bulk-op-group" data-op="squeeze_optimize" style="display:none;">';
		echo '<h3 class="ad-bulk-panel-heading">Optimize images — <span class="ad-bulk-count">0</span> items</h3>';
		echo '<p class="ad-field-note">Runs the enabled squeeze operations (recompress, WebP, AVIF, resize) on each selected item using the current settings. Items that have already been optimized at the same settings are skipped automatically.</p>';
		echo '</div>'; // .ad-bulk-op-group[squeeze_optimize]

		// ── 4f: Restore originals (squeeze_restore) ─────────────────────────────
		echo '<div class="ad-bulk-op-group" data-op="squeeze_restore" style="display:none;">';
		echo '<h3 class="ad-bulk-panel-heading">Restore originals for <span class="ad-bulk-count">0</span> items</h3>';
		echo '<p class="ad-field-note" style="color:#d63638;">This will replace the optimized file with the backed-up original. This cannot be undone. Items without a backup will be skipped.</p>';
		echo '<label>';
		echo '<input type="checkbox" id="ad-bulk-restore-confirm" name="restore_confirm" value="1">';
		echo ' I understand this will replace optimized files with originals';
		echo '</label>';
		echo '<p class="ad-field-note">You must check the box above before applying.</p>';
		echo '</div>'; // .ad-bulk-op-group[squeeze_restore]

		// ── 4g: Regenerate sizes (squeeze_regenerate_sizes) ─────────────────────
		echo '<div class="ad-bulk-op-group" data-op="squeeze_regenerate_sizes" style="display:none;">';
		echo '<h3 class="ad-bulk-panel-heading">Regenerate sizes for <span class="ad-bulk-count">0</span> items</h3>';
		echo '<p class="ad-field-note">Additively generates any missing registered thumbnail sizes for each selected item. Existing sizes and custom crops are never overwritten.</p>';
		echo '</div>'; // .ad-bulk-op-group[squeeze_regenerate_sizes]

		// ── Panel actions row ────────────────────────────────────────────────────
		echo '<div class="ad-bulk-panel-actions">';
		echo '<button type="button" class="button button-primary ad-bulk-apply">Apply to <span class="ad-bulk-apply-count">0</span> items</button>';
		echo '<button type="button" class="button ad-bulk-cancel">Keep selection</button>';
		echo '</div>';

		// ── Progress + results scaffolding (populated by JS batch driver) ────────
		// Progress state (UI-SPEC §5, reuses existing .ad-progress-track/.ad-progress-fill).
		echo '<div class="ad-bulk-progress" style="display:none;">';
		echo '<p class="ad-bulk-progress-label"><strong class="ad-bulk-progress-count">0 of 0 done</strong></p>';
		echo '<div class="assetdrips-progress">';
		echo '<div class="ad-progress-track"><div class="ad-progress-fill" style="width:0%;"></div></div>';
		echo '</div>';
		echo '</div>'; // .ad-bulk-progress

		// Result state (success or partial-failure) — hidden until operation completes.
		echo '<div class="ad-bulk-result" style="display:none;"></div>';

		echo '</div>'; // .ad-bulk-panel-inner
		echo '</div>'; // .ad-bulk-panel
	}

	/**
	 * Print the result count and sort toolbar.
	 *
	 * @param int    $total       Total results.
	 * @param bool   $is_filtered Whether any filter is active.
	 * @param string $s           Search term.
	 * @return void
	 */
	private function print_toolbar( int $total, bool $is_filtered, string $s ): void {
		if ( $is_filtered ) {
			if ( '' !== $s ) {
				$label = sprintf( '%s results for &ldquo;%s&rdquo;', number_format_i18n( $total ), esc_html( $s ) );
			} else {
				$label = number_format_i18n( $total ) . ' results';
			}
		} else {
			$label = 1 === $total
				? '1 media file'
				: number_format_i18n( $total ) . ' media files';
		}

		echo '<div class="ad-find-toolbar">';
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Label is built with esc_html() applied to user input; static surrounding text is safe.
		echo '<span class="ad-find-total">' . $label . '</span>';
		// Selection controls (SEL-01) — hidden until JS activates (progressive enhancement, D-01).
		echo '<div class="ad-selection-controls" style="display:none;">';
		echo '<span class="ad-selection-count" aria-live="polite">0 selected</span>';
		echo '<button type="button" class="button ad-select-all-page">Select all on page</button>';
		echo '<button type="button" class="button ad-clear-selection" disabled>Clear selection</button>';
		echo '</div>';
		echo '</div>';

		// SEL-02 affordance: "Select all matching this filter" — visible only when filter active,
		// all on page selected, and more pages exist. Shown/hidden by JS.
		echo '<p class="ad-select-all-matching" style="display:none;" data-total="' . esc_attr( (string) $total ) . '" data-is-filtered="' . esc_attr( $is_filtered ? '1' : '0' ) . '">';
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- number_format_i18n returns a sanitized string; static surrounding markup is safe.
		echo '<a href="#" class="ad-select-all-matching-link">Select all ' . number_format_i18n( $total ) . ' attachments matching this filter</a>';
		echo '&nbsp;&mdash;&nbsp;';
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- number_format_i18n returns a sanitized string; static surrounding markup is safe.
		echo '<span class="ad-select-all-matching-active" style="display:none;">All ' . number_format_i18n( $total ) . ' selected. <a href="#" class="ad-clear-selection-link">Clear selection</a></span>';
		echo '</p>';
	}

	/**
	 * Print the results grid.
	 *
	 * @param array<int, array<string, mixed>> $rows        Query result rows.
	 * @param int                              $total       Total indexed count.
	 * @param bool                             $is_filtered Whether any filter is active.
	 * @return void
	 */
	private function print_results_grid( array $rows, int $total, bool $is_filtered ): void {
		echo '<div class="ad-find-grid">';

		if ( empty( $rows ) ) {
			$this->print_empty_state( $total, $is_filtered );
			echo '</div>';
			return;
		}

		foreach ( $rows as $row ) {
			$this->print_card( $row );
		}

		echo '</div>';
	}

	/**
	 * Print an empty-state block.
	 *
	 * @param int  $total       Total indexed rows (library empty when 0).
	 * @param bool $is_filtered Whether any filter is active.
	 * @return void
	 */
	private function print_empty_state( int $total, bool $is_filtered ): void {
		$find_url = add_query_arg( 'page', self::SLUG, admin_url( 'admin.php' ) );

		if ( 0 === $total && ! $is_filtered ) {
			// The index is empty. Distinguish a genuinely empty media library
			// from an index that simply has not been backfilled yet — Find reads
			// the assetdrips_media index, not wp_posts, so an existing library
			// shows zero results until the backfill (or reconcile cron) runs.
			$library_count = (int) array_sum( (array) wp_count_attachments() );

			echo '<div class="ad-find-empty ad-empty">';

			if ( $library_count > 0 ) {
				// Library has media, but the index has not been built yet.
				echo '<p><strong>Your media index is still being built.</strong></p>';
				echo '<p>Find searches a fast index of your '
					. esc_html( number_format_i18n( $library_count ) )
					. ' media file' . ( 1 === $library_count ? '' : 's' )
					. '. It builds automatically in the background — or run '
					. '<code>' . esc_html( 'wp assetdrips index' ) . '</code> to build it now.</p>';
				echo '<a href="' . esc_url( admin_url( 'upload.php' ) ) . '">Go to Media Library &rarr;</a>';
			} else {
				// Genuinely empty library.
				echo '<p><strong>Your media library appears to be empty.</strong></p>';
				echo '<p>Upload your first media file to get started.</p>';
				echo '<a href="' . esc_url( admin_url( 'upload.php' ) ) . '">Go to Media Library &rarr;</a>';
			}

			echo '</div>';
		} else {
			// No results for current filters.
			echo '<div class="ad-find-empty ad-empty">';
			echo '<p><strong>No media match your filters.</strong></p>';
			echo '<p>Try broadening your search or clearing some filters.</p>';
			echo '<a href="' . esc_url( $find_url ) . '">Clear all filters &rarr;</a>';
			echo '</div>';
		}
	}

	/**
	 * Print a single result card.
	 *
	 * @param array<string, mixed> $row Result row from MediaIndex::query().
	 * @return void
	 */
	private function print_card( array $row ): void {
		$id       = (int) ( $row['attachment_id'] ?? 0 );
		$filename = (string) ( $row['filename'] ?? '' );
		$width    = (int) ( $row['width'] ?? 0 );
		$height   = (int) ( $row['height'] ?? 0 );
		$filesize = (int) ( $row['filesize'] ?? 0 );
		$has_alt  = isset( $row['has_alt'] ) ? (int) $row['has_alt'] : null;
		$is_used  = isset( $row['is_used'] ) && '' !== $row['is_used'] ? (int) $row['is_used'] : null;
		$synced   = $row['usage_synced_at'] ?? null;
		$usage_ct = isset( $row['usage_count'] ) && '' !== $row['usage_count'] ? (int) $row['usage_count'] : null;

		$edit_url = get_edit_post_link( $id );
		$edit_url = $edit_url ? $edit_url : '#';
		$aria_lbl = esc_attr( $filename . ' — edit in media library' );

		// Format file size.
		$size_label = '';
		if ( $filesize > 0 ) {
			if ( $filesize >= 1048576 ) {
				$size_label = round( $filesize / 1048576, 1 ) . ' MB';
			} elseif ( $filesize >= 1024 ) {
				$size_label = round( $filesize / 1024, 0 ) . ' KB';
			} else {
				$size_label = $filesize . ' B';
			}
		}

		// Dims + size meta line.
		$dims_parts = array();
		if ( $width > 0 && $height > 0 ) {
			$dims_parts[] = $width . '&times;' . $height;
		}
		if ( '' !== $size_label ) {
			$dims_parts[] = esc_html( $size_label );
		}

		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $aria_lbl is the result of esc_attr() applied above; re-escaping would double-encode.
		echo '<a class="ad-find-card" href="' . esc_url( $edit_url ) . '" target="_blank" rel="noopener" aria-label="' . $aria_lbl . '" data-id="' . esc_attr( (string) $id ) . '">';

		// Selection checkbox overlay (SEL-01).
		echo '<label class="ad-select-label" aria-label="Select ' . esc_attr( $filename ) . '">';
		echo '<input type="checkbox" class="ad-select-cb">';
		echo '</label>';

		// Thumbnail.
		$thumb = wp_get_attachment_image( $id, 'thumbnail', false, array( 'class' => 'ad-find-card-thumb' ) );
		if ( '' !== $thumb ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- wp_get_attachment_image() escapes internally.
			echo $thumb;
		} else {
			echo '<div class="ad-find-card-thumb" style="background:#f3f3f3;width:100%;aspect-ratio:1/1;"></div>';
		}

		// Meta block.
		echo '<div class="ad-find-card-meta">';
		echo '<div class="ad-find-card-filename">' . esc_html( $filename ) . '</div>';

		if ( ! empty( $dims_parts ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Dims are escaped individually; separator is static.
			echo '<div class="ad-find-card-dims">' . implode( ' &middot; ', $dims_parts ) . '</div>';
		}

		// Usage count line (only if synced).
		if ( null !== $synced && '' !== $synced && null !== $usage_ct ) {
			$usage_label = 0 === $usage_ct ? 'Unused' : $usage_ct . ' use' . ( 1 === $usage_ct ? '' : 's' );
			echo '<div class="ad-find-card-usage">' . esc_html( $usage_label ) . '</div>';
		}

		// Badge row.
		echo '<div class="ad-find-card-badges">';

		if ( null !== $synced && '' !== $synced && null !== $is_used ) {
			if ( $is_used ) {
				echo '<span class="ad-pill" style="background:#080808;">Used</span>';
			} else {
				echo '<span class="ad-pill" style="background:#f1f1f1;color:#444;">Unused</span>';
			}
		}

		if ( null !== $has_alt && 0 === $has_alt ) {
			echo '<span class="ad-pill" style="background:var(--ad-orange);">No alt</span>';
		}

		echo '</div>'; // .ad-find-card-badges
		echo '</div>'; // .ad-find-card-meta
		echo '</a>'; // .ad-find-card
	}

	/**
	 * Print classic pagination links.
	 *
	 * @param int                   $paged       Current page.
	 * @param int                   $total_pages Total pages.
	 * @param array<string, string> $filter_args Active filter args for URL preservation.
	 * @return void
	 */
	private function print_pagination( int $paged, int $total_pages, array $filter_args ): void {
		if ( $total_pages <= 1 ) {
			echo '<div class="ad-find-pagination"></div>';
			return;
		}

		// Build base args without paged.
		$base_args = $filter_args;
		unset( $base_args['paged'] );

		$links = paginate_links(
			array(
				'base'      => add_query_arg( 'paged', '%#%', admin_url( 'admin.php' ) ),
				'format'    => '',
				'total'     => $total_pages,
				'current'   => $paged,
				'add_args'  => $base_args,
				'prev_text' => '&larr; Previous',
				'next_text' => 'Next &rarr;',
			)
		);

		if ( $links ) {
			echo '<div class="ad-find-pagination">';
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- paginate_links() produces safe HTML.
			echo $links;
			echo '</div>';
		} else {
			echo '<div class="ad-find-pagination"></div>';
		}
	}

	/**
	 * Print the progressive-enhancement AJAX script for the Find screen.
	 *
	 * The GET form works with no JS (progressive enhancement). With JS, the
	 * form submit and pagination link clicks are intercepted to fetch results
	 * without a full page reload (T-02-04: nonce minted here).
	 *
	 * @return void
	 */
	private function print_find_script(): void {
		$nonce    = esc_js( wp_create_nonce( self::AJAX_NONCE ) );
		$per_page = self::PER_PAGE;

		$script = <<<JS
(function(){
	var form = document.getElementById('ad-find-form');
	if(!form) return;
	var grid = document.querySelector('.ad-find-grid');
	var pag  = document.querySelector('.ad-find-pagination');
	var toolbar = document.querySelector('.ad-find-toolbar .ad-find-total');
	var nonce = '{$nonce}';
	var ajax  = window.ajaxurl || '/wp-admin/admin-ajax.php';
	var perPage = {$per_page};

	// ── Selection state (SEL-01 / SEL-02) ────────────────────────────────────
	// Closured Set persists across AJAX grid re-renders (D-02).
	var selectedIds = new Set();
	var selectAllMatching = false;
	var filterArgs = {};

	// Seam for Plan 04's bulk driver — attach to the page-level adBulk namespace.
	window.adBulk = window.adBulk || {};
	window.adBulk.getSelectedIds       = function(){ return selectedIds; };
	window.adBulk.getSelectAllMatching = function(){ return selectAllMatching; };
	window.adBulk.getFilterArgs        = function(){ return filterArgs; };

	function updateSelectCount(){
		var countEl    = document.querySelector('.ad-selection-count');
		var controls   = document.querySelector('.ad-selection-controls');
		var clearBtn   = document.querySelector('.ad-clear-selection');
		if(!countEl) return;
		var n = selectedIds.size;
		countEl.textContent = n + ' selected';
		if(controls){ controls.style.display = ''; }
		if(clearBtn){ clearBtn.disabled = (n === 0); }
		updateSelectAllMatchingVisibility();
	}

	function updateSelectAllMatchingVisibility(){
		var affordance = document.querySelector('.ad-select-all-matching');
		if(!affordance) return;
		var isFiltered  = affordance.getAttribute('data-is-filtered') === '1';
		var total       = parseInt(affordance.getAttribute('data-total') || '0', 10);
		var cards       = document.querySelectorAll('.ad-find-grid [data-id]');
		var allChecked  = cards.length > 0 && selectedIds.size >= cards.length && (function(){
			var allSelected = true;
			cards.forEach(function(c){ if(!selectedIds.has(parseInt(c.dataset.id, 10))){ allSelected = false; } });
			return allSelected;
		}());
		var morePagesExist = total > perPage;
		var shouldShow = isFiltered && allChecked && morePagesExist;
		affordance.style.display = shouldShow ? '' : 'none';

		// Active state: "All N selected. Clear selection."
		var activeSpan = affordance.querySelector('.ad-select-all-matching-active');
		var matchLink  = affordance.querySelector('.ad-select-all-matching-link');
		if(activeSpan && matchLink){
			activeSpan.style.display = selectAllMatching ? '' : 'none';
			matchLink.style.display  = selectAllMatching ? 'none' : '';
		}
	}

	function reCheckSelected(){
		// Re-acquire the grid node (old ref is detached after outerHTML swap — Pitfall 3).
		var newGrid = document.querySelector('.ad-find-grid');
		if(!newGrid) return;
		newGrid.querySelectorAll('[data-id]').forEach(function(card){
			var id = parseInt(card.dataset.id, 10);
			var cb = card.querySelector('input[type="checkbox"].ad-select-cb');
			if(cb){ cb.checked = selectedIds.has(id); }
			if(selectedIds.has(id)){
				card.classList.add('is-selected');
			} else {
				card.classList.remove('is-selected');
			}
		});
		updateSelectCount();
	}

	function clearSelection(){
		selectedIds.clear();
		selectAllMatching = false;
		document.querySelectorAll('.ad-find-grid [data-id]').forEach(function(card){
			var cb = card.querySelector('input[type="checkbox"].ad-select-cb');
			if(cb){ cb.checked = false; }
			card.classList.remove('is-selected');
		});
		updateSelectCount();
	}

	// Checkbox event delegation on the grid wrapper (handles re-rendered grids too).
	document.addEventListener('change', function(e){
		var cb = e.target;
		if(!cb || !cb.classList.contains('ad-select-cb')) return;
		var card = cb.closest('[data-id]');
		if(!card) return;
		var id = parseInt(card.dataset.id, 10);
		if(cb.checked){
			selectedIds.add(id);
			card.classList.add('is-selected');
		} else {
			selectedIds.delete(id);
			card.classList.remove('is-selected');
			selectAllMatching = false;
		}
		updateSelectCount();
	});

	// Prevent checkbox label click from navigating the card <a> link (UI-SPEC §1).
	document.addEventListener('click', function(e){
		var label = e.target.closest('.ad-select-label');
		if(label){
			e.stopPropagation();
		}
	}, true);

	// "Select all on page" button.
	document.addEventListener('click', function(e){
		if(!e.target.classList.contains('ad-select-all-page')) return;
		document.querySelectorAll('.ad-find-grid [data-id]').forEach(function(card){
			var id = parseInt(card.dataset.id, 10);
			var cb = card.querySelector('input[type="checkbox"].ad-select-cb');
			selectedIds.add(id);
			if(cb){ cb.checked = true; }
			card.classList.add('is-selected');
		});
		updateSelectCount();
	});

	// "Clear selection" button.
	document.addEventListener('click', function(e){
		if(!e.target.classList.contains('ad-clear-selection')) return;
		clearSelection();
	});

	// "Clear selection" link in the select-all-matching affordance.
	document.addEventListener('click', function(e){
		if(!e.target.classList.contains('ad-clear-selection-link')) return;
		e.preventDefault();
		clearSelection();
	});

	// "Select all N matching this filter" link (SEL-02).
	// Stores filter_args + flag — NOT an id blob (D-03, Pitfall 5).
	document.addEventListener('click', function(e){
		if(!e.target.classList.contains('ad-select-all-matching-link')) return;
		e.preventDefault();
		// Capture current filter state from the form (mirrors sanitize_filter_state keys).
		var fd = new FormData(form);
		filterArgs = {};
		fd.forEach(function(v, k){ if(v !== '' && k !== 'action' && k !== 'nonce'){ filterArgs[k] = v; } });
		selectAllMatching = true;
		// Also select all currently visible cards into the Set.
		document.querySelectorAll('.ad-find-grid [data-id]').forEach(function(card){
			var id = parseInt(card.dataset.id, 10);
			var cb = card.querySelector('input[type="checkbox"].ad-select-cb');
			selectedIds.add(id);
			if(cb){ cb.checked = true; }
			card.classList.add('is-selected');
		});
		updateSelectCount();
	});

	// ── Filter / pagination AJAX ──────────────────────────────────────────────

	// Toggle advanced filters.
	var toggle = document.querySelector('.ad-filter-toggle');
	var adv    = document.querySelector('.ad-filter-row.is-advanced');
	if(toggle && adv){
		var hasActive = adv.querySelector('select option[selected], input[checked], input[value]:not([value=""])');
		if(!hasActive){ adv.style.display='none'; }
		toggle.addEventListener('click', function(e){
			e.preventDefault();
			adv.style.display = adv.style.display === 'none' ? '' : 'none';
			toggle.textContent = adv.style.display === 'none' ? 'More filters ▼' : 'Fewer filters ▲';
		});
	}

	// Save-view toggle.
	var saveLink = document.querySelector('.ad-save-view-trigger');
	var saveForm = document.querySelector('.ad-save-view-form');
	if(saveLink && saveForm){
		saveLink.addEventListener('click', function(e){
			e.preventDefault();
			saveForm.style.display = saveForm.style.display === 'none' ? '' : 'none';
		});
	}

	function fetchResults(formData, url){
		if(!grid) return;
		grid.innerHTML = '<div class="ad-find-empty"><div class="assetdrips-progress" style="max-width:200px;margin:24px auto;"><div class="ad-progress-track"><div class="ad-progress-fill is-indeterminate"></div></div></div></div>';
		var body = new FormData();
		for(var pair of formData.entries()){ body.append(pair[0], pair[1]); }
		body.append('action', 'assetdrips_find_results');
		body.append('nonce', nonce);
		fetch(ajax, {method:'POST', credentials:'same-origin', body:body})
			.then(function(r){ return r.json(); })
			.then(function(res){
				if(res && res.success){
					if(grid){ grid.outerHTML = res.data.html; }
					if(pag){ pag.outerHTML = res.data.pagination; }
					if(toolbar){ toolbar.textContent = res.data.total + ' results'; }
					if(url){ history.replaceState(null, '', url); }
					// Re-bind pagination clicks on new HTML.
					bindPagination();
					// CRITICAL: re-acquire grid (old ref is detached — Pitfall 3) then re-check.
					grid = document.querySelector('.ad-find-grid');
					reCheckSelected();
				} else {
					if(grid){ grid.innerHTML = '<div class="ad-find-empty ad-empty"><p>Results failed to load. Reload the page to try again.</p></div>'; }
				}
			})
			.catch(function(){
				if(grid){ grid.innerHTML = '<div class="ad-find-empty ad-empty"><p>Results failed to load. Reload the page to try again.</p></div>'; }
			});
	}

	form.addEventListener('submit', function(e){
		e.preventDefault();
		var data = new FormData(form);
		var url  = '?' + new URLSearchParams(data).toString().replace(/action=[^&]*&?/,'').replace(/nonce=[^&]*&?/,'');
		fetchResults(data, url);
	});

	function bindPagination(){
		var links = document.querySelectorAll('.ad-find-pagination a');
		links.forEach(function(link){
			link.addEventListener('click', function(e){
				e.preventDefault();
				var href = link.getAttribute('href');
				var params = new URLSearchParams(href.split('?')[1] || '');
				var data = new FormData(form);
				data.set('paged', params.get('paged') || '1');
				fetchResults(data, href);
			});
		});
	}
	bindPagination();

	// Show controls on page load (JS active — progressive enhancement D-01).
	updateSelectCount();

	// ── Bulk bar visibility ───────────────────────────────────────────────────
	// Called after every selection change to show/hide the bar (UI-SPEC §3).
	function updateBulkBarVisibility(){
		var bar = document.querySelector('.ad-bulk-bar');
		if(!bar) return;
		var hasSelection = selectedIds.size > 0 || selectAllMatching;
		bar.style.display = hasSelection ? 'flex' : 'none';
	}

	// Patch updateSelectCount to also drive the bulk bar.
	var _origUpdateSelectCount = updateSelectCount;
	updateSelectCount = function(){
		_origUpdateSelectCount();
		updateBulkBarVisibility();
	};
	// Drive bar on initial load.
	updateBulkBarVisibility();

	// ── Bulk panel open/close ─────────────────────────────────────────────────
	var currentOp = null;

	function getSelectionCount(){
		// For select_all_matching, total comes from the toolbar's data-total.
		if(selectAllMatching){
			var affordance = document.querySelector('.ad-select-all-matching');
			return affordance ? parseInt(affordance.getAttribute('data-total') || '0', 10) : selectedIds.size;
		}
		return selectedIds.size;
	}

	function openBulkPanel(op){
		var panel = document.querySelector('.ad-bulk-panel');
		var gridEl = document.querySelector('.ad-find-grid');
		var toolbar = document.querySelector('.ad-find-toolbar');
		var paginationEl = document.querySelector('.ad-find-pagination');
		var barBtns = document.querySelectorAll('.ad-bulk-btn');
		if(!panel) return;
		currentOp = op;
		// Hide all op groups, reveal the matching one.
		panel.querySelectorAll('.ad-bulk-op-group').forEach(function(g){ g.style.display = 'none'; });
		var group = panel.querySelector('.ad-bulk-op-group[data-op="' + op + '"]');
		if(group){ group.style.display = ''; }
		// Update count labels in the panel.
		var n = getSelectionCount();
		panel.querySelectorAll('.ad-bulk-count').forEach(function(el){ el.textContent = String(n); });
		var applyCount = panel.querySelector('.ad-bulk-apply-count');
		if(applyCount){ applyCount.textContent = String(n); }
		// Hide progress/result from previous run.
		var progressEl = panel.querySelector('.ad-bulk-progress');
		var resultEl   = panel.querySelector('.ad-bulk-result');
		var actionsEl  = panel.querySelector('.ad-bulk-panel-actions');
		if(progressEl){ progressEl.style.display = 'none'; }
		if(resultEl){ resultEl.style.display = 'none'; resultEl.innerHTML = ''; }
		if(actionsEl){ actionsEl.style.display = 'flex'; }
		// Reset apply button state.
		var applyBtn = panel.querySelector('.ad-bulk-apply');
		if(applyBtn){ applyBtn.disabled = false; }
		// Restore confirm gate: start disabled until checkbox checked (squeeze_restore only).
		if(op === 'squeeze_restore'){
			var restoreCb = document.getElementById('ad-bulk-restore-confirm');
			if(restoreCb){ restoreCb.checked = false; }
			if(applyBtn){ applyBtn.disabled = true; }
		}
		// Show panel, hide grid + toolbar + pagination.
		panel.style.display = '';
		if(gridEl){ gridEl.style.display = 'none'; }
		if(toolbar){ toolbar.style.display = 'none'; }
		if(paginationEl){ paginationEl.style.display = 'none'; }
		// Disable bar buttons while panel is open (prevent double-open).
		barBtns.forEach(function(btn){ btn.disabled = true; });
	}

	function closeBulkPanel(clearSel){
		var panel = document.querySelector('.ad-bulk-panel');
		var gridEl = document.querySelector('.ad-find-grid');
		var toolbar = document.querySelector('.ad-find-toolbar');
		var paginationEl = document.querySelector('.ad-find-pagination');
		var barBtns = document.querySelectorAll('.ad-bulk-btn');
		if(panel){ panel.style.display = 'none'; }
		if(gridEl){ gridEl.style.display = ''; }
		if(toolbar){ toolbar.style.display = ''; }
		if(paginationEl){ paginationEl.style.display = ''; }
		barBtns.forEach(function(btn){ btn.disabled = false; });
		currentOp = null;
		if(clearSel){ clearSelection(); }
	}

	// Op button click — open the matching panel.
	document.addEventListener('click', function(e){
		var btn = e.target.closest('.ad-bulk-btn');
		if(!btn) return;
		var op = btn.getAttribute('data-op');
		if(op){ openBulkPanel(op); }
	});

	// "Keep selection" (cancel) — close panel, preserve selection.
	document.addEventListener('click', function(e){
		if(!e.target.classList.contains('ad-bulk-cancel')) return;
		closeBulkPanel(false);
	});

	// "Close results" — close panel, clear selection.
	document.addEventListener('click', function(e){
		if(!e.target.classList.contains('ad-bulk-done')) return;
		closeBulkPanel(true);
	});

	// Restore confirm gate — toggle apply button when checkbox changes.
	document.addEventListener('change', function(e){
		if(e.target.id !== 'ad-bulk-restore-confirm') return;
		if(currentOp !== 'squeeze_restore') return;
		var applyBtn = document.querySelector('.ad-bulk-panel .ad-bulk-apply');
		if(applyBtn){ applyBtn.disabled = !e.target.checked; }
	});

	// ── Batch driver ──────────────────────────────────────────────────────────
	// JSON-body POST to wp_ajax_assetdrips_bulk_op; batches ~50 ids sequentially.
	// For select_all_matching: sends filter_args + flag once (server re-derives ids).
	// Progress: "N of M done" text + bar width (UI-SPEC §5 / D-05).
	// Failures: per-item list (SC-3/4 — never silently dropped).

	var BATCH = 50;

	function collectOpParams(op){
		var params = {};
		if('folder_assign' === op){
			var sel = document.getElementById('ad-bulk-folder');
			params.folder_id = sel ? (sel.value || '') : '';
		} else if('tag_add' === op){
			var sel2 = document.getElementById('ad-bulk-tag-add');
			params.tag_id = sel2 ? parseInt(sel2.value, 10) : 0;
		} else if('tag_remove' === op){
			var sel3 = document.getElementById('ad-bulk-tag-remove');
			params.tag_id = sel3 ? parseInt(sel3.value, 10) : 0;
		} else if('meta_edit' === op){
			var fillCb = document.getElementById('ad-bulk-fill-empty-only');
			params.fill_empty_only = fillCb && fillCb.checked ? 1 : 0;
			var altEl  = document.getElementById('ad-bulk-alt');
			var titEl  = document.getElementById('ad-bulk-title');
			var capEl  = document.getElementById('ad-bulk-caption');
			var descEl = document.getElementById('ad-bulk-description');
			params.alt         = altEl  ? altEl.value  : '';
			params.title       = titEl  ? titEl.value  : '';
			params.caption     = capEl  ? capEl.value  : '';
			params.description = descEl ? descEl.value : '';
		} else if ('squeeze_optimize' === op) {
			// no extra params needed
		} else if ('squeeze_restore' === op) {
			var confirmCb = document.getElementById('ad-bulk-restore-confirm');
			params.restore_confirm = confirmCb && confirmCb.checked ? 1 : 0;
		} else if ('squeeze_regenerate_sizes' === op) {
			// no extra params needed
		}
		return params;
	}

	function postBatch(op, payload){
		// Payload is either {ids:[], ...params} or {filter_args:{}, select_all_matching:true, ...params}.
		var body = Object.assign({op: op, nonce: nonce}, payload);
		return fetch(ajax + '?action=assetdrips_bulk_op', {
			method: 'POST',
			credentials: 'same-origin',
			headers: {'Content-Type': 'application/json'},
			body: JSON.stringify(body)
		}).then(function(r){ return r.json(); });
	}

	function updateProgress(panel, done, total){
		var countEl = panel.querySelector('.ad-bulk-progress-count');
		var fill    = panel.querySelector('.ad-progress-fill');
		if(countEl){ countEl.textContent = done + ' of ' + total + ' done'; }
		if(fill){ fill.style.width = total > 0 ? Math.round((done / total) * 100) + '%' : '0%'; }
	}

	function renderResult(panel, total, failures){
		var resultEl  = panel.querySelector('.ad-bulk-result');
		var actionsEl = panel.querySelector('.ad-bulk-panel-actions');
		var progressEl = panel.querySelector('.ad-bulk-progress');
		if(progressEl){ progressEl.style.display = 'none'; }
		if(actionsEl){ actionsEl.style.display = 'none'; }
		if(!resultEl) return;
		resultEl.style.display = '';
		// Build result content without innerHTML string concatenation of server values (T-07-xss-result).
		var frag = document.createDocumentFragment();
		var p = document.createElement('p');
		var strong = document.createElement('strong');
		if(failures.length === 0){
			strong.textContent = total + ' items updated.';
			p.appendChild(strong);
		} else {
			var success = total - failures.length;
			strong.textContent = success + ' of ' + total + ' items updated.';
			p.appendChild(strong);
			p.appendChild(document.createTextNode(' ' + failures.length + ' item(s) could not be updated:'));
		}
		frag.appendChild(p);
		if(failures.length > 0){
			var ul = document.createElement('ul');
			ul.className = 'ad-bulk-failures';
			failures.forEach(function(f){
				var li = document.createElement('li');
				li.className = 'ad-bulk-failure-item';
				var filenameEl = document.createElement('strong');
				// textContent escapes any server-supplied strings (T-07-xss-result).
				filenameEl.textContent = f.filename || ('ID ' + f.id);
				li.appendChild(filenameEl);
				li.appendChild(document.createTextNode(' (ID ' + f.id + ') — ' + (f.reason || 'Unknown error.')));
				ul.appendChild(li);
			});
			frag.appendChild(ul);
		}
		var doneBtn = document.createElement('button');
		doneBtn.type = 'button';
		doneBtn.className = 'button ad-bulk-done';
		doneBtn.textContent = 'Close results';
		frag.appendChild(doneBtn);
		// Replace content safely.
		while(resultEl.firstChild){ resultEl.removeChild(resultEl.firstChild); }
		resultEl.appendChild(frag);
	}

	document.addEventListener('click', function(e){
		if(!e.target.classList.contains('ad-bulk-apply')) return;
		var op = currentOp;
		if(!op) return;

		var panel = document.querySelector('.ad-bulk-panel');
		if(!panel) return;

		// Disable apply during flight (no double-submit).
		e.target.disabled = true;

		var opParams = collectOpParams(op);
		var total = getSelectionCount();
		var done = 0;
		var failures = [];

		// Show progress, hide form fields and actions.
		var actionsEl  = panel.querySelector('.ad-bulk-panel-actions');
		var progressEl = panel.querySelector('.ad-bulk-progress');
		panel.querySelectorAll('.ad-bulk-op-group').forEach(function(g){ g.style.display = 'none'; });
		if(actionsEl){ actionsEl.style.display = 'none'; }
		if(progressEl){ progressEl.style.display = ''; }
		updateProgress(panel, 0, total);

		var runPromise;

		if(selectAllMatching){
			// select_all_matching mode: server re-derives ids authoritatively (D-03).
			// Loop via batch_offset until has_more is false so the FULL matching set
			// is processed — never declare N updated when only the first 50 ran (CR-02).
			var batchOffset = 0;
			var totalMatching = total > 0 ? total : null; // updated from first response.
			function runSelectAllBatch(){
				var payload = Object.assign(
					{filter_args: filterArgs, select_all_matching: true, batch_offset: batchOffset},
					opParams
				);
				return postBatch(op, payload).then(function(res){
					if(res && res.success){
						var processed = res.data.processed || 0;
						done += processed;
						(res.data.results || []).forEach(function(r){
							if(!r.ok){ failures.push(r); }
						});
						// Use server-reported total_matching for accurate progress (M in "N of M done").
						if(res.data.total_matching != null){
							totalMatching = res.data.total_matching;
						}
						updateProgress(panel, done, totalMatching > 0 ? totalMatching : done);
						batchOffset = res.data.next_offset || (batchOffset + processed);
						if(res.data.has_more){
							return runSelectAllBatch();
						}
					} else {
						failures.push({id: 0, reason: 'Server error.'});
					}
				}).catch(function(){
					failures.push({id: 0, reason: 'Bulk operation failed. Reload the page and try again.'});
				});
			}
			runPromise = runSelectAllBatch();
		} else {
			// Explicit ids mode: slice into ~50-id batches and POST sequentially (D-05 / Pitfall 2).
			var ids = Array.from(selectedIds);
			var batches = [];
			for(var i = 0; i < ids.length; i += BATCH){ batches.push(ids.slice(i, i + BATCH)); }
			if(batches.length === 0){ batches = [[]]; }

			runPromise = batches.reduce(function(chain, batch){
				return chain.then(function(){
					var payload = Object.assign({ids: batch}, opParams);
					return postBatch(op, payload).then(function(res){
						if(res && res.success){
							done += res.data.processed || 0;
							(res.data.results || []).forEach(function(r){
								if(!r.ok){ failures.push(r); }
							});
							updateProgress(panel, done, total);
						} else {
							var errMsg = (res && res.data && res.data.message) ? res.data.message : 'Server error.';
							batch.forEach(function(id){ failures.push({id: id, reason: errMsg}); });
							done += batch.length;
							updateProgress(panel, done, total);
						}
					}).catch(function(){
						batch.forEach(function(id){ failures.push({id: id, reason: 'Bulk operation failed. Reload the page and try again.'}); });
						done += batch.length;
						updateProgress(panel, done, total);
					});
				});
			}, Promise.resolve());
		}

		runPromise.then(function(){
			// For select_all_matching, report the actual number processed (done),
			// not the toolbar's pre-flight total which may differ from server-derived count.
			renderResult(panel, selectAllMatching ? done : total, failures);
		});
	});
})();
JS;

		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static script; the only dynamic values (nonce, perPage) are escaped with esc_js() / cast to int above.
		echo '<script>' . $script . '</script>';
	}

	/**
	 * Print the scoped brand styles for the Find screen.
	 *
	 * @return void
	 */
	private function print_styles(): void {
		echo '<style>
			.assetdrips-wrap{--ad-orange:#FF4200;--ad-black:#080808;color:var(--ad-black);}
			.ad-crumb{display:inline-block;margin:6px 0 2px;color:#777;text-decoration:none;font-size:13px;font-weight:600;}
			.ad-crumb:hover{color:var(--ad-orange);}
			.assetdrips-head{display:flex;align-items:baseline;gap:12px;margin:4px 0 4px;}
			.assetdrips-head h1{font-size:28px;font-weight:800;margin:0;color:var(--ad-black);}
			.assetdrips-head .ad-mod{background:var(--ad-orange);color:#fff;border-radius:999px;padding:2px 12px;font-size:12px;font-weight:700;letter-spacing:.04em;text-transform:uppercase;}
			.assetdrips-tag{color:#555;margin:0 0 18px;max-width:680px;font-size:14px;}
			.assetdrips-gap{background:#fff7f4;border:1px solid #ffd8c9;border-left:4px solid var(--ad-orange);border-radius:12px;padding:12px 16px;margin:0 0 20px;}
			.ad-empty{padding:28px;text-align:center;color:#777;background:#fff;border-radius:12px;}
			.ad-pill{display:inline-block;border-radius:999px;padding:1px 8px;font-size:11px;font-weight:700;color:#fff;}
			.assetdrips-progress{margin:0 0 22px;}
			.ad-progress-track{height:14px;background:#f0f0f0;border-radius:999px;overflow:hidden;}
			.ad-progress-fill{height:100%;width:0;background:var(--ad-orange);border-radius:999px;transition:width .35s ease;}
			.ad-progress-fill.is-indeterminate{width:100%;animation:ad-pulse 1.1s ease-in-out infinite;}
			@keyframes ad-pulse{0%{opacity:.45}50%{opacity:1}100%{opacity:.45}}
			.ad-saved-views{display:flex;align-items:center;flex-wrap:wrap;gap:8px;margin:0 0 12px;}
			.ad-saved-views-label{font-size:12px;font-weight:700;color:#666;text-transform:uppercase;letter-spacing:.04em;margin-right:4px;}
			.ad-view-pill{display:inline-flex;align-items:center;gap:4px;border-radius:999px;padding:2px 12px;font-size:12px;font-weight:700;background:#f1f1f1;color:#444;text-decoration:none;border:2px solid transparent;}
			.ad-view-pill.is-active{border-color:var(--ad-orange);color:var(--ad-orange);}
			.ad-view-delete{background:none;border:none;cursor:pointer;color:#999;padding:0 2px;font-size:14px;line-height:1;}
			.ad-view-delete:hover{color:#333;}
			.ad-save-view-trigger{font-size:12px;color:var(--ad-orange);text-decoration:none;margin-left:4px;}
			.ad-find-filters{margin:0 0 16px;}
			.ad-filter-row{display:flex;flex-wrap:wrap;align-items:center;gap:8px;margin:0 0 8px;}
			.ad-filter-row select,.ad-filter-row input[type="search"]{height:28px;}
			.ad-filter-row .button-primary{background:var(--ad-orange);border-color:var(--ad-orange);border-radius:999px;padding:4px 20px;}
			.ad-filter-toggle{font-size:12px;color:#666;text-decoration:none;display:inline-block;margin:0 0 8px;}
			.ad-staleness{margin:0 0 16px;}
			.ad-find-toolbar{display:flex;justify-content:space-between;align-items:center;margin:0 0 12px;}
			.ad-find-total{font-size:13px;font-weight:700;color:var(--ad-black);}
			.ad-find-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(160px,1fr));gap:16px;margin:0 0 20px;}
			.ad-find-card{display:block;background:#fff;border:1px solid #ececec;border-radius:16px;text-decoration:none;color:inherit;overflow:hidden;transition:transform .15s ease,box-shadow .15s ease;}
			.ad-find-card:hover{transform:translateY(-3px);box-shadow:0 10px 24px rgba(8,8,8,.10);}
			.ad-find-card:focus-visible{box-shadow:inset 0 0 0 2px var(--ad-orange);}
			.ad-find-card-thumb{width:100%;aspect-ratio:1/1;object-fit:cover;display:block;background:#f3f3f3;border-radius:12px 12px 0 0;}
			.ad-find-card-meta{padding:10px 12px;}
			.ad-find-card-filename{font-size:12px;font-weight:700;color:#080808;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;}
			.ad-find-card-dims{font-size:11px;color:#777;margin-top:2px;}
			.ad-find-card-usage{font-size:11px;color:#777;margin-top:2px;}
			.ad-find-card-badges{display:flex;flex-wrap:wrap;gap:4px;margin-top:6px;}
			.ad-find-pagination{margin:0 0 20px;}
			.ad-find-pagination .page-numbers{display:inline-block;padding:4px 8px;margin:0 2px;border-radius:999px;text-decoration:none;font-size:13px;}
			.ad-find-pagination .page-numbers.current{background:var(--ad-orange);color:#fff;}
			.ad-find-pagination .page-numbers.dots{pointer-events:none;}
			.ad-find-empty{padding:28px;text-align:center;color:#777;background:#fff;border-radius:12px;}
			.ad-find-card{position:relative;}
			.ad-select-label{position:absolute;top:8px;left:8px;z-index:2;display:flex;align-items:center;justify-content:center;width:24px;height:24px;cursor:pointer;}
			.ad-select-cb{width:18px;height:18px;cursor:pointer;accent-color:var(--ad-orange);}
			.ad-find-card.is-selected{box-shadow:inset 0 0 0 3px var(--ad-orange);}
			.ad-selection-controls{display:flex;align-items:center;gap:8px;}
			.ad-selection-count{font-size:13px;font-weight:700;color:var(--ad-black);min-width:80px;}
			.ad-select-all-matching{font-size:13px;color:#555;margin:-8px 0 16px;}
			.ad-select-all-matching-link{color:var(--ad-orange);text-decoration:none;}
			.ad-select-all-matching-link:hover{text-decoration:underline;}
			.ad-bulk-bar{display:flex;align-items:center;gap:8px;flex-wrap:wrap;padding:8px 16px;background:#fff7f4;border:1px solid #ffd8c9;border-left:4px solid var(--ad-orange);border-radius:12px;margin:0 0 16px;}
			.ad-bulk-bar-label{font-size:12px;font-weight:700;color:#555;text-transform:uppercase;letter-spacing:.04em;margin-right:4px;}
			.ad-bulk-btn{border-radius:999px;}
			.ad-bulk-panel{background:#fff;border:1px solid #ececec;border-radius:16px;padding:16px 24px;margin:0 0 16px;}
			.ad-bulk-panel-inner{max-width:480px;}
			.ad-bulk-panel-heading{font-size:14px;font-weight:700;color:var(--ad-black);margin:0 0 16px;}
			.ad-bulk-panel label{display:block;font-size:13px;font-weight:700;color:var(--ad-black);margin:0 0 4px;}
			.ad-bulk-panel input[type="text"],.ad-bulk-panel textarea,.ad-bulk-panel select{width:100%;max-width:420px;}
			.ad-bulk-panel .ad-field-note{font-size:11px;color:#777;margin:4px 0 8px;}
			.ad-bulk-panel-actions{display:flex;gap:8px;align-items:center;margin-top:16px;}
			.ad-bulk-panel-actions .button-primary{background:var(--ad-orange);border-color:var(--ad-orange);border-radius:999px;padding:4px 16px;}
			.ad-bulk-panel-actions .button{border-radius:999px;}
			.ad-bulk-progress-label{font-size:13px;font-weight:700;color:var(--ad-black);margin:0 0 8px;}
			.ad-bulk-progress-count{font-weight:700;}
			.ad-bulk-result{margin-top:8px;}
			.ad-bulk-result p{font-size:13px;color:var(--ad-black);margin:0 0 8px;}
			.ad-bulk-failures{margin:8px 0 8px;padding-left:16px;list-style:disc;}
			.ad-bulk-failure-item{font-size:12px;color:#777;margin:4px 0;}
			.ad-bulk-failure-item strong{color:var(--ad-black);}
			.ad-bulk-done{border-radius:999px;}
		</style>';
	}
}
