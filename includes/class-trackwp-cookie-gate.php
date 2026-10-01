<?php
/**
 * Server-side cookie gate (1.11.1 D6, KC2-KC5, §9 TR7).
 *
 * A narrow, additional safeguard: it only touches Set-Cookie headers that
 * match an EXPLICIT `cookie:<pattern>` rule in the compiled blocker option
 * (TrackWP_Blocker_Rules::compile()). It never collects cookie names from
 * the vendor catalog or from custom vendors on its own (D6). Cookies that
 * are not covered by a cookie: rule, or that are on the NEVER list, are
 * always kept untouched.
 *
 * Registration is deliberately as late as possible (send_headers,
 * rest_pre_serve_request, admin_init for AJAX), and the whole plan is
 * computed and validated BEFORE any header is removed. The health/coverage
 * of this mechanism is honest, not exhaustive: another plugin can register
 * its own header_register_callback() afterwards and silently replace this
 * one, and a cached response never reaches PHP at all.
 *
 * @package TrackWP
 */

if (!defined('ABSPATH')) {
    exit;
}

class TrackWP_Cookie_Gate {

    /**
     * Cookies that are never touched by the gate, whatever the rules say.
     * Kept short and concretely justified (D6): WP core login state, the
     * TrackWP consent cookie itself, and WooCommerce's cart/session state.
     * Prefixes end in '*'.
     */
    const NEVER = array(
        'trackwp_consent',           // the consent choice itself
        'wordpress_*',               // WP core login/session (http and https), incl. wordpress_test_cookie
        'wp-postpass_*',             // password-protected posts
        'wp_woocommerce_session_*',  // WooCommerce session (cart and checkout)
        'woocommerce_cart_hash',     // WooCommerce cart state
        'woocommerce_items_in_cart', // WooCommerce cart state
    );

    /** Defaults for nocache_params() (§9 TR7): common ad click-id parameters. */
    const DEFAULT_NOCACHE_PARAMS = array(
        'gclid', 'gbraid', 'wbraid', 'dclid', 'fbclid', 'msclkid', 'ttclid', 'li_fat_id', 'twclid', 'epik', 'ScCid',
    );

    /** @var bool Whether header_register_callback() succeeded this request. */
    private static $registered = false;

    /** @var bool Whether on_headers() actually ran this request. */
    private static $ran = false;

    /**
     * Wire the late registration points. Called once from
     * TrackWP::init_hooks().
     *
     * @return void
     */
    public static function hook() {
        add_action('send_headers', array(__CLASS__, 'register_callback'), PHP_INT_MAX);
        add_filter('rest_pre_serve_request', array(__CLASS__, 'filter_rest_pre_serve_request'), PHP_INT_MAX, 1);
        add_action('admin_init', array(__CLASS__, 'maybe_register_for_ajax'), PHP_INT_MAX);
        add_action('template_redirect', array(__CLASS__, 'maybe_nocache_click_id'), 0);
        add_action('shutdown', array(__CLASS__, 'on_shutdown'));
    }

    /**
     * rest_pre_serve_request wrapper: registers, then returns $served
     * unchanged (KC3).
     *
     * @param mixed $served Whether the request has already been served.
     * @return mixed
     */
    public static function filter_rest_pre_serve_request($served) {
        self::register_callback();
        return $served;
    }

    /**
     * admin_init wrapper: only registers for wp-admin AJAX requests (KC3).
     *
     * @return void
     */
    public static function maybe_register_for_ajax() {
        if (function_exists('wp_doing_ajax') && wp_doing_ajax()) {
            self::register_callback();
        }
    }

    /**
     * Register the header callback at most once per request. Checks
     * should_run() first, records an honest status on every failure mode,
     * and never throws.
     *
     * @return void
     */
    public static function register_callback() {
        if (self::$registered) {
            return;
        }
        if (!self::should_run()) {
            return;
        }
        if (headers_sent()) {
            self::status('cookie_gate_late');
            return;
        }
        $ok = header_register_callback(array(__CLASS__, 'on_headers'));
        if (false === $ok) {
            self::status('cookie_gate_register_failed');
            return;
        }
        self::$registered = true;
    }

