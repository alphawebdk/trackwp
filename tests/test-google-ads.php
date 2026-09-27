<?php
/**
 * TrackWP_Google_Ads API upload tests (W3).
 */
class Test_TrackWP_Google_Ads extends WP_UnitTestCase {

    private $requests  = array();
    private $responses = array();
    private $log_file;

    public function set_up() {
        parent::set_up();
        $this->requests  = array();
        $this->responses = array( self::response( 200, '{"results":[{}]}' ) );
        update_option( 'trackwp_platforms', array(
            'google_ads_enabled'              => 1,
            'google_ads_conversion_id'        => 'AW-123',
            'google_ads_customer_id'          => '123-456-7890',
            'google_ads_conversion_action_id' => '987',
            'google_ads_developer_token'      => TrackWP_Hash::encode( 'dev' ),
            'google_ads_oauth_client_id'      => 'client',
            'google_ads_oauth_client_secret'  => TrackWP_Hash::encode( 'secret' ),
            'google_ads_oauth_refresh_token'  => TrackWP_Hash::encode( 'refresh' ),
        ) );
        update_option( 'trackwp_advanced', array( 'capi_debug_logging_enabled' => 1 ) );
        set_transient( 'trackwp_gads_access_token', 'access', HOUR_IN_SECONDS );
        $this->log_file = WP_CONTENT_DIR . '/trackwp/google-ads-pending.log';
        if ( file_exists( $this->log_file ) ) {
            unlink( $this->log_file );
        }
        unset( $_COOKIE['_gcl_aw'] );
        add_filter( 'pre_http_request', array( $this, 'intercept' ), 10, 3 );
    }

    public function tear_down() {
        remove_filter( 'pre_http_request', array( $this, 'intercept' ), 10 );
        parent::tear_down();
    }

    public function intercept( $pre, $args, $url ) {
        $this->requests[] = array( 'url' => $url, 'body' => json_decode( (string) $args['body'], true ) );
        return count( $this->responses ) > 1 ? array_shift( $this->responses ) : $this->responses[0];
    }

    private static function response( $code, $body = '' ) {
        return array( 'headers' => array(), 'body' => $body, 'response' => array( 'code' => $code, 'message' => '' ), 'cookies' => array(), 'filename' => null );
    }

    private static function event( $overrides = array() ) {
        return array_merge( array(
            'event'     => 'purchase',
            'event_id'  => 'evt_' . str_repeat( 'c', 32 ),
            'value'     => 125.5,
            'currency'  => 'DKK',
            'gclid'     => 'GCLID_secret-123',
            'ecommerce' => array( 'transaction_id' => '1042' ),
        ), $overrides );
    }

    private static function granted() {
        return array( 'analytics' => true, 'marketing' => true, 'v' => 1, 'source' => 'payload', 'stale' => false );
    }

    private function conversion() {
        return $this->requests[0]['body']['conversions'][0];
    }

    public function test_v25_order_id_consent_and_value() {
        $result = ( new TrackWP_Google_Ads() )->send_conversion( self::event(), self::granted(), 4.0 );
        $this->assertSame( 'ok', $result['status'] );
        $this->assertSame( 'google_ads', $result['destination'] );
        $this->assertSame( 'https://googleads.googleapis.com/v25/customers/1234567890:uploadClickConversions', $this->requests[0]['url'] );
        $c = $this->conversion();
        $this->assertSame( '1042', $c['orderId'] );
        $this->assertSame( array( 'adUserData' => 'GRANTED' ), $c['consent'] );
        $this->assertSame( 125.5, (float) $c['conversionValue'] );
        $this->assertSame( 'customers/1234567890/conversionActions/987', $c['conversionAction'] );
    }

    public function test_order_id_falls_back_to_event_id() {
        ( new TrackWP_Google_Ads() )->send_conversion( self::event( array( 'ecommerce' => array() ) ), self::granted() );
        $this->assertSame( 'evt_' . str_repeat( 'c', 32 ), $this->conversion()['orderId'] );
    }

    public function test_gbraid_without_gclid() {
        ( new TrackWP_Google_Ads() )->send_conversion( self::event( array( 'gclid' => '', 'gbraid' => 'GBRAID_1' ) ), self::granted() );
        $c = $this->conversion();
        $this->assertSame( 'GBRAID_1', $c['gbraid'] );
        $this->assertArrayNotHasKey( 'gclid', $c );
        $this->assertArrayNotHasKey( 'wbraid', $c );
    }

