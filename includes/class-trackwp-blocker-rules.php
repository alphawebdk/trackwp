<?php
/**
 * Blocker rules: URL normalization, compilation and matching (KB2, KB6-KB8,
 * PLAN-1.11.0-v2 §11 S3, S4, S10, S11, S16, S18).
 *
 * This is the ONE PHP matcher. `script_loader_tag`, `wp_inline_script_attributes`,
 * the output buffer (TrackWP_Blocker) and the scanner all call match(). The
 * browser guard (assets/js/blocker-guard.js) implements the same normalization
 * and matching order on the subset returned by client_rules(); both are held
 * together by tests/fixtures/blocker/url-vectors.json.
 *
 * @package TrackWP
 */

if (!defined('ABSPATH')) {
    exit;
}

class TrackWP_Blocker_Rules {

    const OPTION          = 'trackwp_blocker';
    const COMPILED_OPTION = 'trackwp_blocker_compiled';
    const RULE_ID_REGEX   = '#^(handle|url|host|inline|pixel):[A-Za-z0-9._/\-]{3,200}$#';

    /**
     * 1.11.1 KC2: explicit server-cookie rules. The pattern is a literal
     * cookie name, optionally with a trailing '*' meaning "starts with".
     */
    const COOKIE_RULE_ID_REGEX = '#^cookie:[A-Za-z0-9._\-]{2,128}\*?$#';

    /** Categories a rule may block. 'necessary' is never blocked (it would never be released). */
    const BLOCKABLE_CATEGORIES = array('statistics', 'marketing', 'personalisation');

    /**
     * Whether a rule id has the KB2 shape.
     *
     * @param mixed $id
     * @return bool
     */
    public static function is_valid_rule_id($id) {
        return is_string($id) && (1 === preg_match(self::RULE_ID_REGEX, $id) || 1 === preg_match(self::COOKIE_RULE_ID_REGEX, $id));
    }

    /**
     * Observation id (KB2): only used in scan results, never for rules.
     */
    public static function observation_id($kind, $host, $path, $handle, $marker) {
        return substr(sha1($kind . '|' . $host . '|' . $path . '|' . $handle . '|' . $marker), 0, 12);
    }

    /**
     * S4: handle from a WordPress script id. The longest suffix is stripped
     * first (-js-extra, -js-before, -js-after), then -js. Returns '' when the
     * id carries none of the suffixes.
     *
     * @param mixed $id
     * @return string
     */
    public static function handle_from_id($id) {
        if (!is_string($id) || '' === $id) {
            return '';
        }
        foreach (array('-js-extra', '-js-before', '-js-after', '-js') as $suffix) {
            $len = strlen($suffix);
            if (strlen($id) > $len && substr($id, -$len) === $suffix) {
                return substr($id, 0, -$len);
            }
        }
        return '';
    }

    /**
     * S10: whether a script type attribute makes the browser execute the
     * script as JavaScript. Missing/empty type, the WHATWG "JavaScript MIME
     * type" essences (case-insensitive, parameters ignored) and "module".
     * See https://html.spec.whatwg.org/multipage/scripting.html#prepare-the-script-element
     * and https://mimesniff.spec.whatwg.org/#javascript-mime-type
     *
     * @param mixed $type null (absent), true (boolean attribute) or string.
     * @return bool
     */
    public static function is_js_type($type) {
        if (null === $type || true === $type) {
            return true;
        }
        $type = strtolower(trim((string) $type, " \t\n\f\r"));
        if ('' === $type || 'module' === $type) {
            return true;
        }
        $essence = trim(explode(';', $type, 2)[0], " \t\n\f\r");
        return in_array($essence, array(
            'application/ecmascript', 'application/javascript', 'application/x-ecmascript',
            'application/x-javascript', 'text/ecmascript', 'text/javascript', 'text/javascript1.0',
            'text/javascript1.1', 'text/javascript1.2', 'text/javascript1.3', 'text/javascript1.4',
            'text/javascript1.5', 'text/jscript', 'text/livescript', 'text/x-ecmascript',
            'text/x-javascript',
        ), true);
    }

