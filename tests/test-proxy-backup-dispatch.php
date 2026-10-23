<?php
/**
 * Server-side backup purchase through TrackWP_Proxy::dispatch_backup() and the
 * browser_seen marker written by the real /event route.
 *
 * Producers: WooCommerce CRUD for orders, TrackWP_WooCommerce::order_ref_for()
 * for order_ref, TrackWP_Events templates for the purchase event, the server's
 * own consent-cookie writer (TrackWP_Consent::write_consent_cookie) and the
 * real REST route (rest_get_server()->dispatch) for the browser side.
 *
 * @group orders
 */

class TrackWP_Proxy_Backup_Dispatch_Test extends WP_UnitTestCase {

    /** @var array Captured outgoing HTTP requests: list of {url, body}. */
    private $http = array();

    public function set_up() {
        parent::set_up();
        if ( ! class_exists( 'WooCommerce' ) ) {
            $this->markTestSkipped( 'WooCommerce is not installed in this environment.' );
        }

        $this->http = array();
        add_filter( 'pre_http_request', array( $this, 'capture_http' ), 10, 3 );

        update_option( 'trackwp_platforms', array(
            'ga4_enabled'        => true,
            'ga4_measurement_id' => 'G-TEST12345',
            'ga4_api_secret'     => 'test-secret',
        ) );
        update_option( 'trackwp_advanced', array(
            'dedup_mode'           => 'client_and_server',
            'delivery_log_enabled' => true,
            'batching_enabled'     => false,
        ) );
        update_option( 'trackwp_consent', array( 'consent_version' => 1 ) );
        update_option(
            'trackwp_woocommerce',
            array_merge( TrackWP_WooCommerce::get_defaults(), array( 'enabled' => true ) )
        );
        $events   = TrackWP_Events::get_defaults();
        $events[] = TrackWP_Events::get_woocommerce_event_templates()['purchase'];
        update_option( 'trackwp_events', $events );

        remove_filter( 'query', array( $this, '_create_temporary_tables' ) );
        remove_filter( 'query', array( $this, '_drop_temporary_tables' ) );
        TrackWP_Delivery_Log::create_table();
        add_filter( 'query', array( $this, '_create_temporary_tables' ) );
        add_filter( 'query', array( $this, '_drop_temporary_tables' ) );
        TrackWP_Delivery_Log::clear();
        TrackWP_Order_Claims::create_table();
        TrackWP_WooCommerce::reset_request_state();

        $_SERVER['HTTP_ORIGIN'] = home_url();
        $_SERVER['REMOTE_ADDR'] = '10.' . wp_rand( 0, 255 ) . '.' . wp_rand( 0, 255 ) . '.' . wp_rand( 1, 254 );
        $_COOKIE                = array();
    }

    public function tear_down() {
        remove_filter( 'pre_http_request', array( $this, 'capture_http' ), 10 );
        remove_all_filters( 'trackwp_event_data' );
        TrackWP_WooCommerce::reset_request_state();
        unset( $_SERVER['HTTP_ORIGIN'] );
        $_COOKIE = array();
        parent::tear_down();
    }

    public function capture_http( $pre, $args, $url ) {
        $this->http[] = array(
            'url'  => $url,
            'body' => isset( $args['body'] ) ? (string) $args['body'] : '',
        );
        return array(
            'headers'  => array(),
            'body'     => '',
            'response' => array( 'code' => 204, 'message' => 'mock' ),
            'cookies'  => array(),
            'filename' => null,
        );
    }

    private function ga4_requests() {
        return array_values( array_filter( $this->http, function( $r ) {
            return false !== strpos( $r['url'], 'google-analytics.com/mp/collect' );
        } ) );
    }

    private function make_order() {
        $product = new WC_Product_Simple();
        $product->set_name( 'Backupvare' );
        $product->set_regular_price( '100' );
        $product->save();
        $order = wc_create_order();
        $order->add_product( $product, 1 );
        $order->set_billing_country( 'DK' );
        $order->calculate_totals();
        $order->set_status( 'processing' );
        $order->save();
        return wc_get_order( $order->get_id() );
    }

    private function backup_event_data( $order ) {
        return array(
            'event'      => 'purchase',
            'event_id'   => TrackWP_WooCommerce::event_id_for( $order->get_id() ),
            'client_id'  => '123456789.1700000000',
            'value'      => 100,
            'currency'   => 'DKK',
            'page_url'   => 'https://example.org/checkout/',
            'consent'    => array( 'analytics' => true, 'marketing' => false ),
            '_server_ctx' => array(
                'origin'       => 'backup',
                'event_time'   => time() - 600,
                'consent_id'   => '',
                'user_id_hash' => '',
            ),
        );
    }

    private function effective( $analytics = true, $marketing = false ) {
        return array( 'analytics' => $analytics, 'marketing' => $marketing );
    }

