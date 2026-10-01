<?php
/**
 * TrackWP_Cookie_Gate (1.11.1 KC2-KC5, §9 TR7): the narrow, explicit-only
 * server cookie gate.
 *
 * Producers:
 *  - tests/fixtures/cookie-gate/set-cookie-lines.json (a saved real curl -sI
 *    response plus RFC 6265 edge cases) for is_deletion().
 *  - TrackWP_Consent::write_consent_cookie() -> TrackWP_Cookies::sent_cookies()
 *    for the "consent written in the same request wins" test.
 *  - TrackWP_Blocker_Rules::compile() for every cookie-rule test.
 *
 * headers_list() is always empty under the CLI SAPI, so the actual
 * header_register_callback() registration and the real Set-Cookie mutation
 * cannot be observed here (D10); that is a release/manual-test item. This
 * file tests should_run(), consent(), is_deletion(), plan() and compile()'s
 * cookie: rules directly, plus on_headers()'s exception safety via a
 * pre_option_* filter that throws (a legitimate WordPress seam another
 * plugin could also use).
 */

if (!class_exists('TrackWP_Blocker_Rules')) {
    require_once dirname(__DIR__) . '/includes/class-trackwp-blocker-rules.php';
}
if (!class_exists('TrackWP_Cookie_Gate')) {
    require_once dirname(__DIR__) . '/includes/class-trackwp-cookie-gate.php';
}

class TrackWP_Cookie_Gate_Test extends WP_UnitTestCase {

    private $saved_server;

    public static function fixture() {
        static $data = null;
        if (null === $data) {
            $data = json_decode(file_get_contents(__DIR__ . '/fixtures/cookie-gate/set-cookie-lines.json'), true);
        }
        return $data;
    }

    public function set_up() {
        parent::set_up();
        $this->saved_server = $_SERVER;
        delete_option('trackwp_blocker');
        delete_option(TrackWP_Blocker_Rules::COMPILED_OPTION);
        delete_option(TrackWP_Blocker::STATUS_OPTION);
        update_option('trackwp_consent', array('consent_version' => 1, 'log_consent' => false, 'cookie_lifetime_months' => 12));
        $_COOKIE = array();
        $_GET    = array();
        TrackWP_Cookies::reset_sent();
        TrackWP_Cookie_Gate::reset_request_state();
        TrackWP_Blocker_Scanner::reset_request_state();
        set_current_screen('front');
    }

    public function tear_down() {
        $_SERVER = $this->saved_server;
        $_COOKIE = array();
        $_GET    = array();
        TrackWP_Cookies::reset_sent();
        TrackWP_Cookie_Gate::reset_request_state();
        TrackWP_Blocker_Scanner::reset_request_state();
        set_current_screen('front');
        parent::tear_down();
    }

    private static function compiled_with($rules, $exceptions = array()) {
        return TrackWP_Blocker_Rules::compile(array(
            'rules'      => $rules,
            'exceptions' => array_merge(array('allow' => array(), 'paths' => array()), $exceptions),
        ));
    }

    private static function consent($statistics = false, $marketing = false, $personalisation = false, $has_choice = true) {
        return array('has_choice' => $has_choice, 'statistics' => $statistics, 'marketing' => $marketing, 'personalisation' => $personalisation);
    }

    private static function save_blocker($mode, $rules = array(), $extra = array()) {
        update_option('trackwp_blocker', array_merge(array(
            'mode'       => $mode,
            'rules'      => $rules,
            'exceptions' => array('allow' => array(), 'paths' => array()),
        ), $extra));
        TrackWP_Blocker_Rules::rebuild();
    }

    /* ------------------------------------------------------------------
     * compile(): KC2 cookie rules
     * ------------------------------------------------------------------ */