    /**
     * Origin of home_url(): scheme://host[:port] (default port dropped).
     */
    public static function home_origin() {
        $n = self::normalize_url(home_url('/'), '', 'https://localhost');
        if ('' === $n) {
            return 'https://localhost';
        }
        $p = strpos($n, '/', strpos($n, '//') + 2);
        return false === $p ? $n : substr($n, 0, $p);
    }

    /**
     * S3 URL normalization (identical in the guard):
     *  - `//host/path` keeps the host and gets the home scheme,
     *  - absolute http(s) URLs are used as they are,
     *  - `/path` resolves against the home origin,
     *  - other relative URLs resolve against $base (page URL or <base href>),
     *  - host lower-cased, userinfo and default port dropped, query and
     *    fragment dropped, dot segments removed, path case preserved.
     * WP_Http::make_absolute_url is deliberately not used (S3).
     *
     * @param string $url  Raw attribute value.
     * @param string $base Absolute document base URL ('' = home).
     * @param string $home Home URL/origin ('' = home_url()).
     * @return string scheme://host[:port]/path, or '' for non-http(s) URLs.
     */
    public static function normalize_url($url, $base = '', $home = '') {
        $url = trim((string) $url, " \t\n\f\r");
        if ('' === $url) {
            return '';
        }
        if ('' === $home) {
            $home = self::home_origin();
        }
        $home_parts = self::split_absolute($home);
        if (null === $home_parts) {
            return '';
        }
        if (preg_match('#^([A-Za-z][A-Za-z0-9+.\-]*):#', $url, $m)) {
            $parts = self::split_absolute($url);
        } elseif (0 === strpos($url, '//')) {
            $parts = self::split_absolute($home_parts['scheme'] . ':' . $url);
        } elseif ('/' === $url[0]) {
            $parts = self::split_absolute(self::join_origin($home_parts) . $url);
        } else {
            $base_parts = ('' !== $base) ? self::split_absolute($base) : null;
            if (null === $base_parts) {
                $base_parts = $home_parts;
            }
            $ref = preg_replace('/[?#].*$/s', '', $url);
            if ('' === $ref) {
                $path = $base_parts['path'];
            } else {
                $dir  = substr($base_parts['path'], 0, strrpos($base_parts['path'], '/') + 1);
                $path = $dir . $ref;
            }
            $parts = self::split_absolute(self::join_origin($base_parts) . $path);
        }
        if (null === $parts) {
            return '';
        }
        return self::join_origin($parts) . $parts['path'];
    }

    /**
     * The match key of a normalized URL: host[:port]/path (no scheme).
     */
    public static function url_key($normalized) {
        $p = strpos((string) $normalized, '://');
        return false === $p ? '' : substr($normalized, $p + 3);
    }

    /**
     * Split an absolute http(s) URL. Returns null for anything else.
     *
     * @return array{scheme:string,host:string,port:string,path:string}|null
     */
    private static function split_absolute($url) {
        if (!preg_match('#^([A-Za-z][A-Za-z0-9+.\-]*)://([^/?\#]*)([^?\#]*)#', $url, $m)) {
            return null;
        }
        $scheme = strtolower($m[1]);
        if ('http' !== $scheme && 'https' !== $scheme) {
            return null;
        }
        $authority = $m[2];
        $at        = strrpos($authority, '@');
        if (false !== $at) {
            $authority = substr($authority, $at + 1);
        }
        $port = '';
        if (preg_match('#^(\[[^\]]*\]|[^:]*)(?::(\d*))?$#', $authority, $a)) {
            $host = $a[1];
            $port = isset($a[2]) ? ltrim($a[2], '0') : '';
        } else {
            return null;
        }
        $host = strtolower($host);
        if ('' === $host) {
            return null;
        }
        if (('http' === $scheme && '80' === $port) || ('https' === $scheme && '443' === $port)) {
            $port = '';
        }
        return array(
            'scheme' => $scheme,
            'host'   => $host,
            'port'   => $port,
            'path'   => self::remove_dot_segments('' === $m[3] ? '/' : $m[3]),
        );
    }

