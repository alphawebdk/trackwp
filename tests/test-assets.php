<?php
/**
 * Front-end asset loading and the wp_head inline scripts (W8).
 *
 * Covers: strategy=defer via $args on WP >= 6.3 with the right head/footer
 * group, the bool fallback below 6.3 (BESLUTNINGER §6), the order of the one
 * prio-1 inline script (R3), that no inline gate parses the consent cookie
 * itself, and the cleaned gtag page fields (R16).
 *
 * The reader and cleaner snippets asserted on are produced by their real
 * producers, TrackWP_Consent::reader_js() and TrackWP_Privacy::cleaner_js().
 *
 * @group assets
 */
class TrackWP_Assets_Test extends WP_UnitTestCase {

    /** @var string */
    private $real_wp_version;

    public function set_up() {
        parent::set_up();
        $this->real_wp_version = $GLOBALS['wp_version'];
        $GLOBALS['wp_scripts']  = null; // Fresh WP_Scripts per test.
        update_option('trackwp_advanced', array(
            'consent_mode_ad_signals'       => true,
            'consent_mode_cookieless_pings' => true,
            'first_party_loader_enabled'    => false,
            'cookie_lifetime_months'        => 24,
        ));
        update_option('trackwp_consent', array('consent_version' => 3));
        // TrackWP_Blocker keeps a static instance with the decision of the
        // last request it saw; a new instance is undecided again.
        new TrackWP_Blocker();
    }

    public function tear_down() {
        $GLOBALS['wp_version'] = $this->real_wp_version;
        $GLOBALS['wp_scripts'] = null;
        parent::tear_down();
    }

    private function render($method) {
        ob_start();
        TrackWP::instance()->$method();
        return (string) ob_get_clean();
    }

    public function test_frontend_scripts_are_deferred_with_correct_groups() {
        if ( version_compare($this->real_wp_version, '6.3', '<') ) {
            $this->markTestSkipped('strategy requires WP 6.3+.');
        }
        TrackWP::instance()->enqueue_frontend_scripts();
        $scripts = wp_scripts();

        $this->assertTrue(wp_script_is('trackwp-consent', 'enqueued'));
        $this->assertTrue(wp_script_is('trackwp-tracking', 'enqueued'));
        $this->assertSame('defer', $scripts->get_data('trackwp-consent', 'strategy'));
        $this->assertSame('defer', $scripts->get_data('trackwp-tracking', 'strategy'));
        // consent.js stays in <head> (no group), trackwp.js goes to the footer.
        $this->assertEmpty($scripts->get_data('trackwp-consent', 'group'));
        $this->assertSame(1, (int) $scripts->get_data('trackwp-tracking', 'group'));
        // The old 'async' extra is gone.
        $this->assertEmpty($scripts->get_data('trackwp-consent', 'async'));
        $this->assertEmpty($scripts->get_data('trackwp-tracking', 'async'));
    }

    public function test_head_script_tag_has_defer_attribute() {
        if ( version_compare($this->real_wp_version, '6.3', '<') ) {
            $this->markTestSkipped('strategy requires WP 6.3+.');
        }
        TrackWP::instance()->enqueue_frontend_scripts();
        ob_start();
        wp_print_head_scripts();
        $head = (string) ob_get_clean();
        // Attribute order is WP's business: find the tag, then check its attributes.
        $this->assertSame(1, preg_match('/<script\b[^>]*\bid=[\'"]trackwp-consent-js[\'"][^>]*>/', $head, $m), 'consent.js tag printed in head');
        $this->assertMatchesRegularExpression('/\sdefer(?:[\s>=\/]|$)/', $m[0]);
        $this->assertDoesNotMatchRegularExpression('/\sasync(?:[\s>=\/]|$)/', $m[0]);
    }

    public function test_below_6_3_passes_bool_and_keeps_head_script_in_head() {
        $GLOBALS['wp_version'] = '6.2.6';
        TrackWP::enqueue_deferred_script('twp-test-head', 'https://example.org/h.js', array(), false);
        TrackWP::enqueue_deferred_script('twp-test-foot', 'https://example.org/f.js', array(), true);
        $scripts = wp_scripts();

        $this->assertEmpty($scripts->get_data('twp-test-head', 'strategy'));
        $this->assertEmpty($scripts->get_data('twp-test-head', 'group'), 'An $args array would be truthy on < 6.3 and move the script to the footer.');
        $this->assertSame(1, (int) $scripts->get_data('twp-test-foot', 'group'));
    }

