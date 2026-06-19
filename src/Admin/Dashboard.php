<?php
/**
 * AssetDrips dashboard (landing) screen.
 *
 * @package AssetDrips
 */

declare( strict_types=1 );

namespace AssetDrips\Admin;

use AssetDrips\Admin\FindScreen;
use AssetDrips\Admin\SqueezeScreen;
use AssetDrips\Db\Schema;
use AssetDrips\Index\MediaIndex;
use AssetDrips\Score\Tier;

defined( 'ABSPATH' ) || exit;

/**
 * The AssetDrips home screen.
 *
 * AssetDrips is a media-library enhancement suite. This dashboard is the front
 * door: it frames the suite and routes into each module. Sift (unused-media
 * detection) is the first module to ship; Sort / Squeeze / Find follow. Each
 * module registers its own submenu page; the scanner is no longer the whole
 * plugin, it is one tool among several.
 */
final class Dashboard {

	/**
	 * Top-level menu slug. Modules attach their submenus to this parent.
	 */
	public const SLUG = 'assetdrips';

	/**
	 * Capability required to view.
	 */
	private const CAP = 'manage_options';

	/**
	 * Register hooks. Runs early so the parent menu exists before modules
	 * attach their submenus.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'admin_menu', array( $this, 'add_menu' ), 9 );
	}

	/**
	 * Add the top-level menu and rename its first submenu to "Home".
	 *
	 * @return void
	 */
	public function add_menu(): void {
		add_menu_page(
			'AssetDrips',
			'AssetDrips',
			self::CAP,
			self::SLUG,
			array( $this, 'render' ),
			'dashicons-images-alt2',
			81
		);

		// Rename the auto-generated first submenu (which mirrors the parent slug)
		// from "AssetDrips" to "Home" so the suite reads cleanly.
		add_submenu_page(
			self::SLUG,
			'AssetDrips',
			'Home',
			self::CAP,
			self::SLUG,
			array( $this, 'render' )
		);
	}

	/**
	 * Render the dashboard.
	 *
	 * @return void
	 */
	public function render(): void {
		if ( ! current_user_can( self::CAP ) ) {
			return;
		}

		echo '<div class="wrap assetdrips-wrap assetdrips-dash">';
		$this->print_styles();

		echo '<div class="assetdrips-head"><h1>AssetDrips</h1><span class="ad-mod">Suite</span></div>';
		echo '<p class="assetdrips-tag">Your media library, enhanced. Find what you don\'t need, organise what you keep, and serve it leaner — all without breaking a live site.</p>';

		$this->print_health_band( MediaIndex::from_wordpress()->health_counts() );

		echo '<div class="ad-modules">';
		foreach ( $this->modules() as $module ) {
			$this->print_module_card( $module );
		}
		echo '</div>';

		echo '</div>';
	}

	/**
	 * The suite's modules, in roadmap order. Only Sift is live in v1.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function modules(): array {
		return array(
			array(
				'key'    => 'sift',
				'name'   => 'Sift',
				'icon'   => 'dashicons-search',
				'live'   => true,
				'blurb'  => 'Find unused media and delete it safely, with honest confidence tiers. Nothing is deleted — only moved, and always reversible.',
				'stat'   => $this->sift_stat(),
				'url'    => add_query_arg( 'page', ReviewScreen::SLUG, admin_url( 'admin.php' ) ),
				'action' => 'Open Sift',
			),
			array(
				'key'    => 'sort',
				'name'   => 'Sort',
				'icon'   => 'dashicons-category',
				'live'   => false,
				'blurb'  => 'Organise your library with folders, bulk tagging and smart filters so you can always find the right asset.',
				'stat'   => null,
				'url'    => '',
				'action' => '',
			),
			array(
				'key'    => 'squeeze',
				'name'   => 'Squeeze',
				'icon'   => 'dashicons-archive',
				'live'   => true,                                                                        // D-01/D-08: flip live.
				'blurb'  => 'Compress and convert images automatically so your pages load faster without you lifting a finger.',
				'stat'   => $this->squeeze_stat(),
				'url'    => add_query_arg( 'page', SqueezeScreen::SLUG, admin_url( 'admin.php' ) ),
				'action' => 'Open Squeeze',
			),
			array(
				'key'    => 'find',
				'name'   => 'Find',
				'icon'   => 'dashicons-filter',
				'live'   => true,
				'blurb'  => 'Instant search across your whole media library — by filename, alt text, type or where a file is used.',
				'stat'   => $this->find_stat(),
				'url'    => add_query_arg( 'page', FindScreen::SLUG, admin_url( 'admin.php' ) ),
				'action' => 'Open Find',
			),
		);
	}

	/**
	 * A one-line live stat for the Sift card, drawn from the last scan.
	 *
	 * @return string
	 */
	private function sift_stat(): string {
		global $wpdb;
		$table = Schema::results_table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared -- Aggregate read of scan results; table name from Schema.
		$high = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . $table . ' WHERE tier = %s', Tier::HIGH->value ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared -- Aggregate read; table name from Schema.
		$total = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . $table );