    private static function join_origin(array $p) {
        return $p['scheme'] . '://' . $p['host'] . ('' !== $p['port'] ? ':' . $p['port'] : '');
    }

    /**
     * RFC 3986 §5.2.4 for a path that starts with '/'.
     */
    private static function remove_dot_segments($path) {
        if ('' === $path || '/' !== $path[0]) {
            $path = '/' . $path;
        }
        $in  = explode('/', substr($path, 1));
        $out = array();
        $n   = count($in);
        foreach ($in as $i => $seg) {
            $last = ($i === $n - 1);
            if ('.' === $seg) {
                if ($last) {
                    $out[] = '';
                }
            } elseif ('..' === $seg) {
                array_pop($out);
                if ($last) {
                    $out[] = '';
                }
            } else {
                $out[] = $seg;
            }
        }
        return '/' . implode('/', $out);
    }

    /**
     * Path of a WordPress URL helper, with a trailing slash.
     */
    private static function url_path($url, $fallback) {
        $path = is_string($url) ? (string) wp_parse_url($url, PHP_URL_PATH) : '';
        return '' === $path ? $fallback : trailingslashit($path);
    }

    /**
     * Built-in lists (KB8 with S15 and S18). 'never' is never blocked;
     * 'status' only classifies scan findings for the scanner/admin.
     *
     * @return array{never:array,status:array}
     */
    public static function builtin_lists() {
        $plugins  = self::url_path(function_exists('plugins_url') ? plugins_url() : '', '/wp-content/plugins/');
        $content  = self::url_path(function_exists('content_url') ? content_url() : '', '/wp-content/');
        $includes = self::url_path(function_exists('includes_url') ? includes_url() : '', '/wp-includes/');
        $rest     = self::url_path(function_exists('rest_url') ? rest_url() : '', '/wp-json/');

        $gtm_urls  = array('www.googletagmanager.com/gtm.js', 'www.googletagmanager.com/gtag/js');
        $gtm_paths = array($rest . 'trackwp/v1/loader');
        $bundled   = array($content . 'cache/', $content . 'litespeed/', $content . 'cache/autoptimize/');

        $never = array(
            'handles'         => array(
                'jquery', 'jquery-core', 'jquery-migrate', 'wp-polyfill', 'wp-hooks', 'wp-i18n',
                'woocommerce', 'wc-add-to-cart', 'wc-add-to-cart-variation', 'wc-cart-fragments', 'wc-cart',
                'wc-checkout', 'wc-country-select', 'wc-address-i18n', 'wc-jquery-blockui', 'wc-js-cookie',
                'selectWoo',
            ),
            'handle_prefixes' => array('trackwp-'),
            'ids'             => array('trackwp-meta-pixel'),
            'paths'           => array_merge(array(
                $plugins . 'trackwp/',
                $plugins . basename(dirname(__DIR__)) . '/',
                $includes . 'js/',
                $plugins . 'woocommerce/assets/client/blocks/',
                $plugins . 'contact-form-7/',
                $plugins . 'wpforms/',
                $plugins . 'wpforms-lite/',
                $plugins . 'gravityforms/',
                $plugins . 'fluentform/',
            ), $gtm_paths, $bundled),
            'urls'            => array_merge(array(
                'www.google.com/recaptcha/',
                'www.gstatic.com/recaptcha/',
                'www.recaptcha.net/recaptcha/',
                'challenges.cloudflare.com/turnstile/',
            ), $gtm_urls),
            'hosts'           => array(),
            'inline'          => array('trackwpConsentReader', 'trackwpConfig', 'trackwpConsentConfig', 'trackwpWoo', 'trackwpBlocker', 'trackwpMeta'),
        );
        $status = array(
            'gtm_consent_mode' => array('urls' => $gtm_urls, 'paths' => $gtm_paths, 'hosts' => array()),
            'cannot_bundled'   => array('urls' => array(), 'paths' => $bundled, 'hosts' => array()),
            'protected'        => array(
                'urls'  => array('www.paypal.com/sdk'),
                'paths' => array(),
                'hosts' => array('js.stripe.com', 'quickpay.net', 'nets.eu', 'klarna.com', 'x.klarnacdn.net', 'mobilepay.dk', 'checkout.reepay.com', 'frisbii.com', 'my.anyday.io'),
            ),
        );
        return array('never' => $never, 'status' => $status);
    }

