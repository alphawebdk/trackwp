<?php
defined('ABSPATH') || exit;

/**
 * Cookie attributes and lifetimes (contract K7, correction R17).
 *
 * Single source of truth for:
 *  - cookie lifetimes (lifetime_days()), also used by the banner declaration
 *  - the registrable domain used for domain-wide vendor cookies
 *  - writing and expiring cookies with consistent attributes
 *
 * Host-only means the `domain` key is OMITTED from the setcookie() options.
 * Passing Domain=<host> is NOT host-only: browsers then also send the cookie
 * to every subdomain (RFC 6265 §5.3 step 6), so it is never done here.
 *
 * Every cookie written through this class is also recorded in a per-request
 * list (sent_cookies()) so REST handlers and tests can inspect exactly which
 * Set-Cookie headers a request produced, including under the CLI, where
 * headers are already sent and setcookie() itself is skipped.
 */
class TrackWP_Cookies {

    /** Name of the consent cookie. */
    const CONSENT_COOKIE = 'trackwp_consent';

    /** Default first-party client-id cookie name. */
    const DEFAULT_FP_COOKIE = '_twp_cid';

    /** Chrome/RFC 6265bis upper bound on cookie lifetime, in days. */
    const MAX_LIFETIME_DAYS = 400;

    /** Vendor-documented lifetime for click/ad cookies, in days. */
    const MARKETING_LIFETIME_DAYS = 90;

    /**
     * Known multi-label public suffixes. Used only to decide whether the admin
     * should see a notice asking for an explicit cookie_domain; this is not a
     * public suffix list and does not change the heuristic.
     */
    const MULTI_LABEL_SUFFIXES = array(
        'co.uk', 'org.uk', 'ac.uk', 'gov.uk', 'me.uk', 'ltd.uk', 'plc.uk',
        'com.au', 'net.au', 'org.au', 'co.nz', 'org.nz', 'co.za', 'com.br',
        'co.jp', 'co.in', 'com.tr', 'com.mx', 'com.ar', 'com.cn', 'co.kr',
    );

    /**
     * Cookies recorded during this request.
     * Each entry: array('name' => string, 'value' => string, 'options' => array).
     *
     * @var array
     */
    private static $sent = array();

    /**
     * Configured first-party client-id cookie name.
     *
     * @return string
     */
    public static function fp_cookie_name() {
        $advanced = get_option('trackwp_advanced', array());
        $name     = !empty($advanced['cookie_name']) ? (string) $advanced['cookie_name'] : self::DEFAULT_FP_COOKIE;
        return preg_match('/^[A-Za-z0-9_\-]{1,64}$/', $name) ? $name : self::DEFAULT_FP_COOKIE;
    }

    /**
     * Lifetime in days for a cookie. The ONLY source for these values.
     *
     * - trackwp_consent: consent.cookie_lifetime_months, clamped 1-12, x 30 days
     * - _twp_cid (or the configured name): advanced.cookie_lifetime_months,
     *   clamped 1-24, x 30 days, capped at 400 days
     * - _twp_click, _fbc, _fbp, _gcl_*: 90 days
     *
     * @param string $name Cookie name.
     * @return int Days.
     */
    public static function lifetime_days($name) {
        $name = (string) $name;

        if (self::CONSENT_COOKIE === $name) {
            $consent = get_option('trackwp_consent', array());
            $months  = isset($consent['cookie_lifetime_months']) ? (int) $consent['cookie_lifetime_months'] : 12;
            $months  = max(1, min(12, $months));
            return $months * 30;
        }

        if (self::DEFAULT_FP_COOKIE === $name || self::fp_cookie_name() === $name) {
            $advanced = get_option('trackwp_advanced', array());
            $months   = isset($advanced['cookie_lifetime_months']) ? (int) $advanced['cookie_lifetime_months'] : 24;
            $months   = max(1, min(24, $months));
            return min(self::MAX_LIFETIME_DAYS, $months * 30);
        }

        return self::MARKETING_LIFETIME_DAYS;
    }

    /**
     * Hostname of home_url(), lowercased.
     *
     * @return string
     */
    public static function home_host() {
        return strtolower((string) wp_parse_url(home_url(), PHP_URL_HOST));
    }

