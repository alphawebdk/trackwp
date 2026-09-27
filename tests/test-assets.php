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

    public function test_config_json_cannot_break_out_of_script_tag() {
        update_option('trackwp_consent', array('consent_version' => 3, 'heading' => '</script><script>alert(1)</script>'));
        TrackWP::instance()->enqueue_frontend_scripts();
        list($raw, $cfg) = $this->inline_config('trackwp-consent', 'trackwpConsentConfig');
        $this->assertStringNotContainsString('</script>', $raw);
        $this->assertSame('</script><script>alert(1)</script>', $cfg['heading']);
    }
}
