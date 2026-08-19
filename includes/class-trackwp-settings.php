<?php
/**
 * TrackWP Settings
 *
 * Admin menu, settings registration, sanitize callbacks, and getters.
 *
 * @package TrackWP
 */

defined('ABSPATH') || exit;

class TrackWP_Settings {

    /**
     * Add top-level admin menu.
     */
    public function add_menu_page() {
        add_menu_page(
            __('TrackWP Indstillinger', 'trackwp'),
            'TrackWP',
            'manage_options',
            'trackwp',
            array($this, 'render_page'),
            'dashicons-chart-area',
            80
        );
    }

    /**
     * Render the settings page (loads template).
     */
    public function render_page() {
        if (!current_user_can('manage_options')) {
            wp_die(__('Adgang nægtet.', 'trackwp'));
        }
        include TRACKWP_PLUGIN_DIR . 'templates/settings-page.php';
    }

    /**
     * Register all settings groups on admin_init.
     */
    public function register_settings() {
        register_setting('trackwp_platforms_group', 'trackwp_platforms', array(
            'sanitize_callback' => array($this, 'sanitize_platforms'),
        ));
        register_setting('trackwp_events_group', 'trackwp_events', array(
            'sanitize_callback' => array($this, 'sanitize_events'),
        ));
        register_setting('trackwp_consent_group', 'trackwp_consent', array(
            'sanitize_callback' => array($this, 'sanitize_consent'),
        ));
        register_setting('trackwp_advanced_group', 'trackwp_advanced', array(
            'sanitize_callback' => array($this, 'sanitize_advanced'),
        ));
        register_setting('trackwp_cookie_declarations_group', 'trackwp_cookie_declarations', array(
            'sanitize_callback' => array($this, 'sanitize_cookie_declarations'),
        ));
    }