    /**
     * Registrable-domain form ('.example.dk') for domain-wide vendor cookies.
     *
     * Order (K7):
     *  1. advanced.cookie_domain, when it is the host itself or a dot-suffix of it
     *  2. COOKIE_DOMAIN, only when it is a non-empty string (R17)
     *  3. heuristic: IPs/localhost/single label -> '' (host-only); >= 3 labels
     *     drops the first label; 2 labels uses the host as-is
     *
     * @param string|null $host Hostname; defaults to the home_url() host.
     * @return string Leading-dot domain, or '' meaning host-only.
     */
    public static function registrable_domain($host = null) {
        $host = null === $host ? self::home_host() : strtolower((string) $host);
        if ('' === $host || 'localhost' === $host || filter_var($host, FILTER_VALIDATE_IP)) {
            return '';
        }

        $advanced   = get_option('trackwp_advanced', array());
        $configured = isset($advanced['cookie_domain']) ? strtolower(trim((string) $advanced['cookie_domain'])) : '';
        $configured = ltrim($configured, '.');
        if ('' !== $configured && self::is_suffix_of_host($configured, $host)) {
            return '.' . $configured;
        }

        if (defined('COOKIE_DOMAIN') && is_string(COOKIE_DOMAIN) && '' !== trim(COOKIE_DOMAIN)) {
            return '.' . ltrim(strtolower(trim(COOKIE_DOMAIN)), '.');
        }

        $labels = explode('.', $host);
        if (count($labels) < 2) {
            return '';
        }
        if (count($labels) >= 3) {
            array_shift($labels);
        }
        return '.' . implode('.', $labels);
    }

    /**
     * Is $domain the host itself or a label-aligned suffix of it?
     *
     * @param string $domain Candidate domain without leading dot.
     * @param string $host   Hostname.
     * @return bool
     */
    public static function is_suffix_of_host($domain, $host) {
        $domain = strtolower((string) $domain);
        $host   = strtolower((string) $host);
        if ('' === $domain || false === strpos($domain, '.')) {
            return false;
        }
        if ($domain === $host) {
            return true;
        }
        $suffix = '.' . $domain;
        return strlen($host) > strlen($suffix) && substr($host, -strlen($suffix)) === $suffix;
    }

    /**
     * Does the home host end in a known multi-label public suffix while no
     * explicit cookie_domain is configured? Drives an admin notice (W7).
     *
     * @return bool
     */
    public static function needs_explicit_domain() {
        $advanced = get_option('trackwp_advanced', array());
        if (!empty($advanced['cookie_domain'])) {
            return false;
        }
        $host = self::home_host();
        foreach (self::MULTI_LABEL_SUFFIXES as $suffix) {
            if (substr($host, -strlen('.' . $suffix)) === '.' . $suffix) {
                return true;
            }
        }
        return false;
    }

    /**
     * Write a cookie.
     *
     * @param string $name    Cookie name.
     * @param string $value   Raw value (PHP url-encodes it).
     * @param int    $expires Unix timestamp; 0 for a session cookie.
     * @param string $scope   'host' (domain key omitted) or 'registrable'.
     * @return bool True when recorded (and sent, when headers allow).
     */
    public static function set($name, $value, $expires, $scope = 'host') {
        $options = array(
            'expires'  => (int) $expires,
            'path'     => '/',
            'secure'   => is_ssl(),
            'httponly' => false,
            'samesite' => 'Lax',
        );
        if ('registrable' === $scope) {
            $domain = self::registrable_domain();
            if ('' !== $domain) {
                $options['domain'] = $domain;
            }
        } elseif ('explicit_host' === $scope) {
            // Only used to EXPIRE legacy Domain=<host> cookies (K7 migration).
            $options['domain'] = self::home_host();
        }
        return self::emit((string) $name, (string) $value, $options);
    }

    /**
     * Expire a cookie in every variant this plugin (or an older version, or a
     * vendor script) may have written: host-only, Domain=<host> and the
     * registrable domain.
     *
     * @param string $name Cookie name.
     * @return void
     */
    public static function expire($name) {
        $past = time() - YEAR_IN_SECONDS;
        self::set($name, '', $past, 'host');
        self::set($name, '', $past, 'explicit_host');
        if ('' !== self::registrable_domain()) {
            self::set($name, '', $past, 'registrable');
        }
        unset($_COOKIE[ $name ]);
    }

