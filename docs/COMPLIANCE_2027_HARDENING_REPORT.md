# Compliance 2027 · Hardening der Phase 1

Ausgangsstand war `3c862a7ccac4fa48f4de117651200c761c3bbf6f` auf `main`, vor Arbeitsbeginn mit GitHub abgeglichen. Der Hardening-Stand liegt im lokalen Commit, der diesen Bericht enthält. Alle Datenbankprüfungen liefen unter MariaDB 10.11 in eigenen Scratch-Datenbanken mit synthetischen Daten. Der Auftrag umfasst Schema, Sicherung und Abwesenheitsworkflow. Dateioperationen für Personalunterlagen gehören zu Phase 2.

## Historie und Migrationen

Die Phase-1-Trigger blockierten `UPDATE`. Die Fremdschlüssel verhinderten nur das Löschen bereits referenzierter Zeilen. Ein unreferenzierter Versions-, Regel- oder Auditeintrag ließ sich direkt löschen. Ein Dokumentkopf konnte seine Zuordnung innerhalb der zulässigen Fremdschlüssel wechseln.

`20261118203000_harden_personnel_compliance_history.php` blockiert direkte Löschungen in sieben Historientabellen und an Dokumentköpfen. Der Dokumentkopf schützt ID, Person, Typ, Kontext, Regel und Erstellungsprovenienz. Archivierung und Änderungen von Prüf- oder Sperrstatus bleiben möglich. Korrekturen der Zuordnung erfolgen später über nachvollziehbare Archivierung und Neuanlage. Fremdschlüssel und Versionsketten bleiben wirksam. App- und Backup-Rollen erhalten dadurch keine zusätzlichen Rechte.

Der frühere Phinx-Rücklauf prüfte private Datensätze, konnte aber zusätzliche Katalogrevisionen durch `DROP TABLE` verlieren. MariaDB übernimmt solche DDL-Schritte implizit. `20261118202500_personnel_compliance_rollback_boundary.php` protokolliert eine unwiderrufliche Rücklaufgrenze vor dem ersten neuen Trigger-DDL. Die Hardening-Migration schützt anschließend auch ihren eigenen Rücklauf. Beide Pfade brechen kontrolliert ab, bevor sie den Migrationsstand ändern. Das gilt auch für den reinen veröffentlichten Ausgangskatalog. Nach einer unterbrochenen Triggeranlage normal migrieren, damit fehlende Trigger ergänzt werden. Für eine Rückkehr auf einen alten Datenbankstand ist die separat geprüfte Wiederherstellung einer passenden Sicherung vorgesehen. [Datenmodell](COMPLIANCE_2027_DATA_MODEL.md)

Die Integrationstests prüfen unter anderem nicht referenzierte Historienzeilen, Einfügen, Versionsbezüge, Archivierung, Seed-Wiederholung, den Fortbestand des Ausgangskatalogs und den Abbruch bei zusätzlichen Revisionen und bei privaten Zeilen. Der Scratch-Reset kann seine eigenen Testtabellen per DDL leeren und lässt die Schutzregeln aktiv. Die Runtime sollte vor einem Pilot eine getrennte DB-Kennung mit tabellenspezifischen Rechten erhalten. Eine privilegierte Datenbankrolle kann DDL-Schutz administrativ ändern. Ein künftig freigegebener Aussonderungsprozess muss separat genehmigt, nachvollziehbar und isoliert erprobt sein.

## Systembackup

Die bisherige ZIP-Datei enthielt leere private Personalaktentabellen und einen Warnhinweis. Daraus ging nicht hervor, ob die ausgeschlossenen Tabellen Daten enthielten. Format 2 kennzeichnet den Sicherungsumfang, belegte Ausschlüsse und nötige separate Sicherungen. Erfasst werden auch vorhandene systemversionierte Buchungsdaten, redigierte Werte und der tatsächlich konfigurierte Runtime-Override. Private Zeilen verbleiben außerhalb des Archivs. Partielle Downloads sind im Dateinamen sowie per Antwort-Header erkennbar. Eine strikte Vollständigkeitsanforderung bricht bei ausgeschlossenen Daten ab.

