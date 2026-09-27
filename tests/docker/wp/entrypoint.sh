#!/bin/bash
# Entrypoint for the wp / wp62 e2e services: a real, installed WordPress
# site (not the phpunit test scaffolding) with WooCommerce and the TrackWP
# plugin under test, for Playwright specs (tests/e2e/*.spec.mjs).
set -eu

WEB_ROOT="/var/www/html"
DB_HOST="${WORDPRESS_DB_HOST:-db}"
DB_USER="${WORDPRESS_DB_USER:-root}"
DB_PASS="${WORDPRESS_DB_PASSWORD:-root}"
DB_NAME="${WORDPRESS_DB_NAME:-wordpress71}"
SITE_URL="${WP_SITE_URL:-http://localhost}"
WC_VERSION="${WC_VERSION:-}"
WP_VERSION="${WP_VERSION:-}"

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

if [ ! -f "$WEB_ROOT/wp-load.php" ]; then
    if [ -n "$WP_VERSION" ]; then
        echo "Downloading WordPress core $WP_VERSION..."
        wp core download --version="$WP_VERSION" --path="$WEB_ROOT" --allow-root --force
    else
        echo "Downloading WordPress core (latest)..."
        wp core download --path="$WEB_ROOT" --allow-root --force
    fi
fi

if [ ! -f "$WEB_ROOT/wp-config.php" ]; then
    wp config create --path="$WEB_ROOT" --dbname="$DB_NAME" --dbuser="$DB_USER" \
        --dbpass="$DB_PASS" --dbhost="$DB_HOST" --allow-root
fi

if ! wp core is-installed --path="$WEB_ROOT" --allow-root; then
    wp core install --path="$WEB_ROOT" --url="$SITE_URL" --title="TrackWP E2E" \
        --admin_user="${TRACKWP_WP_ADMIN_USER:-admin}" \
        --admin_password="${TRACKWP_WP_ADMIN_PASSWORD:-admin}" \
        --admin_email="admin@example.org" --skip-email --allow-root
fi

if [ -n "$WC_VERSION" ] && [ ! -f "$WEB_ROOT/wp-content/plugins/woocommerce/woocommerce.php" ]; then
    echo "Installing WooCommerce $WC_VERSION..."
    wp plugin install woocommerce --version="$WC_VERSION" --activate --path="$WEB_ROOT" --allow-root

    # Minimal shop setup so tests/e2e/helpers/checkout.mjs has something to
    # add to cart and pay for. W4 owns the real fixtures under
    # tests/fixtures/*; this is only enough for a smoke test and for W4 to
    # extend (extra products, coupons, tax rates, HPOS state) as needed.
    if ! wp post list --post_type=product --field=ID --path="$WEB_ROOT" --allow-root | grep -q .; then
        wp wc product create --path="$WEB_ROOT" --allow-root --user="${TRACKWP_WP_ADMIN_USER:-admin}" \
            --name="TrackWP Test Product" --slug="trackwp-test-product" \
            --type="simple" --regular_price="100.00" --status="publish" \
            --porcelain > /tmp/trackwp-test-product-id.txt || true
    fi

    if [ -n "${WC_HPOS:-}" ]; then
        wp option update woocommerce_custom_orders_table_enabled "$WC_HPOS" --path="$WEB_ROOT" --allow-root
    fi
fi

if [ ! -L "$WEB_ROOT/wp-content/plugins/trackwp" ] && [ -d /plugin-src ]; then
    rm -rf "$WEB_ROOT/wp-content/plugins/trackwp"
    ln -s /plugin-src "$WEB_ROOT/wp-content/plugins/trackwp"
fi
wp plugin activate trackwp --path="$WEB_ROOT" --allow-root || true

mkdir -p "$WEB_ROOT/wp-content/mu-plugins"
if [ -d /mu-plugins ]; then
    cp -f /mu-plugins/*.php "$WEB_ROOT/wp-content/mu-plugins/" 2>/dev/null || true
fi

wp rewrite structure '/%postname%/' --path="$WEB_ROOT" --allow-root || true
wp rewrite flush --path="$WEB_ROOT" --allow-root || true

exec "$@"