    public function test_prio1_inline_script_order_reader_cleaner_defaults_restore() {
        $out     = $this->render('render_consent_mode_defaults');
        $reader  = TrackWP_Consent::reader_js();
        $cleaner = TrackWP_Privacy::cleaner_js();

        $this->assertSame(1, substr_count($out, '<script'), 'Exactly one inline script at wp_head prio 1.');
        $p_reader   = strpos($out, $reader);
        $p_cleaner  = strpos($out, $cleaner);
        $p_defaults = strpos($out, "gtag('consent','default'");
        $p_restore  = strpos($out, 'window.trackwpConsentReader.read()', (int) $p_defaults);

        $this->assertNotFalse($p_reader);
        $this->assertNotFalse($p_cleaner);
        $this->assertNotFalse($p_defaults);
        $this->assertNotFalse($p_restore);
        $this->assertLessThan($p_defaults, $p_reader);
        $this->assertLessThan($p_restore, $p_defaults);
        $this->assertStringContainsString("gtag('consent','update'", substr($out, $p_restore));
    }

    public function test_no_inline_gate_parses_the_consent_cookie() {
        update_option('trackwp_advanced', array(
            'consent_mode_cookieless_pings' => false, // gated (basic) mode
            'first_party_loader_enabled'    => false,
        ));
        update_option('trackwp_platforms', array(
            'ga4_enabled'               => true,
            'ga4_gtag_enabled'          => true,
            'ga4_measurement_id'        => 'G-TEST1234',
            'meta_enabled'              => true,
            'meta_pixel_client_enabled' => true,
            'meta_pixel_id'             => '123456789012345',
        ));
        $head = $this->render('render_consent_mode_defaults')
            . $this->render('render_gtag_head')
            . $this->render('render_meta_pixel');

        $reader = TrackWP_Consent::reader_js();
        // Every document.cookie access in the head output belongs to the reader.
        $this->assertSame(substr_count($reader, 'document.cookie'), substr_count($head, 'document.cookie'));
        $this->assertSame(2, substr_count($head, 'function hasConsent(){try{var d=window.trackwpConsentReader'));

        update_option('trackwp_platforms', array(
            'gtm_enabled'      => true,
            'gtm_container_id' => 'GTM-ABC1234',
        ));
        $gtm = $this->render('render_gtm_head');
        $this->assertStringNotContainsString('document.cookie', $gtm);
        $this->assertStringContainsString('window.trackwpConsentReader', $gtm);
    }

    public function test_gtag_sets_cleaned_page_fields_before_config_and_ec_on_ads() {
        update_option('trackwp_platforms', array(
            'ga4_enabled'              => true,
            'ga4_gtag_enabled'         => true,
            'ga4_measurement_id'       => 'G-TEST1234',
            'google_ads_enabled'       => true,
            'google_ads_conversion_id' => 'AW-1234567890',
        ));
        $out = $this->render('render_gtag_head');

        $p_set    = strpos($out, "gtag('set',pg)");
        $p_config = strpos($out, "gtag('config'");
        $this->assertNotFalse($p_set);
        $this->assertLessThan($p_config, $p_set);
        $this->assertStringContainsString('P.cleanUrl(location.href)', $out);
        $this->assertStringContainsString('P.cleanTitle(document.title)', $out);
        $this->assertStringContainsString('P.cleanUrl(document.referrer)', $out);
        $this->assertStringContainsString("gtag('config','AW-1234567890',cfg({'allow_enhanced_conversions':true}))", $out);

        $adv = get_option('trackwp_advanced');
        $adv['customer_data_sharing'] = false;
        update_option('trackwp_advanced', $adv);
        $out = $this->render('render_gtag_head');
        $this->assertStringNotContainsString('allow_enhanced_conversions', $out);
    }

    /**
     * Decode the JSON assigned to window.<$var> in the handle's 'before'
     * inline script, as the browser would see it.
     */
    private function inline_config($handle, $var) {
        $before = wp_scripts()->get_data($handle, 'before');
        $js     = is_array($before) ? implode("\n", $before) : (string) $before;
        $this->assertSame(1, preg_match('/window\.' . preg_quote($var, '/') . '=(\{.*\});/s', $js, $m), "window.$var printed before $handle");
        $cfg = json_decode($m[1], true);
        $this->assertIsArray($cfg);
        return array($m[1], $cfg);
    }

