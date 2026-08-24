<?php
/**
 * WooCommerce integration for TrackWP.
 *
 * Emits the four funnel events (view_item, add_to_cart, begin_checkout,
 * purchase) through the existing TrackWP pipeline.
 *
 * DESIGN NOTES
 *
 * 1. All payloads are built in PHP, not scraped from the DOM. WC_Product,
 *    WC_Cart and WC_Order are the authoritative sources for price, quantity,
 *    tax and category; reading them server-side is both simpler and correct,
 *    where DOM scraping breaks on every theme.
 *
 * 2. purchase fires CLIENT-side on the order-received page, not server-side on
 *    an order-status transition. That is a deliberate trade-off. Firing in the
 *    browser means client_id, ga_session, _fbp, _fbc and gclid are all present,
 *    so the sale is attributed to the session that produced it. A server-side
 *    dispatch runs outside the visitor's request, where every one of those is
 *    missing and GA4 would invent a fresh client_id -- turning each sale into a
 *    new Direct user. The cost is orders whose customer never reaches the
 *    order-received page (some redirect gateways, closed tab).
 *
 * 3. Repeat firing is blocked by an order meta flag, not by a JS guard: a
 *    reload of the order-received page is the classic double-count.
 *
 * 4. add_to_cart has three paths because WooCommerce has three:
 *      - classic non-AJAX (form POST, page reload) -> recorded into the WC
 *        session here, replayed on the next page render
 *      - classic AJAX loop add -> handled in the browser via the product map
 *      - Cart/Checkout blocks (Store API) -> handled in the browser by diffing
 *        the wc/store/cart data store
 *    The server path deliberately ignores AJAX and REST requests so the two
 *    halves cannot both count the same add.
 *
 * @since 2.0.0
 * @package TrackWP
 */

defined('ABSPATH') || exit;

class TrackWP_WooCommerce {

    /** WC session key holding add_to_cart events awaiting the next page render. */
    const SESSION_KEY = 'trackwp_pending_add_to_cart';

    /** Order meta flag: purchase has already been emitted for this order. */
    const ORDER_META_SENT = '_trackwp_purchase_sent';

    /** Max add_to_cart events replayed from the session in one render. */
    const MAX_PENDING = 10;

    /** Max products put into the client-side lookup map for one page. */
    const MAX_PRODUCT_MAP = 100;

    /**
     * Product ids rendered in a loop on this request, collected during render
     * so the browser can resolve a classic AJAX add-to-cart without a request.
     *
     * @var int[]
     */
    private $loop_product_ids = array();

    /**
     * Settings cache for this request.
     *
     * @var array|null
     */
    private $settings_cache = null;

    public function __construct() {
        if ( ! $this->is_available() ) {
            return;
        }

        add_action( 'woocommerce_add_to_cart', array( $this, 'record_non_ajax_add_to_cart' ), 10, 4 );

        if ( ! $this->is_enabled() ) {
            return;
        }

        add_filter( 'woocommerce_loop_add_to_cart_args', array( $this, 'collect_loop_product' ), 10, 2 );
        add_action( 'wp_footer', array( $this, 'print_config' ), 5 );
        add_action( 'wp_enqueue_scripts', array( $this, 'enqueue' ), 20 );
        add_action( 'admin_init', array( $this, 'seed_events' ) );
    }

    /**
     * Is WooCommerce active?
     *
     * @return bool
     */
    public function is_available() {
        return class_exists( 'WooCommerce' );
    }

    /**
     * Is the WooCommerce integration switched on in settings?
     *
     * @return bool
     */
    public function is_enabled() {
        $settings = $this->settings();
        return ! empty( $settings['enabled'] );
    }

    /**
     * Per-request settings cache.
     *
     * @return array
     */
    private function settings() {
        if ( null === $this->settings_cache ) {
            $this->settings_cache = self::get_settings();
        }
        return $this->settings_cache;
    }

    /**
     * Settings with defaults applied.
     *
     * @return array
     */
    public static function get_settings() {
        $stored = get_option( 'trackwp_woocommerce', array() );
        if ( ! is_array( $stored ) ) {
            $stored = array();
        }
        return array_merge( self::get_defaults(), $stored );
    }

