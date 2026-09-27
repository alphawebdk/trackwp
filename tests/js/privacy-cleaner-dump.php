<?php
/**
 * CLI helper for tests/js/privacy-cleaner.test.mjs.
 *
 * Loads the REAL TrackWP_Privacy class and the URL/title vectors from
 * tests/test-privacy.php, and prints JSON with cleaner_js() and the PHP
 * results for every vector, so node can run the JS cleaner against exactly
 * what clean_url()/clean_title() produce.
 *
 * Only the handful of WordPress functions TrackWP_Privacy calls are defined
 * here, with their core semantics for the inputs used (no filters are
 * registered, so apply_filters returns its value unchanged).
 */

// phpunit.xml.dist currently sweeps every .php under tests/ (see
// tests/W0-NOTES.md). Inside WordPress (ABSPATH defined) this file must be a
// silent no-op, or the function stubs below would redeclare core functions.
if ( defined( 'ABSPATH' ) || 'cli' !== PHP_SAPI ) {
    return;
}

define( 'ABSPATH', __DIR__ . '/' );

function apply_filters( $hook, $value ) {
    return $value;
}

function wp_json_encode( $value ) {
    return json_encode( $value );
}

function wp_parse_url( $url, $component = -1 ) {
    return parse_url( $url, $component );
}

if ( ! class_exists( 'WP_UnitTestCase' ) ) {
    // tests/test-privacy.php extends it; only its static vector methods are used.
    class WP_UnitTestCase {} // phpcs:ignore
}

require dirname( __DIR__, 2 ) . '/includes/class-trackwp-privacy.php';
require dirname( __DIR__ ) . '/test-privacy.php';

$urls = array();
foreach ( TrackWP_Privacy_Test::url_vectors() as $label => $vector ) {
    $extra  = $vector[1] ? TrackWP_Privacy::CLICK_ID_PARAMS : array();
    $urls[] = array(
        'label'    => $label,
        'input'    => $vector[0],
        'drop'     => (bool) $vector[1],
        'expected' => $vector[2],
        'php'      => TrackWP_Privacy::clean_url( $vector[0], $extra ),
    );
}

$titles = array();
foreach ( TrackWP_Privacy_Test::title_vectors() as $vector ) {
    $titles[] = array(
        'input' => $vector[0],
        'php'   => TrackWP_Privacy::clean_title( $vector[0] ),
    );
}

echo json_encode( array(
    'js'     => TrackWP_Privacy::cleaner_js(),
    'urls'   => $urls,
    'titles' => $titles,
) );
