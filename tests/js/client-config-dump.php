<?php
/**
 * CLI helper for tests/js/consent-gate.test.mjs (W5, review class A).
 *
 * Loads the REAL trackwp.php and prints JSON with
 * TrackWP::frontend_config() and TrackWP::consent_config() for the options
 * given as a JSON object in argv[1] ({option_name: value}), so the JS tests
 * run against exactly the types the producer emits.
 *
 * Only the WordPress functions the producers (and plugin load) call are
 * defined here, with their core semantics for the inputs used.
 *
 * Usage: php tests/js/client-config-dump.php '{"trackwp_advanced":{...}}'
 */

// phpunit.xml.dist sweeps every .php under tests/: inside WordPress this
// file must be a silent no-op.
if ( defined( 'ABSPATH' ) || 'cli' !== PHP_SAPI ) {
    return;
}

define( 'ABSPATH', __DIR__ . '/' );
define( 'WPINC', 'wp-includes' );
// The bundled plugin-update-checker (loaded by trackwp.php) needs these.
define( 'WP_PLUGIN_DIR', dirname( __DIR__, 3 ) );
define( 'WPMU_PLUGIN_DIR', dirname( __DIR__, 3 ) . '/mu-plugins' );
define( 'WP_DEBUG', false );
define( 'DAY_IN_SECONDS', 86400 );
define( 'HOUR_IN_SECONDS', 3600 );

$GLOBALS['trackwp_dump_options'] = isset( $argv[1] ) ? (array) json_decode( $argv[1], true ) : array();
$GLOBALS['wp_version']           = '7.1';

function get_option( $name, $default = false ) {
    return array_key_exists( $name, $GLOBALS['trackwp_dump_options'] ) ? $GLOBALS['trackwp_dump_options'][ $name ] : $default;
}
function update_option() { return true; }
function add_option() { return true; }
function delete_option() { return true; }
function get_transient() { return false; }
function set_transient() { return true; }
function apply_filters( $hook, $value ) { return $value; }
function do_action() {}
function add_action() { return true; }
function add_filter() { return true; }
function remove_action() { return true; }
function add_shortcode() {}
function register_activation_hook() {}
function register_deactivation_hook() {}
function register_uninstall_hook() {}
function plugin_dir_path( $file ) { return rtrim( dirname( $file ), '/\\' ) . '/'; }
function plugin_dir_url( $file ) { return 'https://example.org/wp-content/plugins/trackwp/'; }
function plugin_basename( $file ) { return 'trackwp/trackwp.php'; }
function rest_url( $path = '' ) { return 'https://example.org/wp-json/' . ltrim( $path, '/' ); }
function home_url( $path = '' ) { return 'https://example.org/' . ltrim( $path, '/' ); }
function site_url( $path = '' ) { return home_url( $path ); }
function wp_parse_url( $url, $component = -1 ) { return parse_url( $url, $component ); }
function wp_json_encode( $value, $flags = 0 ) { return json_encode( $value, $flags ); }
function sanitize_text_field( $s ) { return trim( strip_tags( (string) $s ) ); }
function sanitize_key( $s ) { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $s ) ); }
function __( $s ) { return $s; }
function esc_html__( $s ) { return $s; }
function is_admin() { return false; }
function is_multisite() { return false; }
function wp_next_scheduled() { return false; }
function wp_schedule_event() { return true; }
function did_action() { return 0; }
function load_plugin_textdomain() { return true; }
function current_user_can() { return false; }
function wp_normalize_path( $p ) { return str_replace( chr( 92 ), '/', $p ); }
function trailingslashit( $s ) { return rtrim( $s, '/' . chr( 92 ) ) . '/'; }
function untrailingslashit( $s ) { return rtrim( $s, '/' . chr( 92 ) ); }
function get_site_option( $n, $d = false ) { return get_option( $n, $d ); }
function wp_get_schedule() { return false; }
function wp_clear_scheduled_hook() {}
function get_locale() { return 'da_DK'; }
function absint( $n ) { return abs( (int) $n ); }
function sanitize_textarea_field( $s ) { return trim( strip_tags( (string) $s ) ); }
function sanitize_html_class( $s ) { return preg_replace( '/[^A-Za-z0-9_\-]/', '', (string) $s ); }
function esc_url_raw( $u ) { return (string) $u; }
function esc_url( $u ) { return (string) $u; }
function esc_attr( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); }
function esc_html( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); }
function wp_unslash( $v ) { return $v; }
function is_ssl() { return true; }
function wp_salt() { return 'client-config-dump-salt'; }
function get_bloginfo() { return ''; }
function is_email( $e ) { return (bool) filter_var( $e, FILTER_VALIDATE_EMAIL ); }
function sanitize_email( $e ) { return (string) $e; }

require dirname( __DIR__, 2 ) . '/trackwp.php';

echo json_encode(
    array(
        'frontend' => TrackWP::frontend_config(),
        'consent'  => TrackWP::consent_config(),
    )
);
