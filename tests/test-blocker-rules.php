<?php
/**
 * T2: TrackWP_Blocker_Rules (KB2, KB6-KB8, S3, S4, S10, S11, S16, S18).
 *
 * Producers: tests/fixtures/blocker/url-vectors.json (shared with the JS
 * guard test), TrackWP_Blocker_Rules::compile() and
 * TrackWP_Consent_Profile::vendor_catalog().
 */

if (!class_exists('TrackWP_Blocker_Rules')) {
    require_once dirname(__DIR__) . '/includes/class-trackwp-blocker-rules.php';
}

if (!class_exists('TrackWP_Blocker')) {
    require_once dirname(__DIR__) . '/includes/class-trackwp-blocker.php';
}

class TrackWP_Blocker_Rules_Test extends WP_UnitTestCase {

    private static function vectors() {
        return json_decode(file_get_contents(__DIR__ . '/fixtures/blocker/url-vectors.json'), true);
    }

    private static function compiled_from_vectors() {
        return TrackWP_Blocker_Rules::compile(self::vectors()['match']['settings']);
    }

    public function test_url_vectors_normalize() {
        $all = self::vectors();
        foreach ($all['vectors'] as $i => $v) {
            $home = isset($v['home']) ? $v['home'] : $all['home'];
            $base = isset($v['base']) ? $v['base'] : $home . '/';
            $n    = TrackWP_Blocker_Rules::normalize_url($v['url'], $base, $home);
            $this->assertSame($v['expected'], '' === $n ? null : TrackWP_Blocker_Rules::url_key($n), "vector #$i: " . $v['url']);
        }
    }

    public function test_match_vectors() {
        $v = self::vectors()['match'];
        $c = self::compiled_from_vectors();
        foreach ($v['cases'] as $i => $case) {
            $ctx = array('kind' => $case['kind'], 'handle' => isset($case['handle']) ? $case['handle'] : '');
            if (isset($case['url'])) {
                $ctx['url'] = TrackWP_Blocker_Rules::normalize_url($case['url'], $case['base'], $v['home']);
            }
            if (isset($case['text'])) {
                $ctx['text'] = $case['text'];
            }
            $m = TrackWP_Blocker_Rules::match($c, $ctx);
            $this->assertSame($case['expect'], null === $m ? null : $m['rule_id'], "match case #$i: " . wp_json_encode($case));
        }
    }

    public function test_longest_prefix_wins_regardless_of_rule_order() {
        $a = TrackWP_Blocker_Rules::compile(array('rules' => array(
            'url:x.test/a/'   => array('block' => true, 'category' => 'statistics'),
            'url:x.test/a/b/' => array('block' => true, 'category' => 'marketing'),
        )));
        $b = TrackWP_Blocker_Rules::compile(array('rules' => array(
            'url:x.test/a/b/' => array('block' => true, 'category' => 'marketing'),
            'url:x.test/a/'   => array('block' => true, 'category' => 'statistics'),
        )));
        $this->assertSame($a['urls'], $b['urls']);
        $m = TrackWP_Blocker_Rules::match($a, array('kind' => 'script', 'url' => 'https://x.test/a/b/c.js'));
        $this->assertSame(array('rule_id' => 'url:x.test/a/b/', 'category' => 'marketing'), $m);
    }

    public function test_handle_before_url_before_host() {
        $c = TrackWP_Blocker_Rules::compile(array('rules' => array(
            'host:x.test'     => array('block' => true, 'category' => 'personalisation'),
            'url:x.test/a/'   => array('block' => true, 'category' => 'statistics'),
            'handle:my-thing' => array('block' => true, 'category' => 'marketing'),
        )));
        $url = 'https://x.test/a/b.js';
        $this->assertSame('handle:my-thing', TrackWP_Blocker_Rules::match($c, array('kind' => 'script', 'url' => $url, 'handle' => 'my-thing'))['rule_id']);
        $this->assertSame('url:x.test/a/', TrackWP_Blocker_Rules::match($c, array('kind' => 'script', 'url' => $url, 'handle' => 'other'))['rule_id']);
        $this->assertSame('host:x.test', TrackWP_Blocker_Rules::match($c, array('kind' => 'script', 'url' => 'https://cdn.x.test/z.js'))['rule_id']);
    }

