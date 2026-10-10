ARG PHP_IMAGE=php:8.4-apache
FROM ${PHP_IMAGE}

# Pinned upstream release containing the WAL-reset fix. sqlite3.c SHA3 and source ID:
# https://sqlite.org/releaselog/3_53_4.html (inherits the 3.51.3 WAL-reset fix).
# Archive SHA256 pins the complete HTTPS distribution, including its build scripts.
RUN set -eux; \
    curl -fsSLo /tmp/sqlite.tar.gz https://sqlite.org/2026/sqlite-autoconf-3530400.tar.gz; \
    echo '0e9483900e92cd5de8fd48d16bf9200145a61f7fd5be542a5ac81d8a9516eb9c  /tmp/sqlite.tar.gz' | sha256sum -c -; \
    mkdir /tmp/sqlite-build; \
    tar -xzf /tmp/sqlite.tar.gz --strip-components=1 -C /tmp/sqlite-build; \
    cd /tmp/sqlite-build; \
    test "$(php -r 'echo hash_file("sha3-256", "sqlite3.c");')" = '67f423e9ebbbdc473cbc4772c872ee6b89f31fde4ed0279a5c25d5f65c043a16'; \
    CFLAGS='-O2 -DSQLITE_ENABLE_COLUMN_METADATA' ./configure --prefix=/opt/tablo-sqlite --disable-static --disable-readline; \
    make -j"$(nproc)"; \
    make install; \
    echo '/opt/tablo-sqlite/lib' > /etc/ld.so.conf.d/tablo-sqlite.conf; \
    ldconfig; \
    rm -rf /tmp/sqlite-build /tmp/sqlite.tar.gz

RUN apt-get update \
    && apt-get install -y --no-install-recommends libcurl4-openssl-dev libonig-dev libxml2-dev unzip \
    && PKG_CONFIG_PATH=/opt/tablo-sqlite/lib/pkgconfig docker-php-ext-install curl mbstring pdo_sqlite xml pcntl \
    && a2enmod rewrite \
    && rm -rf /var/lib/apt/lists/*

# This checks the library actually loaded by PDO, rather than a header or sqlite3 CLI.
COPY deploy/sqlite-runtime.php /usr/local/bin/tablo-sqlite-runtime.php
RUN php /usr/local/bin/tablo-sqlite-runtime.php \
    && ldd /usr/local/lib/php/extensions/*/pdo_sqlite.so \
    && apache2ctl configtest

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
WORKDIR /var/www/tablo
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-interaction --prefer-dist --no-scripts --no-autoloader
COPY . .
RUN composer dump-autoload --no-dev --optimize \
    && mkdir -p storage/sessions storage/views \
    && chown -R www-data:www-data storage
COPY deploy/apache.conf /etc/apache2/sites-available/000-default.conf
COPY deploy/php.ini /usr/local/etc/php/conf.d/tablo.ini

EXPOSE 80
