<?php
/**
 * /keepalive never sets trackwp_consent, also when a VALID consent exists
 * (BESLUTNINGER 7.2, K4). test-proxy-fields.php checks the no-consent case;
 * the realistic regression is "renew the consent cookie for ITP while
 * renewing the tracking cookies", which only happens with consent given.
 *
 * Producer: tests/fixtures/consent-cookie.json (real consent.js in
 * Chromium, tests/fixtures/gen-consent-cookie.mjs).
 */

class TrackWP_Keepalive_Consent_Test extends WP_UnitTestCase {

    public function set_up() {
        parent::set_up();
        $_COOKIE                = array();
        $_SERVER['HTTP_ORIGIN'] = home_url();
        $_SERVER['REMOTE_ADDR'] = '10.' . wp_rand( 0, 255 ) . '.' . wp_rand( 0, 255 ) . '.' . wp_rand( 1, 254 );
        TrackWP_Cookies::reset_sent();
    }

    public function tear_down() {
        $_COOKIE = array();
        unset( $_SERVER['HTTP_ORIGIN'] );
        TrackWP_Cookies::reset_sent();
        parent::tear_down();
    }

    private function accept_all_cookie() {
        $file = dirname( __FILE__ ) . '/fixtures/consent-cookie.json';
        $this->assertFileExists( $file, 'Run node tests/fixtures/gen-consent-cookie.mjs' );
        $fx = json_decode( file_get_contents( $file ), true );
        update_option( 'trackwp_consent', array( 'consent_version' => (int) $fx['parsed']['v'] ) );
        return $fx['raw_cookie_value'];
    }

    private function sent_names() {
        $names = array();
        foreach ( TrackWP_Cookies::sent_cookies() as $cookie ) {
            if ( is_array( $cookie ) && isset( $cookie['name'] ) ) {
                $names[] = $cookie['name'];
            }
        }
        return $names;
    }

    public function test_keepalive_with_valid_consent_renews_tracking_cookies_but_not_consent() {
        update_option( 'trackwp_advanced', array( 'first_party_cookie_enabled' => true ) );
        $_COOKIE['trackwp_consent'] = $this->accept_all_cookie();
        $_COOKIE['_ga']             = 'GA1.1.111.222';
        $_COOKIE['_fbp']            = 'fb.1.1700000000000.123';

        $this->assertTrue( TrackWP_Consent::get_current_consent()['has_choice'], 'Fixture cookie must be a valid choice' );

        $request  = new WP_REST_Request( 'POST', '/trackwp/v1/keepalive' );
        $response = rest_get_server()->dispatch( $request );
        $this->assertSame( 200, $response->get_status() );

        $names = $this->sent_names();
        // Proves both consent branches ran, so the negative check below is meaningful.
        $this->assertContains( '_ga', $names, 'statistics branch renewed _ga' );
        $this->assertContains( '_fbp', $names, 'marketing branch renewed _fbp' );
        $this->assertNotContains( 'trackwp_consent', $names, 'Keepalive must never set trackwp_consent' );
    }
}
