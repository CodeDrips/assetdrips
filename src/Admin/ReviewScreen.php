<?php
/**
 * Admin review queue screen.
 *
 * @package AssetDrips
 */

declare( strict_types=1 );

namespace AssetDrips\Admin;

use AssetDrips\Coverage\BuilderDetector;
use AssetDrips\Coverage\CoverageReport;
use AssetDrips\Db\Schema;
use AssetDrips\Quarantine\QuarantineManager;
use AssetDrips\Quarantine\RecoveryRecord;
use AssetDrips\Scan\ScanService;
use AssetDrips\Score\Tier;

defined( 'ABSPATH' ) || exit;

/**
 * The AssetDrips Sift review screen.
 *
 * Shows the tiered verdicts with their evidence and the coverage gaps that
 * shaped them, and exposes the reversible actions. HIGH is offered as a
 * self-serve quarantine; MEDIUM and LOW require an explicit override checkbox,
 * matching the trust contract. Nothing here ever deletes — quarantine moves
 * files and snapshots rows; restore is one click away.
 */
final class ReviewScreen {

	/**
	 * Page slug for the Sift module. Public so the dashboard can link to it.
	 */
	public const SLUG = 'assetdrips-sift';

	/**
	 * Capability required to view and act.
	 */
	private const CAP = 'manage_options';

	/**
	 * Nonce action for form posts.
	 */
	private const NONCE = 'assetdrips_review';

	/**
	 * Nonce action for AJAX requests.
	 */
	private const AJAX_NONCE = 'assetdrips_ajax';

