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
    : "${APP_KEY:?Set APP_KEY in Vercel Environment Variables}"
fi
printf 'Listen %s\n' "$PORT" > /etc/apache2/ports.conf
# No migrations, training, or environment-dependent build caches at startup.
exec docker-php-entrypoint "$@"
