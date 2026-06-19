<?php
/**
 * Detects active builders, custom-table plugins, and offloaded media.
 *
 * @package AssetDrips
 */

declare( strict_types=1 );

namespace AssetDrips\Coverage;

defined( 'ABSPATH' ) || exit;

/**
 * Produces a {@see CoverageReport} from the site's plugin/theme environment.
 *
 * Version 1 does not parse builder data, read custom tables, or normalise
 * offloaded URLs. This detector names those blind spots so the scorer can hold
 * affected media below HIGH. It detects presence only — never parses — as scoped.
 *
 * The rules are pure: {@see detect()} takes plain lists and returns a report, so
 * the mapping is unit-testable. {@see from_wordpress()} gathers the live signals.
 */
final class BuilderDetector {

	/**
	 * Page-builder plugin slugs to flag (directory => label). Significant: media
	 * referenced only inside builder data is invisible to v1.
	 *
	 * @var array<string, string>
	 */
	private const BUILDER_PLUGINS = array(
		'elementor'                   => 'Elementor',
		'beaver-builder-lite-version' => 'Beaver Builder',
		'bb-plugin'                   => 'Beaver Builder',
		'js_composer'                 => 'WPBakery Page Builder',
		'visualcomposer'              => 'Visual Composer',
		'oxygen'                      => 'Oxygen',
		'brizy'                       => 'Brizy',
		'siteorigin-panels'           => 'SiteOrigin Page Builder',
		'fusion-builder'              => 'Fusion Builder (Avada)',
		'cornerstone'                 => 'Cornerstone',
		'themify-builder'             => 'Themify Builder',
		'live-composer-page-builder'  => 'Live Composer',
	);

	/**
	 * Builder themes to flag (slug => label).
	 *
	 * @var array<string, string>
	 */
	private const BUILDER_THEMES = array(
		'divi'     => 'Divi',
		'extra'    => 'Extra (Divi)',
		'bricks'   => 'Bricks',
		'avada'    => 'Avada',
		'flatsome' => 'Flatsome (UX Builder)',
	);

	/**
	 * Plugins that can store references in custom tables we do not scan
	 * (directory => label). Significant: a reference there is invisible to v1.
	 *
	 * @var array<string, string>
	 */
	private const CUSTOM_TABLE_PLUGINS = array(
		'toolset-types' => 'Toolset',
		'types'         => 'Toolset',
		'jet-engine'    => 'JetEngine',
		'pods'          => 'Pods',
		'meta-box'      => 'Meta Box',
		'meta-box-aio'  => 'Meta Box',
	);

	/**
	 * Media offload plugins (directory => label). Significant: stored or served
	 * URLs may not resolve against the local uploads directory.
	 *
	 * @var array<string, string>
	 */
	private const OFFLOAD_PLUGINS = array(
		'amazon-s3-and-cloudfront' => 'WP Offload Media',
		'wp-stateless'             => 'WP-Stateless (Google Cloud)',
		'ilab-media-tools'         => 'Media Cloud',
		'be-media-from-production' => 'BE Media from Production',
	);

