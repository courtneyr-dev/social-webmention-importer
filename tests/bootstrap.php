<?php
/**
 * PHPUnit bootstrap for Social Webmention Importer.
 *
 * Both suites run against the WordPress test environment (matching the
 * post-kinds-for-indieweb convention): the "unit" suite exercises pure
 * parsing/normalization classes and simply doesn't touch the database.
 *
 * @package CourtneyRDev\SocialWebmentionImporter
 */

require_once dirname( __DIR__ ) . '/vendor/autoload.php';

$_tests_dir = getenv( 'WP_TESTS_DIR' );
if ( ! $_tests_dir ) {
	$_tests_dir = rtrim( sys_get_temp_dir(), '/\\' ) . '/wordpress-tests-lib';
}

if ( file_exists( dirname( __DIR__ ) . '/vendor/yoast/phpunit-polyfills/phpunitpolyfills-autoload.php' ) ) {
	define( 'WP_TESTS_PHPUNIT_POLYFILLS_PATH', dirname( __DIR__ ) . '/vendor/yoast/phpunit-polyfills/' );
}

if ( ! file_exists( $_tests_dir . '/includes/functions.php' ) ) {
	echo "Could not find {$_tests_dir}/includes/functions.php. Set WP_TESTS_DIR to a WordPress tests checkout.\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	exit( 1 );
}

require_once $_tests_dir . '/includes/functions.php';

/**
 * Load the plugin under test (and the Webmention plugin when available, so
 * integration tests can exercise real compatibility instead of skipping).
 */
function swi_tests_manually_load_plugin() {
	$webmention = getenv( 'SWI_WEBMENTION_PLUGIN' );
	if ( $webmention && file_exists( $webmention ) ) {
		require $webmention;
	}

	require dirname( __DIR__ ) . '/social-webmention-importer.php';
}

tests_add_filter( 'muplugins_loaded', 'swi_tests_manually_load_plugin' );

require $_tests_dir . '/includes/bootstrap.php';

require_once __DIR__ . '/class-fixtures.php';
