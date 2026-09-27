#!/bin/sh
# Entrypoint for the phpunit / phpunit-legacy-orders services.
#
# Downloads a real WordPress core + WooCommerce into a cached volume (so
# repeated `docker compose run` calls are fast), waits for the db service,
# runs `composer install` into vendor-dev/ if missing, then execs the
# container's CMD (normally `php vendor-dev/bin/phpunit`).
set -eu

WP_CORE_DIR="${WP_CORE_DIR:-/tmp/wordpress-core}"
WP_VERSION="${WP_VERSION:-7.1}"
WC_VERSION="${WC_VERSION:-}"
DB_HOST="${WP_TESTS_DB_HOST:-db}"
DB_USER="${WP_TESTS_DB_USER:-root}"
DB_PASS="${WP_TESTS_DB_PASSWORD:-root}"

# default-mysql-client (Debian) provides mariadb-admin; some base images
# still name it mysqladmin. Try both rather than guessing.
db_ping() {
    if command -v mysqladmin >/dev/null 2>&1; then
        mysqladmin ping -h"$DB_HOST" -u"$DB_USER" -p"$DB_PASS" --silent >/dev/null 2>&1
    else
        mariadb-admin ping -h"$DB_HOST" -u"$DB_USER" -p"$DB_PASS" --silent >/dev/null 2>&1
    fi
}

echo "Waiting for db ($DB_HOST)..."
i=0
until db_ping; do
    i=$((i + 1))
    if [ "$i" -gt 60 ]; then
        echo "db never became ready" >&2
        exit 1
    fi
    sleep 2
done

if [ ! -f "$WP_CORE_DIR/wp-load.php" ]; then
    echo "Downloading WordPress core $WP_VERSION into $WP_CORE_DIR..."
    wp core download --version="$WP_VERSION" --path="$WP_CORE_DIR" --skip-content --allow-root --force
    mkdir -p "$WP_CORE_DIR/wp-content/plugins" "$WP_CORE_DIR/wp-content/mu-plugins"
fi

if [ -n "$WC_VERSION" ] && [ ! -f "$WP_CORE_DIR/wp-content/plugins/woocommerce/woocommerce.php" ]; then
    # `wp plugin install` needs a live wp-config.php/DB context, which this
    # service doesn't have (it only ships the WP core *test suite*, driven
    # via wp-tests-config.php, not a real install). Download the plugin zip
    # from wordpress.org directly instead.
    echo "Installing WooCommerce $WC_VERSION..."
    curl -fsSL -o /tmp/woocommerce.zip "https://downloads.wordpress.org/plugin/woocommerce.${WC_VERSION}.zip"
    unzip -q -o /tmp/woocommerce.zip -d "$WP_CORE_DIR/wp-content/plugins/"
    rm -f /tmp/woocommerce.zip
fi

# wp-phpunit/wp-phpunit ships its own wp-tests-config.php that only reads
# WP_PHPUNIT__TESTS_CONFIG (an env var, not a PHP constant) to find the
# real one -- see vendor-dev/wp-phpunit/wp-phpunit/wp-tests-config.php.
export WP_PHPUNIT__TESTS_CONFIG="/workspace/tests/docker/php/wp-tests-config.php"
export WP_CORE_DIR
export WP_TESTS_DB_HOST="$DB_HOST"
export WP_TESTS_DB_USER="$DB_USER"
export WP_TESTS_DB_PASSWORD="$DB_PASS"

cd /workspace

if [ ! -f vendor-dev/autoload.php ]; then
    echo "Running composer install..."
    composer install --no-interaction --no-progress
fi

exec "$@"