    /**
     * Rewrite a cookie as host-only (R17 migration for _twp_cid): expire the
     * Domain=<host> and host-only variants, then write the host-only cookie.
     * Called from visitor requests (/event, /keepalive) only; never from the
     * upgrade routine.
     *
     * @param string $name    Cookie name.
     * @param string $value   Value.
     * @param int    $expires Unix timestamp.
     * @return bool
     */
    public static function rewrite_host_only($name, $value, $expires) {
        $past = time() - YEAR_IN_SECONDS;
        self::set($name, '', $past, 'explicit_host');
        self::set($name, '', $past, 'host');
        $ok = self::set($name, $value, $expires, 'host');
        $_COOKIE[ $name ] = (string) $value;
        return $ok;
    }

    /**
     * Names of tracking cookies per category that are present on this request.
     *
     * @param string $category 'analytics' or 'marketing'.
     * @return string[]
     */
    public static function tracking_cookie_names($category) {
        $present = array_keys(is_array($_COOKIE) ? $_COOKIE : array());
        $names   = array();

        if ('analytics' === $category) {
            $fixed = array('_ga', '_gid', '_gat', self::fp_cookie_name(), 'trackwp_sid', 'trackwp_ka_ts');
            foreach ($present as $cookie) {
                $cookie = (string) $cookie;
                if (in_array($cookie, $fixed, true) || 0 === strpos($cookie, '_ga_')) {
                    $names[] = $cookie;
                }
            }
        } elseif ('marketing' === $category) {
            $fixed = array('_fbp', '_fbc', '_twp_click');
            foreach ($present as $cookie) {
                $cookie = (string) $cookie;
                if (in_array($cookie, $fixed, true) || 0 === strpos($cookie, '_gcl_')) {
                    $names[] = $cookie;
                }
            }
        }

        return array_values(array_unique($names));
    }

    /**
     * Expire tracking cookies for every category that is NOT granted.
     * Only cookies the browser actually presented are touched (the server
     * cannot see others), in all domain variants.
     *
     * @param array $consent Keys 'analytics' (or 'statistics') and 'marketing';
     *                       a category is expired unless its value is true.
     * @return string[] Names of the cookies that were expired.
     */
    public static function expire_tracking_cookies($consent) {
        $consent   = is_array($consent) ? $consent : array();
        $analytics = (isset($consent['analytics']) && true === $consent['analytics'])
            || (isset($consent['statistics']) && true === $consent['statistics']);
        $marketing = isset($consent['marketing']) && true === $consent['marketing'];

        $expired = array();
        if (!$analytics) {
            $expired = array_merge($expired, self::tracking_cookie_names('analytics'));
        }
        if (!$marketing) {
            $expired = array_merge($expired, self::tracking_cookie_names('marketing'));
        }
        foreach ($expired as $name) {
            self::expire($name);
        }
        return $expired;
    }

    /**
     * Cookies recorded during this request.
     *
     * @return array
     */
    public static function sent_cookies() {
        return self::$sent;
    }

    /**
     * Clear the per-request record (tests, long-running processes).
     *
     * @return void
     */
    public static function reset_sent() {
        self::$sent = array();
    }

    /**
     * Record and, when possible, send one Set-Cookie header.
     *
     * @param string $name    Name.
     * @param string $value   Value.
     * @param array  $options setcookie() options array (PHP >= 7.3).
     * @return bool
     */
    private static function emit($name, $value, $options) {
        if (!preg_match('/^[A-Za-z0-9_\-\.]{1,128}$/', $name)) {
            return false;
        }
        self::$sent[] = array('name' => $name, 'value' => $value, 'options' => $options);
        /**
         * Fires for every cookie TrackWP writes or expires.
         *
         * @param string $name    Cookie name.
         * @param string $value   Value ('' when expiring).
         * @param array  $options setcookie() options.
         */
        do_action('trackwp_cookie_set', $name, $value, $options);
        if (headers_sent()) {
            return true;
        }
        return setcookie($name, $value, $options);
    }
}
