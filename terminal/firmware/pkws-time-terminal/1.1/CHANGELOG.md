# Changelog

## 1.1.0 — 2026-07-14

- Independent firmware generation with pinned ESP32 platform/core.
- Explicit HTTP transport and strictly validated HTTPS transport with `TIME_SYNC`.
- Active/previous/factory LittleFS trust bundle lifecycle with ECDSA P-256 validation and anti-rollback.
- Restricted unauthenticated TLS recovery download, portal trust controls, expiry warnings and persistent offline scan queue.
- Transport/trust diagnostics reported to the TimeApp.
