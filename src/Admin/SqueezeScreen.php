<?php
/**
 * Admin Squeeze screen — image optimization settings.
 *
 * @package AssetDrips
 */

declare( strict_types=1 );

namespace AssetDrips\Admin;

use AssetDrips\Admin\FindScreen;
use AssetDrips\Db\Schema;
use AssetDrips\Squeeze\BackupManager;
use AssetDrips\Squeeze\BackupRecord;
use AssetDrips\Squeeze\CapabilityProbe;
use AssetDrips\Squeeze\LossyAck;
use AssetDrips\Squeeze\OptimizationIndex;
use AssetDrips\Squeeze\SizesAuditJob;
use AssetDrips\Squeeze\SqueezeEngine;
use AssetDrips\Squeeze\SqueezeServing;
use AssetDrips\Squeeze\SqueezeSettings;

defined( 'ABSPATH' ) || exit;

/**
 * The AssetDrips Squeeze settings screen.
 *
 * Registers an add_submenu_page under the AssetDrips top-level menu (D-07),
 * renders the full settings form per UI-SPEC (FND-01..FND-06), and provides
 * three admin-post handlers — settings save, capability re-check, and lossy
 * acknowledgement — each gated by capability + nonce (T-08-06, T-08-07).
 *
 * CapabilityProbe::get() is called ONLY inside render() — never in register(),
 * add_menu(), or any constructor (Pitfall 5 / T-08-10 / D-06).
 */
final class SqueezeScreen {

	/**
	 * Page slug. Public so other screens can deep-link into Squeeze.
	 */
	public const SLUG = 'assetdrips-squeeze';

	/**
	 * Capability required to view and act (T-08-07).
	 */
	private const CAP = 'manage_options';

	/**
	 * Optional injected database handle for testing.
	 *
	 * Production callers leave this null; BackupManager::from_wordpress() is used
	 * instead. Unit tests pass an anonymous $wpdb stub to observe query behaviour.
	 *
	 * @var object|null
	 */
	private ?object $wpdb;

	/**
	 * Constructor — accepts an optional $wpdb stub for unit testing.
	 *
	 * Production callers omit the parameter; BackupManager is constructed via
	 * BackupManager::from_wordpress() inside render(). Tests inject a stub so
	 * the render path exercises the query without loading WordPress.
	 *
	 * @param object|null $wpdb Optional database handle for testing.
	 */
	public function __construct( ?object $wpdb = null ) {
		$this->wpdb = $wpdb;
	}

	/**
	 * Return the SQL template used for the backup disk-usage aggregate query.
	 *
	 * Exposed as a public method so unit tests can assert the query touches
	 * original_bytes without needing a full WordPress bootstrap (BAK-04).
	 *
	 * @return string SQL template string containing original_bytes.
	 */
	public function get_backup_usage_query(): string {
		return 'SELECT SUM(original_bytes) FROM {table} WHERE status = %s';
	}

	/**
	 * Register hooks.
	 *
	 * admin_menu hook registers the submenu page. Three admin_post hooks
	 * register the form handlers. CapabilityProbe is NOT called here (Pitfall 5).
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'admin_menu', array( $this, 'add_menu' ) );
		// Render PRG notices on any admin screen (not just this page), so single
		// optimize/restore actions that return to the Media Library still confirm.
		add_action( 'admin_notices', array( $this, 'print_notice' ) );
		add_action( 'admin_post_assetdrips_squeeze_settings_save', array( $this, 'handle_settings_save' ) );
		add_action( 'admin_post_assetdrips_squeeze_recheck', array( $this, 'handle_recheck' ) );
		add_action( 'admin_post_assetdrips_squeeze_lossy_ack', array( $this, 'handle_lossy_ack' ) );
		add_action( 'admin_post_assetdrips_squeeze_purge_backup', array( $this, 'handle_purge_backup' ) );
		add_action( 'admin_post_assetdrips_squeeze_purge_all_backups', array( $this, 'handle_purge_all_backups' ) );
		add_action( 'admin_post_assetdrips_squeeze_run_audit', array( $this, 'handle_run_audit' ) );
		add_action( 'admin_post_assetdrips_squeeze_regenerate_sizes', array( $this, 'handle_regenerate_sizes' ) );
		add_action( 'admin_post_assetdrips_squeeze_single_optimize', array( $this, 'handle_single_optimize' ) );
		add_action( 'admin_post_assetdrips_squeeze_single_restore', array( $this, 'handle_single_restore' ) );
	}

	/**
	 * Add the Squeeze submenu under the AssetDrips top-level menu (D-07).
	 *
	 * @return void
	 */
	public function add_menu(): void {
		add_submenu_page(
			Dashboard::SLUG,
			'Squeeze — Image Optimization Settings',
			'Squeeze',
			self::CAP,
			self::SLUG,
			array( $this, 'render' )
		);
	}