    /**
     * KB4 status that follows from the built-in lists, or '' when none does.
     * For the scanner: 'gtm_consent_mode', 'cannot_bundled' or 'protected'.
     *
     * @param string $key Match key (host[:port]/path) from url_key().
     */
    public static function builtin_status($key) {
        list($host, $path) = self::split_key($key);
        if ('' === $host) {
            return '';
        }
        $home_host = self::url_key(self::home_origin());
        foreach (self::builtin_lists()['status'] as $status => $l) {
            if (self::list_hits($l, $key, $host, $path, $home_host)) {
                return $status;
            }
        }
        return '';
    }

    /**
     * KB7: compile catalog + rules + exceptions.
     *
     * Rules with block=true and a blockable category are compiled. For every
     * catalog vendor with at least one blocked rule, its catalog url/host/pixel
     * signatures are added too (network-level, so the guard catches the same
     * vendor loaded dynamically), unless the admin has an explicit rule or an
     * exception for that id. exceptions.allow (S16: prefixed rule ids only)
     * is added to the never lists, so it wins exactly like never (KB6, S11).
     *
     * @param array      $settings The trackwp_blocker option (may be partial).
     * @param array|null $catalog  vendor_catalog() shape; null = the real catalog
     *                             (the only value production code passes).
     * @return array
     */
    public static function compile(array $settings, $catalog = null) {
        if (null === $catalog) {
            $catalog = class_exists('TrackWP_Consent_Profile') ? TrackWP_Consent_Profile::vendor_catalog() : array();
        }
        $catalog  = is_array($catalog) ? $catalog : array();
        $rules    = (isset($settings['rules']) && is_array($settings['rules'])) ? $settings['rules'] : array();
        $allow    = (isset($settings['exceptions']['allow']) && is_array($settings['exceptions']['allow'])) ? $settings['exceptions']['allow'] : array();

        $out = array(
            'v'       => 1,
            'ver'     => defined('TRACKWP_VERSION') ? TRACKWP_VERSION : '',
            'home'    => self::home_origin(),
            'handles' => array(),
            'urls'    => array(),
            'hosts'   => array(),
            'pixels'  => array(),
            'inline'  => array(),
            'cookies' => array(),
            'never'   => self::builtin_lists()['never'],
        );
        if (!isset($out['never']['cookies']) || !is_array($out['never']['cookies'])) {
            $out['never']['cookies'] = array();
        }

        $allowed = array();
        foreach ($allow as $id) {
            if (!self::is_valid_rule_id($id)) {
                continue;
            }
            $allowed[$id] = true;
            list($kind, $value) = explode(':', $id, 2);
            switch ($kind) {
                case 'handle':
                    $out['never']['handles'][] = $value;
                    break;
                case 'host':
                    $out['never']['hosts'][] = strtolower($value);
                    break;
                case 'inline':
                    $out['never']['inline'][] = $value;
                    break;
                case 'cookie':
                    $out['never']['cookies'][] = $value;
                    break;
                default: // url, pixel
                    $out['never']['urls'][] = self::lower_host($value);
            }
        }

        $vendors = array();
        foreach ($rules as $id => $r) {
            if (!self::is_valid_rule_id($id) || isset($allowed[$id]) || !is_array($r) || empty($r['block'])) {
                continue;
            }
            $vendor = isset($r['vendor']) && is_string($r['vendor']) ? $r['vendor'] : '';
            $cat    = isset($r['category']) ? $r['category'] : '';
            // An explicit 'necessary' is never blocked, whatever the input
            // (e.g. an import), and never falls back to the catalog category.
            if ('necessary' === $cat) {
                continue;
            }
            if (!in_array($cat, self::BLOCKABLE_CATEGORIES, true) && isset($catalog[$vendor]['category'])) {
                $cat = $catalog[$vendor]['category'];
            }
            if (!in_array($cat, self::BLOCKABLE_CATEGORIES, true)) {
                continue;
            }
            self::add_rule($out, $id, $cat);
            // KC2: a cookie: rule's vendor is display-only. It never pulls in
            // that vendor's url/host/pixel signatures (no catalog tokens from
            // a cookie rule).
            $kind = strstr($id, ':', true);
            if ('cookie' !== $kind && '' !== $vendor && isset($catalog[$vendor]) && !isset($vendors[$vendor])) {
                $vendors[$vendor] = $cat;
            }
        }

        foreach ($vendors as $vendor => $rule_cat) {
            $sig = isset($catalog[$vendor]['signatures']) && is_array($catalog[$vendor]['signatures']) ? $catalog[$vendor]['signatures'] : array();
            $cat = (isset($catalog[$vendor]['category']) && in_array($catalog[$vendor]['category'], self::BLOCKABLE_CATEGORIES, true)) ? $catalog[$vendor]['category'] : $rule_cat;
            foreach (array('urls' => 'url', 'hosts' => 'host', 'pixels' => 'pixel') as $field => $kind) {
                foreach ((isset($sig[$field]) && is_array($sig[$field])) ? $sig[$field] : array() as $value) {
                    $id = $kind . ':' . $value;
                    if (self::is_valid_rule_id($id) && !isset($rules[$id]) && !isset($allowed[$id])) {
                        self::add_rule($out, $id, $cat);
                    }
                }
            }
        }

        foreach (array('urls', 'pixels', 'inline') as $k) {
            usort($out[$k], array(__CLASS__, 'by_length_desc'));
        }
        usort($out['cookies'], array(__CLASS__, 'by_cookie_priority'));
        ksort($out['handles']);
        ksort($out['hosts']);
        foreach ($out['never'] as $k => $list) {
            $out['never'][$k] = array_values(array_unique($list));
        }
        return $out;
    }

