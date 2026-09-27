<?php
/**
 * Authoritative purchase flow (PLAN-1.10.1-v4 K5, R9-R15).
 *
 * Runs against real WooCommerce orders in both storage backends: the
 * phpunit service uses HPOS, phpunit-legacy-orders runs `--group orders`
 * with HPOS off (TRACKWP_TEST_HPOS=0, see tests/bootstrap.php).
 *
 * Producers used instead of hand-built fixtures:
 * - orders/products: WooCommerce's own wc_create_order()/WC_Product_Simple
 * - page config: TrackWP_WooCommerce::build_config()
 * - order_ref: TrackWP_WooCommerce::order_ref_for()
 * - trackwp_added: WooCommerce's woocommerce_add_to_cart action fired by
 *   WC_Cart::add_to_cart(), read back through the real fragments filter
 *
 * @group orders
 */

class TrackWP_WooCommerce_Purchase_Test extends WP_UnitTestCase {

    public function set_up() {
        parent::set_up();
        if ( ! class_exists( 'WooCommerce' ) ) {
            $this->markTestSkipped( 'WooCommerce is not installed in this environment.' );
        }
        update_option(
            'trackwp_woocommerce',
            array_merge( TrackWP_WooCommerce::get_defaults(), array( 'enabled' => true ) )
        );
        TrackWP_WooCommerce::reset_request_state();
        TrackWP_Order_Claims::create_table();
        $_COOKIE = array();
    }

    public function tear_down() {
        TrackWP_WooCommerce::reset_request_state();
        remove_all_filters( 'woocommerce_is_order_received_page' );
        remove_all_filters( 'wp_doing_ajax' );
        $_COOKIE = array();
        $_GET    = array();
        parent::tear_down();
    }

    // ------------------------------------------------------------------
    // Helpers (WooCommerce's own CRUD, no hand-written order data)
    // ------------------------------------------------------------------

    private function make_product( $price = '100' ) {
        $product = new WC_Product_Simple();
        $product->set_name( 'Testvare ' . wp_generate_password( 6, false ) );
        $product->set_regular_price( $price );
        $product->save();
        return $product;
    }

    private function make_order( $status = 'processing', $quantity = 2, $price = '100' ) {
        $product = $this->make_product( $price );
        $order   = wc_create_order();
        $order->add_product( $product, $quantity );
        $order->set_billing_email( 'Kunde.Test+x@Example.org' );
        $order->set_billing_phone( '12345678' );
        $order->set_billing_first_name( 'Åse' );
        $order->set_billing_last_name( 'Kunde' );
        $order->set_billing_city( 'Aarhus' );
        $order->set_billing_postcode( '8000' );
        $order->set_billing_country( 'DK' );
        $order->calculate_totals();
        $order->set_status( $status );
        $order->save();
        return wc_get_order( $order->get_id() );
    }

    private function drop_claims_table() {
        global $wpdb;
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $wpdb->query( 'DROP TABLE IF EXISTS ' . TrackWP_Order_Claims::table_name() );
        delete_option( TrackWP_Order_Claims::DB_VERSION_OPTION );
    }

    /**
     * Simulate the order-received page for $order and return build_config().
     */
    private function config_on_received_page( $order, $key = null ) {
        global $wp;
        $wp->query_vars['order-received'] = (string) $order->get_id();
        $_GET['key']                      = null === $key ? $order->get_order_key() : $key;
        add_filter( 'woocommerce_is_order_received_page', '__return_true' );
        $woo = new TrackWP_WooCommerce( false );
        return $woo->build_config();
    }

    private function purchase_entry( $config ) {
        foreach ( $config['immediate'] as $entry ) {
            if ( 'purchase' === $entry['event'] ) {
                return $entry;
            }
        }
        return null;
    }

    // ------------------------------------------------------------------
    // order_ref and event_id
    // ------------------------------------------------------------------

    public function test_order_ref_round_trips_and_forgery_fails() {
        $ref = TrackWP_WooCommerce::order_ref_for( 42 );
        $this->assertMatchesRegularExpression( '/^42\.[a-f0-9]{24}$/', $ref );
        $this->assertSame( 42, TrackWP_WooCommerce::verify_order_ref( $ref ) );

        $this->assertSame( 0, TrackWP_WooCommerce::verify_order_ref( '43.' . substr( $ref, 3 ) ) );
        $this->assertSame( 0, TrackWP_WooCommerce::verify_order_ref( '42.' . str_repeat( '0', 24 ) ) );
        $this->assertSame( 0, TrackWP_WooCommerce::verify_order_ref( array( $ref ) ) );
        $this->assertSame( 0, TrackWP_WooCommerce::verify_order_ref( '' ) );
    }

