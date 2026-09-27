<?php
/**
 * Tests for the WooCommerce integration's pure logic.
 *
 * These cover the parts that do not need a running shop: the ecommerce
 * sanitiser (the single validation gate for everything that reaches GA4 and
 * Meta), the settings sanitiser, and the shape of the seeded shop events.
 *
 * Behaviour that needs real products, carts and orders is covered by the
 * integration run described in the changelog, not here.
 */

class TrackWP_WooCommerce_Test extends WP_UnitTestCase {

    // ------------------------------------------------------------------
    // TrackWP_Proxy::sanitize_ecommerce
    // ------------------------------------------------------------------

    public function test_sanitize_ecommerce_rejects_non_arrays() {
        $this->assertSame( array(), TrackWP_Proxy::sanitize_ecommerce( null ) );
        $this->assertSame( array(), TrackWP_Proxy::sanitize_ecommerce( 'streng' ) );
        $this->assertSame( array(), TrackWP_Proxy::sanitize_ecommerce( 42 ) );
        $this->assertSame( array(), TrackWP_Proxy::sanitize_ecommerce( array() ) );
    }

    public function test_sanitize_ecommerce_keeps_only_whitelisted_item_fields() {
        $out = TrackWP_Proxy::sanitize_ecommerce( array(
            'items' => array(
                array(
                    'item_id'   => 'SKU-A',
                    'item_name' => 'Vare A',
                    'price'     => '99.95',
                    'quantity'  => '3',
                    'evil'      => 'drop-me',
                    'onclick'   => 'alert(1)',
                ),
            ),
        ) );

        $item = $out['items'][0];
        $this->assertSame( 'SKU-A', $item['item_id'] );
        $this->assertSame( 'Vare A', $item['item_name'] );
        $this->assertSame( 99.95, $item['price'] );
        $this->assertSame( 3, $item['quantity'] );
        $this->assertArrayNotHasKey( 'evil', $item );
        $this->assertArrayNotHasKey( 'onclick', $item );
    }

    public function test_sanitize_ecommerce_allows_r15_value_split_and_order_ref() {
        // R15: value_ga4, tax and shipping (numeric) and order_ref pass the
        // allowlist; non-numeric amounts are dropped.
        $out = TrackWP_Proxy::sanitize_ecommerce( array(
            'transaction_id' => '1001',
            'value_ga4'      => '80.00',
            'tax'            => 20,
            'shipping'       => 'gratis',
            'order_ref'      => '1001.' . str_repeat( 'a', 24 ),
        ) );

        $this->assertSame( 80.0, (float) $out['value_ga4'] );
        $this->assertSame( 20.0, (float) $out['tax'] );
        $this->assertArrayNotHasKey( 'shipping', $out );
        $this->assertSame( '1001.' . str_repeat( 'a', 24 ), $out['order_ref'] );
    }

    public function test_sanitize_ecommerce_drops_items_without_an_identifier() {
        // GA4 requires item_id or item_name; an item with neither is dropped
        // rather than sent and silently rejected upstream.
        $out = TrackWP_Proxy::sanitize_ecommerce( array(
            'items' => array(
                array( 'price' => 10, 'quantity' => 1 ),
                array( 'item_name' => 'Beholdes' ),
            ),
        ) );

        $this->assertCount( 1, $out['items'] );
        $this->assertSame( 'Beholdes', $out['items'][0]['item_name'] );
    }

    public function test_sanitize_ecommerce_only_keeps_positive_quantities() {
        // A zero or negative quantity is malformed. Clamping it to 1 would
        // invent a sale, so the key is omitted and GA4 applies its own default.
        $out = TrackWP_Proxy::sanitize_ecommerce( array(
            'items' => array(
                array( 'item_id' => 'neg', 'quantity' => -4 ),
                array( 'item_id' => 'nul', 'quantity' => 0 ),
                array( 'item_id' => 'ok', 'quantity' => 3 ),
            ),
        ) );

        $this->assertArrayNotHasKey( 'quantity', $out['items'][0] );
        $this->assertArrayNotHasKey( 'quantity', $out['items'][1] );
        $this->assertSame( 3, $out['items'][2]['quantity'] );
    }

    public function test_sanitize_ecommerce_keeps_index_zero() {
        // index is a list position, where 0 is legitimate.
        $out = TrackWP_Proxy::sanitize_ecommerce( array(
            'items' => array( array( 'item_id' => 'a', 'index' => 0 ) ),
        ) );

        $this->assertSame( 0, $out['items'][0]['index'] );
    }

    public function test_sanitize_ecommerce_drops_non_numeric_prices() {
        $out = TrackWP_Proxy::sanitize_ecommerce( array(
            'items' => array( array( 'item_id' => 'a', 'price' => 'abc', 'discount' => '' ) ),
        ) );

        $this->assertArrayNotHasKey( 'price', $out['items'][0] );
        $this->assertArrayNotHasKey( 'discount', $out['items'][0] );
    }

