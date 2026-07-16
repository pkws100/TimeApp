# Changelog

## 1.1.2 — 2026-07-16

- Fixed the frozen fourth ready-screen line: it is now rendered locally as a live Europe/Berlin clock instead of retaining the config response timestamp.
- Kept the server contract unchanged: the first three ready-screen lines remain server-controlled, while `device_time` is independently formatted as UTC with `Z`.
- HTTP starts NTP in parallel without blocking config or scans; verified HTTPS still waits for valid time before TLS.

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