    /**
     * Sanitize platform settings.
     *
     * - IDs sanitized with sanitize_text_field
     * - Secrets base64 encoded via TrackWP_Hash::encode()
     * - Only re-encodes if value changed (not the placeholder)
     *
     * @param array $input Raw form input.
     * @return array Sanitized platforms config.
     */
    public function sanitize_platforms($input) {
        $current = get_option('trackwp_platforms', array());
        $output  = array();

        // GA4
        $output['ga4_enabled']        = !empty($input['ga4_enabled']);
        $ga4_id = sanitize_text_field(isset($input['ga4_measurement_id']) ? $input['ga4_measurement_id'] : '');
        if ($ga4_id && strpos($ga4_id, 'G-') !== 0) {
            // Ikke en G- ID — admin warning er allerede sat i UI; her kun log
            add_settings_error(
                'trackwp_platforms',
                'ga4_id_format',
                sprintf(
                    /* translators: %s: the entered ID */
                    __('GA4 Measurement ID "%s" er ikke i G-format. Server-side MP vil ikke virke.', 'trackwp'),
                    esc_html($ga4_id)
                ),
                'warning'
            );
        }
        $output['ga4_measurement_id'] = $ga4_id;

        // GA4 API Secret — only update if user entered a new value (not the placeholder)
        if (!empty($input['ga4_api_secret']) && $input['ga4_api_secret'] !== '••••••••') {
            $output['ga4_api_secret'] = TrackWP_Hash::encode($input['ga4_api_secret']);
        } else {
            $output['ga4_api_secret'] = isset($current['ga4_api_secret']) ? $current['ga4_api_secret'] : '';
        }

        $output['ga4_gtag_enabled'] = ! empty($input['ga4_gtag_enabled']);

        // Google Ads
        $output['google_ads_enabled']       = !empty($input['google_ads_enabled']);
        $output['google_ads_conversion_id'] = sanitize_text_field(isset($input['google_ads_conversion_id']) ? $input['google_ads_conversion_id'] : '');

        // Meta
        $output['meta_enabled']  = !empty($input['meta_enabled']);
        $output['meta_pixel_id'] = sanitize_text_field(isset($input['meta_pixel_id']) ? $input['meta_pixel_id'] : '');

        // Meta Access Token — same pattern as GA4 secret
        if (!empty($input['meta_access_token']) && $input['meta_access_token'] !== '••••••••') {
            $output['meta_access_token'] = TrackWP_Hash::encode($input['meta_access_token']);
        } else {
            $output['meta_access_token'] = isset($current['meta_access_token']) ? $current['meta_access_token'] : '';
        }

        $output['meta_pixel_client_enabled'] = ! empty($input['meta_pixel_client_enabled']);

        // Meta Test Event Code — must match TEST<digits> or be empty
        $raw_test_code = isset($input['meta_test_event_code']) ? sanitize_text_field($input['meta_test_event_code']) : '';
        $raw_test_code = trim($raw_test_code);
        if ( $raw_test_code === '' || preg_match('/^TEST\d+$/', $raw_test_code) ) {
            $output['meta_test_event_code'] = $raw_test_code;
        } else {
            $output['meta_test_event_code'] = '';
        }

        // Meta API version — whitelist
        $allowed_meta_versions = array('v18.0', 'v19.0', 'v20.0', 'v21.0', 'v22.0');
        $meta_version = isset($input['meta_api_version']) ? sanitize_text_field($input['meta_api_version']) : '';
        $output['meta_api_version'] = in_array($meta_version, $allowed_meta_versions, true) ? $meta_version : 'v21.0';

        // Google Ads Customer ID — accept digits + hyphens, validate format
        $raw_cust = isset($input['google_ads_customer_id']) ? sanitize_text_field($input['google_ads_customer_id']) : '';
        $raw_cust = preg_replace('/[^0-9\-]/', '', $raw_cust);
        if ( $raw_cust === '' || preg_match('/^\d{3}-\d{3}-\d{4}$/', $raw_cust) || preg_match('/^\d{10}$/', $raw_cust) ) {
            $output['google_ads_customer_id'] = $raw_cust;
        } else {
            $output['google_ads_customer_id'] = '';
        }

        // Google Ads Conversion Action ID — digits only
        $raw_action = isset($input['google_ads_conversion_action_id']) ? sanitize_text_field($input['google_ads_conversion_action_id']) : '';
        $output['google_ads_conversion_action_id'] = preg_replace('/[^0-9]/', '', $raw_action);

        // Google Ads Developer Token — same secret pattern as GA4/Meta tokens
        if (!empty($input['google_ads_developer_token']) && $input['google_ads_developer_token'] !== '••••••••') {
            $output['google_ads_developer_token'] = TrackWP_Hash::encode($input['google_ads_developer_token']);
        } else {
            $output['google_ads_developer_token'] = isset($current['google_ads_developer_token']) ? $current['google_ads_developer_token'] : '';
        }

        // Google Ads OAuth Client ID — plain text (not a secret)
        $output['google_ads_oauth_client_id'] = sanitize_text_field( isset($input['google_ads_oauth_client_id']) ? $input['google_ads_oauth_client_id'] : '' );

        // Google Ads OAuth Client Secret — same secret pattern as developer token
        if (!empty($input['google_ads_oauth_client_secret']) && $input['google_ads_oauth_client_secret'] !== '••••••••') {
            $output['google_ads_oauth_client_secret'] = TrackWP_Hash::encode($input['google_ads_oauth_client_secret']);
        } else {
            $output['google_ads_oauth_client_secret'] = isset($current['google_ads_oauth_client_secret']) ? $current['google_ads_oauth_client_secret'] : '';
        }

        // Google Ads OAuth Refresh Token — same secret pattern
        if (!empty($input['google_ads_oauth_refresh_token']) && $input['google_ads_oauth_refresh_token'] !== '••••••••') {
            $output['google_ads_oauth_refresh_token'] = TrackWP_Hash::encode($input['google_ads_oauth_refresh_token']);
        } else {
            $output['google_ads_oauth_refresh_token'] = isset($current['google_ads_oauth_refresh_token']) ? $current['google_ads_oauth_refresh_token'] : '';
        }

        // GTM
        $output['gtm_enabled'] = ! empty($input['gtm_enabled']);
        $gtm_id = isset($input['gtm_container_id']) ? sanitize_text_field($input['gtm_container_id']) : '';
        $gtm_id = strtoupper(trim($gtm_id));
        $output['gtm_container_id'] = preg_match('/^GTM-[A-Z0-9]{4,10}$/', $gtm_id) ? $gtm_id : '';

        // "Ryd"-knappen i admin sender et <felt>_clear flag, fordi et tomt
        // secret-felt ellers betyder "uændret" (behold DB-værdi). Er flaget sat,
        // gemmes en tom streng — medmindre brugeren har indtastet en ny værdi,
        // som så vinder over clear-flaget. Flagene selv gemmes ikke i output.
        $secret_keys = array('ga4_api_secret', 'meta_access_token', 'google_ads_developer_token', 'google_ads_oauth_client_secret', 'google_ads_oauth_refresh_token');
        foreach ($secret_keys as $secret_key) {
            if (!empty($input[$secret_key . '_clear']) && (empty($input[$secret_key]) || $input[$secret_key] === '••••••••')) {
                $output[$secret_key] = '';
            }
        }

        return $output;
    }

