<?php
/**
 * Blocker scanner (PLAN-1.11.0-v2 §3.4, KB3, KB4, KB13, S12-S15).
 *
 * POST /trackwp/v1/blocker/scan fetches the front page plus up to four other
 * pages of the site as an anonymous visitor, in observe mode, and records one
 * line per tracker found. It also owns the observe-mode token: the rendering
 * branch in TrackWP_Blocker asks is_observe_request() and appends
 * scan_map_comment() before </body>. That branch runs in every mode,
 * including 'off' (S12).
 *
 * Matching is NOT implemented here. The scanner reuses TrackWP_Blocker_Rules
 * (T2) so the scan, the server rewrite and the guard classify alike:
 * normalize_url()/url_key() (S3), handle_from_id() (S4), is_js_type() (S10),
 * compile() (KB7), match() (KB6), builtin_status() (KB8) and
 * observation_id() (KB2). Vendor detection compiles every catalog signature
 * as if blocked (scan_context()), so a finding is recognised whether or not
 * the admin has toggled it.
 *
 * Nothing stored contains a query string, inline code or a cookie value
 * (KB3): only host, path, handle, plugin slug, marker and cookie names.
 *
 * @package TrackWP
 */

if (!defined('ABSPATH')) {
    exit;
}

class TrackWP_Blocker_Scanner {

    const OPTION        = 'trackwp_blocker_scan';
    const LOCK          = 'trackwp_blk_scan_lock';
    const LOCK_TTL      = 120;
    const TOKEN_PREFIX  = 'trackwp_blk_obs_';
    const TOKEN_TTL     = 600;
    const HEADER        = 'X-TrackWP-Scan';
    const NC_PARAM      = 'trackwp_scan_nc';
    const MAX_PAGES     = 5;
    const MAX_EXTRA     = 4;
    const MAX_BYTES     = 3145728;
    const MAP_OPEN      = '<!--trackwp-scan-map:';
    const MARKER_RE     = '/^[A-Za-z0-9._-]{8,64}$/';
    const PRIO1_MARKER  = 'trackwpConsentReader';

    /** @var bool|null Observe state of the current request, resolved once. */
    private static $observe = null;

    /**
     * Register POST /trackwp/v1/blocker/scan.
     *
     * @return void
     */
    public function register_routes() {
        register_rest_route('trackwp/v1', '/blocker/scan', array(
            'methods'             => 'POST',
            'callback'            => array($this, 'handle_scan'),
            'permission_callback' => array($this, 'check_permission'),
        ));
    }

    /**
     * manage_options plus a valid wp_rest nonce in X-WP-Nonce.
     *
     * @param WP_REST_Request $request Request.
     * @return bool
     */
    public function check_permission($request) {
        if (!current_user_can('manage_options')) {
            return false;
        }
        $nonce = $request->get_header('X-WP-Nonce');
        return is_string($nonce) && '' !== $nonce && false !== wp_verify_nonce($nonce, 'wp_rest');
    }

    /**
     * REST callback: one scan at a time (transient lock, 120 s).
     *
     * @param WP_REST_Request $request Request.
     * @return WP_REST_Response|WP_Error
     */
    public function handle_scan($request) {
        if (false !== get_transient(self::LOCK)) {
            return new WP_Error('trackwp_scan_locked', __('En scanning kører allerede. Prøv igen om lidt.', 'trackwp'), array('status' => 409));
        }
        set_transient(self::LOCK, time(), self::LOCK_TTL);
        try {
            $result = $this->run_scan();
        } finally {
            delete_transient(self::LOCK);
        }
        if (is_wp_error($result)) {
            return $result;
        }
        return rest_ensure_response($result);
    }

