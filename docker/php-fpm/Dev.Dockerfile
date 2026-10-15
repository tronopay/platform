FROM php:8.5-fpm-alpine3.24

RUN apk add --no-cache libpq-dev gmp-dev \
    freetype-dev libjpeg-turbo-dev libpng-dev libwebp-dev \
    && docker-php-ext-configure pgsql --with-pgsql=/usr/local/pgsql \
    && docker-php-ext-configure gmp \
    && docker-php-ext-configure gd --with-freetype --with-jpeg --with-webp \
    && docker-php-ext-install pdo_pgsql gmp bcmath gd

WORKDIR /app

ENV COMPOSER_ALLOW_SUPERUSER=1
RUN curl -sS https://getcomposer.org/installer | php -- --install-dir=/bin --filename=composer --version=2.10.3 --quiet

COPY . .

ENV APP_ENV=dev
RUN composer install --prefer-dist --no-progress --no-suggest --optimize-autoloader \
    && rm -rf /root/.composer/cache

RUN mkdir -p var/cache var/log \
    && chown -R www-data:www-data var

USER www-data
EXPOSE 9000
CMD ["/usr/local/sbin/php-fpm", "-F"]
