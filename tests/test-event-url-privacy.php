<?php
/**
 * URL/PII cleaning through the real /event route (K6, BESLUTNINGER 7.7).
 *
 * test-privacy.php covers TrackWP_Privacy::clean_url() vectors in isolation;
 * this file proves the proxy actually applies the cleaning to what leaves
 * for GA4, also WITH full consent (the order key and e-mail addresses are
 * removed for all destinations, even after consent).
 */

class TrackWP_Event_Url_Privacy_Test extends WP_UnitTestCase {

    /** @var array Captured outgoing HTTP requests: list of {url, body}. */
    private $http = array();

    public function set_up() {
        parent::set_up();
        $this->http = array();
        add_filter( 'pre_http_request', array( $this, 'capture_http' ), 10, 3 );
        update_option( 'trackwp_platforms', array(
            'ga4_enabled'        => true,
            'ga4_measurement_id' => 'G-TEST12345',
            'ga4_api_secret'     => 'test-secret',
        ) );
        update_option( 'trackwp_advanced', array(
            'dedup_mode'       => 'client_and_server',
            'batching_enabled' => false,
        ) );
        update_option( 'trackwp_consent', array( 'consent_version' => 1 ) );
        update_option( 'trackwp_events', TrackWP_Events::get_defaults() );
        $_SERVER['HTTP_ORIGIN'] = home_url();
        $_SERVER['REMOTE_ADDR'] = '10.' . wp_rand( 0, 255 ) . '.' . wp_rand( 0, 255 ) . '.' . wp_rand( 1, 254 );
        $_COOKIE                = array();
    }

    public function tear_down() {
        remove_filter( 'pre_http_request', array( $this, 'capture_http' ), 10 );
        unset( $_SERVER['HTTP_ORIGIN'] );
        $_COOKIE = array();
        parent::tear_down();
    }

    public function capture_http( $pre, $args, $url ) {
        $this->http[] = array( 'url' => $url, 'body' => isset( $args['body'] ) ? (string) $args['body'] : '' );
        return array(
            'headers'  => array(),
            'body'     => '',
            'response' => array( 'code' => 204, 'message' => 'mock' ),
            'cookies'  => array(),
            'filename' => null,
        );
    }

    /** GA4 bodies re-encoded without escaped slashes, so plain substrings can be searched. */
    private function ga4_bodies() {
        $out = array();
        foreach ( $this->http as $r ) {
            if ( false !== strpos( $r['url'], 'google-analytics.com/mp/collect' ) ) {
                $out[] = wp_json_encode( json_decode( $r['body'], true ), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
            }
        }
        return $out;
    }

    public function test_order_key_and_email_never_reach_ga4_even_with_full_consent() {
        $body = array(
            'event'         => 'form_submit',
            'event_id'      => 'evt_' . str_repeat( 'c3', 16 ),
            'page_url'      => 'https://example.org/checkout/order-received/12/?key=wc_order_SECRETKEY1&email=kunde%40example.org&utm_source=nyhedsbrev',
            'page_referrer' => 'https://example.org/konto/?token=TOPSECRET2&utm_medium=mail',
            'page_title'    => 'Tak kunde@example.org',
            'client_id'     => '123456789.1700000000',
            'session_id'    => '1700000000',
            'consent'       => array( 'analytics' => true, 'marketing' => true, 'v' => 1 ),
        );
        $request = new WP_REST_Request( 'POST', '/trackwp/v1/event' );
        $request->set_header( 'Content-Type', 'application/json' );
        $request->set_body( wp_json_encode( $body ) );
        rest_get_server()->dispatch( $request );

        $ga4 = $this->ga4_bodies();
        $this->assertCount( 1, $ga4 );
        // The URL itself did reach GA4 (so the negative checks are meaningful).
        $this->assertStringContainsString( 'example.org/checkout/order-received/12/', $ga4[0] );
        $this->assertStringContainsString( 'utm_source=nyhedsbrev', $ga4[0] );
        $this->assertStringNotContainsString( 'wc_order_', $ga4[0] );
        $this->assertStringNotContainsString( 'SECRETKEY1', $ga4[0] );
        $this->assertStringNotContainsString( 'TOPSECRET2', $ga4[0] );
        $this->assertStringNotContainsString( 'kunde@example.org', $ga4[0] );
        $this->assertStringNotContainsString( 'kunde%40example.org', $ga4[0] );
    }
}
