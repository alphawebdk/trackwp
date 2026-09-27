<?php
/**
 * TrackWP Uninstall
 *
 * Removes all plugin data when uninstalled via WordPress admin or WP-CLI.
 * On multisite every site is cleaned (options, transients, cron, tables and
 * order meta live per site, under each site's table prefix).
 *
 * @package TrackWP
 */

// If uninstall not called from WordPress, die.
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
    die;
}

/**
 * Remove TrackWP data from the CURRENT site (uses the current $wpdb prefix,
 * so it works after switch_to_blog()).
 *
 * @return void
 */
function trackwp_uninstall_site() {
    global $wpdb;

    // Known plugin options.
    $options = array(
        'trackwp_platforms',
        'trackwp_events',
        'trackwp_consent',
        'trackwp_advanced',
        'trackwp_version',
        'trackwp_consent_log',
        'trackwp_stats',
        'trackwp_cookie_declarations',
        'trackwp_woocommerce',
    );
    foreach ( $options as $option ) {
        delete_option( $option );
    }

    // Transients (incl. rate-limit buckets _transient_trackwp_rl_* and the
    // consent ts transients _transient_trackwp_cts_*).
    $wpdb->query(
        $wpdb->prepare(
            "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
            $wpdb->esc_like( '_transient_trackwp_' ) . '%',
            $wpdb->esc_like( '_transient_timeout_trackwp_' ) . '%'
        )
    );

    // Any remaining plugin option (settings added after this list was written).
    $wpdb->query(
        $wpdb->prepare(
            "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
            $wpdb->esc_like( 'trackwp_' ) . '%'
        )
    );
    wp_cache_delete( 'alloptions', 'options' );
    wp_cache_delete( 'notoptions', 'options' );

    // Scheduled cron events (covers WP-CLI uninstall without prior deactivation).
    $cron_hooks = array(
        'trackwp_flush_ga4',
        'trackwp_prune_delivery_log',
        'trackwp_prune_consent_log',
        'trackwp_migrate_consent_log',
    );
    foreach ( $cron_hooks as $hook ) {
        wp_clear_scheduled_hook( $hook );
    }

    // Plugin tables: delivery log, consent log, consent-text snapshots and
    // order claims. Names are $wpdb->prefix plus constants (a table name
    // cannot be a prepare() placeholder); no user input.
    $tables = array(
        'trackwp_delivery_log',
        'trackwp_consent_log',
        'trackwp_consent_texts',
        'trackwp_order_claims',
    );
    foreach ( $tables as $table ) {
        $wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}{$table}" );
    }

    // Per-order meta (purchase flag from 1.10.0, checkout attribution from
    // 1.10.1). Both storage backends are cleaned: HPOS keeps order meta in its
    // own table, and the legacy postmeta table still holds it on sites that
    // never migrated (or that run in sync mode).
    $meta_keys = array( '_trackwp_purchase_sent', '_trackwp_attribution' );
    foreach ( $meta_keys as $meta_key ) {
        $wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->postmeta} WHERE meta_key = %s", $meta_key ) );
    }
    $hpos_meta = $wpdb->prefix . 'wc_orders_meta';
    if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $hpos_meta ) ) ) === $hpos_meta ) {
        foreach ( $meta_keys as $meta_key ) {
            $wpdb->query( $wpdb->prepare( "DELETE FROM {$hpos_meta} WHERE meta_key = %s", $meta_key ) );
        }
    }
}

if ( is_multisite() && function_exists( 'get_sites' ) ) {
    $trackwp_site_ids = get_sites(
        array(
            'fields' => 'ids',
            'number' => 0,
        )
    );
    foreach ( $trackwp_site_ids as $trackwp_site_id ) {
        switch_to_blog( (int) $trackwp_site_id );
        trackwp_uninstall_site();
        restore_current_blog();
    }
} else {
    trackwp_uninstall_site();
}

// Delete all plugin files in the log directory, then the directory itself.
// The directory is shared by all sites (WP_CONTENT_DIR), so this runs once.
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
