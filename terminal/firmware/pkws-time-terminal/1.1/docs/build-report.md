# Reproduzierbarer Buildbericht – Firmware 1.1.1

Ausgeführt am 2026-07-14 auf dem Finalisierungsbranch mit:

```text
PIO_CMD=/tmp/platformio-venv/bin/pio sh terminal/firmware/pkws-time-terminal/build-test.sh
```

| Firmware | Ergebnis | PlatformIO-Plattform / Core | Board | RAM | Flash |
| --- | --- | --- | --- | --- | --- |
| 1.0 | SUCCESS | `espressif32@7.0.1` / `framework-arduinoespressif32@3.20017.241212` | DOIT ESP32 DEVKIT V1, 4 MB | 49.088 / 327.680 Bytes (15,0 %) | 1.046.597 / 1.310.720 Bytes (79,8 %) |
| 1.1 Test | SUCCESS | `espressif32@7.0.1` / `framework-arduinoespressif32@3.20017.241212` | DOIT ESP32 DEVKIT V1, 4 MB | 49.516 / 327.680 Bytes (15,1 %) | 1.138.925 / 1.310.720 Bytes (86,9 %) |

PlatformIO Core im verwendeten virtuellen Environment: 6.1.19. Der 1.1-Testbuild verwendet
explizit `-e esp32doit-devkit-v1-test` und ausschließlich `1.1/test-config/`; eine lokale
Produktionskonfiguration wird dabei nicht gelesen.

Erzeugte, ignorierte Prüfsummen in `dist-test/SHA256SUMS`:

```text
f0cc5c8966c8bb5c91950321c56af1a2805e4c21d8af0674fd042f48486eaa35  pkws-time-terminal-1.0.bin
152c5f29a8125bbf8a93a01d2475ada8aa333a5df502b7c9d201e3c082012f45  pkws-time-terminal-1.1-test.bin
```

Die Testbinärdatei ist nicht für einen Produktionsflash freigegeben.
