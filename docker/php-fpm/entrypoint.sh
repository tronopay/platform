#!/bin/sh
set -e

# Apply pending Doctrine migrations before starting the main process, but only
# when the container is asked to do so (RUN_MIGRATIONS=1). Only the web
# php-fpm service sets that flag, so migrations run exactly once per deploy and
# never race with the `worker`/`scheduler` consumers, which share the same
# image and chain through this same entrypoint with the flag unset.
if [ "${RUN_MIGRATIONS:-0}" = "1" ]; then
    echo "Applying Doctrine migrations..." >&2
    php bin/console doctrine:migrations:migrate --no-interaction --allow-no-migration
fi

# Chain into the base php image entrypoint so its own setup (php-fpm/console
# argument normalization, signal forwarding) is preserved.
exec /usr/local/bin/docker-php-entrypoint "$@"
