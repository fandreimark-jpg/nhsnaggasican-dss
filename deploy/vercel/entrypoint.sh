#!/bin/sh
set -eu

: "${PORT:=8080}"
case "$PORT" in ''|*[!0-9]*) echo 'PORT must be numeric' >&2; exit 1;; esac
if [ "$PORT" -lt 1 ] || [ "$PORT" -gt 65535 ]; then
    echo 'PORT must be between 1 and 65535' >&2
    exit 1
fi
export PORT
if [ "${APP_ENV:-production}" = production ]; then
    : "${APP_KEY:?Set APP_KEY in the service variables (Railway or Vercel)}"
fi
printf 'Listen %s\n' "$PORT" > /etc/apache2/ports.conf
# A persistent volume mounted at storage/ starts EMPTY and hides the
# directories the image created, so Blade has no compiled-view path
# ("View path not found") and Apache's www-data cannot write sessions,
# logs or uploads. Recreate the tree on every start; no data is touched.
cd /var/www/html
mkdir -p storage/app/private storage/app/public storage/framework/cache/data \
    storage/framework/sessions storage/framework/views storage/logs bootstrap/cache
chown -R www-data:www-data storage/framework storage/logs storage/app bootstrap/cache
# No migrations, training, or environment-dependent build caches at startup.
exec docker-php-entrypoint "$@"
