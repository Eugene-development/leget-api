#!/bin/sh
set -eu
cd /var/www
# Production settings arrive through env_file and secrets; never generate keys here.
: "${APP_KEY:?Set the permanent APP_KEY before starting the container}"
if [ -s /run/secrets/jwt_secret ]; then
    JWT_SECRET=$(tr -d '\r\n' < /run/secrets/jwt_secret)
    export JWT_SECRET
fi
: "${JWT_SECRET:?Set the shared API/auth JWT secret}"

mkdir -p storage/logs storage/framework/sessions storage/framework/views storage/framework/cache/data bootstrap/cache
chown -R www-data:www-data storage bootstrap/cache
# Cache belongs to this container, never to a shared volume.
rm -f bootstrap/cache/config.php
# Compatibility for the old scheduler role; new Compose uses an explicit command.
if [ "${CONTAINER_ROLE:-}" = scheduler ]; then
    set -- php artisan schedule:work
fi
if [ "$#" -eq 0 ]; then set -- php-fpm; fi
if [ "$1" = php-fpm ]; then
    exec "$@"
fi
exec su-exec www-data "$@"
