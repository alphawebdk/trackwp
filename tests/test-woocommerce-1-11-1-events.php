<?php
/**
 * 1.11.1 additions to the WooCommerce integration (PLAN-1.11.1-v2 KC10, KC11,
 * §9 TR8): view_item_list, view_cart and the item-field changes (item_sku,
 * deepest-category hierarchy, all coupons, order-level discount).
 *
 * remove_from_cart was cut from scope by the coordinator (2026-09-29,
 * "høj risiko for regressionsfejl og lav værdi") after being implemented and
 * is not covered here.
 *
 * Producer: TrackWP_WooCommerce::build_config() / build_purchase_data(),
 * exactly like tests/test-woocommerce-purchase.php. `test_generate_js_fixture()`
 * additionally writes tests/fixtures/woo/datalayer-config.json, a REAL
 * build_config()/build_purchase_data() capture consumed by
 * tests/js/woo-datalayer-consent.test.mjs (the same "generate once, commit,
 * JS reads the static file" pattern as tests/fixtures/consent-cookie.json).
 *
 * @group orders
 */

class TrackWP_WooCommerce_1111_Events_Test extends WP_UnitTestCase {

    public function set_up() {
        parent::set_up();
        if ( ! class_exists( 'WooCommerce' ) ) {
            $this->markTestSkipped( 'WooCommerce is not installed in this environment.' );
        }
        update_option(
            'trackwp_woocommerce',
            array_merge(
                TrackWP_WooCommerce::get_defaults(),
                array(
                    'enabled'              => true,
                    'event_view_item_list' => true,
                    'event_view_cart'      => true,
                )
            )
        );
        TrackWP_WooCommerce::reset_request_state();
        $_COOKIE = array();
    }

