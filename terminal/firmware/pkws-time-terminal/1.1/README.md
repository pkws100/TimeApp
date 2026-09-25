# PK-WS TimeApp Terminal Firmware 1.1

Firmware 1.1.6 is rebuilt from the complete frozen Firmware 1.0 baseline. It retains the RC522/LCD/LED/buzzer/setup-button workflow, captive portal, WLAN diagnostics and non-blocking display logic, then adds controlled HTTP/HTTPS transport, trust management and an offline queue. The URL scheme is an explicit security boundary: there is no HTTPS-to-HTTP fallback.

## Build / flash

```bash
cd terminal/firmware/pkws-time-terminal/1.1
pio run
pio run -t upload
```

It pins `espressif32@7.0.1` and `framework-arduinoespressif32@3.20017.241212` (Arduino-ESP32 2.0.17). The Arduino IDE wrapper includes only `../../src/main.cpp` in this 1.1 directory.

Before a production build, copy `include/TrustConfig.example.h` to the ignored `include/TrustConfig.local.h`, insert the offline PK-WS P-256 public verification key, and set `PKWS_TRUST_CONFIGURED` to `1`. Also copy `include/ProvisioningConfig.example.h` to ignored `include/ProvisioningConfig.local.h`, set unique portal credentials **and a unique `PKWS_PROVISIONING_ID`**, and set `PKWS_PROVISIONING_CONFIGURED` to `1`. Missing local headers or unset confirmation macros are deliberate build errors; known placeholders additionally produce a local `SECURITY CONFIG ERROR` boot lock. Test fixtures are never used by a production build. The local portal remains reachable on the terminal LAN only for diagnosis and configuration; treat it as an administrative surface.

For a PlatformIO-free build and flash with Arduino IDE 2.x, follow the dedicated
[Arduino IDE flash guide](docs/arduino-ide-flash.md). It pins the compatible
ESP32 core, required libraries, board settings and post-flash checks.

For reproducible CI/review builds without any local production configuration,
run `PIO_CMD=pio sh terminal/firmware/pkws-time-terminal/build-test.sh`. It builds
1.0, the explicit `esp32doit-devkit-v1-test` environment and runs the native
decision-logic tests. PlatformIO Core, the 1.1 platform, native test platform and
all direct libraries are version-pinned; the script explicitly installs the
historically verified 1.0 libraries without modifying its frozen source tree.
The same command is enforced by GitHub CI. The test firmware
uses only the tracked `test-config/` public test key and test portal credentials;
it must never be flashed into production.

## Transport and time

- The fourth ready-screen line is always a local `Europe/Berlin` clock in `dd.mm.yyyy HH:mm`; before NTP it shows `--.--.---- --:--`. The API config response still controls only the first three persistent ready-screen lines.
- `device_time` is always independently rendered from epoch time with `gmtime_r()` as UTC (`...Z`); local Berlin time is never labelled as UTC.
- `http://192.168.1.10`: normal `WiFiClient`; NTP starts in parallel and never blocks config or scans; portal marks it unencrypted.
- `https://terminal-api.pk-ws.de`: `WiFiClientSecure` plus CA and hostname validation; `TIME_SYNC` must obtain a plausible NTP time first.
- Unsupported URL schemes are rejected. TLS failure never emits terminal headers, tokens, NFC UIDs, or booking data through HTTP.

## Trust bundles and recovery

LittleFS is mounted with `begin(false)` only; it is never automatically formatted. `trust-active`, `trust-previous`, `trust-staging`, `trust-new` and `trust-old-pending` provide recoverable, power-loss-safe installation. A candidate that fails its real HTTPS verification is moved to `trust-unverified-candidate` and is never treated as active or previous trust. The portal only reports this quarantine and permits an explicitly confirmed deletion. The factory fallback is versioned in `FactoryTrust.h`; the public ECDSA verifier is supplied by the ignored local header. No private key is present in firmware or production configuration.

During normal operation new bundles are downloaded only with verified HTTPS (at start/reconnect and no more than once per 24 hours). A TLS trust failure may use `setInsecure()` only for the fixed same-origin public `/api/v1/terminal/trust-bundle` GET: it has no body, no terminal ID, no bearer token and no NFC data; the returned payload is accepted only after local ECDSA verification. The portal permits a signed upload, restoring the previous bundle, or factory fallback after local login and a per-boot form token.

## Offline scans

Up to 64 scans are stored as individual atomically created records. Every record retains its `request_id`; it is removed only after a successful server response, preserving server-side idempotency. TLS and WLAN failures persist the current scan before recovery/retry. Queue synchronization transfers one record at a time between normal loop cycles.

Queue directory entries are always reopened and mutated through their full LittleFS path. The sequence parser accepts the basename returned by Arduino-ESP32 2.0.17 as well as a full path, and malformed filenames are quarantined instead of keeping the terminal in `QUEUE_SYNC`. The authenticated status response and serial output expose the active queue phase for field diagnosis.

