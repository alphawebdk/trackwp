<?php
/**
 * CLI helper for the T3 guard tests (S11): prints the REAL PHP producer
 * output as JSON on stdout.
 *
 *   {
 *     "client_rules": TrackWP_Blocker_Rules::client_rules(compile(<option>)),
 *     "matches":      [{url, base, kind, result}]   // vectors + match.cases (not php_only)
 *   }
 *
 * <option> is argv[1] (a trackwp_blocker option as JSON) or, when omitted,
 * match.settings from tests/fixtures/blocker/url-vectors.json. home_url()
 * is the fixture's top-level "home". compile() uses the real
 * TrackWP_Consent_Profile::vendor_catalog(). result is the rule id PHP's
 * match() returns, or null.
 *
 * Usage: php tests/js/blocker-rules-dump.php ['{"rules":{...}}']
 */

// phpunit.xml.dist sweeps every .php under tests/: inside WordPress this
// file must be a silent no-op.
if ( defined( 'ABSPATH' ) || 'cli' !== PHP_SAPI ) {
    return;
}

define( 'ABSPATH', __DIR__ . '/' );

$trackwp_vectors = json_decode( file_get_contents( dirname( __DIR__ ) . '/fixtures/blocker/url-vectors.json' ), true );
$GLOBALS['trackwp_dump_home'] = rtrim( $trackwp_vectors['home'], '/' );

function home_url( $path = '' ) { return $GLOBALS['trackwp_dump_home'] . '/' . ltrim( (string) $path, '/' ); }
function plugins_url( $path = '' ) { return home_url( 'wp-content/plugins/' . ltrim( (string) $path, '/' ) ); }
function content_url( $path = '' ) { return home_url( 'wp-content/' . ltrim( (string) $path, '/' ) ); }
function includes_url( $path = '' ) { return home_url( 'wp-includes/' . ltrim( (string) $path, '/' ) ); }
function rest_url( $path = '' ) { return home_url( 'wp-json/' . ltrim( (string) $path, '/' ) ); }
function wp_parse_url( $url, $component = -1 ) { return parse_url( $url, $component ); }
function trailingslashit( $s ) { return rtrim( $s, '/\\' ) . '/'; }
function get_option( $name, $default = false ) { return $default; }
function update_option() { return true; }
function apply_filters( $hook, $value ) { return $value; }
function __( $text ) { return $text; }
function esc_html__( $text ) { return $text; }
function _x( $text ) { return $text; }
function _n( $single, $plural, $n ) { return 1 === (int) $n ? $single : $plural; }
function sanitize_key( $k ) { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $k ) ); }

$plugin_dir = dirname( __DIR__, 2 );
require_once $plugin_dir . '/includes/class-trackwp-consent-profile.php';
require_once $plugin_dir . '/includes/class-trackwp-blocker-rules.php';

$option = isset( $argv[1] ) ? json_decode( $argv[1], true ) : $trackwp_vectors['match']['settings'];
if ( ! is_array( $option ) ) {
    fwrite( STDERR, "argv[1] is not a JSON object\n" );
    exit( 2 );
}

$compiled = TrackWP_Blocker_Rules::compile( $option );
$matches  = array();
$subjects = array();
foreach ( $trackwp_vectors['vectors'] as $v ) {
    $home       = isset( $v['home'] ) ? $v['home'] : $trackwp_vectors['home'];
    $subjects[] = array( 'url' => $v['url'], 'base' => isset( $v['base'] ) ? $v['base'] : $home . '/', 'home' => $home, 'kind' => 'script' );
}
foreach ( $trackwp_vectors['match']['cases'] as $case ) {
    if ( empty( $case['php_only'] ) && isset( $case['url'] ) ) {
        $subjects[] = array( 'url' => $case['url'], 'base' => $case['base'], 'home' => $trackwp_vectors['home'], 'kind' => $case['kind'] );
    }
}
foreach ( $subjects as $s ) {
    $n         = TrackWP_Blocker_Rules::normalize_url( $s['url'], $s['base'], $s['home'] );
    $m         = '' === $n ? null : TrackWP_Blocker_Rules::match( $compiled, array( 'kind' => $s['kind'], 'url' => $n ) );
    $matches[] = array( 'url' => $s['url'], 'base' => $s['base'], 'kind' => $s['kind'], 'result' => null === $m ? null : $m['rule_id'] );
}

echo json_encode(
    array(
        'client_rules' => TrackWP_Blocker_Rules::client_rules( $compiled ),
        'matches'      => $matches,
    ),
    JSON_UNESCAPED_SLASHES
);