    /**
     * Whether the gate should run for the current request at all (KC3).
     *
     * @return bool
     */
    public static function should_run() {
        $opt  = get_option(TrackWP_Blocker_Rules::OPTION, array());
        $opt  = is_array($opt) ? $opt : array();
        $mode = class_exists('TrackWP_Blocker') ? TrackWP_Blocker::mode($opt) : 'off';
        if ('off' === $mode) {
            return false;
        }
        if ('test' === $mode) {
            if (!function_exists('current_user_can') || !did_action('init') || !current_user_can('manage_options')) {
                return false;
            }
        }
        if (!TrackWP_Blocker_Rules::has_cookie_rules(TrackWP_Blocker_Rules::compiled())) {
            return false;
        }
        if (function_exists('wp_doing_cron') && wp_doing_cron()) {
            return false;
        }
        if (defined('WP_CLI') && WP_CLI) {
            return false;
        }
        if (is_admin() && !(function_exists('wp_doing_ajax') && wp_doing_ajax())) {
            return false;
        }
        if (class_exists('TrackWP_Blocker_Scanner') && TrackWP_Blocker_Scanner::is_observe_request()) {
            return false;
        }
        if (class_exists('TrackWP_Blocker') && TrackWP_Blocker::path_excepted($opt)) {
            return false;
        }
        return (bool) apply_filters('trackwp_cookie_gate_should_run', true);
    }

    /**
     * Current-request consent (KC3): a value written by this same request
     * (via TrackWP_Cookies) wins over the already-sent cookie. A missing
     * choice, a stale cookie or the wrong version all mean every optional
     * category is false.
     *
     * @return array{has_choice:bool,statistics:bool,marketing:bool,personalisation:bool}
     */
    public static function consent() {
        $none = array('has_choice' => false, 'statistics' => false, 'marketing' => false, 'personalisation' => false);
        if (class_exists('TrackWP_Cookies') && class_exists('TrackWP_Consent')) {
            $value = null;
            $found = false;
            foreach (TrackWP_Cookies::sent_cookies() as $c) {
                if (is_array($c) && isset($c['name']) && TrackWP_Cookies::CONSENT_COOKIE === $c['name']) {
                    $value = isset($c['value']) ? (string) $c['value'] : '';
                    $found = true;
                }
            }
            if ($found) {
                if ('' === $value) {
                    return $none;
                }
                $parsed = TrackWP_Consent::parse_cookie($value);
                if (null === $parsed) {
                    return $none;
                }
                return array(
                    'has_choice'      => true,
                    'statistics'      => (bool) $parsed['statistics'],
                    'marketing'       => (bool) $parsed['marketing'],
                    'personalisation' => (bool) $parsed['personalisation'],
                );
            }
            $c = TrackWP_Consent::get_current_consent();
            return array(
                'has_choice'      => (bool) $c['has_choice'],
                'statistics'      => (bool) $c['statistics'],
                'marketing'       => (bool) $c['marketing'],
                'personalisation' => (bool) $c['personalisation'],
            );
        }
        return $none;
    }

    /**
     * Whether a single Set-Cookie value (without the "Set-Cookie:" header
     * name) is a deletion under RFC 6265 (KC4.2). Max-Age has forrang;
     * only a valid Max-Age <= 0, or (when Max-Age is absent/invalid) a
     * parseable Expires before $now, counts. Invalid attributes never count.
     *
     * @param string $line Cookie-pair plus attributes.
     * @param int    $now  Current unix timestamp.
     * @return bool
     */
    public static function is_deletion(string $line, int $now): bool {
        $max_age = self::attr_value($line, 'Max-Age');
        if (null !== $max_age) {
            $trimmed = trim($max_age);
            if (1 === preg_match('/^-?\d+$/', $trimmed)) {
                return ((int) $trimmed) <= 0;
            }
            // Invalid Max-Age: not a VALID Max-Age, so it does not decide on
            // its own; Expires is still consulted (KC4.2).
        }
        $expires = self::attr_value($line, 'Expires');
        if (null !== $expires) {
            $ts = strtotime(trim($expires));
            if (false !== $ts) {
                return $ts < $now;
            }
        }
        return false;
    }

    /**
     * Value of one cookie attribute (case-insensitive name), or null when
     * absent. A bare (valueless) attribute returns ''.
     *
     * @param string $line Cookie-pair plus attributes.
     * @param string $name Attribute name.
     * @return string|null
     */
    private static function attr_value($line, $name) {
        $parts = explode(';', $line);
        array_shift($parts); // the leading name=value pair
        foreach ($parts as $part) {
            $part = trim($part);
            if ('' === $part) {
                continue;
            }
            $eq  = strpos($part, '=');
            $key = trim(false === $eq ? $part : substr($part, 0, $eq));
            if (0 === strcasecmp($key, $name)) {
                return false === $eq ? '' : substr($part, $eq + 1);
            }
        }
        return null;
    }

