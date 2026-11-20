# Shared by the PHP and Playwright suites. Source from the repository root.
scanner_dir=''
scanner_pid=''
scanner_phase='scanner-configuration'
scanner_version='unknown'
scanner_exit='unavailable'

scanner_running() {
    if kill -0 "$scanner_pid" 2>/dev/null; then
        return
    fi
    scanner_exit=0
    wait "$scanner_pid" 2>/dev/null || scanner_exit=$?
    scanner_pid=''
    return 1
}

start_personnel_scanner() {
    local fixture_id=$1 binary version_output deadline entry expected_mode ready=0
    binary=${PERSONNEL_TEST_CLAMD:-$(command -v clamd || true)}
    if [[ ! -x "$binary" || ! "$fixture_id" =~ ^[a-f0-9]{12}$ ]]; then
        printf '%s\n' 'A clamd executable and an isolated scanner identifier are required.' >&2
        return 1
    fi
    # The Ubuntu package AppArmor profile permits /tmp and its own system paths.
    local directory="/tmp/timeapp-personnel-clamd-$fixture_id"
    mkdir -m 0700 -- "$directory"
    scanner_dir=$directory
    scanner_socket="$scanner_dir/clamd.sock"
    mkdir -m 0700 -- "$scanner_dir/signatures"
    php tests/Support/personnel-scanner-probe.php signatures - > "$scanner_dir/signatures/synthetic.hsb"
    test -s "$scanner_dir/signatures/synthetic.hsb"
    cat > "$scanner_dir/clamd.conf" <<EOF
Foreground yes
LocalSocket $scanner_socket
LocalSocketMode 600
DatabaseDirectory $scanner_dir/signatures
TemporaryDirectory $scanner_dir
StreamMaxLength 16M
MaxFileSize 16M
MaxScanSize 16M
AlertExceedsMax yes
EOF
    for entry in "$scanner_dir" "$scanner_dir/signatures" "$scanner_dir/signatures/synthetic.hsb" "$scanner_dir/clamd.conf"; do
        expected_mode=600
        if [[ -d "$entry" ]]; then expected_mode=700; fi
        if [[ -L "$entry" || "$(stat -c '%u:%a' -- "$entry")" != "$EUID:$expected_mode" ]]; then
            printf '%s\n' 'The isolated scanner configuration has unexpected ownership or modes.' >&2
            return 1
        fi
    done

    scanner_phase='scanner-start'
    version_output=$(timeout 2s "$binary" --config-file="$scanner_dir/clamd.conf" --version 2>/dev/null) || version_output=''
    if [[ "$version_output" =~ ^ClamAV[[:space:]]([0-9]+\.[0-9]+\.[0-9]+) ]]; then
        scanner_version=${BASH_REMATCH[1]}
    fi
    "$binary" --config-file="$scanner_dir/clamd.conf" > "$scanner_dir/clamd.log" 2>&1 &
    scanner_pid=$!
    deadline=$((SECONDS + 15))
    while (( SECONDS < deadline )); do
        scanner_running
        if [[ -S "$scanner_socket" && ! -L "$scanner_socket" && "$(stat -c '%u:%a' -- "$scanner_socket")" == "$EUID:600" ]]; then
            scanner_phase='scanner-readiness'
            if php tests/Support/personnel-scanner-probe.php ready "$scanner_socket"; then
                ready=1
                break
            fi
        fi
        sleep 0.1
    done
    scanner_running
    if (( ! ready )); then
        printf '%s\n' 'ClamAV did not answer PING on its private socket within 15 seconds.' >&2
        return 1
    fi
    scanner_phase='scanner-clean-check'
    php tests/Support/personnel-scanner-probe.php clean "$scanner_socket"
    scanner_phase='scanner-signature-check'
    php tests/Support/personnel-scanner-probe.php signature "$scanner_socket"
    scanner_running
    scanner_phase='scanner-ready'
    printf 'ClamAV %s ready: PING, clean scan and synthetic signature verified.\n' "$scanner_version"
}

personnel_diagnostics() {
    local phase=$1 status=$2
    printf 'Personnel checks failed in %s (exit %s). ClamAV version=%s, stage=%s, process exit=%s.\n' \
        "$phase" "$status" "$scanner_version" "$scanner_phase" "$scanner_exit"
    if [[ -n "$scanner_dir" && -f "$scanner_dir/clamd.log" ]]; then
        # Match scanner messages, but emit only fixed text. Even a malformed config
        # can cause clamd to echo arbitrary configuration values and filenames.
        LC_ALL=C awk '
            function report(message) {
                if (!seen[message]++ && ++count <= 10) print "ClamAV log: " message
            }
            { line = tolower($0) }
            line ~ /permission denied|operation not permitted/ { report("Permission denied while accessing a scanner resource.") }
            line ~ /open\/parse the config|parse error|unknown option/ { report("Cannot open or parse the scanner configuration.") }
            line ~ /no supported database|no database|database.*(malformed|error)|malformed database|can.t load|can.t open.*(hsb|signatures)/ { report("Cannot load the scanner signature database.") }
            line ~ /bind\(|address already in use|socket.*(failed|error)|can.t.*socket/ { report("Cannot bind or manage the local scanner socket.") }
            line ~ /temporary.*(failed|error)|can.t.*temp/ { report("Cannot access the scanner temporary directory.") }
            line ~ /out of memory|allocation failed/ { report("ClamAV could not allocate memory.") }
            line ~ /error while loading shared libraries|cannot execute/ { report("Cannot load the scanner executable or its shared libraries.") }
            line ~ /loaded [0-9]+ signatures|reading databases/ { report("ClamAV started loading or loaded signatures.") }
            line ~ /timeapp.synthetic.*found/ { report("ClamAV reported the synthetic signature.") }
            END { if (!count) print "ClamAV log: No recognized scanner message." }
        ' "$scanner_dir/clamd.log"
    fi
}

report_personnel_failure() {
    local phase=$1 status=$2 report
    if [[ -n "$scanner_pid" ]]; then
        # The wrapper has already failed. Collect any newly available process status.
        scanner_running || true
    fi
    report=$(personnel_diagnostics "$phase" "$status") || report="Personnel checks failed in $phase (exit $status)."
    printf '%s\n' "$report" >&2
    if [[ -n "${PERSONNEL_TEST_DIAGNOSTICS_FILE:-}" ]]; then
        printf '%s\n' "$report" > "$PERSONNEL_TEST_DIAGNOSTICS_FILE" || true
    fi
}

stop_personnel_scanner() {
    if [[ -n "$scanner_pid" ]]; then
        kill "$scanner_pid" 2>/dev/null || true
        wait "$scanner_pid" 2>/dev/null || true
    fi
    if [[ -n "$scanner_dir" ]]; then
        rm -rf --one-file-system -- "$scanner_dir"
    fi
}
