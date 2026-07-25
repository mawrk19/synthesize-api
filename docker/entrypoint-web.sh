#!/usr/bin/env bash
set -euo pipefail

cd /var/www/html

echo "[entrypoint] caching config and routes..."
php artisan config:clear
php artisan config:cache
php artisan route:cache

echo "[entrypoint] running migrations..."
php artisan migrate --force

if [ "${SEED_ON_DEPLOY:-false}" = "true" ]; then
  echo "[entrypoint] seeding database..."
  php artisan db:seed --force
fi

echo "[entrypoint] starting HTTP server on port ${PORT:-8000}..."
exec php artisan serve --host=0.0.0.0 --port="${PORT:-8000}"
