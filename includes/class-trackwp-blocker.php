<?php
/**
 * Server-side blocking until consent (PLAN-1.11.0-v2 §3.1, §3.3, KB9, KB12-KB14,
 * rettelser S1, S2, S5, S6, S9, S10, S12).
 *
 * Enqueued scripts are rewritten in `script_loader_tag` and
 * `wp_inline_script_attributes`; hardcoded HTML in our own output buffer.
 * Every path uses TrackWP_Blocker_Rules::match() and self::rewrite().
 *
 * Verified against WordPress 7.1 (wp-includes/html-api/class-wp-html-tag-processor.php):
 *  - S5: next_tag() on a SCRIPT opener consumes the whole element and
 *    get_modifiable_text() on that opener returns the script body ("They also
 *    contain the contents of SCRIPT and STYLE tags", @since 6.5.0, :3780-3806).
 *    The bookmark/next_token/seek fallback is therefore not needed; the
 *    rewrite test asserts inline markers are found this way.
 *  - S6: the Tag Processor runs with the scripting flag off and "will descend
 *    into NOSCRIPT elements and process its child tags" (:384-391). An IMG
 *    pixel inside NOSCRIPT is therefore visited as an ordinary tag and gets
 *    the KB9/S9 pixel attributes; no text replacement is needed.
 *
 * @package TrackWP
 */

if (!defined('ABSPATH')) {
    exit;
}

class TrackWP_Blocker {

    const STATUS_OPTION  = 'trackwp_blocker_status';
    const MIN_WP_VERSION = '6.5';
    const SCAN_HEADER    = 'HTTP_X_TRACKWP_SCAN';

    /** @var TrackWP_Blocker|null */
    private static $instance = null;

    /** @var bool Guards against ever running two buffers (§3.1). */
    private static $buffer_started = false;

    /** @var bool Blocking (rewriting) is active for this request. */
    private $active = false;

    /** @var bool Observe mode (KB13, S12): no rewriting, scan map appended. */
    private $observe = false;

    /** @var bool on_template_redirect() has decided this request. */
    private $decided = false;

    /** @var bool DONOTCACHEPAGE/nocache_headers() were issued for this request. */
    public $nocache = false;

    /** @var array|null */
    private $compiled = null;

    /** @var string Absolute URL of the requested page (base for relative URLs). */
    private $page_url = '';

    /**
     * T6 constructs this unconditionally on init; the class decides its hooks.
     */
    public function __construct() {
        self::$instance = $this;
        $this->register();
    }

    /**
     * Register hooks. Nothing is registered in mode off unless the request
     * carries a scan header (S12: observe mode also works in mode off).
     */
    public function register() {
        $opt  = self::option();
        $mode = self::mode($opt);
        if ('off' === $mode && '' === self::observe_header()) {
            return;
        }
        if (!self::html_api_available()) {
            if ('off' !== $mode) {
                self::record_status('no_html_api');
            }
            return;
        }
        add_action('template_redirect', array($this, 'on_template_redirect'), 9999);
        if ('off' !== $mode) {
            add_filter('script_loader_tag', array($this, 'filter_script_loader_tag'), 10, 3);
            add_filter('wp_inline_script_attributes', array($this, 'filter_inline_script_attributes'), 10, 2);
            $this->register_optimizer_exclusions();
        }
    }

    /**
     * Decide the request on template_redirect (prio 9999, S1) and start the buffer.
     */
    public function on_template_redirect() {
        $this->active   = false;
        $this->observe  = false;
        $this->compiled = null;
        $this->decided  = true;
        if (self::is_observe_request()) {
            // KB13/S12: no rewriting; the scanner has already sent
            // DONOTCACHEPAGE and nocache_headers() for this request.
            $this->observe = true;
            $this->active  = false;
            $this->start_buffer();
            return;
        }
        $opt  = self::option();
        $mode = self::mode($opt);
        if ('off' === $mode || !self::should_block_request($opt)) {
            return;
        }
        $compiled = TrackWP_Blocker_Rules::compiled();
        if (!TrackWP_Blocker_Rules::has_block_rules($compiled)) {
            return;
        }
        $this->compiled = $compiled;
        $this->active   = true;
        if ('test' === $mode) {
            $this->send_nocache();
        }
        $this->start_buffer();
    }

