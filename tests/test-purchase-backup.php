<?php
/**
 * Server-side backup purchase (TrackWP_Purchase_Backup).
 *
 * Producers: TrackWP_WooCommerce::save_attribution() writes the checkout
 * snapshot from a real consent cookie (TrackWP_Consent::write_consent_cookie)
 * and the _ga/_fbp/_twp_click cookies; WooCommerce CRUD makes the orders and
 * fires the real status hooks; the real REST route plays the browser side;
 * Action Scheduler (as_get_scheduled_actions) shows what was scheduled.
 *
 * @group orders
 */

class TrackWP_Purchase_Backup_Test extends WP_UnitTestCase {

    const CONSENT_ID = '0f8e2c1a-3b4d-4e5f-8a9b-0c1d2e3f4a5b';
    const UA         = 'Mozilla/5.0 (Backup Test)';
    const ORDER_IP   = '198.51.100.77';
    const GATEWAY_IP = '192.0.2.55';

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
            'ga4_user_id_enabled'  => true,
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

        $_SERVER['HTTP_ORIGIN'] = home_url();
        $_SERVER['REMOTE_ADDR'] = self::GATEWAY_IP;
        $_COOKIE                = array();

        TrackWP_Purchase_Backup::register_hooks();
    }

    public function tear_down() {
        remove_action( 'woocommerce_payment_complete', array( 'TrackWP_Purchase_Backup', 'on_payment_complete' ), 10 );
        remove_action( 'woocommerce_order_status_changed', array( 'TrackWP_Purchase_Backup', 'on_status_changed' ), 10 );
        remove_action( 'woocommerce_order_refunded', array( 'TrackWP_Purchase_Backup', 'on_refunded' ), 10 );
        remove_filter( 'pre_http_request', array( $this, 'capture_http' ), 10 );
        remove_all_filters( 'trackwp_backup_purchase_statuses' );
        remove_all_filters( 'trackwp_event_data' );
        TrackWP_WooCommerce::reset_request_state();
        wp_set_current_user( 0 );
        unset( $_SERVER['HTTP_ORIGIN'], $_SERVER['HTTP_USER_AGENT'] );
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

    private function requests_to( $needle ) {
        $out = array();
        foreach ( $this->http as $r ) {
            if ( false !== strpos( $r['url'], $needle ) ) {
                $out[] = array( 'url' => $r['url'], 'body' => json_decode( $r['body'], true ) );
            }
        }
        return $out;
    }

    private function ga4_requests() {
        return $this->requests_to( 'google-analytics.com/mp/collect' );
    }

    private function meta_requests() {
        return $this->requests_to( 'graph.facebook.com' );
    }

    private function reasons( $destination ) {
        $out = array();
        foreach ( TrackWP_Delivery_Log::get_recent( 100 ) as $row ) {
            if ( $destination === $row['destination'] ) {
                $out[] = $row['status'] . '/' . $row['reason'];
            }
        }
        return $out;
    }

    private function set_consent_cookie( $statistics, $marketing ) {
        TrackWP_Cookies::reset_sent();
        TrackWP_Consent::write_consent_cookie( 1, 1788256800000, self::CONSENT_ID, $statistics, $marketing, false );
        foreach ( TrackWP_Cookies::sent_cookies() as $c ) {
            if ( 'trackwp_consent' === $c['name'] ) {
                $_COOKIE['trackwp_consent'] = $c['value'];
            }
        }
        TrackWP_Cookies::reset_sent();
        $this->assertNotEmpty( $_COOKIE['trackwp_consent'] );
    }

    /**
     * A pending order with the snapshot written by the real producer.
     *
     * @param array $o statistics, marketing, snapshot (bool), client_id (bool), created_via.
     * @return WC_Order
     */
    private function make_order( array $o = array() ) {
        $o += array(
            'statistics'  => true,
            'marketing'   => true,
            'snapshot'    => true,
            'client_id'   => true,
            'created_via' => 'checkout',
        );
        $product = new WC_Product_Simple();
        $product->set_name( 'Backupvare' );
        $product->set_regular_price( '100' );
        $product->save();

        $order = wc_create_order( array( 'created_via' => $o['created_via'] ) );
        $order->add_product( $product, 1 );
        $order->set_billing_country( 'DK' );
        $order->set_billing_email( 'kunde@example.org' );
        $order->set_customer_ip_address( self::ORDER_IP );
        $order->calculate_totals();
        $order->save();

        if ( $o['snapshot'] ) {
            $this->set_consent_cookie( $o['statistics'], $o['marketing'] );
            $_SERVER['HTTP_USER_AGENT'] = self::UA;
            if ( $o['client_id'] ) {
                $_COOKIE['_ga'] = 'GA1.1.111.222';
            }
            $_COOKIE['_fbp']       = 'fb.1.1700000000000.123';
            $_COOKIE['_twp_click'] = wp_json_encode( array( 'gclid' => array( 'v' => 'abc_DEF-1', 'ts' => 1 ) ) );
            ( new TrackWP_WooCommerce( false ) )->save_attribution( $order->get_id() );
            $_COOKIE = array();
            unset( $_SERVER['HTTP_USER_AGENT'] );
        }
        return wc_get_order( $order->get_id() );
    }

    private function pay( $order ) {
        $order->set_status( 'processing' );
        $order->save();
        return wc_get_order( $order->get_id() );
    }

    /** A paid order whose job has not run, with HTTP capture cleared. */
    private function paid_order( array $o = array() ) {
        $order      = $this->pay( $this->make_order( $o ) );
        $this->http = array();
        return $order;
    }

    private function jobs( $hook, array $args ) {
        return as_get_scheduled_actions(
            array(
                'hook'     => $hook,
                'args'     => $args,
                'status'   => ActionScheduler_Store::STATUS_PENDING,
                'per_page' => -1,
            ),
            'ids'
        );
    }

    private function post_event( $order ) {
        $body    = array(
            'event'      => 'purchase',
            'event_id'   => TrackWP_WooCommerce::event_id_for( $order->get_id() ),
            'page_url'   => 'https://example.org/checkout/order-received/',
            'page_title' => 'Ordre modtaget',
            'client_id'  => '123456789.1700000000',
            'session_id' => '1700000000',
            'order_ref'  => TrackWP_WooCommerce::order_ref_for( $order->get_id() ),
        );
        $request = new WP_REST_Request( 'POST', '/trackwp/v1/event' );
        $request->set_header( 'Content-Type', 'application/json' );
        $request->set_body( wp_json_encode( $body ) );
        return rest_get_server()->dispatch( $request );
    }

    // ------------------------------------------------------------------
    // Scheduling
    // ------------------------------------------------------------------

    public function test_status_change_and_payment_complete_schedule_exactly_one_job_without_http() {
        $order = $this->make_order();
        $order = $this->pay( $order );
        $id    = $order->get_id();

        $this->assertCount( 1, $this->jobs( 'trackwp_backup_purchase', array( $id ) ) );
        do_action( 'woocommerce_payment_complete', $id );
        $this->assertCount( 1, $this->jobs( 'trackwp_backup_purchase', array( $id ) ) );
        $this->assertSame( array(), $this->http, 'The gateway callback and status hook must not make HTTP requests.' );
    }

    public function test_deactivate_unschedules_backup_purchase_and_ga4_refund_jobs() {
        $order = $this->pay( $this->make_order() );
        $id    = $order->get_id();
        as_schedule_single_action( time() + 600, 'trackwp_ga4_refund', array( $id, 999 ) );
        $this->assertCount( 1, $this->jobs( 'trackwp_backup_purchase', array( $id ) ) );
        $this->assertCount( 1, $this->jobs( 'trackwp_ga4_refund', array( $id, 999 ) ) );

        TrackWP::instance()->deactivate();

        $this->assertCount( 0, $this->jobs( 'trackwp_backup_purchase', array( $id ) ) );
        $this->assertCount( 0, $this->jobs( 'trackwp_ga4_refund', array( $id, 999 ) ) );
    }

    public function test_processing_to_completed_schedules_nothing_new() {
        $order = $this->pay( $this->make_order() );
        $id    = $order->get_id();
        $order->set_status( 'completed' );
        $order->save();
        $this->assertCount( 1, $this->jobs( 'trackwp_backup_purchase', array( $id ) ) );
    }

    public function test_on_hold_schedules_nothing_unless_the_filter_adds_it() {
        $order = $this->make_order();
        $order->set_status( 'on-hold' );
        $order->save();
        $this->assertCount( 0, $this->jobs( 'trackwp_backup_purchase', array( $order->get_id() ) ) );

        add_filter( 'trackwp_backup_purchase_statuses', function ( $statuses ) {
            $statuses[] = 'on-hold';
            return $statuses;
        } );
        $order2 = $this->make_order();
        $order2->set_status( 'on-hold' );
        $order2->save();
        $this->assertCount( 1, $this->jobs( 'trackwp_backup_purchase', array( $order2->get_id() ) ) );
    }

    public function test_order_without_snapshot_schedules_nothing_and_logs_no_snapshot() {
        $order = $this->pay( $this->make_order( array( 'snapshot' => false ) ) );
        $this->assertCount( 0, $this->jobs( 'trackwp_backup_purchase', array( $order->get_id() ) ) );
        $this->assertContains( 'skipped/no_snapshot', $this->reasons( 'received' ) );
    }

    // ------------------------------------------------------------------
    // The customer never reaches the order-received page
    // ------------------------------------------------------------------

    public function test_backup_sends_purchase_to_ga4_and_meta_from_the_snapshot() {
        $order = $this->paid_order();
        $id    = $order->get_id();
        $paid  = $order->get_date_paid()->getTimestamp();

        TrackWP_Purchase_Backup::run_purchase( $id );

        $ga4 = $this->ga4_requests();
        $this->assertCount( 1, $ga4 );
        $event = $ga4[0]['body']['events'][0];
        $this->assertSame( 'purchase', $event['name'] );
        $this->assertSame( (string) $order->get_order_number(), $event['params']['transaction_id'] );
        $this->assertSame( TrackWP_WooCommerce::event_id_for( $id ), $event['params']['event_id'] );
        $this->assertSame( $paid * 1000000, $event['timestamp_micros'] );
        $this->assertSame( '111.222', $ga4[0]['body']['client_id'] );
        $this->assertSame( self::UA, $ga4[0]['body']['user_agent'] );

        $meta = $this->meta_requests();
        $this->assertCount( 1, $meta );
        $entry = $meta[0]['body']['data'][0];
        $this->assertSame( TrackWP_WooCommerce::event_id_for( $id ), $entry['event_id'] );
        $this->assertSame( self::UA, $entry['user_data']['client_user_agent'] );
        $this->assertSame( $paid, $entry['event_time'] );

        $this->assertContains( 'ok/backup', $this->reasons( 'received' ) );
        $this->assertContains( 'ok/sent', $this->reasons( 'ga4' ) );
    }

    public function test_backup_sends_the_orders_customer_ip_never_the_request_ip() {
        $order = $this->paid_order();
        $_SERVER['REMOTE_ADDR'] = self::GATEWAY_IP;

        TrackWP_Purchase_Backup::run_purchase( $order->get_id() );

        $ga4  = $this->ga4_requests();
        $meta = $this->meta_requests();
        $this->assertCount( 1, $ga4 );
        $this->assertCount( 1, $meta );
        $this->assertSame( self::ORDER_IP, $ga4[0]['body']['ip_override'] );
        $this->assertSame( self::ORDER_IP, $meta[0]['body']['data'][0]['user_data']['client_ip_address'] );
        foreach ( $this->http as $r ) {
            $this->assertStringNotContainsString( self::GATEWAY_IP, $r['body'] );
        }
    }

    public function test_customer_ip_is_only_used_with_the_matching_consent() {
        $order = $this->paid_order( array( 'statistics' => true, 'marketing' => false ) );
        TrackWP_Purchase_Backup::run_purchase( $order->get_id() );
        $this->assertCount( 0, $this->meta_requests() );
        $this->assertContains( 'skipped/no_consent', $this->reasons( 'meta' ) );
        $this->assertCount( 1, $this->ga4_requests() );

        $this->http = array();
        $order2     = $this->paid_order( array( 'statistics' => false, 'marketing' => true ) );
        TrackWP_Purchase_Backup::run_purchase( $order2->get_id() );
        $this->assertCount( 0, $this->ga4_requests() );
        $meta = $this->meta_requests();
        $this->assertCount( 1, $meta );
        $this->assertSame( self::ORDER_IP, $meta[0]['body']['data'][0]['user_data']['client_ip_address'] );
    }

    public function test_run_reads_no_cookie_ip_or_logged_in_user() {
        $order = $this->paid_order();
        $_COOKIE['_ga']            = 'GA1.1.999.888';
        $_COOKIE['_gcl_aw']        = 'GCL.1.1.LEAKEDCLICK';
        $_COOKIE['trackwp_consent'] = '{"statistics":false,"marketing":false}';
        $_SERVER['REMOTE_ADDR']    = self::GATEWAY_IP;
        wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

        TrackWP_Purchase_Backup::run_purchase( $order->get_id() );

        $this->assertCount( 1, $this->ga4_requests() );
        $this->assertCount( 1, $this->meta_requests() );
        foreach ( $this->http as $r ) {
            $this->assertStringNotContainsString( '999.888', $r['body'] );
            $this->assertStringNotContainsString( 'LEAKEDCLICK', $r['body'] );
            $this->assertStringNotContainsString( self::GATEWAY_IP, $r['body'] );
        }
        $this->assertArrayNotHasKey( 'user_id', $this->ga4_requests()[0]['body'], 'A guest order must not take the current admin as user_id.' );
    }

    // ------------------------------------------------------------------
    // Order of events between browser and backup
    // ------------------------------------------------------------------

    public function test_browser_seen_first_means_backup_sends_nothing() {
        $order = $this->paid_order();
        TrackWP_Order_Claims::claim( $order->get_id(), 'browser_seen', 'browser' );

        TrackWP_Purchase_Backup::run_purchase( $order->get_id() );

        $this->assertSame( array(), $this->http );
        $this->assertContains( 'skipped/browser_seen', $this->reasons( 'received' ) );
    }

    public function test_backup_first_then_browser_post_sends_no_second_purchase() {
        $order = $this->paid_order();
        TrackWP_Purchase_Backup::run_purchase( $order->get_id() );
        $this->assertCount( 1, $this->ga4_requests() );

        $this->set_consent_cookie( true, true );
        $this->post_event( $order );

        $this->assertCount( 1, $this->ga4_requests(), 'The browser path must not send a second purchase.' );
        $this->assertContains( 'duplicate/already_claimed', $this->reasons( 'ga4' ) );
    }

    public function test_backup_twice_sends_once() {
        $order = $this->paid_order();
        TrackWP_Purchase_Backup::run_purchase( $order->get_id() );
        TrackWP_Purchase_Backup::run_purchase( $order->get_id() );
        $this->assertCount( 1, $this->ga4_requests() );
        $this->assertCount( 1, $this->meta_requests() );
    }

    // ------------------------------------------------------------------
    // Consent and data
    // ------------------------------------------------------------------

    public function test_analytics_without_client_id_sends_no_synthetic_ga4_event_but_meta_goes() {
        $order = $this->paid_order( array( 'client_id' => false ) );
        TrackWP_Purchase_Backup::run_purchase( $order->get_id() );
        $this->assertCount( 0, $this->ga4_requests() );
        $this->assertContains( 'skipped/no_client_id', $this->reasons( 'ga4' ) );
        $this->assertCount( 1, $this->meta_requests() );
    }

    public function test_withdrawal_in_the_consent_log_after_checkout_stops_everything() {
        $order = $this->paid_order();
        $this->assertSame( self::CONSENT_ID, $order->get_meta( TrackWP_WooCommerce::ORDER_META_ATTRIBUTION )['consent']['id'] );
        TrackWP_Consent_Log::insert( array(
            'consent_id' => self::CONSENT_ID,
            'event_type' => 'withdraw',
            'statistics' => 0,
            'marketing'  => 0,
            'created_at' => gmdate( 'Y-m-d H:i:s', time() + 5 ),
        ) );

        TrackWP_Purchase_Backup::run_purchase( $order->get_id() );

        $this->assertSame( array(), $this->http );
        $this->assertContains( 'skipped/no_consent', $this->reasons( 'received' ) );
    }

    public function test_copied_snapshot_and_legacy_renewal_snapshot_are_not_used() {
        $source = $this->make_order();
        $snap   = $source->get_meta( TrackWP_WooCommerce::ORDER_META_ATTRIBUTION );

        // Another order carrying a copy of this snapshot (oid differs).
        $copy = $this->paid_order( array( 'snapshot' => false ) );
        $copy->update_meta_data( TrackWP_WooCommerce::ORDER_META_ATTRIBUTION, $snap );
        $copy->save();
        TrackWP_Purchase_Backup::run_purchase( $copy->get_id() );
        $this->assertSame( array(), $this->http );

        // Legacy snapshot (no oid) on a renewal-like order.
        unset( $snap['oid'] );
        $renewal = $this->paid_order( array( 'snapshot' => false, 'created_via' => 'subscription' ) );
        $renewal->update_meta_data( TrackWP_WooCommerce::ORDER_META_ATTRIBUTION, $snap );
        $renewal->save();
        TrackWP_Purchase_Backup::run_purchase( $renewal->get_id() );
        $this->assertSame( array(), $this->http );
        $this->assertContains( 'skipped/no_snapshot', $this->reasons( 'received' ) );

        // The same legacy snapshot on a real checkout order is still honoured.
        $legacy = $this->paid_order( array( 'snapshot' => false ) );
        $legacy->update_meta_data( TrackWP_WooCommerce::ORDER_META_ATTRIBUTION, $snap );
        $legacy->save();
        TrackWP_Purchase_Backup::run_purchase( $legacy->get_id() );
        $this->assertCount( 1, $this->ga4_requests() );
    }

    public function test_order_older_than_71_hours_skips_ga4_but_meta_is_sent() {
        $order = $this->paid_order();
        $order->set_date_paid( time() - 80 * HOUR_IN_SECONDS );
        $order->save();

        TrackWP_Purchase_Backup::run_purchase( $order->get_id() );

        $this->assertCount( 0, $this->ga4_requests() );
        $this->assertContains( 'skipped/event_too_old', $this->reasons( 'ga4' ) );
        $this->assertCount( 1, $this->meta_requests() );
    }

    public function test_order_no_longer_in_a_paid_status_sends_nothing() {
        $order = $this->paid_order();
        $order->set_status( 'cancelled' );
        $order->save();
        $this->http = array();

        TrackWP_Purchase_Backup::run_purchase( $order->get_id() );

        $this->assertSame( array(), $this->http );
        $this->assertContains( 'skipped/order_not_countable', $this->reasons( 'received' ) );
    }
}
