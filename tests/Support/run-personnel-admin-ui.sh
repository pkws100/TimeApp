#!/usr/bin/env bash
set -euo pipefail
umask 077

cd -- "$(dirname -- "${BASH_SOURCE[0]}")/../.."
checkout=$(pwd -P)
qpdf_binary=${PERSONNEL_TEST_QPDF:-$(command -v qpdf || true)}
clamd_binary=${PERSONNEL_TEST_CLAMD:-$(command -v clamd || true)}
if [[ ! -x "$qpdf_binary" || ! -x "$clamd_binary" ]]; then
    printf '%s\n' 'qpdf and clamd are required for the isolated personnel browser tests.' >&2
    exit 1
fi
qpdf_binary=$(realpath -- "$qpdf_binary")
clamd_binary=$(realpath -- "$clamd_binary")
fixture_id=$(php -r 'echo bin2hex(random_bytes(6));')
fixture_root="/var/tmp/timeapp-personnel-http-$fixture_id"
mkdir -m 0700 -- "$fixture_root"

database_pid=''
scanner_pid=''
server_pid=''
disabled_pid=''
cleanup() {
    local result=$?
    if [[ -n "$server_pid" ]]; then
        kill -TERM -- "-$server_pid" 2>/dev/null || kill "$server_pid" 2>/dev/null || true
        wait "$server_pid" 2>/dev/null || true
    fi
    if [[ -n "$disabled_pid" ]]; then
        kill "$disabled_pid" 2>/dev/null || true
        wait "$disabled_pid" 2>/dev/null || true
    fi
    if [[ -n "$scanner_pid" ]]; then
        kill "$scanner_pid" 2>/dev/null || true
        wait "$scanner_pid" 2>/dev/null || true
    fi
    if [[ -n "$database_pid" ]]; then
        mariadb-admin --no-defaults --socket="$fixture_root/mariadb.sock" --user=root shutdown >/dev/null 2>&1 || kill "$database_pid" 2>/dev/null || true
        wait "$database_pid" 2>/dev/null || true
    fi
    if [[ "$result" -ne 0 && "${UI_PERSONNEL_KEEP_FAILURE:-0}" == 1 ]]; then
        printf 'Isolated synthetic test diagnostics: %s\n' "$fixture_root" >&2
        return
    fi
    php -r '
        $directory = $argv[1];
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST) as $entry) {
            $entry->isDir() && !$entry->isLink() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
        }
        rmdir($directory);
    ' "$fixture_root"
}
trap cleanup EXIT
trap 'exit 130' INT
trap 'exit 143' TERM
trap 'exit 129' HUP

mkdir -m 0700 -- "$fixture_root"/{sessions,sessions-disabled,incoming,private-store,database,database-tmp,scanner,uploads,exports,general-backup,playwright,backup}
mkdir -m 0700 -- "$fixture_root/scanner/signatures"

mariadb-install-db --no-defaults --datadir="$fixture_root/database" --auth-root-authentication-method=normal --skip-test-db > "$fixture_root/database-install.log" 2>&1
mariadbd --no-defaults --user="$(id -un)" --datadir="$fixture_root/database" --socket="$fixture_root/mariadb.sock" --pid-file="$fixture_root/mariadb.pid" --skip-networking --tmpdir="$fixture_root/database-tmp" --innodb-flush-log-at-trx-commit=2 --log-error="$fixture_root/mariadb.log" &
database_pid=$!
for _ in $(seq 1 100); do
    if mariadb-admin --no-defaults --socket="$fixture_root/mariadb.sock" --user=root ping --silent >/dev/null 2>&1; then break; fi
    kill -0 "$database_pid"
    sleep 0.1
done
mariadb-admin --no-defaults --socket="$fixture_root/mariadb.sock" --user=root ping --silent >/dev/null

signature_marker='synthetic-file-for-signature-guard'
signature_hash=$(printf '%s' "$signature_marker" | sha256sum | cut -d ' ' -f 1)
printf '%s:%s:TimeApp.Synthetic\n' "$signature_hash" "${#signature_marker}" > "$fixture_root/scanner/signatures/synthetic.hsb"
cat > "$fixture_root/scanner/clamd.conf" <<EOF
Foreground yes
LocalSocket $fixture_root/scanner/clamd.sock
LocalSocketMode 600
DatabaseDirectory $fixture_root/scanner/signatures
TemporaryDirectory $fixture_root/scanner
StreamMaxLength 16M
MaxFileSize 16M
MaxScanSize 16M
AlertExceedsMax yes
EOF
"$clamd_binary" --config-file="$fixture_root/scanner/clamd.conf" > "$fixture_root/scanner/clamd.log" 2>&1 &
scanner_pid=$!
for _ in $(seq 1 100); do
    if [[ -S "$fixture_root/scanner/clamd.sock" ]]; then break; fi
    kill -0 "$scanner_pid"
    sleep 0.1
done
test -S "$fixture_root/scanner/clamd.sock"

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
vendor/bin/phinx migrate -c "$fixture_root/phinx.php" -e test > "$fixture_root/migrate.log" 2>&1
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
export UPLOAD_ROOT="../../../${fixture_root#/}/uploads"
export EXPORT_TEMP_PATH="../../../${fixture_root#/}/exports"

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
    kill -0 "$server_pid" "$disabled_pid"
    sleep 0.1
done
if [[ "$(http_status "$UI_BASE_URL/admin/personnel-documents")" != 302 ]] || \
    [[ "$(http_status "$UI_PERSONNEL_DISABLED_BASE_URL/admin/personnel-documents")" != 404 ]]; then
    printf '%s\n' 'The isolated personnel route gate or its disabled counterpart did not reach the expected HTTP state.' >&2
    exit 1
fi

node_modules/.bin/playwright test --config=playwright.personnel.config.js --output="$fixture_root/playwright" "$@"