    public function test_compile_only_explicit_cookie_rules_are_compiled_no_catalog_tokens() {
        $catalog_vendor = null;
        foreach (TrackWP_Consent_Profile::vendor_catalog() as $key => $v) {
            if (!empty($v['signatures']['hosts'])) {
                $catalog_vendor = $key;
                break;
            }
        }
        $this->assertNotNull($catalog_vendor, 'fixture assumption: at least one catalog vendor has host signatures');

        $c = self::compiled_with(array(
            'cookie:my_marketing_cookie' => array('block' => true, 'category' => 'marketing', 'vendor' => $catalog_vendor),
        ));
        $this->assertCount(1, $c['cookies']);
        $this->assertSame(array('my_marketing_cookie', 'marketing', 'cookie:my_marketing_cookie'), $c['cookies'][0]);
        // A cookie: rule's vendor is display-only: it must never pull in that
        // vendor's other (url/host/pixel) catalog signatures.
        $this->assertSame(array(), $c['urls']);
        $this->assertSame(array(), $c['hosts']);
        $this->assertSame(array(), $c['pixels']);
    }

    public function test_compile_never_starts_the_html_buffer_even_with_cookie_rules() {
        $c = self::compiled_with(array(
            'cookie:only_a_cookie_rule' => array('block' => true, 'category' => 'statistics'),
        ));
        $this->assertTrue(TrackWP_Blocker_Rules::has_cookie_rules($c), 'has_cookie_rules() sees the rule');
        $this->assertFalse(TrackWP_Blocker_Rules::has_block_rules($c), 'has_block_rules() is unchanged by cookie rules (KC2)');
    }

    public function test_compile_exceptions_allow_wins_over_a_cookie_rule() {
        $c = self::compiled_with(
            array('cookie:allowed_cookie' => array('block' => true, 'category' => 'marketing')),
            array('allow' => array('cookie:allowed_cookie'))
        );
        $this->assertSame(array(), $c['cookies'], 'allowed rule is excluded from the compiled cookie list');
        $this->assertContains('allowed_cookie', $c['never']['cookies'], 'allowed rule id lands in never.cookies instead');
        $this->assertFalse(TrackWP_Blocker_Rules::has_cookie_rules($c));
    }

    public function test_compile_sorts_cookies_exact_first_then_longest_prefix_then_alphabetical() {
        $c = self::compiled_with(array(
            'cookie:short*'      => array('block' => true, 'category' => 'marketing'),
            'cookie:longer_pre*' => array('block' => true, 'category' => 'marketing'),
            'cookie:exact_name'  => array('block' => true, 'category' => 'marketing'),
            'cookie:aaa_name'    => array('block' => true, 'category' => 'marketing'),
        ));
        $order = array_column($c['cookies'], 0);
        $this->assertSame(array('aaa_name', 'exact_name', 'longer_pre*', 'short*'), $order);
    }

    public function test_is_valid_rule_id_accepts_cookie_ids_with_optional_wildcard() {
        $this->assertTrue(TrackWP_Blocker_Rules::is_valid_rule_id('cookie:ab'));
        $this->assertTrue(TrackWP_Blocker_Rules::is_valid_rule_id('cookie:ab_cd.ef-*'));
        $this->assertTrue(TrackWP_Blocker_Rules::is_valid_rule_id('cookie:ab_cd.ef-'));
        $this->assertTrue(TrackWP_Blocker_Rules::is_valid_rule_id('cookie:prefix*'));
        $this->assertFalse(TrackWP_Blocker_Rules::is_valid_rule_id('cookie:a'), 'too short (min 2)');
        $this->assertFalse(TrackWP_Blocker_Rules::is_valid_rule_id('cookie:has space'));
        $this->assertFalse(TrackWP_Blocker_Rules::is_valid_rule_id('cookie:has;semi'));
        $this->assertFalse(TrackWP_Blocker_Rules::is_valid_rule_id(''));
    }

    /* ------------------------------------------------------------------
     * plan(): an explicit cookie rule (the general case)
     * ------------------------------------------------------------------ */