    public function test_gclid_and_gbraid_combined_per_upload_guide() {
        ( new TrackWP_Google_Ads() )->send_conversion( self::event( array( 'gbraid' => 'GBRAID_1', 'wbraid' => 'WBRAID_1' ) ), self::granted() );
        $c = $this->conversion();
        $this->assertSame( 'GCLID_secret-123', $c['gclid'] );
        $this->assertSame( 'GBRAID_1', $c['gbraid'] );
        $this->assertArrayNotHasKey( 'wbraid', $c );
    }

    public function test_gbraid_and_wbraid_never_together() {
        ( new TrackWP_Google_Ads() )->send_conversion( self::event( array( 'gclid' => '', 'gbraid' => 'GBRAID_1', 'wbraid' => 'WBRAID_1' ) ), self::granted() );
        $c = $this->conversion();
        $this->assertSame( 'GBRAID_1', $c['gbraid'] );
        $this->assertArrayNotHasKey( 'wbraid', $c );
    }

    public function test_wbraid_alone() {
        ( new TrackWP_Google_Ads() )->send_conversion( self::event( array( 'gclid' => '', 'wbraid' => 'WBRAID_1' ) ), self::granted() );
        $this->assertSame( 'WBRAID_1', $this->conversion()['wbraid'] );
    }

    public function test_skipped_reasons() {
        $ads = new TrackWP_Google_Ads();
        $r   = $ads->send_conversion( self::event(), array( 'analytics' => true, 'marketing' => 'true' ) );
        $this->assertSame( array( 'skipped', 'no_consent' ), array( $r['status'], $r['reason'] ) );

        $r = $ads->send_conversion( self::event( array( 'gclid' => '' ) ), self::granted() );
        $this->assertSame( array( 'skipped', 'no_click_id' ), array( $r['status'], $r['reason'] ) );

        update_option( 'trackwp_platforms', array( 'google_ads_enabled' => 1 ) );
        $r = ( new TrackWP_Google_Ads() )->send_conversion( self::event(), self::granted() );
        $this->assertSame( array( 'skipped', 'not_configured' ), array( $r['status'], $r['reason'] ) );
        $this->assertCount( 0, $this->requests );
    }

    public function test_partial_failure_and_no_gclid_in_log_or_detail() {
        $this->responses = array( self::response( 200, '{"partialFailureError":{"code":3,"message":"Invalid gclid GCLID_secret-123"}}' ) );
        $r = ( new TrackWP_Google_Ads() )->send_conversion( self::event(), self::granted() );
        $this->assertSame( array( 'failed', 'partial_failure' ), array( $r['status'], $r['reason'] ) );
        $this->assertStringNotContainsString( 'GCLID_secret-123', $r['detail'] );
        $this->assertFileExists( $this->log_file );
        $this->assertStringNotContainsString( 'GCLID_secret-123', file_get_contents( $this->log_file ) );
    }

    public function test_success_log_has_no_gclid() {
        ( new TrackWP_Google_Ads() )->send_conversion( self::event(), self::granted() );
        $this->assertStringNotContainsString( 'GCLID_secret-123', file_get_contents( $this->log_file ) );
    }

    public function test_timeout_is_unknown_and_429_is_failed() {
        $this->responses = array( new WP_Error( 'http_request_failed', 'cURL error 28: Operation timed out' ) );
        $r = ( new TrackWP_Google_Ads() )->send_conversion( self::event(), self::granted() );
        $this->assertSame( array( 'unknown', 'timeout', 1 ), array( $r['status'], $r['reason'], $r['attempts'] ) );

        $this->requests  = array();
        $this->responses = array( self::response( 429, '{}' ) );
        $r = ( new TrackWP_Google_Ads() )->send_conversion( self::event(), self::granted() );
        $this->assertSame( array( 'failed', 'http_429', 429 ), array( $r['status'], $r['reason'], $r['http_code'] ) );
        $this->assertCount( 1, $this->requests );
    }

    public function test_user_identifiers_follow_customer_data_sharing() {
        $enhanced = TrackWP_Hash::normalize_enhanced( array( 'email' => 'a@example.com' ) );
        ( new TrackWP_Google_Ads() )->send_conversion( self::event( array( 'enhanced' => $enhanced ) ), self::granted() );
        $this->assertSame( array( array( 'hashedEmail' => hash( 'sha256', 'a@example.com' ) ) ), $this->conversion()['userIdentifiers'] );

        update_option( 'trackwp_advanced', array( 'customer_data_sharing' => 0 ) );
        $this->requests = array();
        ( new TrackWP_Google_Ads() )->send_conversion( self::event( array( 'enhanced' => $enhanced ) ), self::granted() );
        $this->assertArrayNotHasKey( 'userIdentifiers', $this->conversion() );
    }
}