    /**
     * Sanitize events array.
     *
     * Expects a JSON string from the hidden textarea in the admin form, or a
     * plain array when called from import_settings().
     *
     * NOTE: do NOT stripslashes() the incoming string. wp-admin/options.php has
     * already run wp_unslash() on the POSTed value, so a second pass eats the
     * JSON's *own* escapes (`\"` inside a CSS selector like a[href^="tel:"]),
     * json_decode() then returns null and the whole event list is silently
     * replaced. That was the "cannot save events" bug.
     *
     * @param string|array $input Raw events input.
     * @return array Sanitized events array.
     */
    public function sanitize_events($input) {
        $current = get_option('trackwp_events', array());
        $current = is_array($current) ? $current : array();

        if (is_string($input)) {
            $decoded = json_decode($input, true);
            if (!is_array($decoded)) {
                add_settings_error(
                    'trackwp_events',
                    'events_invalid_json',
                    __('Begivenhederne kunne ikke læses (ugyldig JSON). Ingen ændringer blev gemt.', 'trackwp'),
                    'error'
                );
                return $current;
            }
            $input = $decoded;
        }

        if (!is_array($input)) {
            add_settings_error(
                'trackwp_events',
                'events_invalid_payload',
                __('Uventet dataformat for begivenheder. Ingen ændringer blev gemt.', 'trackwp'),
                'error'
            );
            return $current;
        }

        // An explicitly emptied list is a legitimate choice — respect it.
        if (empty($input)) {
            return array();
        }

        $output = array();
        $seen   = array();
        foreach ($input as $i => $event) {
            $validated = TrackWP_Events::validate_event($event);
            if (is_wp_error($validated)) {
                add_settings_error(
                    'trackwp_events',
                    'event_invalid_' . (int) $i,
                    sprintf(
                        /* translators: 1: row number, 2: validation error message */
                        __('Begivenhed #%1$d blev ikke gemt: %2$s', 'trackwp'),
                        (int) $i + 1,
                        $validated->get_error_message()
                    ),
                    'error'
                );
                continue;
            }
            // Duplicate names bind twice client-side and dispatch twice with
            // different event_ids — real double counting. Keep the first.
            if (isset($seen[ $validated['name'] ])) {
                add_settings_error(
                    'trackwp_events',
                    'event_duplicate_' . (int) $i,
                    sprintf(
                        /* translators: %s: event name */
                        __('Begivenhed "%s" findes mere end én gang — kun den første blev gemt.', 'trackwp'),
                        $validated['name']
                    ),
                    'error'
                );
                continue;
            }
            $seen[ $validated['name'] ] = true;
            $output[] = $validated;
        }

        // Every row was rejected — keep what is already stored rather than
        // silently overwriting the site's configuration with the defaults.
        if (empty($output)) {
            add_settings_error(
                'trackwp_events',
                'events_all_invalid',
                __('Ingen af begivenhederne kunne valideres — den tidligere liste er bevaret.', 'trackwp'),
                'error'
            );
            return !empty($current) ? $current : TrackWP_Events::get_defaults();
        }

        return $output;
    }

    /**
     * Sanitize consent settings.
     *
     * @param array $input Raw consent input.
     * @return array Sanitized consent config.
     */
    public function sanitize_consent($input) {
        $current = get_option('trackwp_consent', array());
        $output  = array();

        // Banner style — migrate legacy values then whitelist.
        $style_map = array(
            'bar_bottom'   => 'bottombar',
            'corner_popup' => 'dialog',
        );
        $incoming = isset($input['banner_style']) ? $input['banner_style'] : 'dialog';
        if ( isset($style_map[$incoming]) ) {
            $incoming = $style_map[$incoming];
        }
        $allowed = array('cookiebot', 'dialog', 'bottombar');
        $output['banner_style'] = in_array($incoming, $allowed, true) ? $incoming : 'dialog';

        // Colors — validate hex
        foreach (array('bg_color', 'text_color', 'accent_color', 'button_text_color') as $color_key) {
            $val = isset($input[$color_key]) ? sanitize_hex_color($input[$color_key]) : '';
            $output[$color_key] = $val ? $val : (isset($current[$color_key]) ? $current[$color_key] : '#274A45');
        }

        $output['border_radius'] = isset($input['border_radius']) ? absint($input['border_radius']) : 8;

        // Text fields
        foreach (array('heading', 'description', 'accept_text', 'reject_text', 'customize_text', 'save_text') as $text_key) {
            $output[$text_key] = sanitize_text_field(isset($input[$text_key]) ? $input[$text_key] : '');
        }

        $output['privacy_page_id']          = isset($input['privacy_page_id']) ? absint($input['privacy_page_id']) : 0;

        // Der findes intet UI-felt for 'language' — bevar eksisterende gemt
        // værdi når input mangler, i stedet for at tvinge 'da' ved hvert gem.
        if (isset($input['language'])) {
            $output['language'] = sanitize_text_field($input['language']);
        } else {
            $output['language'] = isset($current['language']) ? sanitize_text_field($current['language']) : 'da';
        }
        $output['show_reject_button']       = !empty($input['show_reject_button']);
        $output['require_active_consent']   = !empty($input['require_active_consent']);
        $output['log_consent']              = !empty($input['log_consent']);
        $output['reconsent_on_policy_change'] = !empty($input['reconsent_on_policy_change']);
        $output['cookie_lifetime_months']   = isset($input['cookie_lifetime_months']) ? absint($input['cookie_lifetime_months']) : 12;

        // Auto-increment consent version if policy changed
        if (!empty($input['reconsent_on_policy_change']) && isset($current['consent_version'])) {
            $output['consent_version'] = absint($current['consent_version']);
            // Check if key texts changed — bump version
            $text_keys_to_watch = array('heading', 'description');
            foreach ($text_keys_to_watch as $key) {
                if (isset($current[$key]) && $current[$key] !== $output[$key]) {
                    $output['consent_version'] = $output['consent_version'] + 1;
                    break;
                }
            }
        } else {
            $output['consent_version'] = isset($current['consent_version']) ? absint($current['consent_version']) : 1;
        }

        return $output;
    }

