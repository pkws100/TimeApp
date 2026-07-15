# Abschlussbericht – Terminal-Firmware 1.1.1 Werkbankkandidat

Branch: `fix/terminal-firmware-1.1.1-release-candidate`

Ausgangscommit: `d92ca7a9714f8d6c399529005eb5bb4c1c757b5c`

Der Endcommit ist der Commit, der diesen Bericht enthält. Ein Draft-PR wird nach den abschließenden Agentenreviews erstellt; ein Merge oder eine Pilotfreigabe ist nicht Bestandteil dieses Auftrags.

## Schutz des Rückfallstands

`terminal/firmware/pkws-time-terminal/1.0/src/main.cpp` hatte vor und nach der Arbeit denselben SHA-256:

```text
3d9d60a22eae9b5895929c42c892d28dddb912cedfe3515eb0c86cdc6295f325
```

Firmware 1.0 wurde nicht geändert.

## Geänderte Dateien

- `1.1/src/main.cpp`: Trust-, Queue-, Transport-, Portal- und Diagnosekorrekturen.
- `1.1/include/TerminalDecisionLogic.h`: host-testbare Queue-/Trust-/Retry-Entscheidungen.
- `1.1/test/test_decision_logic/test_main.cpp` und `1.1/platformio.ini`: native Tests und expliziter Produktions-Default.
- `build-test.sh`: reproduzierbare 1.0-/1.1-Builds plus native Tests; die eingefrorene
  1.0-Konfiguration bleibt unverändert, während das Skript ihre verifizierten
  Bibliotheksversionen explizit und ohne Speichern in `platformio.ini` installiert.
- `prepare-1.0-build.sh` und `build-all.sh`: auch der operative Sammel- und
  Rollback-Build verwendet dieselbe gepinnte 1.0-Abhängigkeitsvorbereitung.
- `.github/workflows/ci.yml`: eigener Firmwarejob mit PlatformIO Core 6.1.19, beiden
  Firmwarebuilds und den nativen Entscheidungstests.
- `tests/Unit/TerminalTrustBundleServiceTest.php`: Zertifikats-, ETag- und 304-Nachweise.
- `1.1/README.md` sowie `1.1/docs/build-report.md`, `finalization-report.md` und `hardware-acceptance.md`: Betriebs-, Build- und Abnahmenachweise.

## Korrekturen

- Fehlgeschlagene Trust-Kandidaten werden aus `active` nach `/trust-unverified-candidate.json` verschoben. Sie werden weder als `previous` noch beim normalen Boot automatisch aktiviert. Das Portal zeigt nur ihre Existenz und löscht sie ausschließlich nach Login, Formularschlüssel und Texteingabe `LOESCHEN`.
- Die Recovery-Entscheidung bevorzugt bei einem Stromausfall zwischen Backup und Kandidatenaktivierung den unmittelbar vorherigen `old-pending`-Stand vor einem älteren `previous`. Wiederhergestellte Dateien werden erneut gelesen und kryptografisch geparst. Der Recovery-Marker bleibt erhalten, solange Rollback, Bereinigung oder Markerentfernung nicht vollständig abgeschlossen sind; im Fehlerfall läuft nur Factory-Trust im RAM.
- Previous- und Factory-Auswahl setzen `tlsState` und `lastCompletedTlsState` auf `NOT_CHECKED`. Ein neuer Zustand `VERIFIED` entsteht erst durch einen tatsächlich erfolgreichen HTTPS-Request.
- Queuefehler verwenden die expliziten Aktionen `RETRY_TEMPORARY`, `BLOCK_GLOBAL_KEEP_ACTIVE`, `DEAD_LETTER_RECORD` und `CONFIRMED`. Ein globaler Fehler persistiert auch einen gerade erst gelesenen Live-Scan mit unveränderter `request_id`, lässt den aktiven Datensatz unverändert und sperrt alle weiteren Queueeinträge. Grund, HTTP-Status, Servercode und Zeitpunkt werden redundant in NVS und einer atomaren LittleFS-Fallbackdatei gespeichert; die Sperre übersteht Neustarts und bleibt bei einem einzelnen Speicherausfall fail-closed.
- Die Sperre wird ausschließlich nach einem erfolgreichen authentifizierten `GET /api/v1/terminal/config` mit der aktuell gespeicherten Terminal-ID und dem aktuellen Token aufgehoben. Das Portal bietet dafür eine geschützte Prüfaktion; eine blinde Entsperrung gibt es nicht.
- Globale Fehler: HTTP 401/403 sowie `terminal_auth_required`, `terminal_auth_failed`, `terminal_disabled`, `terminal_unknown`, `terminal_ip_denied`, `terminal_storage_missing` und `feature_disabled`.
- Datensatzbezogene Dead-Letter-Fehler: `nfc_tag_invalid`, `nfc_tag_not_found`, `employee_mapping_invalid`, `nfc_uid_missing`, `invalid_uid`, `unknown_tag` und `unassigned_tag`. Die letzten vier entsprechen den aktuell vom Server verwendeten NFC-Codes. Unbekannte permanente Fehler sperren konservativ die Queue.
- Ein Dead-Letter-Datensatz wird atomar geschrieben, geschlossen, erneut geöffnet und anhand von JSON, Sequenz und `request_id` verifiziert. Erst danach wird die aktive Datei entfernt. Sequenz-Recovery berücksichtigt aktive, temporäre, defekte und bereits abgelehnte Dateien; ein bereits vorhandenes Dead-Letter-Ziel wird niemals überschrieben. Jeder Fehler behält den aktiven Eintrag und setzt einen eindeutigen Dead-Letter-Fehler.
- Der Portal-API-Test nutzt denselben begrenzten Streamleser wie Config/Scan: maximal 16.384 Byte, Content-Length-Prüfung, JSON-Content-Type, Gesamt- und Idle-Timeout. Teilantworten werden nicht geparst; Formularwerte überschreiben die gespeicherte Konfiguration nicht.
- HTTP 425 gilt als temporär. HTTP 429 liest einen vorab registrierten `Retry-After`-Header; unterstützt wird die Sekundenform, begrenzt auf 1 bis 900 Sekunden. Ein gültiger Wert hat Vorrang vor dem normalen Live- und Queue-Backoff. HTTP-Datumswerte fallen auf den normalen Backoff zurück.
- `/trust/check`, `/trust/upload`, `/trust/previous`, `/trust/factory`, `/queue/rejected/delete`, `/filesystem/format`, `/save`, `/reset`, `/reboot`, die Queue-Entsperrung und das Quarantänelöschen sind während Live-Scan, Queue-Sync oder TLS-Recovery mit HTTP 409 gesperrt. WLAN-/API-Diagnosen sind während dieser Vorgänge ebenfalls gesperrt. Hardwaretests sind ausschließlich im bewusst per Setup-Taster aktivierten Setup-Modus möglich, damit ein NFC-Test keine reale Buchung konsumieren kann. Abgewiesene oder abgebrochene Uploads hinterlassen keinen Pufferrest.
- Die Status-JSON enthält jedes Feld nur einmal, verwendet `free_heap`, `minimum_free_heap`, `stack_high_water_mark` und behält `min_free_heap` als eindeutigen Legacy-Alias. Neu sind Queue-Sperrstatus und Quarantäneanzeige.

