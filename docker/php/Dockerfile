FROM php:8.2.32-fpm-alpine

RUN apk add --no-cache \
    libzip-dev \
    mariadb-client \
    oniguruma-dev

RUN docker-php-ext-install -j2 \
    mbstring \
    pdo_mysql \
    zip

COPY --from=composer:2.8 /usr/bin/composer /usr/bin/composer
COPY docker/php/entrypoint.sh /usr/local/bin/gruppa-entrypoint

WORKDIR /var/www/application

ENV COMPOSER_ALLOW_SUPERUSER=1

ENTRYPOINT ["gruppa-entrypoint"]
CMD ["php-fpm"]