    /**
     * Sanitize endpoint slug — used by sanitize_advanced() and by TrackWP_Proxy::register_routes().
     * Returns a slug-only string suitable for `/wp-json/trackwp/v1/<slug>`.
     *
     * @param string $raw Raw input.
     * @return string Sanitized slug; defaults to 'event' if invalid.
     */
    public static function sanitize_endpoint_slug($raw) {
        $slug = sanitize_title( (string) $raw );
        if ( strlen($slug) < 1 || strlen($slug) > 32 ) {
            $slug = 'event';
        }
        // The tracking route shares the trackwp/v1 namespace with every other
        // route the plugin registers. Picking one of their slugs would shadow
        // the real endpoint (consent logging, the first-party loader, the GDPR
        // endpoints), so the whole set is reserved — not just 'consent-log'.
        $reserved = array(
            'consent-log', // TrackWP_Consent
            'consent',     // TrackWP_Consent (withdraw)
            'loader',      // TrackWP_Loader
            'c',           // TrackWP_Loader collect-proxy prefix (/c/e, /c/se)
            'keepalive',   // TrackWP_Proxy
            'my-data',     // TrackWP_Proxy (GDPR access/erasure)
        );
        if ( in_array( $slug, $reserved, true ) ) {
            $slug = 'event';
        }
        return $slug;
    }

    /**
     * Sanitize advanced settings.
     *
     * @param array $input Raw advanced input.
     * @return array Sanitized advanced config.
     */
    public function sanitize_advanced($input) {
        $output = array();

        $raw_slug = isset($input['endpoint_path']) ? $input['endpoint_path'] : 'event';
        $output['endpoint_path'] = self::sanitize_endpoint_slug($raw_slug);
        $output['first_party_cookie_enabled'] = !empty($input['first_party_cookie_enabled']);

        $cookie_name         = isset($input['cookie_name']) ? sanitize_key($input['cookie_name']) : '_twp_cid';
        $output['cookie_name'] = !empty($cookie_name) ? $cookie_name : '_twp_cid';

        $adv_months = isset($input['cookie_lifetime_months']) ? absint($input['cookie_lifetime_months']) : 24;
        $output['cookie_lifetime_months']       = min(24, max(1, $adv_months));
        $output['consent_mode_cookieless_pings'] = !empty($input['consent_mode_cookieless_pings']);
        $output['consent_mode_ad_signals']      = !empty($input['consent_mode_ad_signals']);
        $output['debug_log']                    = !empty($input['debug_log']);
        $output['debug_console']                = !empty($input['debug_console']);
        // 'async_loading' / 'defer_tracking' were dropped in 1.7.2 (no UI, no
        // reader) — they are intentionally not written back here.

        // Dedup mode
        $mode = isset($input['dedup_mode']) ? $input['dedup_mode'] : 'client_and_server';
        $output['dedup_mode'] = in_array($mode, array('client_and_server', 'server_only', 'client_only'), true)
            ? $mode : 'client_and_server';

        $output['uses_gtm'] = ! empty($input['uses_gtm']);

        // Delivery log (1.9.0). Off by default — switching it on creates a data
        // store, so it must be a deliberate choice. Retention is clamped hard:
        // this is a diagnostic log, not an archive.
        $output['delivery_log_enabled'] = ! empty($input['delivery_log_enabled']);
        $retention = isset($input['delivery_log_retention_days'])
            ? absint($input['delivery_log_retention_days'])
            : TrackWP_Delivery_Log::DEFAULT_RETENTION_DAYS;
        $output['delivery_log_retention_days'] = max(1, min(TrackWP_Delivery_Log::MAX_RETENTION_DAYS, $retention));

        // New v1.2.0 advanced flags
        $output['ga4_user_id_enabled']          = ! empty($input['ga4_user_id_enabled']);
        $output['batching_enabled']             = ! empty($input['batching_enabled']);
        $output['first_party_loader_enabled']   = ! empty($input['first_party_loader_enabled']);
        $output['capi_debug_logging_enabled']   = ! empty($input['capi_debug_logging_enabled']);

        // Create the table on first enable and keep the pruning cron in step
        // with the toggle. sanitize_advanced() runs before the option is
        // written, so read the new value from $output, not from the DB.
        if ( ! empty($output['delivery_log_enabled']) ) {
            TrackWP_Delivery_Log::create_table();
            if ( ! wp_next_scheduled(TrackWP_Delivery_Log::CRON_HOOK) ) {
                wp_schedule_event(time() + HOUR_IN_SECONDS, 'daily', TrackWP_Delivery_Log::CRON_HOOK);
            }
        } else {
            wp_clear_scheduled_hook(TrackWP_Delivery_Log::CRON_HOOK);
        }

        return $output;
    }

