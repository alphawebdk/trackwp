<?php
/**
 * TrackWP_Blocker_Scanner (T4): PLAN-1.11.0-v2 §3.4, §5, KB3, KB4, KB13,
 * S12, S13, S14, S15, S20.
 *
 * Producers: tests/fixtures/blocker/adashofmagic-home.html (saved real
 * response, T0), TrackWP_Consent_Profile::vendor_catalog() (T1),
 * TrackWP_Blocker_Rules (T2) and the scanner's own build_scan_map() for the
 * observe map. pre_http_request serves the fixture and scan-headers.json.
 */
class TrackWP_Blocker_Scanner_Test extends WP_UnitTestCase {

    /** @var array Captured requests {url, args}. */
    private $requests = array();

    /** @var string Body for observe requests (with X-TrackWP-Scan). */
    private $observe_body = '';

    /** @var string Body for the visitor (health) request. */
    private $visitor_body = '';

    /** @var int|null Forced status code. */
    private $code = null;

    /** @var int */
    private $admin;

    public function set_up() {
        parent::set_up();
        if (!TrackWP_Blocker_Scanner::rules_available()) {
            $this->markTestSkipped('TrackWP_Blocker_Rules (T2) is not available.');
        }
        $this->admin = self::factory()->user->create(array('role' => 'administrator'));
        wp_set_current_user($this->admin);
        $this->requests     = array();
        $this->observe_body = self::fixture();
        $this->visitor_body = self::fixture();
        $this->code         = null;
        delete_option('trackwp_blocker');
        delete_option(TrackWP_Blocker_Scanner::OPTION);
        delete_transient(TrackWP_Blocker_Scanner::LOCK);
        TrackWP_Blocker_Scanner::reset_request_state();
        unset($_SERVER['HTTP_X_TRACKWP_SCAN']);
        add_filter('pre_http_request', array($this, 'serve'), 10, 3);
        global $wp_rest_server;
        $wp_rest_server = null;
        rest_get_server();
    }

    public function tear_down() {
        remove_filter('pre_http_request', array($this, 'serve'), 10);
        unset($_SERVER['HTTP_X_TRACKWP_SCAN']);
        TrackWP_Blocker_Scanner::reset_request_state();
        global $wp_rest_server;
        $wp_rest_server = null;
        parent::tear_down();
    }

    private static function fixture() {
        static $html = null;
        if (null === $html) {
            $file = __DIR__ . '/fixtures/blocker/adashofmagic-home.html';
            if (!file_exists($file)) {
                $file = dirname(__DIR__, 2) . '/live-test/adashofmagic/fe2-source-home.html';
            }
            $html = (string) file_get_contents($file);
        }
        return $html;
    }

    private static function headers() {
        $h = json_decode((string) file_get_contents(__DIR__ . '/fixtures/blocker/scan-headers.json'), true);
        unset($h['_comment']);
        return $h;
    }

    public function serve($pre, $args, $url) {
        $this->requests[] = array('url' => $url, 'args' => $args);
        $observe = !empty($args['headers'][TrackWP_Blocker_Scanner::HEADER]);
        return array(
            'headers'  => self::headers(),
            'body'     => $observe ? $this->observe_body : $this->visitor_body,
            'response' => array('code' => $this->code ? $this->code : 200, 'message' => 'OK'),
            'cookies'  => array(),
            'filename' => null,
        );
    }

    /** Scan map built by the producer (build_scan_map) from real WP_Scripts state. */
    private function body_with_map() {
        wp_register_script('leadinfo-test', plugins_url('/leadinfo-x/assets/a.js'), array('sourcebuster-js'), '1');
        wp_register_script('sourcebuster-js', plugins_url('/woocommerce/assets/js/sourcebuster/sourcebuster.min.js'), array(), '1');
        wp_scripts()->done = array('sourcebuster-js', 'leadinfo-test');
        return TrackWP_Blocker_Scanner::inject_scan_map(self::fixture());
    }

    private function dispatch_scan($nonce = true) {
        $req = new WP_REST_Request('POST', '/trackwp/v1/blocker/scan');
        if ($nonce) {
            $req->set_header('X-WP-Nonce', wp_create_nonce('wp_rest'));
        }
        return rest_get_server()->dispatch($req);
    }