    /**
     * Default settings. Off by default: enabling shop tracking changes what is
     * sent to Google and Meta, so it must be a deliberate choice.
     *
     * @return array
     */
    public static function get_defaults() {
        return array(
            'enabled'              => false,
            'event_view_item'      => true,
            'event_add_to_cart'    => true,
            'event_begin_checkout' => true,
            'event_purchase'       => true,
            // total | ex_tax | ex_shipping | ex_tax_shipping
            'value_basis'          => 'total',
            // product_id | sku -- item_id must match the Merchant Center feed,
            // and which one that is differs per shop.
            'item_id_source'       => 'product_id',
            'include_categories'   => true,
        );
    }

    /**
     * Allowed value bases, as basis => whether the amount includes tax.
     *
     * @return array
     */
    public static function value_bases() {
        return array(
            'total'           => true,
            'ex_tax'          => false,
            'ex_shipping'     => true,
            'ex_tax_shipping' => false,
        );
    }

    /**
     * Ensure the four shop events exist in `trackwp_events`.
     *
     * Shop events live in the normal events list rather than in their own
     * option so they inherit per-event `send_to` routing, the Meta event
     * mapping, the Google Ads label, the delivery log and the dedup mode. Only
     * missing events are added -- an admin who edited or disabled one keeps
     * their change.
     *
     * update_option() runs the registered sanitize callback, so seeded rows go
     * through TrackWP_Events::validate_event() like any admin edit.
     *
     * @return void
     */
    public function seed_events() {
        if ( ! $this->is_enabled() ) {
            return;
        }

        $events = get_option( 'trackwp_events', array() );
        if ( ! is_array( $events ) ) {
            $events = array();
        }

        $existing = array();
        foreach ( $events as $event ) {
            if ( is_array( $event ) && ! empty( $event['name'] ) ) {
                $existing[ $event['name'] ] = true;
            }
        }

        $settings  = $this->settings();
        $templates = TrackWP_Events::get_woocommerce_event_templates();
        $added     = false;

        foreach ( $templates as $name => $template ) {
            if ( isset( $existing[ $name ] ) ) {
                continue;
            }
            if ( empty( $settings[ 'event_' . $name ] ) ) {
                continue;
            }
            $events[] = $template;
            $added    = true;
        }

        if ( $added ) {
            update_option( 'trackwp_events', $events );
        }
    }

    /**
     * Collect loop product ids during render for the client-side lookup map.
     *
     * @param array      $args    Add-to-cart link args (returned unmodified).
     * @param WC_Product $product Product being rendered.
     * @return array
     */
    public function collect_loop_product( $args, $product ) {
        if ( $product instanceof WC_Product && count( $this->loop_product_ids ) < self::MAX_PRODUCT_MAP ) {
            $this->loop_product_ids[] = (int) $product->get_id();
        }
        return $args;
    }

    /**
     * Record a non-AJAX add-to-cart into the WC session.
     *
     * AJAX and Store API adds are skipped on purpose: those are handled in the
     * browser, and recording them here as well would count the same add twice.
     *
     * @param string $cart_item_key Cart item key (unused).
     * @param int    $product_id    Product id.
     * @param int    $quantity      Quantity added.
     * @param int    $variation_id  Variation id, 0 for simple products.
     * @return void
     */
    public function record_non_ajax_add_to_cart( $cart_item_key, $product_id, $quantity, $variation_id ) {
        unset( $cart_item_key );

        if ( ! $this->is_enabled() ) {
            return;
        }
        $settings = $this->settings();
        if ( empty( $settings['event_add_to_cart'] ) ) {
            return;
        }
        if ( $this->is_async_request() ) {
            return;
        }
        if ( ! function_exists( 'WC' ) || ! WC()->session ) {
            return;
        }

        $item = $this->build_item_from_product(
            $variation_id ? (int) $variation_id : (int) $product_id,
            max( 1, (int) $quantity )
        );
        if ( empty( $item ) ) {
            return;
        }

        $pending = WC()->session->get( self::SESSION_KEY );
        if ( ! is_array( $pending ) ) {
            $pending = array();
        }
        if ( count( $pending ) >= self::MAX_PENDING ) {
            return;
        }

        $unit_price = isset( $item['price'] ) ? (float) $item['price'] : 0;
        $pending[]  = array(
            'items' => array( $item ),
            'value' => round( $unit_price * (int) $item['quantity'], 2 ),
        );
        WC()->session->set( self::SESSION_KEY, $pending );
    }

