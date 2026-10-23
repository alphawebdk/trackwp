<?php
/**
 * TrackWP_Consent: parse_cookie, effective_consent (K3 + R1/R2/R3),
 * reader_js (executed in node), and the consent-log / withdraw endpoints
 * (K4 + R5/R7/R8).
 *
 * Real producers used:
 *  - consent.js via tests/fixtures/consent-cookie.json (gen-consent-cookie.mjs)
 *  - TrackWP_Consent::write_consent_cookie() (the server's Set-Cookie) for
 *    the PHP->JS reader round trip.
 */
class TrackWP_Consent_Test extends WP_UnitTestCase {

    const ID = '0f8e2c1a-3b4d-4e5f-8a9b-0c1d2e3f4a5b';

    public function set_up() {
        parent::set_up();
        update_option('trackwp_consent', array('consent_version' => 3, 'log_consent' => false, 'cookie_lifetime_months' => 12));
        $_COOKIE = array();
        $_SERVER['HTTP_ORIGIN'] = home_url();
        $_SERVER['REMOTE_ADDR'] = '203.0.113.' . wp_rand(1, 250);
        TrackWP_Cookies::reset_sent();
        global $wp_rest_server;
        $wp_rest_server = null;
        rest_get_server();
    }

    public function tear_down() {
        $_COOKIE = array();
        unset($_SERVER['HTTP_ORIGIN'], $_SERVER['HTTP_REFERER']);
        global $wp_rest_server;
        $wp_rest_server = null;
        parent::tear_down();
    }

    private function cookie_json($overrides = array()) {
        return wp_json_encode(array_merge(array(
            'v' => 3, 'ts' => '2026-09-01T10:00:00.000Z', 'id' => self::ID, 'necessary' => true,
            'statistics' => true, 'marketing' => false, 'personalisation' => false,
        ), $overrides));
    }

    /* ---------------- parse_cookie ---------------- */

    public function test_parse_cookie_valid() {
        $c = TrackWP_Consent::parse_cookie($this->cookie_json());
        $this->assertSame(3, $c['v']);
        $this->assertTrue($c['statistics']);
        $this->assertFalse($c['marketing']);
        $this->assertSame(self::ID, $c['id']);
        $this->assertSame(1788256800000, $c['ts_ms']);
    }

    public function test_parse_cookie_stale_wrong_version_invalid_are_no_choice() {
        $this->assertNull(TrackWP_Consent::parse_cookie($this->cookie_json(array('stale' => true))));
        $this->assertNull(TrackWP_Consent::parse_cookie($this->cookie_json(array('v' => 2))));
        $this->assertNull(TrackWP_Consent::parse_cookie($this->cookie_json(array('v' => '3'))));
        $this->assertNull(TrackWP_Consent::parse_cookie('{not json'));
        $this->assertNull(TrackWP_Consent::parse_cookie(''));
    }

    public function test_parse_cookie_string_true_is_false() {
        $c = TrackWP_Consent::parse_cookie($this->cookie_json(array('statistics' => 'true', 'marketing' => 1)));
        $this->assertFalse($c['statistics']);
        $this->assertFalse($c['marketing']);
    }

    public function test_parse_cookie_url_encoded_raw_value() {
        $c = TrackWP_Consent::parse_cookie(rawurlencode($this->cookie_json()));
        $this->assertNotNull($c);
        $this->assertTrue($c['statistics']);
    }

    public function test_get_current_consent_stale_is_no_choice() {
        $_COOKIE['trackwp_consent'] = $this->cookie_json(array('stale' => true));
        $c = TrackWP_Consent::get_current_consent();
        $this->assertFalse($c['has_choice']);
        $this->assertFalse($c['statistics']);
        $_COOKIE['trackwp_consent'] = $this->cookie_json();
        $c = TrackWP_Consent::get_current_consent();
        $this->assertTrue($c['has_choice']);
        $this->assertTrue($c['statistics']);
        $this->assertSame(self::ID, $c['id']);
    }

