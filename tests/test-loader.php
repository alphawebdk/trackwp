<?php
/**
 * TrackWP_Loader: /loader keeps public caching, collect requires the
 * configured tid (query or body), 64 kB body cap, IP via client_ip(),
 * gcs/gcd passed through unchanged.
 */
class TrackWP_Loader_Test extends WP_UnitTestCase {

    const TID = 'G-TEST1234';

    /** @var array Captured upstream requests. */
    private $upstream = array();

    public function set_up() {
        parent::set_up();
        update_option('trackwp_advanced', array('first_party_loader_enabled' => true));
        update_option('trackwp_platforms', array('ga4_enabled' => true, 'ga4_measurement_id' => self::TID));
        $_SERVER['HTTP_ORIGIN'] = home_url();
        $_SERVER['REMOTE_ADDR'] = '203.0.113.' . wp_rand(1, 250);
        unset($_SERVER['QUERY_STRING'], $_COOKIE['trackwp_consent']);
        $this->upstream = array();
        add_filter('pre_http_request', array($this, 'capture_upstream'), 10, 3);
        global $wp_rest_server;
        $wp_rest_server = null;
        rest_get_server();
    }

    public function tear_down() {
        remove_filter('pre_http_request', array($this, 'capture_upstream'), 10);
        unset($_SERVER['HTTP_ORIGIN'], $_SERVER['QUERY_STRING'], $_COOKIE['trackwp_consent']);
        global $wp_rest_server;
        $wp_rest_server = null;
        parent::tear_down();
    }

    public function capture_upstream($pre, $args, $url) {
        $this->upstream[] = array('url' => $url, 'args' => $args);
        return array('headers' => array(), 'body' => '', 'response' => array('code' => 204, 'message' => 'No Content'), 'cookies' => array(), 'filename' => null);
    }

    private function collect($query, $body = '') {
        $_SERVER['QUERY_STRING'] = $query;
        $req = new WP_REST_Request('POST', '/trackwp/v1/c/e');
        $params = array();
        wp_parse_str($query, $params);
        $req->set_query_params($params);
        $req->set_body($body);
        return rest_get_server()->dispatch($req);
    }

    public function test_loader_keeps_public_cache_header() {
        set_transient('trackwp_gtag_js_rw_' . md5(self::TID), 'window.__t=1;', HOUR_IN_SECONDS);
        $res = rest_get_server()->dispatch(new WP_REST_Request('GET', '/trackwp/v1/loader'));
        $this->assertSame(200, $res->get_status());
        $headers = $res->get_headers();
        $this->assertSame('public, max-age=3600', $headers['Cache-Control']);
        $this->assertStringContainsString('application/javascript', $headers['Content-Type']);
        $this->assertSame('window.__t=1;', $res->get_data());

        $req = new WP_REST_Request('GET', '/trackwp/v1/loader');
        ob_start();
        $served = TrackWP_Loader::serve_raw_script(false, $res, $req, rest_get_server());
        $out = ob_get_clean();
        $this->assertTrue($served);
        $this->assertSame('window.__t=1;', $out, 'Raw JS, no JSON envelope');
    }

    public function test_missing_tid_is_403() {
        $res = $this->collect('v=2&en=page_view');
        $this->assertSame(403, $res->get_status());
        $this->assertCount(0, $this->upstream);
    }

    public function test_wrong_tid_is_403() {
        $res = $this->collect('v=2&tid=G-OTHER999&en=page_view');
        $this->assertSame(403, $res->get_status());
        $res = $this->collect('v=2&tid=' . self::TID, "en=page_view&tid=G-OTHER999\nen=scroll");
        $this->assertSame(403, $res->get_status(), 'Every tid in the body must match');
        $this->assertCount(0, $this->upstream);
    }

