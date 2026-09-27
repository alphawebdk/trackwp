<?php
/**
 * Test-only mu-plugin: REST endpoints Playwright specs use to set up and
 * tear down state on the `wp`/`wp62` services (tests/docker/compose.yml)
 * without wp-cli shell-outs from inside the browser context. Never present
 * in the shipped plugin build.
 *
 * All routes live under the `trackwp-test/v1` namespace (distinct from the
 * plugin's own `trackwp/v1`) and require the `X-TrackWP-Test-Token` header
 * to match the `TRACKWP_TEST_TOKEN` environment variable, so a stray public
 * request can never rewrite options or manufacture orders even if this file
 * were ever deployed by mistake. If TRACKWP_TEST_TOKEN is not set, no route
 * is registered at all (fail closed).
 *
 * Routes:
 *   POST  /wp-json/trackwp-test/v1/options   body: {option_name: value, ...}
 *         Merges into existing option value when the option's current value
 *         and the given value are both arrays and the request also sends
 *         `_merge: true`; otherwise each key is a straight update_option().
 *   POST  /wp-json/trackwp-test/v1/orders    body: {status, [line_items]}
 *         Creates a WooCommerce order via wc_create_order(), returns
 *         {order_id, order_received_url}.
 *   POST  /wp-json/trackwp-test/v1/orders/(?P<id>\d+)/status  body: {status}
 *         Changes an existing order's status.
 *   POST  /wp-json/trackwp-test/v1/reset
 *         Resets every TrackWP settings option to "not set" (so code falls
 *         back to its own inline defaults, the only place those defaults
 *         are authoritatively defined -- see the settings option list in
 *         includes/class-trackwp-settings.php register_setting() calls),
 *         empties trackwp_order_claims, trackwp_consent_log and
 *         trackwp_consent_texts.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

$trackwp_test_token = getenv( 'TRACKWP_TEST_TOKEN' );
if ( ! $trackwp_test_token ) {
    return;
}

/**
 * Shared permission callback: exact match against TRACKWP_TEST_TOKEN.
 *
 * @param WP_REST_Request $request Request.
 * @return bool
 */
function trackwp_test_check_token( $request ) {
    $expected = getenv( 'TRACKWP_TEST_TOKEN' );
    $given    = $request->get_header( 'x-trackwp-test-token' );
    return is_string( $expected ) && '' !== $expected && is_string( $given ) && hash_equals( $expected, $given );
}

/**
 * POST /trackwp-test/v1/options
 *
 * @param WP_REST_Request $request Request.
 * @return WP_REST_Response
 */
function trackwp_test_handle_options( $request ) {
    $body = $request->get_json_params();
    if ( ! is_array( $body ) ) {
        return new WP_REST_Response( array( 'error' => 'body must be a JSON object' ), 400 );
    }

    $merge   = ! empty( $body['_merge'] );
    $updated = array();

    foreach ( $body as $name => $value ) {
        if ( '_merge' === $name ) {
            continue;
        }

        if ( $merge ) {
            $current = get_option( $name, array() );
            if ( is_array( $current ) && is_array( $value ) ) {
                $value = array_replace_recursive( $current, $value );
            }
        }

        update_option( $name, $value );
        $updated[ $name ] = get_option( $name );
    }

    return new WP_REST_Response( array( 'updated' => $updated ), 200 );
}

/**
 * POST /trackwp-test/v1/orders
 *
 * Body: {status: string, line_items?: [{product_id?, name?, quantity?, total?}]}
 * When no line_items are given, a single line for the "trackwp-test-product"
 * seeded by tests/docker/wp/entrypoint.sh is used (falls back to a
 * fee-only order if that product does not exist).
 *
 * @param WP_REST_Request $request Request.
 * @return WP_REST_Response
 */
function trackwp_test_handle_create_order( $request ) {
    if ( ! function_exists( 'wc_create_order' ) ) {
        return new WP_REST_Response( array( 'error' => 'WooCommerce is not active' ), 400 );
    }

    $body   = $request->get_json_params();
    $status = isset( $body['status'] ) ? sanitize_key( $body['status'] ) : 'pending';

    $order = wc_create_order();
    if ( is_wp_error( $order ) ) {
        return new WP_REST_Response( array( 'error' => $order->get_error_message() ), 500 );
    }

    $line_items = isset( $body['line_items'] ) && is_array( $body['line_items'] ) ? $body['line_items'] : array();

    if ( empty( $line_items ) ) {
        $product = get_page_by_path( 'trackwp-test-product', OBJECT, 'product' );
        if ( $product ) {
            $order->add_product( wc_get_product( $product->ID ), 1 );
        } else {
            $order->add_fee( 'trackwp-test-fee', 100.00 );
        }
    } else {
        foreach ( $line_items as $item ) {
            if ( ! empty( $item['product_id'] ) ) {
                $product = wc_get_product( (int) $item['product_id'] );
                if ( $product ) {
                    $order->add_product( $product, isset( $item['quantity'] ) ? (int) $item['quantity'] : 1 );
                    continue;
                }
            }
            $order->add_fee( isset( $item['name'] ) ? $item['name'] : 'trackwp-test-fee', isset( $item['total'] ) ? (float) $item['total'] : 100.00 );
        }
    }

    $order->set_billing_email( isset( $body['billing_email'] ) ? sanitize_email( $body['billing_email'] ) : 'test@example.org' );
    $order->calculate_totals();
    $order->set_status( $status );
    $order->save();

    return new WP_REST_Response(
        array(
            'order_id'            => $order->get_id(),
            'order_key'           => $order->get_order_key(),
            'status'              => $order->get_status(),
            'order_received_url'  => $order->get_checkout_order_received_url(),
        ),
        201
    );
}