## Builds und Tests

Die endgültigen Messwerte und Binärprüfsummen stehen in [build-report.md](build-report.md): Firmware 1.0 und der reproduzierbare 1.1-Testbuild waren erfolgreich. PlatformIO Core 6.1.19, Espressif32 7.0.1, Arduino-ESP32 2.0.17 und DOIT ESP32 DEVKIT V1 wurden verwendet. Ein Produktionsbuild wurde bewusst nicht behauptet, weil im Workspace keine lokalen Produktionsdateien `TrustConfig.local.h` und `ProvisioningConfig.local.h` vorhanden waren; ohne diese echten lokalen Werte muss er fehlschlagen.

- Native Firmwarelogik: 3 Testfälle erfolgreich. Abgedeckt sind globale/datensatzbezogene/temporäre Queueentscheidungen, HTTP 425/429/500, unbekanntes HTTP 400, Retry-After-Grenzen sowie Trust-Recovery mit Previous, Factory, Old-Pending, beschädigtem Marker und vorhandener Quarantäne.
- Fokussierte PHP-Verifikation: 14 Tests, 70 Assertions erfolgreich für Firmware-Implementierung und Trust-Service. Enthalten sind ungültiges CA-Zertifikat, fehlendes `CA:TRUE`, Zertifikatsanzahl, abgelaufenes und zu großes Zertifikat, RSA-Key-Ablehnung, ETag und HTTP 304.
- Vollständiger PHPUnit-Lauf nach den letzten Review-Fixes: 314 Tests, 1.378 Assertions, 0 Fehler, 0 Failures und 0 Skips in 4:46 Minuten. PHPUnit meldete einen nicht fehlschlagenden Warning- und einen Deprecation-Hinweis; der JUnit-Bericht enthielt keine testbezogenen Warnings. Der Lauf endete erfolgreich.
- Testdatenbank: isolierte MariaDB-Scratch-Datenbanken über `/run/mysqld/mysqld.sock`, Benutzer `root`, separater temporärer `DB_OVERRIDE_FILE`; fehlendes `CREATE DATABASE` wurde nicht als Erfolg oder Skip gewertet.

## Statische Prüfungen

- Kein `LittleFS.begin(true)`.
- Genau ein produktiver `setInsecure()`-Treffer in `src/main.cpp`, ausschließlich im festen Same-Origin-Recovery-GET ohne Auth-, Terminal- oder NFC-Header und ohne Body.
- Kein `http.getString()` in `1.1/src`.
- `change-me-` nur in der Example-Konfiguration und den expliziten Boot-Sperrvergleichen.
- Private Schlüssel nur in der klar markierten Testfixture; Tool und Dokumentation erwähnen lediglich den Prüfbegriff.
- `git diff --check` ohne Befund.

## Hardware und Freigabe

Es war kein ESP32 angeschlossen. Sämtliche realen HTTP-/HTTPS-, Trust-, Stromausfall-, Queue-, Speicher-, Portal-, NFC-, Signal- und Rückflashprüfungen sind **Nicht ausgeführt – reale Hardware erforderlich** und in [hardware-acceptance.md](hardware-acceptance.md) einzeln markiert.

Bewertung: **Werkbanktest möglich, noch kein Pilotbetrieb.** Die Pilotfreigabe darf erst nach vollständig dokumentiertem Werkbanktest mit Produktions-Trust-Key, signiertem Produktionsbundle, individuellen Portalzugängen, echten HTTP-/HTTPS-Endpunkten und allen Negativ-/Stromausfalltests erfolgen.