    public function test_most_specific_host_wins() {
        $c = TrackWP_Blocker_Rules::compile(array('rules' => array(
            'host:x.test'     => array('block' => true, 'category' => 'statistics'),
            'host:cdn.x.test' => array('block' => true, 'category' => 'marketing'),
        )));
        $this->assertSame('host:cdn.x.test', TrackWP_Blocker_Rules::match($c, array('kind' => 'script', 'url' => 'https://a.cdn.x.test/z.js'))['rule_id']);
        $this->assertSame('host:x.test', TrackWP_Blocker_Rules::match($c, array('kind' => 'script', 'url' => 'https://x.test:8443/z.js'))['rule_id']);
    }

    public function test_never_wins_over_every_rule_kind() {
        $c = TrackWP_Blocker_Rules::compile(array('rules' => array(
            'host:example.org'           => array('block' => true, 'category' => 'marketing'),
            'handle:wc-cart-fragments'   => array('block' => true, 'category' => 'marketing'),
            'handle:trackwp-tracking'    => array('block' => true, 'category' => 'marketing'),
            'inline:trackwpConfig'       => array('block' => true, 'category' => 'marketing'),
            'host:www.gstatic.com'       => array('block' => true, 'category' => 'marketing'),
        )));
        $home = home_url('/');
        foreach (array('/wp-content/plugins/trackwp/assets/js/trackwp.min.js', '/wp-includes/js/jquery/jquery.min.js', '/wp-content/plugins/contact-form-7/includes/js/index.js', '/wp-content/plugins/wpforms/x.js', '/wp-content/plugins/wpforms-lite/assets/js/frontend.min.js', '/wp-content/plugins/gravityforms/js/a.js', '/wp-content/plugins/fluentform/public/a.js') as $path) {
            $this->assertNull(TrackWP_Blocker_Rules::match($c, array('kind' => 'script', 'url' => TrackWP_Blocker_Rules::normalize_url($path, $home))), $path);
        }
        $this->assertNull(TrackWP_Blocker_Rules::match($c, array('kind' => 'script', 'url' => 'https://www.gstatic.com/recaptcha/releases/x/recaptcha__da.js')));
        $this->assertSame('host:www.gstatic.com', TrackWP_Blocker_Rules::match($c, array('kind' => 'script', 'url' => 'https://www.gstatic.com/other.js'))['rule_id']);
        $this->assertNull(TrackWP_Blocker_Rules::match($c, array('kind' => 'inline', 'handle' => 'wc-cart-fragments', 'text' => 'x')));
        $this->assertNull(TrackWP_Blocker_Rules::match($c, array('kind' => 'inline', 'handle' => 'trackwp-tracking', 'text' => 'x')));
        $this->assertNull(TrackWP_Blocker_Rules::match($c, array('kind' => 'inline', 'text' => 'window.trackwpConfig={}')));
        $this->assertNull(TrackWP_Blocker_Rules::match($c, array('kind' => 'inline', 'id' => 'trackwp-meta-pixel', 'text' => 'fbq("init")')));
        // is_never() tells "never" apart from "no rule".
        $this->assertTrue(TrackWP_Blocker_Rules::is_never($c, array('kind' => 'script', 'url' => 'https://www.gstatic.com/recaptcha/a.js')));
        $this->assertTrue(TrackWP_Blocker_Rules::is_never($c, array('kind' => 'inline', 'handle' => 'jquery-core', 'text' => 'x')));
        $this->assertFalse(TrackWP_Blocker_Rules::is_never($c, array('kind' => 'script', 'url' => 'https://unknown.test/a.js')));
        $this->assertNull(TrackWP_Blocker_Rules::match($c, array('kind' => 'script', 'url' => 'https://unknown.test/a.js')));
        // sourcebuster-js and wc-order-attribution are NOT on the never list (KB8).
        $never = TrackWP_Blocker_Rules::builtin_lists()['never'];
        $this->assertNotContains('sourcebuster-js', $never['handles']);
        $this->assertNotContains('wc-order-attribution', $never['handles']);
        foreach ($never['hosts'] as $h) {
            $this->assertStringStartsNotWith('wp-', $h);
        }
    }

    public function test_exceptions_allow_only_prefixed_ids_and_win() {
        $settings = array(
            'rules'      => array('host:x.test' => array('block' => true, 'category' => 'marketing')),
            'exceptions' => array('allow' => array('x.test', 'url:x.test/ok/')),
        );
        $c = TrackWP_Blocker_Rules::compile($settings);
        $this->assertSame('host:x.test', TrackWP_Blocker_Rules::match($c, array('kind' => 'script', 'url' => 'https://x.test/no.js'))['rule_id']);
        $this->assertNull(TrackWP_Blocker_Rules::match($c, array('kind' => 'script', 'url' => 'https://x.test/ok/yes.js')));
        $settings['exceptions']['allow'] = array('host:x.test');
        $this->assertFalse(TrackWP_Blocker_Rules::has_block_rules(TrackWP_Blocker_Rules::compile($settings)));
    }

