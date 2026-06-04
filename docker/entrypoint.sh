#!/bin/sh
set -e

if [ -z "$APP_KEY" ]; then
	echo "ERROR: APP_KEY is not set. Generate one with:  php artisan key:generate --show" >&2
	echo "       then put it in your .env before starting the stack." >&2
	exit 1
fi

echo "[entrypoint] Running migrations..."
php artisan migrate --force

echo "[entrypoint] Seeding alert rules (idempotent)..."
php artisan db:seed --class=RuleSeeder --force

echo "[entrypoint] Provisioning operator + mobile API token (idempotent)..."
php artisan watchtower:init

echo "[entrypoint] Starting FrankenPHP..."
exec frankenphp run --config /etc/caddy/Caddyfile