    /**
     * Sanitize the admin-defined custom cookie declarations.
     *
     * Accepts a JSON string of flat rows ({category,name,provider,cookies,
     * purpose,lifetime,transfer}) from the editor and returns them grouped by
     * category, matching the structure TrackWP_Cookie_Scanner::custom_declarations() reads.
     *
     * @param string|array $input
     * @return array
     */
    public function sanitize_cookie_declarations($input) {
        $grouped = array('necessary' => array(), 'statistics' => array(), 'marketing' => array(), 'personalisation' => array());

        // See sanitize_events(): the value is already unslashed by options.php,
        // so a second stripslashes() would corrupt any escaped character in the
        // JSON (e.g. a quote in a "purpose" field) and wipe all declarations.
        if (is_string($input)) {
            $decoded = json_decode($input, true);
            if (!is_array($decoded)) {
                add_settings_error(
                    'trackwp_cookie_declarations',
                    'declarations_invalid_json',
                    __('Cookie-deklarationerne kunne ikke læses (ugyldig JSON). Ingen ændringer blev gemt.', 'trackwp'),
                    'error'
                );
                $existing = get_option('trackwp_cookie_declarations', array());
                return is_array($existing) ? $existing : $grouped;
            }
            $input = $decoded;
        }

        if (!is_array($input)) {
            return $grouped;
        }

        $allowed = array('necessary', 'statistics', 'marketing', 'personalisation');

        // Accept the grouped shape too (that is how the value is stored and
        // exported), so import_settings() can hand its payload straight in.
        $is_grouped = false;
        foreach ($allowed as $cat) {
            if (isset($input[$cat]) && is_array($input[$cat])) {
                $is_grouped = true;
                break;
            }
        }
        if ($is_grouped) {
            $flat = array();
            foreach ($allowed as $cat) {
                if (empty($input[$cat]) || !is_array($input[$cat])) {
                    continue;
                }
                foreach ($input[$cat] as $entry) {
                    if (is_array($entry)) {
                        $entry['category'] = $cat;
                        $flat[] = $entry;
                    }
                }
            }
            $input = $flat;
        }

        foreach ($input as $row) {
            if (!is_array($row)) {
                continue;
            }
            $cat = isset($row['category']) ? sanitize_key($row['category']) : 'necessary';
            if (!in_array($cat, $allowed, true)) {
                $cat = 'necessary';
            }
            $name    = sanitize_text_field(isset($row['name']) ? $row['name'] : '');
            $cookies = sanitize_text_field(isset($row['cookies']) ? $row['cookies'] : '');
            if ($name === '' && $cookies === '') {
                continue;
            }
            $grouped[$cat][] = array(
                'name'     => $name,
                'provider' => sanitize_text_field(isset($row['provider']) ? $row['provider'] : ''),
                'cookies'  => $cookies,
                'purpose'  => sanitize_text_field(isset($row['purpose']) ? $row['purpose'] : ''),
                'lifetime' => sanitize_text_field(isset($row['lifetime']) ? $row['lifetime'] : ''),
                'transfer' => sanitize_text_field(isset($row['transfer']) ? $row['transfer'] : ''),
            );
        }
        return $grouped;
    }