    public function test_event_id_is_deterministic_and_matches_k2_pattern() {
        $a = TrackWP_WooCommerce::event_id_for( 7 );
        $this->assertSame( $a, TrackWP_WooCommerce::event_id_for( 7 ) );
        $this->assertNotSame( $a, TrackWP_WooCommerce::event_id_for( 8 ) );
        $this->assertMatchesRegularExpression( '/^evt_[a-f0-9]{16,64}$/', $a );
    }

    // ------------------------------------------------------------------
    // Countable statuses (R10)
    // ------------------------------------------------------------------

    public function test_pending_and_on_hold_count_cancelled_and_failed_do_not() {
        foreach ( array( 'pending', 'on-hold', 'processing', 'completed' ) as $status ) {
            $this->assertTrue( TrackWP_WooCommerce::is_countable_status( $this->make_order( $status ) ), $status );
        }
        foreach ( array( 'cancelled', 'failed', 'refunded' ) as $status ) {
            $this->assertFalse( TrackWP_WooCommerce::is_countable_status( $this->make_order( $status ) ), $status );
        }
    }

    public function test_excluded_statuses_filter_can_exclude_on_hold() {
        $order = $this->make_order( 'on-hold' );
        add_filter( 'trackwp_purchase_excluded_statuses', function ( $statuses ) {
            $statuses[] = 'wc-on-hold';
            return $statuses;
        } );
        $this->assertFalse( TrackWP_WooCommerce::is_countable_status( $order ) );
        remove_all_filters( 'trackwp_purchase_excluded_statuses' );
    }

    public function test_cancelled_order_is_not_countable_on_server_or_page() {
        $order  = $this->make_order( 'cancelled' );
        $result = TrackWP_WooCommerce::authoritative_purchase( TrackWP_WooCommerce::order_ref_for( $order->get_id() ) );
        $this->assertSame( 'skip', $result['status'] );
        $this->assertSame( 'order_not_countable', $result['reason'] );

        $this->assertNull( $this->purchase_entry( $this->config_on_received_page( $order ) ) );
    }

    // ------------------------------------------------------------------
    // Authoritative payload (K5, R15)
    // ------------------------------------------------------------------

    public function test_authoritative_payload_comes_from_the_order_not_the_client() {
        $order  = $this->make_order( 'pending', 2, '100' );
        $result = TrackWP_WooCommerce::authoritative_purchase(
            TrackWP_WooCommerce::order_ref_for( $order->get_id() ),
            array( 'effective' => array( 'analytics' => true, 'marketing' => false ) )
        );

        $this->assertSame( 'verified', $result['status'] );
        $this->assertSame( (int) $order->get_id(), $result['order_id'] );
        $this->assertSame( 'order_ref', $result['via'] );

        $o = $result['overrides'];
        $this->assertSame( round( (float) $order->get_total(), 2 ), $o['value'] );
        $this->assertSame( $order->get_currency(), $o['currency'] );
        $this->assertSame( (string) $order->get_order_number(), $o['ecommerce']['transaction_id'] );
        $this->assertSame( TrackWP_WooCommerce::event_id_for( $order->get_id() ), $o['event_id'] );
        $this->assertCount( 1, $o['ecommerce']['items'] );
        $this->assertSame( 2, $o['ecommerce']['items'][0]['quantity'] );
        $this->assertArrayHasKey( 'value_ga4', $o['ecommerce'] );
        $this->assertArrayHasKey( 'tax', $o['ecommerce'] );
        $this->assertArrayHasKey( 'shipping', $o['ecommerce'] );
        // No marketing consent: no customer data.
        $this->assertArrayNotHasKey( 'enhanced', $o );
        $this->assertArrayNotHasKey( 'external_id', $o );
    }

    public function test_enhanced_only_with_marketing_and_customer_data_sharing() {
        $order = $this->make_order();
        $ref   = TrackWP_WooCommerce::order_ref_for( $order->get_id() );

        $with = TrackWP_WooCommerce::authoritative_purchase( $ref, array( 'effective' => array( 'marketing' => true ) ) );
        $this->assertSame( 'Kunde.Test+x@Example.org', $with['overrides']['enhanced']['email'] );

        update_option( 'trackwp_advanced', array( 'customer_data_sharing' => false ) );
        $without = TrackWP_WooCommerce::authoritative_purchase( $ref, array( 'effective' => array( 'marketing' => true ) ) );
        $this->assertArrayNotHasKey( 'enhanced', $without['overrides'] );
        delete_option( 'trackwp_advanced' );
    }