    public function test_parse_cookie_from_real_consent_js_fixture() {
        $file = dirname(__FILE__) . '/fixtures/consent-cookie.json';
        if (!file_exists($file)) {
            $this->markTestSkipped('tests/fixtures/consent-cookie.json not generated (node tests/fixtures/gen-consent-cookie.mjs).');
        }
        $fx = json_decode(file_get_contents($file), true);
        update_option('trackwp_consent', array('consent_version' => (int) $fx['parsed']['v']));
        $c = TrackWP_Consent::parse_cookie($fx['raw_cookie_value']);
        $this->assertNotNull($c, 'Cookie written by the real consent.js must parse');
        $this->assertSame(true === $fx['parsed']['statistics'], $c['statistics']);
        $this->assertSame(true === $fx['parsed']['marketing'], $c['marketing']);
    }

    /* ---------------- effective_consent (K3) ---------------- */

    /**
     * Put a cookie into $_COOKIE exactly as the server writes it
     * (TrackWP_Consent::write_consent_cookie is the producer).
     */
    private function set_server_written_cookie($statistics, $marketing) {
        TrackWP_Cookies::reset_sent();
        TrackWP_Consent::write_consent_cookie(3, 1788256800000, self::ID, $statistics, $marketing, false);
        $sent = $this->consent_cookies_sent();
        $this->assertCount(1, $sent, 'write_consent_cookie must produce the cookie');
        $_COOKIE['trackwp_consent'] = $sent[0]['value'];
        TrackWP_Cookies::reset_sent();
    }

    public function test_rule1_payload_with_matching_v() {
        // Changed in 1.11.4: the payload alone no longer grants consent, a valid cookie must agree.
        $this->set_server_written_cookie(true, false);
        $e = TrackWP_Consent::effective_consent(array('analytics' => true, 'marketing' => false, 'v' => 3), true);
        $this->assertSame(array('analytics' => true, 'marketing' => false, 'v' => 3, 'source' => 'payload', 'stale' => false), $e);
    }

    public function test_rule1_payload_true_cookie_false_gives_false() {
        $this->set_server_written_cookie(false, false);
        $e = TrackWP_Consent::effective_consent(array('analytics' => true, 'marketing' => true, 'v' => 3), true);
        $this->assertFalse($e['analytics']);
        $this->assertFalse($e['marketing']);
        $this->assertSame('payload', $e['source']);
    }

    public function test_rule1_payload_true_without_cookie_gives_false() {
        $e = TrackWP_Consent::effective_consent(array('analytics' => true, 'marketing' => true, 'v' => 3), true);
        $this->assertFalse($e['analytics']);
        $this->assertFalse($e['marketing']);
        $this->assertFalse($e['stale']);
    }

    public function test_rule1_payload_false_cookie_true_gives_false() {
        $this->set_server_written_cookie(true, true);
        $e = TrackWP_Consent::effective_consent(array('analytics' => false, 'marketing' => false, 'v' => 3), true);
        $this->assertFalse($e['analytics'], 'The payload can still downgrade');
        $this->assertFalse($e['marketing']);
        $e = TrackWP_Consent::effective_consent(array('analytics' => true, 'marketing' => false, 'v' => 3), true);
        $this->assertTrue($e['analytics']);
        $this->assertFalse($e['marketing']);
    }

    public function test_rule1_payload_true_cookie_true_gives_true() {
        $this->set_server_written_cookie(true, true);
        $e = TrackWP_Consent::effective_consent(array('analytics' => true, 'marketing' => true, 'v' => 3), true);
        $this->assertTrue($e['analytics']);
        $this->assertTrue($e['marketing']);
        $this->assertSame('payload', $e['source']);
    }

    public function test_rule1_payload_true_with_stale_or_old_version_cookie_gives_false() {
        $_COOKIE['trackwp_consent'] = $this->cookie_json(array('stale' => true, 'marketing' => true));
        $e = TrackWP_Consent::effective_consent(array('analytics' => true, 'marketing' => true, 'v' => 3), true);
        $this->assertFalse($e['analytics']);
        $this->assertFalse($e['marketing']);
        $_COOKIE['trackwp_consent'] = $this->cookie_json(array('v' => 2, 'marketing' => true));
        $e = TrackWP_Consent::effective_consent(array('analytics' => true, 'marketing' => true, 'v' => 3), true);
        $this->assertFalse($e['analytics']);
        $this->assertFalse($e['marketing']);
    }

