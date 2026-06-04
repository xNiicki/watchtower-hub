#!/bin/sh
set -e

# Zero-config APP_KEY: use the provided env value, otherwise generate one and
# persist it to the storage volume so it stays stable across restarts. This is
# what lets the standalone deployment run with no .env at all.
KEY_FILE=/app/storage/app_key
if [ -z "$APP_KEY" ]; then
	if [ ! -f "$KEY_FILE" ]; then
		echo "[entrypoint] No APP_KEY provided — generating and persisting one..."
		php artisan key:generate --show >"$KEY_FILE"
	fi
	APP_KEY="$(cat "$KEY_FILE")"
	export APP_KEY
fi

echo "[entrypoint] Running migrations..."
php artisan migrate --force

echo "[entrypoint] Seeding alert rules (idempotent)..."
php artisan db:seed --class=RuleSeeder --force

echo "[entrypoint] Provisioning operator + mobile API token (idempotent)..."
php artisan watchtower:init

echo "[entrypoint] Starting FrankenPHP..."
exec frankenphp run --config /etc/caddy/Caddyfile