		if ( 0 === $total ) {
			return 'No scan yet — run your first scan in Sift.';
		}

		if ( 0 === $high ) {
			return 'Last scan: nothing safe to delete right now. Nice and tidy.';
		}

		return sprintf(
			'Last scan: %s safe to delete %s.',
			number_format_i18n( $high ),
			1 === $high ? 'file' : 'files'
		);
	}

	/**
	 * A one-line live stat for the Find card, drawn from the media index.
	 *
	 * @return string
	 */
	private function find_stat(): string {
		global $wpdb;
		$table = Schema::media_table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Aggregate read of indexed media; table name from Schema.
		$count = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . $table );

		if ( 0 === $count ) {
			return 'No media indexed yet — run wp assetdrips index.';
		}

		return sprintf(
			'%s media %s indexed.',
			number_format_i18n( $count ),
			1 === $count ? 'file' : 'files'
		);
	}

	/**
	 * A one-line live stat for the Squeeze card (D-01/D-08).
	 *
	 * Returns a zero-state string when no complete rows exist; otherwise a sprintf
	 * with count + KB saved using the same GREATEST+CAST disk-saved formula as
	 * query_dashboard() — recompress/resize reclaimed bytes only (D-09).
	 * Next-gen sibling bytes (has_webp/has_avif columns) are never summed (D-09).
	 *
	 * @return string
	 */
	private function squeeze_stat(): string {
		global $wpdb;
		$sq = Schema::squeeze_table();

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared -- Aggregate reads; table name from Schema constant (never user input); bound values use prepare() with %s.
		$optimized = (int) $wpdb->get_var(
			$wpdb->prepare( 'SELECT COUNT(*) FROM ' . $sq . ' WHERE status = %s', 'complete' )
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared

		if ( 0 === $optimized ) {
			return 'No images optimized yet — run your first batch in Squeeze.';
		}

		// Honest disk saved: recompress+resize reclaimed bytes only (D-09).
		// CAST AS SIGNED prevents unsigned bigint subtraction wrap (Pitfall 1).
		// Next-gen sibling columns are additive — never included in disk-saved (D-09).
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared -- Aggregate; table from Schema constant.
		$bytes_saved = (int) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT SUM(GREATEST(0, CAST(original_bytes AS SIGNED) - CAST(optimized_bytes AS SIGNED))) FROM ' . $sq . ' WHERE status = %s',
				'complete'
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared

		$kb = number_format( (int) ( $bytes_saved / 1024 ), 0 );

		return sprintf(
			'%s %s optimized — %s KB saved.',
			number_format_i18n( $optimized ),
			1 === $optimized ? 'image' : 'images',
			$kb
		);
	}

	/**
	 * Print a single module card.
	 *
	 * @param array<string, mixed> $module Module definition.
	 * @return void
	 */
	private function print_module_card( array $module ): void {
		$live    = (bool) $module['live'];
		$classes = 'ad-module' . ( $live ? '' : ' is-soon' );

		echo '<div class="' . esc_attr( $classes ) . '">';

		echo '<div class="ad-module-top">';
		echo '<span class="ad-module-icon dashicons ' . esc_attr( (string) $module['icon'] ) . '"></span>';
		echo '<span class="ad-module-name">' . esc_html( (string) $module['name'] ) . '</span>';
		if ( ! $live ) {
			echo '<span class="ad-soon-badge">Coming soon</span>';
		}
		echo '</div>';

		echo '<p class="ad-module-blurb">' . esc_html( (string) $module['blurb'] ) . '</p>';

		if ( $live ) {
			if ( '' !== (string) $module['stat'] ) {
				echo '<p class="ad-module-stat">' . esc_html( (string) $module['stat'] ) . '</p>';
			}
			echo '<a class="button button-primary ad-module-cta" href="' . esc_url( (string) $module['url'] ) . '">' . esc_html( (string) $module['action'] ) . ' &rarr;</a>';
		}

		echo '</div>';
	}

	/**
	 * Print the Library health stat band above the module grid.
	 *
	 * Implements all four UI-SPEC states:
	 * - State 3 (pre-backfill, indexed === 0): single inline message, no band.
	 * - State 1/2 (normal + zero-as-positive): three linked tiles.
	 * - State 4 (never-scanned usage lane): first two tiles linked; unused tile
	 *   replaced by a non-link placeholder (D-08 usage-lane honesty).
	 *
	 * @param array{indexed: int, missing_alt: int, unused: int, is_usage_scanned: bool} $counts Health counts from MediaIndex::health_counts().
	 * @return void
	 */
	private function print_health_band( array $counts ): void {
		// State 3: pre-backfill — no rows in the index yet.
		if ( 0 === $counts['indexed'] ) {
			echo '<p class="ad-health-empty">';
			echo 'No media indexed yet — run <code>' . esc_html( 'wp assetdrips index' ) . '</code>.';
			echo '</p>';
			return;
		}

		// States 1/2/4: index has rows — build CTA URLs via FindScreen contract (D-05).
		$url_indexed     = add_query_arg( array( 'page' => FindScreen::SLUG ), admin_url( 'admin.php' ) );
		$url_missing_alt = add_query_arg(
			array(
				'page'        => FindScreen::SLUG,
				'missing_alt' => '1',
			),
			admin_url( 'admin.php' )
		);
		$url_unused      = add_query_arg(
			array(
				'page' => FindScreen::SLUG,
				'used' => 'unused',
			),
			admin_url( 'admin.php' )
		);

		echo '<section class="ad-health-band" aria-label="' . esc_attr( 'Library health' ) . '">';

		// Tile 1: Indexed files (always a live link, D-06 always show including 0).
		echo '<a class="ad-health-tile" href="' . esc_url( $url_indexed ) . '">';
		echo '<span class="ad-health-num">' . esc_html( number_format_i18n( (int) $counts['indexed'] ) ) . '</span>';
		echo '<span class="ad-health-label">' . esc_html( 'Indexed files' ) . '</span>';
		echo '</a>';

		// Tile 2: Missing alt text (always a live link, D-06 shown even when 0).
		echo '<a class="ad-health-tile" href="' . esc_url( $url_missing_alt ) . '">';
		echo '<span class="ad-health-num">' . esc_html( number_format_i18n( (int) $counts['missing_alt'] ) ) . '</span>';
		echo '<span class="ad-health-label">' . esc_html( 'Missing alt text' ) . '</span>';
		echo '</a>';

		// Tile 3: Unused files — State 4 (never-scanned) shows a non-link placeholder.
		if ( $counts['is_usage_scanned'] ) {
			echo '<a class="ad-health-tile" href="' . esc_url( $url_unused ) . '">';
			echo '<span class="ad-health-num">' . esc_html( number_format_i18n( (int) $counts['unused'] ) ) . '</span>';
			echo '<span class="ad-health-label">' . esc_html( 'Unused files' ) . '</span>';
			echo '</a>';
		} else {
			// State 4: usage lane never synced — placeholder tile, no count, no link (D-08).
			echo '<div class="ad-health-tile--placeholder">';
			echo '<span class="ad-health-placeholder-label">' . esc_html( 'Unused files' ) . '</span>';
			echo '<span class="ad-health-placeholder-body">' . esc_html( 'Run a Sift scan to check usage.' ) . '</span>';
			echo '</div>';
		}

		echo '</section>';
	}

	/**
	 * Print the scoped brand styles for the dashboard.
	 *
	 * @return void
	 */
	private function print_styles(): void {
		echo '<style>
			.assetdrips-wrap{--ad-orange:#FF4200;--ad-black:#080808;color:var(--ad-black);}
			.assetdrips-head{display:flex;align-items:baseline;gap:12px;margin:8px 0 4px;}
			.assetdrips-head h1{font-size:28px;font-weight:800;margin:0;color:var(--ad-black);}
			.assetdrips-head .ad-mod{background:var(--ad-orange);color:#fff;border-radius:999px;padding:2px 12px;font-size:12px;font-weight:700;letter-spacing:.04em;text-transform:uppercase;}
			.assetdrips-tag{color:#555;margin:0 0 22px;max-width:680px;font-size:14px;}
			.ad-modules{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:16px;max-width:880px;}
			@media (max-width:782px){.ad-modules{grid-template-columns:1fr;}}
			.ad-module{background:#fff;border:1px solid #ececec;border-radius:16px;padding:20px 22px;display:flex;flex-direction:column;transition:transform .15s ease,box-shadow .15s ease;}
			.ad-module:not(.is-soon):hover{transform:translateY(-3px);box-shadow:0 12px 28px rgba(8,8,8,.10);}
			.ad-module.is-soon{opacity:.72;background:#fbfbfb;border-style:dashed;}
			.ad-module-top{display:flex;align-items:center;gap:10px;margin-bottom:10px;}
			.ad-module-icon{color:var(--ad-orange);font-size:22px;width:22px;height:22px;}
			.ad-module.is-soon .ad-module-icon{color:#aaa;}
			.ad-module-name{font-size:18px;font-weight:800;}
			.ad-soon-badge{margin-left:auto;background:#eee;color:#777;border-radius:999px;padding:2px 10px;font-size:11px;font-weight:700;letter-spacing:.04em;text-transform:uppercase;}
			.ad-module-blurb{color:#555;font-size:13px;line-height:1.55;margin:0 0 14px;flex:1;}
			.ad-module-stat{font-size:12px;font-weight:700;color:var(--ad-black);background:#fff7f4;border:1px solid #ffd8c9;border-radius:8px;padding:8px 12px;margin:0 0 14px;}
			.ad-module-cta{align-self:flex-start;background:var(--ad-orange);border-color:var(--ad-orange);border-radius:999px;padding:4px 20px;}
			.ad-module-cta:hover,.ad-module-cta:focus{background:#e63b00;border-color:#e63b00;}
			.ad-health-band{display:flex;gap:16px;max-width:880px;margin:0 0 24px;}
			@media (max-width:782px){.ad-health-band{flex-direction:column;}}
			.ad-health-tile{flex:1;background:#fff;border:1px solid #ececec;border-radius:16px;padding:16px;display:flex;flex-direction:column;gap:4px;text-decoration:none;color:var(--ad-black);transition:transform .15s ease,box-shadow .15s ease;}
			.ad-health-tile:hover,.ad-health-tile:focus{transform:translateY(-3px);box-shadow:0 12px 28px rgba(8,8,8,.10);text-decoration:none;color:var(--ad-black);outline-offset:2px;}
			.ad-health-tile:focus-visible{outline:2px solid var(--ad-orange);outline-offset:2px;}
			.ad-health-num{font-size:28px;font-weight:700;color:var(--ad-orange);line-height:1;}
			.ad-health-label{font-size:12px;color:#555;font-weight:700;line-height:1.3;}
			.ad-health-tile--placeholder{flex:1;background:#fbfbfb;border:1px solid #ececec;border-style:dashed;border-radius:16px;padding:16px;display:flex;flex-direction:column;gap:4px;}
			.ad-health-placeholder-label{font-size:12px;color:#777;font-weight:700;line-height:1.3;}
			.ad-health-placeholder-body{font-size:13px;color:#777;line-height:1.55;}
			.ad-health-empty{background:#fbfbfb;border:1px solid #ffd8c9;border-radius:8px;padding:10px 14px;font-size:12px;font-weight:700;color:var(--ad-black);margin:0 0 24px;max-width:880px;}
		</style>';
	}
}