    /**
     * Whether blocking is active for the current request (T6: guard in the
     * prio 1 script and omission of the GTM noscript). False when the option
     * is missing. After template_redirect the instance's decision is
     * returned; before it (or without an instance) the same checks are
     * evaluated live: mode, KB12 context and at least one blocking rule.
     */
    public static function active_for_request() {
        $opt = self::option();
        if (null === $opt || 'off' === self::mode($opt) || !self::html_api_available()) {
            return false;
        }
        if (null !== self::$instance && self::$instance->decided) {
            return self::$instance->active;
        }
        if (self::is_observe_request() || !self::should_block_request($opt)) {
            return false;
        }
        return TrackWP_Blocker_Rules::has_block_rules(TrackWP_Blocker_Rules::compiled());
    }

    /**
     * Whether this request is an observe (scan) request.
     */
    public static function observing() {
        return null !== self::$instance && self::$instance->observe;
    }

    /**
     * The guard JS without <script> tags: window.trackwpBlockerConfig
     * (client_rules(), {v, home, urls, hosts, pixels, never}) followed by
     * assets/js/blocker-guard.min.js (fallback blocker-guard.js). '' when
     * neither file exists.
     */
    public static function guard_js() {
        $dir  = dirname(__DIR__) . '/assets/js/';
        $file = '';
        foreach (array('blocker-guard.min.js', 'blocker-guard.js') as $name) {
            if (is_readable($dir . $name)) {
                $file = $dir . $name;
                break;
            }
        }
        if ('' === $file) {
            return '';
        }
        $js = (string) file_get_contents($file);
        if ('' === trim($js)) {
            return '';
        }
        $rules = TrackWP_Blocker_Rules::client_rules(TrackWP_Blocker_Rules::compiled());
        $json  = wp_json_encode($rules, JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_SLASHES);
        return 'window.trackwpBlockerConfig=' . $json . ";\n" . $js;
    }

    /* ------------------------------------------------------------------ */
    /* Context (KB12)                                                      */
    /* ------------------------------------------------------------------ */

    /**
     * The stored option, or null when missing.
     */
    private static function option() {
        $opt = get_option(TrackWP_Blocker_Rules::OPTION);
        return is_array($opt) ? $opt : null;
    }

    public static function mode($opt) {
        $mode = (is_array($opt) && isset($opt['mode'])) ? $opt['mode'] : 'off';
        return in_array($mode, array('off', 'test', 'on'), true) ? $mode : 'off';
    }

    /**
     * WP >= 6.5 with get_modifiable_text() (KB12, §1). The one source for
     * this check: the scanner (T4) and settings (T5) call it (review M6).
     */
    public static function html_api_available(): bool {
        $version = isset($GLOBALS['wp_version']) ? (string) $GLOBALS['wp_version'] : '0';
        return version_compare($version, self::MIN_WP_VERSION, '>=')
            && class_exists('WP_HTML_Tag_Processor')
            && method_exists('WP_HTML_Tag_Processor', 'get_modifiable_text');
    }

    /**
     * KB12: whether this request may be blocked. Mode is checked by the caller,
     * except the test-mode capability.
     *
     * @param array|null $opt
     */
    public static function should_block_request($opt) {
        if (!self::html_api_available()) {
            return false;
        }
        if (is_admin() || wp_doing_ajax() || wp_doing_cron()
            || (defined('REST_REQUEST') && REST_REQUEST)
            || (function_exists('wp_is_serving_rest_request') && wp_is_serving_rest_request())
            || (defined('XMLRPC_REQUEST') && XMLRPC_REQUEST)) {
            return false;
        }
        if (is_feed() || is_robots() || is_trackback() || is_embed() || is_customize_preview()
            || '' !== (string) get_query_var('sitemap')) {
            return false;
        }
        $method = isset($_SERVER['REQUEST_METHOD']) ? strtoupper((string) $_SERVER['REQUEST_METHOD']) : 'GET';
        if ('GET' !== $method && 'HEAD' !== $method) {
            return false;
        }
        if (!self::response_is_html(headers_list())) {
            return false;
        }
        if (self::path_excepted($opt)) {
            return false;
        }
        if (self::editor_context()) {
            return false;
        }
        if ('test' === self::mode($opt) && !current_user_can('manage_options')) {
            return false;
        }
        return (bool) apply_filters('trackwp_blocker_should_run', true);
    }

    /**
     * S2: a missing Content-Type counts as HTML.
     *
     * @param string[] $headers headers_list() output.
     */
    public static function response_is_html(array $headers) {
        $type = null;
        foreach ($headers as $h) {
            if (0 === stripos((string) $h, 'content-type:')) {
                $type = strtolower(trim(substr($h, 13)));
            }
        }
        if (null === $type || '' === $type) {
            return true;
        }
        return 0 === strpos($type, 'text/html') || 0 === strpos($type, 'application/xhtml+xml');
    }

