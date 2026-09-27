<?php
/**
 * TrackWP_Request_Guard (origin, client_ip with trusted proxies, rate limit,
 * current_user_id) and TrackWP_Cookies (K7/R17).
 *
 * Real producer: current_user_id() is fed the logged_in cookie produced by
 * WordPress' own wp_set_auth_cookie() (captured from its set_logged_in_cookie
 * action; send_auth_cookies=false only stops the header under the CLI).
 */
class TrackWP_Request_Guard_Test extends WP_UnitTestCase {

    public function set_up() {
        parent::set_up();
        unset($_SERVER['HTTP_ORIGIN'], $_SERVER['HTTP_REFERER'], $_SERVER['HTTP_X_FORWARDED_FOR'], $_SERVER['HTTP_CF_CONNECTING_IP']);
        $_SERVER['REMOTE_ADDR'] = '203.0.113.9';
        update_option('trackwp_advanced', array());
        $_COOKIE = array();
        TrackWP_Cookies::reset_sent();
    }

    public function tear_down() {
        unset($_SERVER['HTTP_ORIGIN'], $_SERVER['HTTP_REFERER'], $_SERVER['HTTP_X_FORWARDED_FOR'], $_SERVER['HTTP_CF_CONNECTING_IP']);
        $_COOKIE = array();
        parent::tear_down();
    }

    /* ---------------- check_origin ---------------- */

    public function test_origin_decides_when_present() {
        $_SERVER['HTTP_ORIGIN']  = 'https://evil.example';
        $_SERVER['HTTP_REFERER'] = home_url('/page');
        $this->assertFalse(TrackWP_Request_Guard::check_origin(), 'A foreign Origin is not rescued by a same-site Referer');
        $_SERVER['HTTP_ORIGIN'] = home_url();
        $this->assertTrue(TrackWP_Request_Guard::check_origin());
        $_SERVER['HTTP_ORIGIN'] = 'null';
        $this->assertFalse(TrackWP_Request_Guard::check_origin());
    }

    public function test_referer_used_without_origin() {
        $_SERVER['HTTP_REFERER'] = home_url('/page');
        $this->assertTrue(TrackWP_Request_Guard::check_origin());
        $_SERVER['HTTP_REFERER'] = 'https://evil.example/page';
        $this->assertFalse(TrackWP_Request_Guard::check_origin());
        unset($_SERVER['HTTP_REFERER']);
        $this->assertFalse(TrackWP_Request_Guard::check_origin());
    }

    /* ---------------- client_ip ---------------- */

    public function test_spoofed_xff_from_untrusted_peer_is_ignored() {
        $_SERVER['HTTP_X_FORWARDED_FOR']  = '1.2.3.4';
        $_SERVER['HTTP_CF_CONNECTING_IP'] = '5.6.7.8';
        $this->assertSame('203.0.113.9', TrackWP_Request_Guard::client_ip());
    }

    public function test_trusted_proxy_uses_rightmost_untrusted_hop() {
        update_option('trackwp_advanced', array('trusted_proxies' => array('10.0.0.0/8', '203.0.113.0/24')));
        $_SERVER['HTTP_X_FORWARDED_FOR'] = '1.2.3.4, 198.51.100.20, 10.1.2.3';
        $this->assertSame('198.51.100.20', TrackWP_Request_Guard::client_ip(), 'Client-spoofed left-most hop is not used');
    }

    public function test_trusted_proxy_prefers_cf_connecting_ip() {
        update_option('trackwp_advanced', array('trusted_proxies' => array('203.0.113.0/24')));
        $_SERVER['HTTP_CF_CONNECTING_IP'] = '2001:db8::1';
        $_SERVER['HTTP_X_FORWARDED_FOR']  = '198.51.100.20';
        $this->assertSame('2001:db8::1', TrackWP_Request_Guard::client_ip());
    }

    public function test_cloudflare_enabled_uses_cf_connecting_ip() {
        $ranges = TrackWP_Settings::cloudflare_ip_ranges();
        $this->assertContains('173.245.48.0/20', $ranges);
        $_SERVER['REMOTE_ADDR']           = '173.245.48.10'; // inside a Cloudflare range
        $_SERVER['HTTP_CF_CONNECTING_IP'] = '198.51.100.77';
        update_option('trackwp_advanced', array('trusted_proxies' => array(), 'trusted_proxies_cloudflare' => false));
        $this->assertSame('173.245.48.10', TrackWP_Request_Guard::client_ip(), 'Cloudflare off: header ignored');
        update_option('trackwp_advanced', array('trusted_proxies' => array(), 'trusted_proxies_cloudflare' => true));
        $this->assertSame('198.51.100.77', TrackWP_Request_Guard::client_ip());
        $this->assertSame(TrackWP_Settings::get_trusted_proxies(), TrackWP_Request_Guard::trusted_proxies(), 'One source');
    }

    public function test_cidr_matching_v4_and_v6() {
        $this->assertTrue(TrackWP_Request_Guard::ip_in_cidr('10.20.30.40', '10.0.0.0/8'));
        $this->assertFalse(TrackWP_Request_Guard::ip_in_cidr('11.0.0.1', '10.0.0.0/8'));
        $this->assertTrue(TrackWP_Request_Guard::ip_in_cidr('192.168.1.130', '192.168.1.128/25'));
        $this->assertFalse(TrackWP_Request_Guard::ip_in_cidr('192.168.1.127', '192.168.1.128/25'));
        $this->assertTrue(TrackWP_Request_Guard::ip_in_cidr('2001:db8::5', '2001:db8::/32'));
        $this->assertFalse(TrackWP_Request_Guard::ip_in_cidr('10.0.0.1', '2001:db8::/32'));
        $this->assertFalse(TrackWP_Request_Guard::ip_in_cidr('10.0.0.1', '10.0.0.0/abc'));
    }

