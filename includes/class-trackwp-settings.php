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
     * Set by import_settings() for the duration of a sanitize_platforms()
     * call. The exported JSON already stores secrets in their stored
     * (base64) form (see export_settings()), so re-running them through
     * TrackWP_Hash::encode() would double-encode them. This flag tells
     * sanitize_platforms() to keep the incoming secret values as-is instead
     * of re-encoding — the single source of truth for the "double encoding
     * on import" fix, replacing the old post-hoc raw-value restore.
     *
     * @var bool
     */
    private static $presanitized = false;

    /**
     * Set by bump_consent_version() for the duration of its update_option()
     * call, so sanitize_consent() (hooked on sanitize_option_trackwp_consent)
     * keeps the bumped version instead of restoring the stored one.
     *
     * @var int|null
     */
    private static $bump_consent_to = null;

    /** KB1 modes. The first entry is the default. */
    const BLOCKER_MODES = array( 'off', 'test', 'on' );

    /** KB1 rule categories. */
    const BLOCKER_CATEGORIES = array( 'necessary', 'statistics', 'marketing', 'personalisation' );

    /** KB2 rule-id prefixes. "cookie" (KC2/D6) is only valid in exceptions.allow — never.cookies. */
    const BLOCKER_RULE_TYPES = array( 'handle', 'url', 'host', 'inline', 'pixel', 'cookie' );

    /** KB3 inline-marker allowlist. */
    const BLOCKER_MARKER_PATTERN = '/^[A-Za-z0-9._-]{8,64}$/';

    /** S13: the front page is always page 1, so at most 4 extra paths (5 pages in total). */
    const BLOCKER_MAX_EXTRA_PATHS = 4;

    /** Upper bound for exceptions.paths and exceptions.allow entries. */
    const BLOCKER_MAX_EXCEPTIONS = 100;

    /**
     * Cloudflare's published IP ranges, used to expand advanced.trusted_proxies
     * when advanced.trusted_proxies_cloudflare is enabled. TrackWP_Request_Guard
     * (W1) reads these via get_trusted_proxies() to recognise CF-Connecting-IP
     * as trustworthy.
     *
     * Fetched directly via `curl -s https://www.cloudflare.com/ips-v4` and
     * `curl -s https://www.cloudflare.com/ips-v6` on 2026-09-27 — this is the
     * exact, current content of both lists, not a memorised/guessed value.
     *
     * @return string[] CIDR blocks.
     */
    public static function cloudflare_ip_ranges() {
        return array(
            // IPv4 — https://www.cloudflare.com/ips-v4 (fetched 2026-09-27)
            '173.245.48.0/20',
            '103.21.244.0/22',
            '103.22.200.0/22',
            '103.31.4.0/22',
            '141.101.64.0/18',
            '108.162.192.0/18',
            '190.93.240.0/20',
            '188.114.96.0/20',
            '197.234.240.0/22',
            '198.41.128.0/17',
            '162.158.0.0/15',
            '104.16.0.0/13',
            '104.24.0.0/14',
            '172.64.0.0/13',
            '131.0.72.0/22',
            // IPv6 — https://www.cloudflare.com/ips-v6 (fetched 2026-09-27)
            '2400:cb00::/32',
            '2606:4700::/32',
            '2803:f800::/32',
            '2405:b500::/32',
            '2405:8100::/32',
            '2a06:98c0::/29',
            '2c0f:f248::/32',
        );
    }

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
        register_setting('trackwp_woocommerce_group', 'trackwp_woocommerce', array(
            'sanitize_callback' => array($this, 'sanitize_woocommerce'),
        ));
        // KB1: blocking-until-consent. Autoload is set by the add_option() in
        // the 1.11.0 upgrade (T6); register_setting() does not touch it.
        register_setting('trackwp_blocker_group', 'trackwp_blocker', array(
            'sanitize_callback' => array($this, 'sanitize_blocker'),
        ));

        // admin-post.php runs admin_init before dispatching admin_post_{action},
        // so registering here (rather than at plugin bootstrap) is in time.
        add_action( 'admin_post_trackwp_consent_export', array( __CLASS__, 'handle_consent_export' ) );
        add_action( 'admin_post_trackwp_bump_consent', array( __CLASS__, 'handle_bump_consent' ) );
        add_action( 'admin_notices', array( $this, 'render_admin_notices' ) );
    }

    /**
     * Sanitize the WooCommerce tab.
     *
     * Unchecked checkboxes are absent from the POST, so every boolean is read
     * with !empty() and the whole set is rewritten — the same pattern as
     * sanitize_advanced(). Selects are validated against their own source of
     * truth in TrackWP_WooCommerce rather than a duplicated literal list.
     *
     * No stripslashes() here: options.php has already run wp_unslash() on the
     * input, and unslashing twice eats legitimate escapes.
     *
     * @param mixed $input Raw POST value.
     * @return array
     */
    public function sanitize_woocommerce($input) {
        $defaults = TrackWP_WooCommerce::get_defaults();

        if (!is_array($input)) {
            return $defaults;
        }

        $output = array();
        $output['enabled'] = !empty($input['enabled']);

        foreach (TrackWP_Events::get_woocommerce_event_names() as $event_name) {
            $key            = 'event_' . $event_name;
            $output[ $key ] = !empty($input[ $key ]);
        }

        $basis                 = isset($input['value_basis']) ? sanitize_key($input['value_basis']) : '';
        $output['value_basis'] = array_key_exists($basis, TrackWP_WooCommerce::value_bases())
            ? $basis
            : $defaults['value_basis'];

        $source                   = isset($input['item_id_source']) ? sanitize_key($input['item_id_source']) : '';
        $output['item_id_source'] = in_array($source, array('product_id', 'sku'), true)
            ? $source
            : $defaults['item_id_source'];

        $output['include_categories'] = !empty($input['include_categories']);

        return $output;
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
        $output['ga4_api_secret'] = $this->encode_secret_input(
            isset($input['ga4_api_secret']) ? $input['ga4_api_secret'] : '',
            isset($current['ga4_api_secret']) ? $current['ga4_api_secret'] : ''
        );

        $output['ga4_gtag_enabled'] = ! empty($input['ga4_gtag_enabled']);

        // Google Ads
        $output['google_ads_enabled']       = !empty($input['google_ads_enabled']);
        $output['google_ads_conversion_id'] = sanitize_text_field(isset($input['google_ads_conversion_id']) ? $input['google_ads_conversion_id'] : '');

        // Meta
        $output['meta_enabled']  = !empty($input['meta_enabled']);
        $output['meta_pixel_id'] = sanitize_text_field(isset($input['meta_pixel_id']) ? $input['meta_pixel_id'] : '');

        // Meta Access Token — same pattern as GA4 secret
        $output['meta_access_token'] = $this->encode_secret_input(
            isset($input['meta_access_token']) ? $input['meta_access_token'] : '',
            isset($current['meta_access_token']) ? $current['meta_access_token'] : ''
        );

        $output['meta_pixel_client_enabled'] = ! empty($input['meta_pixel_client_enabled']);

        // M1 (KB15): TrackWP delivers the Meta Pixel even when GTM is active.
        // Opt-in, default false.
        $output['meta_pixel_with_gtm'] = ! empty($input['meta_pixel_with_gtm']);

        // Meta Test Event Code — must match TEST<digits> or be empty
        $raw_test_code = isset($input['meta_test_event_code']) ? sanitize_text_field($input['meta_test_event_code']) : '';
        $raw_test_code = trim($raw_test_code);
        if ( $raw_test_code === '' || preg_match('/^TEST\d+$/', $raw_test_code) ) {
            $output['meta_test_event_code'] = $raw_test_code;
        } else {
            $output['meta_test_event_code'] = '';
        }

        // Meta API version — whitelist
        // Single source of truth: TrackWP_Meta (W3) owns the version and the
        // supported list.
        $allowed_meta_versions = TrackWP_Meta::SUPPORTED_API_VERSIONS;
        $meta_version = isset($input['meta_api_version']) ? sanitize_text_field($input['meta_api_version']) : '';
        $output['meta_api_version'] = in_array($meta_version, $allowed_meta_versions, true) ? $meta_version : TrackWP_Meta::DEFAULT_API_VERSION;

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
        $output['google_ads_developer_token'] = $this->encode_secret_input(
            isset($input['google_ads_developer_token']) ? $input['google_ads_developer_token'] : '',
            isset($current['google_ads_developer_token']) ? $current['google_ads_developer_token'] : ''
        );

        // Google Ads OAuth Client ID — plain text (not a secret)
        $output['google_ads_oauth_client_id'] = sanitize_text_field( isset($input['google_ads_oauth_client_id']) ? $input['google_ads_oauth_client_id'] : '' );

        // Google Ads OAuth Client Secret — same secret pattern as developer token
        $output['google_ads_oauth_client_secret'] = $this->encode_secret_input(
            isset($input['google_ads_oauth_client_secret']) ? $input['google_ads_oauth_client_secret'] : '',
            isset($current['google_ads_oauth_client_secret']) ? $current['google_ads_oauth_client_secret'] : ''
        );

        // Google Ads OAuth Refresh Token — same secret pattern
        $output['google_ads_oauth_refresh_token'] = $this->encode_secret_input(
            isset($input['google_ads_oauth_refresh_token']) ? $input['google_ads_oauth_refresh_token'] : '',
            isset($current['google_ads_oauth_refresh_token']) ? $current['google_ads_oauth_refresh_token'] : ''
        );

        // GA4-conversions imported into Google Ads as a conversion goal. When
        // this AND a direct Google Ads conversion action both count the same
        // goal, the campaign gets double-counted — the admin notice
        // (render_admin_notices()) warns about this combination.
        $output['ga4_imported_to_ads'] = ! empty( $input['ga4_imported_to_ads'] );

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

        // A stored Graph token error (TrackWP_Meta_Takeover::LAST_ERROR_OPTION)
        // belongs to the old token. Drop it only when a new token was saved,
        // not when the field was left empty or masked (review MINOR 5).
        $old_token = isset($current['meta_access_token']) ? (string) $current['meta_access_token'] : '';
        if ( $output['meta_access_token'] !== '' && $output['meta_access_token'] !== $old_token && class_exists('TrackWP_Meta_Takeover') ) {
            delete_option( TrackWP_Meta_Takeover::LAST_ERROR_OPTION );
        }

        // D1/KC6: explicit kill switch for Meta for WooCommerce. It stops
        // fb4woo's own pixel/CAPI/_fbp (via filter_pixel_enabled()), and does
        // NOT affect whether TrackWP itself delivers — that is can_deliver()
        // alone, unaffected by this flag (TrackWP_Meta_Takeover::status()).
        $output['fb4woo_tracking_off'] = ! empty( $input['fb4woo_tracking_off'] );

        // D2/KC7: the dataLayer layer. 'test' only pushes for manage_options
        // users at runtime (TrackWP_DataLayer, W4) — that is a runtime check,
        // not a save-time capability gate. Forced to 'off' when GTM is not in
        // use, because KC8's routing has no meaning without GTM.
        $dl_mode = isset( $input['gtm_datalayer_events'] ) && is_string( $input['gtm_datalayer_events'] ) ? sanitize_key( $input['gtm_datalayer_events'] ) : 'off';
        $dl_mode = in_array( $dl_mode, array( 'off', 'test', 'on' ), true ) ? $dl_mode : 'off';
        $advanced_for_gtm = get_option( 'trackwp_advanced', array() );
        $uses_gtm = ! empty( $output['gtm_enabled'] ) || ( is_array( $advanced_for_gtm ) && ! empty( $advanced_for_gtm['uses_gtm'] ) );
        $output['gtm_datalayer_events'] = $uses_gtm ? $dl_mode : 'off';

        // D4/KC8: routing for GA4 browser events once the dataLayer is on.
        // Ignored (but still stored) while the layer is off.
        $ga4_source = isset( $input['ga4_source'] ) && is_string( $input['ga4_source'] ) ? sanitize_key( $input['ga4_source'] ) : 'gtm';
        $output['ga4_source'] = in_array( $ga4_source, array( 'gtm', 'split' ), true ) ? $ga4_source : 'gtm';

        return $output;
    }

    /**
     * Shared secret-input handling for sanitize_platforms(): keeps the
     * stored value when the field is empty or still shows the masked
     * placeholder, otherwise encodes a freshly entered value — except during
     * import_settings() (self::$presanitized), where the incoming value is
     * already stored (base64) form and must be kept as-is to avoid double
     * encoding.
     *
     * @param string $input_value   Raw POSTed (or imported) value.
     * @param string $current_value Existing stored (encoded) value.
     * @return string
     */
    private function encode_secret_input($input_value, $current_value) {
        if (!empty($input_value) && $input_value !== '••••••••') {
            return self::$presanitized ? $input_value : TrackWP_Hash::encode($input_value);
        }
        return $current_value ? $current_value : '';
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
        // "Vis afvis-knap" udgår (1.10.1): "Afvis valgfrie" er nu altid synlig
        // på første lag med samme prominens som accept (BESLUTNINGER §4), så
        // valget findes ikke længere.
        $output['require_active_consent']   = !empty($input['require_active_consent']);
        $output['log_consent']              = !empty($input['log_consent']);
        $output['reconsent_on_policy_change'] = !empty($input['reconsent_on_policy_change']);

        // K7: consent.cookie_lifetime_months er clamped 1-12 (både her og i UI'ets
        // min/max — se templates/settings-page.php).
        $cookie_months = isset($input['cookie_lifetime_months']) ? absint($input['cookie_lifetime_months']) : 12;
        $output['cookie_lifetime_months'] = min(12, max(1, $cookie_months));

        // Dynamisk vs. brugerredigeret banner-tekst (TrackWP_Consent_Profile::default_texts(), W6).
        $description_mode = isset($input['description_mode']) ? sanitize_key($input['description_mode']) : 'auto';
        $output['description_mode'] = in_array($description_mode, array('auto', 'custom'), true) ? $description_mode : 'auto';

        // Samtykkelog-opbevaring (måneder). Clamp 6-60, standard 24 (TrackWP_Consent_Log::prune(), W1).
        $retention = isset($input['consent_log_retention_months']) ? absint($input['consent_log_retention_months']) : 24;
        $output['consent_log_retention_months'] = min(60, max(6, $retention));

        // Dataansvarlig — indsat i bannerets standardtekst (TrackWP_Consent_Profile::default_texts(), W6).
        $output['controller_name'] = sanitize_text_field( isset($input['controller_name']) ? $input['controller_name'] : '' );

        // GTM-vendorliste: kan ikke udledes automatisk, så admin vælger selv,
        // når GTM er aktiv (BESLUTNINGER §4). Strukturen {known, custom} er
        // ejet af W7, men TrackWP_Consent_Profile::vendor_catalog() (W6) er det
        // ENESTE katalog for "known" — se TrackWP_Consent_Profile::gtm_vendors()
        // for hvordan "custom" bruges (skal have category i OPTIONAL_CATEGORIES).
        $known_catalog = ( class_exists( 'TrackWP_Consent_Profile' ) && method_exists( 'TrackWP_Consent_Profile', 'vendor_catalog' ) )
            ? array_keys( TrackWP_Consent_Profile::vendor_catalog() )
            : array();
        $known_input = isset($input['gtm_vendors']['known']) && is_array($input['gtm_vendors']['known'])
            ? $input['gtm_vendors']['known']
            : array();
        $known_out = array();
        foreach ( $known_input as $vendor_key ) {
            $vendor_key = sanitize_key( $vendor_key );
            if ( in_array( $vendor_key, $known_catalog, true ) && ! in_array( $vendor_key, $known_out, true ) ) {
                $known_out[] = $vendor_key;
            }
        }

        // Custom rows: {name, provider, category, cookies, purpose, lifetime,
        // transfer}. Accepts a JSON string from the admin editor (same pattern
        // as sanitize_cookie_declarations()) or a plain array from import.
        $optional_categories = class_exists( 'TrackWP_Consent_Profile' )
            ? TrackWP_Consent_Profile::OPTIONAL_CATEGORIES
            : array( 'statistics', 'marketing', 'personalisation' );
        $custom_raw = isset($input['gtm_vendors']['custom']) ? $input['gtm_vendors']['custom'] : array();
        if ( is_string( $custom_raw ) ) {
            $decoded    = json_decode( $custom_raw, true );
            $custom_raw = is_array( $decoded ) ? $decoded : array();
        }
        $custom_out = array();
        foreach ( (array) $custom_raw as $row ) {
            if ( ! is_array( $row ) ) {
                continue;
            }
            $name = sanitize_text_field( isset($row['name']) ? $row['name'] : '' );
            if ( $name === '' ) {
                continue;
            }
            $category = isset($row['category']) ? sanitize_key( $row['category'] ) : 'marketing';
            if ( ! in_array( $category, $optional_categories, true ) ) {
                $category = 'marketing';
            }
            $custom_out[] = array(
                'name'     => $name,
                'provider' => sanitize_text_field( isset($row['provider']) ? $row['provider'] : '' ),
                'category' => $category,
                'cookies'  => sanitize_text_field( isset($row['cookies']) ? $row['cookies'] : '' ),
                'purpose'  => sanitize_text_field( isset($row['purpose']) ? $row['purpose'] : '' ),
                'lifetime' => sanitize_text_field( isset($row['lifetime']) ? $row['lifetime'] : '' ),
                'transfer' => sanitize_text_field( isset($row['transfer']) ? $row['transfer'] : '' ),
            );
        }
        $output['gtm_vendors'] = array(
            'known'  => $known_out,
            'custom' => $custom_out,
        );

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

        // The form never carries consent_version (it is always taken from the
        // stored value above). The only writer of a higher value is the
        // explicit "Bed om nyt samtykke" action, see bump_consent_version().
        if ( null !== self::$bump_consent_to ) {
            $output['consent_version'] = self::$bump_consent_to;
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
            'blocker',     // TrackWP_Blocker_Scanner (/blocker/scan, 1.11.0)
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

        // Deling af kundedata (EC/AM). Standard: slået til. Slås den fra,
        // droppes enhanced/user_data/AM helt (K2, K8) — bl.a. relevant for
        // sites i følsomme brancher, se admin-noten i render_admin_notices().
        $output['customer_data_sharing'] = array_key_exists( 'customer_data_sharing', $input )
            ? ! empty( $input['customer_data_sharing'] )
            : true;

        // Betroede proxyer (CIDR-liste), brugt af TrackWP_Request_Guard::client_ip() (W1)
        // til at afgøre hvornår CF-Connecting-IP/X-Forwarded-For skal foretrækkes
        // frem for REMOTE_ADDR.
        $output['trusted_proxies']            = self::sanitize_cidr_list( isset($input['trusted_proxies']) ? $input['trusted_proxies'] : '' );
        $output['trusted_proxies_cloudflare'] = ! empty( $input['trusted_proxies_cloudflare'] );

        // Eksplicit cookie-domæne (K7 registrable_domain() prioritet 1). Kun en
        // sanitized streng her — selve valideringen mod home_url-hosten sker i
        // TrackWP_Cookies::registrable_domain() (W1).
        $raw_domain = isset($input['cookie_domain']) ? strtolower( trim( (string) $input['cookie_domain'] ) ) : '';
        $raw_domain = preg_replace( '#^https?://#', '', $raw_domain );
        $raw_domain = rtrim( $raw_domain, '/' );
        $output['cookie_domain'] = preg_match( '/^[a-z0-9.\-]*$/', $raw_domain ) ? $raw_domain : '';

        // Standard-landekode (ISO-2) for telefonnumre uden internationalt
        // præfiks (R19). Falder tilbage til WC-basislandet eller 'DK' i getteren.
        $phone_country = isset($input['default_phone_country']) ? strtoupper( sanitize_text_field( $input['default_phone_country'] ) ) : '';
        $output['default_phone_country'] = preg_match( '/^[A-Z]{2}$/', $phone_country ) ? $phone_country : '';

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
     * Validate a newline-separated list of CIDR blocks (IPv4 or IPv6). A
     * plain IP without a "/" prefix is accepted and normalised to a
     * single-host block (/32 for IPv4, /128 for IPv6) — trusted_proxies is
     * most often a handful of single reverse-proxy IPs, not ranges, and
     * requiring a prefix there was rejecting valid, common input.
     * Invalid lines are dropped silently (best-effort — a malformed line here
     * must never fail the whole save).
     *
     * @param string $raw Raw textarea input.
     * @return string[] Valid CIDR strings.
     */
    private static function sanitize_cidr_list( $raw ) {
        $lines = preg_split( '/[\r\n]+/', (string) $raw );
        $out   = array();
        foreach ( $lines as $line ) {
            $line = trim( $line );
            if ( $line === '' ) {
                continue;
            }
            if ( strpos( $line, '/' ) === false ) {
                // Plain IP, no prefix — normalise to a single-host block.
                if ( filter_var( $line, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 ) ) {
                    $out[] = $line . '/32';
                } elseif ( filter_var( $line, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6 ) ) {
                    $out[] = $line . '/128';
                }
                continue;
            }
            list( $addr, $prefix ) = array_pad( explode( '/', $line, 2 ), 2, '' );
            if ( ! ctype_digit( $prefix ) ) {
                continue;
            }
            $prefix = (int) $prefix;
            if ( filter_var( $addr, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 ) && $prefix >= 0 && $prefix <= 32 ) {
                $out[] = $addr . '/' . $prefix;
            } elseif ( filter_var( $addr, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6 ) && $prefix >= 0 && $prefix <= 128 ) {
                $out[] = $addr . '/' . $prefix;
            }
        }
        return array_values( array_unique( $out ) );
    }

    /**
     * The effective trusted-proxies list: advanced.trusted_proxies plus
     * Cloudflare's published ranges when advanced.trusted_proxies_cloudflare
     * is enabled. TrackWP_Request_Guard::client_ip() (W1) is the consumer.
     *
     * @return string[] CIDR blocks.
     */
    public static function get_trusted_proxies() {
        $advanced = get_option( 'trackwp_advanced', array() );
        $list     = isset( $advanced['trusted_proxies'] ) && is_array( $advanced['trusted_proxies'] )
            ? $advanced['trusted_proxies']
            : array();
        if ( ! empty( $advanced['trusted_proxies_cloudflare'] ) ) {
            $list = array_merge( $list, self::cloudflare_ip_ranges() );
        }
        return array_values( array_unique( $list ) );
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
     * Names the by_event breakdown is allowed to bucket individually:
     * the site's configured events plus the reserved public names (K2).
     * Anything else — third-party sendEvent() calls with arbitrary names,
     * events for a since-deleted config row, etc. — is folded into '_other'
     * so the breakdown cannot grow without bound.
     *
     * @return string[]
     */
    private static function known_event_names() {
        $names = TrackWP_Events::RESERVED_PUBLIC_NAMES;
        foreach ( self::get_events_config() as $event ) {
            if ( is_array( $event ) && isset( $event['name'] ) ) {
                $names[] = (string) $event['name'];
            }
        }
        return $names;
    }

    /**
     * Increment a top-level stat metric for today.
     *
     * @param string $metric One of: events, bot_skipped, consent_accept, consent_reject.
     * @param int    $by     Increment amount (default 1).
     */
    public static function record_stat( $metric, $by = 1 ) {
        $allowed = array( 'events', 'bot_skipped', 'consent_accept', 'consent_reject', 'forwarded' );
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
        if ( ! in_array( $event_name, self::known_event_names(), true ) ) {
            $event_name = '_other';
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
        // R20: 'forwarded' is counted when at least one destination result is
        // ok or queued (K1) — a proxy-owned concept, added here so W2 has a
        // single stats entrypoint.
        $allowed = array( 'events', 'bot_skipped', 'forwarded' );
        if ( ! in_array( $metric, $allowed, true ) ) {
            return;
        }
        $event_name = sanitize_key( (string) $event_name );
        if ( $event_name !== '' && ! in_array( $event_name, self::known_event_names(), true ) ) {
            $event_name = '_other';
        }

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
            'forwarded'      => 0,
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
            'forwarded'      => 0,
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
        $platforms   = get_option( 'trackwp_platforms', array() );
        $advanced    = get_option( 'trackwp_advanced',  array() );
        $events      = get_option( 'trackwp_events',    array() );
        $consent     = get_option( 'trackwp_consent',   array() );
        $cookies     = get_option( 'trackwp_cookie_declarations', array() );
        $woocommerce = get_option( 'trackwp_woocommerce', array() );
        $blocker     = get_option( 'trackwp_blocker', array() );

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
            'woocommerce'         => is_array( $woocommerce ) ? $woocommerce : array(),
            'blocker'             => is_array( $blocker ) ? $blocker : array(),
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

        // Eksporten gemmer secrets i deres stored (base64) form. Uden
        // self::$presanitized ville sanitize_platforms() base64-encode dem
        // IGEN (dobbelt-encode) — den bug denne flag retter. Flaget nulstilles
        // altid, også hvis sanitize_platforms() kaster.
        self::$presanitized = true;
        try {
            $platforms = $instance->sanitize_platforms( (array) $data['platforms'] );
        } finally {
            self::$presanitized = false;
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

        // Optional — absent in files exported before 1.10.1.
        if ( isset( $data['woocommerce'] ) && is_array( $data['woocommerce'] ) ) {
            update_option(
                'trackwp_woocommerce',
                $instance->sanitize_woocommerce( $data['woocommerce'] )
            );
        }

        // Optional — absent in files exported before 1.11.0.
        if ( isset( $data['blocker'] ) && is_array( $data['blocker'] ) ) {
            update_option(
                'trackwp_blocker',
                $instance->sanitize_blocker( $data['blocker'] )
            );
        }

        return true;
    }

    // =========================================================================
    // Blocking until consent (1.11.0, KB1/KB2, S13, S16)
    // =========================================================================

    /**
     * KB1 defaults: mode off, nothing configured.
     *
     * @return array
     */
    public static function blocker_defaults() {
        return array(
            'mode'            => 'off',
            'rules'           => array(),
            'custom_vendors'  => array(),
            'exceptions'      => array(
                'allow' => array(),
                'paths' => array(),
            ),
            'extra_paths'     => array(),
            // TR7: generic ad-click-id parameters that make a page uncacheable
            // (KC4.6). A setting, not a site-specific hardcode — admin can add
            // affiliate parameters via the "Tilføj server-cookie" section.
            'nocache_params'  => self::default_nocache_params(),
        );
    }

    /**
     * TR7: default click-id parameter list (generic, not site-specific).
     * TrackWP_Cookie_Gate (W1) is the single source of truth when it exists;
     * the literal list here is only a fallback so this file keeps working
     * standalone (e.g. before W1's class is loaded during parallel dev/tests).
     */
    public static function default_nocache_params() {
        if ( class_exists( 'TrackWP_Cookie_Gate' ) && defined( 'TrackWP_Cookie_Gate::DEFAULT_NOCACHE_PARAMS' ) ) {
            $from_gate = constant( 'TrackWP_Cookie_Gate::DEFAULT_NOCACHE_PARAMS' );
            if ( is_array( $from_gate ) ) {
                return $from_gate;
            }
        }
        return array( 'gclid', 'gbraid', 'wbraid', 'dclid', 'fbclid', 'msclkid', 'ttclid', 'li_fat_id', 'twclid', 'epik', 'ScCid' );
    }

    /** Upper bound for trackwp_blocker['nocache_params'] (TR7). */
    const BLOCKER_MAX_NOCACHE_PARAMS = 30;

    /**
     * Stored blocker option merged over the defaults.
     *
     * @return array
     */
    public static function get_blocker_config() {
        $stored = get_option( 'trackwp_blocker', array() );
        $config = array_merge( self::blocker_defaults(), is_array( $stored ) ? $stored : array() );
        if ( ! is_array( $config['exceptions'] ) ) {
            $config['exceptions'] = array();
        }
        $config['exceptions'] = array_merge( array( 'allow' => array(), 'paths' => array() ), $config['exceptions'] );
        return $config;
    }

    /**
     * KC3: honest, code-specific texts for TrackWP_Cookie_Gate's own status
     * codes (TrackWP_Blocker::record_status()), shared by notice_blocker()
     * and templates/partials/admin-blocker.php. These are NOT HTML-rewriting
     * failures, so they never reuse the blocker's "omskrivningen fejlede".
     *
     * @return array<string,string>
     */
    public static function cookie_gate_status_texts() {
        return array(
            'cookie_gate_late'            => __( 'Cookie-gaten kunne ikke køre på en side, fordi HTTP-headers allerede var sendt, da den skulle registrere sig. Server-cookies er måske ikke fjernet på den side.', 'trackwp' ),
            'cookie_gate_register_failed' => __( 'Cookie-gaten kunne ikke registrere sig hos PHP — et andet plugin har formentlig allerede overtaget header-callbacken. Server-cookies fjernes ikke, før det er løst.', 'trackwp' ),
            'cookie_gate_exception'       => __( 'Cookie-gaten stødte på en fejl under beregningen og ændrede ingen headers på den side. Server-cookies blev ikke fjernet på den side.', 'trackwp' ),
            'cookie_gate_not_run'         => __( 'Cookie-gaten kunne ikke køre på en side (headers var allerede sendt, eller et andet plugin overtog header-callbacken). Server-cookies er måske ikke fjernet.', 'trackwp' ),
        );
    }

    /**
     * KB4 status labels, shared by the admin table and the tests.
     *
     * @return array<string,string>
     */
    public static function blocker_status_labels() {
        return array(
            'blocked'           => __( 'Blokeres', 'trackwp' ),
            'allowed'           => __( 'Tillades', 'trackwp' ),
            'cannot_server'     => __( 'Sat af serveren, ingen regel', 'trackwp' ),
            'server_gated'      => __( 'Regel aktiv: fjernes uden samtykke', 'trackwp' ),
            'cannot_serverside' => __( 'Kan ikke blokeres (server-til-server)', 'trackwp' ),
            'cannot_bundled'    => __( 'Kan ikke blokeres (sammenlagt)', 'trackwp' ),
            'gtm_consent_mode'  => __( 'Styres af GTM/Consent Mode (kræver konfiguration i GTM)', 'trackwp' ),
            'protected'         => __( 'Beskyttet (kræver vurdering)', 'trackwp' ),
            'before_trackwp'    => __( 'Kører før TrackWP', 'trackwp' ),
        );
    }

    /**
     * Sanitize the trackwp_blocker option (KB1). Used by the settings form
     * (options.php) and by import_settings().
     *
     * - mode: whitelist, default off; forced off when TrackWP_Blocker::html_api_available() is false.
     * - rules: keys must be KB2 rule ids, category whitelist, vendor must be a
     *   catalog key or a custom vendor id (otherwise '' = "uafklaret").
     *   A rule in the necessary category is never blocked.
     * - exceptions.allow: only prefixed rule ids (S16). The admin form sends
     *   {type, value} rows; the prefix is added here.
     * - exceptions.paths / extra_paths: paths only, no absolute URLs, query
     *   and fragment stripped. extra_paths is capped at 4 (S13).
     *
     * @param mixed $input Raw form or import input.
     * @return array
     */
    public function sanitize_blocker( $input ) {
        $output = self::blocker_defaults();
        if ( ! is_array( $input ) ) {
            return $output;
        }

        $mode           = isset( $input['mode'] ) && is_string( $input['mode'] ) ? sanitize_key( $input['mode'] ) : 'off';
        $output['mode'] = in_array( $mode, self::BLOCKER_MODES, true ) ? $mode : 'off';
        if ( ! TrackWP_Blocker::html_api_available() ) {
            $output['mode'] = 'off';
        }

        // Custom vendors — same fields as gtm_vendors.custom (normalize_custom_vendor, T1).
        $custom_raw = isset( $input['custom_vendors'] ) ? $input['custom_vendors'] : array();
        if ( is_string( $custom_raw ) ) {
            $decoded    = json_decode( $custom_raw, true );
            $custom_raw = is_array( $decoded ) ? $decoded : array();
        }
        // The vendor id is TrackWP_Consent_Profile::normalize_custom_vendor(..., 'blk_')['key'],
        // the same key the profile declares under (review B1). The form sends
        // the stored key along; when a rename changes the key, rules pointing
        // at the old key are moved to the new one ($renamed, review MINOR 2).
        $custom_keys = array();
        $renamed     = array();
        foreach ( (array) $custom_raw as $row ) {
            if ( ! is_array( $row ) ) {
                continue;
            }
            $clean = array();
            foreach ( array( 'name', 'provider', 'category', 'cookies', 'purpose', 'lifetime', 'transfer' ) as $field ) {
                $clean[ $field ] = isset( $row[ $field ] ) && is_scalar( $row[ $field ] ) ? sanitize_text_field( (string) $row[ $field ] ) : '';
            }
            $clean['category'] = sanitize_key( $clean['category'] );
            if ( '' === $clean['category'] ) {
                $clean['category'] = 'marketing';
            }
            $vendor = TrackWP_Consent_Profile::normalize_custom_vendor( $clean, 'blk_' );
            if ( null === $vendor ) {
                continue;
            }
            if ( isset( $custom_keys[ $vendor['key'] ] ) ) {
                // Two names that normalise to the same key cannot both be declared (review MINOR 3).
                add_settings_error(
                    'trackwp_blocker',
                    'blocker_vendor_duplicate',
                    sprintf(
                        /* translators: %s: custom vendor name */
                        __( 'Egen vendor "%s" blev ikke gemt, fordi navnet svarer til en anden egen vendor. Giv den et mere forskelligt navn.', 'trackwp' ),
                        $clean['name']
                    ),
                    'warning'
                );
                continue;
            }
            $custom_keys[ $vendor['key'] ] = true;
            $old_key = isset( $row['key'] ) && is_string( $row['key'] ) ? sanitize_key( $row['key'] ) : '';
            if ( '' !== $old_key && $old_key !== $vendor['key'] ) {
                $renamed[ $old_key ] = $vendor['key'];
            }
            $output['custom_vendors'][] = array_merge( array( 'key' => $vendor['key'] ), $clean );
        }

        $catalog = ( class_exists( 'TrackWP_Consent_Profile' ) && method_exists( 'TrackWP_Consent_Profile', 'vendor_catalog' ) )
            ? TrackWP_Consent_Profile::vendor_catalog()
            : array();

        // Rules.
        $rules_raw    = isset( $input['rules'] ) && is_array( $input['rules'] ) ? $input['rules'] : array();
        $never_hits   = 0;
        foreach ( $rules_raw as $rule_id => $rule ) {
            $rule_id = self::sanitize_blocker_rule_id( $rule_id );
            if ( '' === $rule_id || ! is_array( $rule ) ) {
                continue;
            }
            // KC2/D6: a cookie: rule may never target a cookie the gate must
            // always keep (session, login, consent itself).
            if ( 0 === strpos( $rule_id, 'cookie:' ) && self::blocker_rule_hits_never_cookie( substr( $rule_id, 7 ) ) ) {
                $never_hits++;
                continue;
            }
            $output['rules'][ $rule_id ] = self::sanitize_blocker_rule( $rule, $catalog, $custom_keys, $renamed );
        }
        if ( $never_hits > 0 ) {
            add_settings_error(
                'trackwp_blocker',
                'blocker_cookie_never',
                __( 'En eller flere server-cookie-regler blev ikke gemt, fordi de rammer en cookie, der aldrig må fjernes (fx login, WooCommerce-session eller selve samtykket).', 'trackwp' ),
                'warning'
            );
        }

        // TR7: click-id parameters that make a page uncacheable.
        $nocache_raw = isset( $input['nocache_params'] ) ? $input['nocache_params'] : self::default_nocache_params();
        if ( is_string( $nocache_raw ) ) {
            $nocache_raw = preg_split( '/[\s,]+/', $nocache_raw, -1, PREG_SPLIT_NO_EMPTY );
        }
        $output['nocache_params'] = array();
        foreach ( (array) $nocache_raw as $param ) {
            if ( ! is_scalar( $param ) ) {
                continue;
            }
            $param = trim( (string) $param );
            if ( '' === $param || ! preg_match( '/^[A-Za-z0-9_\-]{1,40}$/', $param ) ) {
                continue;
            }
            if ( ! in_array( $param, $output['nocache_params'], true ) && count( $output['nocache_params'] ) < self::BLOCKER_MAX_NOCACHE_PARAMS ) {
                $output['nocache_params'][] = $param;
            }
        }

        // Admin-entered inline marker for an unknown inline script (§3.5).
        if ( isset( $input['new_inline'] ) && is_array( $input['new_inline'] ) ) {
            $marker = isset( $input['new_inline']['marker'] ) && is_scalar( $input['new_inline']['marker'] ) ? trim( (string) $input['new_inline']['marker'] ) : '';
            if ( '' !== $marker ) {
                if ( preg_match( self::BLOCKER_MARKER_PATTERN, $marker ) ) {
                    $new_rule            = $input['new_inline'];
                    $new_rule['block']   = true;
                    $output['rules'][ 'inline:' . $marker ] = self::sanitize_blocker_rule( $new_rule, $catalog, $custom_keys, $renamed );
                } else {
                    add_settings_error(
                        'trackwp_blocker',
                        'blocker_marker',
                        __( 'Inline-markøren skal være 8-64 tegn og må kun indeholde bogstaver (a-z), tal, punktum, bindestreg og understreg.', 'trackwp' ),
                        'error'
                    );
                }
            }
        }

        // KC5.3: manual server-cookie rule ("Tilføj server-cookie").
        if ( isset( $input['new_cookie'] ) && is_array( $input['new_cookie'] ) ) {
            $cookie_name = isset( $input['new_cookie']['name'] ) && is_scalar( $input['new_cookie']['name'] ) ? trim( (string) $input['new_cookie']['name'] ) : '';
            if ( '' !== $cookie_name ) {
                $cookie_rule_id = self::sanitize_blocker_rule_id( 'cookie:' . $cookie_name );
                if ( '' === $cookie_rule_id ) {
                    add_settings_error(
                        'trackwp_blocker',
                        'blocker_cookie_name',
                        __( 'Cookienavnet til "Tilføj server-cookie" er ugyldigt. Brug 2-128 tegn (bogstaver, tal, punktum, bindestreg, understreg), evt. med * til sidst for et præfiks.', 'trackwp' ),
                        'error'
                    );
                } elseif ( self::blocker_rule_hits_never_cookie( substr( $cookie_rule_id, 7 ) ) ) {
                    add_settings_error(
                        'trackwp_blocker',
                        'blocker_cookie_never',
                        __( 'Cookienavnet til "Tilføj server-cookie" rammer en cookie, der aldrig må fjernes (fx login, WooCommerce-session eller selve samtykket), og blev ikke gemt.', 'trackwp' ),
                        'warning'
                    );
                } else {
                    $new_cookie_rule          = $input['new_cookie'];
                    $new_cookie_rule['block'] = true;
                    $output['rules'][ $cookie_rule_id ] = self::sanitize_blocker_rule( $new_cookie_rule, $catalog, $custom_keys, $renamed );
                }
            }
        }

        // Exceptions.
        $exceptions = isset( $input['exceptions'] ) && is_array( $input['exceptions'] ) ? $input['exceptions'] : array();
        $allow_raw  = isset( $exceptions['allow'] ) ? $exceptions['allow'] : array();
        $rejected   = 0;
        foreach ( (array) $allow_raw as $entry ) {
            if ( is_array( $entry ) ) {
                $type  = isset( $entry['type'] ) && is_scalar( $entry['type'] ) ? sanitize_key( (string) $entry['type'] ) : '';
                $value = isset( $entry['value'] ) && is_scalar( $entry['value'] ) ? trim( (string) $entry['value'] ) : '';
                if ( '' === $value ) {
                    continue; // An empty row in the form means "no entry".
                }
                $rule_id = self::compose_blocker_rule_id( $type, $value );
            } else {
                $rule_id = self::sanitize_blocker_rule_id( $entry );
            }
            if ( '' === $rule_id ) {
                $rejected++;
                continue;
            }
            if ( ! in_array( $rule_id, $output['exceptions']['allow'], true )
                && count( $output['exceptions']['allow'] ) < self::BLOCKER_MAX_EXCEPTIONS ) {
                $output['exceptions']['allow'][] = $rule_id;
            }
        }
        if ( $rejected > 0 ) {
            add_settings_error(
                'trackwp_blocker',
                'blocker_allow',
                __( 'En eller flere undtagelser under "Tillad altid" var ugyldige og blev ikke gemt.', 'trackwp' ),
                'warning'
            );
        }

        $output['exceptions']['paths'] = self::sanitize_blocker_paths(
            isset( $exceptions['paths'] ) ? $exceptions['paths'] : array(),
            self::BLOCKER_MAX_EXCEPTIONS
        );
        $output['extra_paths'] = self::sanitize_blocker_paths(
            isset( $input['extra_paths'] ) ? $input['extra_paths'] : array(),
            self::BLOCKER_MAX_EXTRA_PATHS
        );

        return $output;
    }

    /**
     * One rule: {block, category, vendor}.
     *
     * @param array $rule       Raw rule.
     * @param array $catalog    TrackWP_Consent_Profile::vendor_catalog().
     * @param array $custom_keys Valid custom vendor keys (key => true).
     * @param array $renamed     Old custom vendor key => new key (rename).
     * @return array
     */
    private static function sanitize_blocker_rule( $rule, $catalog, $custom_keys, $renamed ) {
        $vendor = isset( $rule['vendor'] ) && is_scalar( $rule['vendor'] ) ? sanitize_key( (string) $rule['vendor'] ) : '';
        if ( isset( $renamed[ $vendor ] ) && ! isset( $custom_keys[ $vendor ] ) ) {
            $vendor = $renamed[ $vendor ];
        }
        if ( ! isset( $catalog[ $vendor ] ) && ! isset( $custom_keys[ $vendor ] ) ) {
            $vendor = '';
        }
        $category = isset( $rule['category'] ) && is_scalar( $rule['category'] ) ? sanitize_key( (string) $rule['category'] ) : '';
        if ( '' !== $category && ! in_array( $category, self::BLOCKER_CATEGORIES, true ) ) {
            $category = '';
        }
        if ( '' === $category ) {
            // KC13/F2: fall back to the catalog vendor's category when known,
            // otherwise "" ("Uafklaret") — never a guessed "marketing".
            $category = ( '' !== $vendor && isset( $catalog[ $vendor ]['category'] ) && in_array( $catalog[ $vendor ]['category'], self::BLOCKER_CATEGORIES, true ) )
                ? $catalog[ $vendor ]['category']
                : '';
        }
        return array(
            // Necessary is always granted, so blocking it would be a no-op at
            // best and a broken page at worst. An unresolved ("") category
            // cannot be blocked either — the admin must pick one first.
            'block'    => ! empty( $rule['block'] ) && '' !== $category && 'necessary' !== $category,
            'category' => $category,
            'vendor'   => $vendor,
        );
    }

    /**
     * KC2/D6: does a cookie: rule pattern overlap with a never-touch cookie
     * name from TrackWP_Cookie_Gate::NEVER (plus the trackwp_cookie_gate_never
     * filter, W1)? Matching is prefix-based, matching the wildcard semantics
     * both lists use ("name*"): two patterns overlap when the literal prefix
     * of one is a prefix of the other.
     *
     * @param string $pattern The value after "cookie:" (may end in "*").
     * @return bool
     */
    private static function blocker_rule_hits_never_cookie( $pattern ) {
        if ( ! class_exists( 'TrackWP_Cookie_Gate' ) || ! defined( 'TrackWP_Cookie_Gate::NEVER' ) ) {
            return false;
        }
        $never = constant( 'TrackWP_Cookie_Gate::NEVER' );
        if ( ! is_array( $never ) ) {
            return false;
        }
        /** This filter is documented in class-trackwp-cookie-gate.php (KC3). */
        $never = apply_filters( 'trackwp_cookie_gate_never', $never );
        foreach ( (array) $never as $never_pattern ) {
            if ( is_scalar( $never_pattern ) && self::cookie_patterns_overlap( $pattern, (string) $never_pattern ) ) {
                return true;
            }
        }
        return false;
    }

    /**
     * @param string $a
     * @param string $b
     * @return bool
     */
    private static function cookie_patterns_overlap( $a, $b ) {
        $a_prefix = rtrim( (string) $a, '*' );
        $b_prefix = rtrim( (string) $b, '*' );
        if ( '' === $a_prefix || '' === $b_prefix ) {
            return true;
        }
        return 0 === strpos( $a_prefix, $b_prefix ) || 0 === strpos( $b_prefix, $a_prefix );
    }

    /**
     * Validate a full KB2 rule id. Returns '' when invalid.
     *
     * @param mixed $rule_id
     * @return string
     */
    public static function sanitize_blocker_rule_id( $rule_id ) {
        if ( ! is_string( $rule_id ) ) {
            return '';
        }
        $rule_id = trim( $rule_id );
        if ( ! TrackWP_Blocker_Rules::is_valid_rule_id( $rule_id ) ) {
            return '';
        }
        if ( 0 === strpos( $rule_id, 'inline:' ) && ! preg_match( self::BLOCKER_MARKER_PATTERN, substr( $rule_id, 7 ) ) ) {
            return '';
        }
        return $rule_id;
    }

    /**
     * S16: build a prefixed rule id from the admin's type + value. The value
     * may be pasted as a URL; scheme, query and fragment are dropped and the
     * host is lower-cased (KB6), the path keeps its case.
     *
     * @param string $type  handle|url|host|inline|pixel
     * @param string $value Raw value.
     * @return string Rule id, or '' when invalid.
     */
    public static function compose_blocker_rule_id( $type, $value ) {
        if ( ! in_array( $type, self::BLOCKER_RULE_TYPES, true ) ) {
            return '';
        }
        $value = trim( (string) $value );
        // Already prefixed with the same type: accept as-is.
        if ( 0 === strpos( $value, $type . ':' ) ) {
            $value = substr( $value, strlen( $type ) + 1 );
        }
        if ( in_array( $type, array( 'url', 'host', 'pixel' ), true ) ) {
            $value = preg_replace( '#^https?:#i', '', $value );
            $value = ltrim( $value, '/' );
            $value = preg_replace( '/[?#].*$/', '', $value );
            $slash = strpos( $value, '/' );
            $host  = false === $slash ? $value : substr( $value, 0, $slash );
            $path  = false === $slash ? '' : substr( $value, $slash );
            $host  = strtolower( preg_replace( '/:(80|443)$/', '', $host ) );
            $value = 'host' === $type ? $host : $host . $path;
        }
        return self::sanitize_blocker_rule_id( $type . ':' . $value );
    }

    /**
     * Path list (textarea string or array). Only site paths starting with a
     * single "/", no scheme, no "..", query and fragment stripped.
     *
     * @param mixed $raw
     * @param int   $max
     * @return string[]
     */
    public static function sanitize_blocker_paths( $raw, $max ) {
        if ( is_string( $raw ) ) {
            $raw = preg_split( '/\r\n|\r|\n/', $raw );
        }
        $out = array();
        foreach ( (array) $raw as $path ) {
            if ( ! is_string( $path ) ) {
                continue;
            }
            $path = trim( preg_replace( '/[?#].*$/', '', trim( $path ) ) );
            if ( '' === $path ) {
                continue;
            }
            if ( '/' !== $path[0] || 0 === strpos( $path, '//' )
                || preg_match( '#[\s<>"\'\\\\]#', $path )
                || preg_match( '#(^|/)\.\.(/|$)#', $path ) ) {
                add_settings_error(
                    'trackwp_blocker',
                    'blocker_path',
                    sprintf(
                        /* translators: %s: the rejected value */
                        __( '"%s" er ikke en gyldig sti. Angiv kun stier på dette site, der starter med /, fx /kontakt/.', 'trackwp' ),
                        $path
                    ),
                    'warning'
                );
                continue;
            }
            if ( ! in_array( $path, $out, true ) ) {
                $out[] = $path;
            }
            if ( count( $out ) >= $max ) {
                break;
            }
        }
        return $out;
    }

    /**
     * "Bed om nyt samtykke" (§3.5): +1 on consent_version, new material_hash
     * baseline, purge known page caches.
     *
     * @return int The new consent version.
     */
    public static function bump_consent_version() {
        $consent = get_option( 'trackwp_consent', array() );
        $consent = is_array( $consent ) ? $consent : array();
        $next    = max( 1, isset( $consent['consent_version'] ) ? absint( $consent['consent_version'] ) : 1 ) + 1;

        $consent['consent_version'] = $next;
        self::$bump_consent_to      = $next;
        try {
            update_option( 'trackwp_consent', $consent );
        } finally {
            self::$bump_consent_to = null;
        }

        if ( class_exists( 'TrackWP_Consent_Profile' ) && method_exists( 'TrackWP_Consent_Profile', 'material_hash' ) ) {
            update_option( 'trackwp_consent_material_hash', TrackWP_Consent_Profile::material_hash(), false );
        }
        if ( class_exists( 'TrackWP' ) && method_exists( 'TrackWP', 'instance' ) && method_exists( 'TrackWP', 'purge_known_page_caches' ) ) {
            TrackWP::instance()->purge_known_page_caches();
        }
        return $next;
    }

    /**
     * admin_post_trackwp_bump_consent. Requires manage_options and the
     * trackwp_bump_consent nonce.
     */
    public static function handle_bump_consent() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'Adgang nægtet.', 'trackwp' ), '', array( 'response' => 403 ) );
        }
        check_admin_referer( 'trackwp_bump_consent' );
        $version = self::bump_consent_version();
        wp_safe_redirect( admin_url( 'admin.php?page=trackwp&trackwp_bumped=' . $version . '#blocker' ) );
        exit;
    }

    // =========================================================================
    // Admin notices (1.10.1)
    // =========================================================================

    /**
     * Render all 1.10.1 compliance/config admin notices. Hooked to
     * admin_notices from register_settings() (see there for why the timing
     * is safe). Restricted to the TrackWP settings screen so the plugin does
     * not clutter unrelated admin pages.
     */
    public function render_admin_notices() {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }
        $screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
        if ( ! $screen || strpos( (string) $screen->id, 'trackwp' ) === false ) {
            return;
        }

        $platforms = get_option( 'trackwp_platforms', array() );
        $advanced  = get_option( 'trackwp_advanced', array() );
        $consent   = get_option( 'trackwp_consent', array() );

        // Ack link for the "platforms changed" notice below.
        if ( isset( $_GET['trackwp_ack_platform_change'] ) && check_admin_referer( 'trackwp_ack_platform_change' ) ) {
            if ( class_exists( 'TrackWP_Consent_Profile' ) && method_exists( 'TrackWP_Consent_Profile', 'material_hash' ) ) {
                update_option( 'trackwp_consent_material_hash', TrackWP_Consent_Profile::material_hash(), false );
            }
        }

        self::notice_platforms_changed();
        self::notice_missing_controller_name( $consent );
        self::notice_gtm_missing_vendors( $platforms, $consent );
        self::notice_multi_label_tld( $advanced );
        self::notice_missing_trigger( $consent );
        self::notice_missing_ads_label( $platforms );
        self::notice_ga4_double_counting( $platforms );
        self::notice_missing_tables( $consent );

        // 1.11.0: blocking and Meta takeover.
        self::notice_consent_bumped();
        self::notice_blocker( $consent );
        self::notice_meta_takeover( $platforms );
    }

    /**
     * Confirmation after "Bed om nyt samtykke", with the CDN reminder that
     * purge_known_page_caches() cannot cover.
     */
    private static function notice_consent_bumped() {
        if ( empty( $_GET['trackwp_bumped'] ) ) {
            return;
        }
        self::render_notice(
            'success',
            esc_html(
                sprintf(
                    /* translators: %d: new consent version */
                    __( 'Samtykkeversionen er nu %d. Alle besøgende bliver bedt om samtykke igen. Kendte cache-plugins er tømt. Tøm også cachen i dit CDN eller hos dit webhotel.', 'trackwp' ),
                    absint( $_GET['trackwp_bumped'] )
                )
            )
        );
    }

    /**
     * Blocking notices (§3.5, KB14). Only when the mode is not off.
     *
     * @param array $consent trackwp_consent.
     */
    private static function notice_blocker( $consent ) {
        $blocker = self::get_blocker_config();
        if ( 'off' === $blocker['mode'] ) {
            return;
        }

        // KB14 operating status.
        $status = get_option( 'trackwp_blocker_status', array() );
        if ( is_array( $status ) && ! empty( $status['last_error']['code'] ) ) {
            $blk_error_code  = sanitize_key( (string) $status['last_error']['code'] );
            $ts              = isset( $status['last_error']['ts'] ) ? (int) $status['last_error']['ts'] : 0;
            $blk_error_date  = $ts ? wp_date( 'Y-m-d H:i', $ts ) : '-';
            $blk_gate_texts  = self::cookie_gate_status_texts();

            if ( isset( $blk_gate_texts[ $blk_error_code ] ) ) {
                // KC3: these codes come from TrackWP_Cookie_Gate — they are NOT
                // an HTML-rewriting failure, so they get their own honest text
                // instead of the blocker's "omskrivningen af siden fejlede".
                self::render_notice(
                    'warning',
                    esc_html(
                        sprintf(
                            /* translators: 1: message, 2: date/time */
                            __( '%1$s (%2$s)', 'trackwp' ),
                            $blk_gate_texts[ $blk_error_code ],
                            $blk_error_date
                        )
                    )
                );
            } else {
                self::render_notice(
                    'error',
                    esc_html(
                        sprintf(
                            /* translators: 1: error code, 2: date/time */
                            __( 'Blokering: omskrivningen af siden fejlede (%1$s, %2$s). Siden blev vist uden blokering. Se fanen Blokering.', 'trackwp' ),
                            $blk_error_code,
                            $blk_error_date
                        )
                    )
                );
            }
        }

        // KC12/F1: named lists instead of a generic "some trackers" message.
        if ( class_exists( 'TrackWP_Consent_Profile' ) && method_exists( 'TrackWP_Consent_Profile', 'blocker_warning_vendors' ) ) {
            $warning_vendors = (array) TrackWP_Consent_Profile::blocker_warning_vendors();
            $not_selected    = isset( $warning_vendors['not_selected'] ) ? array_values( array_map( 'strval', (array) $warning_vendors['not_selected'] ) ) : array();
            $server_side     = isset( $warning_vendors['server_side'] ) ? array_values( array_map( 'strval', (array) $warning_vendors['server_side'] ) ) : array();

            if ( ! empty( $not_selected ) ) {
                self::render_notice(
                    'warning',
                    esc_html(
                        sprintf(
                            /* translators: %s: comma separated vendor names */
                            __( 'Blokering: disse fundne trackere blokeres ikke: %s. Vælg »Bloker indtil samtykke« på fanen Blokering.', 'trackwp' ),
                            implode( ', ', $not_selected )
                        )
                    )
                );
            }
            if ( ! empty( $server_side ) ) {
                self::render_notice(
                    'warning',
                    esc_html(
                        sprintf(
                            /* translators: %s: comma separated vendor names */
                            __( 'Kan ikke blokeres i browseren (server-til-server): %s.', 'trackwp' ),
                            implode( ', ', $server_side )
                        )
                    )
                );
            }
        } elseif ( class_exists( 'TrackWP_Consent_Profile' ) && method_exists( 'TrackWP_Consent_Profile', 'warnings' )
            && in_array( 'unblocked_vendors', (array) TrackWP_Consent_Profile::warnings(), true ) ) {
            self::render_notice( 'warning', esc_html__( 'Blokering: nogle fundne trackere har en vendor, men blokeres ikke. De står stadig i deklarationen. Se fanen Blokering.', 'trackwp' ) );
        }

        if ( isset( $consent['description_mode'] ) && 'custom' === $consent['description_mode'] ) {
            self::render_notice( 'warning', esc_html__( 'Blokering er slået til, men bannerteksten er i custom-tilstand. Tjek at teksten nævner de trackere, der nu blokeres indtil samtykke.', 'trackwp' ) );
        }
    }

    /**
     * §3.7 / S17: red notice when M1 is on but TrackWP cannot deliver Meta,
     * and when Graph rejected the configured token. The wording follows
     * TrackWP_Meta_Takeover::status(): fb4woo is only described as running
     * or switched off when it is actually loaded, and "forbliver slået fra"
     * is only said while the takeover is in effect (delivering). The
     * persistent "TrackWP leverer Meta" status lives on the Platforms tab.
     *
     * @param array $platforms trackwp_platforms.
     */
    private static function notice_meta_takeover( $platforms ) {
        if ( empty( $platforms['meta_pixel_with_gtm'] ) || ! class_exists( 'TrackWP_Meta_Takeover' ) ) {
            return;
        }
        foreach ( self::meta_takeover_notices( (array) TrackWP_Meta_Takeover::status() ) as $message ) {
            self::render_notice( 'error', esc_html( $message ) );
        }
    }

    /**
     * Plain-text (unescaped) notice messages for a TrackWP_Meta_Takeover::status() array.
     *
     * @param array $status status().
     * @return string[]
     */
    public static function meta_takeover_notices( $status ) {
        $messages = array();
        $missing  = isset( $status['missing'] ) ? array_values( array_diff( (array) $status['missing'], array( 'm1' ) ) ) : array();
        $fb4woo   = ! empty( $status['fb4woo_active'] );
        $labels   = self::meta_missing_labels();

        if ( ! empty( $missing ) ) {
            $names = array();
            foreach ( $missing as $code ) {
                $names[] = isset( $labels[ $code ] ) ? $labels[ $code ] : (string) $code;
            }
            $message = sprintf(
                /* translators: %s: comma separated list of missing settings */
                __( 'Meta: "TrackWP overtager Meta-sporing" er slået til, men TrackWP overtager ikke, fordi dette mangler: %s.', 'trackwp' ),
                implode( ', ', $names )
            );
            // Not true when the kill switch (fb4woo_tracking_off) is on: fb4woo
            // is not tracking either in that case (review Reviewer-deep).
            if ( $fb4woo && empty( $status['forced_off'] ) ) {
                $message .= ' ' . __( 'Meta for WooCommerce sporer derfor fortsat selv.', 'trackwp' );
            }
            $messages[] = $message;
        }

        $error = self::meta_error_text( isset( $status['last_error'] ) ? $status['last_error'] : null );
        if ( '' !== $error ) {
            $message = sprintf(
                /* translators: %s: error from Meta */
                __( "Meta afviste TrackWP's access token: %s. Indsæt et gyldigt token under Platforme.", 'trackwp' ),
                $error
            );
            if ( $fb4woo && ! empty( $status['delivering'] ) ) {
                $message .= ' ' . __( 'Sporingen i Meta for WooCommerce forbliver slået fra.', 'trackwp' );
            }
            $messages[] = $message;
        }
        return $messages;
    }

    /**
     * Labels for TrackWP_Meta_Takeover::status()['missing'] codes (KB15,
     * review M2). 'm1' never shows: the notice only runs with M1 on.
     *
     * @return array<string,string>
     */
    public static function meta_missing_labels() {
        return array(
            'meta_enabled' => __( 'Meta er ikke slået til', 'trackwp' ),
            'pixel_id'     => __( 'gyldigt Pixel ID', 'trackwp' ),
            'token'        => __( 'Access Token', 'trackwp' ),
            'client_pixel' => __( 'Klient-side Pixel', 'trackwp' ),
            'woo_events'   => __( 'WooCommerce-sporing (fanen WooCommerce)', 'trackwp' ),
            'dedup_server_only' => __( 'Dedup-tilstand "kun server" uden et Conversions API-token (Pixel ville kun sende PageView)', 'trackwp' ),
        );
    }

    /**
     * Plain-text summary of status()['last_error'], as written by
     * TrackWP_Meta::record_token_error() (keys http_code, code, message,
     * time). Not escaped.
     *
     * @param mixed $error
     * @return string
     */
    public static function meta_error_text( $error ) {
        if ( empty( $error ) || ! is_array( $error ) ) {
            return '';
        }
        $parts = array();
        if ( ! empty( $error['http_code'] ) ) {
            $parts[] = 'HTTP ' . (int) $error['http_code'];
        }
        if ( ! empty( $error['code'] ) ) {
            /* translators: %d: Graph API error code */
            $parts[] = sprintf( __( 'Graph-kode %d', 'trackwp' ), (int) $error['code'] );
        }
        if ( isset( $error['message'] ) && is_scalar( $error['message'] ) && '' !== (string) $error['message'] ) {
            $parts[] = sanitize_text_field( (string) $error['message'] );
        }
        if ( ! empty( $error['time'] ) ) {
            $parts[] = wp_date( 'Y-m-d H:i', (int) $error['time'] );
        }
        return $parts ? implode( ', ', $parts ) : __( 'ukendt fejl', 'trackwp' );
    }

    /**
     * Shared notice renderer.
     *
     * @param string $type    error|warning|success|info
     * @param string $message Already-escaped HTML (allows links).
     */
    private static function render_notice( $type, $message ) {
        echo '<div class="notice notice-' . esc_attr( $type ) . ' trackwp-admin-notice"><p>' . $message . '</p></div>';
    }

    /**
     * "Platforms changed" — K7's material_hash, admin-facing side (W6 owns
     * TrackWP_Consent_Profile::material_hash() itself). No auto-bump (§7.10):
     * this only reminds the admin to bump the consent version and purge
     * cache/CDN manually.
     *
     * The baseline option (trackwp_consent_material_hash) is set by W8's
     * 1.10.1 upgrade routine (material_hash-baseline uden bump — see
     * PLAN-1.10.1-v4.md §2.4 W8). This method never writes it except via the
     * explicit "Marker som håndteret" ack link in render_admin_notices().
     * Missing baseline (upgrade routine not run yet / class not loaded) means
     * "nothing to compare against" — the notice simply does not fire.
     */
    private static function notice_platforms_changed() {
        if ( ! class_exists( 'TrackWP_Consent_Profile' ) || ! method_exists( 'TrackWP_Consent_Profile', 'material_hash' ) ) {
            return;
        }
        $current_hash = (string) TrackWP_Consent_Profile::material_hash();
        $stored_hash  = get_option( 'trackwp_consent_material_hash', '' );

        if ( $stored_hash === '' || $stored_hash === $current_hash ) {
            return;
        }

        $ack_url = wp_nonce_url(
            add_query_arg( 'trackwp_ack_platform_change', '1', admin_url( 'admin.php?page=trackwp#consent' ) ),
            'trackwp_ack_platform_change'
        );
        self::render_notice(
            'warning',
            sprintf(
                /* translators: %s: link to acknowledge the change */
                esc_html__( 'De platforme/vendors der er dækket af samtykke-banneret er ændret siden sidst. Overvej at forny samtykket (bump version under fanen Samtykke) og tøm cache/CDN, så besøgende ser den opdaterede erklæring. %s', 'trackwp' ),
                '<a href="' . esc_url( $ack_url ) . '">' . esc_html__( 'Marker som håndteret', 'trackwp' ) . '</a>'
            )
        );
    }

    private static function notice_missing_controller_name( $consent ) {
        if ( empty( $consent['controller_name'] ) ) {
            self::render_notice( 'warning', esc_html__( 'Den dataansvarlige (controller_name) er ikke udfyldt under Samtykke. Samtykke-banneret skal navngive sitets ejer, aldrig TrackWP selv.', 'trackwp' ) );
        }
    }

    private static function notice_gtm_missing_vendors( $platforms, $consent ) {
        if ( empty( $platforms['gtm_enabled'] ) ) {
            return;
        }
        $vendors = isset( $consent['gtm_vendors'] ) ? $consent['gtm_vendors'] : array();
        $has_known  = ! empty( $vendors['known'] ) && is_array( $vendors['known'] );
        $has_custom = ! empty( $vendors['custom'] ) && is_array( $vendors['custom'] );
        if ( ! $has_known && ! $has_custom ) {
            self::render_notice( 'warning', esc_html__( 'Google Tag Manager er aktiv, men ingen vendors er angivet under Samtykke. TrackWP kan ikke se hvad der ligger i din GTM-container, så deklarationen og samtykke-kravene bliver ufuldstændige, indtil du vælger eller tilføjer dem manuelt.', 'trackwp' ) );
        }
    }

    private static function notice_multi_label_tld( $advanced ) {
        // Single source of truth for the multi-label-suffix check and the
        // cookie_domain-already-set short-circuit: TrackWP_Cookies (W1).
        if ( ! class_exists( 'TrackWP_Cookies' ) || ! method_exists( 'TrackWP_Cookies', 'needs_explicit_domain' ) ) {
            return;
        }
        if ( ! TrackWP_Cookies::needs_explicit_domain() ) {
            return;
        }
        $host = wp_parse_url( home_url(), PHP_URL_HOST );
        self::render_notice(
            'warning',
            sprintf(
                /* translators: %s: detected host */
                esc_html__( 'Dit domæne (%s) bruger en flerdelt endelse (fx co.uk). Uden et eksplicit cookie-domæne under Avanceret kan cookien blive sat forkert (fx til hele "co.uk"). Angiv domænet manuelt.', 'trackwp' ),
                esc_html( (string) $host )
            )
        );
    }

    /**
     * Cached (1 hour) check for a page/post containing the consent trigger
     * shortcode, so this notice does not run a LIKE-scan of wp_posts on
     * every admin page load.
     */
    private static function has_consent_trigger_shortcode() {
        $cached = get_transient( 'trackwp_has_trigger_shortcode' );
        if ( false !== $cached ) {
            return (bool) $cached;
        }
        global $wpdb;
        $found = (bool) $wpdb->get_var(
            "SELECT 1 FROM {$wpdb->posts} WHERE post_status = 'publish' AND post_content LIKE '%[trackwp_consent_link%' LIMIT 1"
        );
        set_transient( 'trackwp_has_trigger_shortcode', $found ? 1 : 0, HOUR_IN_SECONDS );
        return $found;
    }

    private static function notice_missing_trigger( $consent ) {
        // Nothing to reconsider from if the site never shows a dialog at all.
        if ( empty( $consent ) ) {
            return;
        }
        $filter_allows_trigger = apply_filters( 'trackwp_show_consent_trigger', true );
        if ( $filter_allows_trigger ) {
            return;
        }
        if ( self::has_consent_trigger_shortcode() ) {
            return;
        }
        self::render_notice(
            'warning',
            esc_html__( 'Filteret trackwp_show_consent_trigger returnerer false, og der findes ingen [trackwp_consent_link]-shortcode på nogen udgivet side. Besøgende kan derfor ikke genåbne samtykke-dialogen for at trække samtykket tilbage eller ændre det.', 'trackwp' )
        );
    }

    private static function notice_missing_ads_label( $platforms ) {
        if ( empty( $platforms['google_ads_enabled'] ) ) {
            return;
        }
        $events = self::get_events_config();
        foreach ( $events as $event ) {
            if ( ! is_array( $event ) || empty( $event['enabled'] ) ) {
                continue;
            }
            $routed_to_ads = ! empty( $event['send_to']['google_ads'] );
            if ( $routed_to_ads && empty( $event['ads_label'] ) ) {
                self::render_notice(
                    'warning',
                    sprintf(
                        /* translators: %s: event name */
                        esc_html__( 'Mangler Ads-label: begivenheden "%s" er sendt til Google Ads, men har ingen Google Ads Label. Konverteringen kan ikke matches til en konverteringshandling uden den.', 'trackwp' ),
                        esc_html( $event['name'] )
                    )
                );
                return; // One notice is enough — the Events tab lists all rows.
            }
        }
    }

    private static function notice_ga4_double_counting( $platforms ) {
        if ( empty( $platforms['ga4_imported_to_ads'] ) ) {
            return;
        }
        if ( empty( $platforms['google_ads_enabled'] ) || empty( $platforms['google_ads_conversion_action_id'] ) ) {
            return;
        }
        self::render_notice(
            'warning',
            esc_html__( 'GA4-import til Google Ads er slået til, OG der er konfigureret en direkte Google Ads-konverteringshandling. Bruges begge til det samme mål, tælles konverteringen dobbelt. Brug kun én kilde pr. mål.', 'trackwp' )
        );
    }

    private static function notice_missing_tables( $consent ) {
        global $wpdb;
        $checks = array();
        if ( ! empty( $consent['log_consent'] ) ) {
            $checks['trackwp_consent_log'] = __( 'Samtykkelog', 'trackwp' );
        }
        if ( class_exists( 'WooCommerce' ) ) {
            $woo = get_option( 'trackwp_woocommerce', array() );
            if ( ! empty( $woo['enabled'] ) ) {
                $checks['trackwp_order_claims'] = __( 'Ordreclaims (køb)', 'trackwp' );
            }
        }
        $advanced = get_option( 'trackwp_advanced', array() );
        if ( ! empty( $advanced['delivery_log_enabled'] ) ) {
            $checks['trackwp_delivery_log'] = __( 'Leveringslog', 'trackwp' );
        }
        foreach ( $checks as $table => $label ) {
            $full_name = $wpdb->prefix . $table;
            $exists    = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $full_name ) );
            if ( ! $exists ) {
                self::render_notice(
                    'error',
                    sprintf(
                        /* translators: 1: feature label, 2: table name */
                        esc_html__( 'Tabel kunne ikke oprettes: "%1$s" mangler sin tabel (%2$s). Deaktivér og genaktivér pluginet, eller kontakt din hosting-udbyder — funktionen virker ikke uden tabellen.', 'trackwp' ),
                        esc_html( $label ),
                        esc_html( $full_name )
                    )
                );
            }
        }
    }

    // =========================================================================
    // Consent log (Consent tab) — reads via TrackWP_Consent_Log (W1)
    // =========================================================================

    /**
     * CSV export of the consent log, streamed in chunks so large logs do not
     * exhaust memory. admin_post handler: nonce + manage_options, registered
     * in register_settings().
     */
    /**
     * Neutralise CSV/formula injection (CWE-1236): a cell value opened in
     * Excel/Sheets/LibreOffice that starts with =, +, -, @, a tab, or a CR
     * can execute as a formula. Every logged value here ultimately comes
     * from request input (e.g. user_agent), so it is untrusted. Prefixing
     * with a single quote forces spreadsheet apps to treat it as text
     * without changing the value for any other CSV consumer.
     *
     * @param mixed $value Raw cell value.
     * @return string
     */
    private static function csv_safe_cell( $value ) {
        $value = (string) $value;
        if ( $value !== '' && strpbrk( $value[0], "=+-@\t\r" ) !== false ) {
            return "'" . $value;
        }
        return $value;
    }

    public static function handle_consent_export() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'Adgang nægtet.', 'trackwp' ) );
        }
        check_admin_referer( 'trackwp_consent_export' );

        if ( ! class_exists( 'TrackWP_Consent_Log' ) || ! method_exists( 'TrackWP_Consent_Log', 'get_rows' ) ) {
            wp_die( esc_html__( 'Samtykkeloggen er ikke tilgængelig endnu.', 'trackwp' ) );
        }

        nocache_headers();
        header( 'Content-Type: text/csv; charset=utf-8' );
        header( 'Content-Disposition: attachment; filename="trackwp-consent-log-' . gmdate( 'Y-m-d' ) . '.csv"' );

        // Column order matches the trackwp_consent_log table exactly
        // (includes/class-trackwp-consent-log.php create_tables()), minus the
        // internal migration_key.
        $columns = array(
            'id', 'consent_id', 'created_at', 'event_type', 'statistics', 'marketing',
            'personalisation', 'consent_version', 'server_consent_version', 'banner_hash',
            'ip_hash', 'user_agent', 'page_url', 'source',
        );

        $out = fopen( 'php://output', 'w' );
        fputcsv( $out, $columns );

        $offset = 0;
        $limit  = 500;
        do {
            $rows = (array) TrackWP_Consent_Log::get_rows( $offset, $limit );
            foreach ( $rows as $row ) {
                $row  = (array) $row;
                $line = array();
                foreach ( $columns as $col ) {
                    $line[] = self::csv_safe_cell( isset( $row[ $col ] ) ? $row[ $col ] : '' );
                }
                fputcsv( $out, $line );
            }
            $count = count( $rows );
            $offset += $limit;
            // Flush each chunk so a large export streams instead of buffering fully in memory.
            flush();
        } while ( $count === $limit );

        fclose( $out );
        exit;
    }

    /**
     * Look up the full history for one consent_id, for the Consent tab's
     * lookup form. TrackWP_Consent_Log::find_by_consent_id() returns every
     * logged action for that ID (set/update/withdraw), oldest first — not a
     * single row.
     *
     * @param string $consent_id
     * @return array[]|null Array of row-arrays, or null if not found/unavailable.
     */
    public static function lookup_consent_id( $consent_id ) {
        $consent_id = sanitize_text_field( $consent_id );
        if ( $consent_id === '' || ! class_exists( 'TrackWP_Consent_Log' ) || ! method_exists( 'TrackWP_Consent_Log', 'find_by_consent_id' ) ) {
            return null;
        }
        $rows = TrackWP_Consent_Log::find_by_consent_id( $consent_id );
        return $rows ? (array) $rows : null;
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
