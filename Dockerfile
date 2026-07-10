FROM composer:2 AS vendor
WORKDIR /app
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-interaction --prefer-dist --optimize-autoloader --no-scripts --ignore-platform-req=ext-pcntl --ignore-platform-req=ext-posix

FROM php:8.2-fpm-alpine
RUN apk add --no-cache git icu-libs libzip libpq oniguruma \
    && apk add --no-cache --virtual .build-deps $PHPIZE_DEPS icu-dev libzip-dev postgresql-dev \
    && docker-php-ext-install pdo_pgsql pdo_mysql intl zip pcntl \
    && pecl install redis \
    && docker-php-ext-enable redis \
    && apk del .build-deps
WORKDIR /var/www/html
COPY --from=vendor /app/vendor ./vendor
COPY . .
COPY docker/entrypoint.sh /usr/local/bin/luczor-entrypoint
RUN php artisan package:discover --ansi --no-interaction \
    && chmod +x /usr/local/bin/luczor-entrypoint \
    && chown -R www-data:www-data storage bootstrap/cache
USER www-data
EXPOSE 9000
