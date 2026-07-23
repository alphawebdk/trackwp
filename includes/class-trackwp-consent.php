<?php
defined('ABSPATH') || exit;

class TrackWP_Consent {

    public function __construct() {
        add_action('wp_footer', array($this, 'render_banner'));
        add_action('wp_footer', array($this, 'render_consent_trigger'));
        add_action('rest_api_init', array($this, 'register_consent_log_route'));
        add_action('rest_api_init', array($this, 'register_consent_withdraw_route'));
        add_shortcode('trackwp_consent_link', array($this, 'shortcode_consent_link'));
    }

    /**
     * Render a floating "Cookie-indstillinger" trigger button in the footer.
     *
     * Site-owners can hide it via the `trackwp_show_consent_trigger` filter.
     */
    public function render_consent_trigger() {
        if (is_admin()) return;
        if (!apply_filters('trackwp_show_consent_trigger', true)) return;
        echo '<button type="button" class="trackwp-consent-trigger" aria-label="' . esc_attr__('Cookie-indstillinger', 'trackwp') . '">';
        echo '<svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true" width="24" height="24">';
        echo '<path d="M12 2a10 10 0 1 0 10 10v-.5a2.5 2.5 0 0 1-3.78-2.86 2.5 2.5 0 0 1-2.86-3.78A2.5 2.5 0 0 1 11.5 2H12zM7.5 8a1 1 0 1 1 0 2 1 1 0 0 1 0-2zm4 6a1 1 0 1 1 0 2 1 1 0 0 1 0-2zm5-3a1 1 0 1 1 0 2 1 1 0 0 1 0-2z"/>';
        echo '</svg>';
        echo '</button>';
    }

    /**
     * Shortcode [trackwp_consent_link] - renders a link that re-opens the consent banner.
     *
     * @param array $atts Shortcode attributes. Supports `text`.
     * @return string HTML anchor.
     */
    public function shortcode_consent_link($atts) {
        $atts = shortcode_atts(
            array('text' => __('Skift cookie-indstillinger', 'trackwp')),
            $atts,
            'trackwp_consent_link'
        );
        return '<a href="#trackwp-consent" class="trackwp-consent-open">' . esc_html($atts['text']) . '</a>';
    }

    public function render_banner() {
        if (is_admin()) return;
        $config = get_option('trackwp_consent', array());
        $privacy_url = '';
        if (!empty($config['privacy_page_id'])) {
            $privacy_url = get_permalink(absint($config['privacy_page_id']));
        }
        include TRACKWP_PLUGIN_DIR . 'templates/consent-banner.php';
    }

    /**
     * Permission check for the consent endpoints: origin + rate limit.
     * Same pattern as TrackWP_Proxy::check_permission — Origin or Referer must
     * match the home host, and requests are limited to 5 per 2-second fixed
     * window per IP (window bucket in the key so the TTL is never extended).
     * No nonce: cached pages serve stale nonces.
     */
    public function check_consent_permission($request) {
        // Origin check — only allow from own domain
        $origin  = isset($_SERVER['HTTP_ORIGIN']) ? $_SERVER['HTTP_ORIGIN'] : '';
        $referer = isset($_SERVER['HTTP_REFERER']) ? $_SERVER['HTTP_REFERER'] : '';
        $home_host = wp_parse_url(home_url(), PHP_URL_HOST);

        $origin_ok = false;
        if ($origin && wp_parse_url($origin, PHP_URL_HOST) === $home_host) {
            $origin_ok = true;
        }
        if (!$origin_ok && $referer && wp_parse_url($referer, PHP_URL_HOST) === $home_host) {
            $origin_ok = true;
        }
        if (!$origin_ok) {
            return new WP_Error('rest_forbidden', __('Cross-origin-forespørgsel afvist.', 'trackwp'), array('status' => 403));
        }

        // Rate limiting: 5 requests per 2-second fixed window per IP.
        $ip = isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : '';
        $bucket = (int) floor(time() / 2);
        $ip_key = 'trackwp_cnsrate_' . md5($ip) . '_' . $bucket;
        $count = (int) get_transient($ip_key);
        if ($count >= 5) {
            return new WP_Error('rate_limited', __('For mange forespørgsler.', 'trackwp'), array('status' => 429));
        }
        set_transient($ip_key, $count + 1, 5);

        return true;
    }

