# syntax=docker/dockerfile:1

FROM php:8.3-cli-bookworm

RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        git \
        libpq-dev \
        libzip-dev \
        unzip \
    && docker-php-ext-install pdo_pgsql pgsql zip \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --prefer-dist --optimize-autoloader --no-interaction

COPY . .

RUN composer dump-autoload --optimize \
    && chmod +x docker/entrypoint-web.sh docker/entrypoint-worker.sh

ENV COMPOSER_ALLOW_SUPERUSER=1

EXPOSE 8000

CMD ["docker/entrypoint-web.sh"]
