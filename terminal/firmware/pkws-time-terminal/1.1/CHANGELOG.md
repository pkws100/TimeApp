# Changelog

## 1.1.3 — 2026-08-25

- Fixed offline queue retries getting stuck after the ESP32 `millis()` counter wraps after approximately 49.7 days.
- Reset carried queue retry deadlines after WLAN reconnect and TLS recovery, and made all operational absolute deadlines rollover-safe.
- Added explicit TCP connect and TLS handshake timeouts for normal and recovery HTTPS requests.
- Show a second-by-second queue retry countdown instead of an unchanged `bitte warten` screen during intentional backoff.
- Verify that a server-confirmed record has left the active FIFO before completing queue synchronization; retained `.acked` cleanup artifacts are diagnostic only and are never resent.
- Keep background queue responses in the queue state; live green/red feedback and `SHOW_RESULT` remain exclusive to a tag that was just presented locally.
- Detect failed quarantine renames for corrupt queue files and leave the sync screen through a bounded, visible storage-error retry path instead of spinning on the same record.
- Move long live-scan `Retry-After` waits into the persistent background queue, show a countdown for short retries and guard the complete live request sequence with a rollover-safe watchdog.
- Store a queue record's absolute `not_before_epoch` and a no-NTP relative fallback with verified staging/backup replacement and visible retrying boot recovery, so a restart cannot bypass a server-provided `Retry-After` delay or lose/hide the scan during the update.
- Allow an authenticated portal operator to abort a blocked operation safely or reboot: an open live scan is persisted first, existing queue data is retained, and background synchronization pauses for five minutes so maintenance remains reachable.
- Keep every blocked portal mutation visibly disabled via live status polling while leaving safe abort and safe restart available; reject an abort once restart is already pending.

## 1.1.2 — 2026-07-16

- Fixed the frozen fourth ready-screen line: it is now rendered locally as a live Europe/Berlin clock instead of retaining the config response timestamp.
- Kept the server contract unchanged: the first three ready-screen lines remain server-controlled, while `device_time` is independently formatted as UTC with `Z`.
- HTTP starts NTP in parallel without blocking config or scans; verified HTTPS still waits for valid time before TLS.
- Increased the non-blocking feedback lead-in to 180 ms and the NFC wait beep to 160 ms for clearly audible scan confirmation before the synchronous network request.
- Defined scan feedback semantics: yellow while server confirmation is pending, green only for a fully validated 2xx JSON response with `ok: true`, and red for rejection or final failure.

## 1.1.1 — 2026-07-14

- Restored the complete Firmware 1.0 functional baseline before integrating transport changes.
- Added separate `NFC_SCAN`, `SHOW_RESULT`, `TLS_RECOVERY` and `QUEUE_SYNC` states.
- Added non-formatting LittleFS handling, power-loss recovery files and individual FIFO queue records.
- Moved production trust-key provisioning to ignored `TrustConfig.local.h` and introduced shared length-delimited signing.

## 1.1.0 — 2026-07-14

- Independent firmware generation with pinned ESP32 platform/core.
- Explicit HTTP transport and strictly validated HTTPS transport with `TIME_SYNC`.
- Active/previous/factory LittleFS trust bundle lifecycle with ECDSA P-256 validation and anti-rollback.
- Restricted unauthenticated TLS recovery download, portal trust controls, expiry warnings and persistent offline scan queue.
- Transport/trust diagnostics reported to the TimeApp.
