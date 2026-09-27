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

        // admin-post.php runs admin_init before dispatching admin_post_{action},
        // so registering here (rather than at plugin bootstrap) is in time.
        add_action( 'admin_post_trackwp_consent_export', array( __CLASS__, 'handle_consent_export' ) );
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

        return true;
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
