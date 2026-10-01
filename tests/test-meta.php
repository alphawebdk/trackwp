<?php
/**
 * TrackWP_Meta Conversions API adapter tests (W3), extended for
 * PLAN-1.11.1-v2 §9 TR6/TR8 (access_token()/access_token_source() fallback
 * to Meta for WooCommerce's token for the same pixel).
 *
 * Enhanced data is built with the producer TrackWP_Hash::normalize_enhanced();
 * the expected em/ph/ct hashes come from normalization-vectors.json, whose
 * Meta section is output of the real facebook-python-business-sdk
 * normalize.py (commit 0b12f070533df7eed7239e63b445327a2f7482bc).
 *
 * TR6 fallback tests below mostly use the raw-option path
 * (`get_option('wc_facebook_access_token')`), which is what actually runs
 * in every test environment here (fb4woo is not loaded, so
 * `function_exists('facebook_for_woocommerce')` is false in
 * TrackWP_Meta::fb4woo_connection_token()).
 *
 * A global `function facebook_for_woocommerce() {}` stub is deliberately
 * NOT used to test the Connection-handler branch:
 * TrackWP_Meta_Takeover::fb4woo_active() also checks
 * function_exists('facebook_for_woocommerce'), and phpunit loads all test
 * files into one process, so such a stub would leak into every other test
 * file's "fb4woo is not active" assumption (e.g. test-assets.php's
 * takeover-notice tests). Instead, TrackWP_Meta::fb4woo_connection_token()
 * is `protected static` and called via `static::` the whole way down from
 * access_token(), so Test_TrackWP_Meta_FB4Woo_Handler below can override
 * just that one method (subclass test seam, same pattern as
 * Test_TrackWP_Meta_Takeover_With_Woo/No_Woo in test-meta-takeover.php) to
 * prove the Connection-handler value (i.e. fb4woo's own
 * `wc_facebook_connection_access_token` filter) wins over the raw option,
 * with no global state left behind for other test files.
 */

// phpcs:ignore Generic.Files.OneObjectStructurePerFile
class Test_TrackWP_Meta_FB4Woo_Handler extends TrackWP_Meta {
    public static $connection_token = '';
    protected static function fb4woo_connection_token() {
        return static::$connection_token;
    }
}

class Test_TrackWP_Meta extends WP_UnitTestCase {

    private $requests  = array();
    private $responses = array();

    public function set_up() {
        parent::set_up();
        $this->requests  = array();
        $this->responses = array( self::response( 200, '{"events_received":1}' ) );
        update_option( 'trackwp_platforms', array(
            'meta_enabled'      => 1,
            'meta_pixel_id'     => '1234567890',
            'meta_access_token' => TrackWP_Hash::encode( 'token' ),
        ) );
        update_option( 'trackwp_advanced', array( 'default_phone_country' => 'DK' ) );
        $_SERVER['REMOTE_ADDR'] = '198.51.100.7';
        add_filter( 'pre_http_request', array( $this, 'intercept' ), 10, 3 );
    }

    public function tear_down() {
        remove_filter( 'pre_http_request', array( $this, 'intercept' ), 10 );
        unset( $_COOKIE[ LOGGED_IN_COOKIE ] );
        delete_option( 'wc_facebook_pixel_id' );
        delete_option( 'wc_facebook_access_token' );
        Test_TrackWP_Meta_FB4Woo_Handler::$connection_token = '';
        parent::tear_down();
    }

    public function intercept( $pre, $args, $url ) {
        $this->requests[] = array( 'url' => $url, 'body' => json_decode( $args['body'], true ) );
        return count( $this->responses ) > 1 ? array_shift( $this->responses ) : $this->responses[0];
    }

    private static function response( $code, $body = '' ) {
        return array( 'headers' => array(), 'body' => $body, 'response' => array( 'code' => $code, 'message' => '' ), 'cookies' => array(), 'filename' => null );
    }