    public function test_rule1_string_and_int_booleans_are_false() {
        $e = TrackWP_Consent::effective_consent(array('analytics' => 'true', 'marketing' => 1, 'v' => 3), true);
        $this->assertFalse($e['analytics']);
        $this->assertFalse($e['marketing']);
    }

    public function test_rule2_differing_v_is_stale() {
        $_COOKIE['trackwp_consent'] = $this->cookie_json(array('marketing' => true));
        $e = TrackWP_Consent::effective_consent(array('analytics' => true, 'marketing' => true, 'v' => 2), true);
        $this->assertTrue($e['stale']);
        $this->assertFalse($e['analytics']);
        $this->assertFalse($e['marketing']);
    }

    public function test_rule3_missing_v_falls_back_to_cookie_not_stale() {
        $_COOKIE['trackwp_consent'] = $this->cookie_json(array('marketing' => true));
        $e = TrackWP_Consent::effective_consent(array('analytics' => false, 'marketing' => false), true);
        $this->assertFalse($e['stale'], 'Missing v must never become 0 -> stale (R1)');
        $this->assertSame('cookie', $e['source']);
        $this->assertTrue($e['analytics']);
        $this->assertTrue($e['marketing']);
    }

    public function test_rule3_missing_consent_object_falls_back_to_cookie() {
        $_COOKIE['trackwp_consent'] = $this->cookie_json();
        $e = TrackWP_Consent::effective_consent(null, false);
        $this->assertSame('cookie', $e['source']);
        $this->assertTrue($e['analytics']);
    }

    public function test_rule3_cookie_with_old_version_gives_false() {
        $_COOKIE['trackwp_consent'] = $this->cookie_json(array('v' => 2, 'marketing' => true));
        $e = TrackWP_Consent::effective_consent(null, false);
        $this->assertSame('none', $e['source']);
        $this->assertFalse($e['analytics']);
        $this->assertFalse($e['marketing']);
    }

    public function test_rule4_non_integer_v_gives_false() {
        $e = TrackWP_Consent::effective_consent(array('analytics' => true, 'v' => '3'), true);
        $this->assertFalse($e['analytics']);
        $this->assertFalse($e['stale']);
    }

    /* ---------------- reader_js in node ---------------- */

    private function run_reader($cookie_header, $set_version = null) {
        $node = trim((string) shell_exec('node --version 2>&1'));
        if (0 !== strpos($node, 'v')) {
            $this->markTestSkipped('node is not available in this container.');
        }
        $script = 'var window={};var document={cookie:' . wp_json_encode($cookie_header) . '};'
            . TrackWP_Consent::reader_js()
            . (null === $set_version ? '' : 'window.trackwpConsentReader.version=' . (int) $set_version . ';var detached=window.trackwpConsentReader.read;')
            . 'process.stdout.write(JSON.stringify(window.trackwpConsentReader.read()));';
        $base = wp_tempnam('trackwp-reader');
        $tmp  = $base . '.js';
        file_put_contents($tmp, $script);
        $out = shell_exec('node ' . escapeshellarg($tmp) . ' 2>&1');
        @unlink($tmp);
        @unlink($base);
        return json_decode((string) $out, true);
    }

    public function test_reader_js_reads_server_written_cookie() {
        TrackWP_Consent::write_consent_cookie(3, 1788256800000, self::ID, true, true, false);
        $sent = TrackWP_Cookies::sent_cookies();
        $last = end($sent);
        // setcookie() url-encodes the value: mirror that for the Cookie header.
        $header = 'foo=bar; trackwp_consent=' . urlencode($last['value']);
        $read = $this->run_reader($header);
        $this->assertSame(3, $read['v']);
        $this->assertTrue($read['statistics']);
        $this->assertTrue($read['marketing']);
        $this->assertSame(self::ID, $read['id']);
        $this->assertSame('2026-09-01T10:00:00.000Z', $read['ts']);
    }

