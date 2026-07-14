# Firmware 1.1 test plan

## Build and rollback

- Run `pio run` in both `../1.0` and `..`; verify each Arduino wrapper points to its own `../../src/main.cpp`.
- Flash 1.1, then build/flash 1.0 and confirm V0.1.1/API operation. 1.0 must never include 1.1 source.

## Transport

- HTTP config/scan succeeds with NTP unavailable and portal reports unencrypted HTTP.
- HTTPS waits in `TIME_SYNC`; valid time plus valid hostname/CA succeeds.
- Wrong hostname, unknown CA, expired certificate and NTP timeout fail without a fallback HTTP request.
- Inspect traffic: normal HTTPS requests never call `setInsecure`; recovery GET has no Authorization, `X-Terminal-ID`, UID, or payload.

## Trust and queue

- Install a correct newer bundle; reject altered signature, malformed/oversized bundle and lower version.
- Simulate power loss between temporary write/rename; active or previous bundle remains readable.
- Test previous/factory restore and verified connection after install. Confirm WARNING/REPLACE_REQUIRED stay operational.
- Force TLS failure: recovery either restores trust or scan is queued; reboot retains it. Restore HTTPS and verify FIFO sync and idempotent `request_id`. Fill 64 entries and observe the overflow warning.

## Existing hardware and portal

- Check RC522 UID normalization and two-second duplicate suppression, LCD, LEDs, buzzer, setup AP/button, WLAN reconnect, local login/form token, and that tokens/passwords never appear in LCD, serial output or status HTML.
