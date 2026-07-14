# PK-WS TimeApp Terminal Firmware 1.1

Firmware 1.1.1 is rebuilt from the complete frozen Firmware 1.0 baseline. It retains the RC522/LCD/LED/buzzer/setup-button workflow, captive portal, WLAN diagnostics and non-blocking display logic, then adds controlled HTTP/HTTPS transport, trust management and an offline queue. The URL scheme is an explicit security boundary: there is no HTTPS-to-HTTP fallback.

## Build / flash

```bash
cd terminal/firmware/pkws-time-terminal/1.1
pio run
pio run -t upload
```

It pins `espressif32@7.0.1` and `framework-arduinoespressif32@3.20017.241212` (Arduino-ESP32 2.0.17). The Arduino IDE wrapper includes only `../../src/main.cpp` in this 1.1 directory.

Before a production build, copy `include/TrustConfig.example.h` to the ignored `include/TrustConfig.local.h`, insert the offline PK-WS P-256 public verification key, and set `PKWS_TRUST_CONFIGURED` to `1`. Also copy `include/ProvisioningConfig.example.h` to ignored `include/ProvisioningConfig.local.h`, set unique portal credentials, and set `PKWS_PROVISIONING_CONFIGURED` to `1`. Missing local headers or unset confirmation macros are deliberate build errors; test fixtures are never used by a production build. The local portal remains reachable on the terminal LAN only for diagnosis and configuration; treat it as an administrative surface.

For a PlatformIO-free build and flash with Arduino IDE 2.x, follow the dedicated
[Arduino IDE flash guide](docs/arduino-ide-flash.md). It pins the compatible
ESP32 core, required libraries, board settings and post-flash checks.

## Transport and time

- `http://192.168.1.10`: normal `WiFiClient`; NTP is not required; portal marks it unencrypted.
- `https://terminal-api.pk-ws.de`: `WiFiClientSecure` plus CA and hostname validation; `TIME_SYNC` must obtain a plausible NTP time first.
- Unsupported URL schemes are rejected. TLS failure never emits terminal headers, tokens, NFC UIDs, or booking data through HTTP.

## Trust bundles and recovery

LittleFS is mounted with `begin(false)` only; it is never automatically formatted. `trust-active`, `trust-previous`, `trust-staging`, `trust-new` and `trust-old-pending` provide recoverable, power-loss-safe installation. The factory fallback is versioned in `FactoryTrust.h`; the public ECDSA verifier is supplied by the ignored local header. No private key is present in firmware or production configuration.

During normal operation new bundles are downloaded only with verified HTTPS (at start/reconnect and no more than once per 24 hours). A TLS trust failure may use `setInsecure()` only for the fixed same-origin public `/api/v1/terminal/trust-bundle` GET: it has no body, no terminal ID, no bearer token and no NFC data; the returned payload is accepted only after local ECDSA verification. The portal permits a signed upload, restoring the previous bundle, or factory fallback after local login and a per-boot form token.

## Offline scans

Up to 64 scans are stored as individual atomically created records. Every record retains its `request_id`; it is removed only after a successful server response, preserving server-side idempotency. TLS and WLAN failures persist the current scan before recovery/retry. Queue synchronization transfers one record at a time between normal loop cycles.

## Signed payload protocol

The PHP signer and firmware sign the same UTF-8 block: a magic line followed by fixed-order `name:length` fields and their byte values. PEM line endings are normalized to LF before the certificate fields are written. This avoids hand-written JSON escaping rules. Interoperability vectors reside in `tests/fixtures/terminal-trust/`.

## Nginx Proxy Manager

Create DNS `A` record `terminal-api.pk-ws.de` to the fixed VPS address. In Nginx Proxy Manager configure the proxy host with forward scheme `http`, the internal TimeApp host/port, a Let’s Encrypt certificate, and **Force SSL**. Configure the terminal directly with `https://terminal-api.pk-ws.de`, never an HTTP URL that relies on redirecting. The server intentionally uses `REMOTE_ADDR` for terminal allowlists; it does not trust arbitrary `X-Forwarded-For` or `X-Real-IP` headers. Configure an allowlist only when the proxy's observed source address is stable; leaving it empty is supported.

See [the test plan](docs/testplan.md) and [the trust-bundle tool](../../../../tools/terminal-trust-bundle/README.md).