    public function test_sanitize_ecommerce_caps_item_count() {
        $items = array();
        for ( $i = 0; $i < TrackWP_Proxy::MAX_ECOMMERCE_ITEMS + 25; $i++ ) {
            $items[] = array( 'item_id' => 'ID' . $i );
        }

        $out = TrackWP_Proxy::sanitize_ecommerce( array( 'items' => $items ) );

        $this->assertCount( TrackWP_Proxy::MAX_ECOMMERCE_ITEMS, $out['items'] );
    }

    public function test_sanitize_ecommerce_truncates_transaction_id_and_strips_markup() {
        $out = TrackWP_Proxy::sanitize_ecommerce( array(
            'transaction_id' => str_repeat( 'A', 200 ),
            'coupon'         => 'SOMMER<b>bold</b>',
        ) );

        $this->assertSame( 64, strlen( $out['transaction_id'] ) );
        $this->assertStringNotContainsString( '<', $out['coupon'] );
    }

    public function test_sanitize_ecommerce_drops_unknown_top_level_keys() {
        $out = TrackWP_Proxy::sanitize_ecommerce( array(
            'transaction_id' => '13',
            'noget_andet'    => 'skal vaek',
        ) );

        $this->assertSame( array( 'transaction_id' => '13' ), $out );
    }

    // ------------------------------------------------------------------
    // Shop event templates
    // ------------------------------------------------------------------

    public function test_woocommerce_trigger_type_is_registered() {
        $this->assertArrayHasKey( 'woocommerce', TrackWP_Events::get_trigger_types() );
    }

    public function test_shop_event_templates_survive_validation() {
        // The templates are written straight into trackwp_events, which runs
        // them through validate_event(). A template that does not validate
        // would be silently dropped on save.
        foreach ( TrackWP_Events::get_woocommerce_event_templates() as $name => $template ) {
            $validated = TrackWP_Events::validate_event( $template );
            $this->assertNotWPError( $validated, "Skabelonen for {$name} blev afvist" );
            $this->assertSame( $name, $validated['name'] );
            $this->assertSame( 'woocommerce', $validated['trigger_type'] );
            $this->assertNotEmpty( $validated['firing_triggers'] );
        }
    }

    public function test_shop_event_names_match_templates() {
        $this->assertSame(
            TrackWP_Events::get_woocommerce_event_names(),
            array_keys( TrackWP_Events::get_woocommerce_event_templates() )
        );
    }

    public function test_only_purchase_defaults_to_google_ads() {
        // A conversion upload must be a deliberate choice; purchase is the one
        // shop event a store actually wants counted as one.
        $templates = TrackWP_Events::get_woocommerce_event_templates();
        $this->assertTrue( $templates['purchase']['send_to']['google_ads'] );
        $this->assertFalse( $templates['view_item']['send_to']['google_ads'] );
        $this->assertFalse( $templates['add_to_cart']['send_to']['google_ads'] );
        $this->assertFalse( $templates['begin_checkout']['send_to']['google_ads'] );
    }

    public function test_templates_map_to_meta_standard_events() {
        $meta_types = array_keys( TrackWP_Events::get_meta_event_types() );
        foreach ( TrackWP_Events::get_woocommerce_event_templates() as $name => $template ) {
            $this->assertContains( $template['meta_event'], $meta_types, "Ugyldigt Meta-event for {$name}" );
        }
    }

    // ------------------------------------------------------------------
    // Settings sanitiser
    // ------------------------------------------------------------------

    public function test_woocommerce_settings_default_to_disabled() {
        $defaults = TrackWP_WooCommerce::get_defaults();
        $this->assertFalse( $defaults['enabled'] );
    }

    public function test_sanitize_woocommerce_rejects_unknown_selects() {
        $settings = new TrackWP_Settings();
        $out      = $settings->sanitize_woocommerce( array(
            'value_basis'    => '../../etc/passwd',
            'item_id_source' => 'noget-andet',
        ) );

        $defaults = TrackWP_WooCommerce::get_defaults();
        $this->assertSame( $defaults['value_basis'], $out['value_basis'] );
        $this->assertSame( $defaults['item_id_source'], $out['item_id_source'] );
    }

    public function test_sanitize_woocommerce_accepts_every_documented_value_basis() {
        $settings = new TrackWP_Settings();
        foreach ( array_keys( TrackWP_WooCommerce::value_bases() ) as $basis ) {
            $out = $settings->sanitize_woocommerce( array( 'value_basis' => $basis ) );
            $this->assertSame( $basis, $out['value_basis'] );
        }
    }

    public function test_sanitize_woocommerce_treats_missing_checkboxes_as_off() {
        $settings = new TrackWP_Settings();
        $out      = $settings->sanitize_woocommerce( array( 'enabled' => '1' ) );

        $this->assertTrue( $out['enabled'] );
        foreach ( TrackWP_Events::get_woocommerce_event_names() as $name ) {
            $this->assertFalse( $out[ 'event_' . $name ] );
        }
        $this->assertFalse( $out['include_categories'] );
    }

