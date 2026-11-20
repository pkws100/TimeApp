#!/usr/bin/env bash
set -euo pipefail
umask 077

cd -- "$(dirname -- "${BASH_SOURCE[0]}")/../.."
source tests/Support/isolated-personnel-scanner.sh
phase='infrastructure'

cleanup() {
    local result=$?
    if (( result != 0 )); then
        report_personnel_failure "$phase" "$result"
    fi
    local cleanup_status=0
    stop_personnel_scanner || cleanup_status=$?
    if (( cleanup_status != 0 )); then
        if (( result == 0 )); then
            report_personnel_failure cleanup "$cleanup_status"
            exit "$cleanup_status"
        fi
        printf 'Personnel scanner cleanup also failed (exit %s).\n' "$cleanup_status" >&2
    fi
}
trap cleanup EXIT
trap 'exit 130' INT
trap 'exit 143' TERM
trap 'exit 129' HUP

fixture_id=$(php -r 'echo bin2hex(random_bytes(6));')
start_personnel_scanner "$fixture_id"
phase='phpunit'
PERSONNEL_TEST_CLAMD_SOCKET="$scanner_socket" "$@"