    public function test_stable_rule_ids_and_observation_ids() {
        $c1 = self::compiled_from_vectors();
        $c2 = self::compiled_from_vectors();
        $this->assertSame($c1, $c2);
        $ids = array();
        foreach (array('urls', 'pixels', 'inline') as $k) {
            foreach ($c1[$k] as $r) {
                $ids[] = $r[2];
            }
        }
        foreach (array('handles', 'hosts') as $k) {
            foreach ($c1[$k] as $r) {
                $ids[] = $r[1];
            }
        }
        // Every compiled id is either a stored rule id or a catalog signature
        // of a vendor with a blocked rule (jetpack in the vectors).
        $sig_ids = array();
        foreach (TrackWP_Consent_Profile::vendor_catalog() as $v) {
            foreach (array('urls' => 'url', 'hosts' => 'host', 'pixels' => 'pixel') as $field => $kind) {
                foreach (isset($v['signatures'][$field]) ? $v['signatures'][$field] : array() as $value) {
                    $sig_ids[] = $kind . ':' . $value;
                }
            }
        }
        foreach ($ids as $id) {
            $this->assertTrue(TrackWP_Blocker_Rules::is_valid_rule_id($id), $id);
            $this->assertTrue(isset(self::vectors()['match']['settings']['rules'][$id]) || in_array($id, $sig_ids, true), $id);
        }
        $this->assertFalse(TrackWP_Blocker_Rules::is_valid_rule_id('cdn.x.test'));
        $this->assertFalse(TrackWP_Blocker_Rules::is_valid_rule_id('host:a b'));
        $this->assertSame(12, strlen(TrackWP_Blocker_Rules::observation_id('script', 'a.test', '/x.js', 'h', '')));
        $this->assertSame(
            TrackWP_Blocker_Rules::observation_id('script', 'a.test', '/x.js', 'h', ''),
            TrackWP_Blocker_Rules::observation_id('script', 'a.test', '/x.js', 'h', '')
        );
    }

    public function test_handle_from_id_strips_longest_suffix_first() {
        $this->assertSame('sourcebuster-js', TrackWP_Blocker_Rules::handle_from_id('sourcebuster-js-js'));
        $this->assertSame('wc-order-attribution', TrackWP_Blocker_Rules::handle_from_id('wc-order-attribution-js-extra'));
        $this->assertSame('facebook-capi-param-builder', TrackWP_Blocker_Rules::handle_from_id('facebook-capi-param-builder-js-after'));
        $this->assertSame('jetpack-stats', TrackWP_Blocker_Rules::handle_from_id('jetpack-stats-js-before'));
        $this->assertSame('tp-js', TrackWP_Blocker_Rules::handle_from_id('tp-js-js-extra'));
        $this->assertSame('', TrackWP_Blocker_Rules::handle_from_id('sleeknoteScript'));
        $this->assertSame('', TrackWP_Blocker_Rules::handle_from_id(null));
    }

    public function test_js_type_classification() {
        foreach (array(null, true, '', ' ', 'text/javascript', 'TEXT/JavaScript', 'application/javascript', 'module', 'Module', 'text/ecmascript', 'application/x-javascript', 'text/jscript', 'text/livescript', 'text/javascript1.5', 'text/javascript; charset=utf-8') as $t) {
            $this->assertTrue(TrackWP_Blocker_Rules::is_js_type($t), var_export($t, true));
        }
        foreach (array('application/ld+json', 'importmap', 'text/template', 'text/plain', 'speculationrules', 'text/x-template', 'application/json') as $t) {
            $this->assertFalse(TrackWP_Blocker_Rules::is_js_type($t), $t);
        }
    }

