<?php
/**
 * GA4 refunds (TrackWP_Purchase_Backup::run_refund).
 *
 * Producers: wc_create_refund() makes the refunds and fires the real
 * woocommerce_order_refunded hook; TrackWP_WooCommerce::save_attribution()
 * writes the snapshot from a real consent cookie
 * (TrackWP_Consent::write_consent_cookie); TrackWP_Purchase_Backup::run_purchase()
 * produces the purchase (and its claim) the refund is matched against.
 *
 * @group orders
 */

class TrackWP_GA4_Refund_Test extends WP_UnitTestCase {

    const CONSENT_ID = '0f8e2c1a-3b4d-4e5f-8a9b-0c1d2e3f4a5b';

    /** @var array Captured outgoing HTTP requests: list of {url, body}. */
    private $http = array();

    public function set_up() {
        parent::set_up();
        if ( ! class_exists( 'WooCommerce' ) ) {
            $this->markTestSkipped( 'WooCommerce is not installed in this environment.' );
        }
        if ( ! function_exists( 'as_get_scheduled_actions' ) ) {
            $this->markTestSkipped( 'Action Scheduler is not available.' );
        }

        $this->http = array();
        add_filter( 'pre_http_request', array( $this, 'capture_http' ), 10, 3 );

        update_option( 'trackwp_platforms', array(
            'ga4_enabled'        => true,
            'ga4_measurement_id' => 'G-TEST12345',
            'ga4_api_secret'     => 'test-secret',
            'meta_enabled'       => 1,
            'meta_pixel_id'      => '1234567890',
            'meta_access_token'  => TrackWP_Hash::encode( 'token' ),
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
        TrackWP_Consent_Log::create_tables();
        add_filter( 'query', array( $this, '_create_temporary_tables' ) );
        add_filter( 'query', array( $this, '_drop_temporary_tables' ) );
        TrackWP_Delivery_Log::clear();
        TrackWP_Order_Claims::create_table();
        TrackWP_WooCommerce::reset_request_state();

        $_SERVER['REMOTE_ADDR'] = '192.0.2.55';
        $_COOKIE                = array();

        TrackWP_Purchase_Backup::register_hooks();
    }

    public function tear_down() {
        remove_action( 'woocommerce_payment_complete', array( 'TrackWP_Purchase_Backup', 'on_payment_complete' ), 10 );
        remove_action( 'woocommerce_order_status_changed', array( 'TrackWP_Purchase_Backup', 'on_status_changed' ), 10 );
        remove_action( 'woocommerce_order_refunded', array( 'TrackWP_Purchase_Backup', 'on_refunded' ), 10 );
        remove_filter( 'pre_http_request', array( $this, 'capture_http' ), 10 );
        TrackWP_WooCommerce::reset_request_state();
        unset( $_SERVER['HTTP_USER_AGENT'] );
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
            'body'     => '{"events_received":1}',
            'response' => array( 'code' => 200, 'message' => 'mock' ),
            'cookies'  => array(),
            'filename' => null,
        );
    }

    private function ga4_requests() {
        $out = array();
        foreach ( $this->http as $r ) {
            if ( false !== strpos( $r['url'], 'google-analytics.com/mp/collect' ) ) {
                $out[] = json_decode( $r['body'], true );
            }
        }
        return $out;
    }

    private function refund_requests() {
        return array_values( array_filter( $this->ga4_requests(), function ( $b ) {
            return 'refund' === $b['events'][0]['name'];
        } ) );
    }

    private function refund_reasons() {
        $out = array();
        foreach ( TrackWP_Delivery_Log::get_recent( 100 ) as $row ) {
            if ( 'refund' === $row['event_name'] && 'ga4' === $row['destination'] ) {
                $out[] = $row['status'] . '/' . $row['reason'];
            }
        }
        return $out;
    }

    private function refund_received_reasons() {
        $out = array();
        foreach ( TrackWP_Delivery_Log::get_recent( 100 ) as $row ) {
            if ( 'refund' === $row['event_name'] && 'received' === $row['destination'] ) {
                $out[] = $row['status'] . '/' . $row['reason'];
            }
        }
        return $out;
    }

    /**
     * Paid order, 3 x 100, snapshot from the real producer.
     *
     * @param array $o client_id (bool), snapshot (bool), purchase (bool: run the backup purchase).
     * @return WC_Order
     */
    private function make_order( array $o = array() ) {
        $o += array( 'client_id' => true, 'snapshot' => true, 'purchase' => true );
        $product = new WC_Product_Simple();
        $product->set_name( 'Refundvare' );
        $product->set_regular_price( '100' );
        $product->save();

        $order = wc_create_order( array( 'created_via' => 'checkout' ) );
        $order->add_product( $product, 3 );
        $order->set_billing_country( 'DK' );
        $order->set_customer_ip_address( '198.51.100.77' );
        $order->calculate_totals();
        $order->save();

        if ( $o['snapshot'] ) {
            TrackWP_Cookies::reset_sent();
            TrackWP_Consent::write_consent_cookie( 1, 1788256800000, self::CONSENT_ID, true, true, false );
            foreach ( TrackWP_Cookies::sent_cookies() as $c ) {
                if ( 'trackwp_consent' === $c['name'] ) {
                    $_COOKIE['trackwp_consent'] = $c['value'];
                }
            }
            TrackWP_Cookies::reset_sent();
            $_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0 (Refund Test)';
            if ( $o['client_id'] ) {
                $_COOKIE['_ga'] = 'GA1.1.111.222';
            }
            ( new TrackWP_WooCommerce( false ) )->save_attribution( $order->get_id() );
            $_COOKIE = array();
            unset( $_SERVER['HTTP_USER_AGENT'] );
        }

        $order->set_status( 'processing' );
        $order->save();
        $order = wc_get_order( $order->get_id() );
        if ( $o['purchase'] ) {
            TrackWP_Purchase_Backup::run_purchase( $order->get_id() );
        }
        return $order;
    }

    private function line_id( $order ) {
        foreach ( $order->get_items() as $item_id => $item ) {
            return $item_id;
        }
        return 0;
    }

    private function refund_units( $order, $qty ) {
        $refund = wc_create_refund( array(
            'amount'     => 100 * $qty,
            'reason'     => 'test',
            'order_id'   => $order->get_id(),
            'line_items' => array(
                $this->line_id( $order ) => array(
                    'qty'          => $qty,
                    'refund_total' => 100 * $qty,
                    'refund_tax'   => array(),
                ),
            ),
        ) );
        $this->assertInstanceOf( 'WC_Order_Refund', $refund );
        return $refund;
    }

    public function test_partial_line_refund_sends_original_transaction_id_with_positive_amounts() {
        $order = $this->make_order();
        $purchase_item_id = $this->ga4_requests()[0]['events'][0]['params']['items'][0]['item_id'];
        $this->http = array();

        $refund = $this->refund_units( $order, 1 );
        TrackWP_Purchase_Backup::run_refund( $order->get_id(), $refund->get_id() );

        $sent = $this->refund_requests();
        $this->assertCount( 1, $sent );
        $params = $sent[0]['events'][0]['params'];
        $this->assertSame( (string) $order->get_order_number(), $params['transaction_id'] );
        $this->assertSame( TrackWP_WooCommerce::refund_event_id_for( $refund->get_id() ), $params['event_id'] );
        $this->assertEquals( 100, $params['value'] );
        $this->assertSame( $purchase_item_id, $params['items'][0]['item_id'] );
        $this->assertSame( 1, $params['items'][0]['quantity'] );
        $this->assertEquals( 100, $params['items'][0]['price'] );
        $this->assertContains( 'ok/sent', $this->refund_reasons() );
        $this->assertContains( 'ok/backup', $this->refund_received_reasons() );

        foreach ( $this->http as $r ) {
            $this->assertStringNotContainsString( 'graph.facebook.com', $r['url'], 'Meta must never get a refund.' );
            $this->assertStringNotContainsString( 'googleads.googleapis.com', $r['url'], 'Google Ads must never get a refund.' );
        }
    }

    public function test_two_partial_refunds_send_two_events_with_their_own_amounts() {
        $order = $this->make_order();
        $this->http = array();

        $first  = $this->refund_units( $order, 1 );
        $second = $this->refund_units( wc_get_order( $order->get_id() ), 2 );
        TrackWP_Purchase_Backup::run_refund( $order->get_id(), $first->get_id() );
        TrackWP_Purchase_Backup::run_refund( $order->get_id(), $second->get_id() );

        $sent = $this->refund_requests();
        $this->assertCount( 2, $sent );
        $this->assertEquals( 100, $sent[0]['events'][0]['params']['value'] );
        $this->assertEquals( 200, $sent[1]['events'][0]['params']['value'] );
        $this->assertNotSame( $sent[0]['events'][0]['params']['event_id'], $sent[1]['events'][0]['params']['event_id'] );
    }

    public function test_the_same_refund_twice_sends_one_request() {
        $order  = $this->make_order();
        $refund = $this->refund_units( $order, 1 );
        $this->http = array();

        TrackWP_Purchase_Backup::run_refund( $order->get_id(), $refund->get_id() );
        TrackWP_Purchase_Backup::run_refund( $order->get_id(), $refund->get_id() );

        $this->assertCount( 1, $this->refund_requests() );
        $this->assertContains( 'duplicate/already_claimed', $this->refund_reasons() );
    }

    public function test_amount_only_refund_has_no_items_and_a_proportional_value() {
        $order  = $this->make_order();
        $refund = wc_create_refund( array(
            'amount'   => 60,
            'reason'   => 'goodwill',
            'order_id' => $order->get_id(),
        ) );
        $this->assertInstanceOf( 'WC_Order_Refund', $refund );
        $this->http = array();

        TrackWP_Purchase_Backup::run_refund( $order->get_id(), $refund->get_id() );

        $sent = $this->refund_requests();
        $this->assertCount( 1, $sent );
        $params = $sent[0]['events'][0]['params'];
        $this->assertArrayNotHasKey( 'items', $params );
        // Order total 300, product lines net 300: the amount carries over 1:1.
        $this->assertEquals( 60, $params['value'] );
        $this->assertSame( (string) $order->get_order_number(), $params['transaction_id'] );
    }

    public function test_refund_without_a_purchase_claim_is_skipped() {
        $order  = $this->make_order( array( 'purchase' => false ) );
        $refund = $this->refund_units( $order, 1 );
        $this->http = array();

        TrackWP_Purchase_Backup::run_refund( $order->get_id(), $refund->get_id() );

        $this->assertSame( array(), $this->http );
        $this->assertContains( 'skipped/no_purchase', $this->refund_received_reasons() );
    }

    public function test_refund_is_skipped_when_purchase_event_does_not_route_to_ga4() {
        $order = $this->make_order( array( 'purchase' => false ) );
        TrackWP_Order_Claims::claim( $order->get_id(), 'purchase', 'browser' );
        $events = get_option( 'trackwp_events' );
        foreach ( $events as $i => $e ) {
            if ( isset( $e['name'] ) && 'purchase' === $e['name'] ) {
                $events[ $i ]['send_to']        = (array) ( isset( $e['send_to'] ) ? $e['send_to'] : array() );
                $events[ $i ]['send_to']['ga4'] = false;
            }
        }
        update_option( 'trackwp_events', $events );
        $refund = $this->refund_units( $order, 1 );
        $this->http = array();

        TrackWP_Purchase_Backup::run_refund( $order->get_id(), $refund->get_id() );

        $this->assertSame( array(), $this->ga4_requests() );
        $this->assertContains( 'skipped/no_purchase', $this->refund_received_reasons() );
    }

    public function test_browser_seen_marker_alone_is_enough_for_a_refund() {
        $order = $this->make_order( array( 'purchase' => false ) );
        TrackWP_Order_Claims::claim( $order->get_id(), 'browser_seen', 'browser' );
        $refund = $this->refund_units( $order, 1 );
        $this->http = array();

        TrackWP_Purchase_Backup::run_refund( $order->get_id(), $refund->get_id() );

        $this->assertCount( 1, $this->refund_requests() );
    }

    public function test_refund_without_snapshot_is_skipped() {
        $order = $this->make_order( array( 'snapshot' => false, 'purchase' => false ) );
        TrackWP_Order_Claims::claim( $order->get_id(), 'purchase', 'browser' );
        $refund = $this->refund_units( $order, 1 );
        $this->http = array();

        TrackWP_Purchase_Backup::run_refund( $order->get_id(), $refund->get_id() );

        $this->assertSame( array(), $this->http );
        $this->assertContains( 'skipped/no_snapshot', $this->refund_received_reasons() );
    }

    public function test_refund_without_a_real_client_id_is_skipped() {
        $order = $this->make_order( array( 'client_id' => false, 'purchase' => false ) );
        TrackWP_Order_Claims::claim( $order->get_id(), 'purchase', 'browser' );
        $refund = $this->refund_units( $order, 1 );
        $this->http = array();

        TrackWP_Purchase_Backup::run_refund( $order->get_id(), $refund->get_id() );

        $this->assertSame( array(), $this->http );
        $this->assertContains( 'skipped/no_client_id', $this->refund_received_reasons() );
    }

    public function test_wc_create_refund_schedules_the_refund_job_with_order_and_refund_id() {
        $order  = $this->make_order();
        $refund = $this->refund_units( $order, 1 );

        $jobs = as_get_scheduled_actions(
            array(
                'hook'     => 'trackwp_ga4_refund',
                'args'     => array( $order->get_id(), $refund->get_id() ),
                'status'   => ActionScheduler_Store::STATUS_PENDING,
                'per_page' => -1,
            ),
            'ids'
        );
        $this->assertCount( 1, $jobs );
    }
}