    private static function add_rule(array &$out, $id, $cat) {
        list($kind, $value) = explode(':', $id, 2);
        switch ($kind) {
            case 'handle':
                $out['handles'][$value] = array($cat, $id);
                break;
            case 'host':
                $out['hosts'][strtolower($value)] = array($cat, $id);
                break;
            case 'inline':
                $out['inline'][] = array($value, $cat, $id);
                break;
            case 'cookie':
                $out['cookies'][] = array($value, $cat, $id);
                break;
            case 'url':
            case 'pixel':
                // A url/pixel rule must carry a path, otherwise it is a host rule.
                $value = self::lower_host($value);
                if (false === strpos($value, '/')) {
                    $out['hosts'][$value] = array($cat, $id);
                } else {
                    $out['url' === $kind ? 'urls' : 'pixels'][] = array($value, $cat, $id);
                }
                break;
        }
    }

    private static function lower_host($value) {
        $p = strpos($value, '/');
        return false === $p ? strtolower($value) : strtolower(substr($value, 0, $p)) . substr($value, $p);
    }

    /** Longest first; ties by string so the order is stable. */
    public static function by_length_desc($a, $b) {
        $d = strlen($b[0]) - strlen($a[0]);
        return 0 !== $d ? $d : strcmp($a[0], $b[0]);
    }

    /**
     * KC2 sort for compiled cookie rules: exact names first, then prefixes
     * (trailing '*'), longest prefix first, alphabetical at equal length.
     */
    public static function by_cookie_priority($a, $b) {
        $a_prefix = '*' === substr($a[0], -1);
        $b_prefix = '*' === substr($b[0], -1);
        if ($a_prefix !== $b_prefix) {
            return $a_prefix ? 1 : -1;
        }
        if ($a_prefix) {
            $d = strlen(rtrim($b[0], '*')) - strlen(rtrim($a[0], '*'));
            if (0 !== $d) {
                return $d;
            }
        }
        return strcmp($a[0], $b[0]);
    }

