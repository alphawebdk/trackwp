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
 * 2. purchase is TRIGGERED client-side on the order-received page, so the
 *    request carries client_id, ga_session, _fbp, _fbc and gclid, and the
 *    sale is attributed to the session that produced it. A server-side
 *    dispatch on a status transition runs outside the visitor's request,
 *    where every one of those is missing. The cost is orders whose customer
 *    never reaches the order-received page (documented gap until 1.11.0).
 *
 * 3. The SERVER is authoritative for the purchase itself (PLAN-1.10.1-v4 K5).
 *    The page hands the browser an HMAC-signed order_ref; when /event receives
 *    purchase with it, authoritative_purchase() re-reads the WC_Order and
 *    rebuilds value, items, tax and shipping from it, ignoring whatever the
 *    client sent. Exactly-once delivery comes from an atomic database claim
 *    (TrackWP_Order_Claims), never from a flag set while rendering: a flag
 *    written at render time marked orders as sent that were never sent
 *    (consent not yet given, blocked request), and could not stop a browser
 *    replaying the page from its own HTTP cache.
 *
 * 4. add_to_cart has three paths because WooCommerce has three:
 *      - classic non-AJAX (form POST, page reload) -> recorded into the WC
 *        session here, replayed on the next page render
 *      - classic AJAX (wc-ajax=add_to_cart) -> every line added during the
 *        request is collected here and handed to the browser in the
 *        `trackwp_added` cart fragment; the old product-map lookup remains
 *        as the fallback
 *      - Cart/Checkout blocks (Store API) -> handled in the browser by
 *        watching the /wc/store/v1/cart responses
 *    Store API (REST) requests are skipped here so the two halves cannot both
 *    count the same add.
 *
 * @since 2.0.0
 * @package TrackWP
 */

defined('ABSPATH') || exit;

require_once __DIR__ . '/class-trackwp-order-claims.php';

class TrackWP_WooCommerce {

    /** WC session key holding add_to_cart events awaiting the next page render. */
    const SESSION_KEY = 'trackwp_pending_add_to_cart';

    /**
     * Legacy order meta flag from 1.10.0 (set at render time). No longer
     * written; kept so uninstall can remove it.
     */
    const ORDER_META_SENT = '_trackwp_purchase_sent';

    /** Order meta holding consent and identifiers captured at checkout (K5). */
    const ORDER_META_ATTRIBUTION = '_trackwp_attribution';

    /** Max add_to_cart events replayed from the session in one render. */
    const MAX_PENDING = 10;

    /** Max products put into the client-side lookup map for one page. */
    const MAX_PRODUCT_MAP = 100;

    /**
     * add_to_cart entries collected during this AJAX request, handed to the
     * browser in the `trackwp_added` fragment. Static because WooCommerce
     * fires woocommerce_add_to_cart once per line (grouped products, bundles
     * and plugins can add several in one request) and the fragment filter
     * runs later in the same request.
     *
     * @var array[]
     */
    private static $ajax_added = array();

    /**
     * Memoised Pixel advanced-matching data for this request.
     *
     * @var array|null
     */
    private static $pixel_am = null;

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

