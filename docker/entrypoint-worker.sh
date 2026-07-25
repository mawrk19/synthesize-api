#!/usr/bin/env bash
set -euo pipefail

cd /var/www/html

php artisan config:cache

exec php artisan queue:work --sleep=3 --tries=3 --timeout=300