    /**
     * Compiled rules from the autoloaded cache, recompiled when the cache
     * is from another plugin version or home origin.
     */
    public static function compiled() {
        $c = get_option(self::COMPILED_OPTION);
        if (!is_array($c) || !isset($c['v'], $c['ver'], $c['home']) || 1 !== $c['v']
            || $c['ver'] !== (defined('TRACKWP_VERSION') ? TRACKWP_VERSION : '') || $c['home'] !== self::home_origin()) {
            $c = self::rebuild();
        }
        return $c;
    }

    /**
     * Compile and store (KB7). Hooked by T6 on update_option_trackwp_blocker.
     */
    public static function rebuild() {
        $opt = get_option(self::OPTION, array());
        $c   = self::compile(is_array($opt) ? $opt : array());
        update_option(self::COMPILED_OPTION, $c, true);
        return $c;
    }

    /**
     * Whether the compiled rules block anything at all.
     */
    public static function has_block_rules(array $c) {
        foreach (array('handles', 'urls', 'hosts', 'pixels', 'inline') as $k) {
            if (!empty($c[$k])) {
                return true;
            }
        }
        return false;
    }

    /**
     * KC2: whether any explicit cookie: rule is compiled. Cookie rules never
     * start the HTML output buffer (has_block_rules() stays as it was), this
     * only gates the server cookie gate (KC3).
     */
    public static function has_cookie_rules(array $c) {
        return !empty($c['cookies']);
    }

    /**
     * The part the browser guard needs (KB7, S11): url/host/pixel rules and
     * the never/allow entries that can match a URL, plus the home origin for
     * S3 normalization. Matching order is the same as match().
     */
    public static function client_rules(array $c) {
        return array(
            'v'      => 1,
            'home'   => isset($c['home']) ? $c['home'] : self::home_origin(),
            'urls'   => isset($c['urls']) ? $c['urls'] : array(),
            'hosts'  => isset($c['hosts']) ? (object) $c['hosts'] : new stdClass(),
            'pixels' => isset($c['pixels']) ? $c['pixels'] : array(),
            'never'  => array(
                'paths' => isset($c['never']['paths']) ? $c['never']['paths'] : array(),
                'urls'  => isset($c['never']['urls']) ? $c['never']['urls'] : array(),
                'hosts' => isset($c['never']['hosts']) ? $c['never']['hosts'] : array(),
            ),
        );
    }

    /**
     * KB6 matcher. Order: never and exceptions, handle:, url:/pixel:
     * (longest prefix), host: (most specific host), inline: (scripts
     * without src only).
     *
     * @param array $c   compile() output.
     * @param array $ctx kind ('script'|'inline'|'iframe'|'pixel'), url (normalized
     *                   by normalize_url()), handle, id, text.
     * @return array{rule_id:string,category:string}|null
     */
    public static function match(array $c, array $ctx) {
        $kind   = isset($ctx['kind']) ? $ctx['kind'] : 'script';
        $handle = isset($ctx['handle']) ? (string) $ctx['handle'] : '';
        $text   = ('inline' === $kind && isset($ctx['text'])) ? (string) $ctx['text'] : '';
        $key    = isset($ctx['url']) ? self::url_key($ctx['url']) : '';
        list($host, $path) = self::split_key($key);

        // 1. never and exceptions.
        if (self::is_never($c, $ctx)) {
            return null;
        }

        // 2. handle.
        if ('' !== $handle && ('script' === $kind || 'inline' === $kind) && isset($c['handles'][$handle])) {
            return self::hit($c['handles'][$handle]);
        }

        if ('' !== $host) {
            // 3. url (script, iframe) or pixel (img) prefixes; longest first.
            $list = ('pixel' === $kind) ? 'pixels' : (('script' === $kind || 'iframe' === $kind) ? 'urls' : '');
            if ('' !== $list && !empty($c[$list])) {
                foreach ($c[$list] as $r) {
                    if (0 === strpos($key, $r[0])) {
                        return array('rule_id' => $r[2], 'category' => $r[1]);
                    }
                }
            }
            // 4. host with domain boundary; the most specific host wins.
            if (('script' === $kind || 'iframe' === $kind) && !empty($c['hosts'])) {
                $h = self::strip_port($host);
                while ('' !== $h) {
                    if (isset($c['hosts'][$h])) {
                        return self::hit($c['hosts'][$h]);
                    }
                    $dot = strpos($h, '.');
                    $h   = false === $dot ? '' : substr($h, $dot + 1);
                }
            }
        }

        // 5. inline markers (scripts without src only).
        if ('' !== $text && !empty($c['inline'])) {
            foreach ($c['inline'] as $r) {
                if (false !== strpos($text, $r[0])) {
                    return array('rule_id' => $r[2], 'category' => $r[1]);
                }
            }
        }
        return null;
    }