Restore-Validate überprüft Struktur, SHA-256-Werte und Metadaten der enthaltenen Dateien. Es weist personenbezogene Zeilen in privaten Platzhaltern ab und liefert eine geprüfte Zusammenfassung ohne Rückgabe freier Manifestfelder. Kompatible Altarchive besitzen weiterhin keinen vollständigen Nachweis der ursprünglichen Abdeckung. Die Hashes sind unbeglaubigt. Für Authentizität, ein vollständiges Personalakten-Backup und die produktive Wiederherstellung sind gesonderte Verfahren nötig. [Datenmodell und Backup-Format](COMPLIANCE_2027_DATA_MODEL.md#backup-und-installation)

## Playwright und GitHub-CI

Die historischen GitHub-Läufe zu `d16f40a` und `3c862a7` scheiterten im Abwesenheitstest beim Verlängern nach vorheriger Verkürzung. Im späteren Lauf waren PHPUnit, die Browser-Smokes, Firmware und der Docker-Smoke erfolgreich. Der Timeout lag beim Warten auf den Speichervorgang. Die GitHub-Logs enthielten einen temporären Trace-Pfad, der Lauf hatte keine herunterladbaren Artefakte. [CI für `d16f40a`](https://github.com/pkws100/TimeApp/actions/runs/44316483996) · [CI für `3c862a7`](https://github.com/pkws100/TimeApp/actions/runs/44334551912)

Der alte Test wartete auf die Teilzeichenfolge `neu`. Diese steckt bereits im Platzhalter `erneut pruefen`. Der Test konnte deshalb die Warnungs-Checkbox vor der echten Vorschau abfragen. Die CSS-Regel `display: flex` übersteuerte die Darstellung des HTML-Attributs `hidden`. So ließ sich das Feld schon vor dem Vorschauergebnis anklicken. Beim Eintreffen der Jahreswechselwarnung setzte die Formularlogik die Bestätigung zurück. Der Speicherbutton blieb korrekt gesperrt. Eine kontrollierte Reproduktion mit echter PHP-/MariaDB-Vorschau zeigte `200` für die Jahreswechselvorschau und danach keinen `PUT`. Playwright meldete wiederholt, dass der Speicherbutton deaktiviert war.

Die CSS-Regel für das Bestätigungsfeld respektiert jetzt `hidden`. Die synthetischen Testdaten garantieren den Jahreswechsel bei der Verlängerung. Der Test wartet auf die tatsächliche Tagesdifferenz, prüft die Warnung und den zunächst gesperrten Speicherbutton und bestätigt die Warnung. Beim Speichern prüft er die nach dem Reload angezeigte Zeile beziehungsweise den sichtbaren Fehler. Der Test verfolgt die stabile Zeitraum-ID, Prüfkonflikte und das Urlaubskonto. Eine zusätzliche MariaDB-Regression prüft zwei konkurrierende Vorschauen auf demselben Versionsstand sowie die unveränderten Buchungen eines zweiten Mitarbeiters. Die Timeout-Vorgabe blieb gleich. In CI werden bei einem Fehler des isolierten Real-Workflows dessen Traces, Screenshots und PHP-Testserver-Logs fünf Tage bereitgestellt. Temporäre Datenbankkonfigurationen gehören nicht zu den Artefakten. Der lokale Fix-Commit hat einen eigenen GitHub-CI-Lauf erst nach separat freigegebener Übertragung. [GitHub-Dokumentation zur Artefakt-Aktion](https://github.com/actions/upload-artifact)

## Freigabegrenzen

| Stufe | Bewertung |
| --- | --- |
| Entwicklung | Phase 2 kann mit synthetischen Daten auf dieser Grundlage beginnen. |
| Pilot mit echten Unterlagen | Erfordert freigegebene Rollendelegation und Objektberechtigungen, getrennte DB-Rollen, privaten verschlüsselten Speicher, auditierten Zugriff und eine separat erprobte Sicherung samt Wiederanlauf. |
| Produktivbetrieb | Erfordert zusätzlich die fachliche, rechtliche, datenschutzrechtliche und betriebliche Abnahme für Originale, Signaturen, Aufbewahrung, Wartung und Prüfbereitstellung. Die Produktivfreigabe bleibt ein eigener Auftrag. |

[Phase-2-Backlog](COMPLIANCE_2027_PHASE2_BACKLOG.md)

## Test- und Review-Nachweise

| Prüfung | Lokaler Nachweis |
| --- | --- |
| PHP-Syntax | 366 PHP-Dateien mit `php -l` geprüft. |
| Composer | `composer validate --strict`, `composer check-platform-reqs` und `composer audit --locked --no-interaction` bestanden. Keine Advisories gemeldet. |
| Node und CI | `node --check` für Anwendungs-JavaScript, E2E-Specs und beide Playwright-Konfigurationen. `npm audit --audit-level=high` meldete keine Schwachstellen. `actionlint` 1.7.12 prüfte die geänderte CI-Datei. |
| Gezieltes PHPUnit und MariaDB | **70 Tests, 1.138 Assertions**. Geprüft wurden Personalakten, Katalog, Backup, Abwesenheit, Urlaubskonto und die betroffenen historischen Migrationspfade. |
| Vollständiges PHPUnit | `vendor/bin/phpunit --configuration phpunit.xml.dist --display-skipped --display-incomplete` in einer separaten MariaDB-10.11.14-Instanz. **720 Tests, 4.418 Assertions**, keine Fehler oder Skips. |
| Verlässlichkeit des Realtests | `UI_REAL_TEST_SPEC=tests/e2e/revisable-accounts.real.spec.js npm run ui:test:real` lief dreimal erfolgreich, jedes Mal mit frischer Datenbank. Je Lauf drei Tests inklusive des Abwesenheitsfalls. |
| Vollständiger Realtest | `npm run ui:test:real` bestand mit **21 Tests**. |
| Reales WebKit | `UI_REAL_TEST_BROWSER=webkit UI_REAL_TEST_SPEC=tests/e2e/revisable-accounts.real.spec.js npm run ui:test:real` bestand mit **3 Tests**. |
| Chromium-Smokes | `npm run ui:test:smoke` bestand mit **136 Tests**, einschließlich der optionalen Login-Prüfungen in der isolierten Umgebung. Die dort vorgesehenen 21 Realtest-Skips wurden im Realtest-Lauf ausgeführt. |
| WebKit-Smokes | `npm run ui:test:webkit` bestand mit **24 Tests**. |

Der erste kombinierte lokale PHPUnit-Aufruf hatte zwei HTTP-Testfehler. Der lokale Teststarter hatte die Scratch-Override-Variable in `$_SERVER` vorinitialisiert. Dadurch wurde der verbindungsspezifische Override der beiden Tests übergangen. Mit korrigierter Testisolation bestand derselbe Umfang. Die Testverbindungen blieben durchgehend auf Scratch-Datenbanken beschränkt. Für Docker und Firmware standen die Werkzeuge lokal nicht zur Verfügung. Die entsprechenden Jobs im historischen GitHub-Lauf zu `3c862a7` waren grün. Die neue CI-Ausführung bleibt bis zur freigegebenen Übertragung ausstehend.

Der unabhängige Code-, Datenbank-, Sicherheits-, Workflow- und UI-Review prüfte unter anderem die Sichtbarkeit von Tabellen bei eingeschränkten Datenbankrechten, das konfigurierte Runtime-Override und die Validierung von ZIP-Dateitypen. Die Felder, Pfade und Schutzregeln wurden nach den Korrekturen gezielt nachgetestet. Die produktive DB-Kennungs- und Schlüsseltrennung, ein attestierter Sicherungsursprung und ein vollständiger Wiederanlauftest bleiben vor Pilotbetrieb offene Aufgaben.

## Geänderte Dateien

- Migrationen `migrations/20261118202500_personnel_compliance_rollback_boundary.php` und `migrations/20261118203000_harden_personnel_compliance_history.php`.
- Backup und HTTP `src/Domain/Backup/BackupCoverage.php`, `src/Domain/Backup/BackupService.php`, `src/Domain/Backup/BackupDatabaseSource.php`, `src/Domain/Backup/PdoBackupDatabaseSource.php`, `src/Http/Controllers/BackupController.php` und `bootstrap/app.php`.
- Browser und CI `public/assets/css/admin.css`, `bin/run-revisable-account-ui-tests.php`, `tests/e2e/revisable-accounts.real.spec.js` und `.github/workflows/ci.yml`.
- Weitere Tests `tests/Unit/BackupServiceTest.php`, `tests/Integration/PersonnelComplianceDatabaseTest.php`, `tests/Integration/AbsencePeriodDatabaseTest.php`, `tests/Integration/AbsencePeriodMigrationDatabaseTest.php`, `tests/Integration/ProjectWorkReportsDatabaseTest.php`, `tests/Integration/WorkTimeMigrationDatabaseTest.php` und `tests/Support/MariaDbTestCase.php`.
- Dokumentation `AGENTS.md`, `DATABASE.md`, `README.md`, `docs/COMPLIANCE_2027_DATA_MODEL.md`, `docs/COMPLIANCE_2027_PHASE2_BACKLOG.md` und dieser Bericht.