    private static function event( $overrides = array() ) {
        return array_merge( array(
            'event'           => 'form_submit',
            'event_id'        => 'evt_' . str_repeat( 'b', 32 ),
            'page_url'        => 'https://example.com/kontakt',
            'user_agent'      => 'Mozilla/5.0 Test',
            'fbp'             => 'fb.1.1700000000000.123456',
            'meta_event_name' => '',
            'consent'         => array( 'analytics' => false, 'marketing' => true, 'v' => 1 ),
        ), $overrides );
    }

    private static function meta_vector( $field, $in ) {
        $data = json_decode( file_get_contents( __DIR__ . '/fixtures/normalization-vectors.json' ), true );
        foreach ( $data['meta'] as $v ) {
            if ( $v['field'] === $field && $v['in'] === $in ) {
                return $v['sha256'];
            }
        }
        return null;
    }

    public function test_default_version_is_v25_and_no_ldu() {
        $result = ( new TrackWP_Meta() )->send_event( self::event(), 4.0 );
        $this->assertSame( 'ok', $result['status'] );
        $this->assertSame( 'meta', $result['destination'] );
        $this->assertStringStartsWith( 'https://graph.facebook.com/v25.0/1234567890/events', $this->requests[0]['url'] );
        $entry = $this->requests[0]['body']['data'][0];
        $this->assertArrayNotHasKey( 'data_processing_options', $entry );
        $this->assertArrayNotHasKey( 'data_processing_options_country', $entry );
        $this->assertSame( '198.51.100.7', $entry['user_data']['client_ip_address'] );
        $this->assertSame( 'v25.0', TrackWP_Meta::DEFAULT_API_VERSION );
    }

    public function test_supported_versions_contain_default_and_not_expiring_v24() {
        $this->assertContains( TrackWP_Meta::DEFAULT_API_VERSION, TrackWP_Meta::SUPPORTED_API_VERSIONS );
        $this->assertNotContains( 'v24.0', TrackWP_Meta::SUPPORTED_API_VERSIONS );
        $settings = new TrackWP_Settings();
        $this->assertSame( 'v25.0', $settings->sanitize_platforms( array( 'meta_api_version' => 'v24.0' ) )['meta_api_version'] );
    }

    public function test_resolve_event_name() {
        $this->assertSame( 'Purchase', TrackWP_Meta::resolve_event_name( 'form_submit', 'Purchase' ) );
        $this->assertSame( 'Lead', TrackWP_Meta::resolve_event_name( 'form_submit', 'CustomEvent' ) );
        $this->assertSame( 'Lead', TrackWP_Meta::resolve_event_name( 'form_submit', '' ) );
        $this->assertSame( 'newsletter_signup', TrackWP_Meta::resolve_event_name( 'newsletter_signup', '' ) );
        $this->assertArrayHasKey( 'begin_checkout', TrackWP_Meta::event_map() );
    }

    public function test_fbc_from_fbclid_keeps_value() {
        $this->assertSame( 'fb.1.1700000000000.IwAR_xY-z', TrackWP_Meta::fbc_from_fbclid( 'IwAR_xY-z', 1700000000000 ) );
        $this->assertSame( '', TrackWP_Meta::fbc_from_fbclid( 'bad value' ) );
        $this->assertMatchesRegularExpression( '/^fb\.1\.\d{13}\.abc$/', TrackWP_Meta::fbc_from_fbclid( 'abc' ) );
    }

    public function test_external_id_via_auth_cookie() {
        $uid = self::factory()->user->create();
        $_COOKIE[ LOGGED_IN_COOKIE ] = wp_generate_auth_cookie( $uid, time() + HOUR_IN_SECONDS, 'logged_in' );
        ( new TrackWP_Meta() )->send_event( self::event() );
        $this->assertSame(
            array( hash( 'sha256', $uid . ':' . get_site_url() ) ),
            $this->requests[0]['body']['data'][0]['user_data']['external_id']
        );
    }

