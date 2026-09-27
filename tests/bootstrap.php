<?php
/**
 * PHPUnit bootstrap for TrackWP plugin.
 *
 * Uses wp-phpunit/wp-phpunit (installed to vendor-dev/, see composer.json
 * `config.vendor-dir`, which is kept separate from vendor/ so Plugin Update
 * Checker's shipped runtime code is never touched by `composer install`)
 * for the WordPress core test scaffolding (includes/functions.php,
 * includes/bootstrap.php, WP_UnitTestCase and friends). A real WordPress
 * core checkout still has to exist at wp-tests-config.php's ABSPATH; the
 * Docker phpunit/phpunit-legacy-orders services provide that (see
 * tests/docker/compose.yml and tests/docker/wp-tests-config.php).
 *
 * yoast/phpunit-polyfills bridges PHPUnit 9.6's assertion API to whatever
 * the installed WordPress core test base class expects.
 */

$_composer_autoload = dirname( __DIR__ ) . '/vendor-dev/autoload.php';
if ( ! file_exists( $_composer_autoload ) ) {
    echo "vendor-dev/autoload.php not found. Run `composer install` first (composer.json sets vendor-dir to vendor-dev).\n";
    exit( 1 );
}
require_once $_composer_autoload;

if ( false === getenv( 'WP_PHPUNIT__DIR' ) ) {
    putenv( 'WP_PHPUNIT__DIR=' . dirname( __DIR__ ) . '/vendor-dev/wp-phpunit/wp-phpunit' );
}
$_wp_phpunit_dir = getenv( 'WP_PHPUNIT__DIR' );

if ( false === getenv( 'WP_TESTS_PHPUNIT_POLYFILLS_PATH' ) ) {
    putenv( 'WP_TESTS_PHPUNIT_POLYFILLS_PATH=' . dirname( __DIR__ ) . '/vendor-dev/yoast/phpunit-polyfills' );
}

if ( ! file_exists( $_wp_phpunit_dir . '/includes/functions.php' ) ) {
    echo "WordPress core test suite not found at {$_wp_phpunit_dir}. Run `composer install` first.\n";
    exit( 1 );
}

require_once $_wp_phpunit_dir . '/includes/functions.php';

/**
 * Load the plugin under test, and WooCommerce when it is present, so tests
 * covering the WooCommerce integration (owned by W4) run without a special
 * bootstrap. The Docker phpunit/phpunit-legacy-orders services install
 * WooCommerce under WP_CONTENT_DIR/plugins/woocommerce; a local run without
 * WooCommerce present simply skips loading it, and WooCommerce-dependent
 * tests must guard themselves with class_exists('WooCommerce').
 */
function _trackwp_manually_load_plugin() {
    $wc_plugin = WP_CONTENT_DIR . '/plugins/woocommerce/woocommerce.php';
    if ( file_exists( $wc_plugin ) ) {
        require $wc_plugin;
        // Requiring woocommerce.php does not run its activation hook, so
        // WooCommerce's own custom tables (wc_webhooks,
        // woocommerce_attribute_taxonomies, ...) never get dbDelta'd and
        // every query against them errors. Run its installer once, on
        // 'init' (WC_Install::install() calls add_rewrite_endpoint(), which
        // needs $wp_rewrite -- only set up by 'setup_theme', so
        // 'plugins_loaded' is too early), before TrackWP's own
        // init/maybe_upgrade (priority 1) can query anything
        // WooCommerce-dependent.
        tests_add_filter( 'init', function () {
            if ( class_exists( 'WC_Install' ) ) {
                WC_Install::install();
            }
        }, -10 );
    }
    require dirname( __DIR__ ) . '/trackwp.php';
}
tests_add_filter( 'muplugins_loaded', '_trackwp_manually_load_plugin' );

/**
 * HPOS toggle. The phpunit-legacy-orders Docker service sets
 * TRACKWP_TEST_HPOS=0 so the same `--group orders` tests (owned by W4,
 * test-woocommerce-purchase.php) can run against both storage backends
 * without duplicating test files.
 */
if ( getenv( 'TRACKWP_TEST_HPOS' ) !== false ) {
    tests_add_filter( 'woocommerce_init', function () {
        $hpos_enabled = getenv( 'TRACKWP_TEST_HPOS' ) !== '0';
        update_option(
            'woocommerce_custom_orders_table_enabled',
            $hpos_enabled ? 'yes' : 'no'
        );
    } );
}

require $_wp_phpunit_dir . '/includes/bootstrap.php';
