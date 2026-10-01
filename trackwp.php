<?php
/**
 * Plugin Name: TrackWP
 * Plugin URI: https://trackwp.com
 * Description: Server-side tracking proxy with built-in cookie consent and Consent Mode v2. Supports GA4, Google Ads, and Meta.
 * Version: 1.11.2
 * Author: TrackWP
 * Author URI: https://trackwp.com
 * License: GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: trackwp
 * Domain Path: /languages
 * Requires PHP: 7.4
 * Requires at least: 6.0
 * Update URI: https://github.com/alphawebdk/trackwp
 */

defined('ABSPATH') || exit;

define('TRACKWP_VERSION', '1.11.2');
define('TRACKWP_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('TRACKWP_PLUGIN_URL', plugin_dir_url(__FILE__));
define('TRACKWP_PLUGIN_BASENAME', plugin_basename(__FILE__));

/**
 * Self-hosted updates via GitHub Releases (public repo alphawebdk/trackwp).
 *
 * Plugin Update Checker polls the repo's latest release and serves its ZIP
 * asset through WordPress' standard update flow (update notice + one-click
 * update in wp-admin, wp-cron twice-daily checks).
 *
 * The repo is PUBLIC (since 2026-07-23), so no authentication is needed on
 * client sites. A token is still honoured — define TRACKWP_GITHUB_TOKEN in
 * wp-config.php or use the 'trackwp_github_token' filter — which is useful if
 * the repo ever goes private again, or to lift GitHub's unauthenticated rate
 * limit when many sites share one outbound IP.
 */
if (file_exists(TRACKWP_PLUGIN_DIR . 'vendor/plugin-update-checker/plugin-update-checker.php')) {
    require_once TRACKWP_PLUGIN_DIR . 'vendor/plugin-update-checker/plugin-update-checker.php';

    $trackwp_update_checker = \YahnisElsts\PluginUpdateChecker\v5\PucFactory::buildUpdateChecker(
        'https://github.com/alphawebdk/trackwp/',
        __FILE__,
        'trackwp'
    );
    // Use the ZIP attached to the GitHub Release (built by build pipeline),
    // not the auto-generated source tarball (which lacks minified assets' guarantees).
    $trackwp_update_checker->getVcsApi()->enableReleaseAssets('/trackwp.*\.zip/i');

    $trackwp_token = apply_filters(
        'trackwp_github_token',
        defined('TRACKWP_GITHUB_TOKEN') ? TRACKWP_GITHUB_TOKEN : ''
    );
    if (!empty($trackwp_token)) {
        $trackwp_update_checker->setAuthentication($trackwp_token);
    }
}

/**
 * Class autoloader.
 * Maps TrackWP_Proxy => includes/class-trackwp-proxy.php
 */
spl_autoload_register(function ($class) {
    if (strpos($class, 'TrackWP_') !== 0) {
        return;
    }

    $relative = substr($class, strlen('TrackWP_'));
    $filename = 'class-trackwp-' . str_replace('_', '-', strtolower($relative)) . '.php';
    $filepath = TRACKWP_PLUGIN_DIR . 'includes/' . $filename;

    if (file_exists($filepath)) {
        require_once $filepath;
    }
});

/**
 * Main TrackWP singleton class.
 */
final class TrackWP {

    /** Daily consent-log pruning (TrackWP_Consent_Log::prune); same as TrackWP_Consent_Log::CRON_HOOK. */
    const CRON_PRUNE_CONSENT_LOG = 'trackwp_prune_consent_log';

    /** Single event that continues a cut-off consent-log migration; same as TrackWP_Consent_Log::MIGRATE_HOOK. */
    const CRON_MIGRATE_CONSENT_LOG = 'trackwp_migrate_consent_log';

    /**
     * Option flag: show the "purge your CDN" notice after an upgrade. Value
     * is the version string that triggered it (KC15, PLAN-1.11.1-v2). Before
     * 1.11.1 this held a fixed key per version (trackwp_upgrade_notice_1_10_1
     * with value 1); upgrade_1_11_1() deletes that old key.
     */
    const OPTION_UPGRADE_NOTICE = 'trackwp_upgrade_notice';

    /**
     * Option flag (not autoloaded): D1 flipped Meta delivery on for this site
     * during the 1.11.1 upgrade (missing_conditions() no longer requires a
     * token). Shown once, dismissible, separate from OPTION_UPGRADE_NOTICE.
     */
    const OPTION_META_TAKEOVER_NOTICE = 'trackwp_upgrade_notice_meta_takeover';

    /** Option holding the material_hash baseline the admin notice compares against. */
    const OPTION_MATERIAL_HASH = 'trackwp_consent_material_hash';

    /** @var TrackWP|null */
    private static $instance = null;

    /** @var TrackWP_Consent|null */
    private $consent;

    /** @var TrackWP_Forms|null */
    private $forms;

    /** @var TrackWP_WooCommerce|null */
    private $woocommerce;

    /**
     * Get singleton instance.
     *
     * @return TrackWP
     */
    public static function instance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        $this->init_hooks();
    }

    private function init_hooks() {
        register_activation_hook(__FILE__, [$this, 'activate']);
        register_deactivation_hook(__FILE__, [$this, 'deactivate']);

        add_action('plugins_loaded', [$this, 'load_textdomain']);
        add_action('init', [$this, 'init']);
        add_action('admin_menu', [$this, 'admin_menu']);
        add_action('admin_init', [$this, 'admin_init']);
        add_action('rest_api_init', [$this, 'rest_api_init']);
        add_action('wp_enqueue_scripts', [$this, 'enqueue_frontend_scripts']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_admin_scripts']);
        // The one head inline script — wp_head pri 1 (must run BEFORE GTM pri 5):
        // consent reader, URL/title cleaner, Consent Mode defaults, restore.
        add_action('wp_head', [$this, 'render_consent_mode_defaults'], 1);
        // GTM container — wp_head pri 5 (must run AFTER consent defaults).
        add_action('wp_head', [$this, 'render_gtm_head'], 5);
        // gtag.js loader — wp_head pri 6 (after consent defaults pri 1; skipped entirely when GTM is used).
        add_action('wp_head', [$this, 'render_gtag_head'], 6);
        // Meta Pixel — wp_head pri 7 (after gtag pri 6; skipped entirely when GTM is used).
        add_action('wp_head', [$this, 'render_meta_pixel'], 7);
        add_action('wp_body_open', [$this, 'render_gtm_noscript'], 5);
        // Upgrade routine. Runs on init@1 (not plugins_loaded) because the
        // 1.10.1 text migration compares stored texts with their translated
        // defaults, and translations are not available before init (WP 6.7+
        // flags early _load_textdomain_just_in_time calls). Still runs before
        // every TrackWP class is constructed on init@10.
        add_action('init', [$this, 'maybe_upgrade'], 1);
        // GA4 batch flush — must be registered at bootstrap so the callback
        // exists in WP-Cron requests (no TrackWP_GA4 instance exists there).
        add_action('trackwp_flush_ga4', [$this, 'flush_ga4_queue']);
        // Delivery-log pruning — registered at bootstrap for the same reason as
        // the GA4 flush: the callback must exist in WP-Cron requests.
        add_action(TrackWP_Delivery_Log::CRON_HOOK, ['TrackWP_Delivery_Log', 'prune']);
        // Consent-log pruning and the chunked 1.10.1 consent-log migration.
        // String callbacks: the class is only autoloaded when the cron fires.
        add_action(self::CRON_PRUNE_CONSENT_LOG, ['TrackWP_Consent_Log', 'prune']);
        add_action(self::CRON_MIGRATE_CONSENT_LOG, [$this, 'run_consent_log_migration']);
        // Keep cron jobs in step with the settings whenever they are saved.
        add_action('update_option_trackwp_advanced', [$this, 'sync_cron_jobs']);
        add_action('update_option_trackwp_consent', [$this, 'sync_cron_jobs']);
        // Blocker rules saved: recompile the cached rule set and purge page
        // caches, so no cached page keeps the old markup (KB7, §3.5).
        add_action('update_option_trackwp_blocker', [$this, 'on_blocker_saved']);
        add_action('add_option_trackwp_blocker', [$this, 'on_blocker_saved']);
        // M2 (KB15): Meta for WooCommerce reads this filter in its tracker's
        // constructor, which runs on 'init'. Registered here, unconditionally,
        // so it exists before fb4woo initialises; the callback itself returns
        // false only when TrackWP can actually deliver Pixel + CAPI.
        add_filter('facebook_for_woocommerce_integration_pixel_enabled', ['TrackWP_Meta_Takeover', 'filter_pixel_enabled']);
        // 1.10.1 upgrade side effects: purge known page caches, flag a CDN notice.
        add_action('trackwp_upgraded_1_10_1', [$this, 'purge_known_page_caches']);
        add_action('admin_notices', [$this, 'render_upgrade_notice']);
        add_action('admin_post_trackwp_dismiss_upgrade_notice', [$this, 'handle_dismiss_upgrade_notice']);
        // D1: one-time notice when Meta delivery flips on during the 1.11.1
        // upgrade because the CAPI token requirement is dropped (KC16).
        add_action('admin_notices', [$this, 'render_meta_takeover_notice']);
        add_action('admin_post_trackwp_dismiss_meta_takeover_notice', [$this, 'handle_dismiss_meta_takeover_notice']);
        // Front-end admin-bar shortcut to the console debug mode.
        add_action('admin_bar_menu', [$this, 'admin_bar_debug_link'], 100);

        // KC3/D6: the servercookie gate (new class, W1). class_exists() also
        // triggers the autoloader, so this is a no-op until the file lands.
        if ( class_exists('TrackWP_Cookie_Gate') ) {
            TrackWP_Cookie_Gate::hook();
        }
        // TR5: same no-cache trio as click-id pages, but for admins previewing
        // the dataLayer in "test" mode (KC1/KC7).
        add_action('template_redirect', [$this, 'maybe_set_datalayer_test_nocache'], 0);

        // "Settings" link on Plugins page
        add_filter('plugin_action_links_' . TRACKWP_PLUGIN_BASENAME, ['TrackWP_Settings', 'add_plugin_action_links']);

        add_action( 'admin_post_trackwp_export', [ $this, 'handle_export' ] );
        add_action( 'admin_post_trackwp_import', [ $this, 'handle_import' ] );
        add_action( 'admin_post_trackwp_reset_stats', [ $this, 'handle_reset_stats' ] );
        add_action( 'admin_post_trackwp_clear_delivery_log', [ $this, 'handle_clear_delivery_log' ] );
    }

    /**
     * admin-post handler — empty the delivery log.
     */
    public function handle_clear_delivery_log() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'Adgang nægtet.', 'trackwp' ) );
        }
        check_admin_referer( 'trackwp_clear_delivery_log' );
        TrackWP_Delivery_Log::clear();
        wp_safe_redirect( add_query_arg( 'trackwp_log_cleared', '1', admin_url( 'admin.php?page=trackwp#advanced' ) ) );
        exit;
    }

    /**
     * Plugin activation — create default options.
     */
    public function activate() {
        add_option('trackwp_platforms', [
            'ga4_enabled'                    => false,
            'ga4_measurement_id'             => '',
            'ga4_api_secret'                 => '',
            'ga4_gtag_enabled'               => true,
            'google_ads_enabled'             => false,
            'google_ads_conversion_id'       => '',
            'google_ads_customer_id'         => '',
            'google_ads_conversion_action_id' => '',
            'google_ads_developer_token'     => '',
            'google_ads_oauth_client_id'     => '',
            'google_ads_oauth_client_secret' => '',
            'google_ads_oauth_refresh_token' => '',
            'meta_enabled'                   => false,
            'meta_pixel_id'                  => '',
            'meta_access_token'              => '',
            'meta_test_event_code'           => '',
            'meta_api_version'               => 'v25.0',
            'meta_pixel_client_enabled'      => true,
            'gtm_enabled'                    => false,
            'gtm_container_id'               => '',
        ]);

        add_option('trackwp_events', [
            [
                'enabled'      => true,
                'name'         => 'phone_click',
                'display_name' => __('Telefonklik', 'trackwp'),
                'trigger_type' => 'css_click',
                'css_selector' => 'a[href^="tel:"]',
                'value'        => 0,
                'currency'     => 'DKK',
                'ads_label'    => '',
                'meta_event'   => 'Contact',
                'send_to'      => ['ga4' => true, 'google_ads' => true, 'meta' => true],
            ],
            [
                'enabled'      => true,
                'name'         => 'email_click',
                'display_name' => __('E-mailklik', 'trackwp'),
                'trigger_type' => 'css_click',
                'css_selector' => 'a[href^="mailto:"]',
                'value'        => 0,
                'currency'     => 'DKK',
                'ads_label'    => '',
                'meta_event'   => 'Contact',
                'send_to'      => ['ga4' => true, 'google_ads' => true, 'meta' => true],
            ],
            [
                'enabled'      => true,
                'name'         => 'form_submit',
                'display_name' => __('Formularindsendelse', 'trackwp'),
                'trigger_type' => 'form_submit',
                // Empty on purpose: the generic 'form_submit' event is bound by
                // TrackWP_Forms (which covers the known form plugins). A
                // selector here would only matter for a renamed copy.
                'css_selector' => '',
                'value'        => 0,
                'currency'     => 'DKK',
                'ads_label'    => '',
                'meta_event'   => 'Lead',
                'send_to'      => ['ga4' => true, 'google_ads' => true, 'meta' => true],
            ],
        ]);

        add_option('trackwp_consent', [
            'banner_style'              => 'dialog',
            'bg_color'                  => '#274A45',
            'text_color'                => '#ffffff',
            'accent_color'              => '#30D3C0',
            'button_text_color'         => '#274A45',
            'border_radius'             => 8,
            'heading'                   => __('Vi bruger cookies', 'trackwp'),
            'description'               => __('Vi bruger cookies til at forbedre din oplevelse og analysere trafik. Vælg dine præferencer nedenfor.', 'trackwp'),
            'accept_text'               => __('Accepter alle', 'trackwp'),
            'reject_text'               => __('Afvis valgfrie', 'trackwp'),
            'customize_text'            => __('Tilpas', 'trackwp'),
            'save_text'                 => __('Gem præferencer', 'trackwp'),
            'privacy_page_id'           => 0,
            'language'                  => 'da',
            'require_active_consent'    => true,
            'log_consent'               => true,
            'reconsent_on_policy_change' => true,
            'cookie_lifetime_months'    => 12,
            'consent_version'           => 1,
        ]);

        add_option('trackwp_advanced', [
            'endpoint_path'                => 'event',
            'first_party_cookie_enabled'   => true,
            'cookie_name'                  => '_twp_cid',
            'cookie_lifetime_months'       => 24,
            'consent_mode_cookieless_pings' => true,
            'consent_mode_ad_signals'      => true,
            'debug_log'                    => false,
            'debug_console'                => false,
            'dedup_mode'                   => 'client_and_server',
            'uses_gtm'                     => false,
            'ga4_user_id_enabled'          => false,
            'batching_enabled'             => false,
            'first_party_loader_enabled'   => true,
            'capi_debug_logging_enabled'   => false,
            // Off by default — enabling it creates a data store.
            'delivery_log_enabled'         => false,
            'delivery_log_retention_days'  => TrackWP_Delivery_Log::DEFAULT_RETENTION_DAYS,
        ]);

        add_option('trackwp_stats', array());
        add_option('trackwp_cookie_declarations', array());

        add_option('trackwp_version', '1.0.0');

        // Tables are created here as well as in the 1.10.1 upgrade block, so a
        // fresh install has them before the first consent or purchase arrives.
        // Both calls are dbDelta-based and idempotent.
        TrackWP_Consent_Log::create_tables();
        TrackWP_Order_Claims::create_table();
        self::maybe_upgrade_delivery_log_table();
        $this->sync_cron_jobs();
    }

    /**
     * Bring an existing delivery-log table up to the current schema (1.10.1
     * adds the `reason` column; without it every log insert fails). The
     * table is still only created for sites that switch the log on, as in
     * 1.9.0 — an unused table on every install is pointless.
     *
     * @return void
     */
    private static function maybe_upgrade_delivery_log_table() {
        if ( TrackWP_Delivery_Log::is_enabled() || TrackWP_Delivery_Log::table_exists() ) {
            TrackWP_Delivery_Log::create_table();
        }
    }

    /**
     * Schedule or clear every recurring TrackWP cron job so it matches the
     * current settings. Called on activation, on upgrade and whenever
     * trackwp_advanced or trackwp_consent is saved. Idempotent.
     *
     * The consent-log prune job always runs daily: retention applies to rows
     * already stored even after logging is switched off, and prune() is a
     * no-op when the table is missing or empty.
     *
     * @return void
     */
    public function sync_cron_jobs() {
        TrackWP_Delivery_Log::sync_cron();
        TrackWP_Consent_Log::sync_cron();
    }

    /**
     * Run (or continue) the consent-log migration from the legacy
     * trackwp_consent_log option into the trackwp_consent_log table.
     *
     * TrackWP_Consent_Log::migrate_legacy_option() works in chunks within a
     * time budget, holds a MySQL named lock, and returns true only once every
     * valid legacy entry is verifiably in the table and the option is gone.
     * Anything else (budget exhausted, lock held elsewhere, count mismatch)
     * reschedules a single cron event to continue.
     *
     * @return bool True when the migration is complete.
     */
    public function run_consent_log_migration() {
        $done = (bool) TrackWP_Consent_Log::migrate_legacy_option();
        if ( ! $done && ! wp_next_scheduled(self::CRON_MIGRATE_CONSENT_LOG) ) {
            wp_schedule_single_event(time() + MINUTE_IN_SECONDS, self::CRON_MIGRATE_CONSENT_LOG);
        }
        return $done;
    }

    /**
     * Migration routine — runs on plugins_loaded@5.
     */
    public function maybe_upgrade() {
        $stored = get_option('trackwp_version', '1.0.0');
        if ( version_compare($stored, TRACKWP_VERSION, '>=') ) {
            return;
        }

        if ( version_compare($stored, '1.1.0', '<') ) {
            $platforms = get_option('trackwp_platforms', array());
            $platforms += array(
                'gtm_enabled'      => false,
                'gtm_container_id' => '',
            );
            update_option('trackwp_platforms', $platforms);

            $advanced = get_option('trackwp_advanced', array());
            // Convert legacy full-URL endpoint_path to slug-only.
            if ( isset($advanced['endpoint_path']) && strpos($advanced['endpoint_path'], '/') !== false ) {
                $advanced['endpoint_path'] = 'event';
            }
            $advanced += array(
                'endpoint_path' => 'event',
                'dedup_mode'    => 'client_and_server',
            );
            update_option('trackwp_advanced', $advanced);
        }

        if ( version_compare($stored, '1.2.0', '<') ) {
            $platforms = get_option('trackwp_platforms', array());
            $platforms += array(
                'meta_test_event_code'            => '',
                'meta_api_version'                => 'v21.0',
                'google_ads_customer_id'          => '',
                'google_ads_conversion_action_id' => '',
                'google_ads_developer_token'      => '',
            );
            update_option('trackwp_platforms', $platforms);

            $advanced = get_option('trackwp_advanced', array());
            $advanced += array(
                'ga4_user_id_enabled'         => false,
                'batching_enabled'            => false,
                'first_party_loader_enabled'  => false,
                'capi_debug_logging_enabled'  => false,
            );
            update_option('trackwp_advanced', $advanced);
        }

        if ( version_compare($stored, '1.3.0', '<') ) {
            $consent = get_option('trackwp_consent', array());
            if ( isset($consent['banner_style']) ) {
                $map = array('bar_bottom' => 'bottombar', 'corner_popup' => 'dialog');
                if ( isset($map[ $consent['banner_style'] ]) ) {
                    $consent['banner_style'] = $map[ $consent['banner_style'] ];
                    update_option('trackwp_consent', $consent);
                }
            }
        }

        if ( version_compare($stored, '1.4.0', '<') ) {
            $platforms = get_option('trackwp_platforms', array());
            $platforms += array( 'ga4_gtag_enabled' => true );
            update_option('trackwp_platforms', $platforms);
        }

        if ( version_compare($stored, '1.5.0', '<') ) {
            $platforms = get_option('trackwp_platforms', array());
            $platforms += array(
                'meta_pixel_client_enabled'      => true,
                'google_ads_oauth_client_id'     => '',
                'google_ads_oauth_client_secret' => '',
                'google_ads_oauth_refresh_token' => '',
            );
            update_option('trackwp_platforms', $platforms);
        }

        if ( version_compare($stored, '1.6.0', '<') ) {
            add_option('trackwp_cookie_declarations', array());
        }

        if ( version_compare($stored, '1.7.0', '<') ) {
            // Enable the first-party loader (stronger adblock resistance) on upgrade.
            $advanced = get_option('trackwp_advanced', array());
            $advanced['first_party_loader_enabled'] = true;
            update_option('trackwp_advanced', $advanced);
        }

        if ( version_compare($stored, '1.7.1', '<') ) {
            // Default events were created with 'send_to' => [] on activation,
            // so server-side Google Ads dispatch (requires send_to['google_ads'])
            // never fired. Backfill empty/missing send_to with all platforms on.
            $events = get_option('trackwp_events', array());
            if ( is_array($events) ) {
                $changed = false;
                foreach ( $events as $i => $event ) {
                    if ( ! is_array($event) ) {
                        continue;
                    }
                    if ( empty($event['send_to']) || ! is_array($event['send_to']) ) {
                        $events[ $i ]['send_to'] = array( 'ga4' => true, 'google_ads' => true, 'meta' => true );
                        $changed = true;
                    }
                }
                if ( $changed ) {
                    update_option('trackwp_events', $events);
                }
            }
        }

        if ( version_compare($stored, '1.8.1', '<') ) {
            // Drop dead advanced flags (no UI, no reader since 1.7.2).
            $advanced = get_option('trackwp_advanced', array());
            if ( is_array($advanced) && ( array_key_exists('async_loading', $advanced) || array_key_exists('defer_tracking', $advanced) ) ) {
                unset($advanced['async_loading'], $advanced['defer_tracking']);
                update_option('trackwp_advanced', $advanced);
            }

            // De-duplicate event names. Two events sharing a name bound twice
            // client-side and dispatched twice with different event_ids — real
            // double counting in GA4/Meta. Keep the first occurrence.
            $events = get_option('trackwp_events', array());
            if ( is_array($events) ) {
                $seen    = array();
                $cleaned = array();
                foreach ( $events as $event ) {
                    if ( ! is_array($event) || empty($event['name']) ) {
                        continue;
                    }
                    if ( isset($seen[ $event['name'] ]) ) {
                        continue;
                    }
                    $seen[ $event['name'] ] = true;
                    $cleaned[] = $event;
                }
                if ( count($cleaned) !== count($events) ) {
                    update_option('trackwp_events', $cleaned);
                }
            }
        }

        if ( version_compare($stored, '1.9.0', '<') ) {
            $advanced = get_option('trackwp_advanced', array());
            $advanced += array(
                'delivery_log_enabled'        => false,
                'delivery_log_retention_days' => TrackWP_Delivery_Log::DEFAULT_RETENTION_DAYS,
            );
            update_option('trackwp_advanced', $advanced);
            // Only create the table if the site actually turns the log on —
            // an unused table on every install is pointless.
            if ( ! empty($advanced['delivery_log_enabled']) ) {
                TrackWP_Delivery_Log::create_table();
                TrackWP_Delivery_Log::sync_cron();
            }

            // Give every stored event an explicit firing-trigger list. Both the
            // browser and the admin derive one on the fly when it is missing,
            // so this is purely to make the stored option self-describing.
            $events = get_option('trackwp_events', array());
            if ( is_array($events) ) {
                $changed = false;
                foreach ( $events as $i => $event ) {
                    if ( ! is_array($event) || ! empty($event['firing_triggers']) ) {
                        continue;
                    }
                    $triggers = TrackWP_Conditions::triggers_from_legacy_event($event);
                    if ( ! empty($triggers) ) {
                        $events[ $i ]['firing_triggers'] = $triggers;
                        $changed = true;
                    }
                }
                if ( $changed ) {
                    update_option('trackwp_events', $events);
                }
            }
        }

        if ( version_compare($stored, '1.10.1', '<') ) {
            $this->upgrade_1_10_1($stored);
        }

        if ( version_compare($stored, '1.11.0', '<') ) {
            $this->upgrade_1_11_0();
        }

        if ( version_compare($stored, '1.11.1', '<') ) {
            $this->upgrade_1_11_1($stored);
        }

        $this->sync_cron_jobs();
        update_option('trackwp_version', TRACKWP_VERSION);

        // Fired after the version is stored, and only for a real upgrade (a
        // fresh install stores '1.0.0' on activation). Hooked by
        // purge_known_page_caches(); third parties can hook their own purge.
        if ( version_compare($stored, '1.10.1', '<') && version_compare($stored, '1.0.0', '>') ) {
            do_action('trackwp_upgraded_1_10_1', $stored);
        }
    }

    /**
     * 1.10.1 migration. Server-side only: it never sets or expires a visitor
     * cookie (R17) — the _twp_cid host-only migration happens in the
     * visitor's own /event and /keepalive requests.
     *
     * @param string $stored Version the site is upgrading from.
     * @return void
     */
    private function upgrade_1_10_1($stored) {
        // Tables (dbDelta, idempotent) and the chunked consent-log migration.
        TrackWP_Consent_Log::create_tables();
        TrackWP_Order_Claims::create_table();
        self::maybe_upgrade_delivery_log_table();
        $this->run_consent_log_migration();

        // Meta Graph API: v21.0-v24.0 are expired or about to expire; lift to
        // v25.0. Any other stored value (a deliberate newer pin) is kept.
        $platforms = get_option('trackwp_platforms', array());
        if ( is_array($platforms) && isset($platforms['meta_api_version'])
            && preg_match('/^v2[1-4]\.0$/', (string) $platforms['meta_api_version']) ) {
            $platforms['meta_api_version'] = 'v25.0';
            update_option('trackwp_platforms', $platforms);
        }

        $consent = get_option('trackwp_consent', array());
        if ( is_array($consent) ) {
            // K7: consent cookie lifetime 1-12 months.
            $months = isset($consent['cookie_lifetime_months']) ? (int) $consent['cookie_lifetime_months'] : 12;
            $consent['cookie_lifetime_months'] = max(1, min(12, $months));

            // "Afvis valgfrie" replaces the two old default labels; a custom
            // label is kept. Compared with both the source string and the
            // translation that was stored when the site was activated.
            $old_reject = array(
                'Afvis alle',
                'Kun nødvendige',
                __('Afvis alle', 'trackwp'),
                __('Kun nødvendige', 'trackwp'),
            );
            if ( isset($consent['reject_text']) && in_array(trim((string) $consent['reject_text']), $old_reject, true) ) {
                $consent['reject_text'] = __('Afvis valgfrie', 'trackwp');
            }

            // Banner description: the untouched (or empty) old default switches
            // to the dynamic default text; anything the owner wrote stays.
            if ( ! isset($consent['description_mode']) ) {
                $old_description = array(
                    'Vi bruger cookies til at forbedre din oplevelse og analysere trafik. Vælg dine præferencer nedenfor.',
                    __('Vi bruger cookies til at forbedre din oplevelse og analysere trafik. Vælg dine præferencer nedenfor.', 'trackwp'),
                );
                $description = isset($consent['description']) ? trim((string) $consent['description']) : '';
                $consent['description_mode'] = ( '' === $description || in_array($description, $old_description, true) ) ? 'auto' : 'custom';
            }

            // The reject button is always shown on the first layer now.
            unset($consent['show_reject_button']);
            update_option('trackwp_consent', $consent);
        }

        // K7: first-party client-id cookie lifetime 1-24 months.
        $advanced = get_option('trackwp_advanced', array());
        if ( is_array($advanced) ) {
            $months  = isset($advanced['cookie_lifetime_months']) ? (int) $advanced['cookie_lifetime_months'] : 24;
            $clamped = max(1, min(24, $months));
            if ( ! isset($advanced['cookie_lifetime_months']) || (int) $advanced['cookie_lifetime_months'] !== $clamped ) {
                $advanced['cookie_lifetime_months'] = $clamped;
                update_option('trackwp_advanced', $advanced);
            }
        }

        // count_on_hold is gone (R10: every status except the excluded ones counts).
        $woo = get_option('trackwp_woocommerce', null);
        if ( is_array($woo) && array_key_exists('count_on_hold', $woo) ) {
            unset($woo['count_on_hold']);
            update_option('trackwp_woocommerce', $woo);
        }

        // Baseline for the "platforms changed" admin notice. Recorded without
        // bumping consent_version: an upgrade alone must not invalidate every
        // visitor's stored choice.
        if ( false === get_option(self::OPTION_MATERIAL_HASH, false) ) {
            add_option(self::OPTION_MATERIAL_HASH, TrackWP_Consent_Profile::material_hash(), '', false);
        }

        // Banner and scripts changed: HTML cached by a CDN keeps serving the
        // old version until it is purged there, which we cannot do.
        if ( version_compare($stored, '1.0.0', '>') ) {
            update_option(self::OPTION_UPGRADE_NOTICE, 1, false);
        }
    }

    /**
     * Did this site's stored options already satisfy TrackWP's Meta takeover
     * BEFORE 1.11.1 dropped the CAPI-token requirement (D1)? Mirrors the old
     * missing_conditions() rule (token mandatory) purely from raw options,
     * because the running code already applies D1 and there is no way to
     * re-execute the pre-1.11.1 logic to compare against. Read-only, used
     * once during the upgrade to detect a status().delivering flip (KC16).
     * Returns null when TrackWP_Meta_Takeover is not loaded yet (guarded by
     * the caller, class_exists()).
     *
     * @return bool|null
     */
    private function meta_was_delivering_pre_1_11_1() {
        $p = get_option('trackwp_platforms', array());
        if ( ! is_array($p) ) {
            $p = array();
        }
        if ( empty($p['meta_pixel_with_gtm']) || empty($p['meta_enabled']) || empty($p['meta_pixel_client_enabled']) ) {
            return false;
        }
        if ( ! isset($p['meta_pixel_id']) || ! is_scalar($p['meta_pixel_id'])
            || preg_match('/^\d{5,20}$/', (string) $p['meta_pixel_id']) !== 1 ) {
            return false;
        }
        if ( empty($p['meta_access_token']) || ! is_string($p['meta_access_token'])
            || trim((string) TrackWP_Hash::decode($p['meta_access_token'])) === '' ) {
            return false;
        }
        $woo = new TrackWP_WooCommerce(false);
        if ( $woo->is_available() && ! $woo->is_enabled() ) {
            return false;
        }
        return true;
    }

    /**
     * 1.11.1 migration (PLAN-1.11.1-v2 KC15/KC16, BESLUTNINGER D1).
     *
     * KC1 keys are backfilled with array_key_exists, the same pattern as the
     * 1.1.0 block above (lines ~384-402). Blocking, dataLayer push and the
     * cookie gate stay behaviourally OFF until the owner turns them on
     * (KC16 "uændret indtil det slås til"): gtm_datalayer_events defaults to
     * 'off' and the new Woo event flags default to false.
     * trackwp_blocker_compiled needs no explicit rebuild here — its own
     * version check (TrackWP_Blocker_Rules::compiled()) already recompiles
     * on a version bump (plan §1, verified fact).
     *
     * @param string $stored Version the site is upgrading from.
     * @return void
     */
    private function upgrade_1_11_1($stored) {
        // Computed BEFORE any option below is touched (none of them affect
        // Meta delivery) so it reflects the pre-upgrade state.
        $was_delivering = class_exists('TrackWP_Meta_Takeover') ? $this->meta_was_delivering_pre_1_11_1() : null;

        $platforms = get_option('trackwp_platforms', array());
        if ( is_array($platforms) ) {
            $changed = false;
            if ( ! array_key_exists('fb4woo_tracking_off', $platforms) ) {
                $platforms['fb4woo_tracking_off'] = false;
                $changed = true;
            }
            if ( ! array_key_exists('gtm_datalayer_events', $platforms) ) {
                $platforms['gtm_datalayer_events'] = 'off';
                $changed = true;
            }
            if ( ! array_key_exists('ga4_source', $platforms) ) {
                $platforms['ga4_source'] = 'gtm';
                $changed = true;
            }
            if ( $changed ) {
                update_option('trackwp_platforms', $platforms);
            }
        }

        $woo = get_option('trackwp_woocommerce', array());
        if ( is_array($woo) ) {
            $changed = false;
            // D13: remove_from_cart is cut from 1.11.1.
            foreach ( array('event_view_item_list', 'event_view_cart') as $key ) {
                if ( ! array_key_exists($key, $woo) ) {
                    $woo[ $key ] = false;
                    $changed = true;
                }
            }
            if ( $changed ) {
                update_option('trackwp_woocommerce', $woo);
            }
        }

        // KC15: the new, versionless notice option replaces the old
        // per-version flag. A true fresh install (never ran an older
        // version) gets neither notice, same rule as upgrade_1_10_1() above.
        delete_option('trackwp_upgrade_notice_1_10_1');
        if ( version_compare($stored, '1.0.0', '>') ) {
            update_option(self::OPTION_UPGRADE_NOTICE, '1.11.1', false);
            $this->purge_known_page_caches();

            // D1: Meta delivery flips from off to on purely because the code
            // upgraded (missing_conditions() no longer requires a token).
            if ( false === $was_delivering && class_exists('TrackWP_Meta_Takeover') ) {
                $status = TrackWP_Meta_Takeover::status();
                if ( ! empty($status['delivering']) ) {
                    update_option(self::OPTION_META_TAKEOVER_NOTICE, 1, false);
                }
            }
        }
    }

    /**
     * 1.11.0 migration. Blocking stays off and Meta is not taken over until
     * the owner turns them on, so the front end is unchanged by the upgrade.
     * add_option() never overwrites an existing value; the Meta flag is only
     * added when the key is missing.
     *
     * @return void
     */
    private function upgrade_1_11_0() {
        add_option('trackwp_blocker', array(
            'mode'           => 'off',
            'rules'          => array(),
            'custom_vendors' => array(),
            'exceptions'     => array(
                'allow' => array(),
                'paths' => array(),
            ),
            'extra_paths'    => array(),
        ));

        $platforms = get_option('trackwp_platforms', array());
        if ( is_array($platforms) && ! array_key_exists('meta_pixel_with_gtm', $platforms) ) {
            $platforms['meta_pixel_with_gtm'] = false;
            update_option('trackwp_platforms', $platforms);
        }
    }

    /**
     * update_option_trackwp_blocker / add_option_trackwp_blocker: recompile
     * and cache the rules (KB7, the one implementation is
     * TrackWP_Blocker_Rules::rebuild(), which reads the stored option) and
     * purge known page caches, since cached HTML still carries the markup of
     * the previous rules.
     *
     * @return void
     */
    public function on_blocker_saved() {
        TrackWP_Blocker_Rules::rebuild();
        $this->purge_known_page_caches();
    }

    /**
     * Purge page caches of known caching plugins after the 1.10.1 upgrade.
     * Hooked on 'trackwp_upgraded_1_10_1'. Every call is guarded, so an absent
     * plugin is a no-op; the do_action() calls are the plugins' own public
     * purge hooks. CDN and host-level caches are covered by the admin notice.
     *
     * @return void
     */
    public function purge_known_page_caches() {
        if ( function_exists('rocket_clean_domain') ) {
            rocket_clean_domain(); // WP Rocket.
        }
        if ( function_exists('w3tc_flush_all') ) {
            w3tc_flush_all(); // W3 Total Cache.
        }
        if ( function_exists('wp_cache_clear_cache') ) {
            wp_cache_clear_cache(); // WP Super Cache.
        }
        if ( function_exists('sg_cachepress_purge_cache') ) {
            sg_cachepress_purge_cache(); // SiteGround Speed Optimizer.
        }
        do_action('litespeed_purge_all');                // LiteSpeed Cache.
        do_action('cache_enabler_clear_complete_cache'); // Cache Enabler.
        do_action('rt_nginx_helper_purge_all');          // Nginx Helper.
        do_action('breeze_clear_all_cache');             // Breeze (Cloudways).
    }

    /**
     * Admin notice after an upgrade that changed the banner or tracking
     * script: purge the CDN / host cache (KC15). The version shown is
     * whatever upgrade_1_11_1() (or an older upgrade block) stored.
     *
     * @return void
     */
    public function render_upgrade_notice() {
        $version = get_option(self::OPTION_UPGRADE_NOTICE, '');
        if ( ! current_user_can('manage_options') || '' === $version || false === $version ) {
            return;
        }
        $dismiss = wp_nonce_url(
            admin_url('admin-post.php?action=trackwp_dismiss_upgrade_notice'),
            'trackwp_dismiss_upgrade_notice'
        );
        echo '<div class="notice notice-warning"><p><strong>';
        /* translators: %s: plugin version, e.g. "1.11.1". */
        echo esc_html( sprintf( __('TrackWP %s:', 'trackwp'), (string) $version ) );
        echo '</strong> ';
        echo esc_html__('Samtykkebanneret og tracking-scriptet er opdateret. Kendte cache-plugins er forsøgt tømt automatisk, men en CDN (fx Cloudflare) eller en servercache hos dit webhotel skal du tømme manuelt. Ellers kan besøgende få den gamle version, indtil cachen udløber.', 'trackwp');
        echo '</p><p><a class="button" href="' . esc_url($dismiss) . '">' . esc_html__('Jeg har tømt cachen', 'trackwp') . '</a></p></div>';
    }

    /**
     * admin-post handler — dismiss the upgrade notice.
     */
    public function handle_dismiss_upgrade_notice() {
        if ( ! current_user_can('manage_options') ) {
            wp_die( esc_html__('Adgang nægtet.', 'trackwp') );
        }
        check_admin_referer('trackwp_dismiss_upgrade_notice');
        delete_option(self::OPTION_UPGRADE_NOTICE);
        $back = wp_get_referer();
        wp_safe_redirect( $back ? $back : admin_url() );
        exit;
    }

    /**
     * D1 one-time notice: Meta delivery flipped on for this site during the
     * 1.11.1 upgrade, because a Conversions API token is no longer required
     * (KC16). Separate from render_upgrade_notice() / OPTION_UPGRADE_NOTICE.
     *
     * @return void
     */
    public function render_meta_takeover_notice() {
        if ( ! current_user_can('manage_options') || ! get_option(self::OPTION_META_TAKEOVER_NOTICE) ) {
            return;
        }
        $dismiss = wp_nonce_url(
            admin_url('admin-post.php?action=trackwp_dismiss_meta_takeover_notice'),
            'trackwp_dismiss_meta_takeover_notice'
        );
        echo '<div class="notice notice-info"><p><strong>' . esc_html__('TrackWP: Meta-overtagelse', 'trackwp') . '</strong> ';
        echo esc_html__('Efter opgraderingen leverer TrackWP nu Meta (Pixel) for denne shop, selvom der ikke er sat et Conversions API-token.', 'trackwp') . ' ';
        // Reviewer-deep: the "fb4woo is switched off" sentence only makes
        // sense when fb4woo is actually installed — it was previously shown
        // unconditionally, which was misleading on sites without the plugin.
        if ( class_exists('TrackWP_Meta_Takeover') && ! empty(TrackWP_Meta_Takeover::status()['fb4woo_active']) ) {
            echo esc_html__('Meta for WooCommerce\'s pixel og CAPI er slået fra; katalogsync kører fortsat.', 'trackwp') . ' ';
        }
        echo esc_html__('Tilføj et token under Meta-indstillingerne, hvis du også vil have Conversions API.', 'trackwp');
        echo '</p><p><a class="button" href="' . esc_url($dismiss) . '">' . esc_html__('OK, forstået', 'trackwp') . '</a></p></div>';
    }

    /**
     * admin-post handler — dismiss the D1 Meta-takeover notice.
     */
    public function handle_dismiss_meta_takeover_notice() {
        if ( ! current_user_can('manage_options') ) {
            wp_die( esc_html__('Adgang nægtet.', 'trackwp') );
        }
        check_admin_referer('trackwp_dismiss_meta_takeover_notice');
        delete_option(self::OPTION_META_TAKEOVER_NOTICE);
        $back = wp_get_referer();
        wp_safe_redirect( $back ? $back : admin_url() );
        exit;
    }

    /**
     * TR5 (PLAN-1.11.1-v2 §9): when an admin previews the dataLayer in
     * "test" mode, this request must never be cached, same rule as a
     * click-id page (KC4.6). Only possible in GTM mode (KC1: the sanitizer
     * already forces gtm_datalayer_events to 'off' without GTM, but this is
     * re-checked here so a stale/edited option can never leak a cached page
     * with the wrong dataLayer state to a non-admin visitor).
     *
     * @return void
     */
    public function maybe_set_datalayer_test_nocache() {
        if ( ! function_exists('current_user_can') || ! current_user_can('manage_options') ) {
            return;
        }
        $platforms = get_option('trackwp_platforms', array());
        $advanced  = get_option('trackwp_advanced', array());
        if ( ! is_array($platforms) || 'test' !== ( $platforms['gtm_datalayer_events'] ?? '' ) ) {
            return;
        }
        $gtm_active = ! empty($platforms['gtm_enabled']) || ( is_array($advanced) && ! empty($advanced['uses_gtm']) );
        if ( ! $gtm_active ) {
            return;
        }
        if ( ! defined('DONOTCACHEPAGE') ) {
            define('DONOTCACHEPAGE', true);
        }
        if ( function_exists('nocache_headers') ) {
            nocache_headers();
        }
        do_action('litespeed_control_set_nocache', 'trackwp datalayer test');
    }

    /**
     * Whether the console debug mode may be switched on with ?trackwp_debug=1.
     *
     * @return bool
     */
    public static function debug_allowed() {
        $advanced = get_option('trackwp_advanced', array());
        return ! empty($advanced['debug_console']);
    }

    /**
     * Front-end admin-bar node that toggles ?trackwp_debug=1 on the current
     * page. Only for administrators; the console output itself is gated by
     * debugAllowed in trackwp.js, so visitors never get debug logging.
     *
     * @param WP_Admin_Bar $wp_admin_bar Admin bar instance.
     * @return void
     */
    public function admin_bar_debug_link($wp_admin_bar) {
        if ( is_admin() || ! is_admin_bar_showing() || ! current_user_can('manage_options') ) {
            return;
        }
        if ( ! self::debug_allowed() ) {
            $wp_admin_bar->add_node(array(
                'id'    => 'trackwp-debug',
                'title' => esc_html__('TrackWP debug (slået fra)', 'trackwp'),
                'href'  => admin_url('admin.php?page=trackwp#advanced'),
            ));
            return;
        }
        $on = isset($_GET['trackwp_debug']) && '1' === $_GET['trackwp_debug']; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $wp_admin_bar->add_node(array(
            'id'    => 'trackwp-debug',
            'title' => $on ? esc_html__('TrackWP debug: slå fra', 'trackwp') : esc_html__('TrackWP debug: slå til', 'trackwp'),
            'href'  => $on ? remove_query_arg('trackwp_debug') : add_query_arg('trackwp_debug', '1'),
        ));
    }

    /**
     * admin-post handler — stream a JSON download of all settings.
     */
    public function handle_export() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'Adgang nægtet.', 'trackwp' ) );
        }
        check_admin_referer( 'trackwp_export' );

        $include_secrets = ! empty( $_GET['include_secrets'] );
        $data            = TrackWP_Settings::export_settings( $include_secrets );

        $filename = 'trackwp-settings-' . gmdate( 'Y-m-d-His' ) . '.json';
        nocache_headers();
        header( 'Content-Type: application/json; charset=utf-8' );
        header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
        echo wp_json_encode( $data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
        exit;
    }

    /**
     * admin-post handler — accept JSON upload and import settings.
     */
    public function handle_import() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'Adgang nægtet.', 'trackwp' ) );
        }
        check_admin_referer( 'trackwp_import' );

        if ( empty( $_FILES['trackwp_import_file']['tmp_name'] )
            || ! is_uploaded_file( $_FILES['trackwp_import_file']['tmp_name'] )
            || ! empty( $_FILES['trackwp_import_file']['error'] ) ) {
            wp_safe_redirect( add_query_arg( 'trackwp_import', 'no_file', admin_url( 'admin.php?page=trackwp' ) ) );
            exit;
        }

        // Settings exports are a few KB; anything larger is not one of ours.
        if ( (int) $_FILES['trackwp_import_file']['size'] > 2 * MB_IN_BYTES ) {
            wp_safe_redirect( add_query_arg( 'trackwp_import', 'invalid_json', admin_url( 'admin.php?page=trackwp' ) ) );
            exit;
        }

        $raw  = file_get_contents( $_FILES['trackwp_import_file']['tmp_name'] );
        $data = json_decode( $raw, true );
        if ( ! is_array( $data ) ) {
            wp_safe_redirect( add_query_arg( 'trackwp_import', 'invalid_json', admin_url( 'admin.php?page=trackwp' ) ) );
            exit;
        }

        $result = TrackWP_Settings::import_settings( $data );
        if ( is_wp_error( $result ) ) {
            wp_safe_redirect( add_query_arg( 'trackwp_import', 'error', admin_url( 'admin.php?page=trackwp' ) ) );
            exit;
        }

        wp_safe_redirect( add_query_arg( 'trackwp_import', 'success', admin_url( 'admin.php?page=trackwp' ) ) );
        exit;
    }

    /**
     * Cron callback — flush the GA4 batch queue.
     * Registered at bootstrap (not in TrackWP_GA4's constructor) because no
     * TrackWP_GA4 instance exists in a WP-Cron request otherwise.
     */
    public function flush_ga4_queue() {
        $ga4 = new TrackWP_GA4();
        $ga4->flush_queue();
    }

    /**
     * admin-post handler — wipe stats.
     */
    public function handle_reset_stats() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'Adgang nægtet.', 'trackwp' ) );
        }
        check_admin_referer( 'trackwp_reset_stats' );
        update_option( 'trackwp_stats', array() );
        wp_safe_redirect( add_query_arg( 'trackwp_stats_reset', '1', admin_url( 'admin.php?page=trackwp#dashboard' ) ) );
        exit;
    }

    /**
     * Render Consent Mode v2 default state — wp_head pri 1 (BEFORE GTM).
     */
    public function render_consent_mode_defaults() {
        if ( is_admin() ) {
            return;
        }
        $advanced = get_option('trackwp_advanced', array());
        $ad_signals = ! empty($advanced['consent_mode_ad_signals']);
        // Blocking active for this request (§3.2): the tag opts out of
        // Cloudflare Rocket Loader, LiteSpeed and WP Rocket optimisation, and
        // the guard is inlined. With blocking off the output is unchanged.
        $blocking = TrackWP_Blocker::active_for_request();
        echo "\n<!-- TrackWP consent reader + Consent Mode v2 defaults -->\n";
        echo $blocking ? '<script data-cfasync="false" data-no-optimize="1" data-no-defer="1">' : '<script>';
        // (1) The one consent-cookie reader (K3/R3). Every inline gate below
        // and consent.js / trackwp.js / woocommerce.js use it; nothing else
        // parses the trackwp_consent cookie.
        echo TrackWP_Consent::reader_js(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static JS built by TrackWP_Consent.
        // The one URL/title cleaner (K6), window.trackwpPrivacy =
        // {cleanUrl(url, dropClickIds), cleanTitle(t)}, used for gtag's
        // page_location/page_referrer/page_title (R16) and by trackwp.js.
        // Cleaning runs in the browser on purpose: page_location and
        // page_referrer are per-request values, and a page cache (especially
        // one ignoring query strings) would otherwise serve one visitor's URL
        // or referrer — possibly with an e-mail address — to everyone.
        echo TrackWP_Privacy::cleaner_js(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static JS built by TrackWP_Privacy.
        // (1b) Blocker guard, window.trackwpBlocker (KB10). After the reader,
        // which it uses; before anything else can insert a tracking script.
        if ( $blocking ) {
            echo TrackWP_Blocker::guard_js(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static JS plus JSON rules built by TrackWP_Blocker.
        }
        // (2) Consent Mode defaults.
        echo "window.dataLayer=window.dataLayer||[];";
        echo "function gtag(){dataLayer.push(arguments);}window.gtag=window.gtag||gtag;";
        echo "gtag('consent','default',{";
        echo "'analytics_storage':'denied',";
        echo "'ad_storage':'denied',";
        echo "'ad_user_data':'denied',";
        echo "'ad_personalization':'denied',";
        echo "'functionality_storage':'denied',";
        echo "'personalization_storage':'denied',";
        echo "'security_storage':'granted',";
        echo "'wait_for_update':500";
        echo "});";
        if ( $ad_signals ) {
            echo "gtag('set','url_passthrough',true);";
            echo "gtag('set','ads_data_redaction',true);";
        }
        // (3) Returning-visitor restore, ONLY via the reader: consent.js is
        // deferred and wait_for_update is only 500ms, so restore a stored
        // choice synchronously right after the defaults — otherwise the first
        // pageview is sent cookieless. read() returns null for a missing,
        // invalid, stale or wrong-version cookie.
        echo "try{var d=window.trackwpConsentReader&&window.trackwpConsentReader.read();if(d){gtag('consent','update',{'analytics_storage':d.statistics?'granted':'denied','ad_storage':d.marketing?'granted':'denied','ad_user_data':d.marketing?'granted':'denied','ad_personalization':d.marketing?'granted':'denied','functionality_storage':d.personalisation?'granted':'denied','personalization_storage':d.personalisation?'granted':'denied','security_storage':'granted'});}}catch(e){}";
        echo "</script>\n";
    }

    /**
     * JS expression (for inline gates) that is true when the stored choice
     * grants at least one of the given categories. Uses only the reader;
     * a missing reader means "no choice".
     *
     * @param string[] $categories 'statistics' and/or 'marketing'.
     * @return string
     */
    private static function reader_gate_js(array $categories) {
        $checks = array();
        foreach ( $categories as $cat ) {
            $checks[] = 'd.' . preg_replace('/[^a-z]/', '', $cat);
        }
        return "function hasConsent(){try{var d=window.trackwpConsentReader&&window.trackwpConsentReader.read();return !!(d&&(" . implode('||', $checks) . "));}catch(e){return false;}}";
    }

    /**
     * Render Google Tag Manager <head> snippet — wp_head pri 5.
     */
    public function render_gtm_head() {
        if ( is_admin() ) {
            return;
        }
        $platforms = get_option('trackwp_platforms', array());
        if ( empty($platforms['gtm_enabled']) || empty($platforms['gtm_container_id']) ) {
            return;
        }
        $id = $platforms['gtm_container_id'];
        if ( ! preg_match('/^GTM-[A-Z0-9]{4,10}$/', $id) ) {
            return;
        }
        // Basic consent mode: Google tags are injected only after consent
        // (statistics or marketing). Advanced mode (default) loads them
        // pre-consent with denied defaults so gtag can send cookieless pings.
        $advanced = get_option('trackwp_advanced', array());
        $gate = array_key_exists('consent_mode_cookieless_pings', $advanced) && empty($advanced['consent_mode_cookieless_pings']);
        if ( $gate ) {
            echo "\n<!-- Google Tag Manager (TrackWP, consent-gated) -->\n<script>";
            echo "(function(){";
            echo "var loaded=false;";
            echo "function loadTag(){";
            echo "if(loaded)return;loaded=true;";
            echo "(function(w,d,s,l,i){w[l]=w[l]||[];w[l].push({'gtm.start':new Date().getTime(),event:'gtm.js'});";
            echo "var f=d.getElementsByTagName(s)[0],j=d.createElement(s),dl=l!='dataLayer'?'&l='+l:'';";
            echo "j.async=true;j.src='https://www.googletagmanager.com/gtm.js?id='+i+dl;f.parentNode.insertBefore(j,f);";
            echo "})(window,document,'script','dataLayer','" . esc_js($id) . "');";
            echo "}";
            echo self::reader_gate_js(array('statistics', 'marketing')); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static JS.
            echo "if(hasConsent()){loadTag();}else{document.addEventListener('trackwp:consent_updated',function(e){if(e&&e.detail&&(e.detail.statistics||e.detail.marketing)){loadTag();}});}";
            echo "})();";
            echo "</script>\n<!-- End Google Tag Manager -->\n";
            return;
        }
        echo "\n<!-- Google Tag Manager (TrackWP) -->\n<script>";
        echo "(function(w,d,s,l,i){w[l]=w[l]||[];w[l].push({'gtm.start':new Date().getTime(),event:'gtm.js'});";
        echo "var f=d.getElementsByTagName(s)[0],j=d.createElement(s),dl=l!='dataLayer'?'&l='+l:'';";
        echo "j.async=true;j.src='https://www.googletagmanager.com/gtm.js?id='+i+dl;f.parentNode.insertBefore(j,f);";
        echo "})(window,document,'script','dataLayer','" . esc_js($id) . "');";
        echo "</script>\n<!-- End Google Tag Manager -->\n";
    }

    /**
     * Render gtag.js loader + config for GA4 (page views/sessions) and
     * Google Ads (client-side conversions). Skipped when GTM is enabled
     * or "I use GTM" is checked.
     */
    public function render_gtag_head() {
        if ( is_admin() ) {
            return;
        }
        $platforms = get_option('trackwp_platforms', array());
        $advanced  = get_option('trackwp_advanced', array());
        // GTM container is expected to contain the GA4/Ads tags.
        if ( ! empty($platforms['gtm_enabled']) || ! empty($advanced['uses_gtm']) ) {
            return;
        }
        $ids = array();
        if ( ! empty($platforms['ga4_enabled']) && ! empty($platforms['ga4_gtag_enabled']) ) {
            $ga4_id = isset($platforms['ga4_measurement_id']) ? $platforms['ga4_measurement_id'] : '';
            if ( preg_match('/^G-[A-Z0-9]{4,12}$/', $ga4_id) ) {
                $ids[] = $ga4_id;
            }
        }
        if ( ! empty($platforms['google_ads_enabled']) ) {
            $ads_id = isset($platforms['google_ads_conversion_id']) ? $platforms['google_ads_conversion_id'] : '';
            if ( preg_match('/^AW-\d{6,12}$/', $ads_id) ) {
                $ids[] = $ads_id;
            }
        }
        if ( empty($ids) ) {
            return;
        }
        // First-party mode: serve gtag.js and collect hits via our own REST
        // routes. gtag appends '/g/collect' to transport_url; the loader/proxy
        // routes are registered by TrackWP_Loader only when the option is on.
        $use_fp = ! empty($advanced['first_party_loader_enabled']);
        if ( $use_fp ) {
            $loader_src = rest_url('trackwp/v1/loader');
        } else {
            $loader_src = 'https://www.googletagmanager.com/gtag/js?id=' . $ids[0];
        }

        // Build the gtag('js')/gtag('config') call sequence once — shared by
        // the gated and ungated output paths below.
        $config_js = "window.dataLayer=window.dataLayer||[];";
        $config_js .= "function gtag(){dataLayer.push(arguments);}window.gtag=window.gtag||gtag;";
        $config_js .= "gtag('js',new Date());";
        // R16/K6: page_location, page_referrer and page_title are always the
        // cleaned values, set before the first config (and so before the
        // first page_view). Computed in the browser by window.trackwpPrivacy
        // (see render_consent_mode_defaults() for why not server-side). If the cleaner is
        // missing we fail closed: origin + path only, no title or referrer.
        $config_js .= "(function(){var P=window.trackwpPrivacy,pg;";
        $config_js .= "if(P){pg={'page_location':P.cleanUrl(location.href),'page_title':P.cleanTitle(document.title)};if(document.referrer){pg.page_referrer=P.cleanUrl(document.referrer);}}";
        $config_js .= "else{pg={'page_location':location.origin+location.pathname,'page_title':''};}";
        $config_js .= "function cfg(x){var o={},k;for(k in pg){o[k]=pg[k];}for(k in x){o[k]=x[k];}return o;}";
        $config_js .= "gtag('set',pg);";
        $ec_allowed = TrackWP_Hash::customer_data_sharing_enabled();
        foreach ( $ids as $id ) {
            if ( strpos($id, 'AW-') === 0 ) {
                // Enhanced conversions via gtag user_data (set by trackwp.js /
                // woocommerce.js right before a conversion). Only when the
                // site shares customer data at all (K8 customer_data_sharing).
                $config_js .= "gtag('config','" . esc_js($id) . "',cfg(" . ( $ec_allowed ? "{'allow_enhanced_conversions':true}" : '{}' ) . "));";
                continue;
            }
            if ( $use_fp && strpos($id, 'G-') === 0 ) {
                // transport_url only — deliberately NOT 'first_party_collection'.
                // That flag tells gtag.js the endpoint is a server-side GTM
                // container (gtag's internal "sst mode" 2), which has two
                // consequences we do not want: gtag probes
                // <transport_url>/_/service_worker/<v>/sw_iframe.html for the
                // sGTM service worker — a 404 in the console, since this proxy
                // is not an sGTM container — and it stops appending the
                // first-party Google Ads click ids (gclgs/gclst/gcllp), because
                // a real server container would read those cookies itself.
                // Our proxy just forwards the raw hit to google-analytics.com,
                // so the client must keep doing that enrichment.
                // The collect URL is built from transport_url regardless of the
                // flag, so dropping it does not change where hits are sent.
                $config_js .= "gtag('config','" . esc_js($id) . "',cfg({";
                $config_js .= "'transport_url':'" . esc_js( untrailingslashit( rest_url('trackwp/v1/c') ) ) . "'";
                $config_js .= "}));";
            } else {
                $config_js .= "gtag('config','" . esc_js($id) . "',cfg({}));";
            }
        }
        $config_js .= "})();";

        // Basic consent mode: Google tags are injected only after consent
        // (statistics or marketing). Advanced mode (default) loads them
        // pre-consent with denied defaults so gtag can send cookieless pings.
        $gate = array_key_exists('consent_mode_cookieless_pings', $advanced) && empty($advanced['consent_mode_cookieless_pings']);
        if ( $gate ) {
            echo "\n<!-- Google tag (gtag.js) — TrackWP, consent-gated -->\n<script>";
            echo "(function(){";
            echo "var loaded=false;";
            echo "function loadTag(){";
            echo "if(loaded)return;loaded=true;";
            echo "var s=document.createElement('script');s.async=true;s.src='" . esc_js( $loader_src ) . "';document.head.appendChild(s);";
            // The gtag('js')/gtag('config') calls run inside loadTag after the
            // script injection: dataLayer pushes work regardless of loader
            // timing, but keeping everything in one place means nothing runs
            // until the user has consented.
            echo $config_js;
            echo "}";
            echo self::reader_gate_js(array('statistics', 'marketing')); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static JS.
            echo "if(hasConsent()){loadTag();}else{document.addEventListener('trackwp:consent_updated',function(e){if(e&&e.detail&&(e.detail.statistics||e.detail.marketing)){loadTag();}});}";
            echo "})();";
            echo "</script>\n";
            return;
        }

        echo "\n<!-- Google tag (gtag.js) — TrackWP -->\n";
        if ( $use_fp ) {
            echo '<script async src="' . esc_url( rest_url('trackwp/v1/loader') ) . '"></script>';
        } else {
            echo '<script async src="https://www.googletagmanager.com/gtag/js?id=' . esc_attr($ids[0]) . '"></script>';
        }
        echo "\n<script>";
        echo $config_js;
        echo "</script>\n";
    }

    /**
     * Render Meta Pixel (client-side) gated on marketing consent. Pairs with
     * server-side CAPI; both send the same event_id so Meta dedups. Skipped
     * when GTM is used, unless TrackWP takes over Meta (M1,
     * trackwp_platforms['meta_pixel_with_gtm']).
     */
    /**
     * The one source of the settings under which render_meta_pixel() prints
     * TrackWP's client-side Meta Pixel (also used by the blocker scanner).
     * Settings only: per-request context (admin screen, consent) is not
     * part of it.
     *
     * - meta_enabled and meta_pixel_client_enabled are on;
     * - meta_pixel_id matches ^\d{5,20}$;
     * - GTM gate: neither gtm_enabled nor trackwp_advanced.uses_gtm is set,
     *   unless TrackWP takes over Meta (M1, meta_pixel_with_gtm).
     *
     * @return bool
     */
    public static function client_meta_pixel_will_render() {
        $platforms = get_option('trackwp_platforms', array());
        $advanced  = get_option('trackwp_advanced', array());
        $platforms = is_array($platforms) ? $platforms : array();
        $advanced  = is_array($advanced) ? $advanced : array();
        // Without M1 the GTM container is expected to contain the Meta Pixel tag.
        $takeover = ! empty($platforms['meta_pixel_with_gtm']);
        if ( ! $takeover && ( ! empty($platforms['gtm_enabled']) || ! empty($advanced['uses_gtm']) ) ) {
            return false;
        }
        if ( empty($platforms['meta_enabled']) || empty($platforms['meta_pixel_client_enabled']) ) {
            return false;
        }
        $pixel_id = isset($platforms['meta_pixel_id']) ? $platforms['meta_pixel_id'] : '';
        return is_scalar($pixel_id) && 1 === preg_match('/^\d{5,20}$/', (string) $pixel_id);
    }

    public function render_meta_pixel() {
        if ( is_admin() ) {
            return;
        }
        if ( ! self::client_meta_pixel_will_render() ) {
            return;
        }
        $platforms = get_option('trackwp_platforms', array());
        $pixel_id  = (string) $platforms['meta_pixel_id'];
        // GDPR: fbevents.js is only loaded once marketing consent exists —
        // either via the trackwp_consent cookie at load, or when consent.js
        // fires the 'trackwp:consent_updated' CustomEvent with detail.marketing=true.
        // Advanced matching (normalised SHA-256 hashes, K5 `am`) only on a
        // verified order-received page, which TrackWP_WooCommerce marks
        // private/no-store (R12), and only when customer data may be shared.
        // AM must be in the init call to count as manual advanced matching.
        $am = array();
        if ( TrackWP_Hash::customer_data_sharing_enabled() && class_exists('WooCommerce') ) {
            $am = TrackWP_WooCommerce::pixel_advanced_matching();
        }
        $init_args = "'" . esc_js($pixel_id) . "'";
        if ( ! empty($am) && is_array($am) ) {
            $init_args .= ',' . wp_json_encode($am, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
        }
        // id and the trackwpMeta marker identify TrackWP's own pixel (S15):
        // both are on the blocker's never-list and ignored by the scanner.
        echo "\n<!-- Meta Pixel (TrackWP, consent-gated) -->\n<script id=\"trackwp-meta-pixel\">";
        echo "window.trackwpMeta=1;";
        echo "(function(){";
        echo "var loaded=false;";
        echo "function loadPixel(){";
        echo "if(loaded)return;loaded=true;";
        echo "!function(f,b,e,v,n,t,s){if(f.fbq)return;n=f.fbq=function(){n.callMethod?n.callMethod.apply(n,arguments):n.queue.push(arguments)};if(!f._fbq)f._fbq=n;n.push=n;n.loaded=!0;n.version='2.0';n.queue=[];t=b.createElement(e);t.async=!0;t.src=v;s=b.getElementsByTagName(e)[0];s.parentNode.insertBefore(t,s)}(window,document,'script','https://connect.facebook.net/en_US/fbevents.js');";
        echo "fbq('init'," . $init_args . ");fbq('track','PageView');"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- esc_js + wp_json_encode above.
        echo "}";
        // The reader rejects a stale or wrong-version cookie, so after a
        // policy bump an old choice does not load the pixel. The
        // 'trackwp:consent_updated' detail is fresh from the banner.
        echo self::reader_gate_js(array('marketing')); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static JS.
        echo "if(hasConsent()){loadPixel();}else{document.addEventListener('trackwp:consent_updated',function(e){if(e&&e.detail&&e.detail.marketing){loadPixel();}});}";
        echo "})();";
        echo "</script>\n";
    }

    /**
     * Render Google Tag Manager <noscript> iframe — wp_body_open pri 5.
     */
    public function render_gtm_noscript() {
        if ( is_admin() ) {
            return;
        }
        $platforms = get_option('trackwp_platforms', array());
        if ( empty($platforms['gtm_enabled']) || empty($platforms['gtm_container_id']) ) {
            return;
        }
        $id = $platforms['gtm_container_id'];
        if ( ! preg_match('/^GTM-[A-Z0-9]{4,10}$/', $id) ) {
            return;
        }
        // Basic consent mode: no pre-consent iframe — no-JS users cannot give
        // consent, so the noscript fallback is skipped entirely when gating.
        $advanced = get_option('trackwp_advanced', array());
        if ( array_key_exists('consent_mode_cookieless_pings', $advanced) && empty($advanced['consent_mode_cookieless_pings']) ) {
            return;
        }
        // The noscript iframe cannot be gated by the guard; with blocking
        // active for this request it is left out entirely.
        if ( TrackWP_Blocker::active_for_request() ) {
            return;
        }
        echo "\n<!-- Google Tag Manager (noscript) -->\n";
        echo '<noscript><iframe src="https://www.googletagmanager.com/ns.html?id=' . esc_attr($id) . '"';
        echo ' height="0" width="0" style="display:none;visibility:hidden"></iframe></noscript>';
        echo "\n<!-- End Google Tag Manager (noscript) -->\n";
    }

    /**
     * Plugin deactivation — clean up transients.
     */
    public function deactivate() {
        global $wpdb;

        // Rate-limit counters: 'trackwp_rl_' is TrackWP_Request_Guard::rate_limit()'s
        // key prefix since 1.10.1; 'trackwp_rate_' is the 1.10.0 prefix, still
        // cleared for sites deactivated right after upgrading. esc_like()
        // keeps the '_' in the prefixes literal instead of a LIKE wildcard.
        foreach ( array('trackwp_rl_', 'trackwp_rate_') as $prefix ) {
            $wpdb->query( $wpdb->prepare(
                "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
                $wpdb->esc_like('_transient_' . $prefix) . '%',
                $wpdb->esc_like('_transient_timeout_' . $prefix) . '%'
            ) );
        }

        // Remove the pending GA4 batch-flush cron event.
        wp_clear_scheduled_hook('trackwp_flush_ga4');
        // Stop pruning while deactivated (the table itself survives; uninstall drops it).
        wp_clear_scheduled_hook(TrackWP_Delivery_Log::CRON_HOOK);
        wp_clear_scheduled_hook(self::CRON_PRUNE_CONSENT_LOG);
        // A pending migration resumes via maybe_upgrade()/activate on reactivation.
        wp_clear_scheduled_hook(self::CRON_MIGRATE_CONSENT_LOG);
    }

    /**
     * Enqueue a front-end script deferred (BESLUTNINGER §6).
     *
     * WP >= 6.3: the 5th argument is an $args array with 'strategy' => 'defer'.
     * WP < 6.3: the 5th argument is the $in_footer bool — an array would be
     * truthy and silently move head scripts to the footer — so the plain bool
     * is passed and the script loads the classic, blocking way.
     *
     * @param string   $handle    Script handle.
     * @param string   $src       Script URL.
     * @param string[] $deps      Dependencies.
     * @param bool     $in_footer Footer (true) or head (false).
     * @return void
     */
    public static function enqueue_deferred_script($handle, $src, array $deps, $in_footer) {
        if ( version_compare($GLOBALS['wp_version'], '6.3', '>=') ) {
            wp_enqueue_script($handle, $src, $deps, TRACKWP_VERSION, array(
                'strategy'  => 'defer',
                'in_footer' => (bool) $in_footer,
            ));
            return;
        }
        wp_enqueue_script($handle, $src, $deps, TRACKWP_VERSION, (bool) $in_footer);
    }

    public function load_textdomain() {
        load_plugin_textdomain('trackwp', false, dirname(TRACKWP_PLUGIN_BASENAME) . '/languages');
    }

    public function init() {
        $this->consent = new TrackWP_Consent();
        $this->forms   = new TrackWP_Forms();
        new TrackWP_Cookie_Scanner();
        // Registers nothing unless WooCommerce is active; see the class docblock.
        $this->woocommerce = new TrackWP_WooCommerce();
        // Blocking until consent. The class decides what to hook: observe
        // mode for the scanner always (S12), rewriting only in test/on mode
        // on WP 6.5+ (§3.1, KB12).
        new TrackWP_Blocker();
    }

    public function admin_menu() {
        $settings = new TrackWP_Settings();
        $settings->add_menu_page();
    }

    public function admin_init() {
        $settings = new TrackWP_Settings();
        $settings->register_settings();
    }

    public function rest_api_init() {
        $proxy = new TrackWP_Proxy();
        $proxy->register_routes();

        $loader = new TrackWP_Loader();
        $loader->register_routes();

        // POST /trackwp/v1/blocker/scan (§3.4).
        $scanner = new TrackWP_Blocker_Scanner();
        $scanner->register_routes();
    }

    /**
     * Enqueue admin scripts and styles on TrackWP settings page only.
     *
     * @param string $hook_suffix The current admin page hook suffix.
     */
    public function enqueue_admin_scripts($hook_suffix) {
        if ($hook_suffix !== 'toplevel_page_trackwp') {
            return;
        }

        wp_enqueue_style('wp-color-picker');
        wp_enqueue_style(
            'trackwp-admin',
            self::asset_url('assets/admin/admin.css'),
            [],
            TRACKWP_VERSION
        );

        wp_enqueue_script(
            'trackwp-admin',
            self::asset_url('assets/admin/admin.js'),
            ['jquery', 'wp-color-picker'],
            TRACKWP_VERSION,
            true
        );

        wp_localize_script('trackwp-admin', 'trackwpAdminConfig', array(
            'strings' => array(
                'expand'        => __('Udvid', 'trackwp'),
                'delete'        => __('Slet', 'trackwp'),
                'deleteEvent'   => __('Slet denne begivenhed?', 'trackwp'),
                'fixErrors'     => __('Ret venligst følgende fejl:', 'trackwp'),
                // Firing triggers / conditions builder
                'trigger'       => __('Trigger', 'trackwp'),
                'addTrigger'    => __('Tilføj alternativ trigger', 'trackwp'),
                'removeTrigger' => __('Fjern trigger', 'trackwp'),
                'addCondition'  => __('Tilføj betingelse', 'trackwp'),
                'anyTrigger'    => __('Begivenheden sendes når EN AF disse triggere matcher.', 'trackwp'),
                'allConditions' => __('Udløs kun når ALLE disse betingelser er sande:', 'trackwp'),
                'noConditions'  => __('Ingen betingelser — triggeren matcher altid.', 'trackwp'),
                'moreTriggers'  => __('flere', 'trackwp'),
                'conditions'    => __('betingelser', 'trackwp'),
                'sendTo'        => __('Send til', 'trackwp'),
                'cssSelector'   => __('CSS-selector', 'trackwp'),
                'urlMatch'      => __('URL indeholder', 'trackwp'),
                'scrollDepth'   => __('Scrolldybde (%)', 'trackwp'),
                'timeSeconds'   => __('Sekunder', 'trackwp'),
                'jsEvent'       => __('JavaScript-eventnavn', 'trackwp'),
            ),
        ));

        // "Blokering" tab: scan button and rule table (T5 owns the script).
        wp_enqueue_script(
            'trackwp-admin-blocker',
            self::asset_url('assets/admin/blocker.js'),
            ['trackwp-admin'],
            TRACKWP_VERSION,
            true
        );
        wp_add_inline_script(
            'trackwp-admin-blocker',
            'window.trackwpBlockerAdmin=' . self::json_for_script(array(
                'scanUrl' => rest_url('trackwp/v1/blocker/scan'),
                'nonce'   => wp_create_nonce('wp_rest'),
            )) . ';',
            'before'
        );
    }

    /**
     * Build full asset URL with `.min` suffix in production when the minified
     * file exists on disk. Static so integration classes that enqueue their own
     * script (TrackWP_WooCommerce) resolve assets the same way.
     * Falls back to unminified when `.min` is missing,
     * so the plugin works out of the box without running `npm run build`.
     *
     * @param string $relative_path Path relative to plugin root, e.g. 'assets/js/trackwp.js'.
     * @return string Full URL.
     */
    public static function asset_url($relative_path) {
        $use_min = ! ( defined('SCRIPT_DEBUG') && SCRIPT_DEBUG );
        if ( $use_min ) {
            $min_path = preg_replace('/\.(js|css)$/', '.min.$1', $relative_path);
            if ( $min_path && file_exists( TRACKWP_PLUGIN_DIR . $min_path ) ) {
                return TRACKWP_PLUGIN_URL . $min_path;
            }
        }
        return TRACKWP_PLUGIN_URL . $relative_path;
    }

    /**
     * Enqueue frontend scripts (consent banner + tracking).
     */
    public function enqueue_frontend_scripts() {
        if (is_admin()) {
            return;
        }

        // Consent banner styles
        wp_enqueue_style(
            'trackwp-consent',
            self::asset_url('assets/css/consent-banner.css'),
            [],
            TRACKWP_VERSION
        );

        // Consent script — in <head>, deferred (keeps its order relative to
        // trackwp.js; the inline reader at wp_head@1 already restores a stored
        // choice synchronously, so deferring costs no consent state).
        self::enqueue_deferred_script('trackwp-consent', self::asset_url('assets/js/consent.js'), [], false);

        // Tracking script — depends on consent, footer, deferred.
        self::enqueue_deferred_script('trackwp-tracking', self::asset_url('assets/js/trackwp.js'), ['trackwp-consent'], true);

        // Configs are printed as JSON right before each script, so booleans
        // and numbers keep their types. wp_localize_script() casts every
        // top-level scalar to a string (false became "", 0 became "0"),
        // which turned opt-outs like customerDataSharing into truthy values.
        wp_add_inline_script('trackwp-consent', 'window.trackwpConsentConfig=' . self::json_for_script(self::consent_config()) . ';', 'before');
        wp_add_inline_script('trackwp-tracking', 'window.trackwpConfig=' . self::json_for_script(self::frontend_config()) . ';', 'before');
    }

    /**
     * JSON-encode a config for an inline <script>. JSON_HEX_TAG/AMP keep a
     * value containing "</script>" or "<!--" from breaking out of the tag.
     * An encoding failure yields {} so the page never gets a syntax error;
     * the scripts then use their safe defaults.
     *
     * @param array $data Config.
     * @return string
     */
    private static function json_for_script(array $data) {
        $json = wp_json_encode($data, JSON_HEX_TAG | JSON_HEX_AMP);
        return false === $json ? '{}' : $json;
    }

    /**
     * Producer of window.trackwpConfig (read by trackwp.js and
     * woocommerce.js). Types are preserved: booleans stay booleans, numbers
     * stay numbers. Public so tests and fixture generators use the real
     * producer instead of a hand-written copy.
     *
     * @return array
     */
    public static function frontend_config() {
        $platforms = get_option('trackwp_platforms', []);
        $advanced  = get_option('trackwp_advanced', []);
        $consent   = get_option('trackwp_consent', []);

        // Client-side event configs — built by TrackWP_Events so `send_to`
        // routing reaches the browser. Duplicating this inline (as earlier
        // versions did) is what let the Meta Pixel and the Google Ads gtag
        // conversion fire for events routed away from those platforms.
        $events_manager = new TrackWP_Events();
        $client_events  = $events_manager->get_client_config();

        // Google Ads labels — already filtered on send_to['google_ads'].
        $ads          = new TrackWP_Google_Ads();
        $ads_config   = $ads->get_client_config();

        return [
            'restUrl'   => rest_url(),
            'events'    => $client_events,
            'measurementId' => isset($platforms['ga4_measurement_id']) ? sanitize_text_field($platforms['ga4_measurement_id']) : '',
            'googleAds' => [
                'conversionId' => isset($ads_config['conversionId']) ? $ads_config['conversionId'] : '',
                'labels'       => isset($ads_config['conversionLabels']) ? $ads_config['conversionLabels'] : [],
            ],
            'cookieName' => isset($advanced['cookie_name']) ? $advanced['cookie_name'] : '_twp_cid',
            'endpointSlug' => isset($advanced['endpoint_path']) ? $advanced['endpoint_path'] : 'event',
            'dedupMode'    => isset($advanced['dedup_mode']) ? $advanced['dedup_mode'] : 'client_and_server',
            'fpCookieEnabled'      => !empty($advanced['first_party_cookie_enabled']),
            'fpCookieMonths'       => isset($advanced['cookie_lifetime_months']) ? (int) $advanced['cookie_lifetime_months'] : 24,
            'requireActiveConsent' => !empty($consent['require_active_consent']),
            'consentVersion'       => TrackWP_Consent::server_version(),
            // K8 / R19 (1.10.1).
            // _twp_cid lifetime in days — TrackWP_Cookies is the only source (K7).
            'fpCookieDays'         => (int) TrackWP_Cookies::lifetime_days('_twp_cid'),
            // Same list the server cleans with (filter trackwp_url_param_denylist).
            'urlDenylist'          => array_values(array_map('strtolower', (array) TrackWP_Privacy::url_param_denylist())),
            // ISO-2 fallback country for phone normalisation (R19).
            'defaultPhoneCountry'  => TrackWP_Hash::default_phone_country(),
            // Internal event name => Meta standard event, same rule as the server.
            'metaEventMap'         => (object) TrackWP_Meta::event_map(),
            // Console debug only when allowed here AND ?trackwp_debug=1 is set.
            'debugAllowed'         => self::debug_allowed(),
            'customerDataSharing'  => TrackWP_Hash::customer_data_sharing_enabled(),
            // Registrable domain for cookies that must span subdomains (_fbc, K7).
            'cookieDomain'         => (string) TrackWP_Cookies::registrable_domain(),
            // KC7 (D2/D3): the dataLayer push layer. class_exists() also
            // autoloads; falls back to "everything off" until W4 lands the
            // file, so the key is always present with the contract's shape.
            'dataLayer'            => class_exists('TrackWP_DataLayer')
                ? TrackWP_DataLayer::client_config()
                : array(
                    'enabled'      => false,
                    'ga4Source'    => 'gtm',
                    'ga4Enabled'   => false,
                    'mpConfigured' => false,
                ),
        ];
    }

    /**
     * Producer of window.trackwpConsentConfig (read by consent.js). Types
     * are preserved, as for frontend_config().
     *
     * @return array
     */
    public static function consent_config() {
        $consent = get_option('trackwp_consent', []);
        return [
            'bannerStyle'           => isset($consent['banner_style']) ? $consent['banner_style'] : 'dialog',
            'bgColor'              => isset($consent['bg_color']) ? $consent['bg_color'] : '#274A45',
            'textColor'            => isset($consent['text_color']) ? $consent['text_color'] : '#ffffff',
            'accentColor'          => isset($consent['accent_color']) ? $consent['accent_color'] : '#30D3C0',
            'buttonTextColor'      => isset($consent['button_text_color']) ? $consent['button_text_color'] : '#274A45',
            'borderRadius'         => isset($consent['border_radius']) ? (int) $consent['border_radius'] : 8,
            'heading'              => isset($consent['heading']) ? $consent['heading'] : __('Vi bruger cookies', 'trackwp'),
            'description'          => isset($consent['description']) ? $consent['description'] : '',
            'acceptText'           => isset($consent['accept_text']) ? $consent['accept_text'] : __('Accepter alle', 'trackwp'),
            'rejectText'           => !empty($consent['reject_text']) ? $consent['reject_text'] : __('Afvis valgfrie', 'trackwp'),
            'customizeText'        => isset($consent['customize_text']) ? $consent['customize_text'] : __('Tilpas', 'trackwp'),
            'saveText'             => isset($consent['save_text']) ? $consent['save_text'] : __('Gem præferencer', 'trackwp'),
            'privacyPageId'        => isset($consent['privacy_page_id']) ? (int) $consent['privacy_page_id'] : 0,
            'language'             => isset($consent['language']) ? $consent['language'] : 'da',
            // show_reject_button is gone: "Afvis valgfrie" is always on the
            // first layer. Key kept for scripts that still read it.
            'showRejectButton'     => true,
            'requireActiveConsent' => !empty($consent['require_active_consent']),
            'cookieLifetimeMonths' => max(1, min(12, isset($consent['cookie_lifetime_months']) ? (int) $consent['cookie_lifetime_months'] : 12)),
            'consentVersion'       => TrackWP_Consent::server_version(),
            'log_consent'          => !empty($consent['log_consent']),
            'restUrl'              => rest_url(),
            // Blocking active for this request (KB11: revoking a category reloads the page).
            'blockerActive'        => (bool) TrackWP_Blocker::active_for_request(),
        ];
    }
}

// Bootstrap the plugin.
TrackWP::instance();