	/**
	 * Scan phases in order, for the step counter shown during a scan.
	 *
	 * @var array<string, int>
	 */
	private const PHASE_STEPS = array(
		'index'    => 1,
		'content'  => 2,
		'postmeta' => 3,
		'acf'      => 4,
		'woo'      => 5,
		'options'  => 6,
		'termmeta' => 7,
		'scoring'  => 8,
	);

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'admin_menu', array( $this, 'add_menu' ) );
		add_action( 'admin_post_assetdrips_scan', array( $this, 'handle_scan' ) );
		add_action( 'admin_post_assetdrips_quarantine', array( $this, 'handle_quarantine' ) );
		add_action( 'admin_post_assetdrips_restore', array( $this, 'handle_restore' ) );
		add_action( 'wp_ajax_assetdrips_run_scan', array( $this, 'ajax_run_scan' ) );
		add_action( 'wp_ajax_assetdrips_scan_status', array( $this, 'ajax_scan_status' ) );
	}

	/**
	 * Add the Sift submenu under the AssetDrips top-level menu.
	 *
	 * @return void
	 */
	public function add_menu(): void {
		add_submenu_page(
			Dashboard::SLUG,
			'Sift — Unused Media',
			'Sift',
			self::CAP,
			self::SLUG,
			array( $this, 'render' )
		);
	}

	/**
	 * Brand accent colour for a tier.
	 *
	 * @param string $tier Tier value.
	 * @return string Hex colour.
	 */
	public static function tier_accent( string $tier ): string {
		return match ( $tier ) {
			Tier::HIGH->value   => '#FF4200',
			Tier::USED->value   => '#080808',
			Tier::MEDIUM->value => '#B26A00',
			Tier::LOW->value    => '#8A8A8A',
			default             => '#080808',
		};
	}

	/**
	 * One-line human summary of an attachment's evidence.
	 *
	 * @param array<int, array<string, mixed>> $evidence Decoded evidence hits.
	 * @return string
	 */
	public static function evidence_summary( array $evidence ): string {
		$count = count( $evidence );
		if ( 0 === $count ) {
			return 'No references found';
		}

		$sources = array();
		foreach ( $evidence as $hit ) {
			$source = (string) ( $hit['source'] ?? '' );
			if ( '' !== $source ) {
				$sources[ $source ] = true;
			}
		}
		$names = array_keys( $sources );
		sort( $names );

		return sprintf(
			'%d reference%s · %s',
			$count,
			1 === $count ? '' : 's',
			implode( ', ', $names )
		);
	}

	/**
	 * Render the review screen.
	 *
	 * @return void
	 */
	public function render(): void {
		if ( ! current_user_can( self::CAP ) ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only display filter; no state change.
		$tier = isset( $_GET['tier'] ) ? strtoupper( sanitize_text_field( wp_unslash( $_GET['tier'] ) ) ) : Tier::HIGH->value;
		if ( null === Tier::tryFrom( $tier ) ) {
			$tier = Tier::HIGH->value;
		}

		$counts   = $this->tier_counts();
		$coverage = BuilderDetector::from_wordpress();

		echo '<div class="wrap assetdrips-wrap">';
		$this->print_styles();
		$this->print_header();
		$this->print_notice();
		$this->print_actions_bar();
		$this->print_coverage( $coverage );
		$this->print_summary_cards( $counts, $tier );
		$this->print_tier_help( $tier );
		$this->print_results_table( $tier );
		$this->print_quarantined();
		echo '</div>';
	}

	/**
	 * Handle the "run scan" action.
	 *
	 * @return void
	 */
	public function handle_scan(): void {
		$this->guard();

		// A synchronous scan can run a while on large libraries; give it room. The
		// CLI (`wp assetdrips scan`) is the better path at scale and shows live progress.
		if ( function_exists( 'set_time_limit' ) ) {
			// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- May be disabled by the host; failure is non-fatal.
			@set_time_limit( 0 );
		}

		try {
			$counts  = ScanService::from_wordpress()->run();
			$message = sprintf(
				'Scan complete. USED %d, HIGH %d, MEDIUM %d, LOW %d.',
				$counts['USED'] ?? 0,
				$counts['HIGH'] ?? 0,
				$counts['MEDIUM'] ?? 0,
				$counts['LOW'] ?? 0
			);
			$this->redirect( 'success', $message );
		} catch ( \Throwable $error ) {
			$this->redirect( 'error', 'Scan failed: ' . $error->getMessage() );
		}
	}

	/**
	 * AJAX: run a scan, writing live progress for the poller to read.
	 *
	 * @return void
	 */
	public function ajax_run_scan(): void {
		if ( ! current_user_can( self::CAP ) ) {
			wp_send_json_error( array( 'message' => 'Insufficient permissions.' ), 403 );
		}
		check_ajax_referer( self::AJAX_NONCE, 'nonce' );

		if ( function_exists( 'set_time_limit' ) ) {
			// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- May be disabled by the host; failure is non-fatal.
			@set_time_limit( 0 );
		}

		$write = $this->progress_writer();
		ScanProgress::set(
			array(
				'phase'   => 'index',
				'label'   => 'Building reference index',
				'done'    => 0,
				'total'   => 0,
				'percent' => 0,
				'step'    => 1,
				'steps'   => count( self::PHASE_STEPS ),
			)
		);

		try {
			$service = ScanService::from_wordpress(
				30,
				static function ( int $indexed ) use ( $write ): void {
					$write( 'index', 'Building reference index', $indexed, null );
				}
			);

			$counts = $service->run(
				array(),
				static function ( string $event, array $payload ) use ( $write ): void {
					if ( 'scan' === $event ) {
						list( $source, $done, $total ) = $payload;
						$write( (string) $source, 'Scanning ' . (string) $source, (int) $done, $total );
					} elseif ( 'score' === $event ) {
						list( $done, $total ) = $payload;
						$write( 'scoring', 'Scoring attachments', (int) $done, (int) $total );
					}
				}
			);

			ScanProgress::clear();
			wp_send_json_success( array( 'counts' => $counts ) );
		} catch ( \Throwable $error ) {
			ScanProgress::clear();
			wp_send_json_error( array( 'message' => $error->getMessage() ) );
		}
	}

	/**
	 * AJAX: report the current scan progress.
	 *
	 * @return void
	 */
	public function ajax_scan_status(): void {
		if ( ! current_user_can( self::CAP ) ) {
			wp_send_json_error( array( 'message' => 'Insufficient permissions.' ), 403 );
		}
		check_ajax_referer( self::AJAX_NONCE, 'nonce' );

		wp_send_json_success( ScanProgress::get() );
	}

	/**
	 * Build a throttled progress-writing callback.
	 *
	 * @return callable(string, string, int, ?int):void
	 */
	private function progress_writer(): callable {
		$last_at    = 0.0;
		$last_phase = '';

		return static function ( string $phase, string $label, int $done, ?int $total ) use ( &$last_at, &$last_phase ): void {
			$now     = microtime( true );
			$changed = $phase !== $last_phase;
			if ( ! $changed && ( $now - $last_at ) < 0.4 ) {
				return;
			}
			$last_at    = $now;
			$last_phase = $phase;

			$percent = ( null !== $total && $total > 0 ) ? (int) min( 100, round( $done / $total * 100 ) ) : 0;

			ScanProgress::set(
				array(
					'phase'   => $phase,
					'label'   => $label,
					'done'    => $done,
					'total'   => (int) $total,
					'percent' => $percent,
					'step'    => self::PHASE_STEPS[ $phase ] ?? 0,
					'steps'   => count( self::PHASE_STEPS ),
				)
			);
		};
	}

	/**
	 * Handle a quarantine action. HIGH is self-serve; MEDIUM/LOW need override.
	 *
	 * @return void
	 */
	public function handle_quarantine(): void {
		$this->guard();

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified in guard().
		$attachment_id = isset( $_POST['attachment_id'] ) ? absint( wp_unslash( $_POST['attachment_id'] ) ) : 0;
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified in guard().
		$row_tier = isset( $_POST['tier'] ) ? strtoupper( sanitize_text_field( wp_unslash( $_POST['tier'] ) ) ) : '';
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified in guard().
		$override = isset( $_POST['confirm_override'] );

		if ( 0 === $attachment_id ) {
			$this->redirect( 'error', 'No attachment specified.' );
		}

		$tier = Tier::tryFrom( $row_tier );
		if ( null === $tier || ! $tier->is_candidate() ) {
			$this->redirect( 'error', 'That attachment is in use and cannot be quarantined.' );
		}

		if ( ! $tier->is_self_serve() && ! $override ) {
			$this->redirect( 'error', 'MEDIUM and LOW require explicit override confirmation.' );
		}

		try {
			$record_id = QuarantineManager::from_wordpress()->quarantine( $attachment_id );
			$this->redirect( 'success', sprintf( 'Quarantined attachment #%d (recovery #%d). Restore any time.', $attachment_id, $record_id ) );
		} catch ( \Throwable $error ) {
			$this->redirect( 'error', 'Quarantine failed: ' . $error->getMessage() );
		}
	}

	/**
	 * Handle a restore action.
	 *
	 * @return void
	 */
	public function handle_restore(): void {
		$this->guard();

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified in guard().
		$record_id = isset( $_POST['record_id'] ) ? absint( wp_unslash( $_POST['record_id'] ) ) : 0;
		if ( 0 === $record_id ) {
			$this->redirect( 'error', 'No recovery record specified.' );
		}

		try {
			QuarantineManager::from_wordpress()->restore( $record_id );
			$this->redirect( 'success', sprintf( 'Restored recovery #%d.', $record_id ) );
		} catch ( \Throwable $error ) {
			$this->redirect( 'error', 'Restore failed: ' . $error->getMessage() );
		}
	}

	/**
	 * Verify capability and nonce for an action request.
	 *
	 * @return void
	 */
	private function guard(): void {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( 'Insufficient permissions.' );
		}
		check_admin_referer( self::NONCE );
	}

	/**
	 * Redirect back to the screen with a notice.
	 *
	 * @param string $type    success|error.
	 * @param string $message Notice text.
	 * @return void
	 */
	private function redirect( string $type, string $message ): void {
		$url = add_query_arg(
			array(
				'page'                   => self::SLUG,
				'assetdrips_notice'      => rawurlencode( $message ),
				'assetdrips_notice_type' => $type,
			),
			admin_url( 'admin.php' )
		);

		wp_safe_redirect( $url );
		exit;
	}

	/**
	 * Tier counts from the results table.
	 *
	 * @return array<string, int>
	 */
	private function tier_counts(): array {
		global $wpdb;
		$table = Schema::results_table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared -- Aggregate read of scan results; table name from Schema.
		$rows = (array) $wpdb->get_results( 'SELECT tier, COUNT(*) AS n FROM ' . $table . ' GROUP BY tier', ARRAY_A );

		$counts = array(
			Tier::USED->value   => 0,
			Tier::HIGH->value   => 0,
			Tier::MEDIUM->value => 0,
			Tier::LOW->value    => 0,
		);
		foreach ( $rows as $row ) {
			$counts[ (string) $row['tier'] ] = (int) $row['n'];
		}

		return $counts;
	}

	/**
	 * Fetch result rows for one tier, joined to post titles.
	 *
	 * @param string $tier  Tier value.
	 * @param int    $limit Maximum rows.
	 * @return array<int, array<string, mixed>>
	 */
	private function results_for_tier( string $tier, int $limit = 100 ): array {
		global $wpdb;
		$results = Schema::results_table();
		$posts   = $wpdb->posts;

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table names come from Schema/wpdb, not input; tier and limit are placeholders.
		$sql = $wpdb->prepare(
			"SELECT r.attachment_id, r.tier, r.confidence, r.evidence, p.post_title
			FROM {$results} r LEFT JOIN {$posts} p ON p.ID = r.attachment_id
			WHERE r.tier = %s ORDER BY r.attachment_id DESC LIMIT %d",
			$tier,
			$limit
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared -- Pre-prepared read of scan results.
		return (array) $wpdb->get_results( $sql, ARRAY_A );
	}

	/**
	 * Print the scoped brand styles.
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
			.assetdrips-tag{color:#555;margin:0 0 18px;}
			.assetdrips-cards{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:14px;margin:16px 0 18px;}
			@media (max-width:1100px){.assetdrips-cards{grid-template-columns:repeat(2,1fr);}}
			.ad-card{position:relative;background:#fff;border:1px solid #e6e6e6;border-radius:16px;border-top:4px solid #ddd;overflow:hidden;transition:transform .15s ease,box-shadow .15s ease,border-color .15s ease;}
			.ad-card a{text-decoration:none;color:inherit;display:block;padding:18px 20px;cursor:pointer;outline:none;}
			.ad-card:hover{transform:translateY(-3px);box-shadow:0 10px 24px rgba(8,8,8,.10);border-color:#dcdcdc;}
			.ad-card a:focus-visible{box-shadow:inset 0 0 0 2px var(--ad-orange);border-radius:13px;}
			.ad-card.is-active{box-shadow:0 0 0 2px var(--ad-orange);}
			.ad-card .ad-num{font-size:34px;font-weight:800;line-height:1;}
			.ad-card .ad-lbl{font-size:13px;font-weight:800;letter-spacing:.05em;text-transform:uppercase;margin-top:8px;display:flex;align-items:center;gap:7px;}
			.ad-card .ad-lbl::before{content:"";width:9px;height:9px;border-radius:50%;background:currentColor;flex:none;}
			.ad-card .ad-sub{color:#666;font-size:12px;margin-top:5px;}
			.ad-card .ad-cta{margin-top:12px;font-size:12px;font-weight:700;color:var(--ad-orange);opacity:0;transform:translateX(-4px);transition:opacity .15s ease,transform .15s ease;}
			.ad-card:hover .ad-cta{opacity:1;transform:none;}
			.ad-card.is-active .ad-cta{opacity:1;transform:none;color:#999;}
			.ad-help{display:flex;gap:14px;align-items:flex-start;background:#fff;border:1px solid #ececec;border-left:4px solid #ddd;border-radius:14px;padding:16px 20px;margin:0 0 22px;}
			.ad-help .dashicons{font-size:22px;width:22px;height:22px;flex:none;margin-top:2px;}
			.ad-help h3{margin:0 0 5px;font-size:15px;font-weight:800;}
			.ad-help p{margin:0 0 10px;color:#444;font-size:13px;line-height:1.55;max-width:760px;}
			.ad-help .ad-check{font-size:13px;color:#555;background:#fafafa;border:1px solid #f0f0f0;border-radius:8px;padding:9px 13px;line-height:1.5;max-width:760px;}
			.ad-help .ad-check b{color:#222;}
			.assetdrips-bar{display:flex;align-items:center;gap:14px;margin:8px 0 20px;}
			.assetdrips-bar .button-primary{background:var(--ad-orange);border-color:var(--ad-orange);border-radius:999px;padding:4px 20px;}
			.assetdrips-gap{background:#fff7f4;border:1px solid #ffd8c9;border-left:4px solid var(--ad-orange);border-radius:12px;padding:12px 16px;margin:0 0 20px;}
			.assetdrips-gap.minor{background:#fbfbfb;border-left-color:#bbb;border-color:#eee;}
			.assetdrips-gap b{display:block;margin-bottom:4px;}
			.assetdrips-gap ul{margin:6px 0 0;}
			table.assetdrips-table{border-collapse:collapse;width:100%;background:#fff;border-radius:12px;overflow:hidden;}
			table.assetdrips-table th{text-align:left;background:#fafafa;border-bottom:1px solid #eee;padding:10px 12px;font-size:12px;text-transform:uppercase;letter-spacing:.04em;color:#666;}
			table.assetdrips-table td{border-bottom:1px solid #f1f1f1;padding:10px 12px;vertical-align:middle;}
			.ad-pill{display:inline-block;border-radius:999px;padding:2px 12px;font-size:12px;font-weight:700;color:#fff;}
			.ad-thumb{width:44px;height:44px;border-radius:8px;object-fit:cover;background:#f3f3f3;}
			.ad-actions .button{border-radius:999px;}
			.ad-override{font-size:12px;color:#666;margin-left:6px;}
			.ad-empty{padding:28px;text-align:center;color:#777;background:#fff;border-radius:12px;}
			.assetdrips-progress{margin:0 0 22px;max-width:640px;}
			.ad-progress-track{height:14px;background:#f0f0f0;border-radius:999px;overflow:hidden;}
			.ad-progress-fill{height:100%;width:0;background:var(--ad-orange);border-radius:999px;transition:width .35s ease;}
			.ad-progress-fill.is-indeterminate{width:100%;animation:ad-pulse 1.1s ease-in-out infinite;}
			@keyframes ad-pulse{0%{opacity:.45}50%{opacity:1}100%{opacity:.45}}
			.ad-progress-text{margin-top:8px;font-size:13px;color:#333;}
		</style>';
	}

	/**
	 * Print the branded header.
	 *
	 * @return void
	 */
	private function print_header(): void {
		$home = add_query_arg( 'page', Dashboard::SLUG, admin_url( 'admin.php' ) );
		echo '<a class="ad-crumb" href="' . esc_url( $home ) . '">&larr; AssetDrips</a>';
		echo '<div class="assetdrips-head"><h1>Sift</h1><span class="ad-mod">Unused media</span></div>';
		echo '<p class="assetdrips-tag">Unused-media detection with honest confidence. Nothing is deleted — only moved, and always reversible.</p>';
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
	 * Print the actions bar (run scan).
	 *
	 * @return void
	 */
	private function print_actions_bar(): void {
		// The form posts normally without JavaScript (full-page scan, then redirect).
		// With JavaScript, the submit is intercepted to run via AJAX with a live bar.
		echo '<div class="assetdrips-bar">';
		echo '<form id="assetdrips-scan-form" method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="assetdrips_scan" />';
		wp_nonce_field( self::NONCE );
		echo '<button type="submit" class="button button-primary" id="assetdrips-run">Run scan</button>';
		echo '</form>';
		echo '<span class="ad-sub">Very large libraries scan faster from the CLI: <code>wp assetdrips scan</code></span>';
		echo '</div>';

		echo '<div id="assetdrips-progress" class="assetdrips-progress" style="display:none;">';
		echo '<div class="ad-progress-track"><div class="ad-progress-fill" id="assetdrips-progress-fill"></div></div>';
		echo '<div class="ad-progress-text" id="assetdrips-progress-text">Starting…</div>';
		echo '</div>';

		$this->print_scan_script();
	}

	/**
	 * Print the progressive-enhancement script that drives the AJAX scan + poll.
	 *
	 * @return void
	 */
	private function print_scan_script(): void {
		$nonce = esc_js( wp_create_nonce( self::AJAX_NONCE ) );

		$script = <<<JS
(function(){
	var form = document.getElementById('assetdrips-scan-form');
	if(!form) return;
	var btn  = document.getElementById('assetdrips-run');
	var wrap = document.getElementById('assetdrips-progress');
	var fill = document.getElementById('assetdrips-progress-fill');
	var text = document.getElementById('assetdrips-progress-text');
	var nonce = '{$nonce}';
	var ajax = window.ajaxurl || '/wp-admin/admin-ajax.php';
	var poll = null;
	function fmt(n){ try { return (n||0).toLocaleString(); } catch(e){ return n; } }
	function paint(msg, pct, indeterminate){
		text.textContent = msg;
		if(indeterminate){ fill.style.width = '100%'; fill.className = 'ad-progress-fill is-indeterminate'; }
		else { fill.className = 'ad-progress-fill'; fill.style.width = (pct||0) + '%'; }
	}
	function stop(){ if(poll){ clearInterval(poll); poll = null; } }
	function status(){
		fetch(ajax + '?action=assetdrips_scan_status&nonce=' + encodeURIComponent(nonce), {credentials:'same-origin'})
			.then(function(r){ return r.json(); })
			.then(function(res){
				if(!res || !res.success || !res.data || !res.data.running) return;
				var d = res.data;
				var step = d.step ? (' (' + d.step + '/' + d.steps + ')') : '';
				var detail = d.total ? (' — ' + fmt(d.done) + ' / ' + fmt(d.total)) : (d.done ? (' — ' + fmt(d.done) + ' rows') : '');
				paint((d.label || 'Working') + step + detail, d.percent || 0, !d.total);
			}).catch(function(){});
	}
	form.addEventListener('submit', function(e){
		e.preventDefault();
		btn.disabled = true; btn.textContent = 'Scanning…';
		wrap.style.display = 'block';
		paint('Starting…', 0, true);
		poll = setInterval(status, 1500);
		var body = new FormData();
		body.append('action', 'assetdrips_run_scan');
		body.append('nonce', nonce);
		fetch(ajax, {method:'POST', credentials:'same-origin', body:body})
			.then(function(r){ return r.json(); })
			.then(function(res){
				stop();
				if(res && res.success){
					var c = (res.data && res.data.counts) || {};
					paint('Scan complete. USED ' + (c.USED||0) + ' · HIGH ' + (c.HIGH||0) + ' · MEDIUM ' + (c.MEDIUM||0) + ' · LOW ' + (c.LOW||0), 100, false);
					setTimeout(function(){ window.location.reload(); }, 1400);
				} else {
					paint('Scan failed: ' + ((res && res.data && res.data.message) || 'unknown error'), 0, false);
					btn.disabled = false; btn.textContent = 'Run scan';
				}
			})
			.catch(function(){
				stop();
				paint('The scan request was interrupted — it may still be running. Reload to check, or use the CLI for large libraries.', 0, false);
				btn.disabled = false; btn.textContent = 'Run scan';
			});
	});
})();
JS;

		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static script; the only dynamic value (nonce) is escaped with esc_js() above.
		echo '<script>' . $script . '</script>';
	}

	/**
	 * Print the coverage-gap banner.
	 *
	 * @param CoverageReport $coverage Coverage report.
	 * @return void
	 */
	private function print_coverage( CoverageReport $coverage ): void {
		if ( ! $coverage->has_gaps() ) {
			return;
		}

		$class = $coverage->has_significant_gaps() ? '' : 'minor';
		$lead  = $coverage->has_significant_gaps()
			? 'Coverage gaps are holding unreferenced media at LOW:'
			: 'Minor coverage gaps detected:';

		echo '<div class="assetdrips-gap ' . esc_attr( $class ) . '"><b>' . esc_html( $lead ) . '</b><ul>';
		foreach ( $coverage->flags() as $flag ) {
			echo '<li>' . esc_html( $flag->label() ) . '</li>';
		}
		echo '</ul></div>';
	}

	/**
	 * Print the four tier summary cards.
	 *
	 * @param array<string, int> $counts Tier counts.
	 * @param string             $active Active tier filter.
	 * @return void
	 */
	private function print_summary_cards( array $counts, string $active ): void {
		echo '<div class="assetdrips-cards">';
		foreach ( array( Tier::HIGH, Tier::MEDIUM, Tier::LOW, Tier::USED ) as $tier ) {
			$value     = $tier->value;
			$count     = $counts[ $value ] ?? 0;
			$accent    = self::tier_accent( $value );
			$sub       = $tier->is_self_serve() ? 'Self-serve delete' : ( $tier->is_candidate() ? 'Human review' : 'In use' );
			$url       = add_query_arg(
				array(
					'page' => self::SLUG,
					'tier' => $value,
				),
				admin_url( 'admin.php' )
			);
			$is_active = $value === $active;
			$cta       = $is_active ? 'Viewing' : 'View ' . $value . ' &rarr;';

			echo '<div class="ad-card' . ( $is_active ? ' is-active' : '' ) . '" style="border-top-color:' . esc_attr( $accent ) . '">';
			echo '<a href="' . esc_url( $url ) . '" aria-current="' . ( $is_active ? 'true' : 'false' ) . '">';
			echo '<div class="ad-num">' . esc_html( number_format_i18n( $count ) ) . '</div>';
			echo '<div class="ad-lbl" style="color:' . esc_attr( $accent ) . '">' . esc_html( $value ) . '</div>';
			echo '<div class="ad-sub">' . esc_html( $sub ) . '</div>';
			echo '<div class="ad-cta">' . wp_kses_post( $cta ) . '</div>';
			echo '</a></div>';
		}
		echo '</div>';
	}

	/**
	 * Human-facing guidance for a tier: what the verdict means and how to
	 * confirm it before acting. Keyed by headline / what / how.
	 *
	 * @param string $tier Tier value.
	 * @return array{icon:string,headline:string,what:string,how:string}
	 */
	public static function tier_help( string $tier ): array {
		return match ( $tier ) {
			Tier::HIGH->value => array(
				'icon'     => 'dashicons-yes-alt',
				'headline' => 'Safe to delete',
				'what'     => 'We scanned everywhere we can read — post content, custom fields, WooCommerce, site options and more — and found zero references to these files, with no coverage gaps to undermine that. They are the strongest deletion candidates.',
				'how'      => 'Glance at a file to confirm you don\'t still want it, then Quarantine. Quarantine only moves the file and snapshots its database rows, so you can restore it in one click if a page looks off.',
			),
			Tier::MEDIUM->value => array(
				'icon'     => 'dashicons-warning',
				'headline' => 'Probably unused — confirm first',
				'what'     => 'No references were found, but something makes us less certain: a minor coverage gap, or the file was uploaded recently and may not be wired up yet. We hold these back from one-click deletion on purpose.',
				'how'      => 'Search your posts and pages for the filename, and check recent or draft content. If you\'re satisfied it\'s unused, tick Override and Quarantine — it\'s still fully reversible.',
			),
			Tier::LOW->value => array(
				'icon'     => 'dashicons-shield',
				'headline' => 'Needs a human — we couldn\'t see everything',
				'what'     => 'No references were found, but a significant blind spot (such as a page builder or custom-field plugin we can\'t read yet) means we can\'t vouch for this verdict. Absence of evidence here isn\'t evidence the file is unused.',
				'how'      => 'Check the source named in the coverage banner above — open that builder or plugin and confirm the file isn\'t used there before you act.',
			),
			default => array(
				'icon'     => 'dashicons-lock',
				'headline' => 'In active use — protected',
				'what'     => 'At least one scanner found a concrete reference to these files, so they are in use somewhere on your site. AssetDrips never offers in-use media for deletion.',
				'how'      => 'Nothing to do. If you think a file is flagged as used by mistake, the Evidence column shows exactly where the reference was found.',
			),
		};
	}

	/**
	 * Print the contextual explanation panel for the active tier.
	 *
	 * @param string $tier Active tier.
	 * @return void
	 */
	private function print_tier_help( string $tier ): void {
		$accent = self::tier_accent( $tier );
		$help   = self::tier_help( $tier );

		echo '<div class="ad-help" style="border-left-color:' . esc_attr( $accent ) . '">';
		echo '<span class="dashicons ' . esc_attr( $help['icon'] ) . '" style="color:' . esc_attr( $accent ) . '"></span>';
		echo '<div>';
		echo '<h3 style="color:' . esc_attr( $accent ) . '">' . esc_html( $tier ) . ' &mdash; ' . esc_html( $help['headline'] ) . '</h3>';
		echo '<p>' . esc_html( $help['what'] ) . '</p>';
		echo '<div class="ad-check"><b>How to check:</b> ' . esc_html( $help['how'] ) . '</div>';
		echo '</div>';
		echo '</div>';
	}

	/**
	 * Print the results table for the active tier.
	 *
	 * @param string $tier Active tier.
	 * @return void
	 */
	private function print_results_table( string $tier ): void {
		$rows = $this->results_for_tier( $tier );

		echo '<h2>' . esc_html( $tier ) . ' &mdash; ' . esc_html( (string) count( $rows ) ) . ' shown</h2>';

		if ( array() === $rows ) {
			echo '<div class="ad-empty">Nothing here. Run a scan, or pick another tier above.</div>';
			return;
		}

		$tier_enum   = Tier::from( $tier );
		$is_in_use   = ! $tier_enum->is_candidate();
		$needs_force = $tier_enum->is_candidate() && ! $tier_enum->is_self_serve();
		$accent      = self::tier_accent( $tier );

		echo '<table class="assetdrips-table"><thead><tr>';
		echo '<th>Media</th><th>Confidence</th><th>Evidence</th><th>Action</th>';
		echo '</tr></thead><tbody>';

		foreach ( $rows as $row ) {
			$id       = (int) $row['attachment_id'];
			$title    = (string) ( $row['post_title'] ?? '' );
			$evidence = json_decode( (string) ( $row['evidence'] ?? '[]' ), true );
			$evidence = is_array( $evidence ) ? $evidence : array();

			echo '<tr>';

			echo '<td><div style="display:flex;align-items:center;gap:10px;">';
			$thumb = wp_get_attachment_image( $id, array( 44, 44 ), true, array( 'class' => 'ad-thumb' ) );
			echo wp_kses_post( $thumb );
			echo '<div><strong>' . esc_html( '' !== $title ? $title : ( '#' . $id ) ) . '</strong><br><span class="ad-sub">#' . esc_html( (string) $id ) . '</span></div>';
			echo '</div></td>';

			echo '<td><span class="ad-pill" style="background:' . esc_attr( $accent ) . '">' . esc_html( $tier ) . ' · ' . esc_html( (string) (int) $row['confidence'] ) . '</span></td>';

			echo '<td>' . esc_html( self::evidence_summary( $evidence ) ) . '</td>';

			echo '<td class="ad-actions">';
			if ( $is_in_use ) {
				echo '<span class="ad-sub">In use — protected</span>';
			} else {
				$this->print_quarantine_form( $id, $tier, $needs_force );
			}
			echo '</td>';

			echo '</tr>';
		}

		echo '</tbody></table>';
	}

	/**
	 * Print the quarantine form for one attachment.
	 *
	 * @param int    $attachment_id Attachment ID.
	 * @param string $tier          Tier value.
	 * @param bool   $needs_force   Whether an override checkbox is required.
	 * @return void
	 */
	private function print_quarantine_form( int $attachment_id, string $tier, bool $needs_force ): void {
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="display:inline-flex;align-items:center;gap:6px;">';
		echo '<input type="hidden" name="action" value="assetdrips_quarantine" />';
		echo '<input type="hidden" name="attachment_id" value="' . esc_attr( (string) $attachment_id ) . '" />';
		echo '<input type="hidden" name="tier" value="' . esc_attr( $tier ) . '" />';
		wp_nonce_field( self::NONCE );

		$confirm = "return confirm('Move this file to quarantine? It can be restored at any time.');";

		if ( $needs_force ) {
			echo '<label class="ad-override"><input type="checkbox" name="confirm_override" value="1" /> override</label>';
		}
		echo '<button type="submit" class="button" onclick="' . esc_attr( $confirm ) . '">Quarantine</button>';
		echo '</form>';
	}

	/**
	 * Print the quarantined recovery records with restore actions.
	 *
	 * @return void
	 */
	private function print_quarantined(): void {
		$records = QuarantineManager::from_wordpress()->list_by_status( RecoveryRecord::QUARANTINED );

		echo '<h2>Quarantined &mdash; ' . esc_html( (string) count( $records ) ) . '</h2>';

		if ( array() === $records ) {
			echo '<div class="ad-empty">No quarantined media. Anything you quarantine appears here, ready to restore.</div>';
			return;
		}

		echo '<table class="assetdrips-table"><thead><tr><th>Attachment</th><th>Recovery</th><th>Files</th><th>Action</th></tr></thead><tbody>';
		foreach ( $records as $record ) {
			echo '<tr>';
			echo '<td>#' . esc_html( (string) $record->attachment_id() ) . '</td>';
			echo '<td>#' . esc_html( (string) $record->id() ) . '</td>';
			echo '<td>' . esc_html( (string) count( $record->file_paths() ) ) . ' file(s) moved</td>';
			echo '<td class="ad-actions">';
			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="display:inline;">';
			echo '<input type="hidden" name="action" value="assetdrips_restore" />';
			echo '<input type="hidden" name="record_id" value="' . esc_attr( (string) $record->id() ) . '" />';
			wp_nonce_field( self::NONCE );
			echo '<button type="submit" class="button">Restore</button>';
			echo '</form>';
			echo '</td>';
			echo '</tr>';
		}
		echo '</tbody></table>';
	}
}