    public function test_items_carry_discounted_unit_price_and_per_unit_discount() {
        $coupon = new WC_Coupon();
        $coupon->set_code( 'halv' . wp_generate_password( 4, false ) );
        $coupon->set_discount_type( 'percent' );
        $coupon->set_amount( 50 );
        $coupon->save();

        $order = $this->make_order( 'processing', 2, '100' );
        $order->apply_coupon( $coupon->get_code() );
        $order->calculate_totals();
        $order->save();

        $settings                = TrackWP_WooCommerce::get_defaults();
        $settings['enabled']     = true;
        $settings['value_basis'] = 'ex_tax';
        update_option( 'trackwp_woocommerce', $settings );

        $data = ( new TrackWP_WooCommerce( false ) )->build_purchase_data( wc_get_order( $order->get_id() ) );
        $item = $data['ecommerce']['items'][0];
        $this->assertEqualsWithDelta( 50.0, $item['price'], 0.001 );
        $this->assertEqualsWithDelta( 50.0, $item['discount'], 0.001 );
        $this->assertSame( strtolower( $coupon->get_code() ), strtolower( $data['ecommerce']['coupon'] ) );
        $this->assertEqualsWithDelta( 100.0, $data['ecommerce']['value_ga4'], 0.001 );
    }

    public function test_forged_order_ref_is_skipped_invalid() {
        $order  = $this->make_order();
        $result = TrackWP_WooCommerce::authoritative_purchase( $order->get_id() . '.' . str_repeat( 'a', 24 ) );
        $this->assertSame( 'skip', $result['status'] );
        $this->assertSame( 'invalid_order_ref', $result['reason'] );
        $this->assertSame( array(), $result['overrides'] );
    }

    public function test_legacy_client_verified_through_order_key_r11() {
        $order = $this->make_order();
        $url   = add_query_arg( 'key', $order->get_order_key(), home_url( '/checkout/order-received/' . $order->get_id() . '/' ) );

        $ok = TrackWP_WooCommerce::authoritative_purchase( '', array( 'page_url' => $url, 'transaction_id' => $order->get_order_number() ) );
        $this->assertSame( 'verified', $ok['status'] );
        $this->assertSame( 'order_key', $ok['via'] );

        $wrong = TrackWP_WooCommerce::authoritative_purchase( '', array( 'page_url' => $url, 'transaction_id' => '999999' ) );
        $this->assertSame( 'unverified', $wrong['status'] );
        $this->assertSame( 'unverified_purchase', $wrong['reason'] );

        $none = TrackWP_WooCommerce::authoritative_purchase( '', array( 'page_url' => home_url( '/' ) ) );
        $this->assertSame( 'unverified', $none['status'] );
    }

    // ------------------------------------------------------------------
    // Claim (R9)
    // ------------------------------------------------------------------

    public function test_exactly_one_claim_per_order() {
        $order = $this->make_order();
        $this->assertSame( 'claimed', TrackWP_Order_Claims::claim( $order->get_id(), 'purchase', 'browser' ) );
        $this->assertSame( 'duplicate', TrackWP_Order_Claims::claim( $order->get_id(), 'purchase', 'browser' ) );
        $this->assertSame( 'duplicate', TrackWP_Order_Claims::claim( $order->get_id() ) );
        // Another scope is independent (1.11.0 per-destination claims).
        $this->assertSame( 'claimed', TrackWP_Order_Claims::claim( $order->get_id(), 'meta', 'browser' ) );
        $this->assertTrue( TrackWP_Order_Claims::is_claimed( $order->get_id() ) );
    }

    public function test_missing_table_is_an_error_and_repair_recovers() {
        // WP_UnitTestCase rewrites CREATE/DROP TABLE to TEMPORARY tables,
        // which SHOW TABLES does not list. Work on the real table here.
        remove_filter( 'query', array( $this, '_create_temporary_tables' ) );
        remove_filter( 'query', array( $this, '_drop_temporary_tables' ) );

        // No real order here: DROP/CREATE TABLE cause an implicit COMMIT, which
        // would make an order created in this test permanent in the test DB
        // (its line items then leak into later orders that reuse the ID).
        // claim() only needs a positive order id.
        $order_id = 987654321;
        $this->drop_claims_table();
        $this->assertFalse( TrackWP_Order_Claims::table_exists() );
        $this->assertSame( 'error', TrackWP_Order_Claims::claim( $order_id ) );

        TrackWP_Order_Claims::maybe_install();
        $this->assertTrue( TrackWP_Order_Claims::table_exists() );
        $this->assertSame( 'claimed', TrackWP_Order_Claims::claim( $order_id ) );
        $this->assertSame( 'duplicate', TrackWP_Order_Claims::claim( $order_id ) );

        add_filter( 'query', array( $this, '_create_temporary_tables' ) );
        add_filter( 'query', array( $this, '_drop_temporary_tables' ) );
    }

