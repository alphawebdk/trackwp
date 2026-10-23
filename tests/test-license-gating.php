<?php
/**
 * 1.13.0 license gate: TrackWP_License::tracking_allowed() decides, in one
 * place per entry point, whether tracking runs. Banner, consent, blocker,
 * /my-data and admin always work.
 *
 * Real producers: the head output comes from TrackWP::render_*(), the routes
 * from the real rest_api_init callback dispatched through WordPress, the
 * enqueue from TrackWP::enqueue_frontend_scripts(), the grace option from
 * TrackWP::maybe_upgrade(). The license state is forced with
 * TrackWP_License::force_state_for_tests() (the bootstrap forces true).
 *
 * @group license
 */
class TrackWP_License_Gating_Test extends WP_UnitTestCase {

    public function set_up() {
        parent::set_up();
        $GLOBALS['wp_scripts'] = null;
        update_option('trackwp_advanced', array(
            'consent_mode_ad_signals'       => true,
            'consent_mode_cookieless_pings' => true,
            'first_party_loader_enabled'    => true,
            'cookie_lifetime_months'        => 24,
        ));
        update_option('trackwp_consent', array('consent_version' => 3));
        new TrackWP_Blocker();
        delete_option(TrackWP_License::OPTION);
        delete_option(TrackWP_License::GRACE_OPT);
    }

    public function tear_down() {
        TrackWP_License::force_state_for_tests(true);
        $GLOBALS['wp_scripts']     = null;
        $GLOBALS['wp_rest_server'] = null;
        delete_option(TrackWP_License::OPTION);
        delete_option(TrackWP_License::GRACE_OPT);
        parent::tear_down();
    }

    private function render($method) {
        ob_start();
        TrackWP::instance()->$method();
        return (string) ob_get_clean();
    }

    private function routes() {
        $GLOBALS['wp_rest_server'] = null;
        return array_keys(rest_get_server()->get_routes());
    }

    private function enable_all_head_platforms() {
        update_option('trackwp_platforms', array(
            'gtm_enabled'               => true,
            'gtm_container_id'          => 'GTM-ABC123',
            'ga4_enabled'               => true,
            'ga4_gtag_enabled'          => true,
            'ga4_measurement_id'        => 'G-TEST1234',
            'meta_enabled'              => true,
            'meta_pixel_client_enabled' => true,
            'meta_pixel_id'             => '1234567890',
        ));
    }

    // --- head output -------------------------------------------------

    public function test_allowed_prints_gtm_gtag_and_meta() {
        TrackWP_License::force_state_for_tests(true);
        $this->enable_all_head_platforms();
        $this->assertStringContainsString('googletagmanager.com/gtm.js', $this->render('render_gtm_head'));
        $this->assertStringContainsString('googletagmanager.com/ns.html', $this->render('render_gtm_noscript'));

        update_option('trackwp_platforms', array(
            'ga4_enabled' => true, 'ga4_gtag_enabled' => true, 'ga4_measurement_id' => 'G-TEST1234',
            'meta_enabled' => true, 'meta_pixel_client_enabled' => true, 'meta_pixel_id' => '1234567890',
        ));
        $this->assertStringContainsString('gtag', $this->render('render_gtag_head'));
        $this->assertStringContainsString('trackwp-meta-pixel', $this->render('render_meta_pixel'));
    }

    public function test_blocked_prints_no_gtm_gtag_or_meta() {
        TrackWP_License::force_state_for_tests(false);
        $this->enable_all_head_platforms();
        $this->assertSame('', $this->render('render_gtm_head'));
        $this->assertSame('', $this->render('render_gtm_noscript'));

        update_option('trackwp_platforms', array(
            'ga4_enabled' => true, 'ga4_gtag_enabled' => true, 'ga4_measurement_id' => 'G-TEST1234',
            'meta_enabled' => true, 'meta_pixel_client_enabled' => true, 'meta_pixel_id' => '1234567890',
        ));
        $this->assertSame('', $this->render('render_gtag_head'));
        $this->assertSame('', $this->render('render_meta_pixel'));
    }

    public function test_blocked_still_prints_consent_mode_defaults() {
        TrackWP_License::force_state_for_tests(false);
        $this->assertNotSame('', trim($this->render('render_consent_mode_defaults')));
    }

    // --- scripts -----------------------------------------------------

    public function test_blocked_enqueues_consent_but_not_tracking() {
        TrackWP_License::force_state_for_tests(false);
        TrackWP::instance()->enqueue_frontend_scripts();
        $this->assertTrue(wp_script_is('trackwp-consent', 'enqueued'));
        $this->assertFalse(wp_script_is('trackwp-tracking', 'enqueued'));
        $this->assertTrue(wp_style_is('trackwp-consent', 'enqueued'), 'banner CSS stays');
    }

    public function test_allowed_enqueues_consent_and_tracking() {
        TrackWP_License::force_state_for_tests(true);
        TrackWP::instance()->enqueue_frontend_scripts();
        $this->assertTrue(wp_script_is('trackwp-consent', 'enqueued'));
        $this->assertTrue(wp_script_is('trackwp-tracking', 'enqueued'));
    }

    // --- REST routes -------------------------------------------------

    public function test_blocked_removes_event_keepalive_and_loader_routes_only() {
        TrackWP_License::force_state_for_tests(false);
        $routes = $this->routes();
        $this->assertNotContains('/trackwp/v1/event', $routes);
        $this->assertNotContains('/trackwp/v1/keepalive', $routes);
        $this->assertNotContains('/trackwp/v1/loader', $routes);
        $this->assertContains('/trackwp/v1/my-data', $routes);
        $this->assertContains('/trackwp/v1/consent', $routes);
        $this->assertContains('/trackwp/v1/consent-log', $routes);
        $this->assertContains('/trackwp/v1/blocker/scan', $routes);
    }

