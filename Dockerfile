# Watchtower Hub — FrankenPHP image (serves the API; the scheduler reuses this image).
# PHP 8.4: Laravel 13's locked Symfony 8 components require >= 8.4.1.
FROM dunglas/frankenphp:1-php8.4

# curl for the container healthcheck; PHP extensions the hub needs.
RUN apt-get update \
 && apt-get install -y --no-install-recommends curl \
 && rm -rf /var/lib/apt/lists/* \
 && install-php-extensions pdo_pgsql pgsql intl opcache zip pcntl

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /app

# Install PHP dependencies with the full app present (composer scripts need artisan).
COPY . .
RUN composer install --no-dev --optimize-autoloader --no-interaction \
 && php artisan filament:assets \
 && mkdir -p storage/framework/cache storage/framework/sessions storage/framework/views storage/logs bootstrap/cache \
 && chown -R www-data:www-data storage bootstrap/cache

COPY docker/Caddyfile /etc/caddy/Caddyfile
COPY docker/entrypoint.sh /usr/local/bin/entrypoint.sh
RUN chmod +x /usr/local/bin/entrypoint.sh

ENV SERVER_NAME=:80
EXPOSE 80

# App entrypoint runs migrations + rule seeding, then starts FrankenPHP.
# The scheduler service overrides this entrypoint (see compose.yaml).
ENTRYPOINT ["/usr/local/bin/entrypoint.sh"]
