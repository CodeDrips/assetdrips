<?php
/**
 * Admin notice + one-click backfill for an unbuilt media index.
 *
 * @package AssetDrips
 */

declare( strict_types=1 );

namespace AssetDrips\Admin;

use AssetDrips\Index\IndexBuilder;
use AssetDrips\Index\MediaIndex;

defined( 'ABSPATH' ) || exit;

/**
 * Surfaces a "Build index now" prompt when the library has media but the
 * assetdrips_media index is empty.
 *
 * Find reads the index, not wp_posts, so on a fresh install over an existing
 * library Find shows nothing until the backfill (or the reconcile cron) runs.
 * The cron is WP-Cron-driven and only fires on traffic, which makes the first
 * run unreliable on quiet or DISABLE_WP_CRON sites. This component removes that
 * dependency: it shows a notice on the AssetDrips screens and runs the batched,
 * resumable {@see IndexBuilder::backfill()} via AJAX with live progress —
 * mirroring the Sift scan's run/poll pattern ({@see ReviewScreen}) and reusing
 * {@see ScanProgress} as the shared status board.
 */
final class IndexBuildNotice {

	/**
	 * Capability required to see the notice and run the build.
	 */
	private const CAP = 'manage_options';

	/**
	 * Nonce action for the build + status AJAX endpoints.
	 */
	private const AJAX_NONCE = 'assetdrips_index_build';