    public function test_allowed_registers_every_route() {
        TrackWP_License::force_state_for_tests(true);
        $routes = $this->routes();
        foreach (array('event', 'keepalive', 'loader', 'my-data', 'consent', 'consent-log', 'blocker/scan') as $r) {
            $this->assertContains('/trackwp/v1/' . $r, $routes, $r);
        }
    }

    public function test_blocked_event_request_is_404() {
        TrackWP_License::force_state_for_tests(false);
        $this->routes();
        $res = rest_get_server()->dispatch(new WP_REST_Request('POST', '/trackwp/v1/event'));
        $this->assertSame(404, $res->get_status());
    }

    // --- hooks wired in init() / init_hooks() ------------------------

    public function test_init_skips_forms_and_woocommerce_when_blocked() {
        $inst = TrackWP::instance();
        $ref  = new ReflectionClass($inst);
        $forms = $ref->getProperty('forms');
        $forms->setAccessible(true);
        $woo = $ref->getProperty('woocommerce');
        $woo->setAccessible(true);
        $before = array($forms->getValue($inst), $woo->getValue($inst));
        try {
            $forms->setValue($inst, null);
            $woo->setValue($inst, null);
            TrackWP_License::force_state_for_tests(false);
            $inst->init();
            $this->assertNull($forms->getValue($inst));
            $this->assertNull($woo->getValue($inst));
            TrackWP_License::force_state_for_tests(true);
            $inst->init();
            $this->assertInstanceOf('TrackWP_Forms', $forms->getValue($inst));
            $this->assertInstanceOf('TrackWP_WooCommerce', $woo->getValue($inst));
        } finally {
            $forms->setValue($inst, $before[0]);
            $woo->setValue($inst, $before[1]);
        }
    }

    public function test_fb4woo_filter_is_registered_only_when_allowed() {
        $filter = 'facebook_for_woocommerce_integration_pixel_enabled';
        $cb     = array('TrackWP_Meta_Takeover', 'filter_pixel_enabled');
        $had    = has_filter($filter, $cb);
        $ref    = new ReflectionClass('TrackWP');
        $init   = $ref->getMethod('init_hooks');
        $init->setAccessible(true);
        $inst   = $ref->newInstanceWithoutConstructor();

        try {
            remove_filter($filter, $cb);
            TrackWP_License::force_state_for_tests(false);
            $init->invoke($inst);
            $this->assertFalse(has_filter($filter, $cb), 'blocked: Meta for WooCommerce keeps its pixel');

            TrackWP_License::force_state_for_tests(true);
            $init->invoke($inst);
            $this->assertNotFalse(has_filter($filter, $cb));
        } finally {
            // Drop every hook the throw-away instance registered.
            foreach ($GLOBALS['wp_filter'] as $name => $hook) {
                foreach ($hook->callbacks as $prio => $cbs) {
                    foreach ($cbs as $c) {
                        if (is_array($c['function']) && $c['function'][0] === $inst) {
                            remove_filter($name, $c['function'], $prio);
                        }
                    }
                }
            }
            if ($had === false) {
                remove_filter($filter, $cb);
            } else {
                add_filter($filter, $cb);
            }
        }
    }

    // --- upgrade grace (E1) ------------------------------------------

    private function upgrade_from($version) {
        update_option('trackwp_version', $version);
        TrackWP::instance()->maybe_upgrade();
    }

    public function test_upgrade_from_1_11_4_without_key_sets_30_days_grace() {
        $this->upgrade_from('1.11.4');
        $until = TrackWP_License::grace_until();
        $this->assertGreaterThan(time() + 29 * DAY_IN_SECONDS, $until);
        $this->assertLessThanOrEqual(time() + 30 * DAY_IN_SECONDS, $until);
    }

    public function test_new_install_gets_no_grace() {
        $this->upgrade_from('1.0.0');
        $this->assertSame(0, TrackWP_License::grace_until());
    }

    public function test_upgrade_with_stored_key_gets_no_grace() {
        update_option(TrackWP_License::OPTION, array('key' => TrackWP_Hash::encode('TWP-AAAA'), 'token' => ''));
        $this->upgrade_from('1.11.4');
        $this->assertSame(0, TrackWP_License::grace_until());
    }

    public function test_upgrade_keeps_an_existing_grace_end() {
        $fixed = time() + 5 * DAY_IN_SECONDS;
        update_option(TrackWP_License::GRACE_OPT, $fixed);
        $this->upgrade_from('1.11.4');
        $this->assertSame($fixed, TrackWP_License::grace_until());
    }

    public function test_upgrade_from_1_13_0_or_later_changes_nothing() {
        $this->upgrade_from('1.13.0');
        $this->assertSame(0, TrackWP_License::grace_until());
    }

    public function test_grace_after_upgrade_allows_tracking_without_force() {
        $this->upgrade_from('1.11.4');
        TrackWP_License::force_state_for_tests(null);
        $this->assertTrue(TrackWP_License::tracking_allowed());
    }

    public function test_cron_hook_cleared_on_deactivate() {
        wp_schedule_event(time() + 60, 'twicedaily', TrackWP_License::CRON_HOOK);
        $this->assertNotFalse(wp_next_scheduled(TrackWP_License::CRON_HOOK));
        TrackWP::instance()->deactivate();
        $this->assertFalse(wp_next_scheduled(TrackWP_License::CRON_HOOK));
    }
}