    private static function item_by(array $scan, $field, $value) {
        foreach ($scan['items'] as $item) {
            if ($item[$field] === $value) {
                return $item;
            }
        }
        return null;
    }

    // ---- REST, permission, lock -------------------------------------------

    public function test_rest_requires_manage_options_and_nonce() {
        $this->assertContains($this->dispatch_scan(false)->get_status(), array(401, 403), 'Nonce required');
        wp_set_current_user(self::factory()->user->create(array('role' => 'editor')));
        $this->assertContains($this->dispatch_scan()->get_status(), array(401, 403));
        $this->assertSame(array(), $this->requests, 'No fetch without permission');
    }

    public function test_lock_rejects_concurrent_scan_and_is_released() {
        set_transient(TrackWP_Blocker_Scanner::LOCK, time(), 120);
        $this->assertSame(409, $this->dispatch_scan()->get_status());
        $this->assertSame(array(), $this->requests);
        delete_transient(TrackWP_Blocker_Scanner::LOCK);
        $this->assertSame(200, $this->dispatch_scan()->get_status());
        $this->assertFalse(get_transient(TrackWP_Blocker_Scanner::LOCK), 'Lock released after the scan');
    }

    // ---- Page selection (S13) ---------------------------------------------

    public function test_page_selection_front_first_extra_max_four_total_five() {
        $this->set_permalink_structure('/%postname%/');
        self::factory()->post->create(array('post_status' => 'publish'));
        $pages = TrackWP_Blocker_Scanner::select_pages(array('/a/', 'https://evil.test/x', '//evil.test/y', 'relative', '/b?x=1', '/c/', '/d/', '/e/', '/f/'));
        $this->assertSame('/', $pages[0]);
        $this->assertSame(array('/', '/a/', '/c/', '/d/', '/e/'), $pages);
        $this->assertCount(TrackWP_Blocker_Scanner::MAX_PAGES, $pages);

        $auto = TrackWP_Blocker_Scanner::select_pages(array('/a/'));
        $this->assertLessThanOrEqual(5, count($auto));
        $this->assertSame(array('/', '/a/'), array_slice($auto, 0, 2));
        $this->assertGreaterThan(2, count($auto), 'Latest post is added automatically');
    }

    // ---- Fetch (§3.4.2, S12) ----------------------------------------------

    public function test_fetch_args_and_foreign_origin_and_redirect() {
        $this->assertWPError(TrackWP_Blocker_Scanner::fetch('http://evil.test/'));
        $this->assertWPError(TrackWP_Blocker_Scanner::fetch('https://' . wp_parse_url(home_url(), PHP_URL_HOST) . ':8443/'));
        $this->assertSame(array(), $this->requests, 'Foreign origin never fetched');

        $res = TrackWP_Blocker_Scanner::fetch(home_url('/'), str_repeat('a', 32));
        $this->assertIsArray($res);
        $args = $this->requests[0]['args'];
        $this->assertSame(0, $args['redirection']);
        $this->assertTrue($args['reject_unsafe_urls']);
        $this->assertSame(5, $args['timeout']);
        $this->assertSame(3 * MB_IN_BYTES, $args['limit_response_size']);
        $this->assertSame(array(), $args['cookies']);
        $this->assertSame(str_repeat('a', 32), $args['headers']['X-TrackWP-Scan']);
        $this->assertStringContainsString('trackwp_scan_nc=', $this->requests[0]['url']);

        TrackWP_Blocker_Scanner::fetch(home_url('/'));
        $this->assertArrayNotHasKey('X-TrackWP-Scan', $this->requests[1]['args']['headers']);
        $this->assertStringNotContainsString('trackwp_scan_nc', $this->requests[1]['url']);

        $this->code = 301;
        $err = TrackWP_Blocker_Scanner::fetch(home_url('/'));
        $this->assertWPError($err);
        $this->assertSame('redirect', $err->get_error_code());
    }

    // ---- Token (KB13) -----------------------------------------------------

