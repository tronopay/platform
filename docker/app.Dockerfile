# Production application image with separate targets for FPM and Nginx.
# The shared "builder" stage compiles PHP dependencies AND frontend assets
# once; the nginx stage copies the compiled public/ from builder, so the
# deploy is self-contained — no host-side PHP or asset tools required.
#
# Build:  docker compose -f docker-compose.hosting.yaml build

########## PHP base runtime ##########
FROM php:8.5-fpm-alpine3.24 AS php_base
RUN apk add --no-cache libpq-dev gmp-dev \
    freetype-dev libjpeg-turbo-dev libpng-dev libwebp-dev \
    && docker-php-ext-configure pgsql --with-pgsql=/usr/local/pgsql \
    && docker-php-ext-configure gmp \
    && docker-php-ext-configure gd --with-freetype --with-jpeg --with-webp \
    && docker-php-ext-install pdo_pgsql gmp bcmath gd
WORKDIR /app

########## Builder (composer install + frontend asset compilation) ##########
FROM php_base AS builder
ENV COMPOSER_ALLOW_SUPERUSER=1
RUN curl -sS https://getcomposer.org/installer | php -- --install-dir=/bin --filename=composer --version=2.10.3 --quiet
COPY ./composer.json ./composer.lock ./
RUN composer install --no-dev --prefer-dist --no-progress --no-scripts --optimize-autoloader \
    && rm -rf /root/.composer/cache
COPY ./ ./
RUN rm -rf tests
ENV APP_ENV=prod
RUN composer run-script post-install-cmd \
    && php bin/console asset-map:compile --no-interaction

########## PHP-FPM runtime ##########
FROM php_base AS prod
ENV APP_ENV=prod APP_DEBUG=0
RUN mv $PHP_INI_DIR/php.ini-production $PHP_INI_DIR/php.ini
COPY --from=builder --chown=www-data:www-data /app /app
RUN mkdir -p var/cache var/log \
    && chown -R www-data:www-data var
# Apply Doctrine migrations on startup when RUN_MIGRATIONS=1 (web php-fpm only).
COPY docker/php-fpm/entrypoint.sh /usr/local/bin/docker-php-entrypoint-migrate
RUN chmod 0755 /usr/local/bin/docker-php-entrypoint-migrate
USER www-data
EXPOSE 9000
ENTRYPOINT ["/usr/local/bin/docker-php-entrypoint-migrate"]
CMD ["/usr/local/sbin/php-fpm", "-F"]

########## Nginx (static files + reverse proxy to FPM) ##########
FROM nginx:1.31-alpine3.24 AS nginx
RUN apk add --no-cache curl
COPY ./docker/nginx/conf.d /etc/nginx/conf.d
WORKDIR /app
# Compiled frontend assets (public/assets, public/bundles) come from the
# builder stage — no host-side PHP or asset-map:compile step needed.
COPY --from=builder /app/public ./public
HEALTHCHECK --interval=5s --timeout=3s --start-period=1s CMD curl --fail http://127.0.0.1/health || exit 1