/**
 * POST /trackwp-test/v1/orders/(?P<id>\d+)/status
 *
 * @param WP_REST_Request $request Request.
 * @return WP_REST_Response
 */
function trackwp_test_handle_order_status( $request ) {
    if ( ! function_exists( 'wc_get_order' ) ) {
        return new WP_REST_Response( array( 'error' => 'WooCommerce is not active' ), 400 );
    }

    $order_id = (int) $request->get_param( 'id' );
    $order    = wc_get_order( $order_id );
    if ( ! $order ) {
        return new WP_REST_Response( array( 'error' => "order {$order_id} not found" ), 404 );
    }

    $body   = $request->get_json_params();
    $status = isset( $body['status'] ) ? sanitize_key( $body['status'] ) : '';
    if ( '' === $status ) {
        return new WP_REST_Response( array( 'error' => 'status is required' ), 400 );
    }

    $order->set_status( $status );
    $order->save();

    return new WP_REST_Response(
        array(
            'order_id' => $order->get_id(),
            'status'   => $order->get_status(),
        ),
        200
    );
}

/**
 * POST /trackwp-test/v1/reset
 *
 * @return WP_REST_Response
 */
function trackwp_test_handle_reset( $request ) {
    global $wpdb;

    // The full set of TrackWP settings options (see register_setting() calls
    // in includes/class-trackwp-settings.php) plus the material-hash
    // baseline (PLAN-1.10.1-v4 bindende navne, 27-09-2026). Deleting them
    // (rather than writing a hand-guessed "default" shape) makes every
    // TrackWP class fall back to its own inline get_option($name, ...)
    // default, which is the only place those defaults are authoritative.
    $settings_options = array(
        'trackwp_platforms',
        'trackwp_events',
        'trackwp_consent',
        'trackwp_advanced',
        'trackwp_cookie_declarations',
        'trackwp_woocommerce',
        'trackwp_consent_material_hash',
    );
    foreach ( $settings_options as $option_name ) {
        delete_option( $option_name );
    }

    if ( class_exists( 'TrackWP_Order_Claims' ) ) {
        $table = TrackWP_Order_Claims::table_name();
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from a class constant, no user input.
        $wpdb->query( "TRUNCATE TABLE {$table}" );
    }

    if ( class_exists( 'TrackWP_Consent_Log' ) ) {
        $log_table   = TrackWP_Consent_Log::table_name();
        $texts_table = TrackWP_Consent_Log::texts_table_name();
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table names from class constants, no user input.
        $wpdb->query( "TRUNCATE TABLE {$log_table}" );
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table names from class constants, no user input.
        $wpdb->query( "TRUNCATE TABLE {$texts_table}" );
    }

    return new WP_REST_Response( array( 'reset' => true ), 200 );
}

add_action(
    'rest_api_init',
    function () {
        register_rest_route(
            'trackwp-test/v1',
            '/options',
            array(
                'methods'             => 'POST',
                'callback'            => 'trackwp_test_handle_options',
                'permission_callback' => 'trackwp_test_check_token',
            )
        );

        register_rest_route(
            'trackwp-test/v1',
            '/orders',
            array(
                'methods'             => 'POST',
                'callback'            => 'trackwp_test_handle_create_order',
                'permission_callback' => 'trackwp_test_check_token',
            )
        );

        register_rest_route(
            'trackwp-test/v1',
            '/orders/(?P<id>\d+)/status',
            array(
                'methods'             => 'POST',
                'callback'            => 'trackwp_test_handle_order_status',
                'permission_callback' => 'trackwp_test_check_token',
                'args'                => array(
                    'id' => array(
                        'validate_callback' => function ( $value ) {
                            return is_numeric( $value );
                        },
                    ),
                ),
            )
        );

        register_rest_route(
            'trackwp-test/v1',
            '/reset',
            array(
                'methods'             => 'POST',
                'callback'            => 'trackwp_test_handle_reset',
                'permission_callback' => 'trackwp_test_check_token',
            )
        );
    }
);
