<?php
/**
 * T2: server rewrite (KB9, S5, S6, S9, S10, S18, §3.1 filter_html).
 *
 * Producers: tests/fixtures/blocker/adashofmagic-home.html (saved real
 * response, T0), TrackWP_Consent_Profile::vendor_catalog() signatures (the
 * rules are what "Bloker alle kendte trackere" would store for the scanned
 * vendors), TrackWP_Blocker_Rules::compile(), and WordPress' own script
 * printer (WP_Scripts) for the script_loader_tag/buffer parity test.
 */

if (!class_exists('TrackWP_Blocker_Rules')) {
    require_once dirname(__DIR__) . '/includes/class-trackwp-blocker-rules.php';
}
if (!class_exists('TrackWP_Blocker')) {
    require_once dirname(__DIR__) . '/includes/class-trackwp-blocker.php';
}

class TrackWP_Blocker_Rewrite_Test extends WP_UnitTestCase {

    const PAGE = 'https://adashofmagic.dk/';

    /** @var WP_Scripts|null */
    private $saved_scripts;

    /** @var string|null */
    private $saved_uri;

    public function set_up() {
        parent::set_up();
        $this->saved_scripts = isset($GLOBALS['wp_scripts']) ? $GLOBALS['wp_scripts'] : null;
        $this->saved_uri     = isset($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : null;
    }

    public function tear_down() {
        $GLOBALS['wp_scripts'] = $this->saved_scripts;
        self::set_buffer_started(false);
        $r = new ReflectionProperty('TrackWP_Blocker', 'instance');
        $r->setAccessible(true);
        $r->setValue(null, null);
        if (null === $this->saved_uri) {
            unset($_SERVER['REQUEST_URI']);
        } else {
            $_SERVER['REQUEST_URI'] = $this->saved_uri;
        }
        parent::tear_down();
    }

    public static function set_buffer_started($value) {
        $r = new ReflectionProperty('TrackWP_Blocker', 'buffer_started');
        $r->setAccessible(true);
        $r->setValue(null, $value);
    }

    /** The trackwp_blocker option built from the catalog's signatures. */
    private static function option_from_catalog() {
        $catalog = TrackWP_Consent_Profile::vendor_catalog();
        $rules   = array();
        foreach (array('meta', 'leadinfo', 'klaviyo', 'jetpack', 'wc_order_attribution', 'sleeknote') as $vendor) {
            $sig = $catalog[$vendor]['signatures'];
            foreach (array('handles' => 'handle', 'urls' => 'url', 'hosts' => 'host', 'inline' => 'inline', 'pixels' => 'pixel') as $field => $kind) {
                foreach (isset($sig[$field]) ? $sig[$field] : array() as $value) {
                    $rules[$kind . ':' . $value] = array('block' => true, 'category' => $catalog[$vendor]['category'], 'vendor' => $vendor);
                }
            }
        }
        return array('mode' => 'on', 'rules' => $rules, 'exceptions' => array('allow' => array(), 'paths' => array()));
    }

    /**
     * The saved response. The capture starts with an HTTP status line
     * ('200' plus a newline) before <!DOCTYPE; the body is what a buffer receives.
     */
    private static function fixture() {
        $raw = file_get_contents(__DIR__ . '/fixtures/blocker/adashofmagic-home.html');
        return (string) preg_replace('/\A\d{3}\r?\n/', '', $raw);
    }

    private static function rewritten() {
        static $out = null;
        if (null === $out) {
            $out = TrackWP_Blocker::rewrite(self::fixture(), TrackWP_Blocker_Rules::compile(self::option_from_catalog()), self::PAGE);
        }
        return $out;
    }

    /** @return array<int,array{tag:string,attrs:array,text:string}> */
    private static function tags($html, array $names = array('SCRIPT', 'IMG', 'IFRAME')) {
        $p   = new WP_HTML_Tag_Processor($html);
        $out = array();
        while ($p->next_tag()) {
            if (!in_array($p->get_tag(), $names, true)) {
                continue;
            }
            $attrs = array();
            foreach ((array) $p->get_attribute_names_with_prefix('') as $name) {
                $attrs[$name] = $p->get_attribute($name);
            }
            ksort($attrs);
            $out[] = array('tag' => $p->get_tag(), 'attrs' => $attrs, 'text' => 'SCRIPT' === $p->get_tag() ? $p->get_modifiable_text() : '');
        }
        return $out;
    }

    private static function by_id($html, $id) {
        foreach (self::tags($html) as $t) {
            if (isset($t['attrs']['id']) && $id === $t['attrs']['id']) {
                return $t;
            }
        }
        return null;
    }

    private static function executable(array $t) {
        return 'SCRIPT' === $t['tag'] && TrackWP_Blocker_Rules::is_js_type(isset($t['attrs']['type']) ? $t['attrs']['type'] : null);
    }

    public function test_no_executable_script_reaches_blocked_trackers() {
        $needles = array('connect.facebook.net', 'cdn.leadinfo.net', 'static.klaviyo.com', 'stats.wp.com', 'sleeknotecustomerscripts', 'meta-capi-param-builder');
        $before  = array_fill_keys($needles, 0);
        foreach (self::tags(self::fixture(), array('SCRIPT')) as $t) {
            foreach ($needles as $n) {
                $src = isset($t['attrs']['src']) && is_string($t['attrs']['src']) ? $t['attrs']['src'] : '';
                if (self::executable($t) && (false !== strpos($src, $n) || false !== strpos($t['text'], $n))) {
                    $before[$n]++;
                }
            }
        }
        foreach ($before as $n => $count) {
            $this->assertGreaterThan(0, $count, "fixture has no executable script for $n");
        }
        foreach (self::tags(self::rewritten(), array('SCRIPT')) as $t) {
            if (!self::executable($t)) {
                continue;
            }
            $src = isset($t['attrs']['src']) && is_string($t['attrs']['src']) ? $t['attrs']['src'] : '';
            foreach ($needles as $n) {
                $this->assertFalse(false !== strpos($src, $n) || false !== strpos($t['text'], $n), "executable script still reaches $n: " . wp_json_encode($t['attrs']));
            }
        }
    }

    /** S5: the inline Meta loader is found through get_modifiable_text() on SCRIPT. */
    public function test_inline_marker_read_from_script_text() {
        $found = false;
        foreach (self::tags(self::rewritten(), array('SCRIPT')) as $t) {
            if (false !== strpos($t['text'], "connect.facebook.net/en_US/fbevents.js")) {
                $found = true;
                $this->assertSame('text/plain', $t['attrs']['type']);
                $this->assertSame('marketing', $t['attrs']['data-twp-category']);
                $this->assertStringStartsWith('inline:', $t['attrs']['data-twp-blocked']);
            }
        }
        $this->assertTrue($found);
    }

    public function test_order_attribution_is_text_plain() {
        foreach (array('sourcebuster-js-js', 'wc-order-attribution-js', 'wc-order-attribution-js-extra', 'facebook-capi-param-builder-js-after') as $id) {
            $t = self::by_id(self::rewritten(), $id);
            $this->assertNotNull($t, $id);
            $this->assertSame('text/plain', $t['attrs']['type'], $id);
            $this->assertStringStartsWith('handle:', $t['attrs']['data-twp-blocked'], $id);
        }
    }

    /** S6/S9: the fb4woo noscript pixel is neutralized (Tag Processor descends into NOSCRIPT). */
    public function test_noscript_fbpx_pixel_neutralized() {
        $hits = 0;
        foreach (self::tags(self::rewritten(), array('IMG')) as $t) {
            if (isset($t['attrs']['alt']) && 'fbpx' === $t['attrs']['alt']) {
                $hits++;
                $this->assertArrayNotHasKey('src', $t['attrs']);
                $this->assertStringContainsString('www.facebook.com/tr', $t['attrs']['data-twp-src']);
                $this->assertSame('pixel:www.facebook.com/tr', $t['attrs']['data-twp-blocked']);
                $this->assertSame('marketing', $t['attrs']['data-twp-category']);
            }
        }
        $this->assertSame(1, $hits);
    }

    public function test_never_and_unrelated_tags_are_byte_identical() {
        $orig = self::fixture();
        $out  = self::rewritten();
        foreach (array('trackwp-consent-js-before', 'trackwp-consent-js', 'trackwp-tracking-js-before', 'trackwp-tracking-js', 'jquery-core-js', 'jquery-core-js-extra', 'wc-cart-fragments-js', 'wc-cart-fragments-js-extra', 'website-schema') as $id) {
            $re = '#<script\b[^>]*\bid="' . preg_quote($id, '#') . '"[^>]*>#';
            $this->assertSame(1, preg_match($re, $orig, $a), $id);
            $this->assertSame(1, preg_match($re, $out, $b), $id);
            $this->assertSame($a[0], $b[0], $id);
        }
        // gtm.js loader and the GTM noscript iframe are untouched.
        $this->assertSame(substr_count($orig, 'googletagmanager.com/gtm.js'), substr_count($out, 'googletagmanager.com/gtm.js'));
        $this->assertStringContainsString('<iframe src="https://www.googletagmanager.com/ns.html?id=GTM-MVF44ZTL"', $out);
        // Protected payment widget (module) has no rule: unchanged.
        $this->assertStringContainsString('<script src="https://my.anyday.io/price-widget/anyday-price-widget.js" type="module" async>', $out);
    }

    public function test_tag_count_unchanged() {
        $count = function ($html) {
            $p = new WP_HTML_Tag_Processor($html);
            $n = 0;
            while ($p->next_tag(array('tag_closers' => 'visit'))) {
                $n++;
            }
            return $n;
        };
        $this->assertSame($count(self::fixture()), $count(self::rewritten()));
    }

    public function test_kb9_attributes_on_enqueued_script() {
        $t = self::by_id(self::rewritten(), 'jetpack-stats-js');
        $this->assertSame('text/plain', $t['attrs']['type']);
        $this->assertSame('https://stats.wp.com/e-202640.js', $t['attrs']['data-twp-src']);
        $this->assertArrayNotHasKey('src', $t['attrs']);
        $this->assertSame('statistics', $t['attrs']['data-twp-category']);
        $this->assertSame('1', $t['attrs']['data-no-optimize']);
        $this->assertSame('1', $t['attrs']['data-no-defer']);
        $this->assertTrue($t['attrs']['defer']);
        $this->assertSame('low', $t['attrs']['fetchpriority']);
        $this->assertArrayNotHasKey('data-twp-type', $t['attrs']);
    }

    public function test_kb9_preserves_attributes_module_and_types() {
        $c    = TrackWP_Blocker_Rules::compile(array('rules' => array('host:static.klaviyo.com' => array('block' => true, 'category' => 'marketing'))));
        $html = '<script type="module" src="https://static.klaviyo.com/a.js" nonce="abc" integrity="sha384-x" crossorigin="anonymous" referrerpolicy="no-referrer" async id="k1"></script>'
            . '<script nomodule defer src="//static.klaviyo.com/b.js" id="k2"></script>'
            . '<script type="application/ld+json" id="k3">{"u":"https://static.klaviyo.com/x"}</script>'
            . '<script type="text/template" src="https://static.klaviyo.com/t.html" id="k4"></script>'
            . '<script type="TEXT/JAVASCRIPT" src="https://static.klaviyo.com/c.js" id="k5"></script>'
            . '<div data-src="https://static.klaviyo.com/a.js"></div>';
        $out = TrackWP_Blocker::rewrite($html, $c, 'https://example.org/');
        $k1  = self::by_id($out, 'k1')['attrs'];
        $this->assertSame('module', $k1['data-twp-type']);
        $this->assertSame('text/plain', $k1['type']);
        foreach (array('nonce' => 'abc', 'integrity' => 'sha384-x', 'crossorigin' => 'anonymous', 'referrerpolicy' => 'no-referrer', 'async' => true, 'data-twp-src' => 'https://static.klaviyo.com/a.js') as $k => $v) {
            $this->assertSame($v, $k1[$k], $k);
        }
        $k2 = self::by_id($out, 'k2')['attrs'];
        $this->assertTrue($k2['nomodule']);
        $this->assertTrue($k2['defer']);
        $this->assertSame('//static.klaviyo.com/b.js', $k2['data-twp-src']);
        $this->assertArrayNotHasKey('data-twp-type', $k2);
        $this->assertArrayNotHasKey('data-twp-blocked', self::by_id($out, 'k3')['attrs']);
        $this->assertArrayNotHasKey('data-twp-blocked', self::by_id($out, 'k4')['attrs']);
        $this->assertSame('TEXT/JAVASCRIPT', self::by_id($out, 'k5')['attrs']['data-twp-type']);
        $this->assertStringContainsString('<div data-src="https://static.klaviyo.com/a.js"></div>', $out);
        // Already blocked tags are skipped: a second pass is a no-op.
        $this->assertSame($out, TrackWP_Blocker::rewrite($out, $c, 'https://example.org/'));
    }

    /** S3: a <base href> changes how later relative URLs resolve. */
    public function test_base_href_is_respected() {
        $c    = TrackWP_Blocker_Rules::compile(array('rules' => array('url:cdn.example.net/assets/track/' => array('block' => true, 'category' => 'marketing'))));
        $html = '<head><base href="https://cdn.example.net/assets/"></head><body><script src="track/t.js" id="a"></script><script src="/track/t.js" id="b"></script></body>';
        $out  = TrackWP_Blocker::rewrite($html, $c, 'https://example.org/page/');
        $this->assertSame('url:cdn.example.net/assets/track/', self::by_id($out, 'a')['attrs']['data-twp-blocked']);
        $this->assertArrayNotHasKey('data-twp-blocked', self::by_id($out, 'b')['attrs']);
    }

    /** S18: form plugin scripts stay executable even under a first-party host rule. */
    public function test_form_plugin_paths_stay_executable() {
        // Never paths apply on the site's own host (KB8), so use home_url().
        $host = (string) wp_parse_url(home_url('/'), PHP_URL_HOST);
        $c    = TrackWP_Blocker_Rules::compile(array('rules' => array('host:' . $host => array('block' => true, 'category' => 'marketing'))));
        $html = '<script src="' . esc_url(home_url('/wp-content/plugins/wpforms-lite/assets/js/frontend/wpforms.min.js')) . '" id="wpforms-js"></script>'
            . '<script src="' . esc_url(home_url('/wp-content/plugins/other/a.js')) . '" id="other-js"></script>';
        $out  = TrackWP_Blocker::rewrite($html, $c, home_url('/'));
        $this->assertArrayHasKey('src', self::by_id($out, 'wpforms-js')['attrs']);
        $this->assertArrayNotHasKey('type', self::by_id($out, 'wpforms-js')['attrs']);
        $this->assertSame('text/plain', self::by_id($out, 'other-js')['attrs']['type']);
    }

    /** Activate a real instance for the current test (no real buffer). */
    private static function active_instance(array $option) {
        update_option('trackwp_blocker', $option);
        TrackWP_Blocker_Rules::rebuild();
        $_SERVER['REQUEST_URI'] = '/';
        self::set_buffer_started(true);
        $b = new TrackWP_Blocker();
        $b->on_template_redirect();
        return $b;
    }

    private static function print_jetpack_stats() {
        $GLOBALS['wp_scripts'] = new WP_Scripts();
        wp_register_script('jetpack-stats', 'https://stats.wp.com/e-202640.js', array(), null, array('strategy' => 'defer'));
        wp_add_inline_script('jetpack-stats', '_stq = window._stq || [];', 'before');
        wp_add_inline_script('jetpack-stats', '_stq.push(["view",{}]);', 'after');
        wp_localize_script('jetpack-stats', 'jpStats', array('blog' => '1'));
        return get_echo('wp_print_scripts', array(array('jetpack-stats')));
    }

    public function test_script_loader_tag_and_buffer_give_same_result() {
        $option = self::option_from_catalog();
        // Raw WordPress output, printed with blocking inactive.
        update_option('trackwp_blocker', array('mode' => 'off'));
        $raw = self::print_jetpack_stats();
        $this->assertStringContainsString('id="jetpack-stats-js"', $raw);

        $b         = self::active_instance($option);
        $this->assertTrue(TrackWP_Blocker::active_for_request());
        $via_hooks = self::print_jetpack_stats();
        remove_filter('script_loader_tag', array($b, 'filter_script_loader_tag'), 10);
        remove_filter('wp_inline_script_attributes', array($b, 'filter_inline_script_attributes'), 10);
        $via_buffer = $b->filter_html($raw);

        $this->assertNotSame($raw, $via_hooks);
        $this->assertSame(self::tags($via_hooks, array('SCRIPT')), self::tags($via_buffer, array('SCRIPT')));
        foreach (self::tags($via_hooks, array('SCRIPT')) as $t) {
            $this->assertSame('text/plain', $t['attrs']['type'], wp_json_encode($t['attrs']));
            $this->assertStringStartsWith('handle:jetpack-stats', $t['attrs']['data-twp-blocked']);
        }
    }

    public function test_exception_returns_input_and_records_status() {
        $b = self::active_instance(self::option_from_catalog());
        delete_option(TrackWP_Blocker::STATUS_OPTION);
        $thrower = function () {
            throw new RuntimeException('boom');
        };
        add_filter('trackwp_blocker_max_bytes', $thrower);
        $html = self::fixture();
        $this->assertSame($html, $b->filter_html($html));
        remove_filter('trackwp_blocker_max_bytes', $thrower);
        $s = get_option(TrackWP_Blocker::STATUS_OPTION);
        $this->assertSame('exception', $s['last_error']['code']);

        $small = function () {
            return 100;
        };
        add_filter('trackwp_blocker_max_bytes', $small);
        $this->assertSame($html, $b->filter_html($html));
        remove_filter('trackwp_blocker_max_bytes', $small);
        $this->assertSame('too_large', get_option(TrackWP_Blocker::STATUS_OPTION)['last_error']['code']);

        // S2: output that does not start with '<' is returned untouched.
        $this->assertSame('{"a":"https://stats.wp.com/e-1.js"}', $b->filter_html('{"a":"https://stats.wp.com/e-1.js"}'));
        $out = $b->filter_html($html);
        $this->assertNotSame($html, $out);
        $this->assertArrayHasKey('last_ok', get_option(TrackWP_Blocker::STATUS_OPTION));
    }
}
