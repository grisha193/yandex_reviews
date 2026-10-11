#!/bin/sh
set -e

if [ ! -f .env ] && [ "${APP_ENV:-local}" != "production" ]; then
  cp .env.example .env
fi

mkdir -p database storage/framework/cache storage/framework/sessions storage/framework/views storage/logs bootstrap/cache

if [ "${DB_CONNECTION:-sqlite}" = "sqlite" ]; then
  touch "${DB_DATABASE:-database/database.sqlite}"
fi

if [ -z "${APP_KEY:-}" ] && [ -f .env ] && ! grep -q '^APP_KEY=base64:' .env; then
  php artisan key:generate --force --no-interaction
fi

if [ -z "${APP_KEY:-}" ] && { [ ! -f .env ] || ! grep -q '^APP_KEY=base64:' .env; }; then
  echo "APP_KEY is required. Set it in the hosting environment variables."
  exit 1
fi

case "${APP_KEY}" in
  base64:*) ;;
  *) export APP_KEY="base64:${APP_KEY}" ;;
esac

php artisan package:discover --ansi

if [ "${RUN_MIGRATIONS:-true}" = "true" ]; then
  php artisan migrate --seed --force --no-interaction
fi

exec "$@"
