<?php
/**
 * Fixture generator: dumps the client config TrackWP_WooCommerce would
 * print on a real order-received page for a given order, so
 * test-woocommerce-purchase.php (W4) can assert against real output
 * instead of a hand-built guess. Writes tests/fixtures/purchase-config.json.
 *
 * SKELETON against the CURRENT code: TrackWP_WooCommerce::build_config()
 * (includes/class-trackwp-woocommerce.php) is a private instance method
 * gated on $this->is_order_received(), which reads the live $wp_query /
 * $wp->query_vars, so this script simulates being on that page rather than
 * calling build_config() directly. Per 2.3/K5, W4 is expected to add a
 * public, request-independent entry point (authoritative_purchase() or
 * similar); once that lands, replace the WP_Query simulation below with a
 * direct call and drop the Reflection use.
 *
 * Usage (inside the wp/wp62 Docker service, or any WP install with
 * TrackWP + WooCommerce active and wp-cli available):
 *   wp eval-file tests/fixtures/gen-purchase-config.php <order_id> [output_path]
 */

// This file lives under tests/, so PHPUnit's directory-based test discovery
// (phpunit.xml.dist, suffix=".php" over ./tests/) includes it too. It must
// therefore never exit()/die() when not run via wp-cli -- that would abort
// the whole PHPUnit process instead of just skipping this non-test file.
if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
    if ( PHP_SAPI === 'cli' && ! class_exists( 'PHPUnit\Framework\TestCase', false ) ) {
        fwrite( STDERR, "Run this via wp-cli: wp eval-file tests/fixtures/gen-purchase-config.php <order_id>\n" );
    }
    return;
}

$args        = WP_CLI::get_runner()->arguments;
$order_id    = isset( $args[2] ) ? (int) $args[2] : 0;
$output_path = isset( $args[3] ) ? $args[3] : dirname( __DIR__, 1 ) . '/fixtures/purchase-config.json';

if ( ! $order_id ) {
    WP_CLI::error( 'Usage: wp eval-file tests/fixtures/gen-purchase-config.php <order_id> [output_path]' );
}

if ( ! class_exists( 'TrackWP_WooCommerce' ) || ! function_exists( 'wc_get_order' ) ) {
    WP_CLI::error( 'TrackWP and WooCommerce must both be active.' );
}

$order = wc_get_order( $order_id );
if ( ! $order ) {
    WP_CLI::error( "Order {$order_id} not found." );
}

// Simulate being on that order's order-received page, the same way a real
// visitor's browser is when print_config()/build_config() run (see
// TrackWP_WooCommerce::is_order_received(), which delegates to WooCommerce's
// own is_order_received_page()).
global $wp, $wp_query;
$received_endpoint       = get_option( 'woocommerce_checkout_order_received_endpoint', 'order-received' );
$wp->query_vars[ $received_endpoint ] = (string) $order_id;
$_GET['key']              = $order->get_order_key();

$checkout_page_id = function_exists( 'wc_get_page_id' ) ? wc_get_page_id( 'checkout' ) : 0;
if ( $checkout_page_id > 0 ) {
    $wp_query->queried_object_id = $checkout_page_id;
    $wp_query->queried_object    = get_post( $checkout_page_id );
    $wp_query->is_page           = true;
    $wp_query->is_singular       = true;
}

$woocommerce_integration = new TrackWP_WooCommerce();
$reflection              = new ReflectionMethod( $woocommerce_integration, 'build_config' );
$reflection->setAccessible( true );
$config = $reflection->invoke( $woocommerce_integration );

$out = array(
    'generated_at' => gmdate( 'c' ),
    'generated_by' => 'tests/fixtures/gen-purchase-config.php (real TrackWP_WooCommerce::build_config() against a real WC_Order)',
    'order_id'     => $order_id,
    'config'       => $config,
);

file_put_contents( $output_path, wp_json_encode( $out, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n" );

WP_CLI::success( "Wrote {$output_path}" );