    /**
     * exceptions.paths: path prefixes (relative to the site path too).
     */
    public static function path_excepted($opt) {
        $paths = (is_array($opt) && isset($opt['exceptions']['paths']) && is_array($opt['exceptions']['paths'])) ? $opt['exceptions']['paths'] : array();
        if (empty($paths)) {
            return false;
        }
        $uri  = isset($_SERVER['REQUEST_URI']) ? (string) wp_unslash($_SERVER['REQUEST_URI']) : '/';
        $path = (string) wp_parse_url($uri, PHP_URL_PATH);
        $home = rtrim((string) wp_parse_url(home_url('/'), PHP_URL_PATH), '/');
        foreach ($paths as $p) {
            if (!is_string($p) || '' === $p || '/' !== $p[0]) {
                continue;
            }
            if (0 === strpos($path, $p) || ('' !== $home && 0 === strpos($path, $home . $p))) {
                return true;
            }
        }
        return false;
    }

    /**
     * KB12: page builders are exempt only with both a query flag and edit_posts.
     */
    private static function editor_context() {
        $flag = isset($_GET['elementor-preview']) || isset($_GET['et_fb']) || isset($_GET['fl_builder'])
            || isset($_GET['ct_builder']) || isset($_GET['breakdance'])
            || (isset($_GET['bricks']) && 'run' === $_GET['bricks']);
        return $flag && current_user_can('edit_posts');
    }

    private function send_nocache() {
        if (!defined('DONOTCACHEPAGE')) {
            define('DONOTCACHEPAGE', true);
        }
        nocache_headers();
        $this->nocache = true;
    }

    /* ------------------------------------------------------------------ */
    /* Observe mode (KB13, S12). Token and scan map owned by T4.          */
    /* ------------------------------------------------------------------ */

    private static function observe_header() {
        $v = isset($_SERVER[self::SCAN_HEADER]) ? (string) $_SERVER[self::SCAN_HEADER] : '';
        return preg_match('/^[a-f0-9]{32}$/', $v) ? $v : '';
    }

    /**
     * Observe request (KB13, S12), decided by the scanner (T4): one-time,
     * user-bound token. Fails closed when the scanner is unavailable.
     */
    private static function is_observe_request() {
        if (!class_exists('TrackWP_Blocker_Scanner') || !method_exists('TrackWP_Blocker_Scanner', 'is_observe_request')) {
            return false;
        }
        return true === TrackWP_Blocker_Scanner::is_observe_request();
    }

    /* ------------------------------------------------------------------ */
    /* Buffer (S1, S2, §3.1)                                               */
    /* ------------------------------------------------------------------ */

    /**
     * Start our own, non-flushable buffer (S1). Never two buffers.
     */
    public function start_buffer() {
        if (self::$buffer_started) {
            return false;
        }
        self::$buffer_started = true;
        $this->page_url       = self::current_page_url();
        return ob_start(array($this, 'handle_buffer'), 0, PHP_OUTPUT_HANDLER_STDFLAGS & ~PHP_OUTPUT_HANDLER_FLUSHABLE);
    }

    /**
     * Output handler. Only the final pass is processed; a clean pass (whose
     * output PHP discards) is returned untouched. Never echoes, never starts a buffer.
     */
    public function handle_buffer(string $buffer, int $phase) {
        if (!($phase & PHP_OUTPUT_HANDLER_FINAL) || ($phase & PHP_OUTPUT_HANDLER_CLEAN)) {
            return $buffer;
        }
        return $this->filter_html($buffer);
    }

    /**
     * Process a complete HTML response. On any failure the incoming HTML is
     * returned unchanged and KB14 is written.
     */
    public function filter_html($html) {
        if (!is_string($html) || '' === $html) {
            return $html;
        }
        try {
            if (!self::response_is_html(headers_list())) {
                return $html;
            }
            $trimmed = ltrim($html);
            if ('' === $trimmed || '<' !== $trimmed[0]) {
                return $html;
            }
            $max = (int) apply_filters('trackwp_blocker_max_bytes', 3 * 1024 * 1024);
            if (strlen($html) > $max) {
                self::record_status('too_large');
                return $html;
            }
            if ($this->observe) {
                return method_exists('TrackWP_Blocker_Scanner', 'inject_scan_map') ? (string) TrackWP_Blocker_Scanner::inject_scan_map($html) : $html;
            }
            if (!$this->active) {
                return $html;
            }
            $out = self::rewrite($html, $this->compiled, '' !== $this->page_url ? $this->page_url : home_url('/'));
            self::record_status('ok');
            return $out;
        } catch (\Throwable $e) {
            self::record_status('exception');
            return $html;
        }
    }