    /**
     * Is this an AJAX or REST request (i.e. handled client-side)?
     *
     * @return bool
     */
    private function is_async_request() {
        if ( function_exists( 'wp_doing_ajax' ) && wp_doing_ajax() ) {
            return true;
        }
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only context check.
        if ( isset( $_REQUEST['wc-ajax'] ) ) {
            return true;
        }
        if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
            return true;
        }
        return false;
    }

    /**
     * Enqueue the WooCommerce event script.
     *
     * Depends on trackwp-tracking so window.trackwp exists; the script also
     * listens for the 'trackwp:ready' handshake because trackwp.js loads async.
     *
     * @return void
     */
    public function enqueue() {
        if ( is_admin() ) {
            return;
        }
        wp_enqueue_script(
            'trackwp-woocommerce',
            TrackWP::asset_url( 'assets/js/woocommerce.js' ),
            array( 'trackwp-tracking' ),
            TRACKWP_VERSION,
            true
        );
    }

    /**
     * Print the per-page config before the footer scripts run.
     *
     * wp_localize_script cannot be used: the loop product map is only complete
     * after the page body has rendered. Core prints footer scripts on wp_footer
     * priority 20, so priority 5 here lands before them.
     *
     * @return void
     */
    public function print_config() {
        if ( is_admin() ) {
            return;
        }
        $config = $this->build_config();
        echo "\n<script id=\"trackwp-woo-config\">window.trackwpWoo=" . wp_json_encode( $config ) . ";</script>\n";
    }

    /**
     * Build the client config for the current request.
     *
     * @return array
     */
    private function build_config() {
        $settings = $this->settings();
        $advanced = get_option( 'trackwp_advanced', array() );

        $config = array(
            // Events fired immediately on load, in order.
            'immediate' => array(),
            // product_id => item, for resolving a classic AJAX add-to-cart.
            'products'  => array(),
            // Whether add_to_cart is switched on at all.
            'addToCart' => ! empty( $settings['event_add_to_cart'] ),
            'currency'  => function_exists( 'get_woocommerce_currency' ) ? get_woocommerce_currency() : 'DKK',
            // The browser must key item_id the same way PHP does, or GA4 sees
            // one product under two ids.
            'itemIdSource' => $settings['item_id_source'],
            // ...and it must apply the same tax basis, or add_to_cart prices
            // disagree with begin_checkout and purchase for the same product.
            'includeTax'   => $this->value_includes_tax( $settings['value_basis'] ),
            'debug'     => ! empty( $advanced['debug_console'] ),
        );

        $is_order_received = $this->is_order_received();

        // view_item
        if ( ! empty( $settings['event_view_item'] ) && is_singular( 'product' ) ) {
            $item = $this->build_item_from_product( get_the_ID(), 1 );
            if ( ! empty( $item ) ) {
                $config['immediate'][] = array(
                    'event'     => 'view_item',
                    'value'     => isset( $item['price'] ) ? (float) $item['price'] : 0,
                    'currency'  => $config['currency'],
                    'ecommerce' => array( 'items' => array( $item ) ),
                );
            }
        }

        // begin_checkout -- covers both classic and blocks checkout, since both
        // render on the page WooCommerce reports as the checkout.
        if ( ! empty( $settings['event_begin_checkout'] )
            && function_exists( 'is_checkout' ) && is_checkout()
            && ! $is_order_received ) {
            $cart_payload = $this->build_cart_payload();
            if ( ! empty( $cart_payload['ecommerce']['items'] ) ) {
                $config['immediate'][] = array_merge( array( 'event' => 'begin_checkout' ), $cart_payload );
            }
        }

        // purchase
        if ( ! empty( $settings['event_purchase'] ) && $is_order_received ) {
            $purchase = $this->build_purchase_payload();
            if ( ! empty( $purchase ) ) {
                $config['immediate'][] = $purchase;
            }
        }

        // Replay non-AJAX add_to_cart recorded on the previous request.
        if ( ! empty( $settings['event_add_to_cart'] ) ) {
            foreach ( $this->take_pending_add_to_cart() as $pending ) {
                if ( empty( $pending['items'] ) ) {
                    continue;
                }
                $config['immediate'][] = array(
                    'event'     => 'add_to_cart',
                    'value'     => isset( $pending['value'] ) ? (float) $pending['value'] : 0,
                    'currency'  => $config['currency'],
                    'ecommerce' => array( 'items' => $pending['items'] ),
                );
            }
        }

        // Cart baseline for the Store API watcher. Without it the browser would
        // have to treat the first cart response it sees as "the initial state"
        // and swallow the add that produced it.
        if ( ! empty( $settings['event_add_to_cart'] ) ) {
            $config['cart'] = $this->cart_quantities();
        }

        // Lookup map for classic AJAX adds.
        if ( ! empty( $settings['event_add_to_cart'] ) && ! empty( $this->loop_product_ids ) ) {
            foreach ( array_unique( $this->loop_product_ids ) as $product_id ) {
                $item = $this->build_item_from_product( (int) $product_id, 1 );
                if ( ! empty( $item ) ) {
                    $config['products'][ (string) $product_id ] = $item;
                }
            }
        }

        return $config;
    }

    /**
     * Current cart contents as cart_item_key => quantity.
     *
     * The Store API reports the same cart item keys, which is what lets the
     * browser diff its responses against this baseline.
     *
     * @return array
     */
    private function cart_quantities() {
        if ( ! function_exists( 'WC' ) || ! WC()->cart ) {
            return array();
        }
        $out = array();
        foreach ( WC()->cart->get_cart() as $key => $line ) {
            $quantity      = isset( $line['quantity'] ) ? (int) $line['quantity'] : 0;
            $out[ (string) $key ] = max( 0, $quantity );
        }
        return $out;
    }

    /**
     * Pull and clear the pending add_to_cart queue from the WC session.
     *
     * @return array
     */
    private function take_pending_add_to_cart() {
        if ( ! function_exists( 'WC' ) || ! WC()->session ) {
            return array();
        }
        $pending = WC()->session->get( self::SESSION_KEY );
        if ( empty( $pending ) || ! is_array( $pending ) ) {
            return array();
        }
        WC()->session->set( self::SESSION_KEY, array() );
        return array_slice( $pending, 0, self::MAX_PENDING );
    }

    /**
     * Are we on the order-received (thank you) page?
     *
     * @return bool
     */
    private function is_order_received() {
        return function_exists( 'is_order_received_page' ) && is_order_received_page();
    }

    /**
     * Build the purchase payload for the order on the order-received page.
     *
     * Returns an empty array when the order cannot be verified or has already
     * been counted. The order key is checked with hash_equals: the order id is
     * in the URL, and without that check any visitor could replay another
     * customer's order into the shop's analytics.
     *
     * @return array
     */
    private function build_purchase_payload() {
        global $wp;

        $order_id = isset( $wp->query_vars['order-received'] ) ? absint( $wp->query_vars['order-received'] ) : 0;
        if ( ! $order_id ) {
            return array();
        }

        $order = wc_get_order( $order_id );
        if ( ! $order instanceof WC_Order ) {
            return array();
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- WooCommerce's own order-received URL parameter.
        $key = isset( $_GET['key'] ) ? wc_clean( wp_unslash( $_GET['key'] ) ) : '';
        if ( '' === $key || ! hash_equals( (string) $order->get_order_key(), (string) $key ) ) {
            return array();
        }

        // Already counted -- a reload of this page must not send it again.
        if ( $order->get_meta( self::ORDER_META_SENT ) ) {
            return array();
        }

        $settings    = $this->settings();
        $include_tax = $this->value_includes_tax( $settings['value_basis'] );

        $items = array();
        foreach ( $order->get_items() as $line ) {
            if ( ! $line instanceof WC_Order_Item_Product ) {
                continue;
            }
            $item = $this->build_item_from_order_line( $line, $include_tax );
            if ( ! empty( $item ) ) {
                $items[] = $item;
            }
        }

        $payload = array(
            'event'     => 'purchase',
            'value'     => $this->order_value( $order, $settings['value_basis'] ),
            'currency'  => $order->get_currency(),
            'ecommerce' => array(
                'items'          => $items,
                // The ORDER NUMBER, not our event_id: it is what makes GA4 and
                // Google Ads deduplicate the same order, and what a later
                // refund can be matched against.
                'transaction_id' => (string) $order->get_order_number(),
            ),
        );

        $coupons = $order->get_coupon_codes();
        if ( ! empty( $coupons ) ) {
            $payload['ecommerce']['coupon'] = (string) reset( $coupons );
        }

        // Mark before output. If the browser request is blocked the event was
        // never going to arrive anyway, whereas an unmarked order re-fires on
        // every reload. HPOS-safe: CRUD setter plus save(), never update_post_meta.
        $order->update_meta_data( self::ORDER_META_SENT, current_time( 'mysql', true ) );
        $order->save();

        return $payload;
    }

    /**
     * Build the cart payload used for begin_checkout.
     *
     * @return array Payload without the event name: value, currency, ecommerce.
     */
    private function build_cart_payload() {
        if ( ! function_exists( 'WC' ) || ! WC()->cart ) {
            return array();
        }
        $cart = WC()->cart;
        if ( $cart->is_empty() ) {
            return array();
        }

        $settings    = $this->settings();
        $include_tax = $this->value_includes_tax( $settings['value_basis'] );

        $items = array();
        foreach ( $cart->get_cart() as $cart_item ) {
            $item = $this->build_item_from_cart_item( $cart_item, $include_tax );
            if ( ! empty( $item ) ) {
                $items[] = $item;
            }
        }

        return array(
            'value'     => $this->cart_value( $cart, $settings['value_basis'] ),
            'currency'  => function_exists( 'get_woocommerce_currency' ) ? get_woocommerce_currency() : 'DKK',
            'ecommerce' => array( 'items' => $items ),
        );
    }

    /**
     * Does the configured value basis include tax?
     *
     * @param string $basis Configured basis.
     * @return bool
     */
    private function value_includes_tax( $basis ) {
        $bases = self::value_bases();
        return isset( $bases[ $basis ] ) ? (bool) $bases[ $basis ] : true;
    }

    /**
     * Amount for the configured basis, never negative.
     *
     * total           = grand total
     * ex_tax          = total minus all tax (shipping tax included)
     * ex_shipping     = total minus shipping and shipping tax
     * ex_tax_shipping = line items excluding tax
     *
     * @param float  $total        Grand total.
     * @param float  $total_tax    All tax, shipping tax included.
     * @param float  $shipping     Shipping excluding tax.
     * @param float  $shipping_tax Shipping tax.
     * @param string $basis        Configured basis.
     * @return float
     */
    private function apply_value_basis( $total, $total_tax, $shipping, $shipping_tax, $basis ) {
        switch ( $basis ) {
            case 'ex_tax':
                $value = $total - $total_tax;
                break;
            case 'ex_shipping':
                $value = $total - $shipping - $shipping_tax;
                break;
            case 'ex_tax_shipping':
                $value = $total - $total_tax - $shipping;
                break;
            default:
                $value = $total;
        }

        return round( max( 0, $value ), 2 );
    }

    /**
     * Order value for the configured basis.
     *
     * @param WC_Order $order Order.
     * @param string   $basis Configured basis.
     * @return float
     */
    private function order_value( $order, $basis ) {
        return $this->apply_value_basis(
            (float) $order->get_total(),
            (float) $order->get_total_tax(),
            (float) $order->get_shipping_total(),
            (float) $order->get_shipping_tax(),
            $basis
        );
    }

    /**
     * Cart value for the configured basis.
     *
     * @param WC_Cart $cart  Cart.
     * @param string  $basis Configured basis.
     * @return float
     */
    private function cart_value( $cart, $basis ) {
        return $this->apply_value_basis(
            (float) $cart->get_total( 'edit' ),
            (float) $cart->get_total_tax(),
            (float) $cart->get_shipping_total(),
            (float) $cart->get_shipping_tax(),
            $basis
        );
    }

    /**
     * Build a GA4 item from a product id.
     *
     * @param int $product_id Product or variation id.
     * @param int $quantity   Quantity.
     * @return array Empty when the product does not exist.
     */
    private function build_item_from_product( $product_id, $quantity = 1 ) {
        if ( ! function_exists( 'wc_get_product' ) ) {
            return array();
        }
        $product = wc_get_product( $product_id );
        if ( ! $product instanceof WC_Product ) {
            return array();
        }

        // Follow the configured value basis, NOT the shop's display setting.
        // wc_get_price_to_display() honours woocommerce_tax_display_shop, so on
        // a shop that lists prices excluding tax it returned an ex-tax price
        // even when value_basis said to include it -- leaving view_item and
        // add_to_cart inconsistent with the cart and order events, which do
        // follow the basis.
        $include_tax = $this->value_includes_tax( $this->settings()['value_basis'] );
        $unit_price  = $include_tax
            ? wc_get_price_including_tax( $product )
            : wc_get_price_excluding_tax( $product );

        $item = array(
            'item_id'   => $this->item_id_for( $product ),
            'item_name' => $product->get_name(),
            'quantity'  => max( 1, (int) $quantity ),
            'price'     => round( (float) $unit_price, 2 ),
        );

        $this->add_category( $item, $product );
        $this->add_variant( $item, $product );

        return $this->drop_empty( $item );
    }

    /**
     * Build a GA4 item from a cart line.
     *
     * `price` is the pre-discount unit price and `discount` the per-unit
     * reduction, which is GA4's own model -- folding the discount into price
     * loses the difference between a cheap product and a discounted one.
     *
     * @param array $cart_item   Cart line.
     * @param bool  $include_tax Whether amounts should include tax.
     * @return array
     */
    private function build_item_from_cart_item( $cart_item, $include_tax ) {
        if ( empty( $cart_item['data'] ) || ! $cart_item['data'] instanceof WC_Product ) {
            return array();
        }
        $product  = $cart_item['data'];
        $quantity = isset( $cart_item['quantity'] ) ? max( 1, (int) $cart_item['quantity'] ) : 1;

        $subtotal     = isset( $cart_item['line_subtotal'] ) ? (float) $cart_item['line_subtotal'] : 0;
        $subtotal_tax = isset( $cart_item['line_subtotal_tax'] ) ? (float) $cart_item['line_subtotal_tax'] : 0;
        $total        = isset( $cart_item['line_total'] ) ? (float) $cart_item['line_total'] : $subtotal;
        $total_tax    = isset( $cart_item['line_total_tax'] ) ? (float) $cart_item['line_total_tax'] : $subtotal_tax;

        $item = array(
            'item_id'   => $this->item_id_for( $product ),
            'item_name' => $product->get_name(),
            'quantity'  => $quantity,
        );

        $this->add_price_and_discount( $item, $subtotal, $subtotal_tax, $total, $total_tax, $quantity, $include_tax );
        $this->add_category( $item, $product );
        $this->add_variant( $item, $product );

        return $this->drop_empty( $item );
    }

    /**
     * Build a GA4 item from an order line.
     *
     * @param WC_Order_Item_Product $line        Order line.
     * @param bool                  $include_tax Whether amounts should include tax.
     * @return array
     */
    private function build_item_from_order_line( $line, $include_tax ) {
        $quantity = max( 1, (int) $line->get_quantity() );
        $product  = $line->get_product();

        $fallback_id = $line->get_variation_id() ? $line->get_variation_id() : $line->get_product_id();

        $item = array(
            // A deleted product must not lose the line: fall back to the id
            // stored on the order line and the name captured at order time.
            'item_id'   => $product instanceof WC_Product
                ? $this->item_id_for( $product )
                : (string) $fallback_id,
            'item_name' => $line->get_name(),
            'quantity'  => $quantity,
        );

        $this->add_price_and_discount(
            $item,
            (float) $line->get_subtotal(),
            (float) $line->get_subtotal_tax(),
            (float) $line->get_total(),
            (float) $line->get_total_tax(),
            $quantity,
            $include_tax
        );

        if ( $product instanceof WC_Product ) {
            $this->add_category( $item, $product );
            $this->add_variant( $item, $product );
        }

        return $this->drop_empty( $item );
    }

    /**
     * Set unit price and per-unit discount on an item.
     *
     * @param array $item         Item, by reference.
     * @param float $subtotal     Line subtotal excluding tax, before discount.
     * @param float $subtotal_tax Tax on the subtotal.
     * @param float $total        Line total excluding tax, after discount.
     * @param float $total_tax    Tax on the total.
     * @param int   $quantity     Line quantity (always >= 1).
     * @param bool  $include_tax  Whether amounts should include tax.
     * @return void
     */
    private function add_price_and_discount( &$item, $subtotal, $subtotal_tax, $total, $total_tax, $quantity, $include_tax ) {
        $gross_subtotal = $include_tax ? $subtotal + $subtotal_tax : $subtotal;
        $gross_total    = $include_tax ? $total + $total_tax : $total;

        $item['price'] = round( $gross_subtotal / $quantity, 2 );

        $discount = $gross_subtotal - $gross_total;
        if ( $discount > 0 ) {
            $item['discount'] = round( $discount / $quantity, 2 );
        }
    }

    /**
     * item_id per the configured source. SKU is preferred by shops whose
     * Merchant Center feed keys on it; falls back to the id when empty.
     *
     * @param WC_Product $product Product.
     * @return string
     */
    private function item_id_for( $product ) {
        $settings = $this->settings();
        if ( 'sku' === $settings['item_id_source'] ) {
            $sku = $product->get_sku();
            if ( '' !== (string) $sku ) {
                return (string) $sku;
            }
        }
        return (string) $product->get_id();
    }

    /**
     * Add item_category (and item_category2..5) from the product's categories.
     *
     * Variations carry no terms of their own, so the parent is used.
     *
     * @param array      $item    Item, by reference.
     * @param WC_Product $product Product.
     * @return void
     */
    private function add_category( &$item, $product ) {
        $settings = $this->settings();
        if ( empty( $settings['include_categories'] ) ) {
            return;
        }

        $term_source = $product->get_parent_id() ? $product->get_parent_id() : $product->get_id();
        $terms       = get_the_terms( $term_source, 'product_cat' );
        if ( is_wp_error( $terms ) || empty( $terms ) || ! is_array( $terms ) ) {
            return;
        }

        $index = 0;
        foreach ( $terms as $term ) {
            if ( ! isset( $term->name ) ) {
                continue;
            }
            // GA4 spells the hierarchy item_category, item_category2 .. item_category5.
            $key          = 0 === $index ? 'item_category' : 'item_category' . ( $index + 1 );
            $item[ $key ] = $term->name;
            $index++;
            if ( $index >= 5 ) {
                break;
            }
        }
    }

    /**
     * Add item_variant from a variation's attribute summary.
     *
     * @param array      $item    Item, by reference.
     * @param WC_Product $product Product.
     * @return void
     */
    private function add_variant( &$item, $product ) {
        if ( ! $product instanceof WC_Product_Variation ) {
            return;
        }
        $attributes = $product->get_variation_attributes();
        if ( empty( $attributes ) || ! is_array( $attributes ) ) {
            return;
        }
        $parts = array();
        foreach ( $attributes as $value ) {
            if ( is_scalar( $value ) && '' !== (string) $value ) {
                $parts[] = (string) $value;
            }
        }
        if ( ! empty( $parts ) ) {
            $item['item_variant'] = implode( ', ', $parts );
        }
    }

    /**
     * Drop empty values so the sanitiser does not have to.
     *
     * @param array $item Item.
     * @return array
     */
    private function drop_empty( $item ) {
        foreach ( $item as $key => $value ) {
            if ( '' === $value || null === $value ) {
                unset( $item[ $key ] );
            }
        }
        return $item;
    }
}