    /**
     * Fetch, parse, health-check and save (KB3). Existing rules are never
     * touched; items not seen again are kept and flagged 'stale'.
     *
     * @return array|WP_Error The saved scan.
     */
    public function run_scan() {
        if (!self::rules_available()) {
            return new WP_Error('trackwp_blocker_rules_unavailable', __('Blokeringens regelmotor mangler.', 'trackwp'), array('status' => 503));
        }
        if (!TrackWP_Blocker::html_api_available()) {
            return new WP_Error('no_html_api', __('Scanningen kræver WordPress 6.5 eller nyere.', 'trackwp'), array('status' => 501));
        }
        $blocker  = get_option('trackwp_blocker', array());
        $blocker  = is_array($blocker) ? $blocker : array();
        $extra    = isset($blocker['extra_paths']) && is_array($blocker['extra_paths']) ? $blocker['extra_paths'] : array();
        $ctx      = self::scan_context($blocker);
        $user_id  = get_current_user_id();
        // 1.11.1 KC5: server_cookie status uses the REAL compiled cookie
        // rules (not the detection-mode $ctx used for HTML matching), so a
        // row only shows "regel aktiv" when a cookie: rule is actually applied.
        $cookie_compiled = class_exists('TrackWP_Blocker_Rules') ? TrackWP_Blocker_Rules::compiled() : array();
        $mode             = class_exists('TrackWP_Blocker') ? TrackWP_Blocker::mode($blocker) : 'off';

        $items      = array();
        $pages_ok   = array();
        $incomplete = array();
        $errors     = array();
        $meta       = array();
        $gtm        = false;
        $scan_map   = array();

        foreach (self::select_pages($extra) as $path) {
            $token = self::issue_token($user_id);
            $fetch = self::fetch(self::url_for_path($path), $token);
            // One-time token: gone whether or not the page consumed it (a
            // cached page never reaches PHP, so the token would linger).
            delete_transient(self::token_key($token));
            if (is_wp_error($fetch)) {
                $errors[] = array('path' => $path, 'code' => $fetch->get_error_code());
                continue;
            }
            $parsed = self::parse_page($fetch['body'], self::url_for_path($path), $ctx);
            if (null === $parsed['map']) {
                $incomplete[] = $path;
            } else {
                $scan_map += $parsed['map'];
            }
            foreach ($parsed['items'] as $item) {
                self::add_item($items, $item, $path);
            }
            foreach (self::server_cookie_items($fetch['cookies'], $cookie_compiled, $mode) as $item) {
                self::add_item($items, $item, $path);
            }
            // Occurrences per source: the page with the most of each (the
            // same install repeats on every page).
            foreach (array_count_values($parsed['meta']) as $code => $n) {
                $meta[$code] = max(isset($meta[$code]) ? $meta[$code] : 0, $n);
            }
            $gtm  = $gtm || $parsed['gtm'];
            $pages_ok[] = $path;
        }

        if (!in_array('/', $pages_ok, true)) {
            $code = empty($errors) ? 'fetch_failed' : $errors[0]['code'];
            return new WP_Error('trackwp_scan_fetch_failed', __('Serverhentning mislykkedes.', 'trackwp'), array('status' => 502, 'code' => $code));
        }

        foreach (self::server_side_items($items) as $item) {
            self::add_item($items, $item, '/');
        }
        self::apply_map_relations($items, $scan_map);

        $health = self::health_check($ctx['rules']);
        foreach ($items as &$item) {
            $item['seen_blocked'] = ('' !== (string) $item['rule_id'] && isset($health['observed'][$item['rule_id']]))
                ? (bool) $health['observed'][$item['rule_id']]
                : null;
        }
        unset($item);

        $previous = get_option(self::OPTION, array());
        if (is_array($previous) && !empty($previous['items']) && is_array($previous['items'])) {
            foreach ($previous['items'] as $old) {
                if (is_array($old) && isset($old['obs_id']) && !isset($items[$old['obs_id']])) {
                    $old['stale']            = true;
                    $items[$old['obs_id']]   = $old;
                }
            }
        }

        $scan = array(
            'scanned_at'       => time(),
            'pages'            => $pages_ok,
            'incomplete_pages' => $incomplete,
            'errors'           => $errors,
            'items'            => array_values($items),
            'meta_sources'     => self::expand_counts($meta),
            'gtm_active'       => $gtm,
            'health'           => $health,
        );
        update_option(self::OPTION, $scan, false);
        return $scan;
    }

    // -----------------------------------------------------------------
    // Page selection (S13) and fetching (§3.4.2, S12)
    // -----------------------------------------------------------------

    /**
     * Front page first, then up to four valid extra paths, then product,
     * cart, latest post and contact page, stopping at five (S13).
     *
     * @param array $extra_paths Admin-supplied paths (KB1 extra_paths).
     * @return string[] Paths, no query strings.
     */
    public static function select_pages($extra_paths) {
        $pages = array('/');
        $count = 0;
        foreach ((array) $extra_paths as $p) {
            if ($count >= self::MAX_EXTRA) {
                break;
            }
            $p = self::valid_path($p);
            if (null !== $p && !in_array($p, $pages, true)) {
                $pages[] = $p;
                $count++;
            }
        }
        foreach (self::auto_page_urls() as $url) {
            if (count($pages) >= self::MAX_PAGES) {
                break;
            }
            $path = self::path_of_own_url($url);
            if (null !== $path && !in_array($path, $pages, true)) {
                $pages[] = $path;
            }
        }
        return array_slice($pages, 0, self::MAX_PAGES);
    }

    /**
     * A site path: starts with a single '/', no scheme, no query, no
     * fragment, no whitespace or backslash. Absolute URLs are rejected.
     *
     * @param mixed $path Candidate.
     * @return string|null
     */
    public static function valid_path($path) {
        if (!is_string($path)) {
            return null;
        }
        $path = trim($path);
        if ('' === $path || '/' !== $path[0] || 0 === strpos($path, '//') || strlen($path) > 200) {
            return null;
        }
        if (preg_match('/[\s\\\\?#]/', $path) || preg_match('/[\x00-\x1f\x7f]/', $path)) {
            return null;
        }
        return $path;
    }

    /**
     * Candidate URLs in the fixed order product, cart, post, contact.
     *
     * @return string[]
     */
    private static function auto_page_urls() {
        $urls = array();
        if (post_type_exists('product')) {
            $ids = get_posts(array('post_type' => 'product', 'post_status' => 'publish', 'numberposts' => 1, 'fields' => 'ids'));
            if (!empty($ids)) {
                $urls[] = get_permalink($ids[0]);
            }
        }
        if (function_exists('wc_get_cart_url') && function_exists('wc_get_page_id') && wc_get_page_id('cart') > 0) {
            $urls[] = wc_get_cart_url();
        }
        $posts = get_posts(array('post_type' => 'post', 'post_status' => 'publish', 'numberposts' => 1, 'fields' => 'ids'));
        if (!empty($posts)) {
            $urls[] = get_permalink($posts[0]);
        }
        foreach (array('kontakt', 'kontakt-os', 'contact') as $slug) {
            $page = get_page_by_path($slug);
            if ($page instanceof WP_Post && 'publish' === $page->post_status) {
                $urls[] = get_permalink($page);
                break;
            }
        }
        return array_filter($urls, 'is_string');
    }

    /**
     * Path of a same-origin URL without query. URLs with a query (plain
     * permalinks) are skipped, since pages are stored as paths only.
     *
     * @param string $url URL.
     * @return string|null
     */
    private static function path_of_own_url($url) {
        if (!is_string($url) || !self::same_origin($url)) {
            return null;
        }
        $parts = wp_parse_url($url);
        if (!empty($parts['query'])) {
            return null;
        }
        return self::valid_path(isset($parts['path']) ? $parts['path'] : '/');
    }