    public function test_token_single_use_bound_to_admin_with_ttl() {
        $t = TrackWP_Blocker_Scanner::issue_token($this->admin);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{32}$/', $t);
        $this->assertTrue(TrackWP_Blocker_Scanner::consume_token($t));
        $this->assertFalse(TrackWP_Blocker_Scanner::consume_token($t), 'Single use');

        $sub = self::factory()->user->create(array('role' => 'subscriber'));
        $this->assertFalse(TrackWP_Blocker_Scanner::consume_token(TrackWP_Blocker_Scanner::issue_token($sub)));

        $expired = str_repeat('b', 32);
        set_transient('trackwp_blk_obs_' . hash('sha256', $expired), array('user_id' => $this->admin, 'exp' => time() - 1), 600);
        $this->assertFalse(TrackWP_Blocker_Scanner::consume_token($expired));
        $this->assertFalse(TrackWP_Blocker_Scanner::consume_token('not-a-token'));
    }

    public function test_invalid_header_renders_normal_page_and_valid_is_consumed_once() {
        $_SERVER['HTTP_X_TRACKWP_SCAN'] = str_repeat('c', 32);
        $this->assertFalse(TrackWP_Blocker_Scanner::is_observe_request());

        TrackWP_Blocker_Scanner::reset_request_state();
        $_SERVER['HTTP_X_TRACKWP_SCAN'] = TrackWP_Blocker_Scanner::issue_token($this->admin);
        $this->assertTrue(TrackWP_Blocker_Scanner::is_observe_request());
        $this->assertTrue(TrackWP_Blocker_Scanner::is_observe_request(), 'Cached for the request');
        $this->assertTrue(defined('DONOTCACHEPAGE'));
        TrackWP_Blocker_Scanner::reset_request_state();
        $this->assertFalse(TrackWP_Blocker_Scanner::is_observe_request(), 'Replay fails');
    }

    public function test_observe_in_mode_off_gives_map_without_rewrite() {
        update_option('trackwp_blocker', array('mode' => 'off', 'rules' => array('handle:sourcebuster-js' => array('block' => true, 'category' => 'marketing', 'vendor' => ''))));
        $html = $this->body_with_map();
        $this->assertStringNotContainsString('data-twp-blocked', $html);
        $map = TrackWP_Blocker_Scanner::parse_scan_map($html);
        $this->assertSame(array('sourcebuster-js'), $map['leadinfo-test']['deps']);
        $this->assertSame('leadinfo-x', $map['leadinfo-test']['plugin']);
        $this->assertStringNotContainsString('?', $map['leadinfo-test']['src']);
    }

    // ---- Full scan: parse, storage, status, meta, health ------------------

    public function test_scan_warm_cache_without_map_is_incomplete() {
        $res = $this->dispatch_scan();
        $this->assertSame(200, $res->get_status());
        $scan = get_option(TrackWP_Blocker_Scanner::OPTION);
        $this->assertContains('/', $scan['incomplete_pages']);
        $this->assertSame('/', $scan['pages'][0]);
    }

    public function test_scan_items_storage_and_meta_warning() {
        update_option('trackwp_blocker', array('mode' => 'on', 'rules' => array('handle:sourcebuster-js' => array('block' => true, 'category' => 'marketing', 'vendor' => ''))));
        $this->observe_body = $this->body_with_map();
        $this->assertSame(200, $this->dispatch_scan()->get_status());
        $scan = get_option(TrackWP_Blocker_Scanner::OPTION);
        $this->assertNotContains('/', $scan['incomplete_pages']);

        $sb = self::item_by($scan, 'handle', 'sourcebuster-js');
        $this->assertNotNull($sb);
        $this->assertSame('handle:sourcebuster-js', $sb['rule_id']);
        $this->assertSame('blocked', $sb['status']);
        $this->assertContains('/', $sb['pages']);
        $this->assertContains('leadinfo-test', $sb['dependents']);
        $this->assertSame('woocommerce', $sb['plugin']);
        $this->assertNotNull(self::item_by($scan, 'handle', 'wc-order-attribution'), 'Inline -js-extra via id handle (S4)');
        $this->assertNull(self::item_by($scan, 'handle', 'jquery-core'), 'never-listed');
        $this->assertNull(self::item_by($scan, 'handle', 'trackwp-consent'), 'TrackWP itself');

        foreach (TrackWP_Consent_Profile::vendor_catalog() as $key => $v) {
            if (!empty($v['signatures']['handles']) && in_array('wc-order-attribution', $v['signatures']['handles'], true)) {
                $this->assertSame($key, self::item_by($scan, 'handle', 'wc-order-attribution')['vendor']);
            }
        }

        $json = wp_json_encode($scan);
        $this->assertStringNotContainsString('?ver=', $json, 'No query strings');
        $this->assertStringNotContainsString('fbq(', $json, 'No inline code');
        $this->assertStringNotContainsString('SECRETVALUE', $json, 'No cookie values');
        foreach ($scan['items'] as $item) {
            $this->assertStringNotContainsString('?', $item['path']);
            if ('' !== $item['marker']) {
                $this->assertMatchesRegularExpression('/^[A-Za-z0-9._-]{8,64}$/', $item['marker']);
            }
            $this->assertContains($item['status'], array('blocked', 'allowed', 'cannot_server', 'server_gated', 'cannot_serverside', 'cannot_bundled', 'gtm_consent_mode', 'protected', 'before_trackwp'));
        }

        $cookie = self::item_by($scan, 'handle', 'tk_ai');
        $this->assertSame('server_cookie', $cookie['kind']);
        $this->assertSame('cannot_server', $cookie['status']);
        $this->assertSame('cookie:tk_ai', $cookie['rule_id'], 'KC5.1: a valid cookie name gets a cookie: rule id even without a rule');

        // fb4woo prints loader and init in two scripts: one occurrence.
        $this->assertSame(array('pixel_script', 'gtm_server_side'), $scan['meta_sources']);
        $w = TrackWP_Blocker_Scanner::meta_warning($scan);
        $this->assertTrue($w['show']);
        $this->assertCount(2, $w['sources']);
        $this->assertFalse(get_transient(TrackWP_Blocker_Scanner::LOCK));
    }