    public function test_plan_drops_a_gated_cookie_without_matching_consent_and_keeps_it_with_consent() {
        $c     = self::compiled_with(array('cookie:example_marketing' => array('block' => true, 'category' => 'marketing')));
        $lines = array('Set-Cookie: example_marketing=x; Path=/');

        $r1 = TrackWP_Cookie_Gate::plan($lines, self::consent(), $c, time());
        $this->assertSame(array(), $r1['keep']);
        $this->assertSame(array('example_marketing'), $r1['drop']);
        $this->assertFalse($r1['kept_gated']);

        $r2 = TrackWP_Cookie_Gate::plan($lines, self::consent(false, true), $c, time());
        $this->assertSame($lines, $r2['keep']);
        $this->assertSame(array(), $r2['drop']);
        $this->assertTrue($r2['kept_gated']);
    }

    public function test_plan_keeps_a_cookie_with_no_matching_rule_untouched() {
        $c     = self::compiled_with(array());
        $lines = array('Set-Cookie: unrelated=x; Path=/');
        $r     = TrackWP_Cookie_Gate::plan($lines, self::consent(), $c, time());
        $this->assertSame($lines, $r['keep']);
        $this->assertSame(array(), $r['drop']);
        $this->assertFalse($r['kept_gated'], 'a plain keep (no rule) is not "kept_gated"');
    }

    public function test_plan_ignores_non_set_cookie_header_lines() {
        $c     = self::compiled_with(array('cookie:example_marketing' => array('block' => true, 'category' => 'marketing')));
        $lines = array('X-Custom: whatever', 'set-cookie: example_marketing=x; Path=/');
        $r     = TrackWP_Cookie_Gate::plan($lines, self::consent(), $c, time());
        $this->assertSame(array(), $r['keep']);
        $this->assertSame(array('example_marketing'), $r['drop'], 'header name match is case-insensitive');
    }

    /* ------------------------------------------------------------------
     * never-listed names always win (KC3)
     * ------------------------------------------------------------------ */

    public function test_never_listed_names_are_always_kept_even_with_a_matching_rule() {
        $names = array(
            'trackwp_consent',
            'wordpress_logged_in_deadbeef',
            'wordpress_sec_deadbeef',
            'wordpress_test_cookie',
            'wp-postpass_deadbeef',
            'wp_woocommerce_session_deadbeef',
            'woocommerce_cart_hash',
            'woocommerce_items_in_cart',
        );
        foreach ($names as $name) {
            // A rogue rule for the exact never-name must never win over NEVER.
            $c    = self::compiled_with(array('cookie:' . $name => array('block' => true, 'category' => 'marketing')));
            $line = 'Set-Cookie: ' . $name . '=x; Path=/';
            $r    = TrackWP_Cookie_Gate::plan(array($line), self::consent(), $c, time());
            $this->assertSame(array($line), $r['keep'], $name);
            $this->assertSame(array(), $r['drop'], $name);
        }
    }

    public function test_never_filter_and_option_never_cookies_are_both_honoured() {
        $c = self::compiled_with(
            array('cookie:from_allow' => array('block' => true, 'category' => 'marketing')),
            array('allow' => array('cookie:from_allow'))
        );
        $line = 'Set-Cookie: from_allow=x; Path=/';
        $r    = TrackWP_Cookie_Gate::plan(array($line), self::consent(), $c, time());
        $this->assertSame(array($line), $r['keep'], 'exceptions.allow -> never.cookies is honoured by the gate');

        add_filter('trackwp_cookie_gate_never', function ($never) {
            $never[] = 'filtered_never_cookie';
            return $never;
        });
        $c2   = self::compiled_with(array('cookie:filtered_never_cookie' => array('block' => true, 'category' => 'marketing')));
        $line2 = 'Set-Cookie: filtered_never_cookie=x; Path=/';
        $r2   = TrackWP_Cookie_Gate::plan(array($line2), self::consent(), $c2, time());
        $this->assertSame(array($line2), $r2['keep']);
        remove_all_filters('trackwp_cookie_gate_never');
    }

    /* ------------------------------------------------------------------
     * RFC 6265 deletion rules (KC4.2), from the saved/constructed fixture
     * ------------------------------------------------------------------ */