    /**
     * Catalog signatures (KB5) are added for a vendor that has a blocked rule;
     * an explicit rule for the same id wins. Uses the real catalog.
     */
    public function test_catalog_signatures_follow_blocked_vendor() {
        $catalog = TrackWP_Consent_Profile::vendor_catalog();
        $vendor  = null;
        foreach ($catalog as $key => $v) {
            if (!empty($v['signatures']['pixels']) || !empty($v['signatures']['hosts']) || !empty($v['signatures']['urls'])) {
                $vendor = $key;
                break;
            }
        }
        if (null === $vendor) {
            $this->markTestSkipped('vendor_catalog() has no signatures yet (T1 KB5).');
        }
        $c   = TrackWP_Blocker_Rules::compile(array('rules' => array('inline:vendorMarker1' => array('block' => true, 'category' => 'marketing', 'vendor' => $vendor))));
        $sig = $catalog[$vendor]['signatures'];
        $ids = array();
        foreach (array('urls', 'pixels') as $k) {
            foreach ($c[$k] as $r) {
                $ids[] = $r[2];
            }
        }
        foreach ($c['hosts'] as $r) {
            $ids[] = $r[1];
        }
        foreach (array('urls' => 'url', 'hosts' => 'host', 'pixels' => 'pixel') as $field => $kind) {
            foreach (isset($sig[$field]) ? $sig[$field] : array() as $value) {
                $id = $kind . ':' . $value;
                if (TrackWP_Blocker_Rules::is_valid_rule_id($id)) {
                    $this->assertContains($id, $ids);
                }
            }
        }
        $none = TrackWP_Blocker_Rules::compile(array('rules' => array('inline:vendorMarker1' => array('block' => false, 'category' => 'marketing', 'vendor' => $vendor))));
        $this->assertFalse(TrackWP_Blocker_Rules::has_block_rules($none));
    }

    public function test_partial_option_and_compiled_cache() {
        $this->assertFalse(TrackWP_Blocker_Rules::has_block_rules(TrackWP_Blocker_Rules::compile(array())));
        $this->assertFalse(TrackWP_Blocker_Rules::has_block_rules(TrackWP_Blocker_Rules::compile(array('rules' => 'x', 'exceptions' => 5))));
        update_option('trackwp_blocker', array('mode' => 'on', 'rules' => array('host:x.test' => array('block' => true, 'category' => 'marketing'))));
        $c = TrackWP_Blocker_Rules::rebuild();
        $this->assertSame($c, get_option('trackwp_blocker_compiled'));
        $this->assertSame($c, TrackWP_Blocker_Rules::compiled());
        $client = TrackWP_Blocker_Rules::client_rules($c);
        $this->assertSame(array('v', 'home', 'urls', 'hosts', 'pixels', 'never'), array_keys($client));
        $this->assertSame(array('paths', 'urls', 'hosts'), array_keys($client['never']));
    }

    /** Review MINOR 2: 'necessary' is never blocked, whatever the input (e.g. import). */
    public function test_necessary_is_never_blocked() {
        $catalog = TrackWP_Consent_Profile::vendor_catalog();
        $c = TrackWP_Blocker_Rules::compile(array('rules' => array(
            'host:necessary.test'  => array('block' => true, 'category' => 'necessary'),
            // Explicit necessary on a catalog vendor must not fall back to the catalog's marketing.
            'host:connect.facebook.net' => array('block' => true, 'category' => 'necessary', 'vendor' => 'meta'),
            'handle:x-necessary'   => array('block' => '1', 'category' => 'necessary', 'vendor' => 'meta'),
        )), $catalog);
        $this->assertFalse(TrackWP_Blocker_Rules::has_block_rules($c));
        $this->assertNull(TrackWP_Blocker_Rules::match($c, array('kind' => 'script', 'url' => 'https://connect.facebook.net/en_US/fbevents.js')));
        // Unknown category still falls back to the catalog (unchanged behaviour).
        $c = TrackWP_Blocker_Rules::compile(array('rules' => array('host:connect.facebook.net' => array('block' => true, 'category' => 'bogus', 'vendor' => 'meta'))), $catalog);
        $this->assertSame('marketing', TrackWP_Blocker_Rules::match($c, array('kind' => 'script', 'url' => 'https://connect.facebook.net/a.js'))['category']);
    }

