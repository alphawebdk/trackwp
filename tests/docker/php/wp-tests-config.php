<?php
/**
 * wp-tests-config.php for the phpunit / phpunit-legacy-orders Docker
 * services. Read entirely from the environment so the same file serves
 * both HPOS and legacy-orders runs (see tests/docker/compose.yml and
 * tests/docker/php/entrypoint.sh, which exports WP_TESTS_CONFIG_FILE_PATH
 * pointing here).
 */

define( 'DB_NAME', getenv( 'WP_TESTS_DB_NAME' ) ?: 'wordpress_test' );
define( 'DB_USER', getenv( 'WP_TESTS_DB_USER' ) ?: 'root' );
define( 'DB_PASSWORD', getenv( 'WP_TESTS_DB_PASSWORD' ) ?: 'root' );
define( 'DB_HOST', getenv( 'WP_TESTS_DB_HOST' ) ?: 'db' );
define( 'DB_CHARSET', 'utf8' );
define( 'DB_COLLATE', '' );

$table_prefix = getenv( 'WP_TESTS_TABLE_PREFIX' ) ?: 'wptests_';

// phpunit.xml.dist (owned by W8) also defines the WP_TESTS_* constants via
// its <php><const> block, which PHPUnit applies before this file's bootstrap
// runs -- guard against "already defined" warnings on that overlap.
if ( ! defined( 'WP_TESTS_DOMAIN' ) ) {
    define( 'WP_TESTS_DOMAIN', 'example.org' );
}
if ( ! defined( 'WP_TESTS_EMAIL' ) ) {
    define( 'WP_TESTS_EMAIL', 'admin@example.org' );
}
if ( ! defined( 'WP_TESTS_TITLE' ) ) {
    define( 'WP_TESTS_TITLE', 'TrackWP Test Site' );
}

define( 'WP_PHP_BINARY', 'php' );
define( 'WPLANG', '' );

$wp_core_dir = rtrim( getenv( 'WP_CORE_DIR' ) ?: '/tmp/wordpress-core', '/\\' ) . '/';
define( 'ABSPATH', $wp_core_dir );