    public function test_install_is_idempotent() {
        $this->assertTrue( TrackWP_Order_Claims::create_table() );
        $this->assertTrue( TrackWP_Order_Claims::create_table() );
    }

    // ------------------------------------------------------------------
    // Order-received page config (producer: build_config)
    // ------------------------------------------------------------------

    public function test_no_flag_and_no_claim_at_render() {
        $order = $this->make_order( 'pending' );
        $entry = $this->purchase_entry( $this->config_on_received_page( $order ) );

        $this->assertNotNull( $entry );
        $this->assertSame( TrackWP_WooCommerce::order_ref_for( $order->get_id() ), $entry['order_ref'] );
        $this->assertSame( TrackWP_WooCommerce::event_id_for( $order->get_id() ), $entry['event_id'] );
        $this->assertSame( TrackWP_WooCommerce::order_ref_for( $order->get_id() ), $entry['order_ref'] );

        $fresh = wc_get_order( $order->get_id() );
        $this->assertEmpty( $fresh->get_meta( TrackWP_WooCommerce::ORDER_META_SENT ) );
        $this->assertFalse( TrackWP_Order_Claims::is_claimed( $order->get_id() ) );

        // A reload renders the same purchase again: dedup is the claim's job.
        $again = $this->purchase_entry( $this->config_on_received_page( $order ) );
        $this->assertSame( $entry['event_id'], $again['event_id'] );
    }

    public function test_wrong_order_key_renders_no_purchase() {
        $order = $this->make_order();
        $this->assertNull( $this->purchase_entry( $this->config_on_received_page( $order, 'wc_order_forkert' ) ) );
    }

    public function test_ec_and_am_follow_customer_data_sharing() {
        $order = $this->make_order();
        $entry = $this->purchase_entry( $this->config_on_received_page( $order ) );
        $this->assertSame( TrackWP_Hash::email_sha256( 'Kunde.Test+x@Example.org' ), $entry['ec']['sha256_email_address'] );
        $this->assertSame( hash( 'sha256', 'åse' ), $entry['ec']['address']['sha256_first_name'] );
        $this->assertSame( '8000', $entry['ec']['address']['postal_code'] );
        $this->assertSame( 'DK', $entry['ec']['address']['country'] );
        $this->assertArrayNotHasKey( 'sha256_street', $entry['ec']['address'] );
        $this->assertMatchesRegularExpression( '/^[a-f0-9]{64}$/', $entry['am']['em'] );

        update_option( 'trackwp_advanced', array( 'customer_data_sharing' => false ) );
        $entry = $this->purchase_entry( $this->config_on_received_page( $order ) );
        $this->assertArrayNotHasKey( 'ec', $entry );
        $this->assertArrayNotHasKey( 'am', $entry );
        delete_option( 'trackwp_advanced' );
    }

    // ------------------------------------------------------------------
    // Attribution at checkout (K5, R13)
    // ------------------------------------------------------------------

    private function set_consent_cookie( $statistics, $marketing ) {
        $cfg = get_option( 'trackwp_consent', array() );
        $v   = isset( $cfg['consent_version'] ) ? (int) $cfg['consent_version'] : 1;
        $fixture = dirname( __FILE__ ) . '/fixtures/consent-cookie.json';
        $fx      = file_exists( $fixture ) ? json_decode( file_get_contents( $fixture ), true ) : null;
        // The fixture wraps the cookie: the cookie object itself is under 'parsed'.
        $cookie  = ( is_array( $fx ) && isset( $fx['parsed'] ) && is_array( $fx['parsed'] ) ) ? $fx['parsed'] : null;
        if ( ! is_array( $cookie ) ) {
            // Same shape consent.js writes (K3).
            $cookie = array(
                'ts'        => gmdate( 'Y-m-d\TH:i:s.000\Z' ),
                'id'        => wp_generate_uuid4(),
                'necessary' => true,
            );
        }
        $cookie['v']          = $v;
        $cookie['statistics'] = $statistics;
        $cookie['marketing']  = $marketing;
        unset( $cookie['stale'] );
        $_COOKIE['trackwp_consent'] = wp_json_encode( $cookie );
    }

