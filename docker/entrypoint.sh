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

# Attachment storage on a mounted persistent volume (FILESYSTEM_DISK=volume).
#
# A volume is mounted owned by root, while php-fpm runs as www-data — so
# without this the first upload fails with a permission error rather than a
# useful message. Non-recursive on purpose: files inside are created by
# www-data already, and a recursive chown would walk the whole volume on every
# boot.
#
# This does NOT create the mount. If the path is missing, mkdir makes an
# ordinary container directory and the durability check in AppServiceProvider
# then refuses to boot — which is the intended outcome, because writing
# attachments into the container would lose them at the next deploy.
ATTACHMENT_ROOT="${ATTACHMENT_VOLUME_PATH:-}"
if [ -n "${ATTACHMENT_ROOT}" ]; then
    echo "[entrypoint] preparing attachment volume at ${ATTACHMENT_ROOT}"
    mkdir -p "${ATTACHMENT_ROOT}"
    chown www-data:www-data "${ATTACHMENT_ROOT}"
fi

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
    # `--isolated` stops two services racing the schema, and it takes its lock
    # from the DEFAULT CACHE STORE. On the database store — the default here —
    # that lock lives in `cache_locks`, which is itself created by a migration.
    #
    # So on a first deploy against an empty database the lock cannot be taken:
    # the table it needs is one of the tables it is guarding the creation of.
    # Passing --isolated there fails with "Base table or view not found:
    # cache_locks" and, with `set -e`, restart-loops the container.
    #
    # The probe is `migrate:status`, which exits non-zero while the migrations
    # table is absent. So the first run is unisolated and every run after it is
    # isolated — which is when isolation actually earns its keep, because that
    # is when a rollback or a second replica can overlap with a deploy.
    #
    # The residual gap is two replicas migrating a genuinely empty database at
    # the same instant. RUN_MIGRATIONS is meant to be set on exactly one
    # service (see the note above), and railway.json pins that service to one
    # replica, so nothing in this deployment can reach it.
    if php artisan migrate:status >/dev/null 2>&1; then
        echo "[entrypoint] running database migrations"
        php artisan migrate --force --isolated
    else
        echo "[entrypoint] initialising an empty database (migrating without the isolation lock)"
        php artisan migrate --force
    fi
fi

if [ "${RUN_SEEDERS:-false}" = "true" ]; then
    echo "[entrypoint] running database seeders"
    php artisan db:seed --force
fi

echo "[entrypoint] starting services"
exec "$@"
