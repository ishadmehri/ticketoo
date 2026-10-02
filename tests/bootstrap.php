<?php
/**
 * PHPUnit bootstrap for Ticketoo tests.
 *
 * Loads the PHPUnit Polyfills (dev dependency), then the WordPress test
 * framework shipped with wp-env (WP_TESTS_DIR), then the plugin under test
 * before WordPress finishes booting.
 *
 * @package Ticketoo
 */

$_tests_dir = getenv( 'WP_TESTS_DIR' );

if ( ! $_tests_dir ) {
	echo 'WP_TESTS_DIR is not set. Run the suite through wp-env: npm run phpunit.' . PHP_EOL;
	exit( 1 );
}

$ticketoo_polyfills = dirname( __DIR__ ) . '/vendor/yoast/phpunit-polyfills/phpunitpolyfills-autoload.php';

if ( ! file_exists( $ticketoo_polyfills ) ) {
	echo 'PHPUnit Polyfills not found. Install dev dependencies first: npm run composer -- install' . PHP_EOL;
	exit( 1 );
}

require_once $ticketoo_polyfills;

// Gives access to tests_add_filter() and friends.
require_once $_tests_dir . '/includes/functions.php';

/**
 * Loads the plugin under test on muplugins_loaded, before WordPress finishes booting.
 */
function ticketoo_load_plugin() {
	require dirname( __DIR__ ) . '/ticketoo.php';
}
tests_add_filter( 'muplugins_loaded', 'ticketoo_load_plugin' );

// Start up the WP testing environment.
require_once $_tests_dir . '/includes/bootstrap.php';

// Shared base case for tests that run the plugin's DDL against real tables
// (loaded here so WP_UnitTestCase exists; not collected as a test itself).
require_once __DIR__ . '/database-testcase.php';