    public function test_frontend_config_is_json_with_real_types_and_k8_keys() {
        $adv = get_option('trackwp_advanced');
        $adv['customer_data_sharing']      = false;
        $adv['first_party_cookie_enabled'] = false;
        $adv['debug_console']              = false;
        update_option('trackwp_advanced', $adv);

        TrackWP::instance()->enqueue_frontend_scripts();
        // Not wp_localize_script any more: that stringifies every scalar.
        $this->assertEmpty(wp_scripts()->get_data('trackwp-tracking', 'data'));
        $this->assertEmpty(wp_scripts()->get_data('trackwp-consent', 'data'));

        list($raw, $cfg) = $this->inline_config('trackwp-tracking', 'trackwpConfig');
        $this->assertStringContainsString('"customerDataSharing":false', $raw);
        $this->assertStringContainsString('"fpCookieEnabled":false', $raw);
        $this->assertStringContainsString('"debugAllowed":false', $raw);
        foreach ( array('consentVersion', 'fpCookieDays', 'urlDenylist', 'defaultPhoneCountry', 'metaEventMap', 'debugAllowed', 'customerDataSharing', 'cookieDomain') as $key ) {
            $this->assertArrayHasKey($key, $cfg, $key);
        }
        // The 1.10.0 'debug' key logged for every visitor; debugAllowed replaces it.
        $this->assertArrayNotHasKey('debug', $cfg);
        $this->assertSame(TrackWP_Cookies::lifetime_days('_twp_cid'), $cfg['fpCookieDays']);
        $this->assertSame(3, $cfg['consentVersion']);
        $this->assertIsArray($cfg['urlDenylist']);
        // The printed config is exactly what the public producer returns.
        $this->assertSame(json_decode(wp_json_encode(TrackWP::frontend_config()), true), $cfg);

        $adv['customer_data_sharing'] = true;
        update_option('trackwp_advanced', $adv);
        $this->assertTrue(TrackWP::frontend_config()['customerDataSharing']);
    }

    public function test_consent_config_is_json_with_real_types() {
        TrackWP::instance()->enqueue_frontend_scripts();
        list($raw, $cfg) = $this->inline_config('trackwp-consent', 'trackwpConsentConfig');
        $this->assertStringContainsString('"showRejectButton":true', $raw);
        $this->assertSame(3, $cfg['consentVersion']);
        $this->assertIsInt($cfg['cookieLifetimeMonths']);
        $this->assertIsBool($cfg['log_consent']);
        $this->assertSame(json_decode(wp_json_encode(TrackWP::consent_config()), true), $cfg);
    }

    /**
     * Blocking on for an anonymous front-end GET, set up through the real
     * producers: the option is saved (update_option_trackwp_blocker compiles
     * via TrackWP_Blocker_Rules::compile()) and the request context is a
     * plain front-end page view.
     */
    private function enable_blocking() {
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $this->go_to(home_url('/'));
        new TrackWP_Blocker(); // Fresh, undecided instance (see set_up).
        update_option('trackwp_blocker', array(
            'mode'           => 'on',
            'rules'          => array(
                'host:cdn.leadinfo.net' => array('block' => true, 'category' => 'marketing', 'vendor' => 'leadinfo'),
            ),
            'custom_vendors' => array(),
            'exceptions'     => array('allow' => array(), 'paths' => array()),
            'extra_paths'    => array(),
        ));
        $this->assertTrue(TrackWP_Blocker::active_for_request(), 'Precondition: blocking active for this request.');
    }

    private function disable_blocking() {
        update_option('trackwp_blocker', array(
            'mode'           => 'off',
            'rules'          => array(),
            'custom_vendors' => array(),
            'exceptions'     => array('allow' => array(), 'paths' => array()),
            'extra_paths'    => array(),
        ));
        $this->assertFalse(TrackWP_Blocker::active_for_request());
    }

