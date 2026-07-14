# Finalisierungsbericht – Terminal-Firmware 1.1.1

Branch: `fix/terminal-firmware-1.1.1-finalize`

Ausgangscommit: `c056969772ad52340122b132b140e2d994be49a6`
Endcommit: Commit, der diesen Bericht enthält (nach Erstellung mit `git rev-parse HEAD` nachvollziehbar).

## Schutz des Rückfallstands

`sha256sum terminal/firmware/pkws-time-terminal/1.0/src/main.cpp` ergab vor den Änderungen:

```text
3d9d60a22eae9b5895929c42c892d28dddb912cedfe3515eb0c86cdc6295f325
```

Die Dateien unter `1.0/src`, `1.0/platformio.ini` und `1.0/arduino` wurden nicht geändert.
Die abschließende Hashprüfung ist vor Merge/Flash erneut auszuführen.

## Behobene Befunde

- Der Trust-Recovery-Marker ist ein validiertes, versionsgebundenes Installationsprotokoll. Ein Neustart mit Marker quarantänisiert einen nicht bestätigten Kandidaten und stellt `previous`/`old-pending` oder Factory-Trust wieder her; der Marker wird erst danach entfernt.
- Queue-Sync speichert Kontext, arbeitet mit 0/2/10/60-Sekunden-Backoff, endet nach vier Versuchen und führt TLS-Fehler in die Recovery. Erfolgreiche Übertragungen werden einzeln bestätigt.
- Permanente Serverablehnungen werden atomar nach `/queue-rejected/` verschoben. Sie enthalten Status/Code/Zeitpunkt, werden im Portal ohne UID sichtbar und nur nach Login, Formularschlüssel und `LOESCHEN` gelöscht.
- Bei fehlgeschlagener Persistierung während WLAN-Verlust bleibt der offene Scan samt `request_id` im RAM, neue NFC-Scans bleiben gesperrt und der identische Request wird nach Reconnect fortgesetzt.
- Dasselbe gilt bei jedem anderen Persistierungsfehler, etwa voller Queue oder LittleFS-Fehler: Das Terminal bleibt in `SEND_SCAN`, zeigt die Sperre an und versucht ausschließlich den unveränderten offenen Scan erneut; es wechselt nicht in `SHOW_RESULT` oder `NFC_SCAN`.
- Diagnoseheader verwenden den letzten abgeschlossenen TLS-Zustand. `verified` wird nur nach erfolgreichem HTTPS-Request gesetzt.
- Recovery-, Config- und Scan-Antworten haben Content-Length-, Größen-, Gesamt- und Idle-Timeout-Grenzen. Der einzige `setInsecure()`-Pfad bleibt der öffentliche Recovery-GET ohne Header oder Body.
- Firmware und Server erzwingen P-256; der Server prüft zusätzlich PEM, CA:TRUE, Zertifikatsanzahl/-größe und Gültigkeit. ETag wird aus dem exakt ausgelieferten JSON erzeugt; `If-None-Match` liefert 304.
- Platzhalter-Provisionierung ist per Pflicht-ID, Compile-Sentinels und Boot-Sperre blockiert. Der Testbuild verwendet ausdrücklich getrennte, nicht produktive Werte.

## Nachweise

Die echten Builddaten stehen in [build-report.md](build-report.md). Der Testbuild von 1.0 und 1.1 war erfolgreich.

- `vendor/bin/phpunit --no-progress`: erfolgreich abgeschlossen. Die Suite enthält 312 Tests; sechs bekannte Tests waren übersprungen. Der vorherige Sandbox-Lauf meldete 12 MariaDB-Socketfehler, der Lauf mit lokaler Testdatenbank lief anschließend erfolgreich durch.
- Der fokussierte Nachweis für Trust-Service und Firmware-Implementierung ergab `12 tests, 51 assertions`, ohne Fehler oder Warnings.
- Das Signierwerkzeug wurde real mit einem temporären Test-CA-Zertifikat geprüft: P-256 sign/verify erfolgreich; manipuliertes Payload und manipulierte Signatur abgelehnt; RSA- und P-384-Privat- sowie öffentliche Schlüssel abgelehnt.
- Statische Prüfung: kein `LittleFS.begin(true)`; genau eine Quellcode-Stelle für `setInsecure()` im anonymen Recovery-GET; Platzhalter nur in Example-Datei und expliziter Boot-Sperre; `PRIVATE KEY` nur in der Tool-Prüfung, Projekt-README und klar markierter Testfixture. Der Recovery-Pfad ruft `addApiHeaders()` nicht auf.

## Hardware und Freigabe

Es war kein ESP32 angeschlossen. Alle realen Hardware-, Recovery- und Speichermessungen sind daher **Nicht ausgeführt – reale Hardware erforderlich**. Die ausfüllbare Checkliste steht in [hardware-acceptance.md](hardware-acceptance.md).

Empfehlung: **Werkbanktest möglich, noch kein Pilotbetrieb.** Ein Pilot darf erst nach vollständig dokumentierter Werkbank-Abnahme mit Produktions-Trust, echter Terminalkonfiguration und den dort aufgeführten Negativtests starten.
