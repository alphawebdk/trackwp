<?php
/**
 * TrackWP_DataLayer (W4): active_for_request() (KC7), client_config() and
 * ga4_route() (D4, KC8, TR4). The routing vectors are the SAME file used by
 * tests/js/datalayer.test.mjs (tests/fixtures/datalayer/ga4-route-vectors.json).
 */

class TrackWP_DataLayer_Test extends WP_UnitTestCase {

    public function tear_down() {
        wp_set_current_user( 0 );
        parent::tear_down();
    }

    // ------------------------------------------------------------------
    // active_for_request() / KC7
    // ------------------------------------------------------------------

    public function test_active_for_request_off_by_default() {
        delete_option( 'trackwp_platforms' );
        $this->assertFalse( TrackWP_DataLayer::active_for_request() );
    }

    public function test_active_for_request_on_is_active_for_everyone() {
        update_option( 'trackwp_platforms', array( 'gtm_datalayer_events' => 'on' ) );
        wp_set_current_user( 0 );
        $this->assertTrue( TrackWP_DataLayer::active_for_request() );
    }

    public function test_active_for_request_test_mode_only_for_admin() {
        update_option( 'trackwp_platforms', array( 'gtm_datalayer_events' => 'test' ) );

        wp_set_current_user( 0 );
        $this->assertFalse( TrackWP_DataLayer::active_for_request(), 'anonymous visitor must not be instrumented in test mode' );

        $subscriber = self::factory()->user->create( array( 'role' => 'subscriber' ) );
        wp_set_current_user( $subscriber );
        $this->assertFalse( TrackWP_DataLayer::active_for_request() );

        $admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
        wp_set_current_user( $admin );
        $this->assertTrue( TrackWP_DataLayer::active_for_request() );
    }

    public function test_active_for_request_unknown_mode_is_off() {
        update_option( 'trackwp_platforms', array( 'gtm_datalayer_events' => 'bogus' ) );
        $this->assertFalse( TrackWP_DataLayer::active_for_request() );
    }

    // ------------------------------------------------------------------
    // client_config()
    // ------------------------------------------------------------------

    public function test_client_config_shape_and_types() {
        update_option( 'trackwp_platforms', array(
            'gtm_datalayer_events' => 'on',
            'ga4_source'           => 'split',
            'ga4_enabled'          => true,
            'ga4_measurement_id'   => 'G-TEST123',
            'ga4_api_secret'       => 'secret',
        ) );

        $config = TrackWP_DataLayer::client_config();

        $this->assertSame( array( 'enabled', 'ga4Source', 'ga4Enabled', 'mpConfigured' ), array_keys( $config ) );
        foreach ( array( 'enabled', 'ga4Enabled', 'mpConfigured' ) as $key ) {
            $this->assertIsBool( $config[ $key ], $key );
        }
        $this->assertIsString( $config['ga4Source'] );
        $this->assertTrue( $config['enabled'] );
        $this->assertSame( 'split', $config['ga4Source'] );
        $this->assertTrue( $config['ga4Enabled'] );
        $this->assertTrue( $config['mpConfigured'] );
    }

    public function test_client_config_defaults_source_to_gtm_and_off_without_toggle() {
        delete_option( 'trackwp_platforms' );
        $config = TrackWP_DataLayer::client_config();
        $this->assertSame( 'gtm', $config['ga4Source'] );
        $this->assertFalse( $config['enabled'] );
        $this->assertFalse( $config['ga4Enabled'] );
    }

    public function test_client_config_ga4_enabled_requires_measurement_id_even_via_gtm() {
        update_option( 'trackwp_platforms', array(
            'ga4_enabled'        => true,
            'ga4_measurement_id' => '',
        ) );
        $this->assertFalse( TrackWP_DataLayer::client_config()['ga4Enabled'] );
    }

    // ------------------------------------------------------------------
    // ga4_route() vectors, shared with the JS test (TR4/KC8)
    // ------------------------------------------------------------------

    private function vectors() {
        $file = dirname( __FILE__ ) . '/fixtures/datalayer/ga4-route-vectors.json';
        $this->assertFileExists( $file );
        $data = json_decode( file_get_contents( $file ), true );
        $this->assertIsArray( $data );
        $this->assertNotEmpty( $data['cases'] );
        return $data['cases'];
    }

    public function test_ga4_route_matches_the_shared_vectors() {
        foreach ( $this->vectors() as $case ) {
            $this->assertSame(
                $case['expected'],
                TrackWP_DataLayer::ga4_route( $case['input'] ),
                $case['name']
            );
        }
    }

    public function test_ga4_route_never_upgrades_gtm_to_server() {
        $route = TrackWP_DataLayer::ga4_route( array(
            'datalayer_active' => true,
            'ga4_enabled'      => true,
            'mp_configured'    => true,
            'send_to_ga4'      => true,
            'source'           => 'gtm',
            'event'            => 'purchase',
            'statistics'       => true,
            'dedup_mode'       => 'client_and_server',
        ) );
        $this->assertSame( 'gtm', $route );
    }
}