    public function test_is_deletion_vectors() {
        $now = strtotime('2026-06-01T00:00:00Z');
        foreach (self::fixture()['constructed'] as $case) {
            if (!array_key_exists('is_deletion', $case)) {
                continue;
            }
            $this->assertSame(
                $case['is_deletion'],
                TrackWP_Cookie_Gate::is_deletion($case['line'], $now),
                $case['name'] . ': ' . $case['description']
            );
        }
    }

    public function test_plan_applies_the_deletion_rule_end_to_end() {
        $c   = self::compiled_with(array('cookie:example_marketing' => array('block' => true, 'category' => 'marketing')));
        $now = time();

        // Max-Age>0 has precedence over a past Expires: not a deletion, so it
        // is classified and, without consent, dropped.
        $line1 = 'Set-Cookie: example_marketing=x; Max-Age=3600; Expires=Mon, 01 Jan 2001 00:00:00 GMT; Path=/';
        $r1    = TrackWP_Cookie_Gate::plan(array($line1), self::consent(), $c, $now);
        $this->assertSame(array('example_marketing'), $r1['drop']);

        // Max-Age=0 is a deletion and is always kept.
        $line2 = 'Set-Cookie: example_marketing=x; Max-Age=0; Path=/';
        $r2    = TrackWP_Cookie_Gate::plan(array($line2), self::consent(), $c, $now);
        $this->assertSame(array($line2), $r2['keep']);
        $this->assertSame(array(), $r2['drop']);

        // Invalid Max-Age with a future Expires: not a deletion, so it is
        // dropped without consent.
        $line3 = 'Set-Cookie: example_marketing=x; Max-Age=notanumber; Expires=Fri, 01 Jan 2999 00:00:00 GMT; Path=/';
        $r3    = TrackWP_Cookie_Gate::plan(array($line3), self::consent(), $c, $now);
        $this->assertSame(array('example_marketing'), $r3['drop']);
    }

    /* ------------------------------------------------------------------
     * __Host-/__Secure- prefixes and duplicate names (KC4.1/.4)
     * ------------------------------------------------------------------ */

    public function test_plan_host_prefix_is_part_of_the_name() {
        $c    = self::compiled_with(array('cookie:__Host-example_marketing' => array('block' => true, 'category' => 'marketing')));
        $line = 'Set-Cookie: __Host-example_marketing=x; Path=/; Secure';
        $r    = TrackWP_Cookie_Gate::plan(array($line), self::consent(), $c, time());
        $this->assertSame(array('__Host-example_marketing'), $r['drop']);
    }

    public function test_plan_treats_duplicate_names_independently() {
        $c     = self::compiled_with(array('cookie:dup_example' => array('block' => true, 'category' => 'marketing')));
        $lines = array(
            'Set-Cookie: dup_example=x; Path=/',
            'Set-Cookie: dup_example=x; Max-Age=0; Path=/',
        );
        $r = TrackWP_Cookie_Gate::plan($lines, self::consent(), $c, time());
        $this->assertSame(array('dup_example'), $r['drop'], 'the non-deletion line is dropped');
        $this->assertSame(array($lines[1]), $r['keep'], 'the deletion line is always kept');
    }

    /* ------------------------------------------------------------------
     * consent(): current-request value wins over the already-sent cookie
     * ------------------------------------------------------------------ */

    public function test_consent_prefers_the_value_written_by_the_same_request() {
        // Simulate an old cookie on the incoming request (not what this
        // request writes).
        $_COOKIE[TrackWP_Cookies::CONSENT_COOKIE] = wp_json_encode(array(
            'v' => 1, 'ts' => '2026-01-01T00:00:00.000Z', 'id' => wp_generate_uuid4(),
            'necessary' => true, 'statistics' => false, 'marketing' => false, 'personalisation' => false,
        ));
        $this->assertFalse(TrackWP_Cookie_Gate::consent()['marketing'], 'baseline: the incoming cookie says no marketing');

        $ok = TrackWP_Consent::write_consent_cookie(1, (int) (microtime(true) * 1000), wp_generate_uuid4(), true, true, false);
        $this->assertTrue($ok);

        $c = TrackWP_Cookie_Gate::consent();
        $this->assertTrue($c['has_choice']);
        $this->assertTrue($c['statistics']);
        $this->assertTrue($c['marketing']);
        $this->assertFalse($c['personalisation']);
    }

