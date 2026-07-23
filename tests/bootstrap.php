<?php
/**
 * PHPUnit bootstrap for TrackWP plugin.
 *
 * Assumes WordPress test suite is installed at WP_TESTS_DIR (default /tmp/wordpress-tests-lib).
 * Run install-wp-tests.sh first to set up the test environment.
 */

$_tests_dir = getenv( 'WP_TESTS_DIR' );
if ( ! $_tests_dir ) {
    $_tests_dir = rtrim( sys_get_temp_dir(), '/\\' ) . '/wordpress-tests-lib';
}

if ( ! file_exists( $_tests_dir . '/includes/functions.php' ) ) {
    echo "WordPress test suite not found at {$_tests_dir}. Run install-wp-tests.sh first.\n";
    exit( 1 );
}

require_once $_tests_dir . '/includes/functions.php';

function _trackwp_manually_load_plugin() {
    require dirname( __DIR__ ) . '/trackwp.php';
}
tests_add_filter( 'muplugins_loaded', '_trackwp_manually_load_plugin' );

require $_tests_dir . '/includes/bootstrap.php';