    public function register_consent_log_route() {
        register_rest_route('trackwp/v1', '/consent-log', array(
            'methods'             => 'POST',
            'callback'            => array($this, 'handle_consent_log'),
            'permission_callback' => array($this, 'check_consent_permission'),
            'args'                => array(
                'statistics'      => array('type' => 'boolean', 'required' => true),
                'marketing'       => array('type' => 'boolean', 'required' => true),
                'personalisation' => array('type' => 'boolean', 'required' => false, 'default' => false),
            ),
        ));
    }

    public function handle_consent_log($request) {
        $config = get_option('trackwp_consent', array());

        $statistics      = (bool) $request->get_param('statistics');
        $marketing       = (bool) $request->get_param('marketing');
        $personalisation = (bool) $request->get_param('personalisation');

        // Stats: record consent acceptance/rejection regardless of log_consent flag.
        $accepted = $statistics || $marketing || $personalisation;
        TrackWP_Settings::record_stat( $accepted ? 'consent_accept' : 'consent_reject' );

        if (empty($config['log_consent'])) {
            return new WP_REST_Response(array('status' => 'logging_disabled'), 200);
        }

        $this->append_consent_log_entry(
            array(
                'statistics'      => $statistics,
                'marketing'       => $marketing,
                'personalisation' => $personalisation,
            ),
            'set'
        );

        return new WP_REST_Response(array('status' => 'ok'), 200);
    }

    /**
     * Register REST route for consent withdrawal.
     *
     * DELETE /wp-json/trackwp/v1/consent
     * - Clears consent cookie (sets all categories false).
     * - Logs withdrawal event (when log_consent enabled).
     * - Returns 200.
     */
    public function register_consent_withdraw_route() {
        register_rest_route('trackwp/v1', '/consent', array(
            'methods'             => 'DELETE',
            'callback'            => array($this, 'handle_consent_withdraw'),
            'permission_callback' => array($this, 'check_consent_permission'),
        ));
    }

    public function handle_consent_withdraw($request) {
        $config = get_option('trackwp_consent', array());

        // Expire the consent cookie (client-side cookie set by banner JS).
        setcookie(
            'trackwp_consent',
            '',
            array(
                'expires'  => time() - 3600,
                'path'     => '/',
                'secure'   => is_ssl(),
                'httponly' => false,
                'samesite' => 'Lax',
            )
        );
        unset($_COOKIE['trackwp_consent']);

        // Also expire first-party tracking cookie if present.
        $advanced = get_option('trackwp_advanced', array());
        $fp_cookie_name = !empty($advanced['cookie_name']) ? $advanced['cookie_name'] : '_twp_cid';
        if (isset($_COOKIE[ $fp_cookie_name ])) {
            setcookie(
                $fp_cookie_name,
                '',
                array(
                    'expires'  => time() - 3600,
                    'path'     => '/',
                    'secure'   => is_ssl(),
                    'httponly' => false,
                    'samesite' => 'Lax',
                )
            );
            unset($_COOKIE[ $fp_cookie_name ]);
        }

        // Record stat for withdrawal.
        if (class_exists('TrackWP_Settings') && method_exists('TrackWP_Settings', 'record_stat')) {
            TrackWP_Settings::record_stat('consent_reject');
        }

        if (!empty($config['log_consent'])) {
            $this->append_consent_log_entry(
                array(
                    'statistics'      => false,
                    'marketing'       => false,
                    'personalisation' => false,
                ),
                'withdraw'
            );
        }

        return new WP_REST_Response(array('status' => 'withdrawn'), 200);
    }