    public function test_reader_js_null_for_stale_wrong_version_and_missing() {
        $this->assertNull($this->run_reader('trackwp_consent=' . rawurlencode($this->cookie_json(array('stale' => true)))));
        $this->assertNull($this->run_reader('trackwp_consent=' . rawurlencode($this->cookie_json(array('v' => 2)))));
        $this->assertNull($this->run_reader('other=1'));
        $this->assertNull($this->run_reader('trackwp_consent=%7Bbroken'));
    }

    public function test_reader_js_uses_writable_version_after_stale() {
        // Cached page renders version 3; the server is now at 4 and consent.js
        // set window.trackwpConsentReader.version = 4 after stale_version.
        $v4 = 'trackwp_consent=' . rawurlencode($this->cookie_json(array('v' => 4)));
        $this->assertNull($this->run_reader($v4), 'Without the update, v4 does not match the inline version 3');
        $read = $this->run_reader($v4, 4);
        $this->assertSame(4, $read['v']);
        $this->assertTrue($read['statistics']);
        $v3 = $this->run_reader('trackwp_consent=' . rawurlencode($this->cookie_json()));
        $this->assertSame(3, $v3['v'], 'Without the update, a v3 cookie is still a valid choice on the v3 page');
        $this->assertNull($this->run_reader('trackwp_consent=' . rawurlencode($this->cookie_json()), 4), 'v3 cookie is no choice once version is 4');
    }

    public function test_snapshot_uses_profile_banner_hash_and_texts() {
        if (!is_callable(array('TrackWP_Consent_Profile', 'banner_hash'))) {
            $this->markTestSkipped('TrackWP_Consent_Profile::banner_hash() not available.');
        }
        remove_filter('query', array($this, '_create_temporary_tables'));
        remove_filter('query', array($this, '_drop_temporary_tables'));
        TrackWP_Consent_Log::create_tables();
        update_option('trackwp_consent', array('consent_version' => 3, 'log_consent' => true));
        $hash = TrackWP_Consent_Profile::banner_hash();
        $this->post_log($this->body(1788256800000, array('banner_hash' => $hash)));
        $stored = json_decode((string) TrackWP_Consent_Log::get_texts($hash), true);
        $this->assertSame(json_decode(wp_json_encode(TrackWP_Consent_Profile::banner_texts()), true), $stored['banner_texts']);
        $other = str_repeat('c', 64);
        $this->post_log($this->body(1788256801000, array('banner_hash' => $other)));
        $this->assertNull(TrackWP_Consent_Log::get_texts($other), 'Unknown (cached) hash is not snapshotted');
    }

    public static function tear_down_after_class() {
        // Leave the tables as the bootstrap did: later classes (and the
        // table_exists() cache) expect them to exist.
        TrackWP_Consent_Log::drop_tables();
        TrackWP_Consent_Log::create_tables();
        parent::tear_down_after_class();
    }

    /* ---------------- consent-log / withdraw endpoints ---------------- */

    private function post_log($body) {
        $req = new WP_REST_Request('POST', '/trackwp/v1/consent-log');
        $req->set_header('Content-Type', 'application/json');
        $req->set_body(wp_json_encode($body));
        return rest_get_server()->dispatch($req);
    }

    private function consent_cookies_sent() {
        return array_values(array_filter(TrackWP_Cookies::sent_cookies(), function ($c) {
            return 'trackwp_consent' === $c['name'];
        }));
    }

    private function body($ts_ms, $overrides = array()) {
        return array_merge(array(
            'statistics' => true, 'marketing' => true, 'personalisation' => false,
            'consent_id' => self::ID, 'consent_version' => 3,
            'ts' => TrackWP_Consent::ms_to_iso($ts_ms), 'ts_ms' => $ts_ms,
            'event_type' => 'set', 'banner_hash' => str_repeat('a', 64),
        ), $overrides);
    }