    /**
     * @param bool $register_hooks False builds a hook-less instance, used by
     *                             the static entry points (authoritative_purchase)
     *                             and by tests/fixture generators.
     */
    public function __construct( $register_hooks = true ) {
        if ( ! $register_hooks || ! $this->is_available() ) {
            return;
        }

        add_action( 'woocommerce_add_to_cart', array( $this, 'record_add_to_cart' ), 10, 4 );
        add_action( 'admin_init', array( 'TrackWP_Order_Claims', 'maybe_install' ) );

        if ( ! $this->is_enabled() ) {
            return;
        }

        add_filter( 'woocommerce_add_to_cart_fragments', array( $this, 'add_cart_fragment' ) );
        add_filter( 'woocommerce_loop_add_to_cart_args', array( $this, 'collect_loop_product' ), 10, 2 );
        // Priority 0 so no page cache has seen the response yet; repeated at
        // the very end in case a later callback resets Cache-Control.
        add_action( 'template_redirect', array( $this, 'send_nocache_headers' ), 0 );
        add_action( 'template_redirect', array( $this, 'send_nocache_headers' ), PHP_INT_MAX );
        add_action( 'woocommerce_checkout_order_processed', array( $this, 'save_attribution' ), 10, 1 );
        add_action( 'woocommerce_store_api_checkout_order_processed', array( $this, 'save_attribution' ), 10, 1 );
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
     * Order meta keys this integration writes, for uninstall. Both storage
     * backends (postmeta and the HPOS wc_orders_meta table) must be cleaned.
     *
     * @return string[]
     */
    public static function uninstall_meta_keys() {
        return array( self::ORDER_META_ATTRIBUTION, self::ORDER_META_SENT );
    }

    /**
     * Whether customer data (Enhanced Conversions user_data, Pixel advanced
     * matching, CAPI user data from the order) may be shared at all. On by
     * default; shops in sensitive categories switch it off
     * (support.google.com/adspolicy/answer/7475709).
     *
     * @return bool
     */
    public static function customer_data_sharing() {
        // Single source (review class C): setting and filter live in TrackWP_Hash.
        return TrackWP_Hash::customer_data_sharing_enabled();
    }

    // ------------------------------------------------------------------
    // Purchase: identifiers, verification and the authoritative payload
    // ------------------------------------------------------------------

    /**
     * Statuses that are countable as a purchase (R10). Everything counts
     * except failed, cancelled, refunded, trash and checkout-draft: a
     * verified order-received page is itself the conversion signal, so
     * pending (awaiting a gateway callback) and on-hold (bank transfer)
     * count. Used when building immediate[] AND when the server accepts the
     * purchase, so browser and server can never disagree.
     *
     * @param WC_Order $order Order.
     * @return bool
     */
    public static function is_countable_status( $order ) {
        if ( ! $order instanceof WC_Order ) {
            return false;
        }
        $excluded = apply_filters(
            'trackwp_purchase_excluded_statuses',
            array( 'failed', 'cancelled', 'refunded', 'trash', 'checkout-draft' ),
            $order
        );
        $normalised = array();
        foreach ( (array) $excluded as $status ) {
            $status = (string) $status;
            // Accept both 'cancelled' and 'wc-cancelled' from filters.
            $normalised[] = 0 === strpos( $status, 'wc-' ) ? substr( $status, 3 ) : $status;
        }
        return ! in_array( (string) $order->get_status(), $normalised, true );
    }

    /**
     * Deterministic event_id for an order's purchase, shared by the Pixel
     * (eventID), the page config and CAPI so Meta deduplicates every copy.
     *
     * @param int $order_id Order id.
     * @return string evt_ + 32 hex.
     */
    public static function event_id_for( $order_id ) {
        return 'evt_' . substr( hash_hmac( 'sha256', 'purchase|' . (int) $order_id, wp_salt( 'auth' ) ), 0, 32 );
    }

    /**
     * Signed order reference: "<order_id>.<24 hex HMAC>". Proves the sender
     * was shown this order's order-received page with a valid order key.
     *
     * @param int $order_id Order id.
     * @return string
     */
    public static function order_ref_for( $order_id ) {
        return (int) $order_id . '.' . substr( hash_hmac( 'sha256', 'order|' . (int) $order_id, wp_salt( 'auth' ) ), 0, 24 );
    }

    /**
     * Verify an order_ref. Constant-time comparison.
     *
     * @param mixed $order_ref Value from the /event body.
     * @return int Order id, or 0 when the reference is malformed or forged.
     */
    public static function verify_order_ref( $order_ref ) {
        if ( ! is_string( $order_ref ) || ! preg_match( '/^(\d{1,20})\.([a-f0-9]{24})$/', $order_ref, $m ) ) {
            return 0;
        }
        $order_id = (int) $m[1];
        if ( $order_id <= 0 ) {
            return 0;
        }
        return hash_equals( self::order_ref_for( $order_id ), $order_ref ) ? $order_id : 0;
    }

    /**
     * Legacy verification (R11): a cached 1.10.0 client sends purchase without
     * order_ref, but its page_url still carries WooCommerce's own order key.
     * The key resolves to an order, and that order's number must equal the
     * transaction_id the client sent.
     *
     * @param string $raw_page_url   UNCLEANED page_url from the request.
     * @param string $transaction_id ecommerce.transaction_id from the request.
     * @return int Order id, or 0.
     */
    public static function verify_legacy_purchase( $raw_page_url, $transaction_id ) {
        if ( ! function_exists( 'wc_get_order_id_by_order_key' ) || ! is_string( $raw_page_url ) ) {
            return 0;
        }
        $transaction_id = is_scalar( $transaction_id ) ? trim( (string) $transaction_id ) : '';
        if ( '' === $transaction_id ) {
            return 0;
        }
        $query = wp_parse_url( $raw_page_url, PHP_URL_QUERY );
        if ( ! is_string( $query ) || '' === $query ) {
            return 0;
        }
        parse_str( $query, $params );
        $key = isset( $params['key'] ) && is_string( $params['key'] ) ? $params['key'] : '';
        if ( ! preg_match( '/^wc_order_[A-Za-z0-9]{1,64}$/', $key ) ) {
            return 0;
        }
        $order_id = (int) wc_get_order_id_by_order_key( $key );
        if ( $order_id <= 0 ) {
            return 0;
        }
        $order = wc_get_order( $order_id );
        if ( ! $order instanceof WC_Order ) {
            return 0;
        }
        if ( ! hash_equals( (string) $order->get_order_key(), $key ) ) {
            return 0;
        }
        return hash_equals( (string) $order->get_order_number(), $transaction_id ) ? $order_id : 0;
    }

    /**
     * Resolve a purchase event to its order and build the server-side payload.
     *
     * Does NOT claim: per R9 the proxy claims only after effective consent,
     * event activation and routing have shown that at least one destination
     * will actually be tried, via
     * TrackWP_Order_Claims::claim( $result['order_id'], 'purchase', 'browser' ).
     *
     * Return shape (always an array):
     *   status    'verified'   use `overrides`, then claim
     *             'skip'       send nothing: skipped/<reason>
     *             'unverified' no order_ref and no verifiable legacy key:
     *                          treat as an ordinary event (client payload,
     *                          no claim), delivery-log detail 'unverified_purchase'
     *   reason    '' | 'invalid_order_ref' | 'order_not_countable' | 'unverified_purchase'
     *   order_id  int, 0 unless verified
     *   via       'order_ref' | 'order_key' | ''
     *   overrides array, empty unless verified. Replaces the client's fields in
     *             $event_data:
     *               event_id    string  deterministic, see event_id_for()
     *               order_ref   string
     *               value       float   per value_basis (Meta, Google Ads)
     *               currency    string  ISO 4217
     *               ecommerce   array   transaction_id (order NUMBER), items
     *                                   (discounted unit price + per-unit
     *                                   discount), coupon?, value_ga4, tax,
     *                                   shipping
     *               enhanced    array   raw K2a keys from the billing address
     *                                   (email, phone, first_name, last_name,
     *                                   city, zip, country); only when
     *                                   $context['effective']['marketing'] is
     *                                   true and customer_data_sharing is on
     *               external_id string  sha256(customer_id . ':' . site_url),
     *                                   same condition, customer_id > 0 only
     *
     * @param string $order_ref Signed reference from the /event body ('' if absent).
     * @param array  $context   {
     *     @type array  $effective      K3 effective consent (analytics, marketing).
     *     @type string $page_url       UNCLEANED page_url (legacy path, R11).
     *     @type string $transaction_id Client transaction_id (legacy path, R11).
     * }
     * @return array
     */
    public static function authoritative_purchase( $order_ref, $context = array() ) {
        $context = wp_parse_args(
            is_array( $context ) ? $context : array(),
            array(
                'effective'      => array(),
                'page_url'       => '',
                'transaction_id' => '',
            )
        );

        $result = array(
            'status'    => 'skip',
            'reason'    => 'invalid_order_ref',
            'order_id'  => 0,
            'via'       => '',
            'overrides' => array(),
        );

        if ( ! function_exists( 'wc_get_order' ) ) {
            $result['status'] = 'unverified';
            $result['reason'] = 'unverified_purchase';
            return $result;
        }

        $order_ref = is_string( $order_ref ) ? trim( $order_ref ) : '';
        if ( '' !== $order_ref ) {
            $order_id      = self::verify_order_ref( $order_ref );
            $result['via'] = 'order_ref';
            if ( ! $order_id ) {
                return $result;
            }
        } else {
            $order_id = self::verify_legacy_purchase( (string) $context['page_url'], $context['transaction_id'] );
            if ( ! $order_id ) {
                $result['status'] = 'unverified';
                $result['reason'] = 'unverified_purchase';
                return $result;
            }
            $result['via'] = 'order_key';
        }

        $order = wc_get_order( $order_id );
        if ( ! $order instanceof WC_Order ) {
            return $result;
        }
        if ( ! self::is_countable_status( $order ) ) {
            $result['reason'] = 'order_not_countable';
            return $result;
        }

        $effective        = is_array( $context['effective'] ) ? $context['effective'] : array();
        $include_enhanced = ! empty( $effective['marketing'] ) && true === $effective['marketing']
            && self::customer_data_sharing();

        $builder = new self( false );

        $result['status']    = 'verified';
        $result['reason']    = '';
        $result['order_id']  = (int) $order->get_id();
        $result['overrides'] = $builder->build_purchase_data( $order, $include_enhanced );
        return $result;
    }

    /**
     * Build the purchase data for an order. Shared by the page config and the
     * server-side authoritative path, so both carry identical values.
     *
     * @param WC_Order $order            Order.
     * @param bool     $include_enhanced Add raw `enhanced` and `external_id`.
     * @return array
     */
    public function build_purchase_data( $order, $include_enhanced = false ) {
        $settings    = $this->settings();
        $include_tax = $this->value_includes_tax( $settings['value_basis'] );
        $order_id    = (int) $order->get_id();

        $items     = array();
        $value_ga4 = 0.0;
        foreach ( $order->get_items() as $line ) {
            if ( ! $line instanceof WC_Order_Item_Product ) {
                continue;
            }
            $value_ga4 += (float) $line->get_total();
            $item       = $this->build_item_from_order_line( $line, $include_tax );
            if ( ! empty( $item ) ) {
                $items[] = $item;
            }
        }

        $ecommerce = array(
            // The ORDER NUMBER, not our event_id: it is what makes GA4 and
            // Google Ads deduplicate the same order, and what a later refund
            // can be matched against.
            'transaction_id' => (string) $order->get_order_number(),
            'items'          => $items,
            // GA4 revenue is the net of the product lines; tax and shipping
            // travel separately (R15).
            'value_ga4'      => round( max( 0, $value_ga4 ), 2 ),
            'tax'            => round( max( 0, (float) $order->get_total_tax() ), 2 ),
            'shipping'       => round( max( 0, (float) $order->get_shipping_total() ), 2 ),
        );

        $coupons = $order->get_coupon_codes();
        if ( ! empty( $coupons ) ) {
            $ecommerce['coupon'] = (string) reset( $coupons );
        }

        $data = array(
            'event_id'  => self::event_id_for( $order_id ),
            'order_ref' => self::order_ref_for( $order_id ),
            'value'     => $this->order_value( $order, $settings['value_basis'] ),
            'currency'  => (string) $order->get_currency(),
            'ecommerce' => $ecommerce,
        );

        if ( $include_enhanced ) {
            $enhanced = self::raw_customer_data( $order );
            if ( ! empty( $enhanced ) ) {
                $data['enhanced'] = $enhanced;
            }
            $external_id = self::external_id_for( $order );
            if ( '' !== $external_id ) {
                $data['external_id'] = $external_id;
            }
        }

        return $data;
    }

    /**
     * Raw billing data in the K2a `enhanced` shape. Normalisation and hashing
     * are left to TrackWP_Hash::normalize_enhanced() on the way out.
     *
     * @param WC_Order $order Order.
     * @return array
     */
    private static function raw_customer_data( $order ) {
        $data = array(
            'email'      => (string) $order->get_billing_email(),
            'phone'      => (string) $order->get_billing_phone(),
            'first_name' => (string) $order->get_billing_first_name(),
            'last_name'  => (string) $order->get_billing_last_name(),
            'city'       => (string) $order->get_billing_city(),
            'zip'        => (string) $order->get_billing_postcode(),
            'country'    => (string) $order->get_billing_country(),
        );
        return array_filter(
            array_map( 'trim', $data ),
            static function ( $value ) {
                return '' !== $value;
            }
        );
    }

    /**
     * Meta external_id for a registered customer: the same form TrackWP_Meta
     * derives for a logged-in user, so both paths identify one person alike.
     *
     * @param WC_Order $order Order.
     * @return string Empty for guest orders.
     */
    private static function external_id_for( $order ) {
        $customer_id = (int) $order->get_customer_id();
        if ( $customer_id <= 0 ) {
            return '';
        }
        return hash( 'sha256', $customer_id . ':' . site_url() );
    }

    /**
     * Google Enhanced Conversions user_data for gtag (K5 `ec`, K8 rules):
     * sha256_email_address, sha256_phone_number and an address with only
     * sha256_first_name, sha256_last_name, postal_code and country.
     * Field names: https://support.google.com/google-ads/answer/13262500
     *
     * @param WC_Order $order Order.
     * @return array
     */
    public static function google_user_data( $order ) {
        $raw = self::raw_customer_data( $order );
        $out = array();

        if ( ! empty( $raw['email'] ) ) {
            $hash = TrackWP_Hash::email_sha256( $raw['email'] );
            if ( '' !== $hash ) {
                $out['sha256_email_address'] = $hash;
            }
        }
        if ( ! empty( $raw['phone'] ) ) {
            // ISO-2 billing country for the trunk-prefix table (K8, R19);
            // empty falls back to default_phone_country inside TrackWP_Hash.
            $hash = TrackWP_Hash::phone_e164_sha256( $raw['phone'], isset( $raw['country'] ) ? $raw['country'] : '' );
            if ( '' !== $hash ) {
                $out['sha256_phone_number'] = $hash;
            }
        }

        $address = array();
        foreach ( array( 'first_name', 'last_name' ) as $field ) {
            if ( ! empty( $raw[ $field ] ) ) {
                // K8 Google name rule (trim, lowercase, accents kept), one
                // implementation in TrackWP_Hash.
                $hash = TrackWP_Hash::google_name_sha256( $raw[ $field ] );
                if ( '' !== $hash ) {
                    $address[ 'sha256_' . $field ] = $hash;
                }
            }
        }
        if ( ! empty( $raw['zip'] ) ) {
            $address['postal_code'] = $raw['zip'];
        }
        if ( ! empty( $raw['country'] ) ) {
            $address['country'] = strtoupper( $raw['country'] );
        }
        if ( ! empty( $address ) ) {
            $out['address'] = $address;
        }

        return $out;
    }

    /**
     * Meta Pixel advanced matching (K5 `am`): em, ph, fn, ln, ct, zp, country
     * and external_id. Hashes come from TrackWP_Hash::normalize_enhanced(),
     * the same normaliser CAPI uses, so Pixel and CAPI send identical values.
     *
     * @param WC_Order $order Order.
     * @return array
     */
    public static function meta_advanced_matching( $order ) {
        $raw    = self::raw_customer_data( $order );
        $hashed = empty( $raw ) ? array() : TrackWP_Hash::normalize_enhanced( $raw );
        $map    = array(
            'email_meta_sha256' => 'em',
            'phone_sha256'      => 'ph',
            'first_name_sha256' => 'fn',
            'last_name_sha256'  => 'ln',
            'city_sha256'       => 'ct',
            'zip_sha256'        => 'zp',
            'country_sha256'    => 'country',
        );

        $out = array();
        foreach ( $map as $from => $to ) {
            if ( ! empty( $hashed[ $from ] ) && is_string( $hashed[ $from ] ) ) {
                $out[ $to ] = $hashed[ $from ];
            }
        }
        $external_id = self::external_id_for( $order );
        if ( '' !== $external_id ) {
            $out['external_id'] = $external_id;
        }
        return $out;
    }

    /**
     * Advanced matching for `fbq('init', id, am)` on the current request.
     * Non-empty only on a verified order-received page for a countable order
     * with customer_data_sharing on. For render_meta_pixel (trackwp.php).
     *
     * @return array
     */
    public static function pixel_advanced_matching() {
        if ( null !== self::$pixel_am ) {
            return self::$pixel_am;
        }
        self::$pixel_am = array();

        $builder  = new self( false );
        $settings = $builder->settings();
        if ( ! $builder->is_available() || empty( $settings['enabled'] ) || empty( $settings['event_purchase'] ) ) {
            return self::$pixel_am;
        }
        if ( ! self::customer_data_sharing() || ! $builder->is_order_received() ) {
            return self::$pixel_am;
        }
        $order = $builder->current_received_order();
        if ( $order && self::is_countable_status( $order ) ) {
            self::$pixel_am = self::meta_advanced_matching( $order );
        }
        return self::$pixel_am;
    }

    // ------------------------------------------------------------------
    // Order-received page: caching and attribution
    // ------------------------------------------------------------------

    /**
     * Make the order-received page uncacheable (R12).
     *
     * The page carries customer hashes (ec/am) and a signed order_ref, so a
     * shared cache must never store it and a browser must never replay it.
     * WooCommerce itself sends `max-age=60` there, and nocache_headers() alone
     * does not include no-store before WP 6.8, hence the explicit header.
     *
     * @return void
     */
    public function send_nocache_headers() {
        if ( ! $this->is_order_received() ) {
            return;
        }
        if ( ! defined( 'DONOTCACHEPAGE' ) ) {
            define( 'DONOTCACHEPAGE', true );
        }
        if ( headers_sent() ) {
            return;
        }
        nocache_headers();
        header( 'Cache-Control: private, no-store, no-cache, must-revalidate, max-age=0', true );
        header( 'Pragma: no-cache', true );
    }

    /**
     * Store consent and identifiers on the order at checkout (K5, R13).
     *
     * Nothing is stored without analytics or marketing consent. Only the
     * category that was consented to contributes identifiers. Read by 1.11.0
     * (fallback delivery); 1.10.1 only captures it.
     *
     * @param int|WC_Order $order_or_id woocommerce_checkout_order_processed
     *                                  passes an id, the Store API hook an order.
     * @return void
     */
    public function save_attribution( $order_or_id ) {
        $order = $order_or_id instanceof WC_Order ? $order_or_id : wc_get_order( $order_or_id );
        if ( ! $order instanceof WC_Order || ! class_exists( 'TrackWP_Consent' ) ) {
            return;
        }

        $consent   = TrackWP_Consent::get_current_consent();
        $consent   = is_array( $consent ) ? $consent : array();
        $analytics = ! empty( $consent['statistics'] ) || ! empty( $consent['analytics'] );
        $marketing = ! empty( $consent['marketing'] );
        if ( ! $analytics && ! $marketing ) {
            return;
        }

        $attribution = array(
            'consent'     => array(
                'analytics' => $analytics,
                'marketing' => $marketing,
                'v'         => isset( $consent['v'] ) ? (int) $consent['v'] : 0,
                'id'        => isset( $consent['id'] ) && is_string( $consent['id'] ) ? substr( $consent['id'], 0, 36 ) : '',
                'ts'        => isset( $consent['ts'] ) && is_scalar( $consent['ts'] ) ? substr( (string) $consent['ts'], 0, 40 ) : '',
            ),
            'captured_at' => gmdate( 'Y-m-d\TH:i:s\Z' ),
        );

        if ( $marketing ) {
            $marketing_ids = array_merge(
                self::click_ids_from_cookie(),
                array(
                    'fbp' => self::cookie_token( '_fbp' ),
                    'fbc' => self::cookie_token( '_fbc' ),
                )
            );
            $attribution['marketing'] = array_filter( $marketing_ids, 'strlen' );
        }

        if ( $analytics ) {
            $attribution['analytics'] = array_filter(
                array(
                    'client_id'  => self::client_id_from_cookies(),
                    'session_id' => self::session_id_from_cookies(),
                ),
                'strlen'
            );
        }

        // HPOS-safe: CRUD setter plus save(), never update_post_meta.
        $order->update_meta_data( self::ORDER_META_ATTRIBUTION, $attribution );
        $order->save();
    }

    /**
     * A cookie value restricted to a safe identifier alphabet.
     *
     * @param string $name Cookie name.
     * @return string
     */
    private static function cookie_token( $name ) {
        if ( ! isset( $_COOKIE[ $name ] ) || ! is_string( $_COOKIE[ $name ] ) ) {
            return '';
        }
        $value = sanitize_text_field( wp_unslash( $_COOKIE[ $name ] ) );
        return preg_match( '/^[A-Za-z0-9_.\-]{1,200}$/', $value ) ? $value : '';
    }

    /**
     * gclid, gbraid and wbraid from the _twp_click cookie (K7:
     * {gclid?:{v,ts}, gbraid?:{v,ts}, wbraid?:{v,ts}}).
     *
     * @return array
     */
    private static function click_ids_from_cookie() {
        $out = array( 'gclid' => '', 'gbraid' => '', 'wbraid' => '' );
        if ( empty( $_COOKIE['_twp_click'] ) || ! is_string( $_COOKIE['_twp_click'] ) ) {
            return $out;
        }
        $decoded = json_decode( wp_unslash( $_COOKIE['_twp_click'] ), true );
        if ( ! is_array( $decoded ) ) {
            $decoded = json_decode( rawurldecode( wp_unslash( $_COOKIE['_twp_click'] ) ), true );
        }
        if ( ! is_array( $decoded ) ) {
            return $out;
        }
        foreach ( array_keys( $out ) as $key ) {
            $value = isset( $decoded[ $key ]['v'] ) ? $decoded[ $key ]['v'] : '';
            if ( is_string( $value ) && preg_match( '/^[A-Za-z0-9_\-]{1,200}$/', $value ) ) {
                $out[ $key ] = $value;
            }
        }
        return $out;
    }

    /**
     * GA4 client_id: TrackWP's first-party cookie, else the _ga cookie
     * ("GA1.1.<random>.<timestamp>").
     *
     * @return string
     */
    private static function client_id_from_cookies() {
        $advanced = get_option( 'trackwp_advanced', array() );
        $name     = is_array( $advanced ) && ! empty( $advanced['cookie_name'] ) ? (string) $advanced['cookie_name'] : '_twp_cid';
        $own      = self::cookie_token( $name );
        if ( preg_match( '/^\d+\.\d+$/', $own ) ) {
            return $own;
        }
        $ga = self::cookie_token( '_ga' );
        if ( preg_match( '/^GA\d\.\d\.(\d+\.\d+)$/', $ga, $m ) ) {
            return $m[1];
        }
        return '';
    }

    /**
     * GA4 session_id from _ga_<container> (GS1 "GS1.1.<sid>...." or GS2
     * "GS2.1.s<sid>$o..."), for the configured measurement id.
     *
     * @return string
     */
    private static function session_id_from_cookies() {
        $platforms = get_option( 'trackwp_platforms', array() );
        $mid       = is_array( $platforms ) && ! empty( $platforms['ga4_measurement_id'] ) ? (string) $platforms['ga4_measurement_id'] : '';
        if ( ! preg_match( '/^G-([A-Z0-9]{4,12})$/', $mid, $m ) ) {
            return '';
        }
        $name = '_ga_' . $m[1];
        if ( empty( $_COOKIE[ $name ] ) || ! is_string( $_COOKIE[ $name ] ) ) {
            return '';
        }
        $parts = explode( '.', sanitize_text_field( wp_unslash( $_COOKIE[ $name ] ) ) );
        if ( ! isset( $parts[2] ) ) {
            return '';
        }
        if ( ctype_digit( $parts[2] ) ) {
            return substr( $parts[2], 0, 12 );
        }
        return preg_match( '/^s(\d{1,12})/', $parts[2], $sm ) ? $sm[1] : '';
    }

    // ------------------------------------------------------------------
    // Events, loop map and add_to_cart recording
    // ------------------------------------------------------------------

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
        $added     = $this->ensure_woocommerce_triggers( $events, $templates );

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

    /** Option marking the one-time 1.10.1 shop-trigger migration as done. */
    const TRIGGER_MIGRATION_OPTION = 'trackwp_woo_triggers_1_10_1';

    /**
     * One-time 1.10.1 migration: every existing shop event gets a
     * `woocommerce` firing trigger if it has none.
     *
     * In 1.10.0 woocommerce.js fired shop events without looking at their
     * triggers. From 1.10.1 it asks passesPageConditions(name, 'woocommerce'),
     * which returns false for an event that has triggers but none of that
     * type -- such an event would silently stop. Adding an unconditioned
     * woocommerce trigger keeps the 1.10.0 behaviour. It runs once, so an
     * admin who later removes the trigger on purpose is not overruled.
     *
     * @param array $events    Events list, by reference.
     * @param array $templates Shop event templates keyed by name.
     * @return bool Whether $events changed.
     */
    private function ensure_woocommerce_triggers( &$events, $templates ) {
        if ( get_option( self::TRIGGER_MIGRATION_OPTION ) ) {
            return false;
        }
        update_option( self::TRIGGER_MIGRATION_OPTION, 1, false );

        $trigger = TrackWP_Conditions::triggers_from_legacy_event( array( 'trigger_type' => 'woocommerce' ) );
        if ( empty( $trigger ) ) {
            return false;
        }

        $changed = false;
        foreach ( $events as $index => $event ) {
            if ( ! is_array( $event ) || empty( $event['name'] ) || ! isset( $templates[ $event['name'] ] ) ) {
                continue;
            }
            $triggers = isset( $event['firing_triggers'] ) && is_array( $event['firing_triggers'] ) ? $event['firing_triggers'] : array();
            $has_woo  = empty( $triggers ) && isset( $event['trigger_type'] ) && 'woocommerce' === $event['trigger_type'];
            foreach ( $triggers as $existing ) {
                if ( is_array( $existing ) && isset( $existing['type'] ) && 'woocommerce' === $existing['type'] ) {
                    $has_woo = true;
                    break;
                }
            }
            if ( $has_woo ) {
                continue;
            }
            if ( empty( $triggers ) ) {
                // Legacy single-trigger row: keep its trigger, add ours.
                $triggers = TrackWP_Conditions::triggers_from_legacy_event( $event );
            }
            $triggers[]                          = $trigger[0];
            $events[ $index ]['firing_triggers'] = $triggers;
            $changed                             = true;
        }
        return $changed;
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
     * Record an add-to-cart.
     *
     * - classic AJAX (wc-ajax / admin-ajax): collected in a static list for
     *   the `trackwp_added` fragment of this same response
     * - non-AJAX form POST: stored in the WC session, replayed next render
     * - Store API (REST): skipped, the browser watcher counts it
     *
     * @param string $cart_item_key Cart item key (unused).
     * @param int    $product_id    Product id.
     * @param int    $quantity      Quantity added.
     * @param int    $variation_id  Variation id, 0 for simple products.
     * @return void
     */
    public function record_add_to_cart( $cart_item_key, $product_id, $quantity, $variation_id ) {
        unset( $cart_item_key );

        if ( ! $this->is_enabled() ) {
            return;
        }
        $settings = $this->settings();
        if ( empty( $settings['event_add_to_cart'] ) ) {
            return;
        }
        if ( $this->is_rest_request() ) {
            return;
        }

        $item = $this->build_item_from_product(
            $variation_id ? (int) $variation_id : (int) $product_id,
            max( 1, (int) $quantity )
        );
        if ( empty( $item ) ) {
            return;
        }

        $unit_price = isset( $item['price'] ) ? (float) $item['price'] : 0;
        $entry      = array(
            'items' => array( $item ),
            'value' => round( $unit_price * (int) $item['quantity'], 2 ),
        );

        if ( $this->is_ajax_request() ) {
            if ( count( self::$ajax_added ) < self::MAX_PENDING ) {
                $entry['currency']   = function_exists( 'get_woocommerce_currency' ) ? get_woocommerce_currency() : 'DKK';
                self::$ajax_added[] = $entry;
            }
            return;
        }

        if ( ! function_exists( 'WC' ) || ! WC()->session ) {
            return;
        }
        $pending = WC()->session->get( self::SESSION_KEY );
        if ( ! is_array( $pending ) ) {
            $pending = array();
        }
        if ( count( $pending ) >= self::MAX_PENDING ) {
            return;
        }
        $pending[] = $entry;
        WC()->session->set( self::SESSION_KEY, $pending );
    }

    /**
     * Backwards-compatible name for record_add_to_cart() (1.10.0).
     *
     * @param string $cart_item_key Cart item key.
     * @param int    $product_id    Product id.
     * @param int    $quantity      Quantity.
     * @param int    $variation_id  Variation id.
     * @return void
     */
    public function record_non_ajax_add_to_cart( $cart_item_key, $product_id, $quantity, $variation_id ) {
        $this->record_add_to_cart( $cart_item_key, $product_id, $quantity, $variation_id );
    }

    /**
     * Add the lines added during this AJAX request to the cart fragments.
     *
     * WooCommerce's JS treats each fragment key as a selector and replaces
     * matching elements; 'trackwp_added' matches no element, so the value
     * only travels to the added_to_cart handler. Not added when empty, so
     * plain fragment refreshes carry nothing.
     *
     * @param array $fragments Fragments.
     * @return array
     */
    public function add_cart_fragment( $fragments ) {
        if ( ! is_array( $fragments ) || empty( self::$ajax_added ) ) {
            return $fragments;
        }
        $fragments['trackwp_added'] = array_values( self::$ajax_added );
        return $fragments;
    }

    /**
     * Reset the per-request AJAX collection (tests, long-running workers).
     *
     * @return void
     */
    public static function reset_request_state() {
        self::$ajax_added = array();
        self::$pixel_am   = null;
    }

    /**
     * Is this a classic AJAX request (wc-ajax or admin-ajax)?
     *
     * @return bool
     */
    private function is_ajax_request() {
        if ( function_exists( 'wp_doing_ajax' ) && wp_doing_ajax() ) {
            return true;
        }
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only context check.
        return isset( $_REQUEST['wc-ajax'] );
    }

    /**
     * Is this a REST (Store API) request?
     *
     * @return bool
     */
    private function is_rest_request() {
        return defined( 'REST_REQUEST' ) && REST_REQUEST;
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
     * Public so the fixture generator (tests/fixtures/gen-purchase-config.php)
     * can capture the real output.
     *
     * @return array
     */
    public function build_config() {
        $settings = $this->settings();
        $advanced = get_option( 'trackwp_advanced', array() );

        $config = array(
            // Events fired once consent allows, in order.
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
            // Same gate as trackwp.js: only active together with ?trackwp_debug=1.
            'debugAllowed' => is_array( $advanced ) && ! empty( $advanced['debug_console'] ),
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

        // Lookup map for classic AJAX adds (fallback when the trackwp_added
        // fragment is missing, e.g. a theme that triggers added_to_cart itself).
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
     * The order shown on this order-received page, verified by its key.
     *
     * The order id is in the URL; without the hash_equals check on the key
     * any visitor could pull another customer's order into the page.
     *
     * @return WC_Order|null
     */
    private function current_received_order() {
        global $wp;

        $endpoint = get_option( 'woocommerce_checkout_order_received_endpoint', 'order-received' );
        $endpoint = is_string( $endpoint ) && '' !== $endpoint ? $endpoint : 'order-received';
        $order_id = 0;
        if ( isset( $wp->query_vars[ $endpoint ] ) ) {
            $order_id = absint( $wp->query_vars[ $endpoint ] );
        } elseif ( isset( $wp->query_vars['order-received'] ) ) {
            $order_id = absint( $wp->query_vars['order-received'] );
        }
        if ( ! $order_id ) {
            return null;
        }

        $order = wc_get_order( $order_id );
        if ( ! $order instanceof WC_Order ) {
            return null;
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- WooCommerce's own order-received URL parameter.
        $key = isset( $_GET['key'] ) ? wc_clean( wp_unslash( $_GET['key'] ) ) : '';
        if ( '' === $key || ! is_string( $key ) || ! hash_equals( (string) $order->get_order_key(), $key ) ) {
            return null;
        }
        return $order;
    }

    /**
     * Build the purchase entry for immediate[] on the order-received page.
     *
     * Nothing is written to the order here (no render-time flag, K5). Orders
     * in an excluded status produce nothing, so browser tags (gtag, Pixel)
     * never report a purchase the server would refuse.
     *
     * @return array
     */
    private function build_purchase_payload() {
        $order = $this->current_received_order();
        if ( ! $order || ! self::is_countable_status( $order ) ) {
            return array();
        }

        $data    = $this->build_purchase_data( $order, false );
        $payload = array(
            'event'     => 'purchase',
            'event_id'  => $data['event_id'],
            'order_ref' => $data['order_ref'],
            'value'     => $data['value'],
            'currency'  => $data['currency'],
            'ecommerce' => $data['ecommerce'],
        );

        if ( self::customer_data_sharing() ) {
            $ec = self::google_user_data( $order );
            if ( ! empty( $ec ) ) {
                $payload['ec'] = $ec;
            }
            $am = self::meta_advanced_matching( $order );
            if ( ! empty( $am ) ) {
                $payload['am'] = $am;
            }
        }

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

        $items     = array();
        $value_ga4 = 0.0;
        foreach ( $cart->get_cart() as $cart_item ) {
            $value_ga4 += isset( $cart_item['line_total'] ) ? (float) $cart_item['line_total'] : 0;
            $item       = $this->build_item_from_cart_item( $cart_item, $include_tax );
            if ( ! empty( $item ) ) {
                $items[] = $item;
            }
        }

        return array(
            'value'     => $this->cart_value( $cart, $settings['value_basis'] ),
            'currency'  => function_exists( 'get_woocommerce_currency' ) ? get_woocommerce_currency() : 'DKK',
            'ecommerce' => array(
                'items'     => $items,
                // Same split as purchase (R15): net product lines for GA4,
                // tax and shipping separately.
                'value_ga4' => round( max( 0, $value_ga4 ), 2 ),
                'tax'       => round( max( 0, (float) $cart->get_total_tax() ), 2 ),
                'shipping'  => round( max( 0, (float) $cart->get_shipping_total() ), 2 ),
            ),
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
     * `price` is the discounted unit price and `discount` the per-unit
     * reduction, which is GA4's own model: price * quantity is what was paid,
     * and the discount keeps a cheap product apart from a discounted one.
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

        // GA4: price is the DISCOUNTED unit price, discount the per-unit
        // reduction. Summing price * quantity then equals the revenue.
        // https://developers.google.com/analytics/devguides/collection/ga4/reference/events#purchase_item
        $item['price'] = round( max( 0, $gross_total ) / $quantity, 2 );

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
