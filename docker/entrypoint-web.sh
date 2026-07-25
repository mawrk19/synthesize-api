#!/usr/bin/env bash
set -euo pipefail

cd /var/www/html

php artisan config:clear
php artisan config:cache
php artisan route:cache
php artisan migrate --force

if [ "${SEED_ON_DEPLOY:-false}" = "true" ]; then
  php artisan db:seed --force
fi

exec php artisan serve --host=0.0.0.0 --port="${PORT:-8000}"
