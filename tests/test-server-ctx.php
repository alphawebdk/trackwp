<?php
/**
 * _server_ctx branches in the GA4, Meta and Google Ads adapters (backup purchase).
 *
 * A server-originated event (no browser request) must never pick up the
 * current request's IP, cookies or logged-in user; the only IP allowed is
 * ctx['customer_ip'] (the order's checkout IP). Events without _server_ctx
 * stay byte-identical to before. HTTP is intercepted with pre_http_request;
 * the queued GA4 case goes through the producers send_event()/flush_queue().
 *
 * @group server-ctx
 */
class TrackWP_Server_Ctx_Test extends WP_UnitTestCase {

    private $requests  = array();
    private $log_file;

    public function set_up() {
        parent::set_up();
        $this->requests = array();
        update_option( 'trackwp_platforms', array(
            'ga4_enabled'                     => 1,
            'ga4_measurement_id'              => 'G-TEST1234',
            'ga4_api_secret'                  => TrackWP_Hash::encode( 'secret' ),
            'meta_enabled'                    => 1,
            'meta_pixel_id'                   => '1234567890',
            'meta_access_token'               => TrackWP_Hash::encode( 'token' ),
            'google_ads_enabled'              => 1,
            'google_ads_conversion_id'        => 'AW-123',
            'google_ads_customer_id'          => '123-456-7890',
            'google_ads_conversion_action_id' => '987',
            'google_ads_developer_token'      => TrackWP_Hash::encode( 'dev' ),
            'google_ads_oauth_client_id'      => 'client',
            'google_ads_oauth_client_secret'  => TrackWP_Hash::encode( 'secret' ),
            'google_ads_oauth_refresh_token'  => TrackWP_Hash::encode( 'refresh' ),
        ) );
        update_option( 'trackwp_advanced', array( 'ga4_user_id_enabled' => 1 ) );
        delete_transient( 'trackwp_ga4_queue' );
        set_transient( 'trackwp_gads_access_token', 'access', HOUR_IN_SECONDS );
        $this->log_file = WP_CONTENT_DIR . '/trackwp/google-ads-pending.log';
        if ( file_exists( $this->log_file ) ) {
            unlink( $this->log_file );
        }
        $_SERVER['REMOTE_ADDR'] = '203.0.113.9';
        unset( $_COOKIE['_gcl_aw'], $_COOKIE['trackwp_consent'] );
        add_filter( 'pre_http_request', array( $this, 'intercept' ), 10, 3 );
    }

    public function tear_down() {
        remove_filter( 'pre_http_request', array( $this, 'intercept' ), 10 );
        unset( $_COOKIE[ LOGGED_IN_COOKIE ], $_COOKIE['_gcl_aw'], $_COOKIE['trackwp_consent'] );
        wp_clear_scheduled_hook( 'trackwp_flush_ga4' );
        delete_transient( 'trackwp_ga4_queue' );
        parent::tear_down();
    }

    public function intercept( $pre, $args, $url ) {
        $this->requests[] = array( 'url' => $url, 'body' => json_decode( (string) $args['body'], true ) );
        return array(
            'headers'  => array(),
            'body'     => '{"results":[{}],"events_received":1}',
            'response' => array( 'code' => 200, 'message' => '' ),
            'cookies'  => array(),
            'filename' => null,
        );
    }

    private static function granted( $analytics = true, $marketing = true ) {
        return array( 'analytics' => $analytics, 'marketing' => $marketing, 'v' => 1, 'source' => 'snapshot', 'stale' => false );
    }

    private static function event( $overrides = array() ) {
        return array_merge( array(
            'event'      => 'purchase',
            'event_id'   => 'evt_' . str_repeat( 'a', 32 ),
            'client_id'  => '123.456',
            'gclid'      => 'GCLID_secret-123',
            'page_url'   => 'https://example.com/kasse',
            'user_agent' => 'Mozilla/5.0 Snapshot',
            'value'      => 125.5,
            'currency'   => 'DKK',
            'ecommerce'  => array( 'transaction_id' => '1042' ),
            'consent'    => self::granted(),
        ), $overrides );
    }

    private static function ctx( $extra = array() ) {
        return array_merge( array(
            'origin'     => 'backup',
            'event_time' => 1700000000,
            'consent_id' => '11111111-1111-4111-8111-111111111111',
        ), $extra );
    }

    private static function login_cookie() {
        $uid = self::factory()->user->create();
        $_COOKIE[ LOGGED_IN_COOKIE ] = wp_generate_auth_cookie( $uid, time() + HOUR_IN_SECONDS, 'logged_in' );
        return $uid;
    }

