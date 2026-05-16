#!/bin/sh
set -eu

COMPOSE_FILE=${COMPOSE_FILE:-docker-compose.prod.yml}
ENV_FILE=${ENV_FILE:-.env}
WEB_SERVICE=${WEB_SERVICE:-timeapp-web}
APPLY=false

if [ "${1:-}" = "--apply" ]; then
    APPLY=true
elif [ "$#" -gt 0 ]; then
    echo "Usage: $0 [--apply]" >&2
    echo "Without --apply the script runs read-only checks only." >&2
    exit 1
fi

if [ ! -f "$COMPOSE_FILE" ]; then
    echo "Compose file not found: $COMPOSE_FILE" >&2
    exit 1
fi

if [ ! -f "$ENV_FILE" ]; then
    echo "Env file not found: $ENV_FILE" >&2
    echo "Create it from .env.example and fill production values first." >&2
    exit 1
fi

compose() {
    docker compose -f "$COMPOSE_FILE" --env-file "$ENV_FILE" "$@"
}

echo "== Compose config =="
compose config >/dev/null

echo "== Service status =="
compose ps

echo "== PHP extension check =="
compose exec -T "$WEB_SERVICE" php -m | grep -E '^(curl|gd|pdo_mysql|zip)$'

echo "== Phinx status before migrate =="
compose exec -T "$WEB_SERVICE" vendor/bin/phinx status -c phinx.php || true

if [ "$APPLY" = true ]; then
    echo "== Migrate =="
    compose exec -T "$WEB_SERVICE" vendor/bin/phinx migrate -c phinx.php

    echo "== Seed reference data twice =="
    compose exec -T "$WEB_SERVICE" vendor/bin/phinx seed:run -c phinx.php -s InitialReferenceSeeder
    compose exec -T "$WEB_SERVICE" vendor/bin/phinx seed:run -c phinx.php -s InitialReferenceSeeder
else
    echo "== Migrate/seed skipped =="
    echo "Run $0 --apply to execute migrations and seed reference data."
fi

echo "== Scheduler dry-run =="
compose exec -T "$WEB_SERVICE" php bin/send-push-reminders.php --dry-run

APP_PORT_VALUE=${APP_PORT:-$(sed -n 's/^APP_PORT=//p' "$ENV_FILE" | tail -n 1)}
APP_PORT_VALUE=$(printf '%s' "$APP_PORT_VALUE" | tr -d "\"'")

if [ -z "$APP_PORT_VALUE" ]; then
    APP_PORT_VALUE=18080
fi

echo "== HTTP smoke checks =="
curl -fsSI "http://127.0.0.1:${APP_PORT_VALUE}/app" >/dev/null
curl -fsSI "http://127.0.0.1:${APP_PORT_VALUE}/admin/login" >/dev/null

if [ "$APPLY" = true ]; then
    echo "Production deploy checks and apply steps completed."
else
    echo "Production deploy checks completed without mutating the database."
fi
