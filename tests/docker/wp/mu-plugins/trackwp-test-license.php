<?php
/**
 * Test-only mu-plugin: runs the E2E containers with tracking allowed, as if a
 * valid license were active. Never present in the shipped plugin build.
 *
 * The license class is loaded straight from the plugin directory, because mu-plugins
 * load before the plugin and its autoloader.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

$trackwp_license_file = WP_PLUGIN_DIR . '/trackwp/includes/class-trackwp-license.php';
if ( file_exists( $trackwp_license_file ) ) {
    require_once $trackwp_license_file;
    TrackWP_License::force_state_for_tests( true );
}
unset( $trackwp_license_file );
