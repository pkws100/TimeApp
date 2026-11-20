#!/usr/bin/env bash
set -euo pipefail
umask 077

cd -- "$(dirname -- "${BASH_SOURCE[0]}")/../.."
checkout=$(pwd -P)
source tests/Support/isolated-personnel-scanner.sh

phase='prerequisites'
fixture_root=''
database_pid=''
server_pid=''
disabled_pid=''
cleanup() {
    local result=$?
    if (( result != 0 )); then
        report_personnel_failure "$phase" "$result"
    fi
    if [[ -n "$server_pid" ]]; then
        kill -TERM -- "-$server_pid" 2>/dev/null || kill "$server_pid" 2>/dev/null || true
        wait "$server_pid" 2>/dev/null || true
    fi
    if [[ -n "$disabled_pid" ]]; then
        kill "$disabled_pid" 2>/dev/null || true
        wait "$disabled_pid" 2>/dev/null || true
    fi
    if [[ -n "$database_pid" ]]; then
        mariadb-admin --no-defaults --socket="$fixture_root/mariadb.sock" --user=root shutdown >/dev/null 2>&1 || kill "$database_pid" 2>/dev/null || true
        wait "$database_pid" 2>/dev/null || true
    fi
    local cleanup_status=0
    stop_personnel_scanner || cleanup_status=$?
    if [[ -n "$fixture_root" ]]; then
        rm -rf --one-file-system -- "$fixture_root" || cleanup_status=$?
    fi
    if (( cleanup_status != 0 )); then
        if (( result == 0 )); then
            report_personnel_failure cleanup "$cleanup_status"
            exit "$cleanup_status"
        fi
        printf 'Personnel fixture cleanup also failed (exit %s).\n' "$cleanup_status" >&2
    fi
}
trap cleanup EXIT
trap 'exit 130' INT
trap 'exit 143' TERM
trap 'exit 129' HUP

qpdf_binary=${PERSONNEL_TEST_QPDF:-$(command -v qpdf || true)}
if [[ ! -x "$qpdf_binary" ]]; then
    printf '%s\n' 'qpdf is required for the isolated personnel browser tests.' >&2
    exit 1
fi
qpdf_binary=$(realpath -- "$qpdf_binary")
fixture_id=$(php -r 'echo bin2hex(random_bytes(6));')
directory="/var/tmp/timeapp-personnel-http-$fixture_id"
mkdir -m 0700 -- "$directory"
fixture_root=$directory
mkdir -m 0700 -- "$fixture_root"/{sessions,sessions-disabled,incoming,private-store,database,database-tmp,uploads,exports,general-backup,playwright,backup}

phase='scanner-infrastructure'
start_personnel_scanner "$fixture_id"

phase='mariadb-install'
mariadb-install-db --no-defaults --datadir="$fixture_root/database" --auth-root-authentication-method=normal --skip-test-db > "$fixture_root/database-install.log" 2>&1
phase='mariadb-start'
mariadbd --no-defaults --user="$(id -un)" --datadir="$fixture_root/database" --socket="$fixture_root/mariadb.sock" --pid-file="$fixture_root/mariadb.pid" --skip-networking --tmpdir="$fixture_root/database-tmp" --innodb-flush-log-at-trx-commit=2 --log-error="$fixture_root/mariadb.log" &
database_pid=$!
for _ in $(seq 1 100); do
    if mariadb-admin --no-defaults --socket="$fixture_root/mariadb.sock" --user=root ping --silent >/dev/null 2>&1; then break; fi
    kill -0 "$database_pid" 2>/dev/null
    sleep 0.1
done
mariadb-admin --no-defaults --socket="$fixture_root/mariadb.sock" --user=root ping --silent >/dev/null