    public function test_prio1_with_blocking_on_order_reader_cleaner_guard_defaults_restore() {
        if ( version_compare($this->real_wp_version, '6.5', '<') ) {
            $this->markTestSkipped('Blocking requires WP 6.5+.');
        }
        $this->enable_blocking();
        $out   = $this->render('render_consent_mode_defaults');
        $guard = TrackWP_Blocker::guard_js();

        $this->assertNotSame('', $guard);
        $this->assertSame(1, substr_count($out, '<script'), 'Still exactly one inline script at prio 1.');
        $this->assertSame(1, preg_match('/<script\b[^>]*>/', $out, $tag));
        $this->assertStringContainsString('data-cfasync="false"', $tag[0]);
        $this->assertStringContainsString('data-no-optimize="1"', $tag[0]);
        $this->assertStringContainsString('data-no-defer="1"', $tag[0]);
        $this->assertStringNotContainsString(' src=', $tag[0]);

        $p_reader   = strpos($out, TrackWP_Consent::reader_js());
        $p_cleaner  = strpos($out, TrackWP_Privacy::cleaner_js());
        $p_guard    = strpos($out, $guard);
        $p_defaults = strpos($out, "gtag('consent','default'");
        $p_restore  = strpos($out, 'window.trackwpConsentReader.read()', (int) $p_defaults);
        foreach ( array($p_reader, $p_cleaner, $p_guard, $p_defaults, $p_restore) as $p ) {
            $this->assertNotFalse($p);
        }
        $this->assertLessThan($p_cleaner, $p_reader);
        $this->assertLessThan($p_guard, $p_cleaner);
        $this->assertLessThan($p_defaults, $p_guard);
        $this->assertLessThan($p_restore, $p_defaults);
    }

    public function test_prio1_with_blocking_off_has_no_guard_and_plain_tag() {
        $this->disable_blocking();
        $out = $this->render('render_consent_mode_defaults');
        $this->assertStringContainsString("-->\n<script>", $out);
        $this->assertStringNotContainsString('data-cfasync', $out);
        $this->assertStringNotContainsString('trackwpBlocker', $out);
    }

    public function test_consent_config_reports_blocker_active() {
        $this->disable_blocking();
        $this->assertFalse(TrackWP::consent_config()['blockerActive']);
        if ( version_compare($this->real_wp_version, '6.5', '>=') ) {
            $this->enable_blocking();
            $this->assertTrue(TrackWP::consent_config()['blockerActive']);
        }
    }

    public function test_gtm_noscript_left_out_while_blocking() {
        update_option('trackwp_platforms', array(
            'gtm_enabled'      => true,
            'gtm_container_id' => 'GTM-ABC1234',
        ));
        $this->disable_blocking();
        $this->assertStringContainsString('googletagmanager.com/ns.html?id=GTM-ABC1234', $this->render('render_gtm_noscript'));
        if ( version_compare($this->real_wp_version, '6.5', '<') ) {
            return;
        }
        $this->enable_blocking();
        $this->assertSame('', $this->render('render_gtm_noscript'));
    }

    private function meta_platforms(array $extra) {
        update_option('trackwp_platforms', $extra + array(
            'gtm_enabled'               => true,
            'gtm_container_id'          => 'GTM-ABC1234',
            'meta_enabled'              => true,
            'meta_pixel_client_enabled' => true,
            'meta_pixel_id'             => '123456789012345',
        ));
    }

    public function test_meta_pixel_with_gtm_needs_m1() {
        $this->meta_platforms(array('meta_pixel_with_gtm' => false));
        $this->assertSame('', $this->render('render_meta_pixel'), 'GTM without M1: the container owns Meta.');

        $this->meta_platforms(array('meta_pixel_with_gtm' => true));
        $out = $this->render('render_meta_pixel');
        $this->assertStringContainsString('<script id="trackwp-meta-pixel">', $out);
        $this->assertStringContainsString('trackwpMeta', $out);
        $this->assertStringContainsString("fbq('init','123456789012345'", $out);

        // uses_gtm (GTM loaded by another plugin) behaves the same way.
        update_option('trackwp_advanced', array('uses_gtm' => true));
        $this->meta_platforms(array('gtm_enabled' => false, 'meta_pixel_with_gtm' => false));
        $this->assertSame('', $this->render('render_meta_pixel'));
        $this->meta_platforms(array('gtm_enabled' => false, 'meta_pixel_with_gtm' => true));
        $this->assertStringContainsString('id="trackwp-meta-pixel"', $this->render('render_meta_pixel'));
    }