    /**
     * Append a single entry to the consent log option, with rotation.
     *
     * Structure of an entry:
     *  - consent_id      string  UUIDv4
     *  - timestamp       string  UTC ISO-8601 (gmdate('c'))
     *  - ip_hash         string  SHA-256 of REMOTE_ADDR + wp_salt() (no raw IP)
     *  - user_agent      string  sanitised, truncated to 500 chars
     *  - banner_version  string  filter: trackwp_consent_banner_version (default '1.0')
     *  - consent_choices string  JSON of categories (necessary/statistics/marketing)
     *  - page_url        string  esc_url_raw of same-host Referer (page where consent was given), REQUEST_URI fallback
     *  - event_type      string  'set' | 'withdraw'
     *  - consent_version int     legacy/admin-bumped version from config
     *
     * @param array  $choices    Associative array of category => bool.
     * @param string $event_type 'set' or 'withdraw'.
     */
    protected function append_consent_log_entry($choices, $event_type = 'set') {
        $config = get_option('trackwp_consent', array());

        $ip = isset($_SERVER['REMOTE_ADDR']) ? wp_unslash($_SERVER['REMOTE_ADDR']) : '';
        $ip_hash = $ip !== '' ? hash('sha256', $ip . wp_salt()) : '';

        $ua_raw = isset($_SERVER['HTTP_USER_AGENT']) ? wp_unslash($_SERVER['HTTP_USER_AGENT']) : '';
        $user_agent = sanitize_text_field($ua_raw);
        if (strlen($user_agent) > 500) {
            $user_agent = substr($user_agent, 0, 500);
        }

        // Prefer the Referer (the page where consent was given) — the REST
        // request URI (/wp-json/...) is useless as documentation. Only accept
        // a same-host referer; fall back to REQUEST_URI otherwise.
        $page_url = '';
        $referer  = isset($_SERVER['HTTP_REFERER']) ? wp_unslash($_SERVER['HTTP_REFERER']) : '';
        if ($referer !== '' && wp_parse_url($referer, PHP_URL_HOST) === wp_parse_url(home_url(), PHP_URL_HOST)) {
            $page_url = esc_url_raw($referer);
        }
        if ($page_url === '') {
            $page_url = isset($_SERVER['REQUEST_URI'])
                ? esc_url_raw(wp_unslash($_SERVER['REQUEST_URI']))
                : '';
        }

        $consent_choices = array(
            'necessary'       => true,
            'statistics'      => !empty($choices['statistics']),
            'marketing'       => !empty($choices['marketing']),
            'personalisation' => !empty($choices['personalisation']),
        );

        $banner_version = apply_filters('trackwp_consent_banner_version', '1.0');

        $entry = array(
            'consent_id'       => function_exists('wp_generate_uuid4') ? wp_generate_uuid4() : md5(uniqid('twp_', true)),
            'timestamp'        => gmdate('c'),
            'ip_hash'          => $ip_hash,
            'user_agent'       => $user_agent,
            'banner_version'   => (string) $banner_version,
            'consent_choices'  => wp_json_encode($consent_choices),
            'page_url'         => $page_url,
            'event_type'       => ($event_type === 'withdraw') ? 'withdraw' : 'set',
            'consent_version'  => isset($config['consent_version']) ? (int) $config['consent_version'] : 1,
            // Keep legacy flat fields for backward compat with any existing readers.
            'statistics'       => $consent_choices['statistics'],
            'marketing'        => $consent_choices['marketing'],
            'personalisation'  => $consent_choices['personalisation'],
        );

        $log = get_option('trackwp_consent_log', array());
        if (!is_array($log)) {
            $log = array();
        }
        $log[] = $entry;

        // Rotate to prevent option bloat (max 10000 entries).
        $max_entries = (int) apply_filters('trackwp_consent_log_max_entries', 10000);
        if ($max_entries > 0 && count($log) > $max_entries) {
            $log = array_slice($log, -$max_entries);
        }

        update_option('trackwp_consent_log', $log, false); // autoload = false
    }

    /**
     * Read current consent from cookie (server-side check).
     */
    public static function get_current_consent() {
        if (!isset($_COOKIE['trackwp_consent'])) {
            return array(
                'necessary'       => true,
                'statistics'      => false,
                'marketing'       => false,
                'personalisation' => false,
            );
        }
        $cookie = json_decode(stripslashes($_COOKIE['trackwp_consent']), true);
        if (!is_array($cookie)) {
            return array(
                'necessary'       => true,
                'statistics'      => false,
                'marketing'       => false,
                'personalisation' => false,
            );
        }

        // A version mismatch means the consent was given for an outdated
        // policy — treat as no consent (consent.js re-prompts).
        $config   = get_option('trackwp_consent', array());
        $expected = isset($config['consent_version']) ? (int) $config['consent_version'] : 1;
        if ((int) ($cookie['v'] ?? 0) !== $expected) {
            return array(
                'necessary'       => true,
                'statistics'      => false,
                'marketing'       => false,
                'personalisation' => false,
            );
        }

        return array(
            'necessary'       => true,
            'statistics'      => !empty($cookie['statistics']),
            'marketing'       => !empty($cookie['marketing']),
            'personalisation' => !empty($cookie['personalisation']),
        );
    }
}