    public function test_attribution_only_with_consent_and_per_category() {
        $woo = new TrackWP_WooCommerce( false );

        $order = $this->make_order();
        $woo->save_attribution( $order->get_id() );
        $this->assertEmpty( wc_get_order( $order->get_id() )->get_meta( TrackWP_WooCommerce::ORDER_META_ATTRIBUTION ) );

        $this->set_consent_cookie( true, false );
        $_COOKIE['_fbp']       = 'fb.1.1700000000000.123';
        $_COOKIE['_twp_click'] = wp_json_encode( array( 'gclid' => array( 'v' => 'abc_DEF-1', 'ts' => 1 ) ) );
        $_COOKIE['_ga']        = 'GA1.1.111.222';

        $order = $this->make_order();
        // Store API hook passes the order object (R13).
        $woo->save_attribution( $order );
        $meta = wc_get_order( $order->get_id() )->get_meta( TrackWP_WooCommerce::ORDER_META_ATTRIBUTION );
        $this->assertTrue( $meta['consent']['analytics'] );
        $this->assertFalse( $meta['consent']['marketing'] );
        $this->assertSame( '111.222', $meta['analytics']['client_id'] );
        $this->assertArrayNotHasKey( 'marketing', $meta );

        $this->set_consent_cookie( false, true );
        $order = $this->make_order();
        $woo->save_attribution( $order->get_id() );
        $meta = wc_get_order( $order->get_id() )->get_meta( TrackWP_WooCommerce::ORDER_META_ATTRIBUTION );
        $this->assertSame( 'abc_DEF-1', $meta['marketing']['gclid'] );
        $this->assertSame( 'fb.1.1700000000000.123', $meta['marketing']['fbp'] );
        $this->assertArrayNotHasKey( 'analytics', $meta );
    }

    // ------------------------------------------------------------------
    // AJAX add-to-cart fragment
    // ------------------------------------------------------------------

    public function test_trackwp_added_holds_every_line_added_in_the_request() {
        add_filter( 'wp_doing_ajax', '__return_true' );
        new TrackWP_WooCommerce( true );

        if ( function_exists( 'wc_load_cart' ) ) {
            wc_load_cart();
        }
        $this->assertNotNull( WC()->cart, 'WooCommerce cart could not be loaded.' );

        $a = $this->make_product( '10' );
        $b = $this->make_product( '20' );
        WC()->cart->add_to_cart( $a->get_id(), 1 );
        WC()->cart->add_to_cart( $b->get_id(), 3 );

        $fragments = apply_filters( 'woocommerce_add_to_cart_fragments', array() );
        $this->assertArrayHasKey( 'trackwp_added', $fragments );
        $this->assertCount( 2, $fragments['trackwp_added'] );
        $this->assertSame( (string) $b->get_id(), $fragments['trackwp_added'][1]['items'][0]['item_id'] );
        $this->assertSame( 3, $fragments['trackwp_added'][1]['items'][0]['quantity'] );

        WC()->cart->empty_cart();
    }

    public function test_fragment_is_absent_when_nothing_was_added() {
        $woo = new TrackWP_WooCommerce( false );
        $this->assertSame( array( 'div.x' => '<div/>' ), $woo->add_cart_fragment( array( 'div.x' => '<div/>' ) ) );
    }

    public function test_pixel_advanced_matching_only_on_verified_page_with_sharing() {
        $this->assertSame( array(), TrackWP_WooCommerce::pixel_advanced_matching() );

        $order = $this->make_order();
        TrackWP_WooCommerce::reset_request_state();
        $this->config_on_received_page( $order );
        $am = TrackWP_WooCommerce::pixel_advanced_matching();
        $this->assertSame( TrackWP_WooCommerce::meta_advanced_matching( $order ), $am );
        $this->assertArrayHasKey( 'em', $am );

        TrackWP_WooCommerce::reset_request_state();
        update_option( 'trackwp_advanced', array( 'customer_data_sharing' => false ) );
        $this->assertSame( array(), TrackWP_WooCommerce::pixel_advanced_matching() );
        delete_option( 'trackwp_advanced' );

        TrackWP_WooCommerce::reset_request_state();
        $this->config_on_received_page( $order, 'wc_order_forkert' );
        $this->assertSame( array(), TrackWP_WooCommerce::pixel_advanced_matching() );
    }

    public function test_uninstall_meta_keys() {
        $this->assertSame(
            array( '_trackwp_attribution', '_trackwp_purchase_sent' ),
            TrackWP_WooCommerce::uninstall_meta_keys()
        );
    }
}
