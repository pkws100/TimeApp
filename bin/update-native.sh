#!/bin/sh
set -eu

APP_DIR=${APP_DIR:-$(CDPATH= cd -- "$(dirname -- "$0")/.." && pwd)}
COMPOSER_BIN=${COMPOSER_BIN:-composer}
PHP_BIN=${PHP_BIN:-php}
PHINX_BIN=${PHINX_BIN:-vendor/bin/phinx}
SKIP_COMPOSER=false
SKIP_MIGRATIONS=false
SKIP_SEED=false
SKIP_VERIFY=false
DEV_COMPOSER=false

usage() {
    cat <<'USAGE'
Usage: bin/update-native.sh [options]

Run this after the application code was updated on a native PHP/Apache install.
It installs Composer dependencies, runs Phinx migrations, seeds reference data
and verifies the resulting schema/permissions.

Options:
  --skip-composer    Skip composer install.
  --dev-composer     Install dev dependencies instead of production dependencies.
  --skip-migrations  Skip Phinx migrations; final verification still requires an up-to-date DB.
  --skip-seed        Skip InitialReferenceSeeder; final verification still requires current reference data.
  --skip-verify      Skip post-update verification.
  --help             Show this help.

Environment overrides:
  APP_DIR            Default: repository root inferred from this script.
  COMPOSER_BIN       Default: composer
  PHP_BIN            Default: php
  PHINX_BIN          Default: vendor/bin/phinx
USAGE
}

for arg in "$@"; do
    case "$arg" in
        --skip-composer)
            SKIP_COMPOSER=true
            ;;
        --dev-composer)
            DEV_COMPOSER=true
            ;;
        --skip-migrations)
            SKIP_MIGRATIONS=true
            ;;
        --skip-seed)
            SKIP_SEED=true
            ;;
        --skip-verify)
            SKIP_VERIFY=true
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

cd "$APP_DIR"

if [ ! -f composer.json ]; then
    echo "composer.json not found in APP_DIR: $APP_DIR" >&2
    exit 1
fi

resolve_command() {
    command_name=$1

    case "$command_name" in
        */*)
            printf '%s\n' "$command_name"
            ;;
        *)
            command -v "$command_name"
            ;;
    esac
}

if [ "$SKIP_COMPOSER" = true ]; then
    echo "== Composer install skipped =="
else
    echo "== Install Composer dependencies =="
    COMPOSER_PATH=$(resolve_command "$COMPOSER_BIN")

    if [ "$DEV_COMPOSER" = true ]; then
        "$PHP_BIN" "$COMPOSER_PATH" install --prefer-dist --no-interaction --no-progress
    else
        "$PHP_BIN" "$COMPOSER_PATH" install --no-dev --optimize-autoloader --prefer-dist --no-interaction --no-progress
    fi
fi

if [ ! -x "$PHINX_BIN" ]; then
    echo "Phinx executable not found or not executable: $PHINX_BIN" >&2
    echo "Run without --skip-composer or check COMPOSER_BIN/PHINX_BIN." >&2
    exit 1
fi

if [ "$SKIP_MIGRATIONS" = true ]; then
    echo "== Migrations skipped =="
else
    echo "== Run migrations =="
    "$PHP_BIN" "$PHINX_BIN" migrate -c phinx.php
fi

if [ "$SKIP_SEED" = true ]; then
    echo "== Reference seed skipped =="
else
    echo "== Seed reference data =="
    "$PHP_BIN" "$PHINX_BIN" seed:run -c phinx.php -s InitialReferenceSeeder
fi

if [ "$SKIP_VERIFY" = true ]; then
    echo "== Post-update verification skipped =="
else
    echo "== Verify update =="
    "$PHP_BIN" bin/verify-update.php
fi

echo "Native update completed."
