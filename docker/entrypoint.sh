#!/bin/sh
set -eu

# ---------------------------------------------------------------------------
# Container entrypoint.
#
# Everything here is runtime work that must NOT happen at image build time:
# configuration caching reads environment variables that only exist once the
# service is running, and migrations need a reachable database.
# ---------------------------------------------------------------------------

PORT="${PORT:-8080}"

echo "[entrypoint] binding nginx to port ${PORT}"
sed -i "s/__PORT__/${PORT}/g" /etc/nginx/nginx.conf

# Storage is ephemeral on Railway; recreate the tree on every boot so a fresh
# container never fails on a missing directory.
mkdir -p \
    storage/framework/cache/data \
    storage/framework/sessions \
    storage/framework/views \
    storage/app/private \
    storage/logs \
    bootstrap/cache
chown -R www-data:www-data storage bootstrap/cache

if [ -z "${APP_KEY:-}" ]; then
    echo "[entrypoint] FATAL: APP_KEY is not set. Generate one with 'php artisan key:generate --show'" >&2
    exit 1
fi

# Drop any cache baked into the image, then rebuild from the live environment.
php artisan config:clear >/dev/null 2>&1 || true

echo "[entrypoint] caching configuration, routes and views"
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan event:cache

# Opt-in so a rollback or a second replica cannot race the schema.
# Set RUN_MIGRATIONS=true on exactly one service, or run them from a release
# command instead.
if [ "${RUN_MIGRATIONS:-false}" = "true" ]; then
    echo "[entrypoint] running database migrations"
    php artisan migrate --force --isolated
fi

if [ "${RUN_SEEDERS:-false}" = "true" ]; then
    echo "[entrypoint] running database seeders"
    php artisan db:seed --force
fi

echo "[entrypoint] starting services"
exec "$@"
