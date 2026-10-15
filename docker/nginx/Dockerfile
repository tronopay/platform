FROM nginx:1.31-alpine3.24

RUN apk add --no-cache curl

COPY ./docker/nginx/conf.d /etc/nginx/conf.d

WORKDIR /app

# Static assets only — PHP requests are proxied to the php-fpm service.
# public/bundles and public/assets are generated at build time; make sure they
# exist on the host before building this image (bin/console assets:install and
# bin/console asset-map:compile).
COPY ./public ./public

HEALTHCHECK --interval=5s --timeout=3s --start-period=1s CMD curl --fail http://127.0.0.1/health || exit 1
