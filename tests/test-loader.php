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
        unset($_SERVER['QUERY_STRING']);
        $this->upstream = array();
        add_filter('pre_http_request', array($this, 'capture_upstream'), 10, 3);
        global $wp_rest_server;
        $wp_rest_server = null;
        rest_get_server();
    }

    public function tear_down() {
        remove_filter('pre_http_request', array($this, 'capture_upstream'), 10);
        unset($_SERVER['HTTP_ORIGIN'], $_SERVER['QUERY_STRING']);
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

    public function test_gcs_gcd_unchanged_and_uip_from_client_ip() {
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
}