    private function ga4_reasons() {
        $out = array();
        foreach ( TrackWP_Delivery_Log::get_recent( 100 ) as $row ) {
            if ( 'ga4' === $row['destination'] ) {
                $out[] = $row['status'] . '/' . $row['reason'];
            }
        }
        return $out;
    }

    private function received_reasons() {
        $out = array();
        foreach ( TrackWP_Delivery_Log::get_recent( 100 ) as $row ) {
            if ( 'received' === $row['destination'] ) {
                $out[] = $row['status'] . '/' . $row['reason'];
            }
        }
        return $out;
    }

    private function set_consent_cookie( $statistics, $marketing ) {
        TrackWP_Cookies::reset_sent();
        TrackWP_Consent::write_consent_cookie( 1, 1788256800000, '0f8e2c1a-3b4d-4e5f-8a9b-0c1d2e3f4a5b', $statistics, $marketing, false );
        foreach ( TrackWP_Cookies::sent_cookies() as $c ) {
            if ( 'trackwp_consent' === $c['name'] ) {
                $_COOKIE['trackwp_consent'] = $c['value'];
            }
        }
        TrackWP_Cookies::reset_sent();
        $this->assertNotEmpty( $_COOKIE['trackwp_consent'] );
    }

    private function post_event( array $body, $order = null ) {
        $body += array(
            'event'      => 'purchase',
            'event_id'   => 'evt_' . str_repeat( 'b2', 16 ),
            'page_url'   => 'https://example.org/checkout/order-received/',
            'page_title' => 'Ordre modtaget',
            'client_id'  => '123456789.1700000000',
            'session_id' => '1700000000',
        );
        if ( $order ) {
            $body['order_ref'] = TrackWP_WooCommerce::order_ref_for( $order->get_id() );
        }
        $request = new WP_REST_Request( 'POST', '/trackwp/v1/event' );
        $request->set_header( 'Content-Type', 'application/json' );
        $request->set_body( wp_json_encode( $body ) );
        $_SERVER['REMOTE_ADDR'] = '10.' . wp_rand( 0, 255 ) . '.' . wp_rand( 0, 255 ) . '.' . wp_rand( 1, 254 );
        return rest_get_server()->dispatch( $request );
    }

    public function test_backup_claims_purchase_with_source_backup_and_sends_once() {
        $order   = $this->make_order();
        $results = ( new TrackWP_Proxy() )->dispatch_backup( $this->backup_event_data( $order ), $this->effective(), $order->get_id() );

        $this->assertSame( 'ok', $results['ga4']['status'] );
        $this->assertCount( 1, $this->ga4_requests() );
        $this->assertTrue( TrackWP_Order_Claims::is_claimed( $order->get_id(), 'purchase' ) );
        global $wpdb;
        $source = $wpdb->get_var( $wpdb->prepare(
            'SELECT source FROM ' . TrackWP_Order_Claims::table_name() . ' WHERE order_id = %d AND scope = %s',
            $order->get_id(),
            'purchase'
        ) );
        $this->assertSame( 'backup', $source );
        $this->assertContains( 'ok/backup', $this->received_reasons() );
        $this->assertContains( 'ok/sent', $this->ga4_reasons() );
    }

    public function test_second_backup_is_duplicate_and_sends_nothing() {
        $order = $this->make_order();
        $proxy = new TrackWP_Proxy();
        $proxy->dispatch_backup( $this->backup_event_data( $order ), $this->effective(), $order->get_id() );
        $second = $proxy->dispatch_backup( $this->backup_event_data( $order ), $this->effective(), $order->get_id() );

        $this->assertCount( 1, $this->ga4_requests() );
        $this->assertSame( 'duplicate', $second['ga4']['status'] );
        $this->assertSame( 'already_claimed', $second['ga4']['reason'] );
        $this->assertContains( 'duplicate/already_claimed', $this->ga4_reasons() );
    }

    public function test_pre_skip_no_client_id_is_honoured_and_does_not_claim() {
        $order   = $this->make_order();
        $results = ( new TrackWP_Proxy() )->dispatch_backup(
            $this->backup_event_data( $order ),
            $this->effective(),
            $order->get_id(),
            array( 'ga4' => 'no_client_id' )
        );

        $this->assertSame( 'skipped', $results['ga4']['status'] );
        $this->assertSame( 'no_client_id', $results['ga4']['reason'] );
        $this->assertCount( 0, $this->ga4_requests() );
        $this->assertFalse( TrackWP_Order_Claims::is_claimed( $order->get_id(), 'purchase' ), 'Nothing tried, so nothing claimed' );
        $this->assertContains( 'skipped/no_client_id', $this->ga4_reasons() );
    }