    /**
     * Whether the never list or an exception (exceptions.allow) covers the
     * subject. match() returns null both for "never" and for "no rule";
     * this tells them apart (for the scanner).
     *
     * @param array $c   compile() output.
     * @param array $ctx Same keys as match().
     * @return bool
     */
    public static function is_never(array $c, array $ctx) {
        $kind   = isset($ctx['kind']) ? $ctx['kind'] : 'script';
        $handle = isset($ctx['handle']) ? (string) $ctx['handle'] : '';
        $text   = ('inline' === $kind && isset($ctx['text'])) ? (string) $ctx['text'] : '';
        $key    = isset($ctx['url']) ? self::url_key($ctx['url']) : '';
        list($host, $path) = self::split_key($key);
        $never  = isset($c['never']) ? $c['never'] : array();

        if (isset($ctx['id']) && !empty($never['ids']) && in_array($ctx['id'], $never['ids'], true)) {
            return true;
        }
        if ('' !== $handle) {
            if (!empty($never['handles']) && in_array($handle, $never['handles'], true)) {
                return true;
            }
            foreach (isset($never['handle_prefixes']) ? $never['handle_prefixes'] : array() as $pre) {
                if (0 === strpos($handle, $pre)) {
                    return true;
                }
            }
        }
        $home_host = self::url_key(isset($c['home']) ? $c['home'] : self::home_origin());
        if ('' !== $host && self::list_hits($never, $key, $host, $path, $home_host)) {
            return true;
        }
        if ('' !== $text) {
            foreach (isset($never['inline']) ? $never['inline'] : array() as $marker) {
                if (false !== strpos($text, $marker)) {
                    return true;
                }
            }
        }
        return false;
    }

    private static function hit($entry) {
        return array('rule_id' => $entry[1], 'category' => $entry[0]);
    }

    /**
     * Whether a key hits a {paths, urls, hosts} list. Paths match only on
     * the site's own host (KB8: "på egen host"); $home_host is host[:port]
     * of the home origin.
     */
    private static function list_hits(array $l, $key, $host, $path, $home_host) {
        if ($host === $home_host) {
            foreach (isset($l['paths']) ? $l['paths'] : array() as $p) {
                if (0 === strpos($path, $p)) {
                    return true;
                }
            }
        }
        foreach (isset($l['urls']) ? $l['urls'] : array() as $u) {
            if (0 === strpos($key, $u)) {
                return true;
            }
        }
        $h = self::strip_port($host);
        foreach (isset($l['hosts']) ? $l['hosts'] : array() as $r) {
            if ($h === $r || substr($h, -strlen($r) - 1) === '.' . $r) {
                return true;
            }
        }
        return false;
    }

    private static function split_key($key) {
        if ('' === $key) {
            return array('', '');
        }
        $p = strpos($key, '/');
        return false === $p ? array($key, '/') : array(substr($key, 0, $p), substr($key, $p));
    }

    private static function strip_port($host) {
        if ('' !== $host && '[' === $host[0]) {
            $end = strpos($host, ']');
            return false === $end ? $host : substr($host, 0, $end + 1);
        }
        $p = strpos($host, ':');
        return false === $p ? $host : substr($host, 0, $p);
    }
}