    public function test_tid_in_body_is_accepted() {
        $res = $this->collect('v=2&gcs=G100&gcd=13p3p3p2p5l1', "v=2&tid=" . self::TID . "&en=page_view\nv=2&tid=" . self::TID . "&en=scroll");
        $this->assertSame(204, $res->get_status());
        $this->assertCount(1, $this->upstream);
    }

    /** Put a consent cookie into $_COOKIE exactly as the server writes it (producer: write_consent_cookie). */
    private function set_consent($statistics, $marketing) {
        TrackWP_Cookies::reset_sent();
        TrackWP_Consent::write_consent_cookie(TrackWP_Consent::server_version(), 1788256800000, '11111111-2222-4333-8444-555555555555', $statistics, $marketing, false);
        foreach (TrackWP_Cookies::sent_cookies() as $c) {
            if ('trackwp_consent' === $c['name']) {
                $_COOKIE['trackwp_consent'] = $c['value'];
            }
        }
        TrackWP_Cookies::reset_sent();
        $this->assertNotNull(TrackWP_Consent::parse_cookie(), 'producer cookie must parse');
    }

    private function noisy_query() {
        return 'v=2&tid=' . self::TID . '&gcs=G100&uid=u42&up.plan=gold&upn.age=3&ep.page_type=a%20b&epn.x=1&gclid=abc&fbclid=f1&_p=1';
    }

    public function test_consenter_gets_identical_url_and_body_with_full_uip() {
        $this->set_consent(true, false);
        $_SERVER['REMOTE_ADDR'] = '203.0.113.77';
        $query = $this->noisy_query();
        $body  = "v=2&tid=" . self::TID . "&uid=u42&gclid=abc&en=scroll\r\nv=2&tid=" . self::TID . "&en=page_view";
        $res = $this->collect($query, $body);
        $this->assertSame(204, $res->get_status());
        $this->assertCount(1, $this->upstream);
        $this->assertSame('https://www.google-analytics.com/g/collect?' . $query . '&_uip=203.0.113.77', $this->upstream[0]['url']);
        $this->assertSame($body, $this->upstream[0]['args']['body']);
    }

    public function test_no_cookie_truncates_ipv4_and_strips_identifiers_byte_for_byte() {
        $_SERVER['REMOTE_ADDR'] = '203.0.113.77';
        $res = $this->collect($this->noisy_query());
        $this->assertSame(204, $res->get_status());
        $this->assertCount(1, $this->upstream);
        $this->assertSame(
            'https://www.google-analytics.com/g/collect?v=2&tid=' . self::TID . '&gcs=G100&ep.page_type=a%20b&epn.x=1&_p=1&_uip=203.0.113.0',
            $this->upstream[0]['url']
        );
    }

    public function test_no_cookie_truncates_ipv6_to_48() {
        $_SERVER['REMOTE_ADDR'] = '2001:db8:1234:5678:9abc:def0:1111:2222';
        $this->collect('v=2&tid=' . self::TID . '&gcs=G100');
        $this->assertCount(1, $this->upstream);
        $this->assertStringEndsWith('&_uip=' . rawurlencode('2001:db8:1234::'), $this->upstream[0]['url']);
    }

    public function test_client_supplied_uip_is_dropped_without_analytics_consent() {
        $_SERVER['REMOTE_ADDR'] = '203.0.113.77';
        $this->collect('v=2&tid=' . self::TID . '&gcs=G100&_uip=9.9.9.9');
        $this->assertSame('https://www.google-analytics.com/g/collect?v=2&tid=' . self::TID . '&gcs=G100&_uip=203.0.113.0', $this->upstream[0]['url']);
    }