    private function enable_own_pixel() {
        update_option('trackwp_platforms', array('meta_enabled' => true, 'meta_pixel_client_enabled' => true, 'meta_pixel_id' => '1010244770182613', 'meta_pixel_with_gtm' => false));
    }

    private function meta_of($html) {
        $out = TrackWP_Blocker_Scanner::parse_page($html, home_url('/'), TrackWP_Blocker_Scanner::scan_context(array()));
        return array('meta_sources' => $out['meta']);
    }

    public function test_meta_warning_trackwp_plus_fb4woo_shows() {
        $this->enable_own_pixel();
        $fixture = $this->meta_of(self::fixture());
        $this->assertSame(1, count(array_keys($fixture['meta_sources'], 'pixel_script', true)), 'fb4woo counted once');
        $w = TrackWP_Blocker_Scanner::meta_warning(array('meta_sources' => array('pixel_script')));
        $this->assertTrue($w['show']);
        $this->assertSame(array('TrackWP Meta Pixel', 'Meta Pixel (fbevents.js) fra et andet plugin, tema eller script'), $w['sources']);
    }

    public function test_meta_warning_two_foreign_pixels_show() {
        delete_option('trackwp_platforms');
        $scan = $this->meta_of('<html><head><script>window.trackwpConsentReader={};</script></head><body>'
            . '<script src="https://connect.facebook.net/en_US/fbevents.js"></script>'
            . '<script>!function(){var t=document.createElement("script");t.src="https://connect.facebook.net/en_US/fbevents.js";}();fbq(\'init\',\'22222\');</script>'
            . '</body></html>');
        $this->assertSame(array('pixel_script', 'pixel_script'), $scan['meta_sources']);
        $w = TrackWP_Blocker_Scanner::meta_warning($scan);
        $this->assertTrue($w['show']);
        $this->assertCount(2, $w['sources']);
    }

    public function test_meta_warning_only_trackwp_hides_and_m1_irrelevant() {
        $this->enable_own_pixel();
        $scan = $this->meta_of('<html><head><script>window.trackwpConsentReader={};</script>'
            . '<script id="trackwp-meta-pixel">window.trackwpMeta=1;!function(f){f.src="https://connect.facebook.net/en_US/fbevents.js"}({});fbq(\'init\',\'1010244770182613\');</script>'
            . '</head><body></body></html>');
        $w = TrackWP_Blocker_Scanner::meta_warning($scan);
        $this->assertFalse($w['show']);
        $this->assertSame(array('TrackWP Meta Pixel'), $w['sources']);

        $opts = get_option('trackwp_platforms');
        $opts['meta_pixel_with_gtm'] = true;
        update_option('trackwp_platforms', $opts);
        $this->assertSame(array('TrackWP Meta Pixel'), TrackWP_Blocker_Scanner::meta_warning($scan)['sources'], 'Counted with M1 on too');

        $opts['meta_pixel_id'] = 'abc';
        update_option('trackwp_platforms', $opts);
        $this->assertSame(array(), TrackWP_Blocker_Scanner::meta_warning(array('meta_sources' => array()))['sources'], 'Invalid pixel id: not counted');
    }