    public function tear_down() {
        TrackWP_WooCommerce::reset_request_state();
        remove_all_filters( 'woocommerce_is_cart' );
        remove_all_filters( 'trackwp_item_primary_category' );
        $_COOKIE = array();
        $_GET    = array();
        parent::tear_down();
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    private function make_product( $price = '100', $sku = '' ) {
        $product = new WC_Product_Simple();
        $product->set_name( 'Testvare ' . wp_generate_password( 6, false ) );
        $product->set_regular_price( $price );
        if ( '' !== $sku ) {
            $product->set_sku( $sku );
        }
        $product->save();
        return $product;
    }

    private function make_category( $name, $parent = 0 ) {
        $term = wp_insert_term( $name . ' ' . wp_generate_password( 4, false ), 'product_cat', array( 'parent' => $parent ) );
        $this->assertNotWPError( $term );
        return (int) $term['term_id'];
    }

    /**
     * Simulate WooCommerce's own loop rendering: it calls this filter for
     * every product card it prints (class-trackwp-woocommerce.php hooks
     * collect_loop_product() onto it).
     */
    private function simulate_loop_render( $product ) {
        apply_filters( 'woocommerce_loop_add_to_cart_args', array(), $product );
    }

    /**
     * The "shop" page WC_Install::create_pages() normally seeds does not
     * reliably exist in this test DB (its permalink resolves to false), so a
     * real page is created and wired up here instead.
     */
    private function go_to_shop_page() {
        $page_id = self::factory()->post->create( array( 'post_type' => 'page', 'post_title' => 'Shop', 'post_status' => 'publish' ) );
        update_option( 'woocommerce_shop_page_id', $page_id );
        $this->go_to( get_permalink( $page_id ) );
    }

    private function immediate_entry( $config, $event ) {
        foreach ( $config['immediate'] as $entry ) {
            if ( $event === $entry['event'] ) {
                return $entry;
            }
        }
        return null;
    }

    // ------------------------------------------------------------------
    // view_item_list (KC10)
    // ------------------------------------------------------------------

    public function test_view_item_list_on_a_product_category_page() {
        $term_id = $this->make_category( 'Sko' );
        $term    = get_term( $term_id, 'product_cat' );
        $product = $this->make_product( '199' );
        wp_set_object_terms( $product->get_id(), array( $term_id ), 'product_cat' );

        $this->go_to( get_term_link( $term_id, 'product_cat' ) );
        $this->assertTrue( is_product_taxonomy(), 'go_to() must land on the term archive' );

        $woo = new TrackWP_WooCommerce();
        $this->simulate_loop_render( $product );
        $entry = $this->immediate_entry( $woo->build_config(), 'view_item_list' );

        $this->assertNotNull( $entry );
        $this->assertSame( $term->slug, $entry['ecommerce']['item_list_id'] );
        $this->assertSame( $term->name, $entry['ecommerce']['item_list_name'] );
        $this->assertCount( 1, $entry['ecommerce']['items'] );
        $this->assertSame( 0, $entry['ecommerce']['items'][0]['index'] );
        $this->assertSame( $term->slug, $entry['ecommerce']['items'][0]['item_list_id'] );
        $this->assertArrayNotHasKey( 'quantity', $entry['ecommerce']['items'][0] );
    }

    public function test_view_item_list_on_the_shop_page() {
        $product = $this->make_product( '50' );
        $this->go_to_shop_page();
        $this->assertTrue( is_shop() );

        $woo = new TrackWP_WooCommerce();
        $this->simulate_loop_render( $product );
        $entry = $this->immediate_entry( $woo->build_config(), 'view_item_list' );

        $this->assertNotNull( $entry );
        $this->assertSame( 'shop', $entry['ecommerce']['item_list_id'] );
    }

    public function test_view_item_list_on_search() {
        $product = $this->make_product( '50' );
        $this->go_to( home_url( '/?s=testsoegning' ) );
        $this->assertTrue( is_search() );

        $woo = new TrackWP_WooCommerce();
        $this->simulate_loop_render( $product );
        $entry = $this->immediate_entry( $woo->build_config(), 'view_item_list' );

        $this->assertNotNull( $entry );
        $this->assertSame( 'search', $entry['ecommerce']['item_list_id'] );
    }

    public function test_view_item_list_is_capped_at_50_indexed_from_zero() {
        $this->go_to_shop_page();
        $this->assertTrue( is_shop() );

        $woo = new TrackWP_WooCommerce();
        for ( $i = 0; $i < 55; $i++ ) {
            $this->simulate_loop_render( $this->make_product( '10' ) );
        }
        $entry = $this->immediate_entry( $woo->build_config(), 'view_item_list' );

        $this->assertNotNull( $entry );
        $this->assertCount( 50, $entry['ecommerce']['items'] );
        $this->assertSame( 0, $entry['ecommerce']['items'][0]['index'] );
        $this->assertSame( 49, $entry['ecommerce']['items'][49]['index'] );
    }

    public function test_view_item_list_absent_when_setting_off() {
        update_option( 'trackwp_woocommerce', array_merge( TrackWP_WooCommerce::get_defaults(), array( 'enabled' => true, 'event_view_item_list' => false ) ) );
        $this->go_to_shop_page();
        $woo = new TrackWP_WooCommerce();
        $this->simulate_loop_render( $this->make_product( '10' ) );
        $this->assertNull( $this->immediate_entry( $woo->build_config(), 'view_item_list' ) );
    }

    // ------------------------------------------------------------------
    // view_cart (KC10)
    // ------------------------------------------------------------------

    public function test_view_cart_payload_matches_the_real_cart() {
        add_filter( 'woocommerce_is_cart', '__return_true' );
        if ( function_exists( 'wc_load_cart' ) ) {
            wc_load_cart();
        }
        $product = $this->make_product( '80' );
        WC()->cart->empty_cart();
        WC()->cart->add_to_cart( $product->get_id(), 2 );

        $woo   = new TrackWP_WooCommerce();
        $entry = $this->immediate_entry( $woo->build_config(), 'view_cart' );

        $this->assertNotNull( $entry );
        $this->assertCount( 1, $entry['ecommerce']['items'] );
        $this->assertSame( 2, $entry['ecommerce']['items'][0]['quantity'] );
        $this->assertSame( 160.0, $entry['value'] );

        WC()->cart->empty_cart();
    }

    public function test_view_cart_absent_when_cart_is_empty() {
        add_filter( 'woocommerce_is_cart', '__return_true' );
        if ( function_exists( 'wc_load_cart' ) ) {
            wc_load_cart();
        }
        WC()->cart->empty_cart();

        $woo = new TrackWP_WooCommerce();
        $this->assertNull( $this->immediate_entry( $woo->build_config(), 'view_cart' ) );
    }

    // ------------------------------------------------------------------
    // KC11: item_sku
    // ------------------------------------------------------------------

    public function test_item_sku_present_on_view_item_and_absent_when_empty() {
        $with_sku    = $this->make_product( '10', 'ABC-123' );
        $without_sku = $this->make_product( '10' );

        $this->go_to( get_permalink( $with_sku->get_id() ) );
        $woo  = new TrackWP_WooCommerce();
        $item = $this->immediate_entry( $woo->build_config(), 'view_item' )['ecommerce']['items'][0];
        $this->assertSame( 'ABC-123', $item['item_sku'] );

        $this->go_to( get_permalink( $without_sku->get_id() ) );
        $woo  = new TrackWP_WooCommerce();
        $item = $this->immediate_entry( $woo->build_config(), 'view_item' )['ecommerce']['items'][0];
        $this->assertArrayNotHasKey( 'item_sku', $item );
    }

    // ------------------------------------------------------------------
    // KC11: category hierarchy (deepest term, tie-break, filter)
    // ------------------------------------------------------------------

    public function test_category_hierarchy_uses_the_deepest_assigned_term() {
        $top   = $this->make_category( 'Overtoej' );
        $mid   = $this->make_category( 'Jakker', $top );
        $child = $this->make_category( 'Vinterjakker', $mid );

        $product = $this->make_product( '10' );
        // Assigned directly to BOTH the shallow top term and the deep child --
        // the deep one must win.
        wp_set_object_terms( $product->get_id(), array( $top, $child ), 'product_cat' );

        $this->go_to( get_permalink( $product->get_id() ) );
        $woo  = new TrackWP_WooCommerce();
        $item = $this->immediate_entry( $woo->build_config(), 'view_item' )['ecommerce']['items'][0];

        $this->assertSame( get_term( $top, 'product_cat' )->name, $item['item_category'] );
        $this->assertSame( get_term( $mid, 'product_cat' )->name, $item['item_category2'] );
        $this->assertSame( get_term( $child, 'product_cat' )->name, $item['item_category3'] );
        $this->assertArrayNotHasKey( 'item_category4', $item );
    }

    public function test_category_tie_is_broken_by_the_lowest_term_id() {
        $first  = $this->make_category( 'Alfa' );
        $second = $this->make_category( 'Beta' );
        $this->assertLessThan( $second, $first, 'created first, so it must have the lower id' );

        $product = $this->make_product( '10' );
        // Both top-level (depth 0): the lower term_id must win, regardless of
        // array order.
        wp_set_object_terms( $product->get_id(), array( $second, $first ), 'product_cat' );

        $this->go_to( get_permalink( $product->get_id() ) );
        $woo  = new TrackWP_WooCommerce();
        $item = $this->immediate_entry( $woo->build_config(), 'view_item' )['ecommerce']['items'][0];

        $this->assertSame( get_term( $first, 'product_cat' )->name, $item['item_category'] );
    }

    public function test_category_filter_overrides_the_default_choice() {
        $chosen  = $this->make_category( 'Filtervalgt' );
        $default = $this->make_category( 'Standardvalgt' );

        $product = $this->make_product( '10' );
        wp_set_object_terms( $product->get_id(), array( $default ), 'product_cat' );

        add_filter( 'trackwp_item_primary_category', function ( $primary, $wc_product, $terms ) use ( $chosen ) {
            return get_term( $chosen, 'product_cat' );
        }, 10, 3 );

        $this->go_to( get_permalink( $product->get_id() ) );
        $woo  = new TrackWP_WooCommerce();
        $item = $this->immediate_entry( $woo->build_config(), 'view_item' )['ecommerce']['items'][0];

        $this->assertSame( get_term( $chosen, 'product_cat' )->name, $item['item_category'] );
    }

    // ------------------------------------------------------------------
    // KC11: all coupons (comma-separated) and order-level discount
    // ------------------------------------------------------------------

    public function test_all_coupons_are_comma_separated_and_discount_is_set() {
        $c1 = new WC_Coupon();
        $c1->set_code( 'FEST' . wp_generate_password( 4, false, false ) );
        $c1->set_discount_type( 'percent' );
        $c1->set_amount( 10 );
        $c1->save();

        $c2 = new WC_Coupon();
        $c2->set_code( 'EKSTRA' . wp_generate_password( 4, false, false ) );
        $c2->set_discount_type( 'fixed_cart' );
        $c2->set_amount( 5 );
        $c2->save();

        $product = $this->make_product( '100' );
        $order   = wc_create_order();
        $order->add_product( $product, 2 );
        $order->apply_coupon( $c1->get_code() );
        $order->apply_coupon( $c2->get_code() );
        $order->calculate_totals();
        $order->save();

        $data = ( new TrackWP_WooCommerce( false ) )->build_purchase_data( wc_get_order( $order->get_id() ) );

        $this->assertSame(
            strtolower( $c1->get_code() ) . ',' . strtolower( $c2->get_code() ),
            strtolower( $data['ecommerce']['coupon'] )
        );
        $this->assertGreaterThan( 0, $data['ecommerce']['discount'] );
        $this->assertEqualsWithDelta(
            round( (float) $order->get_total_discount( true ), 2 ),
            $data['ecommerce']['discount'],
            0.01
        );
    }

    public function test_discount_key_absent_without_a_coupon() {
        $product = $this->make_product( '100' );
        $order   = wc_create_order();
        $order->add_product( $product, 1 );
        $order->calculate_totals();
        $order->save();

        $data = ( new TrackWP_WooCommerce( false ) )->build_purchase_data( wc_get_order( $order->get_id() ) );
        $this->assertArrayNotHasKey( 'discount', $data['ecommerce'] );
        $this->assertArrayNotHasKey( 'coupon', $data['ecommerce'] );
    }

    // ------------------------------------------------------------------
    // Fixture for tests/js/woo-datalayer-consent.test.mjs (real producer,
    // "generate once, commit" -- see tests/fixtures/consent-cookie.json).
    // ------------------------------------------------------------------

    public function test_generate_js_fixture() {
        $product = $this->make_product( '150', 'JS-FIX-1' );
        $term_id = $this->make_category( 'JS-Fixture-Kategori' );
        wp_set_object_terms( $product->get_id(), array( $term_id ), 'product_cat' );

        $order = wc_create_order();
        $order->add_product( $product, 1 );
        $order->set_billing_email( 'js-fixture@example.org' );
        $order->set_billing_first_name( 'Fixture' );
        $order->set_billing_last_name( 'Testkunde' );
        $order->set_billing_postcode( '8000' );
        $order->set_billing_country( 'DK' );
        $order->calculate_totals();
        $order->set_status( 'processing' );
        $order->save();
        $order = wc_get_order( $order->get_id() );

        global $wp;
        $wp->query_vars['order-received'] = (string) $order->get_id();
        $_GET['key']                      = $order->get_order_key();
        add_filter( 'woocommerce_is_order_received_page', '__return_true' );
        $purchase_config = ( new TrackWP_WooCommerce( false ) )->build_config();
        remove_all_filters( 'woocommerce_is_order_received_page' );
        unset( $wp->query_vars['order-received'], $_GET['key'] );

        $this->go_to( get_permalink( $product->get_id() ) );
        $view_item_config = ( new TrackWP_WooCommerce( false ) )->build_config();

        $out = array(
            'generated_at' => gmdate( 'c' ),
            'generated_by' => 'tests/test-woocommerce-1-11-1-events.php test_generate_js_fixture() (real TrackWP_WooCommerce::build_config() against a real WC_Order/WC_Product)',
            'purchase'     => $this->immediate_entry( $purchase_config, 'purchase' ),
            'view_item'    => $this->immediate_entry( $view_item_config, 'view_item' ),
        );

        $this->assertNotNull( $out['purchase'], 'fixture generation itself must produce a purchase entry' );
        $this->assertNotNull( $out['view_item'], 'fixture generation itself must produce a view_item entry' );

        $dir = dirname( __FILE__ ) . '/fixtures/woo';
        if ( ! is_dir( $dir ) ) {
            mkdir( $dir, 0777, true );
        }
        file_put_contents( $dir . '/datalayer-config.json', wp_json_encode( $out, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) . "\n" );

        $this->assertFileExists( $dir . '/datalayer-config.json' );
    }
}