    public function test_click_ids_removed_from_params_and_from_dl_dr() {
        $dl = rawurlencode('https://example.com/p?gclid=abc&a=1#x');
        $dr = rawurlencode('https://example.com/r?dclid=zz&b=2');
        $_SERVER['REMOTE_ADDR'] = '203.0.113.77';
        $this->collect('v=2&tid=' . self::TID . '&gcs=G100&dl=' . $dl . '&dr=' . $dr . '&gclid=abc');
        $url = $this->upstream[0]['url'];
        $this->assertStringNotContainsString('gclid', $url);
        $this->assertStringContainsString('&dl=' . rawurlencode('https://example.com/p?a=1') . '&', $url);
        $this->assertStringContainsString('&dr=' . rawurlencode('https://example.com/r?b=2') . '&', $url);
    }

    public function test_marketing_only_cookie_keeps_click_ids_but_still_strips_uid_and_truncates() {
        $this->set_consent(false, true);
        $_SERVER['REMOTE_ADDR'] = '203.0.113.77';
        $dl = rawurlencode('https://example.com/p?gclid=abc&a=1');
        $this->collect('v=2&tid=' . self::TID . '&gcs=G100&uid=u42&gclid=abc&dl=' . $dl);
        $this->assertSame(
            'https://www.google-analytics.com/g/collect?v=2&tid=' . self::TID . '&gcs=G100&gclid=abc&dl=' . $dl . '&_uip=203.0.113.0',
            $this->upstream[0]['url']
        );
    }

    public function test_analytics_granted_gcs_without_consent_is_not_forwarded() {
        $res = $this->collect('v=2&tid=' . self::TID . '&gcs=G101&en=page_view');
        $this->assertSame(204, $res->get_status());
        $this->assertNull($res->get_data());
        $this->assertCount(0, $this->upstream);
        $res = $this->collect('v=2&tid=' . self::TID, "v=2&tid=" . self::TID . "&gcs=G100\nv=2&tid=" . self::TID . "&gcs=G111&en=scroll");
        $this->assertSame(204, $res->get_status());
        $this->assertCount(0, $this->upstream, 'One granted line in the body drops the whole request');
    }

    public function test_dropped_collect_without_cookie_carries_drop_header() {
        $res = $this->collect('v=2&tid=' . self::TID . '&gcs=G111&en=page_view');
        $this->assertSame(204, $res->get_status());
        $this->assertNull($res->get_data());
        $this->assertSame('consent_cookie_missing', $res->get_headers()['X-TrackWP-Drop']);
        $this->assertCount(0, $this->upstream);
    }

    public function test_dropped_collect_with_denied_cookie_says_consent_denied() {
        $this->set_consent(false, true);
        $res = $this->collect('v=2&tid=' . self::TID . '&gcs=G111&en=page_view');
        $this->assertSame(204, $res->get_status());
        $this->assertSame('consent_denied', $res->get_headers()['X-TrackWP-Drop']);
        $this->assertCount(0, $this->upstream);
    }

    public function test_forwarded_collect_has_no_drop_header() {
        $res = $this->collect('v=2&tid=' . self::TID . '&gcs=G100');
        $this->assertArrayNotHasKey('X-TrackWP-Drop', $res->get_headers());
    }

    public function test_denied_gcs_variants_are_forwarded_without_consent() {
        $this->collect('v=2&tid=' . self::TID . '&gcs=G100');
        $this->collect('v=2&tid=' . self::TID . '&gcs=G110');
        $this->assertCount(2, $this->upstream);
    }

    public function test_granted_gcs_with_statistics_consent_is_forwarded() {
        $this->set_consent(true, false);
        $this->collect('v=2&tid=' . self::TID . '&gcs=G111');
        $this->assertCount(1, $this->upstream);
    }

    public function test_multi_line_body_is_scrubbed_per_line() {
        $_SERVER['REMOTE_ADDR'] = '203.0.113.77';
        $t = self::TID;
        $this->collect('v=2&tid=' . $t, "v=2&tid=$t&uid=u1&ep.a=1\nv=2&tid=$t&up.x=2&gclid=g&ep.b=%2F\n\nv=2&tid=$t&en=scroll");
        $this->assertSame("v=2&tid=$t&ep.a=1\nv=2&tid=$t&ep.b=%2F\n\nv=2&tid=$t&en=scroll", $this->upstream[0]['args']['body']);
    }

