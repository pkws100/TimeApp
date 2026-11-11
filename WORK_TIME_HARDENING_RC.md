# WorkTime Hardening – Release Candidate

## Ausgangspunkt und Freigabegrenzen

- Ausgangscommit: `e422aecfe33480dd6fa8f3fccf8d9ee2e6e4ba4c`; nach erneutem Fetch identisch mit `origin/main`.
- Entwicklungsbranch: `fix/worktime-hardening`.
- Review und GitHub-CI: [Draft-PR #14](https://github.com/pkws100/TimeApp/pull/14).
- Der Ergebniscommit ist der Head dieses PRs; Commit-ID und abschliessender CI-Lauf werden dort und in der Uebergabe verlinkt. Die [PR-Pruefungen](https://github.com/pkws100/TimeApp/pull/14/checks) zeigen alle Jobs fuer diesen Stand.
- Produktive Datenbank, verbindliche Salden und Journale bleiben unveraendert. Fensteranrechnung bleibt Vorschau; Feature und SMS sind standardmaessig deaktiviert.
- Die zuvor vorhandenen lokalen Login- und PHP-Sicherheitsdoku-Aenderungen gehoeren nicht zu diesem Release Candidate.
- Kein Merge nach main, kein Force-Push, keine Produktionsmigration, kein Produktionsdeployment und keine Kundenaktivierung.

## Bestaetigte Ausgangsprobleme

1. Der [Ausgangslauf der CI](https://github.com/pkws100/TimeApp/actions/runs/43075584482) brach beim Composer-Audit ab. PHPUnit, Node und Browserchecks dieses Jobs wurden deshalb nicht ausgefuehrt; Firmware und Docker-Smoke waren erfolgreich.
2. Der Minutenworker aggregierte alle relevanten abgeschlossenen Buchungstage je Mitarbeiter und reconciliierte den gesamten resultierenden Zeitraum wiederholt.
3. Ein Start nach dem letzten Fensterende wurde von der Vorschlagsbedingung ausgeschlossen und erzeugte kein eigenstaendiges Benachrichtigungsereignis.
4. Ungeklaerte Pausendauern waren rechnerisch gekennzeichnet; ein gefuehrter, belegbarer Nachtrag der Intervalllage fehlte.
5. Versandfehler und ungeklaerte Sendungen wurden festgehalten, boten aber noch keine hinreichende Admin-Nachbearbeitung oder klare Providerannahme-Semantik.

## Sicherheitsupdates

`symfony/config` wurde von `7.4.17` und `symfony/filesystem` von `7.4.21` auf
`7.4.23` aktualisiert. Dies sind korrigierte Versionen innerhalb der bestehenden
7.4-Reihe; siehe die primaeren Advisories
[GHSA-5jff-8h8q-r5qx](https://github.com/symfony/symfony/security/advisories/GHSA-5jff-8h8q-r5qx)
und [GHSA-6prc-h956-hxjq](https://github.com/symfony/symfony/security/advisories/GHSA-6prc-h956-hxjq).
Kompatible transitive Updates: `symfony/deprecation-contracts` `3.7.1` → `3.8.1`
und `symfony/polyfill-mbstring` `1.43.0` → `1.43.3`. Keine Major-Upgrades.
Die vorhandenen Anforderungen in `composer.json` erlauben diese Versionen bereits;
deshalb war dort keine Erweiterung notwendig. `composer.lock` bleibt konsistent.
Keine Advisory-Ausnahmen oder abgeschaltete Sicherheitspruefungen.

Der erste CI-Lauf nach diesem Update bestaetigte Audit und alle 594 bisherigen
PHPUnit-Tests (2.774 Assertions). Anschliessend wurde ein weiterer, zuvor vom
Audit verdeckter Infrastrukturfehler sichtbar: `ubuntu-latest` verwendete Ubuntu
26.04, fuer das das gesperrte Playwright `1.59.1` keine Chromium-Abhaengigkeiten
installieren konnte. Der PHP-/Browserjob verwendet deshalb explizit Ubuntu 24.04,
das im installierten Playwright-Paket unterstuetzt ist. Keine Pruefstufe wurde
entfernt. Firmware- und Dockerjob bleiben erhalten.

Danach sichtbar gewordene Browserfehler waren lokal mit `TZ=UTC` reproduzierbar:
zwei bestehende Live-Timer-Tests mischten Berliner Fixture-Uhrzeiten und die
Browserzeitzone des Hosts. Playwright verwendet jetzt ausdruecklich
`Europe/Berlin`, entsprechend der betrieblichen Tages-/Stempelzeitzone. Beide
Tests bestehen anschliessend auch mit UTC-Host; Assertions bleiben unveraendert.

Eine echte Browser-Endkontrolle hatte zusaetzlich einen Login-Navigations-Timeout.
Seine genaue Ursache ist mangels damals aufbewahrter Fehlerartefakte rueckwirkend
nicht eindeutig zuzuordnen. Die anschliessende gezielte Untersuchung belegte
einen separaten, passenden Formularfehler deterministisch: `beforeinstallprompt`
baute das anonyme Loginformular neu auf und loeschte eingegebene Werte.
Der enge Fix verwendet bei identischem anonymen Seiten-/Sessionkontext das
vorhandene DOM-Patching. Eingabeknoten und Fokus bleiben erhalten; Auth-/User-/
Sessionwechsel ersetzen die Knoten weiterhin vollstaendig. Passwoerter bleiben
im Input und werden nicht in State/Storage gespeichert. Der Formularverlust
wurde mit einem roten Test vor dem Fix belegt; beide gezielten
Regressionspruefungen bestehen nach dem Fix. Der anschliessende
reale Workflow besteht mit aktivem Service Worker vollstaendig. Fehlertraces,
Screenshots und Serverlogs bleiben kuenftig auf Fehler privat erhalten.

Der erste vollstaendige CI-Lauf des Hardening-Codes bestand Composer, alle
633 PHP-Tests, Browser-Smokes, Docker und Firmware, scheiterte aber in einem
bestehenden Urlaubs-UI-Test. Dieser klickte unmittelbar nach asynchronem
Speichern/Reload auf `:visible`; vor geladener CSS waren die beabsichtigt
vorhandenen Desktop-/Mobilbuttons derselben Zeitraum-ID gleichzeitig sichtbar.
Eine kontrolliert zurueckgehaltene echte Stylesheet-Antwort bestaetigte dies.
Der Test startet nun ausdruecklich im Desktopmodus, wartet auf das vor dem
Speichern registrierte neue Load-Ereignis und den erfolgreichen POST/PUT und
bindet Korrekturen an die aus dem vollstaendig geladenen neuen Datensatz
ausgelesene Zeitraum-ID. Die Anzeigegrenzen
werden explizit geprueft; die spaetere mobile Modalpruefung bleibt erhalten.
Keine Produkt-CSS-Aenderung fuer diesen Befund.

Bereits ausgefuehrt:

| Befehl | Ergebnis |
| --- | --- |
| `composer validate --strict` | Erfolgreich. |
| `composer audit --locked --no-interaction` | Keine Sicherheitsmeldungen. |
| `composer check-platform-reqs` | Erfolgreich unter PHP 8.2. |
| `npm ci` | Erfolgreich. |
| `npm audit --audit-level=high` | Keine Sicherheitsmeldungen. |

## Implementierung und Betrieb

### Inkrementeller Worker

`WorkTimeReconciliationQueue` ersetzt den historischen MIN/MAX-Abgleich im
Minutenlauf. Eine Buchung hat genau einen Queue-Eintrag mit Quellenrevision,
verarbeiteter Revision, Claim-Token, Status, Versuchszahl und Backoff. Separate
Berechnungslauf-Datensaetze halten Quellhash, Berechnungshash und Ergebnis fest.
Trigger nehmen relevante Aenderungen in derselben Transaktion wie ihre Quelle
auf: Buchung, Archivierung/Wiederherstellung, Pausen, Regelzuordnung und
Genehmigungsentscheidungen. Alte Entscheidungen bleiben historisch erhalten;
ein anderer Quell-/Berechnungshash berechtigt nicht zur Wiederverwendung ihrer
Genehmigung.

Standardbatch: 100, maximal 1.000. Ein DB-spezifischer Worker-Lock schliesst
parallele Verarbeitung derselben Installation aus und erlaubt die sichere
Wiederaufnahme abgebrochener Claims. Quellenwechsel waehrend der Vorbereitung
stellen die Buchung erneut an. Fehler werden mit Klasse statt SQL-/Secrettext
gespeichert; maximal fuenf automatische Berechnungsversuche mit Backoff.

Vorbereitung findet ausserhalb des Accounting-Schreiblocks statt. Danach folgen
Accounting-Lock, Transaktion, Queue-Zeilensperre, erneute Revision-/Quellen- und
Periodenpruefung, Commit. Claim-Transaktionen enden vorher. Zuordnungswechsel
halten Mitarbeiter-Zuordnungslock vor Accounting-Lock vor Transaktion, damit
Queue-Trigger und historische FKs keine umgekehrte Sperrreihenfolge erzeugen.
Geschlossene Perioden veraendern keine bestehenden Abweichungen/Entscheidungen.

```bash
php bin/process-worktime-events.php --dry-run
php bin/process-worktime-events.php --batch-size=100
php bin/process-worktime-events.php --full-reconcile --user-id=123 --batch-size=100
php bin/benchmark-worktime-queue.php --report=/tmp/worktime-benchmark.json
```

`--full-reconcile` ist ein ausdruecklicher Wartungsmodus; er stellt Historie an,
der normale Minutenlauf tut dies nicht. `--user-id` gilt nur fuer diesen Modus.
Dry-Run schreibt und versendet nichts. Der Benchmark verwendet ausschliesslich
eine selbst erstellte und anschliessend geloeschte Scratch-Datenbank.

### Eigenstaendige Aussenzeitereignisse und Versand

`work_time_outside_window_started` entsteht unabhaengig von einem Endvorschlag
fuer einen noch offenen Einsatz ab Fensterende. Die neue Option ist standardmaessig
aus; konfigurierbare Verzoegerung ab Arbeitsbeginn standardmaessig fuenf Minuten.
Mitarbeiter-/Vorgesetztenkanaele bleiben getrennt. Auch Tage ohne Fenster,
mehrere Einsaetze, fortgesetzte Arbeit und Nachtfenster sind beruecksichtigt.
Keine erfundene Endzeit, keine automatische Schliessung.

Die Versandverwaltung trennt wartend, laufend, Providerannahme, nachweisliche
Nichtannahme, nicht konfiguriert, unklar, manuell erneut angefordert und
Testsimulation. Bis zu drei automatische Versuche nur bei sicher nicht
angenommenen, wiederholbaren Fehlern; Backoff 30/120 Sekunden. Insgesamt maximal
fuenf Versuche einschliesslich begruendeter manueller Anforderungen.
Teilannahmen, Netzwerk-Ausnahmen und unterbrochene Versandversuche werden nicht
erneut gesendet. Ein stabiler Providerschluessel und gegebenenfalls
Provider-Message-ID werden gespeichert. Externer Versand haelt keinen
Accounting-Lock. Providertexte/Exceptions gelangen nicht in neue WorkTime-Logs;
stattdessen werden feste sichere Fehlertexte verwendet.

`/admin/work-time/notifications` zeigt Versand und Audit. Die Aktion braucht
`work_time_notifications.manage`, Mitarbeiterzustaendigkeit, CSRF,
Pflichtbegruendung und idempotente Request-ID. Unklare oder angenommene
Sendungen bieten keine Wiederholung an. SMS bleibt ohne produktiven Provider
aus; ein aktivierter Mock ergibt nur `test_simulated`.
Auch historische SMS-Zeilen mit altem `sent`-Status werden als Testsimulation
uebernommen: Im Ausgangscode existierte ausschliesslich der netzwerkfreie Mock.
`accepted_at` bleibt dabei leer, waehrend `legacy_status` und urspruengliches
`sent_at` fuer die historische Nachvollziehbarkeit erhalten bleiben. Alte
Push-Annahmen bleiben von dieser Einordnung unberuehrt.

### Nachtraegliche Pausennachweise

Die Mitarbeiteransicht unter `/admin/work-time` zeigt Buchung, gespeicherte
Pausendauer, vorhandene Intervalle, fehlende Dauer und Klaerungsgrund.
`WorkTimePauseCorrectionService` ergaenzt ausschliesslich belegte, tatsaechlich
genommene Intervalle. Die Summe muss exakt der bereits gespeicherten Dauer
entsprechen. Vorhandene Intervalle werden nicht ersetzt und Pausenlagen nicht
geraten.

Die Aktion verlangt beide Rechte `timesheets.manage` und
`work_time_deviations.manage`, Mitarbeiterzustaendigkeit, CSRF, ausdrueckliche
Nachweisbestaetigung, Begruendung, Request-ID und aktuellen Quelltoken.
Kundenunterschriften, Archivierung und Periodensperren blockieren Nachtraege.
Die Signaturpruefung verwendet vor Schreibvorgaengen aktuelle sperrende Reads.
Vorher-/Nachher-Snapshots sowie der bestehende Timesheet-Audit halten die
Korrektur fest. Urspruengliche Stempel, Buchungsrevision und `net_minutes`
bleiben unveraendert. Der Abnahmefall ergibt danach 503 Arbeitsminuten,
450 Fensterminuten und 53 Aussenminuten; zuvor bleibt die Lage ungeklart.

### Additive Phinx-Migrationen

| Migration | Inhalt |
| --- | --- |
| `20261111130000_work_time_reconciliation_queue.php` | Queue, Berechnungslauf-Historie, Aenderungstrigger, einmaliger Backfill relevanter Altbuchungen. |
| `20261111140000_work_time_notification_delivery.php` | Versandmetadaten und Legacy-Status, konservative Statusuebernahme, Versuche, manuelle Anfragen, Statushistorie und gezielte Indizes. |
| `20261111150000_work_time_pause_corrections.php` | Unveraenderliche Nachweise mit Quellenbindung und Vorher-/Nachher-Snapshots. |

Keine Struktur-/Indexaenderung der systemversionierten `timesheets`. Migrationen
wurden ausschliesslich in Scratch-Datenbanken getestet. Fuer einen spaeteren
Entwicklungsserver sind Sicherungspunkt, gestoppter alter Worker und passende
Code-/Migrationsversion gemeinsam erforderlich. Der Queue-Backfill ist eine
einmalige, bestandsabhaengige Upgrade-Arbeit.

## Performancevergleich und SQL-Plaene

Synthetische MariaDB 10.11.14, PHP 8.2.35, 100 Mitarbeiter, drei Jahre mit je
240 Buchungstagen/Jahr, insgesamt 72.000 abgeschlossene und zehn offene
Buchungen. Bereits einmal gepruefter Bestand; keine externen Provider.

| Messung | Neu berechnete historische Buchungen | Laufzeit lokal |
| --- | ---: | ---: |
| Alter historischer Aggregations-/Vorschaupfad bei unveraendertem Bestand | 72.000 | 29,534791 s |
| Neuer kompletter Minutenworker bei unveraendertem Bestand | 0 | 0,018069 s |
| Neuer kompletter Minutenworker nach einer Buchungskorrektur | 1 | 0,043169 s |

Der alte Vergleich fuehrt Aggregation und historische Vorschau aus; die neue
Messung fuehrt den wirklichen Worker einschliesslich Open-Entry- und Queuepfad
aus. Einzelmessungen auf dieser Testumgebung, keine garantierten
Produktionslatenzen. Der komplette Benchmark einschliesslich Aufbau/Abbau der
72.010 Scratch-Buchungen lief in 85,1 Sekunden mit 16 MB PHP-Speicher.
Reproduzierbar ueber den oben genannten Benchmarkbefehl. Ein vorheriger Lauf
ergab 32,280333 / 0,009156 / 0,113414 Sekunden; die entscheidenden Invarianten
sind 72.000 gegen null bzw. genau eine historische Neuberechnung.

`EXPLAIN` zeigte:

| Query | Zugriff / Index | Geschaetzte Zeilen |
| --- | --- | ---: |
| Alter historischer MIN/MAX-Abgleich | `u: index/PRIMARY`; `t: ref/idx_timesheets_user_date`; `a: ref/uniq_work_time_assignment_start` | 100 User; 355 Buchungen/User; 1 Zuordnung |
| Alte Abfrage offener Buchungen mit `COALESCE(t.is_deleted,0)` | `index/PRIMARY` auf `timesheets` | 71.863 |
| Neue Abfrage offener Buchungen mit `t.is_deleted=0` | `range/idx_timesheets_issue_end` | 10 |
| Bereite Queue-Eintraege | `range/idx_work_time_queue_ready` | 2 (tatsaechlich 0 bereit) |

Der vorhandene Timesheet-Index reicht nach der praeziseren Bedingung aus; kein
neuer Quellenindex wurde hinzugefuegt. Schaetzwerte koennen mit Datenverteilung
und MariaDB-Statistik variieren.

## Regressionen und unabhaengige Reviews

Neue gezielte Tests pruefen Revisionswechsel, Pausenkorrektur und entwertete
Genehmigung, Archivierung/Wiederherstellung, Batchgrenzen, Backoff, zwei
parallele Prozesse, SIGKILL/Wiederaufnahme, Zuordnungs-/Accounting-Sperrreihenfolge,
Altbestandsmigration und vollstaendige systemversionierte Historie. Der
gemeinsame Scratch-Reset beruecksichtigt jetzt auch `SYSTEM VERSIONED`-Tabellen
und entfernt erst nach Quellenresets erzeugte Queue-Tombstones; dadurch bleibt
jede Testmethode isoliert.

Benachrichtigungstests decken 16:00/16:01/17:00 plus Verzoegerung,
vorzeitiges regulaeres Ende, Nacht-/Wochenendfaelle, fortgesetzte Arbeit,
Mehrfacheinsaetze, Teilannahme, unklare Providerantwort, begrenzte Wiederholung,
Mockkennzeichnung, Rechte, CSRF und Mitarbeiterzustaendigkeit ab.

Reale Browserablaeufe auf Scratch-Daten pruefen kuenftige Regelzuordnung,
fruehe Anwesenheit, ausdruecklichen Arbeitsbeginn, Pause, Fensteraustritt,
synthetischen Push, Endvorschlag/Korrektur, 503/450/53-Vorschau,
Pausennachweis und einen zweiten PHP-Server mit deaktiviertem Feature.
Vorhandene Konto-, Urlaubs-, NFC-, Export- und Offline-Regressionspruefungen
bleiben Bestandteil der unverkuerzten Suiten.

Unabhaengige UI-, Workflow- und Codereviews sind abgeschlossen. Bestaetigte
Findings wurden direkt behoben und gezielt geprueft: Zuordnungs-/Queue-Lockzyklus,
Signaturpruefung bei aelterem Transaktionssnapshot, veraltete Versandkanaele und
Vorgesetztenzustaendigkeiten, Telefonnummernwechsel waehrend eines Versandbatches,
voreilige Ganzzahlkonvertierung der Verzoegerung und mobile Hoehe des Suchfelds.
Der letzte Versandclaim liest Kontakt und Policy frisch; gemeinsame Fensterlogik
verhindert widerspruechliche Einstufungen bei Erzeugung und Versand. Die
Versandseite wurde mobil in Hell/Dunkel geprueft, ohne Seitenueberbreite.

## Tatsaechlich ausgefuehrte Abschlusspruefungen

| Befehl / Pruefung | Lokales Ergebnis |
| --- | --- |
| `composer validate --strict` | Exit 0. |
| `composer audit --locked --no-interaction` | Exit 0, keine Advisories. |
| `composer check-platform-reqs` | Exit 0 unter PHP 8.2.35. |
| `composer test` | PHPUnit meldet lokal **633 Tests / 3.065 Assertions erfolgreich**, 18:44 Minuten, 62,5 MB. Die lokale Composer-Prozesshuelle endete jedoch mit **143**; der Gesamtbefehl wird deshalb lokal nicht als Exit-0-Erfolg ausgewiesen. Der unverkuerzte Composer-Aufruf bestand anschliessend regulaer mit **633 Tests / 3.070 Assertions** in [GitHub-CI fuer den Implementierungscommit](https://github.com/pkws100/TimeApp/actions/runs/43091273048), nach der letzten Alt-SMS-Migrationskorrektur. Dieser Zwischenlauf scheiterte spaeter am Urlaubs-UI-Selektor; der finale Head muss alle Jobs bestehen. |
| `composer test -- --filter testUnconfiguredProviderIsReportedWithoutAnyNetworkAttempt` | Exit 0, 1 Test / 3 Assertions; Kontrolllauf fuer die Prozesshuelle. |
| `composer test -- --filter 'testKilledWorkerIsRecoveredAndParallelWorkerCannotTakeItsClaim\|testAssignmentWriterWaitsForAccountingBeforeStartingItsTransaction'` | Exit 0, **2 Tests / 16 Assertions**; auch Signal-/Parallelitaetsfaelle enden ueber Composer regulaer. |
| `php vendor/bin/phpunit tests/Integration/WorkTimeMigrationDatabaseTest.php` | Exit 0, **1 Test / 17 Assertions**, nach der letzten gezielten Alt-SMS-Statuskorrektur: Upgrade, DDL-Retry, Rollback, erneutes Upgrade und unveraenderte systemversionierte Quellen. |
| `npm ci` | Exit 0. |
| `npm audit --audit-level=high` | Exit 0, keine Sicherheitsmeldungen. |
| `TZ=UTC npm run ui:test:smoke` | Exit 0, nach dem Loginfix **109 bestanden / 11 uebersprungen**. Neun reale Tests werden separat ausgefuehrt; zwei optionale Live-Login-Pruefungen benoetigen nicht konfigurierte Zugangsdaten. Synthetische Admin-/Mitarbeiterlogins prueft der reale Runner. |
| `UI_REVIEW_ARTIFACT_DIR=/tmp/timeapp-hardening-notification-review npm run ui:test:real` | Exit 0 nach den Fixes, zuletzt **9/9 bestanden**, Service Worker unveraendert aktiv. Einschliesslich bestehendem Continue-/Confirm-Login, aller drei neuen RC-Ablaeufe, mobiler Versandseite in Hell/Dunkel sowie kompletter Abwesenheitsanlage, Kuerzung, Verlaengerung und mobilem Modal. |
| PHP-Syntax geaenderter/neuer Dateien | Erfolgreich, einschliesslich aller drei Migrationen und des nachtraeglich erweiterten Upgrade-Tests. |
| JavaScript-Syntax | Erfolgreich fuer 24 bisherige Dateien sowie neuen Login-Test; geaendertes App-JS und reale Browserfaelle erneut geprueft. |
| `git diff --check` | Erfolgreich. |

Der finale GitHub-Lauf und sein Ergebniscommit werden am
[PR #14](https://github.com/pkws100/TimeApp/pull/14) verlinkt; die
[Head-Pruefungen](https://github.com/pkws100/TimeApp/pull/14/checks) enthalten
Composer-Validierung/Audit, komplette PHPUnit-Suite, Node-Audit/Syntax,
Chromium-Smokes, reale Browserworkflows, Docker-Smokes und Firmware.

## Geaenderte Dateien

```text
.github/workflows/ci.yml
AGENTS.md
DATABASE.md
WORK_TIME_HARDENING_RC.md
WORK_TIME_WINDOWS.md
bin/benchmark-worktime-queue.php
bin/process-worktime-events.php
bin/run-revisable-account-ui-tests.php
bootstrap/app.php
composer.lock
migrations/20261111130000_work_time_reconciliation_queue.php
migrations/20261111140000_work_time_notification_delivery.php
migrations/20261111150000_work_time_pause_corrections.php
playwright.config.js
public/assets/css/admin.css
public/assets/js/admin-work-time.js
public/assets/js/app.js
src/Domain/Push/PushNotificationService.php
src/Domain/WorkTime/MockSmsProvider.php
src/Domain/WorkTime/SmsProvider.php
src/Domain/WorkTime/WorkTimeEventService.php
src/Domain/WorkTime/WorkTimeEventWindows.php
src/Domain/WorkTime/WorkTimeNotificationService.php
src/Domain/WorkTime/WorkTimePauseCorrectionService.php
src/Domain/WorkTime/WorkTimePolicyService.php
src/Domain/WorkTime/WorkTimePreviewService.php
src/Domain/WorkTime/WorkTimePushChannel.php
src/Domain/WorkTime/WorkTimeReconciliationQueue.php
src/Http/Controllers/AdminWorkTimeController.php
src/Http/Controllers/AdminWorkTimeNotificationController.php
tests/Integration/WorkTimeEventsDatabaseTest.php
tests/Integration/WorkTimeMigrationDatabaseTest.php
tests/Integration/WorkTimeNotificationDatabaseTest.php
tests/Integration/WorkTimePauseCorrectionDatabaseTest.php
tests/Integration/WorkTimePolicyAdminDatabaseTest.php
tests/Integration/WorkTimeReconciliationQueueDatabaseTest.php
tests/Support/MariaDbTestCase.php
tests/Support/work-time-hardening-ui.php
tests/Support/work-time-queue-worker.php
tests/Unit/WorkTimePushChannelTest.php
tests/e2e/app-login.spec.js
tests/e2e/revisable-accounts.real.spec.js
tests/e2e/work-time-hardening.real.spec.js
tests/e2e/work-time-pause-evidence.spec.js
tests/e2e/work-time.real.spec.js
```

## Entwicklungsserver

Die reale Browserpruefung verwendet den vorhandenen Runner mit einer eigens
erzeugten `timeapp_ui_*`-Scratch-Datenbank und einem separaten lokalen PHP-Server.
Ein darueber hinausgehender separater Entwicklungsserver ist erst zu verwenden,
wenn dessen Ziel und Deploymentverfahren eindeutig bekannt und freigegeben sind.
Produktive Migrationen oder Datenkopien sind kein Bestandteil dieses Auftrags.

## Offene fachliche Punkte

- Ein produktiver SMS-Provider ist bislang weder ausgewaehlt noch in der Codebasis oder den verfuegbaren verbundenen Werkzeugen vorhanden. Es werden keine Zugangsdaten erfunden und keine echten SMS versendet.
- Die verbindlich andere Zeitkonto-Anrechnung benoetigt weiterhin eine gesonderte fachliche Freigabe.

## Betriebsrisiken und Empfehlung

Der Release Candidate ist fuer die fachliche Abnahme auf einem isolierten
Entwicklungsserver vorgesehen, sobald dessen Ziel und freigegebenes Verfahren
feststehen und die Head-CI erfolgreich ist. Vor dem Upgrade einen geeigneten
Sicherungspunkt herstellen, den alten Minutenworker stoppen und Code sowie
Migrationen zusammen aktualisieren. Der einmalige Queue-Backfill kann bei
grossem Altbestand laenger laufen; der gemessene Minutenlauf ersetzt keine
Messung dieser Upgradezeit auf dem Zielsystem.

Feature und SMS zunaechst aus lassen, dann einen synthetischen Testmitarbeiter
mit kuenftiger Regelzuordnung abnehmen. Die separate Vorschau ersetzt keine
freigegebene verbindliche Abrechnung. Ohne Zustellnachweise kann auch eine
Providerannahme keine Endgeraetezustellung bestaetigen. Unklare Sendungen bleiben
bewusst ohne Wiederholung zur Pruefung stehen.

Ein eigener Docker-Build wurde lokal nicht ausgefuehrt; Docker-/Compose-Smokes
und beide Firmware-Builds mit nativen Tests werden in der vollstaendigen
GitHub-CI geprueft. Ein weiterer externer Entwicklungsserver wurde mangels
eindeutigem Ziel/Deploymentverfahren nicht verwendet. Der vorhandene isolierte
PHP-/MariaDB-Browserrunner ist der ausgefuehrte Entwicklungsnachweis.