    /**
     * Create the log directory (if missing) and drop webserver protection
     * files into it: .htaccess (Apache deny) and an empty index.html
     * (directory-listing guard on non-Apache stacks).
     *
     * @param string $dir Absolute directory path.
     */
    public static function ensure_log_dir( $dir ) {
        if ( ! is_dir( $dir ) ) {
            wp_mkdir_p( $dir );
        }
        $htaccess = trailingslashit( $dir ) . '.htaccess';
        if ( ! file_exists( $htaccess ) ) {
            // Apache 2.4 (Require) with 2.2 fallback (Order/Deny).
            $rules = "<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\nOrder deny,allow\nDeny from all\n</IfModule>\n";
            @file_put_contents( $htaccess, $rules );
        }
        $index = trailingslashit( $dir ) . 'index.html';
        if ( ! file_exists( $index ) ) {
            @file_put_contents( $index, '' );
        }
    }

    // =========================================================================
    // Getters
    // =========================================================================

    /**
     * Get platform config with decoded secrets.
     *
     * @return array Platform config with *_decoded keys for secrets.
     */
    public static function get_platform_config() {
        $config = get_option('trackwp_platforms', array());

        // Decode secrets
        if (!empty($config['ga4_api_secret'])) {
            $config['ga4_api_secret_decoded'] = TrackWP_Hash::decode($config['ga4_api_secret']);
        }
        if (!empty($config['meta_access_token'])) {
            $config['meta_access_token_decoded'] = TrackWP_Hash::decode($config['meta_access_token']);
        }

        return $config;
    }

    /**
     * Get events config array.
     *
     * @return array Events config.
     */
    public static function get_events_config() {
        return get_option('trackwp_events', TrackWP_Events::get_defaults());
    }

    /**
     * Get consent config array.
     *
     * @return array Consent config.
     */
    public static function get_consent_config() {
        return get_option('trackwp_consent', array());
    }

    /**
     * Get advanced config array.
     *
     * @return array Advanced config.
     */
    public static function get_advanced_config() {
        return get_option('trackwp_advanced', array());
    }

    // =========================================================================
    // Stats
    // =========================================================================

    const STATS_RETENTION_DAYS = 30;

    /**
     * Increment a top-level stat metric for today.
     *
     * @param string $metric One of: events, bot_skipped, consent_accept, consent_reject.
     * @param int    $by     Increment amount (default 1).
     */
    public static function record_stat( $metric, $by = 1 ) {
        $allowed = array( 'events', 'bot_skipped', 'consent_accept', 'consent_reject' );
        if ( ! in_array( $metric, $allowed, true ) ) {
            return;
        }
        $stats = get_option( 'trackwp_stats', array() );
        $today = gmdate( 'Y-m-d' );
        if ( ! isset( $stats[ $today ] ) ) {
            $stats[ $today ] = array();
        }
        $current = isset( $stats[ $today ][ $metric ] ) ? (int) $stats[ $today ][ $metric ] : 0;
        $stats[ $today ][ $metric ] = $current + max( 1, (int) $by );
        $stats = self::prune_stats( $stats );
        update_option( 'trackwp_stats', $stats, false );
    }

    /**
     * Increment a per-event counter for today.
     *
     * @param string $event_name Event identifier (sanitized to slug-safe form).
     */
    public static function record_event_stat( $event_name ) {
        $event_name = sanitize_key( (string) $event_name );
        if ( $event_name === '' ) {
            return;
        }
        $stats = get_option( 'trackwp_stats', array() );
        $today = gmdate( 'Y-m-d' );
        if ( ! isset( $stats[ $today ] ) ) {
            $stats[ $today ] = array();
        }
        if ( ! isset( $stats[ $today ]['by_event'] ) ) {
            $stats[ $today ]['by_event'] = array();
        }
        $current = isset( $stats[ $today ]['by_event'][ $event_name ] ) ? (int) $stats[ $today ]['by_event'][ $event_name ] : 0;
        $stats[ $today ]['by_event'][ $event_name ] = $current + 1;
        $stats = self::prune_stats( $stats );
        update_option( 'trackwp_stats', $stats, false );
    }