    public function test_gcs_gcd_unchanged_and_uip_from_client_ip() {
        $this->set_consent(true, false);
        update_option('trackwp_advanced', array('first_party_loader_enabled' => true, 'trusted_proxies' => array($_SERVER['REMOTE_ADDR'] . '/32')));
        $_SERVER['HTTP_X_FORWARDED_FOR'] = '198.51.100.20';
        $query = 'v=2&tid=' . self::TID . '&gcs=G100&gcd=13p3p3p2p5l1&en=page_view';
        $res = $this->collect($query);
        unset($_SERVER['HTTP_X_FORWARDED_FOR']);
        $this->assertSame(204, $res->get_status());
        $this->assertSame('https://www.google-analytics.com/g/collect?' . $query . '&_uip=198.51.100.20', $this->upstream[0]['url']);
    }

    public function test_body_over_64kb_is_rejected() {
        $body = 'v=2&tid=' . self::TID . '&en=x&ep.pad=' . str_repeat('a', 65536);
        $res  = $this->collect('v=2&tid=' . self::TID, $body);
        $this->assertSame(413, $res->get_status());
        $this->assertCount(0, $this->upstream);
    }

    public function test_cross_origin_collect_is_403() {
        $_SERVER['HTTP_ORIGIN'] = 'https://evil.example';
        $res = $this->collect('v=2&tid=' . self::TID);
        $this->assertSame(403, $res->get_status());
    }

    public function test_collect_response_is_not_no_store() {
        $res = $this->collect('v=2&tid=' . self::TID);
        $headers = $res->get_headers();
        $this->assertFalse(isset($headers['Cache-Control']) && false !== strpos($headers['Cache-Control'], 'no-store'));
    }

    private function with_extra($enabled = true) {
        update_option('trackwp_platforms', array(
            'ga4_enabled'        => $enabled,
            'ga4_measurement_id' => self::TID,
            'ga4_extra_ids'      => array(array('id' => 'G-EXTRA1', 'api_secret' => '')),
        ));
    }

    public function test_extra_tid_in_query_is_accepted() {
        $this->with_extra();
        $res = $this->collect('v=2&tid=G-EXTRA1&en=page_view');
        $this->assertSame(204, $res->get_status());
        $this->assertCount(1, $this->upstream);
    }

    public function test_mixed_primary_and_extra_tid_in_body_is_accepted() {
        $this->with_extra();
        $res = $this->collect('v=2', "v=2&tid=" . self::TID . "&en=page_view\nv=2&tid=G-EXTRA1&en=page_view");
        $this->assertSame(204, $res->get_status());
        $this->assertCount(1, $this->upstream);
    }

    public function test_primary_plus_unknown_tid_is_403() {
        $this->with_extra();
        $res = $this->collect('v=2', "v=2&tid=" . self::TID . "&en=page_view\nv=2&tid=G-UNKNOWN1&en=page_view");
        $this->assertSame(403, $res->get_status());
        $this->assertCount(0, $this->upstream);
    }

    public function test_extra_tid_with_ga4_disabled_is_403() {
        $this->with_extra(false);
        $res = $this->collect('v=2&tid=G-EXTRA1&en=page_view');
        $this->assertSame(403, $res->get_status());
        $this->assertCount(0, $this->upstream);
    }

    public function test_ipv4_mapped_ipv6_is_truncated_as_ipv4() {
        $_SERVER['REMOTE_ADDR'] = '::ffff:203.0.113.77';
        $this->collect('v=2&tid=' . self::TID . '&gcs=G100');
        $this->assertCount(1, $this->upstream);
        $this->assertStringEndsWith('&_uip=203.0.113.0', $this->upstream[0]['url']);
    }
}