    public function test_meta_warning_gtm_without_m1_does_not_count_own_pixel() {
        $this->enable_own_pixel();
        $opts = get_option('trackwp_platforms');
        $opts['gtm_enabled'] = true;
        update_option('trackwp_platforms', $opts);
        $this->assertFalse(TrackWP::client_meta_pixel_will_render(), 'Producer: pixel not printed');
        $w = TrackWP_Blocker_Scanner::meta_warning(array('meta_sources' => array('pixel_script')));
        $this->assertNotContains('TrackWP Meta Pixel', $w['sources']);
        $this->assertFalse($w['show']);

        $opts['meta_pixel_with_gtm'] = true;
        update_option('trackwp_platforms', $opts);
        $this->assertContains('TrackWP Meta Pixel', TrackWP_Blocker_Scanner::meta_warning(array('meta_sources' => array()))['sources'], 'GTM with M1 prints the pixel');
    }

    public function test_trackwp_own_meta_pixel_is_ignored() {
        $html = '<html><head><script>window.trackwpConsentReader={};</script>'
            . '<script id="trackwp-meta-pixel">window.trackwpMeta=1;!function(f){f.src="https://connect.facebook.net/en_US/fbevents.js"}({});fbq(\'init\',\'123456\');</script>'
            . '</head><body></body></html>';
        $out = TrackWP_Blocker_Scanner::parse_page($html, home_url('/'), TrackWP_Blocker_Scanner::scan_context(array()));
        $this->assertSame(array(), $out['meta']);
        $this->assertSame(array(), $out['items']);
    }

    public function test_before_trackwp() {
        $html = '<html><head><script src="https://cdn.example.net/early.js?x=1"></script>'
            . '<script>window.trackwpConsentReader={};</script>'
            . '<script src="https://cdn.example.net/late.js"></script></head><body></body></html>';
        $out   = TrackWP_Blocker_Scanner::parse_page($html, home_url('/'), TrackWP_Blocker_Scanner::scan_context(array()));
        $by    = array();
        foreach ($out['items'] as $i) {
            $by[$i['path']] = $i;
        }
        $this->assertSame('before_trackwp', $by['/early.js']['status']);
        $this->assertSame('allowed', $by['/late.js']['status']);
        $this->assertSame('url:cdn.example.net/early.js', $by['/early.js']['rule_id']);
    }

    public function test_health_check_observed_in_html() {
        $rules = array(
            'handle:sourcebuster-js' => array('block' => true, 'category' => 'marketing', 'vendor' => ''),
            'host:cdn.leadinfo.net'  => array('block' => true, 'category' => 'marketing', 'vendor' => ''),
        );
        $this->visitor_body = '<html><head><script type="text/plain" data-twp-blocked="handle:sourcebuster-js" data-twp-src="/x.js"></script></head><body></body></html>';
        $h = TrackWP_Blocker_Scanner::health_check($rules);
        $this->assertTrue($h['observed']['handle:sourcebuster-js']);
        $this->assertFalse($h['observed']['host:cdn.leadinfo.net']);
        $this->assertArrayNotHasKey('X-TrackWP-Scan', end($this->requests)['args']['headers']);
    }

    /** KB13: every token the scan issued is gone afterwards, also when no page consumed it (cached page). */
    public function test_scan_deletes_unconsumed_tokens() {
        $seen = array();
        $spy  = function ($pre, $args) use (&$seen) {
            foreach ((array) (isset($args['headers']) ? $args['headers'] : array()) as $k => $v) {
                if (false !== stripos((string) $k, 'scan')) {
                    $seen[] = (string) $v;
                }
            }
            return $pre;
        };
        add_filter('pre_http_request', $spy, 5, 2);
        (new TrackWP_Blocker_Scanner())->run_scan();
        remove_filter('pre_http_request', $spy, 5);
        $this->assertNotEmpty($seen, 'the scan sent tokens');
        foreach ($seen as $t) {
            $this->assertFalse(get_transient('trackwp_blk_obs_' . hash('sha256', $t)), 'token left behind');
        }
    }

