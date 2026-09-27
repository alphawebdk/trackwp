<?php
/**
 * Purchase claim through the real /event route (PLAN-1.10.1-v4 K5, R9).
 *
 * test-woocommerce-purchase.php covers TrackWP_Order_Claims::claim() in
 * isolation; this file covers what the proxy DOES with the claim result, so
 * a regression in the /event wiring (duplicate treated as claimed, claim
 * made before the consent gate) is caught without Playwright:
 * - the same verified purchase posted twice (reload / two tabs) gives
 *   exactly one GA4 request, the second is logged duplicate/already_claimed
 * - rejected or stale consent gives no claim; a valid consent afterwards
 *   still gives exactly one purchase
 *
 * Producers: WooCommerce CRUD for orders, TrackWP_WooCommerce::order_ref_for()
 * for order_ref, TrackWP_Events templates for the purchase event.
 *
 * @group orders
 */

class TrackWP_Purchase_Claim_Flow_Test extends WP_UnitTestCase {

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

        // Real (non-temporary) tables, as in test-proxy-fields.php.
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
        $product->set_name( 'Claimvare' );
        $product->set_regular_price( '100' );
        $product->save();
        $order = wc_create_order();
        $order->add_product( $product, 2 );
        $order->set_billing_country( 'DK' );
        $order->calculate_totals();
        $order->set_status( 'processing' );
        $order->save();
        return wc_get_order( $order->get_id() );
    }

    private function post_purchase( $order, array $consent, $client_value = 99999 ) {
        $body = array(
            'event'      => 'purchase',
            'event_id'   => 'evt_' . str_repeat( 'b2', 16 ),
            'page_url'   => 'https://example.org/checkout/order-received/',
            'page_title' => 'Ordre modtaget',
            'client_id'  => '123456789.1700000000',
            'session_id' => '1700000000',
            'order_ref'  => TrackWP_WooCommerce::order_ref_for( $order->get_id() ),
            'value'      => $client_value,
            'consent'    => $consent,
        );
        $request = new WP_REST_Request( 'POST', '/trackwp/v1/event' );
        $request->set_header( 'Content-Type', 'application/json' );
        $request->set_body( wp_json_encode( $body ) );
        // Each POST is a fresh visitor request (own rate-limit bucket).
        $_SERVER['REMOTE_ADDR'] = '10.' . wp_rand( 0, 255 ) . '.' . wp_rand( 0, 255 ) . '.' . wp_rand( 1, 254 );
        return rest_get_server()->dispatch( $request );
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

    public function test_same_purchase_twice_sends_exactly_one_ga4_hit() {
        $order   = $this->make_order();
        $consent = array( 'analytics' => true, 'marketing' => false, 'v' => 1 );

        $this->post_purchase( $order, $consent );
        $this->assertCount( 1, $this->ga4_requests(), 'First verified purchase is sent' );
        $this->assertTrue( TrackWP_Order_Claims::is_claimed( $order->get_id() ) );

        // Reload / second tab: same order_ref again.
        $this->post_purchase( $order, $consent );
        $this->assertCount( 1, $this->ga4_requests(), 'Second post of the same order must not reach GA4' );
        $this->assertContains( 'duplicate/already_claimed', $this->ga4_reasons() );
    }

    public function test_sent_purchase_uses_order_data_not_client_value() {
        $order = $this->make_order();
        $this->post_purchase( $order, array( 'analytics' => true, 'marketing' => false, 'v' => 1 ), 99999 );

        $ga4 = $this->ga4_requests();
        $this->assertCount( 1, $ga4 );
        $this->assertStringNotContainsString( '99999', $ga4[0]['body'] );
        $params = json_decode( $ga4[0]['body'], true )['events'][0]['params'];
        $this->assertSame( (string) $order->get_order_number(), (string) $params['transaction_id'] );
    }

    public function test_rejected_consent_does_not_claim_then_valid_consent_sends_once() {
        $order = $this->make_order();

        $this->post_purchase( $order, array( 'analytics' => false, 'marketing' => false, 'v' => 1 ) );
        $this->assertCount( 0, $this->ga4_requests() );
        $this->assertFalse( TrackWP_Order_Claims::is_claimed( $order->get_id() ), 'No claim without consent (R9)' );

        $this->post_purchase( $order, array( 'analytics' => true, 'marketing' => false, 'v' => 1 ) );
        $this->assertCount( 1, $this->ga4_requests() );
        $this->assertTrue( TrackWP_Order_Claims::is_claimed( $order->get_id() ) );
    }

    public function test_stale_consent_does_not_claim() {
        $order    = $this->make_order();
        $response = $this->post_purchase( $order, array( 'analytics' => true, 'marketing' => true, 'v' => 2 ) );

        $this->assertSame( 'stale_version', $response->get_data()['consent'] );
        $this->assertCount( 0, $this->ga4_requests() );
        $this->assertFalse( TrackWP_Order_Claims::is_claimed( $order->get_id() ) );
    }
}