    public function test_client_meta_pixel_will_render_is_the_render_gate() {
        // GTM without M1: false, and render_meta_pixel prints nothing.
        $this->meta_platforms(array('meta_pixel_with_gtm' => false));
        $this->assertFalse(TrackWP::client_meta_pixel_will_render());
        $this->assertSame('', $this->render('render_meta_pixel'));

        // GTM with M1: true, and the pixel is printed.
        $this->meta_platforms(array('meta_pixel_with_gtm' => true));
        $this->assertTrue(TrackWP::client_meta_pixel_will_render());
        $this->assertStringContainsString('id="trackwp-meta-pixel"', $this->render('render_meta_pixel'));

        // No GTM at all (neither gtm_enabled nor uses_gtm): true.
        update_option('trackwp_advanced', array('uses_gtm' => false));
        $this->meta_platforms(array('gtm_enabled' => false, 'meta_pixel_with_gtm' => false));
        $this->assertTrue(TrackWP::client_meta_pixel_will_render());
        $this->assertStringContainsString('id="trackwp-meta-pixel"', $this->render('render_meta_pixel'));

        // uses_gtm without M1: false. Each settings condition on its own: false.
        update_option('trackwp_advanced', array('uses_gtm' => true));
        $this->assertFalse(TrackWP::client_meta_pixel_will_render());
        update_option('trackwp_advanced', array('uses_gtm' => false));
        foreach ( array(
            array('meta_enabled' => false),
            array('meta_pixel_client_enabled' => false),
            array('meta_pixel_id' => '123'),
            array('meta_pixel_id' => ''),
        ) as $override ) {
            $this->meta_platforms($override + array('gtm_enabled' => false));
            $this->assertFalse(TrackWP::client_meta_pixel_will_render(), wp_json_encode($override));
            $this->assertSame('', $this->render('render_meta_pixel'), wp_json_encode($override));
        }
    }

    public function test_fb4woo_filter_registered_at_bootstrap() {
        // Registered in init_hooks(), i.e. before plugins_loaded and before
        // fb4woo builds its tracker on 'init'.
        $this->assertNotFalse(has_filter('facebook_for_woocommerce_integration_pixel_enabled', array('TrackWP_Meta_Takeover', 'filter_pixel_enabled')));
    }

    public function test_blocker_save_compiles_rules() {
        delete_option('trackwp_blocker_compiled');
        $this->disable_blocking();
        $value = get_option('trackwp_blocker');
        $value['rules'] = array(
            'host:cdn.leadinfo.net' => array('block' => true, 'category' => 'marketing', 'vendor' => 'leadinfo'),
        );
        update_option('trackwp_blocker', $value);
        $compiled = get_option('trackwp_blocker_compiled');
        $this->assertIsArray($compiled, 'update_option_trackwp_blocker stored the compiled rules.');
        $this->assertEquals(TrackWP_Blocker_Rules::compile(get_option('trackwp_blocker')), $compiled);

        // add_option (first save on a site without the option) compiles too.
        delete_option('trackwp_blocker');
        delete_option('trackwp_blocker_compiled');
        add_option('trackwp_blocker', $value);
        $this->assertEquals(TrackWP_Blocker_Rules::compile(get_option('trackwp_blocker')), get_option('trackwp_blocker_compiled'));
    }

    /**
     * KC7: frontend_config()['dataLayer'] always has the contract's shape.
     * Uses the real producer (TrackWP_DataLayer::client_config()) when W4's
     * class has landed; otherwise asserts the documented "everything off"
     * fallback frontend_config() itself falls back to.
     */
    public function test_frontend_config_carries_datalayer_key_with_contract_shape() {
        $cfg = TrackWP::frontend_config();
        $this->assertArrayHasKey('dataLayer', $cfg);
        $dl = $cfg['dataLayer'];
        foreach ( array('enabled', 'ga4Source', 'ga4Enabled', 'mpConfigured') as $key ) {
            $this->assertArrayHasKey($key, $dl, $key);
        }
        $this->assertIsBool($dl['enabled']);
        $this->assertIsString($dl['ga4Source']);
        $this->assertIsBool($dl['ga4Enabled']);
        $this->assertIsBool($dl['mpConfigured']);

        if ( class_exists('TrackWP_DataLayer') ) {
            $this->assertSame(TrackWP_DataLayer::client_config(), $dl, 'Must be exactly what the real producer returns.');
        } else {
            $this->assertSame(array(
                'enabled'      => false,
                'ga4Source'    => 'gtm',
                'ga4Enabled'   => false,
                'mpConfigured' => false,
            ), $dl);
        }

        // The printed inline config and the public producer are identical
        // (same assertion pattern as the rest of frontend_config()).
        TrackWP::instance()->enqueue_frontend_scripts();
        list(, $printed) = $this->inline_config('trackwp-tracking', 'trackwpConfig');
        $this->assertSame(json_decode(wp_json_encode($cfg), true)['dataLayer'], $printed['dataLayer']);
    }

