# Änderungs- und Buildnachweis – Firmware 1.1.2 Live-Uhr

Ausgangscommit: `049ea5421f166209d962b0b0a8586b9a72c745c6`
Arbeitsbranch: `fix/terminal-firmware-1.1-live-clock`

## Korrektur

Die vierte Zeile des dauerhaften Bereitschaftsbilds wird nicht mehr aus
`display.lines[3]` der Config-Antwort gespeichert. Die ersten drei Zeilen bleiben
servergesteuert; Zeile vier wird lokal mit der POSIX-Zeitzone
`CET-1CEST,M3.5.0/2,M10.5.0/3` aus der Epochzeit formatiert. Vor erfolgreicher
NTP-Synchronisierung zeigt sie `--.--.---- --:--`.

`device_time` bleibt davon getrennt: Es wird mit `gmtime_r()` als UTC im Format
`YYYY-MM-DDTHH:MM:SSZ` erzeugt. HTTP startet NTP parallel und bleibt sofort
nutzbar; HTTPS behält die bestehende `TIME_SYNC`-Pflicht vor der TLS-Verifikation.
Der Serverendpoint und seine Payload wurden nicht geändert.

## Reproduzierbarer Testbuild

Ausgeführt am 2026-07-16 mit:

```text
PIO_CMD=/tmp/platformio-venv/bin/pio sh terminal/firmware/pkws-time-terminal/build-test.sh
```

| Firmware | Ergebnis | PlatformIO / Plattform / Core | Board | RAM | Flash |
| --- | --- | --- | --- | --- | --- |
| 1.0 | SUCCESS | Core 6.1.19 / `espressif32@7.0.1` / `framework-arduinoespressif32@3.20017.241212` | DOIT ESP32 DEVKIT V1, 4 MB | 49.088 / 327.680 B (15,0 %) | 1.046.597 / 1.310.720 B (79,8 %) |
| 1.1 Test | SUCCESS | Core 6.1.19 / `espressif32@7.0.1` / `framework-arduinoespressif32@3.20017.241212` | DOIT ESP32 DEVKIT V1, 4 MB | 49.596 / 327.680 B (15,1 %) | 1.152.901 / 1.310.720 B (88,0 %) |

Gegenüber dem historischen 1.1.1-Testbuild: **+16 B RAM**, **+420 B Flash**.
Die App-Partition hat weiterhin 157.819 B Reserve.

```text
26ba12e5dc6318cec4a3c650e4048ba909d3068ea9774edd8b38d0cc81839264  pkws-time-terminal-1.1-test.bin
```

Die native Firmwaretest-Suite bestand mit acht Testfällen, darunter
Platzhalter, CET/CEST, UTC-`device_time`, Minuten-/Datumswechsel sowie die
Sperre bei temporären und nicht-idlen Anzeigen. Zusätzlich beweist ein Test,
dass UTC-Trust-Zeitwerte trotz Berlin-TZ unverändert in Epochzeit überführt
werden. Der vollständige
`COMPOSER_ALLOW_SUPERUSER=1 composer test`-Lauf wurde ebenfalls ausgeführt.

## Schutz und Produktionsstatus

`../1.0/src/main.cpp` hatte vor und nach der Arbeit denselben SHA-256:

```text
3d9d60a22eae9b5895929c42c892d28dddb912cedfe3515eb0c86cdc6295f325
```

`git diff -- terminal/firmware/pkws-time-terminal/1.0` ist leer. Firmware 1.0,
der Serverendpoint, API-Routen, Payloads, Terminal-ID, Bearer-Token,
Trust-/Recovery- und Queue-Logik wurden nicht verändert; eine Migration ist
nicht erforderlich.

Ein Produktionsbuild wurde nicht ausgeführt: Die ignorierten lokalen Dateien
`TrustConfig.local.h` und `ProvisioningConfig.local.h` fehlen. Testschlüssel
wurden nicht als Ersatz verwendet. Ein späterer manueller Upload lautet:

```bash
cd terminal/firmware/pkws-time-terminal/1.1
pio run -e esp32doit-devkit-v1 -t upload
```

Kein reales Gerät wurde geflasht, kein Full-Flash-Erase und kein
Partitionswechsel sind erforderlich. Die ergänzten HTTP-, HTTPS-, Reconnect-,
Queue-/Recovery-, temporären Display- und Ergebnis-Hold-Werkbanktests in
`hardware-acceptance.md` bleiben vor einem Produktionsflash auszuführen.