    /**
     * Absolute URL for a path on the home origin.
     *
     * @param string $path Path.
     * @return string
     */
    public static function url_for_path($path) {
        $home = wp_parse_url(home_url('/'));
        $url  = strtolower($home['scheme']) . '://' . $home['host'];
        if (!empty($home['port'])) {
            $url .= ':' . $home['port'];
        }
        return $url . $path;
    }

    /**
     * Scheme, host and port equal to home_url() (default ports filled in).
     *
     * @param string $url URL.
     * @return bool
     */
    public static function same_origin($url) {
        $a = wp_parse_url($url);
        $b = wp_parse_url(home_url('/'));
        if (!is_array($a) || empty($a['scheme']) || empty($a['host']) || !is_array($b)) {
            return false;
        }
        $origin = function ($p) {
            $scheme = strtolower($p['scheme']);
            $port   = !empty($p['port']) ? (int) $p['port'] : ('https' === $scheme ? 443 : 80);
            return $scheme . '://' . strtolower($p['host']) . ':' . $port;
        };
        return $origin($a) === $origin($b);
    }

    /**
     * Anonymous fetch (§3.4.2): no redirects, unsafe URLs rejected, 5 s,
     * 3 MB cap, no cookies, same origin only. With a token the request
     * carries X-TrackWP-Scan and a cache-bust parameter (S12).
     *
     * @param string      $url   Page URL (no query).
     * @param string|null $token Observe token, null for a visitor fetch.
     * @return array|WP_Error {body, cookies: string[]}
     */
    public static function fetch($url, $token = null) {
        if (!self::same_origin($url)) {
            return new WP_Error('foreign_origin', 'foreign origin');
        }
        $headers = array();
        if (null !== $token) {
            $headers[self::HEADER] = $token;
            $url = add_query_arg(self::NC_PARAM, wp_generate_password(12, false, false), $url);
        }
        $response = wp_safe_remote_get($url, array(
            'redirection'         => 0,
            'reject_unsafe_urls'  => true,
            'timeout'             => 5,
            'limit_response_size' => self::MAX_BYTES,
            'cookies'             => array(),
            'headers'             => $headers,
        ));
        if (is_wp_error($response)) {
            return new WP_Error('http_error', $response->get_error_message());
        }
        $code = (int) wp_remote_retrieve_response_code($response);
        if ($code >= 300 && $code < 400) {
            return new WP_Error('redirect', 'redirect');
        }
        if (200 !== $code) {
            return new WP_Error('http_' . $code, 'http ' . $code);
        }
        return array(
            'body'    => (string) wp_remote_retrieve_body($response),
            'cookies' => self::cookie_names($response),
        );
    }

    /**
     * Set-Cookie names only; values are never kept.
     *
     * @param array $response HTTP API response.
     * @return string[]
     */
    private static function cookie_names($response) {
        $names = array();
        foreach ((array) wp_remote_retrieve_cookies($response) as $c) {
            if (is_object($c) && isset($c->name)) {
                $names[] = (string) $c->name;
            }
        }
        $raw = wp_remote_retrieve_header($response, 'set-cookie');
        foreach ((array) $raw as $line) {
            $eq = strpos((string) $line, '=');
            if (false !== $eq) {
                $names[] = trim(substr((string) $line, 0, $eq));
            }
        }
        $out = array();
        foreach ($names as $n) {
            if (preg_match('/^[A-Za-z0-9._\-]{1,128}$/', $n)) {
                $out[$n] = true;
            }
        }
        return array_keys($out);
    }

    // -----------------------------------------------------------------
    // Observe token and scan map (KB13, S12)
    // -----------------------------------------------------------------

    /**
     * One token per page fetch, bound to the admin, TTL 10 min.
     *
     * @param int $user_id User the scan runs for.
     * @return string 32 hex chars.
     */
    public static function issue_token($user_id) {
        $token = bin2hex(random_bytes(16));
        set_transient(self::token_key($token), array('user_id' => (int) $user_id, 'exp' => time() + self::TOKEN_TTL), self::TOKEN_TTL);
        return $token;
    }

    /**
     * @param string $token Token.
     * @return string Transient name.
     */
    private static function token_key($token) {
        return self::TOKEN_PREFIX . hash('sha256', (string) $token);
    }

    /**
     * Look up and delete the token at once (single use). Valid only when
     * unexpired and bound to a user who can manage_options. Fails closed.
     * A valid token only enables observe rendering, never any write.
     *
     * @param mixed $token Header value.
     * @return bool
     */
    public static function consume_token($token) {
        if (!is_string($token) || !preg_match('/^[a-f0-9]{32}$/', $token)) {
            return false;
        }
        $key  = self::token_key($token);
        $data = get_transient($key);
        delete_transient($key);
        if (!is_array($data) || empty($data['user_id']) || empty($data['exp']) || (int) $data['exp'] < time()) {
            return false;
        }
        return user_can((int) $data['user_id'], 'manage_options');
    }