    /**
     * The cookie's own name: the text before the first '=', trimmed
     * (KC4.1). __Host- and __Secure- are part of the name. Returns null for
     * an invalid/empty name (RFC 6265 cookie-name token).
     *
     * @param string $line Cookie-pair plus attributes.
     * @return string|null
     */
    private static function cookie_name($line) {
        $eq = strpos($line, '=');
        if (false === $eq) {
            return null;
        }
        $name = trim(substr($line, 0, $eq));
        if ('' === $name || 0 === preg_match('/^[!#$%&\'*+\-.0-9A-Za-z^_`|~]+$/', $name)) {
            return null;
        }
        return $name;
    }

    /**
     * name_matches: delegates to TrackWP_Cookie_Scanner so there is only one
     * implementation of the 'prefix*'/exact rule (KC3).
     *
     * @param string $name    Cookie name.
     * @param string $pattern Exact name or 'prefix*'.
     * @return bool
     */
    public static function name_matches(string $name, string $pattern): bool {
        return class_exists('TrackWP_Cookie_Scanner') && TrackWP_Cookie_Scanner::name_matches($name, $pattern);
    }

    /**
     * never patterns: the built-in NEVER list, filterable, plus
     * never.cookies from compile()'s exceptions.allow (KC2/KC3).
     *
     * @param array $compiled TrackWP_Blocker_Rules::compile() output.
     * @return string[]
     */
    private static function never_patterns(array $compiled) {
        $never = apply_filters('trackwp_cookie_gate_never', self::NEVER);
        $never = is_array($never) ? array_values(array_filter($never, 'is_string')) : self::NEVER;
        $extra = (isset($compiled['never']['cookies']) && is_array($compiled['never']['cookies'])) ? $compiled['never']['cookies'] : array();
        return array_merge($never, array_values(array_filter($extra, 'is_string')));
    }

    /**
     * First compiled cookie rule that matches this name (KC2's sort order
     * already gives first-match-wins), or null.
     *
     * @param string $name     Cookie name.
     * @param array  $compiled TrackWP_Blocker_Rules::compile() output.
     * @return array{0:string,1:string}|null {category, rule_id}
     */
    private static function classify($name, array $compiled) {
        $list = (isset($compiled['cookies']) && is_array($compiled['cookies'])) ? $compiled['cookies'] : array();
        foreach ($list as $entry) {
            if (isset($entry[0], $entry[1]) && self::name_matches($name, $entry[0])) {
                return array($entry[1], isset($entry[2]) ? $entry[2] : '');
            }
        }
        return null;
    }

    /**
     * Compute the full gating plan for a set of raw header lines (KC4).
     * Pure and side-effect free, so it can be validated before any mutation.
     *
     * @param string[] $lines    Header lines as returned by headers_list()
     *                           (e.g. "Set-Cookie: name=value; Path=/").
     *                           Non-Set-Cookie lines are ignored (they are
     *                           never touched by header_remove('Set-Cookie')
     *                           either).
     * @param array    $consent  consent() output.
     * @param array    $compiled TrackWP_Blocker_Rules::compile() output.
     * @param int      $now      Current unix timestamp.
     * @return array{keep:string[],drop:string[],kept_gated:bool} keep holds
     *         the original Set-Cookie header lines to restore, in order;
     *         drop holds the cookie NAMES that were removed.
     */
    public static function plan(array $lines, array $consent, array $compiled, int $now): array {
        $keep       = array();
        $drop       = array();
        $kept_gated = false;
        $never      = self::never_patterns($compiled);

        foreach ($lines as $line) {
            if (!is_string($line)) {
                continue;
            }
            $colon = strpos($line, ':');
            if (false === $colon || 0 !== strcasecmp(trim(substr($line, 0, $colon)), 'Set-Cookie')) {
                continue;
            }
            $value = trim(substr($line, $colon + 1));
            $name  = self::cookie_name($value);
            if (null === $name) {
                $keep[] = $line;
                continue;
            }
            if (self::is_deletion($value, $now)) {
                $keep[] = $line;
                continue;
            }
            if (self::name_matches_any($name, $never)) {
                $keep[] = $line;
                continue;
            }
            $hit = self::classify($name, $compiled);
            if (null === $hit) {
                $keep[] = $line;
                continue;
            }
            $category = $hit[0];
            if (!empty($consent[$category])) {
                $keep[]     = $line;
                $kept_gated = true;
                continue;
            }
            $drop[] = $name;
        }

        return array('keep' => $keep, 'drop' => $drop, 'kept_gated' => $kept_gated);
    }