    public function test_external_id_from_verified_purchase_wins() {
        $uid = self::factory()->user->create();
        $_COOKIE[ LOGGED_IN_COOKIE ] = wp_generate_auth_cookie( $uid, time() + HOUR_IN_SECONDS, 'logged_in' );
        $customer_hash = hash( 'sha256', '77:' . get_site_url() );
        ( new TrackWP_Meta() )->send_event( self::event( array( 'external_id' => $customer_hash ) ) );
        $this->assertSame( array( $customer_hash ), $this->requests[0]['body']['data'][0]['user_data']['external_id'] );
    }

    public function test_no_external_id_when_logged_out() {
        ( new TrackWP_Meta() )->send_event( self::event() );
        $this->assertArrayNotHasKey( 'external_id', $this->requests[0]['body']['data'][0]['user_data'] );
    }

    public function test_sdk_vectors_reach_user_data() {
        $enhanced = TrackWP_Hash::normalize_enhanced( array(
            'email' => '  John.Doe+Tag@Gmail.COM ',
            'city'  => 'København K.',
            'zip'   => '12345-6789',
        ) );
        ( new TrackWP_Meta() )->send_event( self::event( array( 'enhanced' => $enhanced ) ) );
        $ud = $this->requests[0]['body']['data'][0]['user_data'];
        $this->assertSame( array( self::meta_vector( 'em', '  John.Doe+Tag@Gmail.COM ' ) ), $ud['em'] );
        $this->assertSame( array( self::meta_vector( 'ct', 'København K.' ) ), $ud['ct'] );
        $this->assertSame( array( self::meta_vector( 'zp', '12345-6789' ) ), $ud['zp'] );
    }

    public function test_customer_data_sharing_off_keeps_browser_ids_only() {
        update_option( 'trackwp_advanced', array( 'customer_data_sharing' => 0 ) );
        $uid = self::factory()->user->create();
        $_COOKIE[ LOGGED_IN_COOKIE ] = wp_generate_auth_cookie( $uid, time() + HOUR_IN_SECONDS, 'logged_in' );
        $enhanced = TrackWP_Hash::normalize_enhanced( array( 'email' => 'a@example.com', 'phone' => '12345678' ) );
        ( new TrackWP_Meta() )->send_event( self::event( array( 'enhanced' => $enhanced, 'fbc' => 'fb.1.1700000000000.abc' ) ) );
        $ud = $this->requests[0]['body']['data'][0]['user_data'];
        foreach ( array( 'em', 'ph', 'fn', 'ln', 'external_id' ) as $key ) {
            $this->assertArrayNotHasKey( $key, $ud );
        }
        $this->assertSame( 'fb.1.1700000000000.abc', $ud['fbc'] );
        $this->assertSame( 'fb.1.1700000000000.123456', $ud['fbp'] );
        $this->assertSame( '198.51.100.7', $ud['client_ip_address'] );
    }

    public function test_customer_data_sharing_filter_false_drops_hashed_user_data() {
        add_filter( 'trackwp_customer_data_sharing', '__return_false' );
        $uid = self::factory()->user->create();
        $_COOKIE[ LOGGED_IN_COOKIE ] = wp_generate_auth_cookie( $uid, time() + HOUR_IN_SECONDS, 'logged_in' );
        $enhanced = TrackWP_Hash::normalize_enhanced( array( 'email' => 'a@example.com' ) );
        ( new TrackWP_Meta() )->send_event( self::event( array( 'enhanced' => $enhanced ) ) );
        remove_filter( 'trackwp_customer_data_sharing', '__return_false' );
        $ud = $this->requests[0]['body']['data'][0]['user_data'];
        $this->assertArrayNotHasKey( 'em', $ud );
        $this->assertArrayNotHasKey( 'external_id', $ud );
        $this->assertSame( 'fb.1.1700000000000.123456', $ud['fbp'] );
    }