phase='fixture-prepare'
ports=$(php -r '
    $first = stream_socket_server("tcp://127.0.0.1:0");
    $second = stream_socket_server("tcp://127.0.0.1:0");
    foreach ([$first, $second] as $listener) {
        if (!is_resource($listener)) { exit(1); }
        echo explode(":", stream_socket_get_name($listener, false))[1], "\n";
    }
')
mapfile -t listeners <<< "$ports"
primary_port=${listeners[0]}
disabled_port=${listeners[1]}

php tests/Support/personnel-admin-ui-fixtures.php prepare "$fixture_root" "$primary_port" "$qpdf_binary"
phase='fixture-migrate'
vendor/bin/phinx migrate -c "$fixture_root/phinx.php" -e test > "$fixture_root/migrate.log" 2>&1
phase='fixture-seed'
vendor/bin/phinx seed:run -c "$fixture_root/phinx.php" -e test -s InitialReferenceSeeder > "$fixture_root/seed.log" 2>&1
php tests/Support/personnel-admin-ui-fixtures.php seed "$fixture_root" "$primary_port" "$qpdf_binary"

export UI_BASE_URL="http://127.0.0.1:$primary_port"
export UI_PERSONNEL_DISABLED_BASE_URL="http://127.0.0.1:$disabled_port"
export UI_PERSONNEL_FIXTURES="$fixture_root/ui.json"
export PERSONNEL_HTTP_FIXTURE_FILE="$fixture_root/fixture.json"
export PERSONNEL_HTTP_MODE=synthetic
export DB_OVERRIDE_FILE="$fixture_root/database.php"
export SESSION_SECURE_COOKIE=false
export APP_DEBUG=false
export APP_SECRET=$(php -r 'echo bin2hex(random_bytes(32));')
export SETTINGS_ENCRYPTION_KEY=$(php -r 'echo bin2hex(random_bytes(32));')
export PERSONNEL_BACKUP_ROOT="$fixture_root/backup"
UPLOAD_ROOT=$(realpath --relative-to="$checkout" -- "$fixture_root/uploads")
EXPORT_TEMP_PATH=$(realpath --relative-to="$checkout" -- "$fixture_root/exports")
export UPLOAD_ROOT EXPORT_TEMP_PATH

phase='http-infrastructure'
APP_ENV=test APP_URL="$UI_BASE_URL" PHP_CLI_SERVER_WORKERS=4 setsid php \
    -d "session.save_path=$fixture_root/sessions" -d "upload_tmp_dir=$fixture_root/incoming" \
    -d sys_temp_dir=/tmp -d post_max_size=18M -d upload_max_filesize=17M -d display_errors=0 \
    -S "127.0.0.1:$primary_port" -t "$checkout/public" "$checkout/tests/Support/php-server-router.php" \
    > "$fixture_root/app.log" 2>&1 &
server_pid=$!

cat > "$fixture_root/disabled-router.php" <<'PHP'
<?php
session_name('timeapp_personnel_disabled');
session_start();

return require getenv('UI_PERSONNEL_DISABLED_APP_ROUTER', true);
PHP
APP_ENV=production APP_URL="$UI_PERSONNEL_DISABLED_BASE_URL" UI_PERSONNEL_DISABLED_APP_ROUTER="$checkout/tests/Support/php-server-router.php" \
    php -d "session.save_path=$fixture_root/sessions-disabled" -d display_errors=0 \
    -S "127.0.0.1:$disabled_port" -t "$checkout/public" "$fixture_root/disabled-router.php" > "$fixture_root/disabled-app.log" 2>&1 &
disabled_pid=$!

http_status() {
    php -r '
        $request = curl_init($argv[1]);
        curl_setopt_array($request, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT_MS => 1000, CURLOPT_PROXY => ""]);
        curl_exec($request);
        echo curl_getinfo($request, CURLINFO_RESPONSE_CODE);
    ' "$1"
}
for _ in $(seq 1 100); do
    if [[ "$(http_status "$UI_BASE_URL/admin/personnel-documents")" == 302 ]] && \
        [[ "$(http_status "$UI_PERSONNEL_DISABLED_BASE_URL/admin/personnel-documents")" == 404 ]]; then
        break
    fi
    kill -0 "$server_pid" "$disabled_pid" 2>/dev/null
    sleep 0.1
done
if [[ "$(http_status "$UI_BASE_URL/admin/personnel-documents")" != 302 ]] || \
    [[ "$(http_status "$UI_PERSONNEL_DISABLED_BASE_URL/admin/personnel-documents")" != 404 ]]; then
    printf '%s\n' 'The isolated personnel route gate or its disabled counterpart did not reach the expected HTTP state.' >&2
    exit 1
fi

phase='playwright'
printf '%s\n' 'Isolated infrastructure ready. Starting personnel Playwright tests.'
node_modules/.bin/playwright test --config=playwright.personnel.config.js --output="$fixture_root/playwright" "$@"