    /** Review MINOR 3 / KB8: never paths apply on the site's own host only. */
    public function test_never_paths_only_on_own_host() {
        $c    = TrackWP_Blocker_Rules::compile(array('rules' => array(
            'host:cdn.other.test' => array('block' => true, 'category' => 'marketing'),
            'host:example.org'    => array('block' => true, 'category' => 'statistics'),
        )));
        $home = home_url('/');
        $own  = TrackWP_Blocker_Rules::normalize_url('/wp-content/plugins/trackwp/assets/js/consent.min.js', $home);
        $this->assertNull(TrackWP_Blocker_Rules::match($c, array('kind' => 'script', 'url' => $own)));
        $this->assertTrue(TrackWP_Blocker_Rules::is_never($c, array('kind' => 'script', 'url' => $own)));
        $foreign = 'https://cdn.other.test/wp-content/plugins/trackwp/assets/js/consent.min.js';
        $this->assertFalse(TrackWP_Blocker_Rules::is_never($c, array('kind' => 'script', 'url' => $foreign)));
        $this->assertSame('host:cdn.other.test', TrackWP_Blocker_Rules::match($c, array('kind' => 'script', 'url' => $foreign))['rule_id']);
        // Other port on the same host name is another origin.
        $this->assertSame('host:example.org', TrackWP_Blocker_Rules::match($c, array('kind' => 'script', 'url' => 'http://example.org:8080/wp-includes/js/jquery/jquery.min.js'))['rule_id']);
        // Status paths (cannot_bundled) follow the same rule.
        $this->assertSame('cannot_bundled', TrackWP_Blocker_Rules::builtin_status(TrackWP_Blocker_Rules::url_key(TrackWP_Blocker_Rules::normalize_url('/wp-content/cache/min/1/a.js', $home))));
        $this->assertSame('', TrackWP_Blocker_Rules::builtin_status('cdn.other.test/wp-content/cache/min/1/a.js'));
        // Guard payload keeps home so the browser can apply the same own-host test.
        $this->assertSame($c['home'], TrackWP_Blocker_Rules::client_rules($c)['home']);
    }

    /** Review M6: the one html_api_available() source, typed bool. */
    public function test_html_api_available_is_the_single_bool_source() {
        $m = new ReflectionMethod('TrackWP_Blocker', 'html_api_available');
        $this->assertTrue($m->isPublic() && $m->isStatic());
        $this->assertSame('bool', (string) $m->getReturnType());
        $saved = $GLOBALS['wp_version'];
        $this->assertTrue(TrackWP_Blocker::html_api_available());
        $GLOBALS['wp_version'] = '6.4.9';
        $this->assertFalse(TrackWP_Blocker::html_api_available());
        $GLOBALS['wp_version'] = '6.5';
        $this->assertTrue(TrackWP_Blocker::html_api_available());
        $GLOBALS['wp_version'] = $saved;
    }

    public function test_builtin_status_for_scanner() {
        $home = home_url('/');
        $this->assertSame('gtm_consent_mode', TrackWP_Blocker_Rules::builtin_status('www.googletagmanager.com/gtm.js'));
        $this->assertSame('protected', TrackWP_Blocker_Rules::builtin_status('api.playground.klarna.com/x.js'));
        $this->assertSame('protected', TrackWP_Blocker_Rules::builtin_status('my.anyday.io/price-widget/anyday-price-widget.js'));
        $this->assertSame('cannot_bundled', TrackWP_Blocker_Rules::builtin_status(TrackWP_Blocker_Rules::url_key(TrackWP_Blocker_Rules::normalize_url('/wp-content/cache/min/1/a.js', $home))));
        $this->assertSame('', TrackWP_Blocker_Rules::builtin_status('static.klaviyo.com/onsite/js/x.js'));
    }

    /** KB8: the WooCommerce checkout handle is on never, even with an explicit rule for it. */
    public function test_never_covers_wc_checkout_handle() {
        $c = TrackWP_Blocker_Rules::compile(array('rules' => array(
            'handle:wc-checkout' => array('block' => true, 'category' => 'marketing'),
        )));
        $this->assertTrue(TrackWP_Blocker_Rules::is_never($c, array('kind' => 'script', 'handle' => 'wc-checkout')));
        $this->assertNull(TrackWP_Blocker_Rules::match($c, array('kind' => 'script', 'handle' => 'wc-checkout', 'url' => 'https://cdn.example.org/checkout.js')));
    }

    /** KB6: host lists (here the built-in "protected" hosts) respect the domain boundary. */
    public function test_builtin_status_hosts_respect_domain_boundary() {
        $this->assertSame('protected', TrackWP_Blocker_Rules::builtin_status('js.stripe.com/v3/'));
        $this->assertNotSame('protected', TrackWP_Blocker_Rules::builtin_status('evilklarna.com/x.js'));
        $this->assertNotSame('protected', TrackWP_Blocker_Rules::builtin_status('notnets.eu/x.js'));
    }
}
