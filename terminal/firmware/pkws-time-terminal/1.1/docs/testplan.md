# Firmware 1.1.6 test plan

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
- Exercise queue retry deadlines immediately before and after a simulated `millis()` rollover. The queue must continue, and an intentional backoff must show a decreasing retry countdown.
- Start a queue retry shortly before Europe/Berlin midnight. During the final two-minute safety window it must not POST; after midnight it must move the record to the rejected queue with its original booking time, queue reason and rejection reason visible in the portal.
- Simulate a power loss after a rejected copy is committed but before its active FIFO source is removed. On reboot the matching move must complete idempotently instead of blocking later records.
- Force both a newly presented tag and an existing queue POST to outlive all normal client timeouts. The scan must already be journaled before each POST; the 90-second task watchdog must reboot the ESP32 with request ID and payload intact. Automatic replay must remain persistently blocked (no reboot loop) until the authenticated portal action verifies access and unblocks it, and the portal must report `task_watchdog` as the reset reason. While blocked, another presented tag must visibly report `nicht gebucht` and must not be accepted.
- Corrupt a queue record and force its quarantine rename to fail. The terminal must show the storage error and enter bounded API retry instead of repeatedly reading the same file in a tight queue loop.
- Return HTTP 429 with `Retry-After: 900` for a live scan. The scan must be persisted with `not_before_epoch`, the terminal must return to ready after the result hold, and queue synchronization must wait in the background without blocking portal maintenance. Restart during the wait and verify that the record is still not sent before the persisted deadline.
- Repeat the long `Retry-After` test over plain HTTP before NTP is valid. The relative fallback must wait once in the same boot and once conservatively after a restart, without sending early.
- Interrupt each queue deadline update phase (`staging` written, active moved to backup, new active promoted). On reboot the valid new file or last good backup must be restored and verified. Force a rename failure and verify the visible 10-second storage-recovery retry blocks queue processing while the portal and safe restart remain available.
- During `SEND_SCAN` and `QUEUE_SYNC`, use the authenticated portal recovery abort and safe reboot. Volatile scans must be persisted before recovery; existing queue records must remain intact and formatting must become available only after the busy state ends.
- Verify boot recovery for `active`, `previous`, `staging`, `new` and `old-pending`; test interrupted installation before/after every rename.
- Record `ESP.getFreeHeap()`, `ESP.getMinFreeHeap()` and stack reserve after boot, WLAN, HTTPS handshake, a full queue and queue sync.

## Existing hardware and portal

- Check RC522 UID normalization and two-second duplicate suppression, LCD, LEDs, buzzer, setup AP/button, WLAN reconnect, local login/form token, and that tokens/passwords never appear in LCD, serial output or status HTML.
- Verify that the LCD backlight turns off after 15 seconds only on the idle `Tag vorhalten` screen, wakes immediately on any successfully read tag, and remains continuously on during setup, network work, scan processing, results, temporary warnings and every error state.
- Create a real `/queue/0000000001.json` record on Arduino-ESP32 2.0.17 and verify that directory iteration selects it through `File::path()`, reaches `Sende Buchung`, and never remains on the generic queue-entry screen.
- Verify basename and full-path sequence parsing, recovery suffixes and malformed filename quarantine. Only the exact ten-digit `NNNNNNNNNN.json` form may enter the active FIFO; names such as `0000000001.copy.json` and `0000000001.json.bak.json` must be quarantined without a POST. A quarantined record must leave queue sync, show `Queue zur Pruefung / Datensatz defekt / nicht gesendet / Portal pruefen` for ten seconds, and retain `corrupt_quarantined` as the portal phase; a record that cannot be classified or quarantined must create a visible persistent storage block.
- Interrupt a deferred queue metadata update at each rename boundary. Power loss after the valid `.defer.tmp` has been flushed but before target-to-backup must resume the staging transaction and retain its later `Retry-After` deadline; no POST may occur early. If both a corrupt active target and a structurally valid staging copy exist, recovery must quarantine the target and activate the valid staging record without deleting it. Create multiple simultaneous recovery artifacts and verify every one is processed despite directory renames. Give target/backup/staging the same sequence but different request IDs and verify all distinct records are preserved in separate quarantine files while a persistent manual storage block prevents any automatic POST. Pre-create the default `.identity-conflict.corrupt` and `.recovery-invalid.corrupt` destinations and verify numbered alternatives are selected without blocking portal maintenance.
- Mark every test without a real ESP32 and connected peripherals as **Nicht ausgeführt – reale Hardware erforderlich**.

## Functional inventory

| Function | Firmware 1.0 | Firmware 1.1.6 | Test status |
| --- | --- | --- | --- |
| WLAN, RC522, LCD, LEDs, buzzer, setup button | yes | retained | hardware required |
| Captive portal, login/form key, WLAN/API/hardware diagnostics | yes | retained and extended | portal/hardware required |
| Non-blocking buzzer, display hold, duplicate UID guard | yes | retained | hardware required |
| HTTP transport | yes | retained without NTP dependency | integration required |
| Live Europe/Berlin ready clock | no | local minute-based clock; UTC server timestamp remains separate | native tests + hardware required |
| HTTPS, NTP, verified TLS diagnostics | no | added | integration required |
| Signed trust recovery and CA expiry data | no | added | integration required |
| Persistent FIFO offline queue | partial scan resume | added | integration/hardware required |