    public function test_consent_empty_value_written_this_request_means_no_choice() {
        TrackWP_Cookies::set(TrackWP_Cookies::CONSENT_COOKIE, '', time() - 100, 'host');
        $c = TrackWP_Cookie_Gate::consent();
        $this->assertFalse($c['has_choice']);
        $this->assertFalse($c['statistics']);
        $this->assertFalse($c['marketing']);
        $this->assertFalse($c['personalisation']);
    }

    public function test_consent_falls_back_to_the_sent_cookie_when_nothing_written_this_request() {
        $_COOKIE[TrackWP_Cookies::CONSENT_COOKIE] = wp_json_encode(array(
            'v' => 1, 'ts' => '2026-01-01T00:00:00.000Z', 'id' => wp_generate_uuid4(),
            'necessary' => true, 'statistics' => true, 'marketing' => false, 'personalisation' => false,
        ));
        $c = TrackWP_Cookie_Gate::consent();
        $this->assertTrue($c['has_choice']);
        $this->assertTrue($c['statistics']);
        $this->assertFalse($c['marketing']);
    }

    public function test_consent_no_choice_at_all() {
        $c = TrackWP_Cookie_Gate::consent();
        $this->assertFalse($c['has_choice']);
        $this->assertFalse($c['statistics']);
        $this->assertFalse($c['marketing']);
        $this->assertFalse($c['personalisation']);
    }

    /* ------------------------------------------------------------------
     * should_run(): matrix
     * ------------------------------------------------------------------ */

    public function test_should_run_matrix() {
        $rules = array('cookie:example_marketing' => array('block' => true, 'category' => 'marketing'));

        self::save_blocker('off', $rules);
        $this->assertFalse(TrackWP_Cookie_Gate::should_run(), 'mode off');

        self::save_blocker('on', array());
        $this->assertFalse(TrackWP_Cookie_Gate::should_run(), 'mode on, no cookie rules');

        self::save_blocker('on', $rules);
        $this->assertTrue(TrackWP_Cookie_Gate::should_run(), 'mode on with a cookie rule');

        self::save_blocker('test', $rules);
        $this->assertFalse(TrackWP_Cookie_Gate::should_run(), 'mode test, no capability');
        wp_set_current_user(self::factory()->user->create(array('role' => 'administrator')));
        $this->assertTrue(TrackWP_Cookie_Gate::should_run(), 'mode test, manage_options');
        wp_set_current_user(0);

        self::save_blocker('on', $rules);
        add_filter('wp_doing_cron', '__return_true');
        $this->assertFalse(TrackWP_Cookie_Gate::should_run(), 'cron');
        remove_filter('wp_doing_cron', '__return_true');

        set_current_screen('dashboard');
        $this->assertFalse(TrackWP_Cookie_Gate::should_run(), 'wp-admin, not AJAX');
        add_filter('wp_doing_ajax', '__return_true');
        $this->assertTrue(TrackWP_Cookie_Gate::should_run(), 'wp-admin AJAX');
        remove_filter('wp_doing_ajax', '__return_true');
        set_current_screen('front');

        add_filter('trackwp_cookie_gate_should_run', '__return_false');
        $this->assertFalse(TrackWP_Cookie_Gate::should_run(), 'should_run filter');
        remove_filter('trackwp_cookie_gate_should_run', '__return_false');

        $this->assertTrue(TrackWP_Cookie_Gate::should_run(), 'back to baseline true');
    }