    // ---- GA4 ---------------------------------------------------------

    public function test_ga4_ctx_never_uses_request_ip_user_or_consent_cookie() {
        self::login_cookie();
        $_COOKIE['trackwp_consent'] = wp_json_encode( array( 'id' => 'cookie-id-from-request' ) );

        ( new TrackWP_GA4() )->send_event( self::event( array( '_server_ctx' => self::ctx() ) ) );
        $body = $this->requests[0]['body'];
        $this->assertArrayNotHasKey( 'ip_override', $body );
        $this->assertArrayNotHasKey( 'user_id', $body );
        $this->assertSame( 'Mozilla/5.0 Snapshot', $body['user_agent'] );
    }

    public function test_ga4_ctx_consent_id_comes_from_ctx_not_cookie() {
        $_COOKIE['trackwp_consent'] = wp_json_encode( array(
            'v' => TrackWP_Consent::server_version(), 'ts' => '2026-09-01T10:00:00.000Z', 'id' => '22222222-2222-4222-8222-222222222222',
            'necessary' => true, 'statistics' => true, 'marketing' => true, 'personalisation' => false,
        ) );
        update_option( 'trackwp_advanced', array( 'batching_enabled' => 1 ) );
        $result = ( new TrackWP_GA4() )->send_event( self::event( array( '_server_ctx' => self::ctx() ) ) );
        $this->assertSame( 'queued', $result['status'] );
        $queue = get_transient( 'trackwp_ga4_queue' );
        $this->assertSame( '11111111-1111-4111-8111-111111111111', $queue[0]['_consent_id'] );
        $this->assertSame( '', $queue[0]['_ip_override'] );
    }

    public function test_ga4_ctx_customer_ip_used_with_analytics_consent() {
        $body = ( new TrackWP_GA4() )->send_event( self::event( array( '_server_ctx' => self::ctx( array( 'customer_ip' => '198.51.100.20' ) ) ) ) );
        $this->assertSame( 'ok', $body['status'] );
        $this->assertSame( '198.51.100.20', $this->requests[0]['body']['ip_override'] );
        $this->assertNotSame( '203.0.113.9', $this->requests[0]['body']['ip_override'] );
    }

    public function test_ga4_ctx_customer_ip_dropped_without_analytics_or_when_invalid() {
        ( new TrackWP_GA4() )->send_event( self::event( array(
            'consent'     => self::granted( false, true ),
            '_server_ctx' => self::ctx( array( 'customer_ip' => '198.51.100.20' ) ),
        ) ) );
        ( new TrackWP_GA4() )->send_event( self::event( array( '_server_ctx' => self::ctx( array( 'customer_ip' => 'not-an-ip' ) ) ) ) );
        foreach ( $this->requests as $request ) {
            $this->assertArrayNotHasKey( 'ip_override', $request['body'] );
        }
        $this->assertGreaterThanOrEqual( 1, count( $this->requests ) );
    }

    public function test_ga4_ctx_user_id_hash_from_ctx_only() {
        self::login_cookie();
        ( new TrackWP_GA4() )->send_event( self::event( array( '_server_ctx' => self::ctx( array( 'user_id_hash' => str_repeat( 'b', 64 ) ) ) ) ) );
        $this->assertSame( str_repeat( 'b', 64 ), $this->requests[0]['body']['user_id'] );
    }

    public function test_ga4_ctx_timestamp_direct_and_queued() {
        ( new TrackWP_GA4() )->send_event( self::event( array( '_server_ctx' => self::ctx() ) ) );
        $this->assertSame( 1700000000000000, $this->requests[0]['body']['events'][0]['timestamp_micros'] );

        update_option( 'trackwp_advanced', array( 'batching_enabled' => 1 ) );
        $ga4 = new TrackWP_GA4();
        $this->assertSame( 'queued', $ga4->send_event( self::event( array( '_server_ctx' => self::ctx() ) ) )['status'] );
        $queue = get_transient( 'trackwp_ga4_queue' );
        $this->assertNotSame( 1700000000000000, $queue[0]['_queued_at_us'] );
        $ga4->flush_queue();
        $this->assertSame( 1700000000000000, $this->requests[1]['body']['events'][0]['timestamp_micros'] );
    }

    public function test_ga4_without_ctx_is_unchanged() {
        $uid = self::login_cookie();
        ( new TrackWP_GA4() )->send_event( self::event() );
        $body = $this->requests[0]['body'];
        $this->assertSame( '203.0.113.9', $body['ip_override'] );
        $this->assertSame( hash( 'sha256', $uid . ':' . get_site_url() ), $body['user_id'] );
        $this->assertArrayNotHasKey( 'timestamp_micros', $body['events'][0] );
    }

