# Firmware 1.1.2 test plan

## Build and rollback

- Verify the SHA-256 of `../1.0/src/main.cpp` is `3d9d60a22eae9b5895929c42c892d28dddb912cedfe3515eb0c86cdc6295f325` before and after work. Run `pio run` in both `../1.0` and `..`; verify each Arduino wrapper points to its own `../../src/main.cpp`.
- Flash 1.1, then build/flash 1.0 and confirm V0.1.1/API operation. 1.0 must never include 1.1 source.

## Transport

- HTTP config/scan succeeds while NTP is unavailable and portal reports unencrypted HTTP; after NTP the ready screen updates to the current Europe/Berlin time without a restart or config request.
- HTTPS waits in `TIME_SYNC`; valid time plus valid hostname/CA succeeds and the ready screen uses the local Europe/Berlin clock.
- Wrong hostname, unknown CA, expired certificate and NTP timeout fail without a fallback HTTP request.
- Inspect traffic: normal HTTPS requests never call `setInsecure`; recovery GET has no Authorization, `X-Terminal-ID`, UID, or payload.

## Live ready clock

- Native tests verify placeholder output before time sync, CET winter time, CEST summer time, UTC `device_time` formatting, minute/date changes, and that temporary or non-idle displays cannot be overwritten.
- Confirm after a booking result's complete server-defined `hold_ms` that the ready screen returns with the current clock, not the config response timestamp.

## Trust and queue

- Install a correct newer bundle; reject altered signature, malformed/oversized bundle and lower version.
- Simulate power loss between temporary write/rename; active or previous bundle remains readable.
- Test previous/factory restore and verified connection after install. Confirm WARNING/REPLACE_REQUIRED stay operational.
- Force TLS failure: recovery either restores trust or scan is queued; reboot retains it. Restore HTTPS and verify FIFO sync and idempotent `request_id`. Fill 64 entries and observe the overflow warning.
- Verify boot recovery for `active`, `previous`, `staging`, `new` and `old-pending`; test interrupted installation before/after every rename.
- Record `ESP.getFreeHeap()`, `ESP.getMinFreeHeap()` and stack reserve after boot, WLAN, HTTPS handshake, a full queue and queue sync.

## Existing hardware and portal

- Check RC522 UID normalization and two-second duplicate suppression, LCD, LEDs, buzzer, setup AP/button, WLAN reconnect, local login/form token, and that tokens/passwords never appear in LCD, serial output or status HTML.
- Mark every test without a real ESP32 and connected peripherals as **Nicht ausgeführt – reale Hardware erforderlich**.

## Functional inventory

| Function | Firmware 1.0 | Firmware 1.1.2 | Test status |
| --- | --- | --- | --- |
| WLAN, RC522, LCD, LEDs, buzzer, setup button | yes | retained | hardware required |
| Captive portal, login/form key, WLAN/API/hardware diagnostics | yes | retained and extended | portal/hardware required |
| Non-blocking buzzer, display hold, duplicate UID guard | yes | retained | hardware required |
| HTTP transport | yes | retained without NTP dependency | integration required |
| Live Europe/Berlin ready clock | no | local minute-based clock; UTC server timestamp remains separate | native tests + hardware required |
| HTTPS, NTP, verified TLS diagnostics | no | added | integration required |
| Signed trust recovery and CA expiry data | no | added | integration required |
| Persistent FIFO offline queue | partial scan resume | added | integration/hardware required |
