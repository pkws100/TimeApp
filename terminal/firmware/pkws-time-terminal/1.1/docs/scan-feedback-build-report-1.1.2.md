# Änderungs- und Buildnachweis – Firmware 1.1.2 Scan-Feedback

Ausgangscommit: `432999dc67c60ea85b3f67a0eb86b874fa648dcc`

## Korrektur

Nach einer sicheren NFC-Erkennung wartet die Firmware 100 ms nicht blockierend,
bevor sie den synchronen Netzwerkaufruf beginnt. Der 60-ms-Wartepiep kann in
dieser Zeit durch den Hauptloop sauber enden; unmittelbar vor dem Senden wird
der Buzzer zusätzlich kontrolliert ausgeschaltet. Es gibt kein `delay()` und
keine Warteschleife.

Gelb bedeutet eine lokal gelesene, noch nicht bestätigte Buchung. Grün und das
Erfolgsmuster entstehen nur bei HTTP-2xx, vollständig gelesener und gültiger
JSON-Antwort mit explizitem `ok: true`. `ok: false`, fehlendes `ok`, ungültiges
JSON, Nicht-2xx, Netzwerk-/TLS-Fehler und eine ausschließlich lokal gespeicherte
Offline-Buchung können kein Grün oder Erfolgsmuster erzeugen. Queue-, Retry-,
Trust- und Dead-Letter-Abläufe wurden nicht verändert.

## Reproduzierbarer Testbuild

Ausgeführt am 2026-07-16 mit:

```text
PIO_CMD=/tmp/platformio-venv/bin/pio sh terminal/firmware/pkws-time-terminal/build-test.sh
```

| Firmware | Ergebnis | PlatformIO / Plattform / Core | Board | RAM | Flash |
| --- | --- | --- | --- | --- | --- |
| 1.0 | SUCCESS | Core 6.1.19 / `espressif32@7.0.1` / `framework-arduinoespressif32@3.20017.241212` | DOIT ESP32 DEVKIT V1, 4 MB | 49.088 / 327.680 B (15,0 %) | 1.046.597 / 1.310.720 B (79,8 %) |
| 1.1 Test | SUCCESS | Core 6.1.19 / `espressif32@7.0.1` / `framework-arduinoespressif32@3.20017.241212` | DOIT ESP32 DEVKIT V1, 4 MB | 49.604 / 327.680 B (15,1 %) | 1.153.529 / 1.310.720 B (88,0 %) |

Gegenüber dem vorherigen PR-Head: **±0 B RAM**, **+804 B Flash**. Die
App-Partition hat weiterhin **157.191 B Reserve**; der Unterschied entspricht
den zusätzlichen Entscheidungs- und Testhilfen und ist für die Korrektur klein.

```text
4ce818484b1a75a4de3dffcc93fc8165c527bad8dc54c96eba79f8c3d8af3a00  pkws-time-terminal-1.1-test.bin
f0cc5c8966c8bb5c91950321c56af1a2805e4c21d8af0674fd042f48486eaa35  pkws-time-terminal-1.0.bin
```

Die native Firmwaretest-Suite besteht aus 14 reinen Entscheidungstests. Sie
deckt Sendefreigabe vor/nach Ablauf und über `millis()`-Überlauf, HTTP-200/201
mit `ok: true`, `ok: false`, fehlendes `ok`, 204, Nicht-2xx sowie die
ausschließliche Grün-/Erfolgstonfreigabe für serverbestätigte Buchungen ab. Eine
nicht bestätigte 2xx-Antwort einer Offline-Queue wird über den bestehenden
Dead-Letter-Pfad beendet, statt erneut übertragen zu werden. Netzwerk-,
LittleFS-, Buzzer- und Hardwareabläufe bleiben Gegenstand der getrennten
Werkbank- und Pilotprüfungen.

Der lokale Aufruf `COMPOSER_ALLOW_SUPERUSER=1 composer test` erreicht die
MariaDB-Scratch-Datenbanken, wird in dieser Ausführungsumgebung jedoch nach etwa
30 Sekunden vom Prozesslimit beendet, bevor PHPUnit sein Fazit schreibt. Die
vollständige Suite ist daher nicht als lokal bestanden behauptet und wird über
die GitHub-Action des neuen PRs abschließend verifiziert.

## Schutz und Produktionsstatus

`../1.0/src/main.cpp` hatte vor der Änderung den SHA-256:

```text
3d9d60a22eae9b5895929c42c892d28dddb912cedfe3515eb0c86cdc6295f325
```

Der Hash und der leere Diff gegen Firmware 1.0 werden nach Abschluss erneut
geprüft. Ein Produktionsbuild oder Flash wurde nicht ausgeführt: Es wurden
weder lokale Produktionskonfigurationen noch Geheimnisse verwendet. Der reale
Hör-, LED- und Buchungsnachtest ist in `hardware-acceptance.md` ausdrücklich
noch offen.
