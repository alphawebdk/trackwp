FROM wordpress:php8.3-apache

RUN curl -o /usr/local/bin/wp -L https://raw.githubusercontent.com/wp-cli/builds/gh-pages/phar/wp-cli.phar \
    && chmod +x /usr/local/bin/wp \
    && apt-get update && apt-get install -y --no-install-recommends default-mysql-client \
    && rm -rf /var/lib/apt/lists/*

COPY entrypoint.sh /usr/local/bin/trackwp-entrypoint.sh
RUN chmod +x /usr/local/bin/trackwp-entrypoint.sh

ENTRYPOINT ["/usr/local/bin/trackwp-entrypoint.sh"]
CMD ["apache2-foreground"]