	/**
	 * Admin page slugs (?page=…) the notice may appear on.
	 */
	private const SCREENS = array( Dashboard::SLUG, FindScreen::SLUG );

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'admin_notices', array( $this, 'maybe_render' ) );
		add_action( 'wp_ajax_assetdrips_index_build', array( $this, 'ajax_build' ) );
		add_action( 'wp_ajax_assetdrips_index_status', array( $this, 'ajax_status' ) );
	}

	/**
	 * Render the notice when the index is empty but the library has media.
	 *
	 * @return void
	 */
	public function maybe_render(): void {
		if ( ! current_user_can( self::CAP ) ) {
			return;
		}

		// Display gating on the admin menu routing param only — no state change.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$page = isset( $_GET['page'] ) ? sanitize_text_field( wp_unslash( $_GET['page'] ) ) : '';
		if ( ! in_array( $page, self::SCREENS, true ) ) {
			return;
		}

		// Already built — nothing to prompt (single indexed COUNT query).
		if ( MediaIndex::from_wordpress()->count_rows() > 0 ) {
			return;
		}

		$library = (int) array_sum( (array) wp_count_attachments() );
		if ( $library < 1 ) {
			return; // Genuinely empty library — no index to build.
		}

		$this->render_notice( $library );
	}

	/**
	 * AJAX: run the structural backfill, then clear the progress board.
	 *
	 * Security (mirrors ReviewScreen::ajax_run_scan): capability check first,
	 * then nonce. backfill() is resumable, so an interrupted run resumes on the
	 * next click without duplicating rows.
	 *
	 * @return void
	 */
	public function ajax_build(): void {
		if ( ! current_user_can( self::CAP ) ) {
			wp_send_json_error( array( 'message' => 'Insufficient permissions.' ), 403 );
		}
		check_ajax_referer( self::AJAX_NONCE, 'nonce' );

		if ( function_exists( 'set_time_limit' ) ) {
			// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- May be disabled by the host; failure is non-fatal.
			@set_time_limit( 0 );
		}

		try {
			$indexed = IndexBuilder::from_wordpress()->backfill();
			ScanProgress::clear();
			wp_send_json_success( array( 'indexed' => $indexed ) );
		} catch ( \Throwable $error ) {
			ScanProgress::clear();
			wp_send_json_error( array( 'message' => $error->getMessage() ) );
		}
	}

	/**
	 * AJAX: report the current backfill progress for the poller.
	 *
	 * @return void
	 */
	public function ajax_status(): void {
		if ( ! current_user_can( self::CAP ) ) {
			wp_send_json_error( array( 'message' => 'Insufficient permissions.' ), 403 );
		}
		check_ajax_referer( self::AJAX_NONCE, 'nonce' );

		wp_send_json_success( ScanProgress::get() );
	}

	/**
	 * Print the notice markup, scoped styles, and the run/poll script.
	 *
	 * @param int $library Total attachments in the media library.
	 * @return void
	 */
	private function render_notice( int $library ): void {
		$nonce = esc_js( wp_create_nonce( self::AJAX_NONCE ) );
		$count = number_format_i18n( $library );

		echo '<div class="notice notice-warning ad-index-notice">';
		echo '<p><strong>AssetDrips:</strong> your media index isn\'t built yet. '
			. 'Find searches a fast index of your ' . esc_html( $count ) . ' media file'
			. ( 1 === $library ? '' : 's' )
			. ' — build it once to start searching and filtering.</p>';

		echo '<p style="display:flex;align-items:center;gap:12px;flex-wrap:wrap;">';
		echo '<button type="button" class="button button-primary" id="ad-index-build-btn">Build index now</button>';
		echo '<span id="ad-index-progress" class="ad-index-progress" style="display:none;">';
		echo '<span class="ad-index-track"><span class="ad-index-fill" id="ad-index-fill"></span></span>';
		echo '<span id="ad-index-text" class="ad-index-text"></span>';
		echo '</span>';
		echo '</p>';

		echo '<style>
			.ad-index-notice .ad-index-progress{align-items:center;gap:10px;display:inline-flex;}
			.ad-index-notice .ad-index-track{width:200px;height:8px;background:#eee;border-radius:999px;overflow:hidden;}
			.ad-index-notice .ad-index-fill{display:block;height:100%;width:0;background:#FF4200;transition:width .3s ease;}
			.ad-index-notice .ad-index-fill.is-indeterminate{width:100% !important;opacity:.5;}
			.ad-index-notice .ad-index-text{font-size:12px;color:#555;}
		</style>';

		$script = <<<JS
(function(){
	var btn  = document.getElementById('ad-index-build-btn');
	if(!btn) return;
	var wrap = document.getElementById('ad-index-progress');
	var fill = document.getElementById('ad-index-fill');
	var text = document.getElementById('ad-index-text');
	var nonce = '{$nonce}';
	var ajax = window.ajaxurl || '/wp-admin/admin-ajax.php';
	var poll = null;
	function fmt(n){ try { return (n||0).toLocaleString(); } catch(e){ return n; } }
	function paint(msg, pct, indeterminate){
		if(text){ text.textContent = msg; }
		if(!fill){ return; }
		if(indeterminate){ fill.className = 'ad-index-fill is-indeterminate'; }
		else { fill.className = 'ad-index-fill'; fill.style.width = (pct||0) + '%'; }
	}
	function stop(){ if(poll){ clearInterval(poll); poll = null; } }
	function status(){
		fetch(ajax + '?action=assetdrips_index_status&nonce=' + encodeURIComponent(nonce), {credentials:'same-origin'})
			.then(function(r){ return r.json(); })
			.then(function(res){
				if(!res || !res.success || !res.data || !res.data.running) return;
				var d = res.data;
				var detail = d.total ? (' — ' + fmt(d.done) + ' / ' + fmt(d.total)) : '';
				paint((d.label || 'Indexing') + detail, d.percent || 0, !d.total);
			}).catch(function(){});
	}
	btn.addEventListener('click', function(e){
		e.preventDefault();
		btn.disabled = true; btn.textContent = 'Building…';
		if(wrap){ wrap.style.display = 'inline-flex'; }
		paint('Starting…', 0, true);
		poll = setInterval(status, 1500);
		var body = new FormData();
		body.append('action', 'assetdrips_index_build');
		body.append('nonce', nonce);
		fetch(ajax, {method:'POST', credentials:'same-origin', body:body})
			.then(function(r){ return r.json(); })
			.then(function(res){
				stop();
				if(res && res.success){
					paint('Indexed ' + fmt((res.data && res.data.indexed) || 0) + ' files. Reloading…', 100, false);
					setTimeout(function(){ window.location.reload(); }, 1200);
				} else {
					paint('Build failed: ' + ((res && res.data && res.data.message) || 'unknown error'), 0, false);
					btn.disabled = false; btn.textContent = 'Build index now';
				}
			})
			.catch(function(){
				stop();
				paint('The build was interrupted — it may still be running. Reload to check, or run "wp assetdrips index" for very large libraries.', 0, false);
				btn.disabled = false; btn.textContent = 'Build index now';
			});
	});
})();
JS;

		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static script; the only dynamic value (nonce) is escaped with esc_js() above.
		echo '<script>' . $script . '</script>';
	}
}