    public function test_sanitize_woocommerce_falls_back_on_garbage_input() {
        $settings = new TrackWP_Settings();
        $this->assertSame( TrackWP_WooCommerce::get_defaults(), $settings->sanitize_woocommerce( 'ikke et array' ) );
    }

    public function test_value_bases_declare_their_tax_treatment() {
        $bases = TrackWP_WooCommerce::value_bases();
        $this->assertTrue( $bases['total'] );
        $this->assertFalse( $bases['ex_tax'] );
        $this->assertTrue( $bases['ex_shipping'] );
        $this->assertFalse( $bases['ex_tax_shipping'] );
    }

    // ------------------------------------------------------------------
    // 1.10.1: shop events always carry a woocommerce trigger
    // ------------------------------------------------------------------

    private function stored_triggers( $name ) {
        foreach ( get_option( 'trackwp_events', array() ) as $event ) {
            if ( is_array( $event ) && isset( $event['name'] ) && $name === $event['name'] ) {
                // Same resolution as the client config: firing_triggers, else
                // the legacy single trigger (update_option() in tests does not
                // run the admin-registered sanitizer).
                return ! empty( $event['firing_triggers'] )
                    ? $event['firing_triggers']
                    : TrackWP_Conditions::triggers_from_legacy_event( $event );
            }
        }
        return null;
    }

    private function trigger_types( $triggers ) {
        return array_map( function ( $t ) {
            return $t['type'];
        }, (array) $triggers );
    }

    public function test_seeded_shop_events_have_a_woocommerce_trigger() {
        if ( ! class_exists( 'WooCommerce' ) ) {
            $this->markTestSkipped( 'WooCommerce is not installed.' );
        }
        update_option( 'trackwp_events', array() );
        update_option( 'trackwp_woocommerce', array_merge( TrackWP_WooCommerce::get_defaults(), array( 'enabled' => true ) ) );

        ( new TrackWP_WooCommerce( false ) )->seed_events();

        foreach ( array( 'view_item', 'add_to_cart', 'begin_checkout', 'purchase' ) as $name ) {
            $this->assertContains( 'woocommerce', $this->trigger_types( $this->stored_triggers( $name ) ), $name );
        }
    }

    public function test_existing_shop_event_without_woocommerce_trigger_is_migrated_once() {
        if ( ! class_exists( 'WooCommerce' ) ) {
            $this->markTestSkipped( 'WooCommerce is not installed.' );
        }
        delete_option( TrackWP_WooCommerce::TRIGGER_MIGRATION_OPTION );
        // A 1.10.0 purchase event whose admin switched the trigger type; the
        // producer is the events sanitizer itself (validate_event).
        $templates                  = TrackWP_Events::get_woocommerce_event_templates();
        $purchase                   = $templates['purchase'];
        $purchase['trigger_type']   = 'url_match';
        $purchase['url_match']      = '/tak';
        update_option( 'trackwp_events', array( $purchase ) );
        $this->assertNotContains( 'woocommerce', $this->trigger_types( $this->stored_triggers( 'purchase' ) ) );

        update_option( 'trackwp_woocommerce', array_merge( TrackWP_WooCommerce::get_defaults(), array( 'enabled' => true ) ) );
        ( new TrackWP_WooCommerce( false ) )->seed_events();

        $types = $this->trigger_types( $this->stored_triggers( 'purchase' ) );
        $this->assertContains( 'woocommerce', $types );
        $this->assertContains( 'url_match', $types );

        // Removed again by the admin: the one-time migration does not re-add it.
        update_option( 'trackwp_events', array( $purchase ) );
        ( new TrackWP_WooCommerce( false ) )->seed_events();
        $this->assertNotContains( 'woocommerce', $this->trigger_types( $this->stored_triggers( 'purchase' ) ) );
    }

    // ------------------------------------------------------------------
    // Review class A: window.trackwpWoo keeps its JSON types
    // ------------------------------------------------------------------

    public function test_print_config_is_json_with_real_booleans() {
        if ( ! class_exists( 'WooCommerce' ) ) {
            $this->markTestSkipped( 'WooCommerce is not installed.' );
        }
        update_option( 'trackwp_woocommerce', array_merge( TrackWP_WooCommerce::get_defaults(), array( 'enabled' => true ) ) );
        update_option( 'trackwp_advanced', array( 'debug_console' => '1' ) );

        ob_start();
        ( new TrackWP_WooCommerce( false ) )->print_config();
        $html = ob_get_clean();
        delete_option( 'trackwp_advanced' );

        $this->assertSame( 1, preg_match( '/window\.trackwpWoo=(.*);<\/script>/s', $html, $m ) );
        $config = json_decode( $m[1], true );
        $this->assertIsArray( $config );
        $this->assertTrue( $config['addToCart'] );
        $this->assertTrue( $config['includeTax'] );
        $this->assertTrue( $config['debugAllowed'] );
        $this->assertArrayNotHasKey( 'debug', $config );
        $this->assertIsArray( $config['immediate'] );
    }
}