    /** server_cookie_items() is private; called via reflection (1.11.1 KC5). */
    private static function server_cookie_items($names, array $compiled, $mode) {
        $m = new ReflectionMethod('TrackWP_Blocker_Scanner', 'server_cookie_items');
        $m->setAccessible(true);
        return $m->invoke(null, $names, $compiled, $mode);
    }

    public function test_server_cookie_items_rule_id_and_server_gated_status() {
        // A valid cookie name always gets a cookie: rule id, whether or not a
        // rule exists for it. A one-character name is too short for the
        // cookie: rule id shape and gets ''.
        $compiled_empty = TrackWP_Blocker_Rules::compile(array());
        $items = self::server_cookie_items(array('example_cookie', 'x'), $compiled_empty, 'on');
        $this->assertSame('cookie:example_cookie', $items[0]['rule_id']);
        $this->assertSame('cannot_server', $items[0]['status'], 'no compiled rule -> cannot_server');
        $this->assertSame('', $items[1]['rule_id'], 'name too short for a valid cookie: id');
        $this->assertSame('cannot_server', $items[1]['status']);

        // With a compiled cookie: rule for the name and mode != off: server_gated.
        $compiled = TrackWP_Blocker_Rules::compile(array('rules' => array(
            'cookie:example_cookie' => array('block' => true, 'category' => 'marketing'),
        )));
        $gated = self::server_cookie_items(array('example_cookie'), $compiled, 'on');
        $this->assertSame('server_gated', $gated[0]['status']);

        // The same rule, but the blocker is off: still cannot_server.
        $off = self::server_cookie_items(array('example_cookie'), $compiled, 'off');
        $this->assertSame('cannot_server', $off[0]['status']);
    }

    public function test_server_cookie_items_category_guess_is_from_known_cookies_but_vendor_is_a_catalog_key_or_null() {
        $compiled = TrackWP_Blocker_Rules::compile(array());
        // PHPSESSID and _ga are declared by hardcoded, non-cataloged entries
        // in known_cookies() (provider text only, no catalog 'key'). Reviewer
        // finding M3: 'vendor' must never become a display string such as
        // "Webserver (PHP)" that a caller (TrackWP_Consent_Profile::
        // blocker_vendors()) would sanitize_key() into a phantom vendor.
        $items = self::server_cookie_items(array('PHPSESSID', '_ga', 'totally_unknown_cookie_name'), $compiled, 'on');
        $known = null;
        foreach (TrackWP_Cookie_Scanner::known_cookies() as $k) {
            if ('_ga' === $k['match']) {
                $known = $k;
                break;
            }
        }
        $this->assertNotNull($known, 'fixture assumption: _ga is a known cookie');
        $this->assertNull($items[0]['vendor'], 'PHPSESSID: no catalog key, must be null (M3)');
        $this->assertSame('Webserver (PHP)', $items[0]['vendor_label']);
        $this->assertSame($known['category'], $items[1]['category_guess']);
        $this->assertNull($items[1]['vendor'], '_ga: hardcoded provider text, no catalog key');
        $this->assertSame($known['provider'], $items[1]['vendor_label']);
        $this->assertSame('', $items[2]['category_guess'], 'unknown cookie: no category guess');
        $this->assertNull($items[2]['vendor']);
        $this->assertNull($items[2]['vendor_label']);
    }

    /** M3: a via_blocker vendor whose cookie token comes only from the catalog gets vendor = the real catalog key. */
    public function test_server_cookie_items_vendor_is_the_real_catalog_key_for_a_blocker_found_vendor() {
        update_option('trackwp_blocker', array('rules' => array()));
        update_option(TrackWP_Blocker_Scanner::OPTION, array('items' => array(
            array('rule_id' => 'handle:hotjar-script', 'vendor' => 'hotjar', 'kind' => 'handle'),
        )));
        $catalog = TrackWP_Consent_Profile::vendor_catalog();
        $this->assertArrayHasKey('hotjar', $catalog, 'fixture assumption: hotjar is in the catalog');

        $compiled = TrackWP_Blocker_Rules::compile(array());
        $items    = self::server_cookie_items(array('_hjid'), $compiled, 'on');
        $this->assertSame('hotjar', $items[0]['vendor']);
        $this->assertSame($catalog['hotjar']['provider'], $items[0]['vendor_label']);
        $this->assertSame('statistics', $items[0]['category_guess']);
    }
}
