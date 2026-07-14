# PK-WS TimeApp Terminal Firmware

Firmware **1.0** is the frozen rollback baseline (`pkws-time-terminal-v0.1.1`). Firmware **1.1** is the current dual-transport firmware with verified HTTPS, signed trust bundles and an offline queue.

```text
pkws-time-terminal/
├── 1.0/  frozen, independently buildable rollback snapshot
├── 1.1/  independently buildable current development generation
├── build-all.sh
└── dist/  generated, ignored binaries and SHA256SUMS
```

Build either version with `cd 1.0 && pio run` or `cd 1.1 && pio run`; use `pio run -t upload` to flash it. `./build-all.sh` produces `dist/pkws-time-terminal-1.0.bin`, `pkws-time-terminal-1.1.bin` and `SHA256SUMS`.

For rollback, flash the independently built 1.0 image and validate the local portal/API test. A firmware downgrade does not migrate 1.1 LittleFS trust/queue data; synchronize queued scans before rollback.

Use an explicit `http://` URL only inside a protected network. Use direct `https://terminal-api.pk-ws.de` for public/remote use; never depend on a HTTP redirect. Details for HTTPS, Nginx Proxy Manager, trust bundle creation/recovery, and offline synchronization are in [1.1/README.md](1.1/README.md). The 1.0 snapshot details are in [1.0/SNAPSHOT.md](1.0/SNAPSHOT.md).