    public function test_no_consent_is_skipped() {
        $result = ( new TrackWP_Meta() )->send_event( self::event( array( 'consent' => array( 'analytics' => true, 'marketing' => 'true', 'v' => 1 ) ) ) );
        $this->assertSame( 'skipped', $result['status'] );
        $this->assertSame( 'no_consent', $result['reason'] );
        $this->assertCount( 0, $this->requests );
    }

    public function test_missing_consent_is_skipped_without_cookie_fallback() {
        $_COOKIE['trackwp_consent'] = rawurlencode( wp_json_encode( array( 'v' => 1, 'statistics' => true, 'marketing' => true, 'necessary' => true ) ) );
        $event = self::event();
        unset( $event['consent'] );
        $result = ( new TrackWP_Meta() )->send_event( $event );
        unset( $_COOKIE['trackwp_consent'] );
        $this->assertSame( array( 'skipped', 'no_consent' ), array( $result['status'], $result['reason'] ) );
        $this->assertCount( 0, $this->requests );
    }

    public function test_timeout_retried_then_unknown() {
        $this->responses = array( new WP_Error( 'http_request_failed', 'cURL error 28: Operation timed out' ) );
        $result = ( new TrackWP_Meta() )->send_event( self::event(), 4.0 );
        $this->assertSame( 'unknown', $result['status'] );
        $this->assertSame( 'timeout', $result['reason'] );
        $this->assertSame( 2, $result['attempts'] );
    }

    public function test_5xx_then_ok() {
        $this->responses = array( self::response( 502 ), self::response( 200, '{}' ) );
        $result = ( new TrackWP_Meta() )->send_event( self::event(), 4.0 );
        $this->assertSame( 'ok', $result['status'] );
        $this->assertSame( 2, $result['attempts'] );
    }

    public function test_4xx_not_retried_and_detail_has_no_message() {
        $this->responses = array( self::response( 400, '{"error":{"message":"Invalid user a@example.com","type":"OAuthException","code":100,"fbtrace_id":"Abc"}}' ) );
        $result = ( new TrackWP_Meta() )->send_event( self::event(), 4.0 );
        $this->assertSame( 'failed', $result['status'] );
        $this->assertSame( 'http_4xx', $result['reason'] );
        $this->assertSame( 1, $result['attempts'] );
        $this->assertStringNotContainsString( '@', $result['detail'] );
    }

    // --- TR6/TR8: access_token() ------------------------------------------

    public function test_access_token_uses_own_token_first() {
        $this->assertSame( 'token', TrackWP_Meta::access_token() );
        $this->assertSame( 'trackwp', TrackWP_Meta::access_token_source() );
    }

    public function test_access_token_falls_back_to_fb4woo_for_same_pixel() {
        update_option( 'trackwp_platforms', array(
            'meta_enabled'      => 1,
            'meta_pixel_id'     => '1234567890',
            'meta_access_token' => '',
        ) );
        update_option( 'wc_facebook_pixel_id', '1234567890' );
        update_option( 'wc_facebook_access_token', 'EAAB-fb4woo-raw' );

        // Raw: base64_decode() would mangle this value, so an unchanged
        // result proves the fb4woo token is never run through TrackWP_Hash::decode().
        $this->assertSame( 'EAAB-fb4woo-raw', TrackWP_Meta::access_token() );
        $this->assertSame( 'fb4woo', TrackWP_Meta::access_token_source() );
        $this->assertTrue( ( new TrackWP_Meta() )->is_enabled() );
    }