    // ---- Meta --------------------------------------------------------

    public function test_meta_ctx_never_uses_request_ip_or_logged_in_user() {
        self::login_cookie();
        ( new TrackWP_Meta() )->send_event( self::event( array( '_server_ctx' => self::ctx() ) ) );
        $entry = $this->requests[0]['body']['data'][0];
        $this->assertArrayNotHasKey( 'client_ip_address', $entry['user_data'] );
        $this->assertArrayNotHasKey( 'external_id', $entry['user_data'] );
        $this->assertSame( 1700000000, $entry['event_time'] );
        $this->assertSame( 'Mozilla/5.0 Snapshot', $entry['user_data']['client_user_agent'] );
    }

    public function test_meta_ctx_customer_ip_used_with_marketing_consent() {
        ( new TrackWP_Meta() )->send_event( self::event( array( '_server_ctx' => self::ctx( array( 'customer_ip' => '198.51.100.20' ) ) ) ) );
        $this->assertSame( '198.51.100.20', $this->requests[0]['body']['data'][0]['user_data']['client_ip_address'] );
    }

    public function test_meta_ctx_customer_ip_dropped_without_marketing_or_when_invalid() {
        $body = ( new TrackWP_Meta() )->build_body( self::event( array(
            'consent'     => self::granted( true, false ),
            '_server_ctx' => self::ctx( array( 'customer_ip' => '198.51.100.20' ) ),
        ) ) );
        $this->assertArrayNotHasKey( 'client_ip_address', $body['data'][0]['user_data'] );

        $body = ( new TrackWP_Meta() )->build_body( self::event( array( '_server_ctx' => self::ctx( array( 'customer_ip' => 'x' ) ) ) ) );
        $this->assertArrayNotHasKey( 'client_ip_address', $body['data'][0]['user_data'] );
    }

    public function test_meta_ctx_external_id_only_from_event_data() {
        $hash = str_repeat( 'c', 64 );
        ( new TrackWP_Meta() )->send_event( self::event( array( 'external_id' => $hash, '_server_ctx' => self::ctx() ) ) );
        $this->assertSame( array( $hash ), $this->requests[0]['body']['data'][0]['user_data']['external_id'] );
    }

    public function test_meta_without_ctx_is_unchanged() {
        $before = time();
        $uid    = self::login_cookie();
        ( new TrackWP_Meta() )->send_event( self::event() );
        $entry = $this->requests[0]['body']['data'][0];
        $this->assertSame( '203.0.113.9', $entry['user_data']['client_ip_address'] );
        $this->assertSame( array( hash( 'sha256', $uid . ':' . get_site_url() ) ), $entry['user_data']['external_id'] );
        $this->assertGreaterThanOrEqual( $before, $entry['event_time'] );
    }

    // ---- Google Ads --------------------------------------------------

    public function test_ads_ctx_ignores_gcl_aw_cookie_and_uses_event_time() {
        $_COOKIE['_gcl_aw'] = 'GCL.1700000000.COOKIE_gclid';
        $event = self::event( array( '_server_ctx' => self::ctx() ) );
        unset( $event['gclid'] );
        $result = ( new TrackWP_Google_Ads() )->send_conversion( $event, self::granted(), 4.0 );
        $this->assertSame( 'skipped', $result['status'] );
        $this->assertSame( 'no_click_id', $result['reason'] );
        $this->assertCount( 0, $this->requests );

        $result = ( new TrackWP_Google_Ads() )->send_conversion( self::event( array( '_server_ctx' => self::ctx() ) ), self::granted(), 4.0 );
        $this->assertSame( 'ok', $result['status'], wp_json_encode( $result ) );
        $conversion = $this->requests[0]['body']['conversions'][0];
        $this->assertSame( gmdate( 'Y-m-d H:i:sP', 1700000000 ), $conversion['conversionDateTime'] );
        $this->assertSame( 'GCLID_secret-123', $conversion['gclid'] );
    }

    public function test_ads_without_ctx_keeps_cookie_fallback_and_now() {
        $_COOKIE['_gcl_aw'] = 'GCL.1700000000.COOKIE_gclid';
        $event = self::event();
        unset( $event['gclid'] );
        $result = ( new TrackWP_Google_Ads() )->send_conversion( $event, self::granted(), 4.0 );
        $this->assertSame( 'ok', $result['status'] );
        $conversion = $this->requests[0]['body']['conversions'][0];
        $this->assertSame( 'COOKIE_gclid', $conversion['gclid'] );
        $this->assertGreaterThan( 1700000000, strtotime( $conversion['conversionDateTime'] ) );
    }
}