    public function test_consent_log_sets_cookie_from_body_r8() {
        $res = $this->post_log($this->body(1788256800000));
        $this->assertSame(200, $res->get_status());
        $this->assertSame(array('status' => 'ok', 'consent_id' => self::ID), $res->get_data());
        $this->assertStringContainsString('no-store', $res->get_headers()['Cache-Control']);

        $sent = $this->consent_cookies_sent();
        $this->assertCount(1, $sent);
        $cookie = json_decode($sent[0]['value'], true);
        $this->assertSame(array('v', 'ts', 'id', 'necessary', 'statistics', 'marketing', 'personalisation'), array_keys($cookie));
        $this->assertSame('2026-09-01T10:00:00.000Z', $cookie['ts']);
        $this->assertArrayNotHasKey('domain', $sent[0]['options'], 'Host-only = domain key omitted (R17)');
        $this->assertSame(1788256800 + 360 * DAY_IN_SECONDS, $sent[0]['options']['expires']);
    }

    public function test_consent_log_older_than_cookie_ts_is_not_written() {
        $_COOKIE['trackwp_consent'] = $this->cookie_json(array('ts' => '2026-09-01T10:00:00.000Z'));
        $this->post_log($this->body(1788256800000 - 1));
        $this->assertCount(0, $this->consent_cookies_sent());
        $this->post_log($this->body(1788256800000));
        $this->assertCount(1, $this->consent_cookies_sent(), 'Equal ts is accepted (>=)');
    }

    public function test_consent_log_older_than_stored_transient_is_not_written() {
        $this->post_log($this->body(1788256805000, array('event_type' => 'withdraw')));
        TrackWP_Cookies::reset_sent();
        // Late response of an older accept, cookie already gone from the request.
        $this->post_log($this->body(1788256802000));
        $this->assertCount(0, $this->consent_cookies_sent());
        $this->assertSame('1788256805000', get_transient('trackwp_cts_' . self::ID));
    }

    public function test_future_ts_ms_is_clamped_to_now_plus_5_minutes() {
        $before = (int) floor(microtime(true) * 1000);
        $this->post_log($this->body($before + 10 * YEAR_IN_SECONDS * 1000));
        $after  = (int) floor(microtime(true) * 1000);
        $sent   = $this->consent_cookies_sent();
        $this->assertCount(1, $sent);
        $ts_ms  = TrackWP_Consent::ts_to_ms(json_decode($sent[0]['value'], true)['ts']);
        $this->assertGreaterThanOrEqual($before + 300000, $ts_ms);
        $this->assertLessThanOrEqual($after + 300000, $ts_ms);
        $this->assertLessThanOrEqual($after + 300000, (int) get_transient('trackwp_cts_' . self::ID));

        // A normal later choice (now + 6 min would be clamped too) still wins
        // once real time passes; an old ts is accepted unchanged when newest.
        TrackWP_Cookies::reset_sent();
        delete_transient('trackwp_cts_' . self::ID);
        $this->post_log($this->body(1788256800000));
        $this->assertSame('2026-09-01T10:00:00.000Z', json_decode($this->consent_cookies_sent()[0]['value'], true)['ts']);
    }

    public function test_withdraw_via_post_writes_rejection_and_expires_cookies() {
        $_COOKIE['_ga'] = 'GA1.1.1.2';
        $_COOKIE['_fbp'] = 'fb.1.2.3';
        $this->post_log($this->body(1788256800000, array('event_type' => 'withdraw')));
        $sent  = $this->consent_cookies_sent();
        $this->assertCount(1, $sent);
        $value = json_decode($sent[0]['value'], true);
        $this->assertFalse($value['statistics']);
        $this->assertFalse($value['marketing']);
        $names = wp_list_pluck(TrackWP_Cookies::sent_cookies(), 'name');
        $this->assertContains('_ga', $names);
        $this->assertContains('_fbp', $names);
    }

    public function test_update_keeps_statistics_and_expires_only_marketing() {
        $_COOKIE['_ga'] = 'GA1.1.1.2';
        $_COOKIE['_fbp'] = 'fb.1.2.3';
        $this->post_log($this->body(1788256800000, array('event_type' => 'update', 'marketing' => false)));
        $names = wp_list_pluck(TrackWP_Cookies::sent_cookies(), 'name');
        $this->assertNotContains('_ga', $names);
        $this->assertContains('_fbp', $names);
    }