    public function test_access_token_ignores_fb4woo_for_a_different_pixel() {
        update_option( 'trackwp_platforms', array(
            'meta_enabled'      => 1,
            'meta_pixel_id'     => '1234567890',
            'meta_access_token' => '',
        ) );
        update_option( 'wc_facebook_pixel_id', '9999999999' );
        update_option( 'wc_facebook_access_token', 'EAAB-fb4woo-raw' );

        $this->assertSame( '', TrackWP_Meta::access_token() );
        $this->assertSame( '', TrackWP_Meta::access_token_source() );
        $this->assertFalse( ( new TrackWP_Meta() )->is_enabled() );
    }

    public function test_access_token_ignores_fb4woo_without_own_pixel_id() {
        update_option( 'trackwp_platforms', array( 'meta_enabled' => 1, 'meta_pixel_id' => '', 'meta_access_token' => '' ) );
        update_option( 'wc_facebook_pixel_id', '1234567890' );
        update_option( 'wc_facebook_access_token', 'EAAB-fb4woo-raw' );
        $this->assertSame( '', TrackWP_Meta::access_token() );
    }

    public function test_access_token_own_token_wins_over_fb4woo() {
        update_option( 'trackwp_platforms', array(
            'meta_enabled'      => 1,
            'meta_pixel_id'     => '1234567890',
            'meta_access_token' => TrackWP_Hash::encode( 'own-token' ),
        ) );
        update_option( 'wc_facebook_pixel_id', '1234567890' );
        update_option( 'wc_facebook_access_token', 'EAAB-fb4woo-raw' );

        $this->assertSame( 'own-token', TrackWP_Meta::access_token() );
        $this->assertSame( 'trackwp', TrackWP_Meta::access_token_source() );
    }

    public function test_access_token_prefers_connection_handler_over_raw_option() {
        update_option( 'trackwp_platforms', array(
            'meta_enabled'      => 1,
            'meta_pixel_id'     => '1234567890',
            'meta_access_token' => '',
        ) );
        update_option( 'wc_facebook_pixel_id', '1234567890' );
        // Raw option deliberately different from the handler's value, so
        // the assertion proves the Connection-handler path (which applies
        // fb4woo's own wc_facebook_connection_access_token filter) wins.
        update_option( 'wc_facebook_access_token', 'raw-option-token' );

        Test_TrackWP_Meta_FB4Woo_Handler::$connection_token = 'via-connection-handler';

        $this->assertSame( 'via-connection-handler', Test_TrackWP_Meta_FB4Woo_Handler::access_token() );
        $this->assertSame( 'fb4woo', Test_TrackWP_Meta_FB4Woo_Handler::access_token_source() );

        // The unmodified class is untouched: no global state leaked.
        $this->assertSame( 'raw-option-token', TrackWP_Meta::access_token() );
    }

    public function test_fb4woo_fallback_token_is_never_written_to_trackwp_platforms() {
        update_option( 'trackwp_platforms', array(
            'meta_enabled'      => 1,
            'meta_pixel_id'     => '1234567890',
            'meta_access_token' => '',
        ) );
        update_option( 'wc_facebook_pixel_id', '1234567890' );
        update_option( 'wc_facebook_access_token', 'EAAB-fb4woo-raw' );

        ( new TrackWP_Meta() )->send_event( self::event() );

        $stored = get_option( 'trackwp_platforms' );
        $this->assertSame( '', $stored['meta_access_token'], 'the fb4woo fallback token is never written to trackwp_platforms' );
    }

    public function test_send_event_uses_fb4woo_fallback_token_in_request() {
        update_option( 'trackwp_platforms', array(
            'meta_enabled'      => 1,
            'meta_pixel_id'     => '1234567890',
            'meta_access_token' => '',
        ) );
        update_option( 'wc_facebook_pixel_id', '1234567890' );
        update_option( 'wc_facebook_access_token', 'EAAB-fb4woo-raw' );

        $result = ( new TrackWP_Meta() )->send_event( self::event() );
        $this->assertSame( 'ok', $result['status'] );
        $this->assertSame( 'EAAB-fb4woo-raw', $this->requests[0]['body']['access_token'] );
    }
}
