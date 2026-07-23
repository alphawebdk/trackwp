<?php
/**
 * Plugin Name: TrackWP
 * Plugin URI: https://trackwp.com
 * Description: Server-side tracking proxy with built-in cookie consent and Consent Mode v2. Supports GA4, Google Ads, and Meta.
 * Version: 1.8.0
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

define('TRACKWP_VERSION', '1.8.0');
define('TRACKWP_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('TRACKWP_PLUGIN_URL', plugin_dir_url(__FILE__));
define('TRACKWP_PLUGIN_BASENAME', plugin_basename(__FILE__));

/**
 * Self-hosted updates via GitHub Releases (private repo alphawebdk/trackwp).
 *
 * Plugin Update Checker polls the repo's latest release and serves its ZIP
 * asset through WordPress' standard update flow (update notice + one-click
 * update in wp-admin, wp-cron twice-daily checks).
 *
 * The repo is private, so each site must authenticate: define
 * TRACKWP_GITHUB_TOKEN in wp-config.php (fine-grained PAT, read-only
 * "Contents" access to the repo) or supply it via the
 * 'trackwp_github_token' filter.
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

    /** @var TrackWP|null */
    private static $instance = null;

    /** @var TrackWP_Consent|null */
    private $consent;

    /** @var TrackWP_Forms|null */
    private $forms;

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
        // Consent Mode v2 defaults — wp_head pri 1 (must run BEFORE GTM pri 5).
        add_action('wp_head', [$this, 'render_consent_mode_defaults'], 1);
        // GTM container — wp_head pri 5 (must run AFTER consent defaults).
        add_action('wp_head', [$this, 'render_gtm_head'], 5);
        // gtag.js loader — wp_head pri 6 (after consent defaults pri 1; skipped entirely when GTM is used).
        add_action('wp_head', [$this, 'render_gtag_head'], 6);
        // Meta Pixel — wp_head pri 7 (after gtag pri 6; skipped entirely when GTM is used).
        add_action('wp_head', [$this, 'render_meta_pixel'], 7);
        add_action('wp_body_open', [$this, 'render_gtm_noscript'], 5);
        // Upgrade routine.
        add_action('plugins_loaded', [$this, 'maybe_upgrade'], 5);
        // GA4 batch flush — must be registered at bootstrap so the callback
        // exists in WP-Cron requests (no TrackWP_GA4 instance exists there).
        add_action('trackwp_flush_ga4', [$this, 'flush_ga4_queue']);

        // "Settings" link on Plugins page
        add_filter('plugin_action_links_' . TRACKWP_PLUGIN_BASENAME, ['TrackWP_Settings', 'add_plugin_action_links']);

        add_action( 'admin_post_trackwp_export', [ $this, 'handle_export' ] );
        add_action( 'admin_post_trackwp_import', [ $this, 'handle_import' ] );
        add_action( 'admin_post_trackwp_reset_stats', [ $this, 'handle_reset_stats' ] );
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
            'meta_api_version'               => 'v21.0',
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
                'css_selector' => 'form',
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
            'reject_text'               => __('Afvis alle', 'trackwp'),
            'customize_text'            => __('Tilpas', 'trackwp'),
            'save_text'                 => __('Gem præferencer', 'trackwp'),
            'privacy_page_id'           => 0,
            'language'                  => 'da',
            'show_reject_button'        => true,
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
            'async_loading'                => true,
            'defer_tracking'               => true,
            'dedup_mode'                   => 'client_and_server',
            'uses_gtm'                     => false,
            'ga4_user_id_enabled'          => false,
            'batching_enabled'             => false,
            'first_party_loader_enabled'   => true,
            'capi_debug_logging_enabled'   => false,
        ]);

        add_option('trackwp_stats', array());
        add_option('trackwp_cookie_declarations', array());

        add_option('trackwp_version', '1.0.0');
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

        update_option('trackwp_version', TRACKWP_VERSION);
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

        if ( empty( $_FILES['trackwp_import_file']['tmp_name'] ) ) {
            wp_safe_redirect( add_query_arg( 'trackwp_import', 'no_file', admin_url( 'admin.php?page=trackwp' ) ) );
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
        $consent_cfg = get_option('trackwp_consent', array());
        $cv = isset($consent_cfg['consent_version']) ? (int) $consent_cfg['consent_version'] : 1;
        echo "\n<!-- TrackWP Consent Mode v2 defaults -->\n<script>";
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
        // Returning-visitor fix: consent.js loads async and wait_for_update is
        // only 500ms, so restore a stored consent synchronously right after the
        // defaults — otherwise the first pageview is sent cookieless.
        echo "try{var m=document.cookie.match(/(?:^|; )trackwp_consent=([^;]+)/);if(m){var d=JSON.parse(decodeURIComponent(m[1]));if(d&&d.v===" . (int) $cv . "){gtag('consent','update',{'analytics_storage':d.statistics?'granted':'denied','ad_storage':d.marketing?'granted':'denied','ad_user_data':d.marketing?'granted':'denied','ad_personalization':d.marketing?'granted':'denied','functionality_storage':d.personalisation?'granted':'denied','personalization_storage':d.personalisation?'granted':'denied','security_storage':'granted'});}}}catch(e){}";
        echo "</script>\n";
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
            $consent_cfg = get_option('trackwp_consent', array());
            $cv = isset($consent_cfg['consent_version']) ? (int) $consent_cfg['consent_version'] : 1;
            echo "\n<!-- Google Tag Manager (TrackWP, consent-gated) -->\n<script>";
            echo "(function(){";
            echo "var loaded=false;";
            echo "function loadTag(){";
            echo "if(loaded)return;loaded=true;";
            echo "(function(w,d,s,l,i){w[l]=w[l]||[];w[l].push({'gtm.start':new Date().getTime(),event:'gtm.start'});";
            echo "var f=d.getElementsByTagName(s)[0],j=d.createElement(s),dl=l!='dataLayer'?'&l='+l:'';";
            echo "j.async=true;j.src='https://www.googletagmanager.com/gtm.js?id='+i+dl;f.parentNode.insertBefore(j,f);";
            echo "})(window,document,'script','dataLayer','" . esc_js($id) . "');";
            echo "}";
            echo "function hasConsent(){try{var m=document.cookie.match(/(?:^|; )trackwp_consent=([^;]+)/);if(!m)return false;var d=JSON.parse(decodeURIComponent(m[1]));return d&&d.v===" . (int) $cv . "&&!!(d.statistics||d.marketing);}catch(e){return false;}}";
            echo "if(hasConsent()){loadTag();}else{document.addEventListener('trackwp:consent_updated',function(e){if(e&&e.detail&&(e.detail.statistics||e.detail.marketing)){loadTag();}});}";
            echo "})();";
            echo "</script>\n<!-- End Google Tag Manager -->\n";
            return;
        }
        echo "\n<!-- Google Tag Manager (TrackWP) -->\n<script>";
        echo "(function(w,d,s,l,i){w[l]=w[l]||[];w[l].push({'gtm.start':new Date().getTime(),event:'gtm.start'});";
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
        foreach ( $ids as $id ) {
            if ( $use_fp && strpos($id, 'G-') === 0 ) {
                $config_js .= "gtag('config','" . esc_js($id) . "',{";
                $config_js .= "'transport_url':'" . esc_js( untrailingslashit( rest_url('trackwp/v1/c') ) ) . "',";
                $config_js .= "'first_party_collection':true";
                $config_js .= "});";
            } else {
                $config_js .= "gtag('config','" . esc_js($id) . "');";
            }
        }

        // Basic consent mode: Google tags are injected only after consent
        // (statistics or marketing). Advanced mode (default) loads them
        // pre-consent with denied defaults so gtag can send cookieless pings.
        $gate = array_key_exists('consent_mode_cookieless_pings', $advanced) && empty($advanced['consent_mode_cookieless_pings']);
        if ( $gate ) {
            $consent_cfg = get_option('trackwp_consent', array());
            $cv = isset($consent_cfg['consent_version']) ? (int) $consent_cfg['consent_version'] : 1;
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
            echo "function hasConsent(){try{var m=document.cookie.match(/(?:^|; )trackwp_consent=([^;]+)/);if(!m)return false;var d=JSON.parse(decodeURIComponent(m[1]));return d&&d.v===" . (int) $cv . "&&!!(d.statistics||d.marketing);}catch(e){return false;}}";
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
     * when GTM is used.
     */
    public function render_meta_pixel() {
        if ( is_admin() ) {
            return;
        }
        $platforms = get_option('trackwp_platforms', array());
        $advanced  = get_option('trackwp_advanced', array());
        // GTM container is expected to contain the Meta Pixel tag.
        if ( ! empty($platforms['gtm_enabled']) || ! empty($advanced['uses_gtm']) ) {
            return;
        }
        if ( empty($platforms['meta_enabled']) || empty($platforms['meta_pixel_client_enabled']) ) {
            return;
        }
        $pixel_id = isset($platforms['meta_pixel_id']) ? $platforms['meta_pixel_id'] : '';
        if ( ! preg_match('/^\d{5,20}$/', $pixel_id) ) {
            return;
        }
        // GDPR: fbevents.js is only loaded once marketing consent exists —
        // either via the trackwp_consent cookie at load, or when consent.js
        // fires the 'trackwp:consent_updated' CustomEvent with detail.marketing=true.
        $consent_cfg = get_option('trackwp_consent', array());
        $cv = isset($consent_cfg['consent_version']) ? (int) $consent_cfg['consent_version'] : 1;
        echo "\n<!-- Meta Pixel (TrackWP, consent-gated) -->\n<script>";
        echo "(function(){";
        echo "var loaded=false;";
        echo "function loadPixel(){";
        echo "if(loaded)return;loaded=true;";
        echo "!function(f,b,e,v,n,t,s){if(f.fbq)return;n=f.fbq=function(){n.callMethod?n.callMethod.apply(n,arguments):n.queue.push(arguments)};if(!f._fbq)f._fbq=n;n.push=n;n.loaded=!0;n.version='2.0';n.queue=[];t=b.createElement(e);t.async=!0;t.src=v;s=b.getElementsByTagName(e)[0];s.parentNode.insertBefore(t,s)}(window,document,'script','https://connect.facebook.net/en_US/fbevents.js');";
        echo "fbq('init','" . esc_js($pixel_id) . "');fbq('track','PageView');";
        echo "}";
        // Cookie consent must match the current consent_version — after a
        // policy bump a stale cookie must not load the pixel. The
        // 'trackwp:consent_updated' detail is fresh from the banner, so the
        // listener below needs no version check.
        echo "function hasMarketing(){try{var m=document.cookie.match(/(?:^|; )trackwp_consent=([^;]+)/);if(!m)return false;var d=JSON.parse(decodeURIComponent(m[1]));return d&&d.v===" . (int) $cv . "&&!!d.marketing;}catch(e){return false;}}";
        echo "if(hasMarketing()){loadPixel();}else{document.addEventListener('trackwp:consent_updated',function(e){if(e&&e.detail&&e.detail.marketing){loadPixel();}});}";
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

        $wpdb->query(
            "DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_trackwp_rate_%' OR option_name LIKE '_transient_timeout_trackwp_rate_%'"
        );

        // Remove the pending GA4 batch-flush cron event.
        wp_clear_scheduled_hook('trackwp_flush_ga4');
    }

    public function load_textdomain() {
        load_plugin_textdomain('trackwp', false, dirname(TRACKWP_PLUGIN_BASENAME) . '/languages');
    }

    public function init() {
        $this->consent = new TrackWP_Consent();
        $this->forms   = new TrackWP_Forms();
        new TrackWP_Cookie_Scanner();
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
            $this->asset_url('assets/admin/admin.css'),
            [],
            TRACKWP_VERSION
        );

        wp_enqueue_script(
            'trackwp-admin',
            $this->asset_url('assets/admin/admin.js'),
            ['jquery', 'wp-color-picker'],
            TRACKWP_VERSION,
            true
        );

        wp_localize_script('trackwp-admin', 'trackwpAdminConfig', array(
            'strings' => array(
                'expand'       => __('Udvid', 'trackwp'),
                'delete'       => __('Slet', 'trackwp'),
                'deleteEvent'  => __('Slet denne begivenhed?', 'trackwp'),
                'fixErrors'    => __('Ret venligst følgende fejl:', 'trackwp'),
            ),
        ));
    }

    /**
     * Build full asset URL with `.min` suffix in production when the minified
     * file exists on disk. Falls back to unminified when `.min` is missing,
     * so the plugin works out of the box without running `npm run build`.
     *
     * @param string $relative_path Path relative to plugin root, e.g. 'assets/js/trackwp.js'.
     * @return string Full URL.
     */
    private function asset_url($relative_path) {
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
            $this->asset_url('assets/css/consent-banner.css'),
            [],
            TRACKWP_VERSION
        );

        // Consent script — loads early (not in footer)
        wp_enqueue_script(
            'trackwp-consent',
            $this->asset_url('assets/js/consent.js'),
            [],
            TRACKWP_VERSION,
            false
        );
        wp_script_add_data('trackwp-consent', 'async', true);

        // Tracking script — depends on consent, loads in footer
        wp_enqueue_script(
            'trackwp-tracking',
            $this->asset_url('assets/js/trackwp.js'),
            ['trackwp-consent'],
            TRACKWP_VERSION,
            true
        );
        wp_script_add_data('trackwp-tracking', 'async', true);

        // Build tracking config
        $platforms = get_option('trackwp_platforms', []);
        $advanced  = get_option('trackwp_advanced', []);
        $events    = get_option('trackwp_events', []);
        $consent   = get_option('trackwp_consent', []);

        // Filter to active events only
        $active_events = array_values(array_filter($events, function ($event) {
            return !empty($event['enabled']);
        }));

        // Build client-side event configs
        $client_events = array_map(function ($event) {
            return [
                'name'         => isset($event['name']) ? $event['name'] : '',
                'trigger_type' => isset($event['trigger_type']) ? $event['trigger_type'] : '',
                'css_selector' => isset($event['css_selector']) ? $event['css_selector'] : '',
                'value'        => isset($event['value']) ? $event['value'] : 0,
                'currency'     => isset($event['currency']) ? $event['currency'] : 'DKK',
                'ads_label'    => isset($event['ads_label']) ? $event['ads_label'] : '',
                'url_match'    => isset($event['url_match']) ? $event['url_match'] : '',
                'scroll_depth' => isset($event['scroll_depth']) ? $event['scroll_depth'] : 0,
                'time_seconds' => isset($event['time_seconds']) ? $event['time_seconds'] : 0,
                'js_event'     => isset($event['js_event']) ? $event['js_event'] : '',
                'meta_event'   => isset($event['meta_event']) ? $event['meta_event'] : '',
            ];
        }, $active_events);

        // Build Google Ads labels per event
        $ads_labels = [];
        foreach ($active_events as $event) {
            if (!empty($event['ads_label'])) {
                $ads_labels[$event['name']] = $event['ads_label'];
            }
        }

        wp_localize_script('trackwp-tracking', 'trackwpConfig', [
            'restUrl'   => rest_url(),
            'events'    => $client_events,
            'measurementId' => isset($platforms['ga4_measurement_id']) ? sanitize_text_field($platforms['ga4_measurement_id']) : '',
            'googleAds' => [
                'conversionId' => isset($platforms['google_ads_conversion_id']) ? $platforms['google_ads_conversion_id'] : '',
                'labels'       => $ads_labels,
            ],
            'debug'      => !empty($advanced['debug_console']),
            'cookieName' => isset($advanced['cookie_name']) ? $advanced['cookie_name'] : '_twp_cid',
            'endpointSlug' => isset($advanced['endpoint_path']) ? $advanced['endpoint_path'] : 'event',
            'dedupMode'    => isset($advanced['dedup_mode']) ? $advanced['dedup_mode'] : 'client_and_server',
            'fpCookieEnabled'      => !empty($advanced['first_party_cookie_enabled']),
            'fpCookieMonths'       => isset($advanced['cookie_lifetime_months']) ? (int) $advanced['cookie_lifetime_months'] : 24,
            'requireActiveConsent' => !empty($consent['require_active_consent']),
            'consentVersion'       => isset($consent['consent_version']) ? (int) $consent['consent_version'] : 1,
        ]);

        // Consent banner config
        wp_localize_script('trackwp-consent', 'trackwpConsentConfig', [
            'bannerStyle'           => isset($consent['banner_style']) ? $consent['banner_style'] : 'dialog',
            'bgColor'              => isset($consent['bg_color']) ? $consent['bg_color'] : '#274A45',
            'textColor'            => isset($consent['text_color']) ? $consent['text_color'] : '#ffffff',
            'accentColor'          => isset($consent['accent_color']) ? $consent['accent_color'] : '#30D3C0',
            'buttonTextColor'      => isset($consent['button_text_color']) ? $consent['button_text_color'] : '#274A45',
            'borderRadius'         => isset($consent['border_radius']) ? (int) $consent['border_radius'] : 8,
            'heading'              => isset($consent['heading']) ? $consent['heading'] : __('Vi bruger cookies', 'trackwp'),
            'description'          => isset($consent['description']) ? $consent['description'] : '',
            'acceptText'           => isset($consent['accept_text']) ? $consent['accept_text'] : __('Accepter alle', 'trackwp'),
            'rejectText'           => isset($consent['reject_text']) ? $consent['reject_text'] : __('Afvis alle', 'trackwp'),
            'customizeText'        => isset($consent['customize_text']) ? $consent['customize_text'] : __('Tilpas', 'trackwp'),
            'saveText'             => isset($consent['save_text']) ? $consent['save_text'] : __('Gem præferencer', 'trackwp'),
            'privacyPageId'        => isset($consent['privacy_page_id']) ? (int) $consent['privacy_page_id'] : 0,
            'language'             => isset($consent['language']) ? $consent['language'] : 'da',
            'showRejectButton'     => !empty($consent['show_reject_button']),
            'requireActiveConsent' => !empty($consent['require_active_consent']),
            'cookieLifetimeMonths' => isset($consent['cookie_lifetime_months']) ? (int) $consent['cookie_lifetime_months'] : 12,
            'consentVersion'       => isset($consent['consent_version']) ? (int) $consent['consent_version'] : 1,
            'log_consent'          => !empty($consent['log_consent']),
            'restUrl'              => rest_url(),
        ]);
    }
}

// Bootstrap the plugin.
TrackWP::instance();