    public function test_should_run_respects_exceptions_paths() {
        self::save_blocker('on', array('cookie:example_marketing' => array('block' => true, 'category' => 'marketing')), array(
            'exceptions' => array('allow' => array(), 'paths' => array('/no-gate/')),
        ));
        $_SERVER['REQUEST_URI'] = '/no-gate/checkout/';
        $this->assertFalse(TrackWP_Cookie_Gate::should_run());
        $_SERVER['REQUEST_URI'] = '/shop/';
        $this->assertTrue(TrackWP_Cookie_Gate::should_run());
    }

    public function test_should_run_is_false_during_an_observe_scan_request() {
        self::save_blocker('on', array('cookie:example_marketing' => array('block' => true, 'category' => 'marketing')));
        $admin = self::factory()->user->create(array('role' => 'administrator'));
        $token = TrackWP_Blocker_Scanner::issue_token($admin);
        $_SERVER['REQUEST_METHOD']       = 'GET';
        $_SERVER['HTTP_X_TRACKWP_SCAN'] = $token;
        $this->assertFalse(TrackWP_Cookie_Gate::should_run());
        unset($_SERVER['HTTP_X_TRACKWP_SCAN']);
    }

    /* ------------------------------------------------------------------
     * nocache_params() (§9 TR7)
     * ------------------------------------------------------------------ */

    public function test_nocache_params_defaults_when_option_key_is_missing() {
        update_option('trackwp_blocker', array('mode' => 'on'));
        $params = TrackWP_Cookie_Gate::nocache_params();
        $this->assertContains('gclid', $params);
        $this->assertContains('fbclid', $params);
        $this->assertContains('msclkid', $params);
    }

    public function test_nocache_params_uses_the_configured_list_including_an_explicit_empty_one() {
        update_option('trackwp_blocker', array('mode' => 'on', 'nocache_params' => array('pacid', 'ref')));
        $this->assertSame(array('pacid', 'ref'), TrackWP_Cookie_Gate::nocache_params());

        update_option('trackwp_blocker', array('mode' => 'on', 'nocache_params' => array()));
        $this->assertSame(array(), TrackWP_Cookie_Gate::nocache_params(), 'an explicit empty list is honoured, not defaulted');
    }

    public function test_maybe_nocache_click_id_sets_no_cache_when_a_click_id_param_is_present_and_the_gate_is_active() {
        self::save_blocker('on', array('cookie:example_marketing' => array('block' => true, 'category' => 'marketing')));
        $_GET['gclid'] = 'abc';
        $fired = false;
        add_action('litespeed_control_set_nocache', function () use (&$fired) {
            $fired = true;
        });
        TrackWP_Cookie_Gate::maybe_nocache_click_id();
        $this->assertTrue($fired);
        $this->assertTrue(defined('DONOTCACHEPAGE') && DONOTCACHEPAGE);
    }

    public function test_maybe_nocache_click_id_does_nothing_when_the_gate_is_inactive() {
        self::save_blocker('off', array());
        $_GET['gclid'] = 'abc';
        $fired = false;
        add_action('litespeed_control_set_nocache', function () use (&$fired) {
            $fired = true;
        });
        TrackWP_Cookie_Gate::maybe_nocache_click_id();
        $this->assertFalse($fired);
    }

    /* ------------------------------------------------------------------
     * on_headers(): exception safety (D10 note: real header mutation
     * cannot be exercised under the CLI SAPI, so only the catch is tested)
     * ------------------------------------------------------------------ */

    public function test_on_headers_records_status_and_does_not_throw_when_a_dependency_throws() {
        add_filter('pre_option_' . TrackWP_Blocker_Rules::COMPILED_OPTION, function () {
            throw new RuntimeException('simulated failure from another plugin');
        });
        TrackWP_Cookie_Gate::on_headers();
        $status = get_option(TrackWP_Blocker::STATUS_OPTION);
        $this->assertSame('cookie_gate_exception', $status['last_error']['code']);
        remove_all_filters('pre_option_' . TrackWP_Blocker_Rules::COMPILED_OPTION);
    }
}
