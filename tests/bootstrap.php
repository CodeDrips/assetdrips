<?php
/**
 * PHPUnit bootstrap for the WordPress test suite.
 *
 * Expects the WP test library to be installed (e.g. via bin/install-wp-tests.sh).
 * Set WP_TESTS_DIR to override the default lookup path.
 *
 * @package AssetDrips
 */

declare( strict_types=1 );

$assetdrips_tests_dir = getenv( 'WP_TESTS_DIR' );

if ( false === $assetdrips_tests_dir || '' === $assetdrips_tests_dir ) {
	$assetdrips_tests_dir = rtrim( sys_get_temp_dir(), '/\\' ) . '/wordpress-tests-lib';
}

$assetdrips_functions = $assetdrips_tests_dir . '/includes/functions.php';

if ( ! is_readable( $assetdrips_functions ) ) {
	echo 'Could not find the WordPress test suite at ' . esc_html( $assetdrips_tests_dir ) . PHP_EOL;
	echo 'Install it first (bin/install-wp-tests.sh) or set WP_TESTS_DIR.' . PHP_EOL;
	exit( 1 );
}

// Composer autoload so plugin classes are available inside tests.
require_once dirname( __DIR__ ) . '/vendor/autoload.php';

require_once $assetdrips_functions;

/**
 * Load the plugin into the test WordPress instance.
 *
 * @return void
 */
function assetdrips_manually_load_plugin(): void {
	require dirname( __DIR__ ) . '/assetdrips.php';
}
tests_add_filter( 'muplugins_loaded', 'assetdrips_manually_load_plugin' );

require $assetdrips_tests_dir . '/includes/bootstrap.php';