    /**
     * Increment a daily metric AND the per-event counter in a single
     * option read/write (the tracking hot path calls this once per event).
     *
     * @param string $metric     One of: events, bot_skipped.
     * @param string $event_name Event identifier.
     */
    public static function record_event_hit( $metric, $event_name ) {
        $allowed = array( 'events', 'bot_skipped' );
        if ( ! in_array( $metric, $allowed, true ) ) {
            return;
        }
        $event_name = sanitize_key( (string) $event_name );

        $stats = get_option( 'trackwp_stats', array() );
        $today = gmdate( 'Y-m-d' );
        if ( ! isset( $stats[ $today ] ) ) {
            $stats[ $today ] = array();
        }
        $current = isset( $stats[ $today ][ $metric ] ) ? (int) $stats[ $today ][ $metric ] : 0;
        $stats[ $today ][ $metric ] = $current + 1;
        if ( $event_name !== '' ) {
            if ( ! isset( $stats[ $today ]['by_event'] ) ) {
                $stats[ $today ]['by_event'] = array();
            }
            $cur_evt = isset( $stats[ $today ]['by_event'][ $event_name ] ) ? (int) $stats[ $today ]['by_event'][ $event_name ] : 0;
            $stats[ $today ]['by_event'][ $event_name ] = $cur_evt + 1;
        }
        $stats = self::prune_stats( $stats );
        update_option( 'trackwp_stats', $stats, false );
    }

    /**
     * Drop buckets older than STATS_RETENTION_DAYS.
     */
    private static function prune_stats( $stats ) {
        $cutoff = strtotime( '-' . self::STATS_RETENTION_DAYS . ' days' );
        foreach ( array_keys( $stats ) as $date ) {
            $ts = strtotime( $date );
            if ( $ts === false || $ts < $cutoff ) {
                unset( $stats[ $date ] );
            }
        }
        return $stats;
    }

    /**
     * Aggregate stats over the last N days into a summary array.
     *
     * @param int $days Window size (default 30, max 30).
     * @return array
     */
    public static function aggregate_stats( $days = 30 ) {
        $days  = max( 1, min( self::STATS_RETENTION_DAYS, (int) $days ) );
        $stats = get_option( 'trackwp_stats', array() );

        $totals = array(
            'events'         => 0,
            'bot_skipped'    => 0,
            'consent_accept' => 0,
            'consent_reject' => 0,
        );
        $by_event = array();
        $per_day  = array();

        // Build N-day window (oldest first).
        for ( $i = $days - 1; $i >= 0; $i-- ) {
            $date = gmdate( 'Y-m-d', strtotime( "-{$i} days" ) );
            $bucket = isset( $stats[ $date ] ) ? $stats[ $date ] : array();
            $day_total = isset( $bucket['events'] ) ? (int) $bucket['events'] : 0;
            $per_day[] = array( 'date' => $date, 'events' => $day_total );

            foreach ( array_keys( $totals ) as $k ) {
                if ( isset( $bucket[ $k ] ) ) {
                    $totals[ $k ] += (int) $bucket[ $k ];
                }
            }
            if ( ! empty( $bucket['by_event'] ) && is_array( $bucket['by_event'] ) ) {
                foreach ( $bucket['by_event'] as $name => $count ) {
                    $by_event[ $name ] = ( isset( $by_event[ $name ] ) ? $by_event[ $name ] : 0 ) + (int) $count;
                }
            }
        }

        arsort( $by_event );

        $consent_total = $totals['consent_accept'] + $totals['consent_reject'];
        $accept_rate   = $consent_total > 0 ? round( ( $totals['consent_accept'] / $consent_total ) * 100, 1 ) : 0;

        return array(
            'days'         => $days,
            'totals'       => $totals,
            'by_event'     => $by_event,
            'per_day'      => $per_day,
            'accept_rate'  => $accept_rate,
        );
    }

    /**
     * Aggregate stats over the last N days AND the prior N days, with trend deltas.
     *
     * @param int $days Window size.
     * @return array {
     *   current:  output of aggregate_stats($days),
     *   previous: per-metric totals for days [-2N, -N),
     *   trend:    per-metric % change (+/- float, or null if previous was zero),
     * }
     */
    public static function aggregate_stats_with_trend( $days = 7 ) {
        $days = max( 1, min( self::STATS_RETENTION_DAYS, (int) $days ) );

        $current = self::aggregate_stats( $days );

        // Previous window: days [-2N, -N).
        $stats = get_option( 'trackwp_stats', array() );
        $prev = array(
            'events'         => 0,
            'bot_skipped'    => 0,
            'consent_accept' => 0,
            'consent_reject' => 0,
        );
        for ( $i = $days * 2 - 1; $i >= $days; $i-- ) {
            $date = gmdate( 'Y-m-d', strtotime( "-{$i} days" ) );
            $bucket = isset( $stats[ $date ] ) ? $stats[ $date ] : array();
            foreach ( array_keys( $prev ) as $k ) {
                if ( isset( $bucket[ $k ] ) ) {
                    $prev[ $k ] += (int) $bucket[ $k ];
                }
            }
        }

        $trend = array();
        foreach ( $prev as $k => $prev_v ) {
            if ( $prev_v === 0 ) {
                $trend[ $k ] = null; // Indeterminate (no prior data).
            } else {
                $curr_v = isset( $current['totals'][ $k ] ) ? $current['totals'][ $k ] : 0;
                $trend[ $k ] = round( ( ( $curr_v - $prev_v ) / $prev_v ) * 100, 1 );
            }
        }

        return array(
            'current'  => $current,
            'previous' => $prev,
            'trend'    => $trend,
        );
    }