	/**
	 * Render the Squeeze settings screen.
	 *
	 * Loads settings and probes capabilities LAZILY here (Pitfall 5 / D-06).
	 * CapabilityProbe::get() is never called outside this method.
	 *
	 * @return void
	 */
	public function render(): void {
		if ( ! current_user_can( self::CAP ) ) {
			return;
		}

		$settings = SqueezeSettings::load();
		// Lazy probe — called ONLY in render(), never in register/add_menu/constructor (Pitfall 5).
		$caps = CapabilityProbe::get();
		$ack  = LossyAck::is_acknowledged();

		echo '<div class="wrap assetdrips-wrap">';
		$this->print_styles();
		$this->print_header();
		// Notice is rendered via the admin_notices hook (see register()), so it
		// is NOT printed inline here — printing both would double-render it.

		// ── Dashboard band (D-01): renders ABOVE the settings form. ───────────
		$this->print_dashboard_band();

		// ── Settings form (Sections 1–4 + submit) ─────────────────────────────
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="assetdrips_squeeze_settings_save" />';
		wp_nonce_field( 'assetdrips_squeeze_settings_save' );

		// ── Section 1: Compression Quality (FND-01, FND-02, FND-03) ───────────
		echo '<h2 class="title">Compression Quality</h2>';
		echo '<table class="form-table" role="presentation">';

		// Row: Quality preset (FND-01)
		echo '<tr>';
		echo '<th scope="row">Quality preset</th>';
		echo '<td>';
		foreach ( array(
			'conservative' => array(
				'label' => 'Conservative',
				'badge' => '(quality: 88)',
			),
			'balanced'     => array(
				'label' => 'Balanced',
				'badge' => '(quality: 82)',
			),
			'aggressive'   => array(
				'label' => 'Aggressive',
				'badge' => '(quality: 75)',
			),
			'custom'       => array(
				'label' => 'Custom&hellip;',
				'badge' => '(enter below)',
			),
		) as $key => $info ) {
			echo '<label style="display:inline-block;margin-right:16px;">';
			echo '<input type="radio" name="preset" value="' . esc_attr( $key ) . '"'
				. checked( $settings->preset, $key, false ) . ' />';
			echo ' ' . $info['label'];
			echo ' <span class="ad-squeeze-preset-badge">' . esc_html( $info['badge'] ) . '</span>';
			echo '</label>';
		}
		echo '</td>';
		echo '</tr>';

		// Row: Custom JPEG quality (always visible; JS toggles visibility based on preset)
		echo '<tr class="ad-squeeze-custom-quality"' . ( 'custom' !== $settings->preset ? ' style="display:none;"' : '' ) . '>';
		echo '<th scope="row"><label for="squeeze_jpeg_quality">JPEG quality</label></th>';
		echo '<td>';
		echo '<input type="number" id="squeeze_jpeg_quality" name="jpeg_quality" min="1" max="100" value="'
			. esc_attr( (string) $settings->jpeg_quality ) . '" style="width:80px;" />';
		echo '<p class="description">Custom JPEG quality (1–100). Only applies when Custom preset is selected.</p>';
		echo '</td>';
		echo '</tr>';

		// Row: PNG mode — always lossless (FND-02)
		echo '<tr>';
		echo '<th scope="row">PNG mode</th>';
		echo '<td>';
		echo '<span style="font-weight:600;">Lossless (always)</span>';
		echo '<p class="description">PNG files are always compressed losslessly. Lossy PNG is never applied.</p>';
		echo '</td>';
		echo '</tr>';

		// Row: Max dimension (FND-03)
		echo '<tr>';
		echo '<th scope="row"><label for="squeeze_max_dimension">Max dimension</label></th>';
		echo '<td>';
		echo '<input type="number" id="squeeze_max_dimension" name="max_dimension" min="100" max="10000" value="'
			. esc_attr( (string) $settings->max_dimension ) . '" style="width:100px;" /> px';
		echo '<p class="description">Originals wider or taller than this value will be resized down. Default: 2560 px (matches WordPress&rsquo;s own big-image scaling).</p>';
		echo '</td>';
		echo '</tr>';

		echo '</table>';

		// ── Section 2: Operations (FND-04, FND-05) ────────────────────────────
		echo '<h2 class="title">Operations</h2>';
		echo '<table class="form-table" role="presentation">';

		// Recompress originals
		echo '<tr>';
		echo '<th scope="row">Recompress originals</th>';
		echo '<td>';
		echo '<label>';
		echo '<input type="checkbox" name="enable_recompress" value="1"'
			. checked( $settings->enable_recompress, true, false ) . ' />';
		echo ' Re-compress JPEG/PNG originals';
		echo '</label>';
		echo '</td>';
		echo '</tr>';

		// Generate WebP (capability-gated FND-05)
		$webp_disabled = ! $caps['webp'];
		echo '<tr>';
		echo '<th scope="row">Generate WebP</th>';
		echo '<td>';
		echo '<fieldset' . ( $webp_disabled ? ' disabled' : '' ) . '>';
		echo '<label>';
		echo '<input type="checkbox" name="enable_webp" value="1"'
			. checked( $settings->enable_webp, true, false )
			. ( $webp_disabled ? ' disabled' : '' ) . ' />';
		echo ' Generate WebP alternates';
		echo '</label>';
		if ( $webp_disabled ) {
			echo '<p class="description" style="color:#a00;">'
				. esc_html( 'WebP encoding is not available on this server. Requires GD with WebP support (standard since PHP 5.5 + libwebp) or ImageMagick with WebP support.' )
				. '</p>';
		}
		echo '</fieldset>';
		echo '</td>';
		echo '</tr>';

		// Generate AVIF (capability-gated FND-05)
		$avif_disabled = ! $caps['avif'];
		echo '<tr>';
		echo '<th scope="row">Generate AVIF</th>';
		echo '<td>';
		echo '<fieldset' . ( $avif_disabled ? ' disabled' : '' ) . '>';
		echo '<label>';
		echo '<input type="checkbox" name="enable_avif" value="1"'
			. checked( $settings->enable_avif, true, false )
			. ( $avif_disabled ? ' disabled' : '' ) . ' />';
		echo ' Generate AVIF alternates';
		echo '</label>';
		if ( $avif_disabled ) {
			echo '<p class="description" style="color:#a00;">'
				. esc_html( 'AVIF encoding is not available on this server. Requires ImageMagick 7+ with libheif AV1 encoder, or GD on PHP 8.1+ compiled with libavif. The check uses a live encode probe — if AVIF is listed as supported but this shows unavailable, your server\'s libheif lacks an AV1 backend.' )
				. '</p>';
		}
		echo '</fieldset>';
		echo '</td>';
		echo '</tr>';

		// Resize oversized
		echo '<tr>';
		echo '<th scope="row">Resize oversized originals</th>';
		echo '<td>';
		echo '<label>';
		echo '<input type="checkbox" name="enable_resize" value="1"'
			. checked( $settings->enable_resize, true, false ) . ' />';
		echo ' Resize originals exceeding the max dimension';
		echo '</label>';
		echo '</td>';
		echo '</tr>';

		// Enable next-gen serving (SRV-03, D-06). Toggle rides the existing settings form —
		// handle_settings_save() → SqueezeSettings::sanitize() already maps enable_serving (line 245).
		// No separate nonce or save handler is needed (T-12-06).
		echo '<tr>';
		echo '<th scope="row">Enable next-gen serving</th>';
		echo '<td>';
		echo '<label>';
		echo '<input type="checkbox" name="enable_serving" value="1"'
			. checked( $settings->enable_serving, true, false ) . ' />';
		echo ' Enable next-gen serving';
		echo '</label>';
		if ( $settings->enable_serving ) {
			echo '<p class="description">'
				. esc_html( 'Serving is on. Browsers that accept WebP or AVIF will receive the appropriate sibling file. The original URL is preserved.' )
				. '</p>';
		} else {
			echo '<p class="description">'
				. esc_html( 'Serving is off. Enable to serve WebP/AVIF siblings to supported browsers via the WordPress image API.' )
				. '</p>';
		}
		echo '</td>';
		echo '</tr>';

		echo '</table>';

		// ── Section 3: Auto-on-Upload (FND-04) ────────────────────────────────
		echo '<h2 class="title">Auto-optimize on Upload</h2>';
		echo '<p class="description" style="margin-bottom:12px;">All triggers default to off. When enabled, optimization runs asynchronously after each new upload &mdash; it never delays the upload response.</p>';
		echo '<table class="form-table" role="presentation">';

		// Auto-recompress
		echo '<tr>';
		echo '<th scope="row">Auto-recompress on upload</th>';
		echo '<td>';
		echo '<label>';
		echo '<input type="checkbox" name="auto_recompress" value="1"'
			. checked( $settings->auto_recompress, true, false ) . ' />';
		echo ' Automatically re-compress new uploads';
		echo '</label>';
		echo '</td>';
		echo '</tr>';

		// Auto-WebP (capability-gated)
		echo '<tr>';
		echo '<th scope="row">Auto-generate WebP</th>';
		echo '<td>';
		echo '<fieldset' . ( $webp_disabled ? ' disabled' : '' ) . '>';
		echo '<label>';
		echo '<input type="checkbox" name="auto_webp" value="1"'
			. checked( $settings->auto_webp, true, false )
			. ( $webp_disabled ? ' disabled' : '' ) . ' />';
		echo ' Automatically generate WebP on upload';
		echo '</label>';
		if ( $webp_disabled ) {
			echo '<p class="description" style="color:#a00;">'
				. esc_html( 'WebP encoding is not available on this server. Requires GD with WebP support (standard since PHP 5.5 + libwebp) or ImageMagick with WebP support.' )
				. '</p>';
		}
		echo '</fieldset>';
		echo '</td>';
		echo '</tr>';

		// Auto-AVIF (capability-gated)
		echo '<tr>';
		echo '<th scope="row">Auto-generate AVIF</th>';
		echo '<td>';
		echo '<fieldset' . ( $avif_disabled ? ' disabled' : '' ) . '>';
		echo '<label>';
		echo '<input type="checkbox" name="auto_avif" value="1"'
			. checked( $settings->auto_avif, true, false )
			. ( $avif_disabled ? ' disabled' : '' ) . ' />';
		echo ' Automatically generate AVIF on upload';
		echo '</label>';
		if ( $avif_disabled ) {
			echo '<p class="description" style="color:#a00;">'
				. esc_html( 'AVIF encoding is not available on this server. Requires ImageMagick 7+ with libheif AV1 encoder, or GD on PHP 8.1+ compiled with libavif. The check uses a live encode probe — if AVIF is listed as supported but this shows unavailable, your server\'s libheif lacks an AV1 backend.' )
				. '</p>';
		}
		echo '</fieldset>';
		echo '</td>';
		echo '</tr>';

		// Auto-resize
		echo '<tr>';
		echo '<th scope="row">Auto-resize on upload</th>';
		echo '<td>';
		echo '<label>';
		echo '<input type="checkbox" name="auto_resize" value="1"'
			. checked( $settings->auto_resize, true, false ) . ' />';
		echo ' Automatically resize oversized new uploads';
		echo '</label>';
		echo '</td>';
		echo '</tr>';

		echo '</table>';

		// Submit button — closes the settings form before the ack panel so the
		// acknowledgement cannot be silently recorded as a side-effect of saving
		// unrelated settings (WR-04). The ack panel is its own form below.
		echo '<p class="submit">';
		echo '<input type="submit" class="button button-primary ad-squeeze-save" value="Save Settings" />';
		echo '</p>';

		echo '</form>';

		// ── Section 4: Lossy Acknowledgement (FND-06) ─────────────────────────
		// Rendered OUTSIDE the settings form so acknowledging lossy ops is an
		// explicit gesture via its own submit, never a side-effect of Save Settings.
		if ( ! $ack ) {
			// Not yet acknowledged — show the warning panel with its own form.
			echo '<div class="notice notice-warning inline ad-squeeze-ack-panel" style="padding:12px 16px;margin:16px 0;">';
			echo '<h3 style="margin-top:0;">Lossy Processing Acknowledgement</h3>';
			echo '<p>Recompression permanently reduces JPEG quality. While the original file is backed up before any operation, you should understand that bulk lossy recompression across your library cannot be undone without restoring from backup one file at a time.</p>';
			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
			echo '<input type="hidden" name="action" value="assetdrips_squeeze_lossy_ack" />';
			echo '<input type="hidden" name="lossy_ack_action" value="record" />';
			wp_nonce_field( 'assetdrips_squeeze_lossy_ack' );
			echo '<label>';
			echo '<input type="checkbox" name="lossy_ack_consent" value="1" id="ad-squeeze-ack-checkbox" />';
			echo ' I understand. Enable lossy bulk operations.';
			echo '</label>';
			echo '<p>';
			echo '<input type="submit" class="button" value="Acknowledge" id="ad-squeeze-ack-submit" disabled />';
			echo '</p>';
			echo '</form>';
			echo '</div>';
		} else {
			// Already acknowledged — show confirmation line only (revoke link is below).
			$ack_data = LossyAck::get();
			$ack_date = '';
			$ack_user = '';
			if ( is_array( $ack_data ) ) {
				$ack_date = esc_html( $ack_data['acknowledged_at'] ?? '' );
				$user_obj = get_userdata( (int) ( $ack_data['user_id'] ?? 0 ) );
				$ack_user = $user_obj ? esc_html( $user_obj->display_name ) : '';
			}
			echo '<p class="description ad-squeeze-ack-done" style="margin:16px 0;">';
			echo '<span class="dashicons dashicons-yes-alt" style="color:#46b450;vertical-align:middle;"></span> ';
			if ( $ack_date && $ack_user ) {
				echo 'Lossy processing acknowledged on ' . $ack_date . ' by ' . $ack_user . '.';
			} else {
				echo 'Lossy processing acknowledged.';
			}
			echo '</p>';
		}

		// ── Section 5: Server Capabilities (outside settings form) ────────────
		echo '<h2 class="title">Server Capabilities</h2>';
		echo '<div class="ad-squeeze-caps">';

		// Image editor row
		$editor_label = $caps['imagick'] ? 'ImageMagick' : 'GD';
		if ( $caps['imagick'] ) {
			// Use the static Imagick::getVersion() to avoid instantiating an Imagick
			// object on every settings-page render. The constructor can throw on broken
			// installs; the try/catch here makes the render safe.
			$im_version = 'version unknown';
			try {
				$v          = \Imagick::getVersion();
				$im_version = $v['versionString'] ?? 'version unknown';
			} catch ( \Throwable $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch -- Intentional: a broken Imagick install must not fatal the settings page; fall back to the default string.
				$im_version = 'version unknown';
			}
			$editor_label = 'ImageMagick (' . esc_html( $im_version ) . ')';
		}
		echo '<div class="ad-squeeze-cap-row">';
		echo '<strong>Image editor:</strong>&nbsp;';
		echo '<span class="ad-squeeze-cap-supported">';
		echo '<span class="dashicons dashicons-yes-alt" style="color:#46b450;vertical-align:middle;"></span> ';
		echo esc_html( $editor_label );
		echo '</span>';
		echo '</div>';

		// WebP row
		echo '<div class="ad-squeeze-cap-row" style="margin-top:8px;">';
		echo '<strong>WebP encoding:</strong>&nbsp;';
		if ( $caps['webp'] ) {
			echo '<span class="ad-squeeze-cap-supported">';
			echo '<span class="dashicons dashicons-yes-alt" style="color:#46b450;vertical-align:middle;"></span> Supported';
			echo '</span>';
		} else {
			echo '<span class="ad-squeeze-cap-unsupported">';
			echo '<span class="dashicons dashicons-warning" style="color:#a00;vertical-align:middle;"></span> Not available';
			echo '</span>';
			echo '<p class="description" style="color:#a00;margin:4px 0 0;">'
				. esc_html( 'WebP encoding is not available on this server. Requires GD with WebP support (standard since PHP 5.5 + libwebp) or ImageMagick with WebP support.' )
				. '</p>';
		}
		echo '</div>';

		// AVIF row
		echo '<div class="ad-squeeze-cap-row" style="margin-top:8px;">';
		echo '<strong>AVIF encoding:</strong>&nbsp;';
		if ( $caps['avif'] ) {
			echo '<span class="ad-squeeze-cap-supported">';
			echo '<span class="dashicons dashicons-yes-alt" style="color:#46b450;vertical-align:middle;"></span> Supported';
			echo '</span>';
		} else {
			echo '<span class="ad-squeeze-cap-unsupported">';
			echo '<span class="dashicons dashicons-warning" style="color:#a00;vertical-align:middle;"></span> Not available';
			echo '</span>';
			echo '<p class="description" style="color:#a00;margin:4px 0 0;">'
				. esc_html( 'AVIF encoding is not available on this server. Requires ImageMagick 7+ with libheif AV1 encoder, or GD on PHP 8.1+ compiled with libavif. The check uses a live encode probe — if AVIF is listed as supported but this shows unavailable, your server\'s libheif lacks an AV1 backend.' )
				. '</p>';
		}
		echo '</div>';

		echo '</div><!-- .ad-squeeze-caps -->';

		// ── Section 6: Next-Gen Serving status/diagnostics (D-07) ────────────────
		// Rendered OUTSIDE the settings form — read-only diagnostics, same pattern
		// as Server Capabilities above. No form or nonce needed here (T-12-06).
		echo '<h2 class="title">Next-Gen Serving</h2>';
		echo '<div class="ad-squeeze-caps">';

		// Row A: serving state.
		echo '<div class="ad-squeeze-serving-status">';
		if ( $settings->enable_serving ) {
			echo '<span class="ad-squeeze-serving-active">';
			echo '<span class="dashicons dashicons-yes-alt" style="vertical-align:middle;"></span> ';
			echo esc_html( 'Active — next-gen images are being served to supported browsers.' );
			echo '</span>';
		} else {
			echo '<span class="ad-squeeze-serving-inactive">';
			echo '<span class="dashicons dashicons-minus" style="vertical-align:middle;"></span> ';
			echo esc_html( 'Off — enable the toggle above to start serving next-gen formats.' );
			echo '</span>';
		}
		echo '</div>';

		// Row B: sibling coverage.
		$counts   = OptimizationIndex::from_wordpress()->get_coverage_counts();
		$total    = $counts['total'];
		$webp_n   = $counts['webp_count'];
		$avif_n   = $counts['avif_count'];
		$webp_pct = $total > 0 ? round( $webp_n / $total * 100 ) : 0;
		$avif_pct = $total > 0 ? round( $avif_n / $total * 100 ) : 0;
		echo '<div class="ad-squeeze-coverage" style="margin-top:8px;">';
		echo '<span class="dashicons dashicons-info" style="color:#777;vertical-align:middle;"></span> ';
		if ( $total > 0 ) {
			echo esc_html(
				'WebP: ' . $webp_n . ' of ' . $total . ' images (' . $webp_pct . '%) '
				. '| AVIF: ' . $avif_n . ' of ' . $total . ' (' . $avif_pct . '%)'
			);
		} else {
			echo esc_html( 'No images indexed yet. Run optimization to generate WebP or AVIF siblings first.' );
		}
		echo '</div>';

		// Row C: detected server software.
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized,WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- Shown only inside is_admin() under manage_options; escaped via esc_html() below (T-12-07).
		$raw_software   = (string) ( $_SERVER['SERVER_SOFTWARE'] ?? '' );
		$lower_software = strtolower( $raw_software );
		if ( str_contains( $lower_software, 'litespeed' ) ) {
			$server_label = 'LiteSpeed';
		} elseif ( str_contains( $lower_software, 'nginx' ) ) {
			$server_label = 'Nginx';
		} elseif ( str_contains( $lower_software, 'apache' ) ) {
			$server_label = 'Apache';
		} else {
			$server_label = '(unknown)';
		}
		echo '<div class="ad-squeeze-serving-status" style="margin-top:8px;">';
		echo '<span class="dashicons dashicons-info" style="color:#777;vertical-align:middle;"></span> ';
		echo esc_html( 'Server: ' . $server_label );
		echo '</div>';

		// Row D: conditional cache-plugin / CDN Vary warning (D-05, T-12-07).
		// detect_cache_plugins() uses defined()-only checks — no class instantiation (T-12-05).
		$detected_plugins = ( SqueezeServing::from_wordpress() )->detect_cache_plugins();
		if ( ! empty( $detected_plugins ) ) {
			echo '<div class="assetdrips-gap" style="margin-top:12px;">';
			echo '<strong>' . esc_html( 'Page Cache Compatibility Warning' ) . '</strong>';
			echo '<p>';
			if ( in_array( 'Cloudflare', $detected_plugins, true ) ) {
				echo esc_html( 'Cloudflare detected. Cloudflare free and pro plans do not respect Vary: Accept and will cache one version of each page for all browsers. Users behind Cloudflare may receive the wrong image format. Consider disabling Cloudflare page caching for image-heavy pages, or upgrading to Cloudflare Enterprise.' );
			} elseif ( in_array( 'WP Rocket', $detected_plugins, true ) ) {
				echo esc_html( 'WP Rocket detected. WP Rocket supports a separate WebP cache variant, but does not support an AVIF variant. AVIF URLs may be served incorrectly to non-AVIF browsers from the HTML cache. Clear all caches after enabling serving.' );
			} else {
				$plugin_list = implode( ', ', array_map( 'esc_html', $detected_plugins ) );
				echo $plugin_list . esc_html( ' detected. These plugins cache HTML pages as static files and may serve the wrong image format to some browsers. All cached pages should be cleared after enabling or disabling next-gen serving.' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $plugin_list is already passed through esc_html() per element above.
			}
			echo '</p>';
			echo '</div>';
		}

		echo '</div><!-- .ad-squeeze-caps Next-Gen Serving -->';

		// ── Section 7: Sizes Audit (SIZE-01/02/03/04; D-01/D-02/D-03/D-04/D-05/D-08) ──
		// Rendered OUTSIDE the settings form — read-only aggregate readout + two
		// nonce-gated action forms.  No postmeta iteration at render time (D-02/SIZE-01).
		// id="sizes-audit" matches the missing-sizes dashboard tile anchor (DASH-07).
		echo '<h2 class="title" id="sizes-audit">Sizes Audit</h2>';
		echo '<p class="description">' . esc_html( 'Scan your library for missing, orphaned, and unused thumbnail size definitions.' ) . '</p>';

		// Read aggregate state ONCE — no per-row postmeta iteration (D-02/SIZE-01).
		$audit_summary  = OptimizationIndex::from_wordpress()->get_sizes_audit_summary();
		$audited_count  = (int) ( $audit_summary['audited_count'] ?? 0 );
		$missing_count  = (int) ( $audit_summary['missing_count'] ?? 0 );
		$orphaned_count = (int) ( $audit_summary['orphaned_count'] ?? 0 );

		// "Run sizes audit" form — triggers SizesAuditJob, supports Resume / Restart.
		$checkpoint_exists = (bool) get_option( SizesAuditJob::CHECKPOINT_OPTION );
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="margin-bottom:12px;" id="ad-squeeze-run-audit-form">';
		echo '<input type="hidden" name="action" value="assetdrips_squeeze_run_audit" />';
		wp_nonce_field( 'assetdrips_squeeze_run_audit' );
		if ( $checkpoint_exists ) {
			// Interrupted scan — offer Resume + Restart.
			echo '<input type="hidden" name="audit_restart" value="0" id="ad-squeeze-audit-restart-flag" />';
			echo '<p class="description" style="margin-bottom:6px;">'
				. esc_html( 'A previous scan was interrupted. Resume from where it left off?' )
				. '</p>';
			echo '<input type="submit" class="button" value="' . esc_attr( 'Resume' ) . '" />';
			echo ' <input type="submit" class="button" value="' . esc_attr( 'Restart' ) . '" id="ad-squeeze-audit-restart-btn" />';
		} else {
			echo '<input type="submit" class="button" value="' . esc_attr( 'Run sizes audit' ) . '" />';
		}
		echo '</form>';

		// Audit summary card.
		echo '<div class="ad-squeeze-caps">';

		if ( 0 === $audited_count ) {
			// ── Pre-scan state ─────────────────────────────────────────────────
			echo '<p class="ad-squeeze-audit-muted">';
			echo '<strong>' . esc_html( 'No audit data yet.' ) . '</strong><br />';
			echo esc_html( 'Run the sizes audit to see which attachments are missing registered sizes, which thumbnail files on disk are no longer referenced, and which registered size definitions no attachment uses.' );
			echo '</p>';
		} else {
			// ── Audit complete: issues found or all clear ──────────────────────

			// Row A: Missing-size count.
			echo '<div class="ad-squeeze-audit-stat">';
			if ( $missing_count > 0 ) {
				echo '<span class="ad-squeeze-cap-unsupported">';
				echo '<span class="dashicons dashicons-warning" style="color:#a00;vertical-align:middle;"></span> ';
				echo '<span class="ad-squeeze-audit-count">' . intval( $missing_count ) . '</span> ';
				echo esc_html( 'attachment(s) missing one or more registered sizes.' );
				echo '</span>';
			} else {
				echo '<span class="ad-squeeze-cap-supported">';
				echo '<span class="dashicons dashicons-yes-alt" style="color:#46b450;vertical-align:middle;"></span> ';
				echo esc_html( 'All registered sizes are present for all audited attachments.' );
				echo '</span>';
			}
			echo '</div>';

			// Row B: Orphan count + dry-run notice (SIZE-03/D-04 — report only).
			echo '<div class="ad-squeeze-audit-stat">';
			if ( $orphaned_count > 0 ) {
				echo '<span class="ad-squeeze-cap-unsupported">';
				echo '<span class="dashicons dashicons-warning" style="color:#a00;vertical-align:middle;"></span> ';
				echo '<span class="ad-squeeze-audit-count">' . intval( $orphaned_count ) . '</span> ';
				echo esc_html( 'attachment(s) have orphaned thumbnail files on disk.' );
				echo '</span>';
			} else {
				echo '<span class="ad-squeeze-cap-supported">';
				echo '<span class="dashicons dashicons-yes-alt" style="color:#46b450;vertical-align:middle;"></span> ';
				echo esc_html( 'No orphaned thumbnail files found.' );
				echo '</span>';
			}
			echo '</div>';

			if ( $orphaned_count > 0 ) {
				// Dry-run notice: makes clear nothing is deleted (SIZE-03/D-04/T-13-19).
				echo '<div class="assetdrips-gap" style="margin-top:8px;">';
				echo '<strong>' . esc_html( 'Report only — nothing is deleted.' ) . '</strong>';
				echo '<p>';
				echo esc_html( 'Orphaned files are listed for review. AssetDrips never auto-deletes files. Deletion support will be added in a future release after the dry-run report proves reliable.' );
				echo '</p>';
				echo '</div>';
			}

			// Row C: Unused size definitions (SIZE-04/D-05 — informational only).
			// A registered size is "unused" only when it is missing from EVERY audited
			// attachment (i.e. present for none). This is the canonical algorithm in
			// SizesAuditJob::compute_unused_definitions(); reuse it rather than re-deriving
			// here (a prior inline array_diff inverted the logic — CR-01).
			$unused_sizes = array_values( $this->get_unused_size_names() );

			if ( ! empty( $unused_sizes ) ) {
				echo '<div class="assetdrips-gap" style="margin-top:8px;">';
				echo '<strong>' . esc_html( 'Unused size definitions (informational).' ) . '</strong>';
				echo '<p>';
				echo esc_html( 'These registered size definitions are not used by any attachment in your library. They are listed here for reference only — AssetDrips never auto-disables size definitions.' );
				echo '</p>';
				echo '<ul class="ad-squeeze-audit-list">';
				foreach ( $unused_sizes as $size_name ) {
					echo '<li>' . esc_html( (string) $size_name ) . '</li>';
				}
				echo '</ul>';
				echo '</div>';
			} else {
				echo '<div class="ad-squeeze-audit-stat">';
				echo '<span class="dashicons dashicons-info" style="color:#777;vertical-align:middle;"></span> ';
				echo esc_html( 'All registered size definitions are in use.' );
				echo '</div>';
			}

			// Last-scanned timestamp (muted).
			$last_scanned = $this->get_last_scanned_date();
			if ( '' !== $last_scanned ) {
				echo '<p class="ad-squeeze-audit-muted" style="margin-top:8px;">';
				echo esc_html( 'Last scanned: ' . $last_scanned );
				echo '</p>';
			}
		}

		echo '</div><!-- .ad-squeeze-caps Sizes Audit -->';

		// "Regenerate missing sizes" form — additive bulk op (SIZE-02/D-03).
		// Disabled (rendered but hidden) when no audit has run or all clear (nothing to regenerate).
		$has_missing = $audited_count > 0 && $missing_count > 0;
		if ( $has_missing ) {
			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="margin-top:12px;" id="ad-squeeze-regenerate-form">';
			echo '<input type="hidden" name="action" value="assetdrips_squeeze_regenerate_sizes" />';
			wp_nonce_field( 'assetdrips_squeeze_regenerate_sizes' );
			echo '<input type="submit" class="button" value="' . esc_attr( 'Regenerate missing sizes' ) . '" />';
			echo '<p class="description">';
			echo esc_html( 'Generates only the missing registered sizes for each attachment. Custom crops and existing sizes are never overwritten.' );
			echo '</p>';
			echo '</form>';
		}

		// ── Section 8: Backup Storage (BAK-04) ────────────────────────────────
		// Displayed OUTSIDE the settings form — backup deletion is a destructive
		// action with its own nonce-gated form; it must not be a side-effect of
		// saving unrelated settings (WR-04).
		$backup_bytes = $this->get_total_backup_bytes();
		$backup_kb    = number_format( (int) ( $backup_bytes / 1024 ), 0 );

		echo '<h2 class="title">Backup Storage</h2>';
		echo '<p class="description">';
		echo 'Total backup disk usage: <strong>' . esc_html( $backup_kb ) . ' KB</strong>';
		echo '</p>';

		// Global delete-all-backups form with two-step checkbox confirmation.
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="margin-top:12px;" id="ad-squeeze-purge-all-form">';
		echo '<input type="hidden" name="action" value="assetdrips_squeeze_purge_all_backups" />';
		wp_nonce_field( 'assetdrips_squeeze_purge_all_backups' );
		echo '<label>';
		echo '<input type="checkbox" name="purge_all_confirm" value="1" id="ad-squeeze-purge-all-checkbox" />';
		echo ' I understand. Delete all backups permanently.';
		echo '</label>';
		echo '<p>';
		echo '<button type="submit" class="button button-secondary" id="ad-squeeze-purge-all-submit" disabled style="color:#a00;border-color:#a00;">Delete all backups</button>';
		echo '</p>';
		echo '</form>';

		// Re-check capabilities form
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="margin-top:12px;" id="ad-squeeze-recheck-form">';
		echo '<input type="hidden" name="action" value="assetdrips_squeeze_recheck" />';
		wp_nonce_field( 'assetdrips_squeeze_recheck' );
		echo '<input type="submit" id="ad-squeeze-recheck-btn" class="button" value="Re-check capabilities" />';
		echo '</form>';

		// Revoke acknowledgement form (rendered only when acknowledged)
		if ( $ack ) {
			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="margin-top:8px;">';
			echo '<input type="hidden" name="action" value="assetdrips_squeeze_lossy_ack" />';
			echo '<input type="hidden" name="lossy_ack_action" value="revoke" />';
			wp_nonce_field( 'assetdrips_squeeze_lossy_ack' );
			echo '<button type="submit" class="button-link ad-squeeze-ack-revoke-link" style="color:#a00;cursor:pointer;">Revoke acknowledgement</button>';
			echo '</form>';
		}

		$this->print_script();

		echo '</div><!-- .wrap -->';
	}

	// ── Admin-post handlers ────────────────────────────────────────────────────

	/**
	 * Handle the settings save form submission.
	 *
	 * Security: current_user_can (T-08-07) → check_admin_referer (T-08-06) →
	 * SqueezeSettings::sanitize (T-08-08) → save → PRG redirect.
	 *
	 * @return void
	 */
	public function handle_settings_save(): void {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( 'Insufficient permissions.' );
		}
		check_admin_referer( 'assetdrips_squeeze_settings_save' );

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified via check_admin_referer() above.
		$settings = SqueezeSettings::sanitize( (array) $_POST );
		// NOTE: lossy acknowledgement is intentionally NOT processed here. The ack
		// panel is its own form (action=assetdrips_squeeze_lossy_ack) so consent
		// cannot be silently recorded as a side-effect of saving unrelated settings
		// (WR-04). handle_lossy_ack() is the sole path for recording/revoking ack.

		SqueezeSettings::save( $settings );

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'              => self::SLUG,
					'assetdrips_notice' => rawurlencode( 'Settings saved.' ),
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	/**
	 * Handle the re-check capabilities form submission.
	 *
	 * Deletes the capability transient (D-06); the next render() call re-probes.
	 *
	 * @return void
	 */
	public function handle_recheck(): void {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( 'Insufficient permissions.' );
		}
		check_admin_referer( 'assetdrips_squeeze_recheck' );

		CapabilityProbe::invalidate();

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'              => self::SLUG,
					'assetdrips_notice' => rawurlencode( 'Capability check refreshed.' ),
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	/**
	 * Handle the lossy acknowledgement form submission (record or revoke).
	 *
	 * @return void
	 */
	public function handle_lossy_ack(): void {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( 'Insufficient permissions.' );
		}
		check_admin_referer( 'assetdrips_squeeze_lossy_ack' );

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- Nonce verified via check_admin_referer() above.
		$lossy_action = isset( $_POST['lossy_ack_action'] ) ? (string) $_POST['lossy_ack_action'] : 'record';
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		if ( 'revoke' === $lossy_action ) {
			LossyAck::revoke();
			$notice = 'Lossy processing acknowledgement revoked.';
		} else {
			LossyAck::record( get_current_user_id() );
			$notice = 'Lossy processing acknowledged.';
		}

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'              => self::SLUG,
					'assetdrips_notice' => rawurlencode( $notice ),
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	// ── Backup Storage handlers ───────────────────────────────────────────────

	/**
	 * Handle per-image backup deletion.
	 *
	 * Security: current_user_can (T-09-12) → check_admin_referer → action → PRG.
	 *
	 * @return void
	 */
	public function handle_purge_backup(): void {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( 'Insufficient permissions.' );
		}
		check_admin_referer( 'assetdrips_squeeze_purge_backup' );

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified via check_admin_referer() above.
		$attachment_id = isset( $_POST['attachment_id'] ) ? absint( $_POST['attachment_id'] ) : 0;

		// IN-02: show an error notice rather than a false success when no valid
		// attachment ID was submitted (e.g. attachment_id=0 or absent field).
		if ( $attachment_id <= 0 ) {
			wp_safe_redirect(
				add_query_arg(
					array(
						'page'              => self::SLUG,
						'assetdrips_notice' => rawurlencode( 'Invalid attachment ID.' ),
					),
					admin_url( 'admin.php' )
				)
			);
			exit;
		}

		BackupManager::from_wordpress()->purge_all( $attachment_id );

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'              => self::SLUG,
					'assetdrips_notice' => rawurlencode( 'Backups deleted.' ),
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	/**
	 * Handle single-attachment optimize from the Media Library column.
	 *
	 * Security: current_user_can (T-14-06-01) → id-bound check_admin_referer
	 * (T-14-06-02 / T-14-06-03) → action → PRG to Media Library (upload.php).
	 *
	 * @return void
	 */
	public function handle_single_optimize(): void {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( 'Insufficient permissions.' );
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Nonce verified via check_admin_referer() below; absint() coerces the id.
		$attachment_id = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0;

		// Nonce action binds the attachment_id to prevent id-swap (T-14-06-03).
		check_admin_referer( 'assetdrips_squeeze_single_optimize_' . $attachment_id );

		// Per-item capability check: consistent with BulkActions::handle_bulk_op() (CR-01).
		if ( ! current_user_can( 'edit_post', $attachment_id ) ) {
			wp_die( 'Insufficient permissions.' );
		}

		if ( $attachment_id <= 0 ) {
			wp_safe_redirect(
				add_query_arg(
					array(
						'page'              => self::SLUG,
						'assetdrips_notice' => rawurlencode( 'Invalid attachment ID.' ),
					),
					admin_url( 'admin.php' )
				)
			);
			exit;
		}

		// Return the user to whichever screen launched this action — the Squeeze
		// dashboard "biggest offenders" list OR the Media Library column —
		// falling back to the dashboard. Returning to the dashboard lets the
		// offenders list and savings stats refresh so the optimization is
		// visibly reflected (wp_safe_redirect restricts to same host).
		$return_url = wp_get_referer();
		if ( ! $return_url ) {
			$return_url = add_query_arg( 'page', self::SLUG, admin_url( 'admin.php' ) );
		}

		$settings = SqueezeSettings::load();

		// If no operation is enabled, optimizing is a silent no-op — report that
		// instead of a false "Image optimized." (otherwise the button appears to
		// do nothing: it runs, changes nothing, and redirects).
		if ( ! $settings->enable_recompress && ! $settings->enable_webp
			&& ! $settings->enable_avif && ! $settings->enable_resize ) {
			wp_safe_redirect(
				add_query_arg(
					'assetdrips_notice',
					rawurlencode( 'No optimization operations are enabled. Turn on Recompress / WebP / AVIF / Resize in AssetDrips → Squeeze settings first.' ),
					$return_url
				)
			);
			exit;
		}

		// Run the same settings-gated op sequence as BulkActions::op_squeeze_optimize().
		// Collect each op's result so the notice reflects what actually happened.
		// "Real work" = ok===true (recompress/webp/avif), or ok===true AND
		// was_oversized===true for resize (the not-oversized path returns ok=true
		// but changes nothing). When nothing changes, report why — never claim
		// "Image optimized." for a runtime no-op (e.g. WebP/AVIF unsupported, or
		// the image was already optimized).
		$engine  = SqueezeEngine::from_wordpress();
		$results = array();
		$changed = false;
		try {
			if ( $settings->enable_recompress ) {
				$r         = $engine->recompress( $attachment_id );
				$results[] = $r;
				$changed   = $changed || ! empty( $r['ok'] );
			}
			if ( $settings->enable_webp ) {
				$r         = $engine->generate_webp( $attachment_id );
				$results[] = $r;
				$changed   = $changed || ! empty( $r['ok'] );
			}
			if ( $settings->enable_avif ) {
				$r         = $engine->generate_avif( $attachment_id );
				$results[] = $r;
				$changed   = $changed || ! empty( $r['ok'] );
			}
			if ( $settings->enable_resize ) {
				$r         = $engine->resize_original( $attachment_id );
				$results[] = $r;
				$changed   = $changed || ( ! empty( $r['ok'] ) && ! empty( $r['was_oversized'] ) );
			}
		} catch ( \Throwable $e ) {
			wp_safe_redirect(
				add_query_arg(
					'assetdrips_notice',
					rawurlencode( 'Optimization failed: ' . $e->getMessage() ),
					$return_url
				)
			);
			exit;
		}

		$notice = $changed ? 'Image optimized.' : self::summarize_no_change( $results );

		wp_safe_redirect(
			add_query_arg(
				'assetdrips_notice',
				rawurlencode( $notice ),
				$return_url
			)
		);
		exit;
	}

	/**
	 * Build an honest "nothing changed" notice from the engine op results.
	 *
	 * Called only when no enabled operation did real work, so every result is
	 * either a skip/failure (ok=false with a 'reason') or a not-oversized resize
	 * (ok=true with was_oversized=false). Maps the machine reasons to a concise,
	 * actionable message rather than a misleading "Image optimized."
	 *
	 * @param array<int,array<string,mixed>> $results Per-op result arrays.
	 * @return string Notice text.
	 */
	private static function summarize_no_change( array $results ): string {
		$reasons = array();
		foreach ( $results as $r ) {
			// Not-oversized resize: ok=true but nothing to do.
			if ( array_key_exists( 'was_oversized', $r ) && empty( $r['was_oversized'] ) ) {
				$reasons[] = 'already within the size limit';
				continue;
			}
			$reason = isset( $r['reason'] ) ? (string) $r['reason'] : '';
			switch ( $reason ) {
				case 'already_optimized':
					$reasons[] = 'already optimized';
					break;
				case 'webp_unsupported':
					$reasons[] = 'WebP encoding not available on this server';
					break;
				case 'avif_unsupported':
					$reasons[] = 'AVIF encoding not available on this server';
					break;
				case 'no_file':
				case 'file_missing':
					$reasons[] = 'source file is missing';
					break;
				case 'backup_failed':
					$reasons[] = 'a backup could not be created';
					break;
				case 'save_failed':
				case 'editor_unavailable':
					$reasons[] = 'the image editor could not write the output';
					break;
				default:
					if ( '' !== $reason ) {
						$reasons[] = $reason;
					}
			}
		}

		$reasons = array_values( array_unique( $reasons ) );
		if ( empty( $reasons ) ) {
			return 'Nothing to optimize for this image.';
		}

		return 'Nothing to optimize — ' . implode( '; ', $reasons ) . '.';
	}

	/**
	 * Handle single-attachment restore from the Media Library column.
	 *
	 * Security: current_user_can (T-14-06-01) → id-bound check_admin_referer
	 * (T-14-06-02 / T-14-06-03) → action → PRG to Media Library (upload.php).
	 *
	 * @return void
	 */
	public function handle_single_restore(): void {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( 'Insufficient permissions.' );
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Nonce verified via check_admin_referer() below; absint() coerces the id.
		$attachment_id = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0;

		// Nonce action binds the attachment_id to prevent id-swap (T-14-06-03).
		check_admin_referer( 'assetdrips_squeeze_single_restore_' . $attachment_id );

		// Per-item capability check: consistent with BulkActions::handle_bulk_op() (CR-01).
		if ( ! current_user_can( 'edit_post', $attachment_id ) ) {
			wp_die( 'Insufficient permissions.' );
		}

		if ( $attachment_id <= 0 ) {
			wp_safe_redirect(
				add_query_arg(
					array(
						'page'              => self::SLUG,
						'assetdrips_notice' => rawurlencode( 'Invalid attachment ID.' ),
					),
					admin_url( 'admin.php' )
				)
			);
			exit;
		}

		BackupManager::from_wordpress()->restore_all( $attachment_id );

		wp_safe_redirect(
			add_query_arg(
				array(
					'assetdrips_notice' => rawurlencode( 'Original restored.' ),
				),
				admin_url( 'upload.php' )
			)
		);
		exit;
	}

	/**
	 * Handle global backup deletion (all backups for all attachments).
	 *
	 * Security: current_user_can (T-09-11) → check_admin_referer → action → PRG.
	 * Two-step JS checkbox confirmation prevents accidental destruction (T-09-11).
	 *
	 * @return void
	 */
	public function handle_purge_all_backups(): void {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( 'Insufficient permissions.' );
		}
		check_admin_referer( 'assetdrips_squeeze_purge_all_backups' );

		// Server-side confirmation guard (CR-04 / T-09-11): the JS-only two-step
		// confirmation can be bypassed via direct POST, tab-restore, or JS-disabled
		// browsers. Require the checkbox field to be present and non-empty here too.
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified via check_admin_referer() above.
		if ( empty( $_POST['purge_all_confirm'] ) ) {
			wp_safe_redirect(
				add_query_arg(
					array(
						'page'              => self::SLUG,
						'assetdrips_notice' => rawurlencode( 'You must check the confirmation box to delete all backups.' ),
					),
					admin_url( 'admin.php' )
				)
			);
			exit;
		}

		$manager = BackupManager::from_wordpress();

		// Retrieve all attachment IDs that have active backups and purge each.
		global $wpdb;
		$table = Schema::squeeze_backups_table();
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Global purge; table name from Schema constant (never input); status bound via prepare().
		$ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT DISTINCT attachment_id FROM {$table} WHERE status = %s",
				BackupRecord::ACTIVE
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		foreach ( (array) $ids as $raw_id ) {
			$manager->purge_all( (int) $raw_id );
		}

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'              => self::SLUG,
					'assetdrips_notice' => rawurlencode( 'Backups deleted.' ),
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	// ── Sizes Audit handlers ──────────────────────────────────────────────────

	/**
	 * Handle the "Run sizes audit" form submission.
	 *
	 * Triggers SizesAuditJob::run().  If the 'audit_restart' field is set, the
	 * stored checkpoint is deleted first so the scan restarts from the beginning.
	 *
	 * Security: current_user_can (T-13-15) → check_admin_referer (T-13-16) →
	 * work → PRG redirect.
	 *
	 * @return void
	 */
	public function handle_run_audit(): void {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( 'Insufficient permissions.' );
		}
		check_admin_referer( 'assetdrips_squeeze_run_audit' );

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified via check_admin_referer() above.
		$restart_raw = isset( $_POST['audit_restart'] ) ? sanitize_key( wp_unslash( (string) $_POST['audit_restart'] ) ) : '0';
		$restart     = '1' === $restart_raw;

		if ( $restart ) {
			delete_option( SizesAuditJob::CHECKPOINT_OPTION );
		}

		$resume = ! $restart && (bool) get_option( SizesAuditJob::CHECKPOINT_OPTION );

		try {
			SizesAuditJob::from_wordpress()->run( 100, $resume );
			$notice = 'Sizes audit complete.';
		} catch ( \Throwable $e ) {
			$notice = 'The sizes audit could not complete. Please try again. If the problem persists, check that the assetdrips_squeeze table exists (Settings > Squeeze).';
		}

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'              => self::SLUG,
					'assetdrips_notice' => rawurlencode( $notice ),
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	/**
	 * Handle the "Regenerate missing sizes" form submission.
	 *
	 * Runs the additive repair server-side over every audited attachment that the
	 * sizes audit recorded as missing ≥1 registered size, calling
	 * SqueezeEngine::repair_missing_sizes() (wp_update_image_subsizes — additive,
	 * crop-safe, original untouched; SIZE-02/D-07) with per-item Throwable isolation.
	 * After repair it re-runs the audit so the on-screen counts reflect the new state.
	 * This is the same synchronous PRG model as handle_run_audit() — the settings page
	 * has no bulk-AJAX JS driver (that lives on the Find grid).
	 *
	 * Security: current_user_can (T-13-15) → check_admin_referer (T-13-16) →
	 * work → PRG redirect.
	 *
	 * @return void
	 */
	public function handle_regenerate_sizes(): void {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( 'Insufficient permissions.' );
		}
		check_admin_referer( 'assetdrips_squeeze_regenerate_sizes' );

		global $wpdb;
		$table = Schema::squeeze_table();

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Read-only; table from Schema constant; no user input interpolated.
		$rows = $wpdb->get_results(
			"SELECT attachment_id, sizes_audit FROM {$table} WHERE sizes_audit != '{}' AND sizes_audit IS NOT NULL",
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		$engine   = SqueezeEngine::from_wordpress();
		$repaired = 0;
		$failed   = 0;
		foreach ( (array) $rows as $row ) {
			$data    = json_decode( (string) ( $row['sizes_audit'] ?? '{}' ), true );
			$missing = is_array( $data ) && isset( $data['missing'] ) && is_array( $data['missing'] ) ? $data['missing'] : array();
			if ( empty( $missing ) ) {
				continue;
			}
			$id = (int) ( $row['attachment_id'] ?? 0 );
			if ( $id <= 0 ) {
				continue;
			}
			try {
				$result = $engine->repair_missing_sizes( $id );
				if ( ! empty( $result['ok'] ) ) {
					++$repaired;
				} else {
					++$failed;
				}
			} catch ( \Throwable $e ) {
				++$failed;
			}
		}

		// Refresh the audit so the missing/orphaned/unused counts reflect post-repair state.
		try {
			SizesAuditJob::from_wordpress()->run( 100, false );
		} catch ( \Throwable $e ) {
			$e = null; // Non-fatal: regeneration already succeeded; counts refresh on next manual audit.
		}

		$notice = sprintf(
			'Regeneration complete: %d attachment(s) repaired%s.',
			$repaired,
			$failed > 0 ? sprintf( ', %d failed', $failed ) : ''
		);

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'              => self::SLUG,
					'assetdrips_notice' => rawurlencode( $notice ),
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	// ── Private render helpers ─────────────────────────────────────────────────

	/**
	 * Print the Squeeze dashboard band at the top of the screen (D-01, DASH-01..07).
	 *
	 * Renders:
	 * - Stat-tile row (7 tiles): disk saved, optimized count, WebP coverage %,
	 *   AVIF coverage %, oversized count, missing-WebP count, missing-sizes count.
	 *   Each tile is an <a> deep-link into FindScreen (DASH-04), except the
	 *   missing-sizes tile which anchors to the in-page #sizes-audit section (DASH-07).
	 * - Biggest Offenders section: top-10 unoptimized images with Optimize-now links.
	 * - Optimization History table: newest-first log from the option store (DASH-03).
	 * - Serving coverage boundary note (D-09 framing).
	 *
	 * All dynamic output escaped (esc_html / number_format_i18n / esc_url).
	 * Disk-saved reflects recompress+resize only; WebP/AVIF shown as coverage %
	 * only — never added to disk-saved (D-09).
	 *
	 * @return void
	 */
	private function print_dashboard_band(): void {
		$index     = OptimizationIndex::from_wordpress();
		$stats     = $index->query_dashboard();
		$counts    = $index->get_coverage_counts();
		$audit     = $index->get_sizes_audit_summary();
		$offenders = $index->get_biggest_unoptimized( 10 );
		$history   = get_option( 'assetdrips_squeeze_history', array() );
		if ( ! is_array( $history ) ) {
			$history = array();
		}

		// ── Derived values ──────────────────────────────────────────────────────
		$optimized     = (int) ( $stats['optimized'] ?? 0 );
		$total         = (int) ( $stats['total'] ?? 0 );
		$bytes_saved   = (int) ( $stats['bytes_saved'] ?? 0 );
		$pct           = (float) ( $stats['pct_reduction'] ?? 0.0 );
		$oversized     = (int) ( $stats['oversized'] ?? 0 );
		$missing_webp  = (int) ( $stats['missing_webp'] ?? 0 );
		$missing_sizes = (int) ( $audit['missing_count'] ?? 0 );

		$webp_count  = (int) ( $counts['webp_count'] ?? 0 );
		$avif_count  = (int) ( $counts['avif_count'] ?? 0 );
		$count_total = (int) ( $counts['total'] ?? 0 );
		$webp_pct    = $count_total > 0 ? round( $webp_count / $count_total * 100 ) : 0;
		$avif_pct    = $count_total > 0 ? round( $avif_count / $count_total * 100 ) : 0;

		// Human-readable disk-saved figure.
		if ( $bytes_saved >= 1048576 ) {
			$disk_saved_str = number_format( $bytes_saved / 1048576, 1 ) . ' MB';
		} elseif ( $bytes_saved > 0 ) {
			$disk_saved_str = number_format( (int) ( $bytes_saved / 1024 ), 0 ) . ' KB';
		} else {
			$disk_saved_str = '0 KB';
		}

		// Deep-link URLs into FindScreen (DASH-04).
		$url_not_optimized = esc_url(
			add_query_arg(
				array(
					'page'          => FindScreen::SLUG,
					'squeeze_state' => 'not-optimized',
				),
				admin_url( 'admin.php' )
			)
		);
		$url_missing_webp  = esc_url(
			add_query_arg(
				array(
					'page'          => FindScreen::SLUG,
					'squeeze_state' => 'missing-webp',
				),
				admin_url( 'admin.php' )
			)
		);
		$url_oversized     = esc_url(
			add_query_arg(
				array(
					'page'          => FindScreen::SLUG,
					'squeeze_state' => 'oversized',
				),
				admin_url( 'admin.php' )
			)
		);

		echo '<div class="ad-squeeze-dashboard-band">';

		// ── Stat tile row ───────────────────────────────────────────────────────
		echo '<section class="ad-health-band" aria-label="' . esc_attr( 'Squeeze stats' ) . '">';

		// Tile 1: Disk saved (recompress + resize only — D-09).
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $url_not_optimized is pre-encoded via esc_url() above.
		echo '<a class="ad-health-tile" href="' . $url_not_optimized . '">';
		echo '<span class="ad-health-num">' . esc_html( $disk_saved_str ) . '</span>';
		echo '<span class="ad-health-label">' . esc_html( 'Disk saved' ) . '</span>';
		if ( $pct > 0 ) {
			echo '<span class="ad-squeeze-tile-sub">' . esc_html( number_format( $pct, 1 ) . '% reduction' ) . '</span>';
		}
		echo '</a>';

		// Tile 2: Optimized count / total.
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $url_not_optimized is pre-encoded via esc_url() above.
		echo '<a class="ad-health-tile" href="' . $url_not_optimized . '">';
		echo '<span class="ad-health-num">' . esc_html( number_format_i18n( $optimized ) ) . '</span>';
		echo '<span class="ad-health-label">' . esc_html( 'Images optimized' ) . '</span>';
		if ( $total > 0 ) {
			echo '<span class="ad-squeeze-tile-sub">' . esc_html( 'of ' . number_format_i18n( $total ) ) . '</span>';
		}
		echo '</a>';

		// Tile 3: WebP coverage % (coverage only — D-09; never savings).
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $url_missing_webp is pre-encoded via esc_url() above.
		echo '<a class="ad-health-tile" href="' . $url_missing_webp . '">';
		echo '<span class="ad-health-num">' . esc_html( number_format_i18n( $webp_pct ) . '%' ) . '</span>';
		echo '<span class="ad-health-label">' . esc_html( 'WebP coverage' ) . '</span>';
		echo '</a>';

		// Tile 4: AVIF coverage % (coverage only — D-09; never savings).
		// No missing-avif Find facet exists, so this tile is a non-link stat to avoid
		// misrepresenting the filter (WR-01). Keep WebP tile linking to missing-webp.
		echo '<span class="ad-health-tile">';
		echo '<span class="ad-health-num">' . esc_html( number_format_i18n( $avif_pct ) . '%' ) . '</span>';
		echo '<span class="ad-health-label">' . esc_html( 'AVIF coverage' ) . '</span>';
		echo '</span>';

		// Tile 5: Oversized originals.
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $url_oversized is pre-encoded via esc_url() above.
		echo '<a class="ad-health-tile" href="' . $url_oversized . '">';
		echo '<span class="ad-health-num">' . esc_html( number_format_i18n( $oversized ) ) . '</span>';
		echo '<span class="ad-health-label">' . esc_html( 'Oversized originals' ) . '</span>';
		echo '</a>';

		// Tile 6: Missing WebP count.
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $url_missing_webp is pre-encoded via esc_url() above.
		echo '<a class="ad-health-tile" href="' . $url_missing_webp . '">';
		echo '<span class="ad-health-num">' . esc_html( number_format_i18n( $missing_webp ) ) . '</span>';
		echo '<span class="ad-health-label">' . esc_html( 'Missing WebP' ) . '</span>';
		echo '</a>';

		// Tile 7: Missing sizes — links to the in-page Sizes Audit anchor (DASH-07).
		// NOT a FindScreen deep-link; the sizes-audit section is on this same screen.
		echo '<a class="ad-health-tile" href="' . esc_url( '#sizes-audit' ) . '">';
		echo '<span class="ad-health-num">' . esc_html( number_format_i18n( $missing_sizes ) ) . '</span>';
		echo '<span class="ad-health-label">' . esc_html( 'Missing sizes' ) . '</span>';
		echo '</a>';

		echo '</section>';

		// ── Biggest Offenders ───────────────────────────────────────────────────
		echo '<h2 class="title">' . esc_html( 'Biggest Offenders' ) . '</h2>';
		echo '<div class="ad-squeeze-caps">';

		if ( empty( $offenders ) ) {
			echo '<p class="ad-squeeze-audit-muted">' . esc_html( 'No media indexed yet — run `wp assetdrips index` to populate the library.' ) . '</p>';
		} else {
			foreach ( $offenders as $row ) {
				$att_id   = (int) ( $row['attachment_id'] ?? 0 );
				$filename = (string) ( $row['filename'] ?? '' );
				$filesize = (int) ( $row['filesize'] ?? 0 );

				if ( $att_id <= 0 ) {
					continue;
				}

				$size_str = $filesize >= 1048576
					? number_format( $filesize / 1048576, 1 ) . ' MB'
					: number_format( (int) ( $filesize / 1024 ), 0 ) . ' KB';

				$nonce        = wp_create_nonce( 'assetdrips_squeeze_single_optimize_' . $att_id );
				$optimize_url = esc_url(
					add_query_arg(
						array(
							'action'   => 'assetdrips_squeeze_single_optimize',
							'id'       => $att_id,
							'_wpnonce' => $nonce,
						),
						admin_url( 'admin-post.php' )
					)
				);

				echo '<div class="ad-squeeze-offender-row">';
				echo '<span class="ad-squeeze-offender-name">' . esc_html( $filename ) . '</span>';
				echo '<span class="ad-squeeze-offender-size">' . esc_html( $size_str ) . '</span>';
				// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $optimize_url is pre-encoded via esc_url() above.
				echo '<a href="' . $optimize_url . '" class="button button-primary ad-squeeze-save">' . esc_html( 'Optimize now' ) . '</a>';
				echo '</div>';
			}
		}

		echo '</div><!-- .ad-squeeze-caps -->';

		// ── Optimization History ────────────────────────────────────────────────
		echo '<h2 class="title">' . esc_html( 'Optimization History' ) . '</h2>';

		if ( empty( $history ) ) {
			echo '<p class="ad-squeeze-audit-muted">' . esc_html( 'No optimization runs yet. History will appear here after the first batch completes.' ) . '</p>';
		} else {
			echo '<table class="widefat striped ad-squeeze-history-table">';
			echo '<thead>';
			echo '<tr>';
			echo '<th>' . esc_html( 'Date' ) . '</th>';
			echo '<th>' . esc_html( 'Operations' ) . '</th>';
			echo '<th>' . esc_html( 'Images processed' ) . '</th>';
			echo '<th>' . esc_html( 'Bytes saved (recompress/resize)' ) . '</th>';
			echo '</tr>';
			echo '</thead>';
			echo '<tbody>';
			foreach ( $history as $entry ) {
				if ( ! is_array( $entry ) ) {
					continue;
				}
				$h_date   = esc_html( (string) ( $entry['date'] ?? '' ) );
				$h_ops    = is_array( $entry['ops'] ?? null )
					? esc_html( implode( ', ', (array) $entry['ops'] ) )
					: esc_html( (string) ( $entry['ops'] ?? '' ) );
				$h_images = (int) ( $entry['images_processed'] ?? 0 );
				$h_bytes  = (int) ( $entry['bytes_saved'] ?? 0 );

				$h_bytes_str = $h_bytes >= 1048576
					? number_format( $h_bytes / 1048576, 1 ) . ' MB'
					: number_format( (int) ( $h_bytes / 1024 ), 0 ) . ' KB';

				echo '<tr>';
				// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $h_date and $h_ops are pre-escaped via esc_html() above.
				echo '<td>' . $h_date . '</td><td>' . $h_ops . '</td>';
				echo '<td>' . esc_html( number_format_i18n( $h_images ) ) . '</td>';
				echo '<td>' . esc_html( $h_bytes_str ) . '</td>';
				echo '</tr>';
			}
			echo '</tbody>';
			echo '</table>';
		}

		// ── Serving coverage boundary note (D-09 / ARCHITECTURE §397) ──────────
		echo '<div class="assetdrips-gap" style="margin-top:16px;">';
		echo '<strong>' . esc_html( 'Next-gen serving scope' ) . '</strong>';
		echo '<p>' . esc_html( 'Note: Next-gen serving covers only images served through WordPress\'s image API (wp_get_attachment_image and related functions). Images referenced by hard-coded <img> tags in post content or theme CSS are not rewritten.' ) . '</p>';
		echo '</div>';

		echo '</div><!-- .ad-squeeze-dashboard-band -->';
	}

	/**
	 * Return total active backup bytes from the injected $wpdb stub or live BackupManager.
	 *
	 * When a $wpdb stub was passed to the constructor (unit-test path), it is used
	 * directly so tests can observe the query without loading WordPress. In production
	 * the $wpdb property is null and BackupManager::from_wordpress() is used.
	 *
	 * @return int Total bytes across all active backups.
	 */
	private function get_total_backup_bytes(): int {
		if ( null !== $this->wpdb ) {
			// Test path: use the injected stub directly.
			$table = Schema::squeeze_backups_table();
			// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Aggregate query; table name is a Schema constant and status bound via prepare().
			$sum = $this->wpdb->get_var(
				$this->wpdb->prepare(
					"SELECT SUM(original_bytes) FROM {$table} WHERE status = %s",
					BackupRecord::ACTIVE
				)
			);
			// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			return (int) $sum;
		}

		return BackupManager::from_wordpress()->total_active_bytes();
	}

	/**
	 * Derive the registered size definitions that are unused by the whole library
	 * (SIZE-04/D-05 — informational only).
	 *
	 * Reads the sizes_audit column via a single direct SELECT (no postmeta
	 * iteration — SIZE-01/D-02), decodes each scanned JSON blob, and delegates the
	 * set logic to the canonical SizesAuditJob::compute_unused_definitions(): a size
	 * is unused only when it is in EVERY audited attachment's 'missing' list (present
	 * for none). An earlier inline array_diff inverted this (CR-01).
	 *
	 * @return string[] Registered size names used by zero audited attachments.
	 */
	private function get_unused_size_names(): array {
		global $wpdb;
		$table = \AssetDrips\Db\Schema::squeeze_table();

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Read-only aggregate; table from Schema constant; no user input interpolated.
		$rows = $wpdb->get_col(
			"SELECT sizes_audit FROM {$table} WHERE sizes_audit != '{}' AND sizes_audit IS NOT NULL"
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		$audit_rows = array();
		foreach ( (array) $rows as $raw ) {
			$data = json_decode( (string) $raw, true );
			if ( is_array( $data ) && isset( $data['scanned_at'] ) ) {
				$audit_rows[] = $data;
			}
		}

		return SizesAuditJob::from_wordpress()->compute_unused_definitions( $audit_rows );
	}

	/**
	 * Return a human-readable "last scanned" date string from the most recent
	 * scanned_at timestamp stored across all audited rows, or '' if no data.
	 *
	 * @return string Formatted date/time string, or empty string.
	 */
	private function get_last_scanned_date(): string {
		global $wpdb;
		$table = \AssetDrips\Db\Schema::squeeze_table();

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Read-only aggregate; table from Schema constant.
		$rows = $wpdb->get_col(
			"SELECT sizes_audit FROM {$table} WHERE sizes_audit != '{}' AND sizes_audit IS NOT NULL"
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		$latest = '';
		foreach ( (array) $rows as $raw ) {
			$data = json_decode( (string) $raw, true );
			if ( ! is_array( $data ) || ! isset( $data['scanned_at'] ) ) {
				continue;
			}
			$ts = (string) $data['scanned_at'];
			if ( '' === $latest || $ts > $latest ) {
				$latest = $ts;
			}
		}

		if ( '' === $latest ) {
			return '';
		}

		// Format as a readable date/time (WP local time).
		$timestamp = strtotime( $latest );
		if ( false === $timestamp ) {
			return $latest;
		}

		return get_date_from_gmt( gmdate( 'Y-m-d H:i:s', $timestamp ), 'Y-m-d H:i' );
	}

	/**
	 * Print the page header: breadcrumb, h1 + pill badge, tagline.
	 *
	 * @return void
	 */
	private function print_header(): void {
		$home = add_query_arg( 'page', Dashboard::SLUG, admin_url( 'admin.php' ) );
		echo '<a class="ad-crumb" href="' . esc_url( $home ) . '">&larr; AssetDrips</a>';
		echo '<div class="assetdrips-head">';
		echo '<h1>Squeeze</h1>';
		echo '<span class="ad-mod" style="background:var(--ad-orange);">Settings</span>';
		echo '</div>';
		echo '<p class="assetdrips-tag">Configure image optimization settings, server capabilities, and auto-upload behavior.</p>';
	}

	/**
	 * Print a post-redirect notice if present in the GET params (PRG pattern).
	 *
	 * Hooked on `admin_notices` so it renders on whichever admin screen the
	 * PRG redirect lands on (this dashboard OR the Media Library). Public for
	 * that reason; capability-gated since it runs on every admin page load.
	 *
	 * @return void
	 */
	public function print_notice(): void {
		if ( ! current_user_can( self::CAP ) ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display of a post-redirect message; no state change.
		if ( ! isset( $_GET['assetdrips_notice'] ) ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display only.
		$message = sanitize_text_field( wp_unslash( $_GET['assetdrips_notice'] ) );
		$class   = 'notice-success';

		printf(
			'<div class="notice %s is-dismissible"><p>%s</p></div>',
			esc_attr( $class ),
			esc_html( $message )
		);
	}

	/**
	 * Print the scoped brand styles for the Squeeze screen.
	 *
	 * Shared utility classes are copied verbatim from FindScreen (standalone
	 * rendering). New ad-squeeze-* classes follow the UI-SPEC CSS Class Inventory.
	 *
	 * @return void
	 */
	private function print_styles(): void {
		echo '<style>
			/* --- Shared utility classes (verbatim from FindScreen/FolderScreen) --- */
			.assetdrips-wrap{--ad-orange:#FF4200;--ad-black:#080808;color:var(--ad-black);}
			.ad-crumb{display:inline-block;margin:6px 0 2px;color:#777;text-decoration:none;font-size:13px;font-weight:600;}
			.ad-crumb:hover{color:var(--ad-orange);}
			.assetdrips-head{display:flex;align-items:baseline;gap:12px;margin:4px 0 4px;}
			.assetdrips-head h1{font-size:28px;font-weight:800;margin:0;color:var(--ad-black);}
			.assetdrips-head .ad-mod{background:var(--ad-orange);color:#fff;border-radius:999px;padding:2px 12px;font-size:12px;font-weight:700;letter-spacing:.04em;text-transform:uppercase;}
			.assetdrips-tag{color:#555;margin:0 0 18px;max-width:680px;font-size:14px;}
			.assetdrips-gap{background:#fff7f4;border:1px solid #ffd8c9;border-left:4px solid var(--ad-orange);border-radius:12px;padding:12px 16px;margin:0 0 20px;}
			.ad-mod{border-radius:999px;padding:2px 12px;font-size:12px;font-weight:700;letter-spacing:.04em;text-transform:uppercase;color:#fff;}
			/* --- New ad-squeeze-* classes (UI-SPEC CSS Class Inventory) --- */
			.ad-squeeze-caps{background:#fff;border:1px solid #ececec;border-radius:8px;padding:12px 16px;margin:0 0 16px;max-width:600px;}
			.ad-squeeze-cap-row{display:flex;align-items:flex-start;gap:4px;flex-wrap:wrap;}
			.ad-squeeze-cap-supported{color:#46b450;}
			.ad-squeeze-cap-unsupported{color:#a00;}
			.ad-squeeze-preset-badge{display:inline-block;background:#f1f1f1;color:#444;border-radius:999px;padding:1px 8px;font-size:12px;font-weight:700;margin-left:4px;}
			.ad-squeeze-ack-panel{max-width:600px;}
			.ad-squeeze-ack-done{display:flex;align-items:center;gap:4px;}
			.ad-squeeze-custom-quality{}
			.ad-squeeze-save{background:var(--ad-orange) !important;border-color:var(--ad-orange) !important;border-radius:999px;padding:4px 20px;}
			/* --- Next-Gen Serving status classes (12-UI-SPEC CSS Class Inventory) --- */
			.ad-squeeze-serving-status{display:flex;align-items:flex-start;gap:4px;flex-wrap:wrap;}
			.ad-squeeze-serving-active{color:#46b450;}
			.ad-squeeze-serving-inactive{color:#777;}
			.ad-squeeze-coverage{color:#555;font-size:13px;}
			/* --- Sizes Audit classes (13-UI-SPEC CSS Class Inventory) --- */
			.ad-squeeze-audit-stat{display:flex;align-items:flex-start;gap:4px;flex-wrap:wrap;margin-top:8px;}
			.ad-squeeze-audit-count{font-weight:600;color:inherit;}
			.ad-squeeze-audit-muted{color:#777;font-size:13px;}
			.ad-squeeze-audit-list{margin:8px 0 0 20px;padding:0;list-style:disc;}
			/* --- Health band tile classes (reused from Dashboard.php verbatim — DASH-01/04) --- */
			.ad-health-band{display:flex;gap:16px;max-width:880px;margin:0 0 24px;flex-wrap:wrap;}
			@media (max-width:782px){.ad-health-band{flex-direction:column;}}
			.ad-health-tile{flex:1;min-width:120px;background:#fff;border:1px solid #ececec;border-radius:16px;padding:16px;display:flex;flex-direction:column;gap:4px;text-decoration:none;color:var(--ad-black);transition:transform .15s ease,box-shadow .15s ease;}
			.ad-health-tile:hover,.ad-health-tile:focus{transform:translateY(-3px);box-shadow:0 12px 28px rgba(8,8,8,.10);text-decoration:none;color:var(--ad-black);outline-offset:2px;}
			.ad-health-tile:focus-visible{outline:2px solid var(--ad-orange);outline-offset:2px;}
			.ad-health-num{font-size:28px;font-weight:700;color:var(--ad-orange);line-height:1;}
			.ad-health-label{font-size:12px;color:#555;font-weight:700;line-height:1.3;}
			/* --- Dashboard band classes (14-UI-SPEC CSS Class Inventory) --- */
			.ad-squeeze-dashboard-band{margin:0 0 24px;}
			.ad-squeeze-tile-sub{font-size:11px;color:#777;line-height:1.2;}
			.ad-squeeze-offender-row{display:flex;align-items:center;gap:8px;padding:8px 0;border-top:1px solid #ececec;font-size:13px;}
			.ad-squeeze-offender-name{flex:1;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;}
			.ad-squeeze-offender-size{color:#777;font-size:12px;white-space:nowrap;}
			.ad-squeeze-history-table{}
			/* --- Status badge classes (14-UI-SPEC / DASH-06) --- */
			.ad-squeeze-status-badge{display:inline-block;border-radius:999px;padding:4px 8px;font-size:12px;font-weight:600;margin-right:4px;}
			.ad-squeeze-status-optimized{background:#f1f1f1;color:#46b450;}
			.ad-squeeze-status-pending{background:#f1f1f1;color:#777;}
			.ad-squeeze-status-failed{background:#fff0f0;color:#a00;}
			.ad-squeeze-bytes-saved{color:#777;font-size:12px;display:block;margin-top:4px;}
			.ad-squeeze-action-links{display:block;margin-top:4px;font-size:12px;}
		</style>';
	}

	/**
	 * Print minimal inline JS for progressive enhancement.
	 *
	 * Toggles the custom-quality row visibility on preset change and sets the
	 * re-check button to a loading state on submit. All disabled states are
	 * server-rendered (UI-SPEC Interaction Contract).
	 *
	 * @return void
	 */
	private function print_script(): void {
		echo '<script>
(function(){
	// Toggle custom quality row on preset change.
	var radios = document.querySelectorAll("input[name=\'preset\']");
	var customRow = document.querySelector("tr.ad-squeeze-custom-quality");
	function toggleCustomRow(){
		var selected = document.querySelector("input[name=\'preset\']:checked");
		if(customRow){
			customRow.style.display = selected && selected.value === "custom" ? "" : "none";
		}
	}
	for(var i=0;i<radios.length;i++){radios[i].addEventListener("change",toggleCustomRow);}
	toggleCustomRow();

	// Re-check button loading state.
	var recheckForm = document.getElementById("ad-squeeze-recheck-form");
	var recheckBtn  = document.getElementById("ad-squeeze-recheck-btn");
	if(recheckForm && recheckBtn){
		recheckForm.addEventListener("submit",function(){
			recheckBtn.disabled = true;
			recheckBtn.value = "Checking…";
		});
	}

	// Lossy-ack: enable the Acknowledge submit only when the checkbox is checked.
	// This makes the one-way gesture explicit — the button is inert until the
	// user actively ticks the checkbox (WR-04).
	var ackCheckbox = document.getElementById("ad-squeeze-ack-checkbox");
	var ackSubmit   = document.getElementById("ad-squeeze-ack-submit");
	if(ackCheckbox && ackSubmit){
		ackCheckbox.addEventListener("change",function(){
			ackSubmit.disabled = !this.checked;
		});
	}

	// Delete-all-backups: enable the submit only when the confirmation checkbox
	// is checked. Two-step confirmation prevents accidental bulk deletion (T-09-11).
	var purgeAllCheckbox = document.getElementById("ad-squeeze-purge-all-checkbox");
	var purgeAllSubmit   = document.getElementById("ad-squeeze-purge-all-submit");
	if(purgeAllCheckbox && purgeAllSubmit){
		purgeAllCheckbox.addEventListener("change",function(){
			purgeAllSubmit.disabled = !this.checked;
		});
	}

	// Sizes Audit — Restart button sets the hidden audit_restart flag to 1 before submit.
	var auditRestartBtn = document.getElementById("ad-squeeze-audit-restart-btn");
	var auditRestartFlag = document.getElementById("ad-squeeze-audit-restart-flag");
	if(auditRestartBtn && auditRestartFlag){
		auditRestartBtn.addEventListener("click",function(){
			auditRestartFlag.value = "1";
		});
	}
})();
</script>';
	}
}