	/**
	 * Detect coverage gaps from plain environment signals.
	 *
	 * @param string[] $active_plugins Active plugin paths (e.g. "elementor/elementor.php").
	 * @param string[] $theme_slugs    Active template/stylesheet slugs.
	 * @param bool     $acf_json       Whether an acf-json directory is present.
	 * @param bool     $offloaded_host Whether the uploads host differs from the site host.
	 * @return CoverageReport
	 */
	public static function detect( array $active_plugins, array $theme_slugs, bool $acf_json = false, bool $offloaded_host = false ): CoverageReport {
		$report = new CoverageReport();

		foreach ( $active_plugins as $plugin ) {
			$slug = self::plugin_slug( (string) $plugin );

			if ( isset( self::BUILDER_PLUGINS[ $slug ] ) ) {
				$report->add( self::flag( CoverageFlag::BUILDER, $slug, self::BUILDER_PLUGINS[ $slug ], 'page builder' ) );
			}
			if ( isset( self::CUSTOM_TABLE_PLUGINS[ $slug ] ) ) {
				$report->add( self::flag( CoverageFlag::CUSTOM_TABLE, $slug, self::CUSTOM_TABLE_PLUGINS[ $slug ], 'custom-table plugin' ) );
			}
			if ( isset( self::OFFLOAD_PLUGINS[ $slug ] ) ) {
				$report->add( self::flag( CoverageFlag::OFFLOADED, $slug, self::OFFLOAD_PLUGINS[ $slug ], 'media offload plugin' ) );
			}
		}

		foreach ( $theme_slugs as $theme ) {
			$slug = strtolower( (string) $theme );
			if ( isset( self::BUILDER_THEMES[ $slug ] ) ) {
				$report->add( self::flag( CoverageFlag::BUILDER, $slug, self::BUILDER_THEMES[ $slug ], 'builder theme' ) );
			}
		}

		if ( $offloaded_host ) {
			$report->add(
				new CoverageFlag(
					'offloaded.host',
					CoverageFlag::OFFLOADED,
					CoverageFlag::SIGNIFICANT,
					'Media is served from a different host than the site.',
					'uploads host differs'
				)
			);
		}

		if ( $acf_json ) {
			$report->add(
				new CoverageFlag(
					'acf.local_json',
					CoverageFlag::OTHER,
					CoverageFlag::MINOR,
					'ACF local JSON detected; field types are read by name heuristic only.',
					'acf-json directory present'
				)
			);
		}

		return $report;
	}

	/**
	 * Gather live signals and detect.
	 *
	 * @return CoverageReport
	 */
	public static function from_wordpress(): CoverageReport {
		$active  = (array) get_option( 'active_plugins', array() );
		$network = array_keys( (array) get_site_option( 'active_sitewide_plugins', array() ) );
		$plugins = array_values( array_unique( array_merge( $active, $network ) ) );

		$themes = array_values( array_unique( array_filter( array( get_template(), get_stylesheet() ) ) ) );

		$acf_json = is_dir( get_stylesheet_directory() . '/acf-json' ) || is_dir( get_template_directory() . '/acf-json' );

		return self::detect( $plugins, $themes, $acf_json, self::uploads_host_differs() );
	}

	/**
	 * Whether the uploads base URL host differs from the site host.
	 *
	 * @return bool
	 */
	private static function uploads_host_differs(): bool {
		$uploads = wp_get_upload_dir();

		$upload_host = wp_parse_url( (string) $uploads['baseurl'], PHP_URL_HOST );
		$site_host   = wp_parse_url( home_url(), PHP_URL_HOST );

		return is_string( $upload_host ) && is_string( $site_host )
			&& '' !== $upload_host && 0 !== strcasecmp( $upload_host, $site_host );
	}

	/**
	 * Directory slug of a plugin path.
	 *
	 * @param string $plugin Plugin path, e.g. "elementor/elementor.php".
	 * @return string
	 */
	private static function plugin_slug( string $plugin ): string {
		$pos = strpos( $plugin, '/' );

		return false === $pos ? $plugin : substr( $plugin, 0, $pos );
	}

	/**
	 * Build a significant flag for a detected plugin/theme.
	 *
	 * @param string $category One of the CoverageFlag category constants.
	 * @param string $slug     Detected slug.
	 * @param string $label    Product name.
	 * @param string $kind     Short noun for the message ("page builder").
	 * @return CoverageFlag
	 */
	private static function flag( string $category, string $slug, string $label, string $kind ): CoverageFlag {
		return new CoverageFlag(
			$category . '.' . $slug,
			$category,
			CoverageFlag::SIGNIFICANT,
			$label . ' is active; its ' . $kind . ' data is not parsed in v1.',
			$slug
		);
	}
}