    private static function current_page_url() {
        $uri  = isset($_SERVER['REQUEST_URI']) ? (string) wp_unslash($_SERVER['REQUEST_URI']) : '/';
        $path = (string) wp_parse_url($uri, PHP_URL_PATH);
        return TrackWP_Blocker_Rules::home_origin() . ('' === $path ? '/' : $path);
    }

    /* ------------------------------------------------------------------ */
    /* Rewriting (KB9, S9, S10)                                            */
    /* ------------------------------------------------------------------ */

    /**
     * One pass over the markup. Tags already carrying data-twp-blocked are
     * skipped. SCRIPT (JS types only, S10) is matched by src or, without src,
     * by id-handle and inline text; IFRAME by url/host rules; IMG (also inside
     * NOSCRIPT, see the class docblock) by pixel rules. A <base href> changes
     * the base for later relative URLs (S3).
     *
     * @param string $html
     * @param array  $c           compile() output.
     * @param string $page_url    Absolute URL of the page.
     * @param string $handle_hint Handle for SCRIPT tags without a WordPress id (script_loader_tag).
     * @return string
     */
    public static function rewrite($html, array $c, $page_url, $handle_hint = '') {
        $p    = new WP_HTML_Tag_Processor($html);
        $home = isset($c['home']) ? $c['home'] : '';
        $base = $page_url;
        $base_seen = false;
        while ($p->next_tag()) {
            $tag = $p->get_tag();
            if ('BASE' === $tag) {
                $href = $p->get_attribute('href');
                if (!$base_seen && is_string($href) && '' !== trim($href)) {
                    $resolved = TrackWP_Blocker_Rules::normalize_url($href, $page_url, $home);
                    if ('' !== $resolved) {
                        $base = $resolved;
                    }
                    $base_seen = true;
                }
                continue;
            }
            if ('SCRIPT' !== $tag && 'IFRAME' !== $tag && 'IMG' !== $tag) {
                continue;
            }
            if (null !== $p->get_attribute('data-twp-blocked')) {
                continue;
            }
            $src = $p->get_attribute('src');
            $src = is_string($src) ? trim($src) : '';
            $url = '' !== $src ? TrackWP_Blocker_Rules::normalize_url($src, $base, $home) : '';

            if ('SCRIPT' === $tag) {
                $type = $p->get_attribute('type');
                if (!TrackWP_Blocker_Rules::is_js_type($type)) {
                    continue;
                }
                $id     = $p->get_attribute('id');
                $handle = TrackWP_Blocker_Rules::handle_from_id($id);
                if ('' === $handle && !is_string($id)) {
                    $handle = (string) $handle_hint;
                }
                $ctx = array('handle' => $handle, 'id' => is_string($id) ? $id : '');
                if ('' !== $src) {
                    $ctx['kind'] = 'script';
                    $ctx['url']  = $url;
                } else {
                    $ctx['kind'] = 'inline';
                    $ctx['text'] = $p->get_modifiable_text();
                }
                $m = TrackWP_Blocker_Rules::match($c, $ctx);
                if (null === $m) {
                    continue;
                }
                if (is_string($type) && '' !== trim($type)) {
                    $p->set_attribute('data-twp-type', $type);
                }
                $p->set_attribute('type', 'text/plain');
                self::mark($p, $m, $src);
                $p->set_attribute('data-no-optimize', '1');
                $p->set_attribute('data-no-defer', '1');
                continue;
            }

            if ('' === $url) {
                continue;
            }
            $m = TrackWP_Blocker_Rules::match($c, array('kind' => 'IFRAME' === $tag ? 'iframe' : 'pixel', 'url' => $url));
            if (null !== $m) {
                self::mark($p, $m, $src);
            }
        }
        return $p->get_updated_html();
    }

    /**
     * S9 common attributes; src moves to data-twp-src.
     */
    private static function mark(WP_HTML_Tag_Processor $p, array $m, $src) {
        $p->set_attribute('data-twp-blocked', $m['rule_id']);
        $p->set_attribute('data-twp-category', $m['category']);
        if ('' !== $src) {
            $p->set_attribute('data-twp-src', $src);
            $p->remove_attribute('src');
        }
    }