    public function test_string_booleans_in_body_are_false() {
        $this->post_log($this->body(1788256800000, array('statistics' => 'true', 'marketing' => 1)));
        $value = json_decode($this->consent_cookies_sent()[0]['value'], true);
        $this->assertFalse($value['statistics']);
        $this->assertFalse($value['marketing']);
    }

    public function test_old_version_is_not_written_and_reports_stale() {
        $res = $this->post_log($this->body(1788256800000, array('consent_version' => 2)));
        $this->assertCount(0, $this->consent_cookies_sent());
        $this->assertSame('stale_version', $res->get_data()['consent']);
        $this->assertSame(3, $res->get_data()['current_version']);
    }

    public function test_delete_route_accepts_query_and_body() {
        $req = new WP_REST_Request('DELETE', '/trackwp/v1/consent');
        $req->set_query_params(array('consent_id' => self::ID, 'consent_version' => '3', 'ts_ms' => '1788256800000'));
        $res = rest_get_server()->dispatch($req);
        $this->assertSame(200, $res->get_status());
        $this->assertSame('withdrawn', $res->get_data()['status']);
        $this->assertCount(1, $this->consent_cookies_sent());

        TrackWP_Cookies::reset_sent();
        $req = new WP_REST_Request('DELETE', '/trackwp/v1/consent');
        $req->set_header('Content-Type', 'application/json');
        $req->set_body(wp_json_encode(array('consent_id' => self::ID, 'consent_version' => 3, 'ts_ms' => 1788256801000, 'statistics' => true)));
        rest_get_server()->dispatch($req);
        $sent = $this->consent_cookies_sent();
        $this->assertCount(1, $sent);
        $this->assertFalse(json_decode($sent[0]['value'], true)['statistics'], 'DELETE is always a full withdraw');
    }

    public function test_cross_origin_is_rejected() {
        $_SERVER['HTTP_ORIGIN'] = 'https://evil.example';
        $res = $this->post_log($this->body(1788256800000));
        $this->assertSame(403, $res->get_status());
    }

    /* ---------------- GA4 queue purge on withdraw / downgrade ---------------- */

    const OTHER_ID = '1a2b3c4d-5e6f-4a7b-8c9d-0e1f2a3b4c5d';

    private function queued($consent_id, $marketing = true) {
        return array(
            'event'              => 'page_view',
            '_consent_id'        => $consent_id,
            '_consent_marketing' => $marketing,
            '_user_data'         => $marketing ? array('sha256_email_address' => str_repeat('a', 64)) : array(),
        );
    }

    public function test_withdraw_purges_only_own_ga4_queue_entries() {
        $legacy = $this->queued('', true);
        unset($legacy['_consent_id']);
        set_transient('trackwp_ga4_queue', array($this->queued(self::ID), $this->queued(self::OTHER_ID), $legacy), 3600);
        $this->post_log($this->body(1788256800000, array('event_type' => 'withdraw')));
        $queue = get_transient('trackwp_ga4_queue');
        $this->assertCount(2, $queue);
        $this->assertSame(self::OTHER_ID, $queue[0]['_consent_id']);
        $this->assertArrayNotHasKey('_consent_id', $queue[1], 'Entries without _consent_id survive');
    }

    public function test_full_withdraw_via_delete_purges_own_ga4_queue_entries() {
        set_transient('trackwp_ga4_queue', array($this->queued(self::ID), $this->queued(self::OTHER_ID)), 3600);
        $req = new WP_REST_Request('DELETE', '/trackwp/v1/consent');
        $req->set_query_params(array('consent_id' => self::ID, 'consent_version' => '3', 'ts_ms' => '1788256800000'));
        rest_get_server()->dispatch($req);
        $queue = get_transient('trackwp_ga4_queue');
        $this->assertCount(1, $queue);
        $this->assertSame(self::OTHER_ID, $queue[0]['_consent_id']);
    }

