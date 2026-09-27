FROM php:8.3-cli

RUN apt-get update && apt-get install -y --no-install-recommends \
        git \
        unzip \
        curl \
        default-mysql-client \
        libzip-dev \
        libicu-dev \
        libpng-dev \
        libjpeg-dev \
        libfreetype6-dev \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j"$(nproc)" mysqli pdo_mysql zip intl bcmath gd \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# Node is required by PHPUnit tests that execute real JS (reader_js in
# test-consent.php, the JS privacy cleaner parity check in test-privacy.php).
# Copied from the official image instead of apt's nodejs (too old on bookworm).
COPY --from=node:22-bookworm-slim /usr/local/bin/node /usr/local/bin/node

RUN curl -o /usr/local/bin/wp -L https://raw.githubusercontent.com/wp-cli/builds/gh-pages/phar/wp-cli.phar \
    && chmod +x /usr/local/bin/wp

WORKDIR /workspace

COPY entrypoint.sh /usr/local/bin/trackwp-entrypoint.sh
RUN chmod +x /usr/local/bin/trackwp-entrypoint.sh

ENTRYPOINT ["/usr/local/bin/trackwp-entrypoint.sh"]
CMD ["php", "vendor-dev/bin/phpunit"]