    public function test_client_only_dedup_mode_sends_nothing() {
        update_option( 'trackwp_advanced', array(
            'dedup_mode'           => 'client_only',
            'delivery_log_enabled' => true,
            'batching_enabled'     => false,
        ) );
        $order   = $this->make_order();
        $results = ( new TrackWP_Proxy() )->dispatch_backup( $this->backup_event_data( $order ), $this->effective(), $order->get_id() );

        $this->assertSame( 'skipped', $results['ga4']['status'] );
        $this->assertSame( 'client_only', $results['ga4']['reason'] );
        $this->assertCount( 0, $this->ga4_requests() );
        $this->assertFalse( TrackWP_Order_Claims::is_claimed( $order->get_id(), 'purchase' ) );
    }

    public function test_no_analytics_consent_sends_nothing() {
        $order   = $this->make_order();
        $results = ( new TrackWP_Proxy() )->dispatch_backup( $this->backup_event_data( $order ), $this->effective( false, false ), $order->get_id() );

        $this->assertSame( 'no_consent', $results['ga4']['reason'] );
        $this->assertCount( 0, $this->ga4_requests() );
    }

    public function test_browser_purchase_with_consent_sets_browser_seen_and_purchase_claim() {
        $order = $this->make_order();
        $this->set_consent_cookie( true, false );
        $this->post_event( array( 'consent' => array( 'analytics' => true, 'marketing' => false, 'v' => 1 ) ), $order );

        $this->assertTrue( TrackWP_Order_Claims::is_claimed( $order->get_id(), 'browser_seen' ) );
        $this->assertTrue( TrackWP_Order_Claims::is_claimed( $order->get_id(), 'purchase' ) );
    }

    public function test_browser_purchase_without_consent_sets_browser_seen_but_no_purchase_claim() {
        $order = $this->make_order();
        $this->set_consent_cookie( false, false );
        $this->post_event( array( 'consent' => array( 'analytics' => false, 'marketing' => false, 'v' => 1 ) ), $order );

        $this->assertTrue( TrackWP_Order_Claims::is_claimed( $order->get_id(), 'browser_seen' ) );
        $this->assertFalse( TrackWP_Order_Claims::is_claimed( $order->get_id(), 'purchase' ), 'The refusal does not claim purchase' );

        // Consent afterwards still gives exactly one purchase.
        $this->set_consent_cookie( true, false );
        $this->post_event( array( 'consent' => array( 'analytics' => true, 'marketing' => false, 'v' => 1 ) ), $order );
        $this->assertCount( 1, $this->ga4_requests() );
        $this->assertTrue( TrackWP_Order_Claims::is_claimed( $order->get_id(), 'purchase' ) );
    }

    public function test_unverified_purchase_sets_no_browser_seen() {
        $order = $this->make_order();
        $this->set_consent_cookie( true, false );
        $this->post_event( array( 'consent' => array( 'analytics' => true, 'marketing' => false, 'v' => 1 ) ) );

        $this->assertFalse( TrackWP_Order_Claims::is_claimed( $order->get_id(), 'browser_seen' ) );
    }

    public function test_backup_after_browser_is_duplicate() {
        $order = $this->make_order();
        $this->set_consent_cookie( true, false );
        $this->post_event( array( 'consent' => array( 'analytics' => true, 'marketing' => false, 'v' => 1 ) ), $order );
        $this->assertCount( 1, $this->ga4_requests() );

        $results = ( new TrackWP_Proxy() )->dispatch_backup( $this->backup_event_data( $order ), $this->effective(), $order->get_id() );
        $this->assertCount( 1, $this->ga4_requests(), 'Backup adds no second server send' );
        $this->assertSame( 'duplicate', $results['ga4']['status'] );
    }

    public function test_rest_body_cannot_inject_server_ctx() {
        $order = $this->make_order();
        $this->set_consent_cookie( true, false );
        $this->post_event( array(
            'consent'     => array( 'analytics' => true, 'marketing' => false, 'v' => 1 ),
            '_server_ctx' => array( 'origin' => 'backup', 'event_time' => 1000000000 ),
        ), $order );

        $ga4 = $this->ga4_requests();
        $this->assertCount( 1, $ga4 );
        $this->assertStringNotContainsString( '1000000000000000', $ga4[0]['body'] );
        $this->assertArrayNotHasKey( 'timestamp_micros', json_decode( $ga4[0]['body'], true ) );
    }

    public function test_filter_cannot_inject_server_ctx() {
        add_filter( 'trackwp_event_data', function( $data ) {
            $data['_server_ctx'] = array( 'origin' => 'backup', 'event_time' => 1000000000 );
            return $data;
        } );
        $order = $this->make_order();
        $this->set_consent_cookie( true, false );
        $this->post_event( array( 'consent' => array( 'analytics' => true, 'marketing' => false, 'v' => 1 ) ), $order );

        $ga4 = $this->ga4_requests();
        $this->assertCount( 1, $ga4 );
        $this->assertStringNotContainsString( '1000000000000000', $ga4[0]['body'] );
        $this->assertArrayNotHasKey( 'timestamp_micros', json_decode( $ga4[0]['body'], true ) );
    }
}
