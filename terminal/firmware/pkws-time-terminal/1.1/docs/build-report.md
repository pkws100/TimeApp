# Reproduzierbarer Buildbericht – Firmware 1.1.1

Ausgeführt am 2026-07-15 auf `fix/terminal-firmware-1.1.1-release-candidate` mit:

```text
PIO_CMD=/tmp/platformio-venv/bin/pio sh terminal/firmware/pkws-time-terminal/build-test.sh
```

| Firmware | Ergebnis | PlatformIO-Plattform / Core | Board | RAM | Flash |
| --- | --- | --- | --- | --- | --- |
| 1.0 | SUCCESS | `espressif32@7.0.1` / `framework-arduinoespressif32@3.20017.241212` | DOIT ESP32 DEVKIT V1, 4 MB | 49.088 / 327.680 Bytes (15,0 %) | 1.046.597 / 1.310.720 Bytes (79,8 %) |
| 1.1 Test | SUCCESS | `espressif32@7.0.1` / `framework-arduinoespressif32@3.20017.241212` | DOIT ESP32 DEVKIT V1, 4 MB | 49.580 / 327.680 Bytes (15,1 %) | 1.152.481 / 1.310.720 Bytes (87,9 %) |

PlatformIO Core im verwendeten virtuellen Environment: 6.1.19. Der 1.1-Testbuild verwendet
explizit `-e esp32doit-devkit-v1-test` und ausschließlich `1.1/test-config/`; eine lokale
Produktionskonfiguration wird dabei nicht gelesen. Dasselbe Skript führte anschließend
`pio test -e native` aus: 3 von 3 Testfällen waren erfolgreich.

Die 1.1-Plattform, die native Testplattform und alle direkten Bibliotheken sind exakt
versioniert. Für den unveränderten 1.0-Quellstand installiert `build-test.sh` die bei
diesem Nachweis verwendeten Bibliotheksversionen explizit mit `--no-save`. GitHub CI führt
denselben Build mit PlatformIO Core 6.1.19 als eigenen Pflichtlauf aus.

Erzeugte, ignorierte Prüfsummen in `dist-test/SHA256SUMS`:

```text
f0cc5c8966c8bb5c91950321c56af1a2805e4c21d8af0674fd042f48486eaa35  pkws-time-terminal-1.0.bin
a1b3b8a9e8318af7d40ec1110d3e2c07872cdf5363628578d0f020bf2428367b  pkws-time-terminal-1.1-test.bin
```

Die Testbinärdatei ist nicht für einen Produktionsflash freigegeben.
