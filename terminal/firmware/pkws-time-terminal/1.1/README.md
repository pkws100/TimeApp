# PK-WS TimeApp Terminal Firmware 1.1

Firmware 1.1 is the new dual-transport generation: `http://` remains available for protected internal networks, while `https://` uses hostname and CA validation. The URL scheme is an explicit security boundary: there is no HTTPS-to-HTTP fallback.

## Build / flash

```bash
cd terminal/firmware/pkws-time-terminal/1.1
pio run
pio run -t upload
```

It pins `espressif32@7.0.1` and `framework-arduinoespressif32@3.20017.241212` (Arduino-ESP32 2.0.17). The Arduino IDE wrapper includes only `../../src/main.cpp` in this 1.1 directory.

Before productive flashing, replace `change-me-setup` and `change-me-portal` with unique per-device values and replace the example trust signing public key with PK-WS's offline signing public key. The local portal remains reachable on the terminal LAN only for diagnosis and configuration; treat it as an administrative surface.

## Transport and time

- `http://192.168.1.10`: normal `WiFiClient`; NTP is not required; portal marks it unencrypted.
- `https://terminal-api.pk-ws.de`: `WiFiClientSecure` plus CA and hostname validation; `TIME_SYNC` must obtain a plausible NTP time first.
- Unsupported URL schemes are rejected. TLS failure never emits terminal headers, tokens, NFC UIDs, or booking data through HTTP.

## Trust bundles and recovery

LittleFS stores independent active and previous signed JSON bundles. Atomic rename means a power loss cannot replace an active bundle with a partial file. The factory fallback contains the two public ISRG/Let's Encrypt root anchors used by the documented Nginx Proxy Manager deployment, rather than a single immutable root. The matching public ECDSA P-256 verifier key is compiled into firmware; no private key is present. Before production release, replace the example verifier key with PK-WS's offline signing public key.

During normal operation new bundles are downloaded only with verified HTTPS (at start/reconnect and no more than once per 24 hours). A TLS trust failure may use `setInsecure()` only for the fixed same-origin public `/api/v1/terminal/trust-bundle` GET: it has no body, no terminal ID, no bearer token and no NFC data; the returned payload is accepted only after local ECDSA verification. The portal permits a signed upload, restoring the previous bundle, or factory fallback after local login and a per-boot form token.

## Offline scans

Up to 64 scans are persisted atomically in LittleFS. Every record retains its `request_id`; it is removed only after a successful server response, preserving server-side idempotency. TLS failures show “Server nicht sicher / Scan gespeichert”; queue overflow is an explicit red warning. The queue synchronizes after a successful config request.

## Nginx Proxy Manager

Create DNS `A` record `terminal-api.pk-ws.de` to the fixed VPS address. In Nginx Proxy Manager configure the proxy host with forward scheme `http`, the internal TimeApp host/port, a Let’s Encrypt certificate, and **Force SSL**. Configure the terminal directly with `https://terminal-api.pk-ws.de`, never an HTTP URL that relies on redirecting. The server intentionally uses `REMOTE_ADDR` for terminal allowlists; it does not trust arbitrary `X-Forwarded-For` or `X-Real-IP` headers. Configure an allowlist only when the proxy's observed source address is stable; leaving it empty is supported.

See [the test plan](docs/testplan.md) and [the trust-bundle tool](../../../../tools/terminal-trust-bundle/README.md).