Queue, reconnect and display deadlines are safe across the ESP32 `millis()` rollover after approximately 49.7 days. Queue retry deadlines are reset after reconnect or TLS recovery, and an intentional retry wait is shown as a countdown. TCP connection establishment, TLS handshakes and response reads all have explicit bounds. Every live scan is atomically journaled before its first POST. A 90-second ESP32 task watchdog is armed only around requests whose payload and request ID are already persistent. If a lower network layer nevertheless stops returning, reboot retains the record and reports the reset reason. Automatic replay is then persistently blocked until an authenticated administrator verifies terminal access and explicitly unblocks it, preventing a recurring reboot loop; new tags receive a visible `nicht gebucht` warning while blocked.

Automatic replay is limited to records from the current `Europe/Berlin` calendar day because the server currently assigns terminal scans to its current work day. The day is checked again immediately before every POST, including retries, and sends are deferred during the final two minutes before Berlin midnight. Previous-day and malformed timestamps are never posted as a new booking; the original record is moved with restartable verification to the rejected queue for authenticated administrator review. A total queue-operation watchdog pauses a problematic synchronization for five minutes and restores normal terminal use. The portal shows the active attempt, elapsed time and queue-specific error; rejected entries include their original booking time, original queue reason and rejection reason but do not expose the NFC UID.

Long server-directed waits and every failed first attempt are moved out of the foreground scan flow so the terminal remains usable. The authenticated local portal offers a safe recovery abort and reboot while busy: volatile scan data must be persisted first, active queue files are retained, and destructive formatting stays locked until the busy operation has ended.

HTTP 408, 425, 429 and 5xx responses are temporary. A numeric `Retry-After` value on HTTP 429 is honored between 1 and 900 seconds; HTTP-date values are deliberately not interpreted. Long waits are stored as an absolute `not_before_epoch` plus a conservative relative fallback for HTTP operation without valid NTP time. Existing queue files use a recoverable staging/backup replacement, so a terminal restart cannot send the record early or lose it during the metadata update. Global terminal failures (`401`, `403`, `terminal_auth_required`, `terminal_auth_failed`, `terminal_disabled`, `terminal_unknown`, `terminal_ip_denied`, `terminal_storage_missing`, `feature_disabled`) keep the current record active and persistently block all automatic queue work. Only a successful authenticated config request with the current terminal identity can clear that block. Data-specific codes (`nfc_tag_invalid`, `nfc_tag_not_found`, `employee_mapping_invalid`, `nfc_uid_missing`, `invalid_uid`, `unknown_tag`, `unassigned_tag`) are moved to a reread-and-verified dead-letter record before the active file is removed. Unknown permanent failures conservatively block the queue.

## Scan feedback

While the terminal is idly showing the ready screen and waiting for an NFC tag, the LCD backlight switches off after 15 seconds. Reading a tag wakes it immediately. Setup, network activity, scan processing, result screens, temporary warnings and error recovery always keep the backlight on; a fresh 15-second window begins only after the terminal returns to NFC waiting.

- **Yellow:** The NFC tag was read locally; server confirmation is still pending.
- **Green:** The TimeApp confirmed the concrete booking. This requires a 2xx HTTP status, a fully read and valid JSON response, and explicit `ok: true`.
- **Red:** The booking was rejected or reached a final error state.

After a tag read, the short wait beep is allowed to finish non-blockingly before
the synchronous network request starts. The buzzer remains silent while that
request is in progress. A locally stored offline scan, an incomplete response,
`ok: false`, or a non-2xx response never produces green or the success pattern.

Config, scan, recovery and portal API-test responses use bounded reads with content-length, total-time and idle-time limits. Trust and storage mutations return HTTP 409 while a live scan, queue synchronization or TLS recovery owns the state.

## Signed payload protocol

The PHP signer and firmware sign the same UTF-8 block: a magic line followed by fixed-order `name:length` fields and their byte values. PEM line endings are normalized to LF before the certificate fields are written. This avoids hand-written JSON escaping rules. Interoperability vectors reside in `tests/fixtures/terminal-trust/`.

## Nginx Proxy Manager

Create DNS `A` record `terminal-api.pk-ws.de` to the fixed VPS address. In Nginx Proxy Manager configure the proxy host with forward scheme `http`, the internal TimeApp host/port, a Let’s Encrypt certificate, and **Force SSL**. Configure the terminal directly with `https://terminal-api.pk-ws.de`, never an HTTP URL that relies on redirecting. The server intentionally uses `REMOTE_ADDR` for terminal allowlists; it does not trust arbitrary `X-Forwarded-For` or `X-Real-IP` headers. Configure an allowlist only when the proxy's observed source address is stable; leaving it empty is supported.

See [the test plan](docs/testplan.md) and [the trust-bundle tool](../../../../tools/terminal-trust-bundle/README.md).