    /**
     * @param string   $name     Cookie name.
     * @param string[] $patterns Exact names or 'prefix*'.
     * @return bool
     */
    private static function name_matches_any($name, array $patterns) {
        foreach ($patterns as $p) {
            if (is_string($p) && self::name_matches($name, $p)) {
                return true;
            }
        }
        return false;
    }

    /**
     * PHP's header_register_callback() (KC3.2). Computes and validates the
     * whole plan first; only mutates when it is safe. header_remove() only
     * runs when something is actually being dropped, so a response with
     * nothing to gate never becomes uncacheable by accident.
     *
     * @return void
     */
    public static function on_headers() {
        self::$ran = true;
        try {
            $compiled = TrackWP_Blocker_Rules::compiled();
            $consent  = self::consent();
            $result   = self::plan(headers_list(), $consent, $compiled, time());
        } catch (\Throwable $e) {
            self::status('cookie_gate_exception');
            return;
        }

        if (!empty($result['drop'])) {
            header_remove('Set-Cookie');
            foreach ($result['keep'] as $line) {
                header($line, false);
            }
        }
        if (!empty($result['kept_gated'])) {
            header('Cache-Control: private, no-store, max-age=0', true);
            header('X-LiteSpeed-Cache-Control: no-cache', true);
        }
    }

    /**
     * Honest monitoring (KC3): if the gate should have run, was registered,
     * but never ran even though headers were already sent, something else
     * (another plugin, or a cache hit at the PHP layer) got in the way.
     *
     * @return void
     */
    public static function on_shutdown() {
        if (self::$ran || !self::$registered || !headers_sent()) {
            return;
        }
        if (self::should_run()) {
            self::status('cookie_gate_not_run');
        }
    }

    /**
     * Forget the per-request state (tests, long-running workers).
     *
     * @return void
     */
    public static function reset_request_state() {
        self::$registered = false;
        self::$ran         = false;
    }

    /**
     * §9 TR7: the ad/affiliate click-id parameters that make a page
     * uncacheable while the gate is active. A generic, site-agnostic list;
     * the admin can add more (e.g. an affiliate parameter) via
     * trackwp_blocker['nocache_params']. Only the configured list falls back
     * to the defaults when the key is missing entirely; an explicit, empty
     * list is honoured as "no extra click-id parameters".
     *
     * @return string[]
     */
    public static function nocache_params() {
        $opt = get_option(TrackWP_Blocker_Rules::OPTION, array());
        $opt = is_array($opt) ? $opt : array();
        $list = (array_key_exists('nocache_params', $opt) && is_array($opt['nocache_params']))
            ? $opt['nocache_params']
            : self::DEFAULT_NOCACHE_PARAMS;

        $out = array();
        foreach ($list as $p) {
            if (is_string($p) && 1 === preg_match('/^[A-Za-z0-9_\-]{1,40}$/', $p)) {
                $out[] = $p;
            }
        }
        $out = array_slice(array_values(array_unique($out)), 0, 30);

        $filtered = apply_filters('trackwp_nocache_params', $out);
        return is_array($filtered) ? array_values(array_filter($filtered, 'is_string')) : $out;
    }

    /**
     * KC4.6: a request carrying a known click-id parameter must never be
     * cached while the gate is active, or a later visitor could receive a
     * cached response that was built for someone else's consent state.
     *
     * @return void
     */
    public static function maybe_nocache_click_id() {
        if (!self::should_run()) {
            return;
        }
        foreach (self::nocache_params() as $param) {
            if (isset($_GET[$param])) {
                if (!defined('DONOTCACHEPAGE')) {
                    define('DONOTCACHEPAGE', true);
                }
                if (function_exists('nocache_headers')) {
                    nocache_headers();
                }
                do_action('litespeed_control_set_nocache', 'trackwp click id');
                return;
            }
        }
    }

    /**
     * @param string $code Status code (see TrackWP_Blocker::record_status()).
     * @return void
     */
    private static function status($code) {
        if (class_exists('TrackWP_Blocker')) {
            TrackWP_Blocker::record_status($code);
        }
    }
}
