FROM php:8.5-fpm-alpine3.24 AS php_base
RUN apk add --no-cache libpq-dev gmp-dev \
    freetype-dev libjpeg-turbo-dev libpng-dev libwebp-dev \
    && docker-php-ext-configure pgsql --with-pgsql=/usr/local/pgsql \
    && docker-php-ext-configure gmp \
    && docker-php-ext-configure gd --with-freetype --with-jpeg --with-webp \
    && docker-php-ext-install pdo_pgsql gmp bcmath gd
WORKDIR /app

FROM php_base AS builder
ENV COMPOSER_ALLOW_SUPERUSER=1
RUN curl -sS https://getcomposer.org/installer | php -- --install-dir=/bin --filename=composer --version=2.10.3 --quiet
COPY ./composer.json ./composer.lock ./
RUN composer install --no-dev --prefer-dist --no-progress --no-suggest --no-scripts --optimize-autoloader \
    && rm -rf /root/.composer/cache
COPY ./ ./
RUN rm -rf tests
ENV APP_ENV=prod
RUN composer run-script post-install-cmd \
    && php bin/console asset-map:compile --no-interaction

### FPM ###
FROM php_base AS prod
ENV APP_ENV=prod APP_DEBUG=0
RUN mv $PHP_INI_DIR/php.ini-production $PHP_INI_DIR/php.ini
COPY --from=builder --chown=www-data:www-data /app /app
RUN mkdir -p var/cache var/log \
    && chown -R www-data:www-data var
USER www-data
EXPOSE 9000
CMD ["/usr/local/sbin/php-fpm", "-F"]
