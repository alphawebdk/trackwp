<?php
/**
 * TrackWP Uninstall
 *
 * Removes all plugin data when uninstalled via WordPress admin.
 *
 * @package TrackWP
 */

// If uninstall not called from WordPress, die.
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
    die;
}

// Delete all plugin options.
$options = array(
    'trackwp_platforms',
    'trackwp_events',
    'trackwp_consent',
    'trackwp_advanced',
    'trackwp_version',
    'trackwp_consent_log',
    'trackwp_stats',
    'trackwp_cookie_declarations',
);

foreach ( $options as $option ) {
    delete_option( $option );
}

// Delete transients.
global $wpdb;
$wpdb->query(
    "DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_trackwp_%' OR option_name LIKE '_transient_timeout_trackwp_%'"
);

// Clear scheduled cron events (covers WP-CLI uninstall without prior deactivation).
wp_clear_scheduled_hook( 'trackwp_flush_ga4' );
wp_clear_scheduled_hook( 'trackwp_prune_delivery_log' );

// Drop the delivery-log table.
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}trackwp_delivery_log" );

// Delete all plugin files in the log directory, then the directory itself.
$log_dir = WP_CONTENT_DIR . '/trackwp';
$log_files = array(
    $log_dir . '/debug.log',
    $log_dir . '/capi-errors.log',
    $log_dir . '/google-ads-pending.log',
    $log_dir . '/.htaccess',
    $log_dir . '/index.html',
);
foreach ( $log_files as $log_file ) {
    if ( file_exists( $log_file ) ) {
        @unlink( $log_file );
    }
}
if ( is_dir( $log_dir ) ) {
    @rmdir( $log_dir );
}