    /**
     * Whether this front-end request is an observe (scan) request. Resolved
     * once per request, so the token is consumed once. When true the
     * response is marked uncacheable (DONOTCACHEPAGE, nocache_headers).
     * The caller (TrackWP_Blocker) skips rewriting and appends
     * scan_map_comment() before </body>, in every mode including 'off'.
     *
     * @return bool
     */
    public static function is_observe_request() {
        if (null !== self::$observe) {
            return self::$observe;
        }
        self::$observe = false;
        $method = isset($_SERVER['REQUEST_METHOD']) ? strtoupper((string) $_SERVER['REQUEST_METHOD']) : 'GET';
        if (is_admin() || !in_array($method, array('GET', 'HEAD'), true) || empty($_SERVER['HTTP_X_TRACKWP_SCAN'])) {
            return false;
        }
        if (!self::consume_token(wp_unslash($_SERVER['HTTP_X_TRACKWP_SCAN']))) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- validated by consume_token() against ^[a-f0-9]{32}$.
            return false;
        }
        self::$observe = true;
        if (!defined('DONOTCACHEPAGE')) {
            define('DONOTCACHEPAGE', true);
        }
        if (!headers_sent()) {
            nocache_headers();
        }
        return true;
    }

    /**
     * Forget the per-request observe state (tests, long-running workers).
     *
     * @return void
     */
    public static function reset_request_state() {
        self::$observe = null;
    }

    /**
     * {handle, src (host+path), deps, plugin} for every printed script.
     *
     * @return array[]
     */
    public static function build_scan_map() {
        global $wp_scripts;
        $map = array();
        if (!($wp_scripts instanceof WP_Scripts) || !self::rules_available()) {
            return $map;
        }
        foreach ((array) $wp_scripts->done as $handle) {
            $reg = isset($wp_scripts->registered[$handle]) ? $wp_scripts->registered[$handle] : null;
            if (!$reg) {
                continue;
            }
            $n   = (is_string($reg->src) && '' !== $reg->src) ? self::norm($reg->src, home_url('/')) : null;
            $src = is_array($n) ? $n['key'] : '';
            $map[] = array(
                'handle' => (string) $handle,
                'src'    => $src,
                'deps'   => array_values(array_map('strval', (array) $reg->deps)),
                'plugin' => is_array($n) ? self::plugin_slug($n['path']) : '',
            );
        }
        return $map;
    }

    /**
     * <!--trackwp-scan-map:BASE64(JSON)--> for the current request.
     *
     * @return string
     */
    public static function scan_map_comment() {
        return self::MAP_OPEN . base64_encode((string) wp_json_encode(self::build_scan_map())) . '-->';
    }

    /**
     * Insert the scan map before the last </body>, or append it.
     *
     * @param string $html Page HTML.
     * @return string
     */
    public static function inject_scan_map($html) {
        $comment = self::scan_map_comment();
        $pos     = strripos($html, '</body>');
        return false === $pos ? $html . $comment : substr($html, 0, $pos) . $comment . substr($html, $pos);
    }

    /**
     * Decode the last scan map in a page, indexed by handle.
     *
     * @param string $html Page HTML.
     * @return array|null Null when the page carries no valid map.
     */
    public static function parse_scan_map($html) {
        $start = strrpos($html, self::MAP_OPEN);
        if (false === $start) {
            return null;
        }
        $start += strlen(self::MAP_OPEN);
        $end    = strpos($html, '-->', $start);
        if (false === $end) {
            return null;
        }
        $json = base64_decode(substr($html, $start, $end - $start), true);
        $data = false === $json ? null : json_decode($json, true);
        if (!is_array($data)) {
            return null;
        }
        $map = array();
        foreach ($data as $row) {
            if (is_array($row) && isset($row['handle']) && is_string($row['handle'])) {
                $map[$row['handle']] = array(
                    'src'    => isset($row['src']) ? (string) $row['src'] : '',
                    'deps'   => isset($row['deps']) ? array_values(array_filter((array) $row['deps'], 'is_string')) : array(),
                    'plugin' => isset($row['plugin']) ? (string) $row['plugin'] : '',
                );
            }
        }
        return $map;
    }

    // -----------------------------------------------------------------
    // Parsing (§3.4.3, KB3, KB4, S14, S15)
    // -----------------------------------------------------------------

    /**
     * Matching context for a scan. 'compiled' holds every catalog signature
     * plus every admin rule compiled as if blocked (detection only, never
     * stored or used for rewriting), with no exceptions, so an exception or
     * an untoggled vendor is still recognised. 'vendor_of' maps rule id to
     * vendor key.
     *
     * @param array $blocker The trackwp_blocker option (may be partial).
     * @return array {compiled, vendor_of, rules, allow}
     */
    public static function scan_context($blocker) {
        $catalog   = class_exists('TrackWP_Consent_Profile') ? TrackWP_Consent_Profile::vendor_catalog() : array();
        $admin     = (isset($blocker['rules']) && is_array($blocker['rules'])) ? $blocker['rules'] : array();
        $allow     = (isset($blocker['exceptions']['allow']) && is_array($blocker['exceptions']['allow'])) ? $blocker['exceptions']['allow'] : array();
        $blockable = TrackWP_Blocker_Rules::BLOCKABLE_CATEGORIES;
        $detect    = array();
        $vendor_of = array();
        $kinds     = array('handles' => 'handle', 'urls' => 'url', 'hosts' => 'host', 'inline' => 'inline', 'pixels' => 'pixel');
        foreach ($catalog as $key => $vendor) {
            $sig = (isset($vendor['signatures']) && is_array($vendor['signatures'])) ? $vendor['signatures'] : array();
            $cat = (isset($vendor['category']) && in_array($vendor['category'], $blockable, true)) ? $vendor['category'] : 'marketing';
            foreach ($kinds as $field => $kind) {
                foreach ((isset($sig[$field]) && is_array($sig[$field])) ? $sig[$field] : array() as $value) {
                    $id = $kind . ':' . $value;
                    if (TrackWP_Blocker_Rules::is_valid_rule_id($id)) {
                        $detect[$id]    = array('block' => true, 'vendor' => (string) $key, 'category' => $cat);
                        $vendor_of[$id] = (string) $key;
                    }
                }
            }
        }
        foreach ($admin as $id => $rule) {
            if (!TrackWP_Blocker_Rules::is_valid_rule_id($id) || !is_array($rule)) {
                continue;
            }
            if (!empty($rule['vendor']) && is_string($rule['vendor'])) {
                $vendor_of[$id] = $rule['vendor'];
            }
            $cat = (isset($rule['category']) && in_array($rule['category'], $blockable, true)) ? $rule['category']
                : (isset($detect[$id]) ? $detect[$id]['category'] : 'marketing');
            $detect[$id] = array('block' => true, 'vendor' => isset($vendor_of[$id]) ? $vendor_of[$id] : '', 'category' => $cat);
        }
        $allowed = array();
        foreach ($allow as $id) {
            if (is_string($id)) {
                $allowed[$id] = true;
            }
        }
        return array(
            'compiled'  => TrackWP_Blocker_Rules::compile(array('rules' => $detect), $catalog),
            'vendor_of' => $vendor_of,
            'rules'     => $admin,
            'allow'     => $allowed,
        );
    }

    /**
     * S3-normalized URL split into host (with non-default port) and path.
     *
     * @param string $url  Raw attribute value.
     * @param string $base Document base URL.
     * @return array|null {url, key, host, path}
     */
    private static function norm($url, $base) {
        $n   = TrackWP_Blocker_Rules::normalize_url($url, $base);
        $key = '' === $n ? '' : TrackWP_Blocker_Rules::url_key($n);
        if ('' === $key) {
            return null;
        }
        $p = strpos($key, '/');
        return array(
            'url'  => $n,
            'key'  => $key,
            'host' => false === $p ? $key : substr($key, 0, $p),
            'path' => false === $p ? '/' : substr($key, $p),
        );
    }

    /**
     * Whether the never list (KB8, S15, S18) covers a subject. Uses T2's
     * matcher itself on a probe rule set that has the real never lists and a
     * catch-all rule: the probe only misses when never wins.
     *
     * @param array $compiled compile() output.
     * @param array $subject  match() context.
     * @return bool
     */
    private static function is_never($compiled, $subject) {
        $catch = array('', 'probe', 'probe');
        $probe = array(
            'never'   => isset($compiled['never']) ? $compiled['never'] : array(),
            'handles' => array(),
            'urls'    => array($catch),
            'hosts'   => array(),
            'pixels'  => array($catch),
            'inline'  => array($catch),
        );
        $handle = isset($subject['handle']) ? (string) $subject['handle'] : '';
        if ('' !== $handle) {
            $probe['handles'][$handle] = array('probe', 'probe');
        }
        $has_url  = isset($subject['url']) && '' !== $subject['url'];
        $has_text = 'inline' === $subject['kind'] && isset($subject['text']) && '' !== $subject['text'];
        if (!$has_url && !$has_text && '' === $handle) {
            return false;
        }
        return null === TrackWP_Blocker_Rules::match($probe, $subject);
    }

    /**
     * One Tag Processor pass over a page.
     *
     * @param string $html     Page HTML.
     * @param string $page_url Page URL (document base unless <base href>).
     * @param array  $ctx      scan_context() output.
     * @return array {items, map, meta: string[], gtm: bool, blocked_ids: array<string,bool>}
     */
    public static function parse_page($html, $page_url, $ctx) {
        $map    = self::parse_scan_map($html);
        $out    = array('items' => array(), 'map' => $map, 'meta' => array(), 'gtm' => false, 'blocked_ids' => array());
        $base   = $page_url;
        $based  = false;
        $head   = true;
        $prio1  = false;
        $loads  = 0;
        $inits  = 0;
        $sgtm   = 0;
        $p      = new WP_HTML_Tag_Processor($html);

        while ($p->next_tag()) {
            $tag     = $p->get_tag();
            $blocked = $p->get_attribute('data-twp-blocked');
            if (is_string($blocked) && '' !== $blocked) {
                $out['blocked_ids'][$blocked] = true;
            }
            if ('BASE' === $tag && !$based) {
                $href = $p->get_attribute('href');
                if (is_string($href) && '' !== $href) {
                    $n = TrackWP_Blocker_Rules::normalize_url($href, $page_url);
                    if ('' !== $n) {
                        $base = $n;
                    }
                }
                $based = true;
                continue;
            }
            if ('BODY' === $tag) {
                $head = false;
                continue;
            }
            if ('SCRIPT' === $tag) {
                $type = $p->get_attribute('type');
                if (!TrackWP_Blocker_Rules::is_js_type($type)) {
                    continue;
                }
                $id     = $p->get_attribute('id');
                $id     = is_string($id) ? $id : '';
                $src    = $p->get_attribute('src');
                $src    = is_string($src) ? trim($src) : '';
                $text   = '' === $src ? (string) $p->get_modifiable_text() : '';
                $handle = '' !== $id ? (string) TrackWP_Blocker_Rules::handle_from_id($id) : '';

                if (!$prio1 && '' === $src && false !== strpos($text, self::PRIO1_MARKER)) {
                    $prio1 = true;
                    continue;
                }
                $haystack = '' !== $src ? $src : $text;
                if (false !== strpos($haystack, 'googletagmanager.com/gtm.js')) {
                    $out['gtm'] = true;
                }
                // S15: TrackWP's own Meta Pixel (id="trackwp-meta-pixel",
                // window.trackwpMeta=1) is never a foreign Meta source.
                $own = 'trackwp-meta-pixel' === $id || 0 === strpos($handle, 'trackwp-') || false !== strpos($text, 'trackwpMeta');
                if (!$own && false !== strpos($haystack, 'fbevents.js')) {
                    $loads++;
                } elseif (!$own && preg_match('/fbq\(\s*[\'"]init[\'"]/', $text)) {
                    $inits++;
                }
                if ('gtm-server-side' === $handle && '' !== $src) {
                    $sgtm++;
                }
                if ('' !== $src) {
                    $n = self::norm($src, $base);
                    if (null === $n) {
                        continue;
                    }
                    $subject = array('kind' => 'script', 'url' => $n['url'], 'handle' => $handle, 'id' => $id, 'text' => '');
                } else {
                    $n       = null;
                    $subject = array('kind' => 'inline', 'url' => '', 'handle' => $handle, 'id' => $id, 'text' => $text);
                }
                $item = self::observe($subject, $n, $ctx, $map, $head && !$prio1);
                if ($item) {
                    $out['items'][$item['obs_id']] = $item;
                }
                continue;
            }
            if ('IFRAME' === $tag || 'IMG' === $tag) {
                self::observe_media($out, 'IFRAME' === $tag ? 'iframe' : 'pixel', $p->get_attribute('src'), $base, $ctx);
                continue;
            }
            if ('NOSCRIPT' === $tag) {
                // Raw noscript content, if the processor exposes it as text.
                $inner = (string) $p->get_modifiable_text();
                if ('' !== $inner) {
                    $q = new WP_HTML_Tag_Processor($inner);
                    while ($q->next_tag('img')) {
                        self::observe_media($out, 'pixel', $q->get_attribute('src'), $base, $ctx);
                    }
                }
            }
        }
        // One occurrence per foreign pixel install: each script that loads
        // fbevents.js; an fbq('init') without a visible loader counts once
        // (fb4woo prints loader and init in separate scripts).
        $pixels       = $loads > 0 ? $loads : min(1, $inits);
        $out['meta']  = array_merge(array_fill(0, $pixels, 'pixel_script'), array_fill(0, $sgtm, 'gtm_server_side'));
        $out['items'] = array_values($out['items']);
        return $out;
    }

    /**
     * IFRAME (matched, or on a foreign host) and IMG (pixel rule only).
     */
    private static function observe_media(&$out, $kind, $src, $base, $ctx) {
        if (!is_string($src) || '' === trim($src)) {
            return;
        }
        $n = self::norm(trim($src), $base);
        if (null === $n) {
            return;
        }
        $subject = array('kind' => $kind, 'url' => $n['url'], 'handle' => '', 'id' => '', 'text' => '');
        $m       = TrackWP_Blocker_Rules::match($ctx['compiled'], $subject);
        $home    = TrackWP_Blocker_Rules::url_key(TrackWP_Blocker_Rules::home_origin());
        if (!$m && ('pixel' === $kind || $n['host'] === $home)) {
            return;
        }
        $item = self::observe($subject, $n, $ctx, array(), false);
        if ($item) {
            $out['items'][$item['obs_id']] = $item;
        }
    }

    /**
     * Build one KB3 item, or null when there is nothing to show (never-listed
     * without a KB4 status, or an anonymous unmatched inline script).
     *
     * @param array      $subject match() context.
     * @param array|null $n       norm() output for src, null for inline.
     * @param array      $ctx     scan_context() output.
     * @param array|null $map     Scan map by handle.
     * @param bool       $before  In head before TrackWP's prio-1 script.
     * @return array|null
     */
    private static function observe($subject, $n, $ctx, $map, $before) {
        $kind   = $subject['kind'];
        $handle = (string) $subject['handle'];
        $host   = $n ? $n['host'] : '';
        $path   = $n ? $n['path'] : '';
        $status = $n ? (string) TrackWP_Blocker_Rules::builtin_status($n['key']) : '';
        if ('' === $status && self::is_never($ctx['compiled'], $subject)) {
            return null;
        }
        $m      = '' === $status ? TrackWP_Blocker_Rules::match($ctx['compiled'], $subject) : null;
        $marker = '';
        if ($m && 0 === strpos($m['rule_id'], 'inline:')) {
            $marker = substr($m['rule_id'], 7);
        } elseif ('inline' === $kind && '' === $handle && preg_match(self::MARKER_RE, $subject['id'])) {
            $marker = $subject['id'];
        }
        if ('' !== $marker && !preg_match(self::MARKER_RE, $marker)) {
            $marker = '';
        }
        if ('inline' === $kind && !$m && '' === $handle && '' === $marker) {
            return null;
        }
        $rule_id  = $m ? (string) $m['rule_id'] : self::derive_rule_id($kind, $handle, $host, $path, $marker);
        $rule     = ('' !== $rule_id && isset($ctx['rules'][$rule_id]) && is_array($ctx['rules'][$rule_id])) ? $ctx['rules'][$rule_id] : array();
        $vendor   = ('' !== $rule_id && isset($ctx['vendor_of'][$rule_id])) ? $ctx['vendor_of'][$rule_id] : null;
        $category = !empty($rule['category']) ? (string) $rule['category'] : ($m ? (string) $m['category'] : '');
        if ('' === $status) {
            if ('' !== $rule_id && isset($ctx['allow'][$rule_id])) {
                $status = 'allowed';
            } elseif (!empty($rule['block'])) {
                $status = 'blocked';
            } elseif ($before) {
                $status = 'before_trackwp';
            } else {
                $status = 'allowed';
            }
        }
        $row    = ('' !== $handle && is_array($map) && isset($map[$handle])) ? $map[$handle] : null;
        $plugin = ($row && '' !== $row['plugin']) ? $row['plugin'] : self::plugin_slug($path);
        return array(
            'obs_id'         => TrackWP_Blocker_Rules::observation_id($kind, $host, $path, $handle, $marker),
            'rule_id'        => $rule_id,
            'kind'           => $kind,
            'host'           => $host,
            'path'           => $path,
            'handle'         => $handle,
            'plugin'         => $plugin,
            'deps'           => $row ? $row['deps'] : array(),
            'dependents'     => array(),
            'marker'         => $marker,
            'pages'          => array(),
            'vendor'         => $vendor,
            'category_guess' => $category,
            'status'         => $status,
            'seen_blocked'   => null,
        );
    }

    /**
     * KB2 rule id for an unmatched observation; '' when it cannot be valid.
     */
    private static function derive_rule_id($kind, $handle, $host, $path, $marker) {
        if ('' !== $handle) {
            $id = 'handle:' . $handle;
        } elseif ('inline' === $kind) {
            $id = '' !== $marker ? 'inline:' . $marker : '';
        } elseif ('pixel' === $kind) {
            $id = 'pixel:' . $host . $path;
        } else {
            $id = 'url:' . $host . $path;
        }
        return TrackWP_Blocker_Rules::is_valid_rule_id($id) ? $id : '';
    }

    /**
     * Plugin slug from /wp-content/plugins/<slug>/ (KB3).
     *
     * @param string $path URL path.
     * @return string
     */
    public static function plugin_slug($path) {
        $base = (string) wp_parse_url(plugins_url('/'), PHP_URL_PATH);
        $base = '/' . trim($base, '/') . '/';
        if ('' !== $path && 0 === strpos($path, $base)) {
            $rest = substr($path, strlen($base));
            $slug = strtok($rest, '/');
            if (false !== $slug && preg_match('/^[A-Za-z0-9._\-]+$/', $slug) && strlen($rest) > strlen($slug)) {
                return $slug;
            }
        }
        return '';
    }

    /**
     * server_cookie items (KC5). The cookie name is kept in 'handle'; its
     * value is never read.
     *
     * - rule_id is 'cookie:<name>' when the name is a valid cookie rule id,
     *   '' otherwise (KC5.1).
     * - category_guess is display-only, from
     *   TrackWP_Cookie_Scanner::known_cookies() (KC5.1).
     * - vendor is a TrackWP_Consent_Profile::vendor_catalog() key, or null
     *   when the match is not a cataloged vendor (e.g. PHPSESSID, WordPress,
     *   WooCommerce). Every other item kind already uses 'vendor' this way
     *   (blocker_vendors(), unblocked_vendors()); server_cookie must not be
     *   the exception, or a display name like "Webserver (PHP)" gets
     *   sanitize_key()'d into a phantom catalog entry downstream. The
     *   display text (provider/name) goes in 'vendor_label' instead.
     * - status is 'server_gated' when a compiled cookie: rule actually
     *   matches the name and the blocker mode is not 'off'; 'cannot_server'
     *   otherwise (KC5.2).
     *
     * @param string[] $names          Cookie names.
     * @param array    $cookie_compiled TrackWP_Blocker_Rules::compiled() output.
     * @param string   $mode           TrackWP_Blocker::mode() of the current option.
     * @return array[]
     */
    private static function server_cookie_items($names, array $cookie_compiled, $mode) {
        $home    = (string) wp_parse_url(home_url('/'), PHP_URL_HOST);
        $known   = class_exists('TrackWP_Cookie_Scanner') ? TrackWP_Cookie_Scanner::known_cookies() : array();
        $catalog = class_exists('TrackWP_Consent_Profile') ? TrackWP_Consent_Profile::vendor_catalog() : array();
        $rules   = (isset($cookie_compiled['cookies']) && is_array($cookie_compiled['cookies'])) ? $cookie_compiled['cookies'] : array();
        $items   = array();
        foreach ((array) $names as $raw_name) {
            $name    = (string) $raw_name;
            $rule_id = TrackWP_Blocker_Rules::is_valid_rule_id('cookie:' . $name) ? ('cookie:' . $name) : '';

            $category_guess = '';
            $vendor         = null;
            $vendor_label   = null;
            if (class_exists('TrackWP_Cookie_Scanner')) {
                foreach ($known as $k) {
                    if (isset($k['match']) && TrackWP_Cookie_Scanner::name_matches($name, $k['match'])) {
                        $category_guess = isset($k['category']) ? (string) $k['category'] : '';
                        $vendor_label   = isset($k['provider']) ? (string) $k['provider'] : (isset($k['name']) ? (string) $k['name'] : null);
                        if (isset($k['key']) && is_string($k['key']) && isset($catalog[$k['key']])) {
                            $vendor = $k['key'];
                        }
                        break;
                    }
                }
            }

            $gated = false;
            if ('' !== $rule_id && 'off' !== $mode && class_exists('TrackWP_Cookie_Scanner')) {
                foreach ($rules as $entry) {
                    if (isset($entry[0]) && TrackWP_Cookie_Scanner::name_matches($name, $entry[0])) {
                        $gated = true;
                        break;
                    }
                }
            }

            $items[] = array(
                'obs_id' => TrackWP_Blocker_Rules::observation_id('server_cookie', $home, '', $name, ''),
                'rule_id' => $rule_id, 'kind' => 'server_cookie', 'host' => $home, 'path' => '',
                'handle' => $name, 'plugin' => '', 'deps' => array(), 'dependents' => array(),
                'marker' => '', 'pages' => array(), 'vendor' => $vendor, 'vendor_label' => $vendor_label,
                'category_guess' => $category_guess,
                'status' => $gated ? 'server_gated' : 'cannot_server', 'seen_blocked' => null,
            );
        }
        return $items;
    }

    /**
     * One cannot_serverside line per found vendor with a server_side_note (KB5).
     *
     * @param array $items Items keyed by obs_id.
     * @return array[]
     */
    private static function server_side_items($items) {
        $catalog = class_exists('TrackWP_Consent_Profile') ? TrackWP_Consent_Profile::vendor_catalog() : array();
        $out     = array();
        foreach ($items as $item) {
            $v = $item['vendor'];
            if (null === $v || isset($out[$v]) || empty($catalog[$v]['server_side_note'])) {
                continue;
            }
            $out[$v] = array(
                'obs_id' => TrackWP_Blocker_Rules::observation_id('server_side', '', '', $v, ''),
                'rule_id' => '', 'kind' => 'server_side', 'host' => '', 'path' => '',
                'handle' => '', 'plugin' => '', 'deps' => array(), 'dependents' => array(),
                'marker' => '', 'pages' => array(), 'vendor' => $v,
                'category_guess' => isset($catalog[$v]['category']) ? (string) $catalog[$v]['category'] : '',
                'status' => 'cannot_serverside', 'seen_blocked' => null,
            );
        }
        return array_values($out);
    }

    /**
     * Merge an item into the scan, recording the page it was seen on.
     */
    private static function add_item(&$items, $item, $path) {
        $id = $item['obs_id'];
        if (!isset($items[$id])) {
            $items[$id] = $item;
        }
        if (!in_array($path, $items[$id]['pages'], true)) {
            $items[$id]['pages'][] = $path;
        }
    }

    /**
     * dependents = handles in the scan map whose deps include this item's
     * handle (information only, never blocked automatically).
     *
     * @param array $items    Items keyed by obs_id.
     * @param array $scan_map Merged scan maps by handle.
     */
    private static function apply_map_relations(&$items, $scan_map) {
        $by_handle = array();
        foreach ($items as $id => $item) {
            if ('' !== $item['handle'] && 'server_cookie' !== $item['kind']) {
                $by_handle[$item['handle']][] = $id;
            }
        }
        foreach ($scan_map as $handle => $row) {
            foreach ($row['deps'] as $dep) {
                foreach (isset($by_handle[$dep]) ? $by_handle[$dep] : array() as $target) {
                    if (!in_array((string) $handle, $items[$target]['dependents'], true)) {
                        $items[$target]['dependents'][] = (string) $handle;
                    }
                }
            }
        }
    }

    /**
     * Health check (§3.4.5): the front page as a plain visitor, no token and
     * no cache-bust. observed[rule_id] tells whether the rewritten markup was
     * observed in the HTML. Never presented as proof.
     *
     * @param array $rules KB1 rules.
     * @return array {checked_at, observed: array<string,bool>, error?: string}
     */
    public static function health_check($rules) {
        $health = array('checked_at' => time(), 'observed' => array());
        $fetch  = self::fetch(self::url_for_path('/'));
        if (is_wp_error($fetch)) {
            $health['error'] = $fetch->get_error_code();
            return $health;
        }
        $parsed = self::parse_page($fetch['body'], self::url_for_path('/'), self::scan_context(array('rules' => $rules)));
        foreach ($rules as $rule_id => $rule) {
            if (is_array($rule) && !empty($rule['block'])) {
                $health['observed'][(string) $rule_id] = isset($parsed['blocked_ids'][$rule_id]);
            }
        }
        foreach (array_keys($parsed['blocked_ids']) as $rule_id) {
            if (TrackWP_Blocker_Rules::is_valid_rule_id($rule_id)) {
                $health['observed'][$rule_id] = true;
            }
        }
        return $health;
    }

    /**
     * {code: count} to a list with one code per occurrence.
     *
     * @param array<string,int> $counts Counts.
     * @return string[]
     */
    private static function expand_counts($counts) {
        $out = array();
        foreach ($counts as $code => $n) {
            $out = array_merge($out, array_fill(0, (int) $n, (string) $code));
        }
        return $out;
    }

    /**
     * Whether TrackWP prints its own Meta Pixel. The one source is
     * TrackWP::client_meta_pixel_will_render() (same gate as
     * render_meta_pixel(), including GTM without M1).
     *
     * @return bool
     */
    public static function own_pixel_active() {
        return TrackWP::client_meta_pixel_will_render();
    }

    /**
     * Meta warning (REVIEW-RETTELSER-1.11.0 M1, S14). One translated label
     * per occurrence: TrackWP's own pixel when active, plus every foreign
     * source found in the local HTML. Server-side CAPI cannot be seen from
     * the HTML. Labels are plain text; the caller escapes on output.
     *
     * @param array $scan Saved scan (KB3).
     * @return array{show:bool, sources:string[]}
     */
    public static function meta_warning(array $scan) {
        $labels  = array(
            'pixel_script'    => __('Meta Pixel (fbevents.js) fra et andet plugin, tema eller script', 'trackwp'),
            'gtm_server_side' => __('GTM Server Side (Stape)', 'trackwp'),
        );
        $sources = array();
        if (self::own_pixel_active()) {
            $sources[] = __('TrackWP Meta Pixel', 'trackwp');
        }
        foreach (isset($scan['meta_sources']) ? (array) $scan['meta_sources'] : array() as $code) {
            if (is_string($code) && isset($labels[$code])) {
                $sources[] = $labels[$code];
            }
        }
        return array('show' => count($sources) > 1, 'sources' => $sources);
    }

    // -----------------------------------------------------------------
    // Dependencies
    // -----------------------------------------------------------------

    /**
     * @return bool TrackWP_Blocker_Rules (T2) exposes what the scanner uses.
     */
    public static function rules_available() {
        foreach (array('normalize_url', 'url_key', 'home_origin', 'handle_from_id', 'is_js_type', 'is_valid_rule_id', 'observation_id', 'builtin_status', 'compile', 'match') as $fn) {
            if (!is_callable(array('TrackWP_Blocker_Rules', $fn))) {
                return false;
            }
        }
        return true;
    }
}