    // =========================================================================
    // Plugin action links
    // =========================================================================

    /**
     * Export all trackwp_* settings as a JSON-serializable array.
     * Secrets are kept in their stored (base64) form — re-importable but not human-readable.
     *
     * @param bool $include_secrets Default false — strips ga4_api_secret / meta_access_token.
     * @return array
     */
    public static function export_settings( $include_secrets = false ) {
        $platforms = get_option( 'trackwp_platforms', array() );
        $advanced  = get_option( 'trackwp_advanced',  array() );
        $events    = get_option( 'trackwp_events',    array() );
        $consent   = get_option( 'trackwp_consent',   array() );
        $cookies   = get_option( 'trackwp_cookie_declarations', array() );

        if ( ! $include_secrets ) {
            unset( $platforms['ga4_api_secret'], $platforms['meta_access_token'], $platforms['google_ads_developer_token'], $platforms['google_ads_oauth_client_secret'], $platforms['google_ads_oauth_refresh_token'] );
        }

        return array(
            'version'             => defined( 'TRACKWP_VERSION' ) ? TRACKWP_VERSION : '1.1.0',
            'exported_at'         => gmdate( 'c' ),
            'include_secrets'     => (bool) $include_secrets,
            'platforms'           => $platforms,
            'advanced'            => $advanced,
            'events'              => $events,
            'consent'             => $consent,
            'cookie_declarations' => is_array( $cookies ) ? $cookies : array(),
        );
    }

    /**
     * Import settings from an array (typically decoded JSON).
     * Validates each option group, calls the same sanitizers as the UI.
     *
     * @param array $data Imported settings.
     * @return true|WP_Error
     */
    public static function import_settings( $data ) {
        if ( ! is_array( $data ) || ! isset( $data['platforms'], $data['advanced'], $data['events'], $data['consent'] ) ) {
            return new WP_Error( 'invalid_import', __( 'Ugyldig import-fil — manglende felter.', 'trackwp' ) );
        }

        $instance = new self();

        $platforms = $instance->sanitize_platforms( (array) $data['platforms'] );

        // Eksporten gemmer secrets i deres stored (base64) form, og
        // sanitize_platforms() ville base64-encode dem igen (dobbelt-encode).
        // Gendan derfor de rå importerede værdier direkte — de er allerede
        // i stored form. Mangler nøglen (eller er den tom), beholdes
        // sanitizerens resultat, som bevarer den eksisterende DB-værdi.
        $secret_keys = array( 'ga4_api_secret', 'meta_access_token', 'google_ads_developer_token', 'google_ads_oauth_client_secret', 'google_ads_oauth_refresh_token' );
        foreach ( $secret_keys as $secret_key ) {
            if ( isset( $data['platforms'][ $secret_key ] ) && is_string( $data['platforms'][ $secret_key ] ) && $data['platforms'][ $secret_key ] !== '' ) {
                $platforms[ $secret_key ] = $data['platforms'][ $secret_key ];
            }
        }

        $advanced  = $instance->sanitize_advanced( (array) $data['advanced'] );
        $events    = $instance->sanitize_events( (array) $data['events'] );
        $consent   = $instance->sanitize_consent( (array) $data['consent'] );

        update_option( 'trackwp_platforms', $platforms );
        update_option( 'trackwp_advanced',  $advanced );
        update_option( 'trackwp_events',    $events );
        update_option( 'trackwp_consent',   $consent );

        // Optional — absent in files exported before 1.8.1.
        if ( isset( $data['cookie_declarations'] ) && is_array( $data['cookie_declarations'] ) ) {
            update_option(
                'trackwp_cookie_declarations',
                $instance->sanitize_cookie_declarations( $data['cookie_declarations'] )
            );
        }

        return true;
    }

    /**
     * Add "Settings" link on Plugins page.
     *
     * @param array $links Existing plugin action links.
     * @return array Modified links with Settings prepended.
     */
    public static function add_plugin_action_links($links) {
        $settings_link = '<a href="' . esc_url(admin_url('admin.php?page=trackwp')) . '">' . __('Indstillinger', 'trackwp') . '</a>';
        array_unshift($links, $settings_link);
        return $links;
    }
}