    /**
     * TR5 (PLAN-1.11.1-v2 §9): dataLayer "test" mode in GTM mode must be
     * uncacheable for the previewing admin, and must not fire for anyone
     * else or outside GTM mode.
     */
    public function test_datalayer_test_mode_sets_nocache_trio_for_admin_in_gtm_mode_only() {
        if ( defined('DONOTCACHEPAGE') ) {
            $this->markTestSkipped('DONOTCACHEPAGE already defined earlier in this process.');
        }
        update_option('trackwp_platforms', array(
            'gtm_enabled'           => true,
            'gtm_container_id'      => 'GTM-ABC1234',
            'gtm_datalayer_events'  => 'test',
        ));
        $admin = self::factory()->user->create(array('role' => 'administrator'));
        wp_set_current_user($admin);

        $fired_before = did_action('litespeed_control_set_nocache');
        TrackWP::instance()->maybe_set_datalayer_test_nocache();

        $this->assertTrue(defined('DONOTCACHEPAGE'));
        $this->assertTrue(DONOTCACHEPAGE);
        $this->assertSame($fired_before + 1, did_action('litespeed_control_set_nocache'));
    }

    public function test_datalayer_test_mode_does_nothing_for_non_admin() {
        update_option('trackwp_platforms', array(
            'gtm_enabled'          => true,
            'gtm_container_id'     => 'GTM-ABC1234',
            'gtm_datalayer_events' => 'test',
        ));
        $subscriber = self::factory()->user->create(array('role' => 'subscriber'));
        wp_set_current_user($subscriber);

        $fired_before = did_action('litespeed_control_set_nocache');
        TrackWP::instance()->maybe_set_datalayer_test_nocache();

        $this->assertSame($fired_before, did_action('litespeed_control_set_nocache'));
    }

    public function test_datalayer_test_mode_does_nothing_without_gtm() {
        update_option('trackwp_platforms', array(
            'gtm_enabled'          => false,
            'gtm_datalayer_events' => 'test',
        ));
        update_option('trackwp_advanced', array('uses_gtm' => false));
        $admin = self::factory()->user->create(array('role' => 'administrator'));
        wp_set_current_user($admin);

        $fired_before = did_action('litespeed_control_set_nocache');
        TrackWP::instance()->maybe_set_datalayer_test_nocache();

        $this->assertSame($fired_before, did_action('litespeed_control_set_nocache'), 'TR5 only applies in GTM mode.');
    }

    /**
     * Reviewer-deep: render_meta_takeover_notice() must only claim fb4woo's
     * pixel/CAPI are switched off when fb4woo is actually installed, using
     * the real producer TrackWP_Meta_Takeover::status()['fb4woo_active'].
     * fb4woo_active() detects the plugin via class_exists/function_exists,
     * which cannot be reliably forced false or true from a single test file
     * once other test files in the same PHPUnit process have declared a
     * facebook_for_woocommerce() stub (see tests/test-meta.php) — so this
     * asserts the notice's content is CONSISTENT with whatever the real
     * producer reports right now, in both directions.
     */
    public function test_meta_takeover_notice_mentions_fb4woo_only_when_active() {
        $admin = self::factory()->user->create(array('role' => 'administrator'));
        wp_set_current_user($admin);
        update_option(TrackWP::OPTION_META_TAKEOVER_NOTICE, 1, false);

        $fb4woo_active = TrackWP_Meta_Takeover::status()['fb4woo_active'];
        $out = $this->render('render_meta_takeover_notice');
        $this->assertStringContainsString('leverer TrackWP nu Meta', $out);
        if ( $fb4woo_active ) {
            $this->assertStringContainsString('Meta for WooCommerce', $out, 'fb4woo active: the switched-off sentence must be shown.');
        } else {
            $this->assertStringNotContainsString('Meta for WooCommerce', $out, 'fb4woo not active: must not claim its pixel/CAPI are switched off.');
        }
    }

    public function test_config_json_cannot_break_out_of_script_tag() {
        update_option('trackwp_consent', array('consent_version' => 3, 'heading' => '</script><script>alert(1)</script>'));
        TrackWP::instance()->enqueue_frontend_scripts();
        list($raw, $cfg) = $this->inline_config('trackwp-consent', 'trackwpConsentConfig');
        $this->assertStringNotContainsString('</script>', $raw);
        $this->assertSame('</script><script>alert(1)</script>', $cfg['heading']);
    }
}
