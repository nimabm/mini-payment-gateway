# One image, one stage, no build-time choices to make.
#
# Apache with mod_php rather than nginx + php-fpm: it halves the number of
# containers, removes the config that has to keep them agreeing with each
# other, and at this scale costs nothing.
FROM php:8.3-apache

# `pdo_sqlite`, `sqlite3` and `sodium` are already compiled into the official
# image. Only OPcache needs building, and the toolchain is already present —
# without it every request re-parses the whole application.
#
# `unzip` is what Composer uses to extract packages; without it every install
# falls back to cloning from source.
RUN apt-get update \
    && apt-get install -y --no-install-recommends unzip \
    && rm -rf /var/lib/apt/lists/* \
    && docker-php-ext-install -j"$(nproc)" opcache \
    && a2enmod headers

COPY docker/apache.conf /etc/apache2/sites-available/000-default.conf
COPY docker/php.ini /usr/local/etc/php/conf.d/zz-app.ini

WORKDIR /app

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# Dependencies first, so editing application code does not reinstall them.
# Dev dependencies are included on purpose: `make test` should work in the
# image you already have, without a second build.
COPY composer.json composer.lock ./
RUN --mount=type=cache,target=/tmp/composer \
    COMPOSER_CACHE_DIR=/tmp/composer \
    COMPOSER_ALLOW_SUPERUSER=1 \
    composer install --no-interaction --no-scripts --prefer-dist

COPY . .

# The database lives outside the code tree so a rebuild never touches it.
# `var/` holds the compiled container and the Twig cache, and is excluded from
# the build context, so it has to be created here.
RUN mkdir -p /var/lib/gateway /app/var/cache \
    && chown -R www-data:www-data /app /var/lib/gateway

COPY docker/entrypoint.sh /usr/local/bin/entrypoint
RUN chmod +x /usr/local/bin/entrypoint

ENTRYPOINT ["entrypoint"]
CMD ["apache2-foreground"]