    public function test_rate_limit_per_ip() {
        $this->assertTrue(TrackWP_Request_Guard::rate_limit('unit', 2));
        $this->assertTrue(TrackWP_Request_Guard::rate_limit('unit', 2));
        $this->assertNotFalse(get_transient('trackwp_rl_unit_' . md5('203.0.113.9') . '_' . (int) floor(time() / 2)), 'Documented prefix trackwp_rl_');
        $this->assertFalse(TrackWP_Request_Guard::rate_limit('unit', 2));
        $_SERVER['REMOTE_ADDR'] = '203.0.113.10';
        $this->assertTrue(TrackWP_Request_Guard::rate_limit('unit', 2));
    }

    /* ---------------- current_user_id ---------------- */

    public function test_current_user_id_with_real_auth_cookie() {
        $this->assertSame(0, TrackWP_Request_Guard::current_user_id());
        $uid      = self::factory()->user->create();
        $captured = '';
        $capture  = function ($cookie) use (&$captured) {
            $captured = $cookie;
        };
        add_action('set_logged_in_cookie', $capture);
        add_filter('send_auth_cookies', '__return_false');
        wp_set_auth_cookie($uid);
        remove_action('set_logged_in_cookie', $capture);
        $this->assertNotSame('', $captured);
        $_COOKIE[ LOGGED_IN_COOKIE ] = $captured;
        $this->assertSame($uid, TrackWP_Request_Guard::current_user_id());
        $_COOKIE[ LOGGED_IN_COOKIE ] = $captured . 'x';
        $this->assertSame(0, TrackWP_Request_Guard::current_user_id());
    }

    /* ---------------- TrackWP_Cookies ---------------- */

    public function test_host_only_omits_domain_key() {
        TrackWP_Cookies::set('_twp_cid', '1.2', time() + 60, 'host');
        $sent = TrackWP_Cookies::sent_cookies();
        $this->assertArrayNotHasKey('domain', $sent[0]['options']);
        $this->assertSame('/', $sent[0]['options']['path']);
        $this->assertSame('Lax', $sent[0]['options']['samesite']);
        $this->assertFalse($sent[0]['options']['httponly']);
    }

    public function test_rewrite_host_only_expires_domain_variant_first() {
        TrackWP_Cookies::rewrite_host_only('_twp_cid', '1.2', time() + 60);
        $sent = TrackWP_Cookies::sent_cookies();
        $this->assertCount(3, $sent);
        $this->assertSame(TrackWP_Cookies::home_host(), $sent[0]['options']['domain']);
        $this->assertLessThan(time(), $sent[0]['options']['expires']);
        $this->assertArrayNotHasKey('domain', $sent[2]['options']);
        $this->assertSame('1.2', $sent[2]['value']);
    }

    public function test_lifetime_days_clamps() {
        update_option('trackwp_consent', array('cookie_lifetime_months' => 99));
        update_option('trackwp_advanced', array('cookie_lifetime_months' => 99));
        $this->assertSame(360, TrackWP_Cookies::lifetime_days('trackwp_consent'));
        $this->assertSame(400, TrackWP_Cookies::lifetime_days('_twp_cid'));
        update_option('trackwp_advanced', array('cookie_lifetime_months' => 0));
        $this->assertSame(30, TrackWP_Cookies::lifetime_days('_twp_cid'));
        $this->assertSame(90, TrackWP_Cookies::lifetime_days('_twp_click'));
        $this->assertSame(90, TrackWP_Cookies::lifetime_days('_fbc'));
    }

    public function test_registrable_domain_order() {
        $this->assertSame('.example.co.uk', TrackWP_Cookies::registrable_domain('shop.example.co.uk'));
        $this->assertSame('.example.dk', TrackWP_Cookies::registrable_domain('example.dk'));
        $this->assertSame('', TrackWP_Cookies::registrable_domain('127.0.0.1'));
        update_option('trackwp_advanced', array('cookie_domain' => 'example.co.uk'));
        $this->assertSame('.example.co.uk', TrackWP_Cookies::registrable_domain('www.shop.example.co.uk'));
        update_option('trackwp_advanced', array('cookie_domain' => 'other.dk'));
        $this->assertSame('.example.co.uk', TrackWP_Cookies::registrable_domain('www.example.co.uk'), 'A cookie_domain that is not a suffix of the host is ignored');
    }

    public function test_expire_tracking_cookies_per_category() {
        $_COOKIE = array('_ga' => '1', '_ga_ABC' => '1', '_fbp' => '1', '_gcl_au' => '1', '_twp_click' => '1', 'unrelated' => '1');
        $expired = TrackWP_Cookies::expire_tracking_cookies(array('analytics' => true, 'marketing' => false));
        sort($expired);
        $this->assertSame(array('_fbp', '_gcl_au', '_twp_click'), $expired);
        $expired = TrackWP_Cookies::expire_tracking_cookies(array('statistics' => false, 'marketing' => 'true'));
        $this->assertContains('_ga', $expired);
        $this->assertContains('_ga_ABC', $expired);
        $this->assertNotContains('unrelated', $expired);
    }
}