    public function test_marketing_downgrade_clears_user_data_but_keeps_event() {
        set_transient('trackwp_ga4_queue', array($this->queued(self::ID), $this->queued(self::OTHER_ID)), 3600);
        $this->post_log($this->body(1788256800000, array('event_type' => 'update', 'marketing' => false)));
        $queue = get_transient('trackwp_ga4_queue');
        $this->assertCount(2, $queue);
        $this->assertFalse($queue[0]['_consent_marketing']);
        $this->assertSame(array(), $queue[0]['_user_data']);
        $this->assertTrue($queue[1]['_consent_marketing'], 'Other consent_id untouched');
        $this->assertNotEmpty($queue[1]['_user_data']);
    }

    public function test_full_accept_does_not_touch_ga4_queue() {
        $q = array($this->queued(self::ID));
        set_transient('trackwp_ga4_queue', $q, 3600);
        $this->post_log($this->body(1788256800000));
        $this->assertSame($q, get_transient('trackwp_ga4_queue'));
    }

    public function test_older_choice_does_not_purge_ga4_queue() {
        $q = array($this->queued(self::ID));
        set_transient('trackwp_ga4_queue', $q, 3600);
        $_COOKIE['trackwp_consent'] = $this->cookie_json(array('ts' => '2026-09-01T10:00:00.000Z'));
        $this->post_log($this->body(1788256800000 - 1, array('event_type' => 'withdraw')));
        $this->assertSame($q, get_transient('trackwp_ga4_queue'), 'A late older withdraw is ignored like for the cookie');
    }

    /* ---------------- snapshot_current_banner (fund 8) ---------------- */

    public function test_snapshot_current_banner_stores_for_current_hash_and_never_overwrites() {
        remove_filter('query', array($this, '_create_temporary_tables'));
        remove_filter('query', array($this, '_drop_temporary_tables'));
        // Real (non-temporary) tables persist across tests: start from empty ones so no stale snapshot for this hash exists.
        TrackWP_Consent_Log::drop_tables();
        TrackWP_Consent_Log::create_tables();
        update_option('trackwp_consent', array('consent_version' => 3, 'log_consent' => true, 'description_mode' => 'auto'));
        $hash = TrackWP_Consent_Profile::banner_hash();
        // Saving the option now snapshots the banner itself (maybe_auto_bump), so the snapshot exists already.
        $this->assertNotNull(TrackWP_Consent_Log::get_texts($hash));

        // It already exists for the current hash: INSERT IGNORE stores nothing new.
        $this->assertFalse(TrackWP_Consent::snapshot_current_banner());
        $stored = json_decode((string) TrackWP_Consent_Log::get_texts($hash), true);
        $this->assertSame(3, $stored['consent_version']);
        $this->assertSame(json_decode(wp_json_encode(TrackWP_Consent_Profile::banner_texts()), true), $stored['banner_texts']);
        $this->assertSame(json_decode(wp_json_encode(TrackWP_Consent_Profile::material()), true), $stored['material']);
        $this->assertSame('auto', $stored['style']['description_mode']);

        // Same hash again, now with a changed style value that does not alter the hash: the first snapshot stays.
        $before = TrackWP_Consent_Log::get_texts($hash);
        update_option('trackwp_consent', array('consent_version' => 3, 'log_consent' => true, 'description_mode' => 'auto', 'position' => 'top'));
        $this->assertSame($hash, TrackWP_Consent_Profile::banner_hash());
        $this->assertFalse(TrackWP_Consent::snapshot_current_banner());
        $this->assertSame($before, TrackWP_Consent_Log::get_texts($hash));
    }

    public function test_stats_counted_once_per_action() {
        delete_option('trackwp_stats');
        $this->post_log($this->body(1788256800000, array('event_type' => 'withdraw')));
        $stats = get_option('trackwp_stats', array());
        $today = gmdate('Y-m-d');
        $this->assertSame(1, (int) $stats[ $today ]['consent_reject']);
        $this->assertArrayNotHasKey('consent_accept', $stats[ $today ]);
    }
}
