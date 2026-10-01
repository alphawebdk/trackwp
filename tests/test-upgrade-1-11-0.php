<?php
/**
 * 1.11.0 migration block in TrackWP::maybe_upgrade() (T6).
 *
 * The starting options are a 1.10.1 site (trackwp_version '1.10.1', no
 * trackwp_blocker option, no meta_pixel_with_gtm key). The prio-1 script is
 * compared with what the same code renders before the migration has run,
 * i.e. with no blocker option at all, which is the 1.10.1 state.
 *
 * @group upgrade
 */
class TrackWP_Upgrade_1_11_0_Test extends WP_UnitTestCase {

    public function set_up() {
        parent::set_up();
        delete_option('trackwp_blocker');
        delete_option('trackwp_blocker_compiled');
        update_option('trackwp_platforms', array(
            'gtm_enabled'               => true,
            'gtm_container_id'          => 'GTM-ABC1234',
            'meta_enabled'              => true,
            'meta_pixel_client_enabled' => true,
            'meta_pixel_id'             => '123456789012345',
        ));
        update_option('trackwp_advanced', array(
            'consent_mode_ad_signals'       => true,
            'consent_mode_cookieless_pings' => true,
        ));
        update_option('trackwp_consent', array('consent_version' => 2));
        update_option('trackwp_version', '1.10.1');
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $this->go_to(home_url('/'));
        // A fresh, undecided blocker instance for this request: the static
        // instance of an earlier test keeps that request's decision.
        new TrackWP_Blocker();
    }

    private function render($method) {
        ob_start();
        TrackWP::instance()->$method();
        return (string) ob_get_clean();
    }

    public function test_adds_blocker_off_and_m1_false() {
        TrackWP::instance()->maybe_upgrade();

        $this->assertSame(TRACKWP_VERSION, get_option('trackwp_version'));
        $blocker = get_option('trackwp_blocker');
        $this->assertIsArray($blocker);
        $this->assertSame('off', $blocker['mode']);
        $this->assertSame(array(), $blocker['rules']);

        $platforms = get_option('trackwp_platforms');
        $this->assertArrayHasKey('meta_pixel_with_gtm', $platforms);
        $this->assertFalse($platforms['meta_pixel_with_gtm']);
        // Other platform settings are untouched.
        $this->assertSame('GTM-ABC1234', $platforms['gtm_container_id']);
        $this->assertSame('123456789012345', $platforms['meta_pixel_id']);
        $this->assertFalse(TrackWP_Blocker::active_for_request());
    }

    public function test_existing_values_are_kept() {
        $platforms = get_option('trackwp_platforms');
        $platforms['meta_pixel_with_gtm'] = true;
        update_option('trackwp_platforms', $platforms);
        add_option('trackwp_blocker', array('mode' => 'test', 'rules' => array()));

        TrackWP::instance()->maybe_upgrade();

        $this->assertTrue(get_option('trackwp_platforms')['meta_pixel_with_gtm']);
        $this->assertSame('test', get_option('trackwp_blocker')['mode']);
    }

    public function test_prio1_is_byte_identical_to_1_10_1_when_mode_off() {
        $before = $this->render('render_consent_mode_defaults');
        TrackWP::instance()->maybe_upgrade();
        $after = $this->render('render_consent_mode_defaults');

        $this->assertSame($before, $after);
        $this->assertStringContainsString("-->\n<script>", $after);
        foreach ( array('data-twp-', 'data-cfasync', 'data-no-optimize', 'trackwpBlocker') as $needle ) {
            $this->assertStringNotContainsString($needle, $after, $needle);
        }
        // GTM without M1: still no TrackWP pixel, and the GTM noscript stays.
        $this->assertSame('', $this->render('render_meta_pixel'));
        $this->assertStringContainsString('ns.html?id=GTM-ABC1234', $this->render('render_gtm_noscript'));
    }
}
