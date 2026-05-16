#!/bin/sh
set -eu

COMPOSE_FILE=${COMPOSE_FILE:-docker-compose.prod.yml}
ENV_FILE=${ENV_FILE:-.env}
WEB_SERVICE=${WEB_SERVICE:-timeapp-web}
NO_BUILD=false
SKIP_MIGRATIONS=false
SKIP_SEED=false

usage() {
    cat <<'USAGE'
Usage: bin/update-prod.sh [options]

Options:
  --no-build          Skip docker compose build.
  --skip-migrations  Skip Phinx migrations.
  --skip-seed        Skip InitialReferenceSeeder.
  --help             Show this help.

Environment overrides:
  COMPOSE_FILE        Default: docker-compose.prod.yml
  ENV_FILE            Default: .env
  WEB_SERVICE         Default: timeapp-web
USAGE
}

for arg in "$@"; do
    case "$arg" in
        --no-build)
            NO_BUILD=true
            ;;
        --skip-migrations)
            SKIP_MIGRATIONS=true
            ;;
        --skip-seed)
            SKIP_SEED=true
            ;;
        --help)
            usage
            exit 0
            ;;
        *)
            echo "Unknown option: $arg" >&2
            usage >&2
            exit 1
            ;;
    esac
done

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

echo "== Validate compose config =="
compose config >/dev/null

if [ "$NO_BUILD" = true ]; then
    echo "== Build skipped =="
else
    echo "== Build images =="
    compose build
fi

echo "== Start/update stack =="
compose up -d

if [ "$SKIP_MIGRATIONS" = true ]; then
    echo "== Migrations skipped =="
else
    echo "== Run migrations =="
    compose exec -T "$WEB_SERVICE" vendor/bin/phinx migrate -c phinx.php
fi

if [ "$SKIP_SEED" = true ]; then
    echo "== Reference seed skipped =="
else
    echo "== Seed reference data =="
    compose exec -T "$WEB_SERVICE" vendor/bin/phinx seed:run -c phinx.php -s InitialReferenceSeeder
fi

echo "== Push scheduler dry-run =="
compose exec -T "$WEB_SERVICE" php bin/send-push-reminders.php --dry-run

echo "Production update completed."