    /**
     * script_loader_tag: same rewrite as the buffer, on the tag fragment
     * (translations, -js-before, the tag and -js-after).
     */
    public function filter_script_loader_tag($tag, $handle, $src) {
        if (!$this->active || !is_string($tag) || '' === $tag) {
            return $tag;
        }
        try {
            return self::rewrite($tag, $this->compiled, '' !== $this->page_url ? $this->page_url : home_url('/'), (string) $handle);
        } catch (\Throwable $e) {
            self::record_status('exception');
            return $tag;
        }
    }

    /**
     * wp_inline_script_attributes ($attributes, $javascript): -js-extra,
     * -js-before and -js-after get the KB9 attributes when their handle
     * (S4, from $attributes['id']) or their text matches.
     */
    public function filter_inline_script_attributes($attributes, $javascript) {
        if (!$this->active || !is_array($attributes)) {
            return $attributes;
        }
        $type = isset($attributes['type']) ? $attributes['type'] : null;
        if (isset($attributes['data-twp-blocked']) || !TrackWP_Blocker_Rules::is_js_type($type)) {
            return $attributes;
        }
        $id = isset($attributes['id']) && is_string($attributes['id']) ? $attributes['id'] : '';
        $m  = TrackWP_Blocker_Rules::match($this->compiled, array(
            'kind'   => 'inline',
            'handle' => TrackWP_Blocker_Rules::handle_from_id($id),
            'id'     => $id,
            'text'   => (string) $javascript,
        ));
        if (null === $m) {
            return $attributes;
        }
        if (is_string($type) && '' !== trim($type)) {
            $attributes['data-twp-type'] = $type;
        }
        $attributes['type']              = 'text/plain';
        $attributes['data-twp-blocked']  = $m['rule_id'];
        $attributes['data-twp-category'] = $m['category'];
        $attributes['data-no-optimize']  = '1';
        $attributes['data-no-defer']     = '1';
        return $attributes;
    }

    /* ------------------------------------------------------------------ */
    /* Optimizer plugins (§3.3)                                            */
    /* ------------------------------------------------------------------ */

    /**
     * WP Rocket and LiteSpeed exclusion filters. Runs when mode is not off.
     */
    public function register_optimizer_exclusions() {
        $filters = array(
            'rocket_delay_js_exclusions', 'rocket_exclude_defer_js', 'rocket_defer_inline_exclusions',
            'rocket_exclude_js', 'rocket_excluded_inline_js_content',
            'litespeed_optimize_js_excludes', 'litespeed_optm_js_defer_exc', 'litespeed_optm_gm_js_exc',
        );
        foreach ($filters as $filter) {
            add_filter($filter, array(__CLASS__, 'add_optimizer_exclusions'));
        }
    }

    /**
     * @param mixed $list Array of patterns (both plugins pass arrays).
     * @return mixed
     */
    public static function add_optimizer_exclusions($list) {
        if (!is_array($list)) {
            return $list;
        }
        $plugin_path = (string) wp_parse_url(plugins_url('/', dirname(__FILE__)), PHP_URL_PATH);
        foreach (array('' !== $plugin_path ? $plugin_path : '/wp-content/plugins/trackwp/', 'trackwpConsentReader', 'trackwpBlocker') as $s) {
            if (!in_array($s, $list, true)) {
                $list[] = $s;
            }
        }
        return $list;
    }

    /* ------------------------------------------------------------------ */
    /* Operational status (KB14 without buffer_not_started, S1)            */
    /* ------------------------------------------------------------------ */

    /**
     * Write trackwp_blocker_status {last_ok, last_error:{ts,code}, codes:{code:ts}}
     * at most once per hour per code ('ok' updates last_ok).
     *
     * @param string $code 'ok', 'exception', 'too_large' or 'no_html_api'.
     */
    public static function record_status($code) {
        $s   = get_option(self::STATUS_OPTION);
        $s   = is_array($s) ? $s : array();
        $now = time();
        if ('ok' === $code) {
            if (isset($s['last_ok']) && $now - (int) $s['last_ok'] < HOUR_IN_SECONDS) {
                return;
            }
            $s['last_ok'] = $now;
        } else {
            if (isset($s['codes'][$code]) && $now - (int) $s['codes'][$code] < HOUR_IN_SECONDS) {
                return;
            }
            $s['codes']         = isset($s['codes']) && is_array($s['codes']) ? $s['codes'] : array();
            $s['codes'][$code]  = $now;
            $s['last_error']    = array('ts' => $now, 'code' => $code);
        }
        update_option(self::STATUS_OPTION, $s, false);
    }
}
