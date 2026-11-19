# Compliance 2027 · Phase 2C der digitalen Personalakte

Der lokale Ausgangscommit war `16da303ca00f6595c218e73022643e253186af67`. Docker-Smoke, Firmware sowie PHP-, Node- und Browser-Workflow des Phase-2B-Referenzlaufs waren erfolgreich. Phase 2C entwickelt die Admin-Personalakte für isolierte synthetische Daten. [GitHub-Referenzlauf](https://github.com/pkws100/TimeApp/actions/runs/44393525958)

## Admin-Oberfläche und Arbeitsablauf

- Die Navigation „Digitale Personalakte“ erscheint bei aktiviertem Testmodul und wirksamem `personnel_documents.view`. Die Übersicht sucht nach Name oder Personalnummer ausschließlich im Mitarbeiterbereich. Sie zeigt aktive und archivierte Akten, Dokumentzahlen, den fachlichen Prüfstand und offene historische Zuordnungen. Suchbegriffe, Filter und Seitennummern werden über geschützte Formulare übertragen.
- Mitarbeiterakten zeigen die erforderlichen Stammdaten, aktuelle Beschäftigung am gewählten Stichtag, frühere Abschnitte, Korrekturen und die Dokumentliste. Der Blick auf einen Wechsel vom Minijob zur regulären Beschäftigung hält beide Zeitabschnitte nachvollziehbar.
- Personen mit `view` und `manage` legen Metadaten an. Der Dokumenttyp, die ausgewählte Regelrevision und der Beschäftigungskontext werden gegen den tatsächlichen Datensatz geprüft. Die Quelle ist `manual`. Katalogoptionen enthalten Gültigkeit, Rechtsbezug und den Hinweis zur manuellen Prüfung. Ein falscher historischer Bezug wird durch begründete Archivierung und eine Neuanlage korrigiert. Die alte Datei und ihre Historie bleiben an der bisherigen Akte.
- Die Dokumentansicht zeigt Metadaten- und Dateiversionen, Vorgänger, Autor, UTC-Zeitpunkt, Typ, Größe, technische Prüfschritte, Original- und Signaturstatus, Halte- und Archivstatus. Archivierte Unterlagen bleiben im berechtigten Bereich lesbar. Bei aktiviertem Hold blockiert der Fachkern die Archivierung. Freigabe der Sperre, Archivierung und Korrektur erfordern die ausdrückliche Bestätigung im Formular.
- Fachliche Änderung, dokumentierter Zugriff, Beschäftigungsrevision und Scope-Ereignis haben getrennte Auditabschnitte. Die Ansicht begrenzt das Audit auf die betroffene Akte. Verwandte Dokumente werden ausschließlich bei wirksamem Zugriff auf beide Akten verknüpft. Die Systemadministration mit Delegationsrecht sieht die bereichsbezogenen Zuweisungen. Sonstige berechtigte Personen sehen ihre eigenen Scope-Ereignisse.

### Routen

Alle Routen verwenden den Präfix `/admin/personnel-documents`. Die fachlichen IDs sind technische Referenzen. Namen, Personalnummern, Dateien, Hashes, Schlüssel und CSRF-Token erscheinen nie in Links.

| Methode und Pfad unter dem Präfix | Funktion |
| --- | --- |
| `GET /`, `POST /search` | Gefilterte, seitengesteuerte Übersicht |
| `GET /users/{id}`, `POST /users/{id}/as-of` | Mitarbeiterakte und Stichtagsansicht |
| `GET /users/{id}/audit`, `POST /users/{id}/audit` | Seitenweise, berechtigte Auditansicht |
| `POST /users/{id}/documents` | Metadatenanlage |
| `GET /{id}` | Dokument, Versionen, Audit und Nachweise |
| `GET /{id}/correction`, `POST /{id}/correction-target`, `POST /{id}/correct` | Ziel im erlaubten Mitarbeiterbereich wählen, Zuordnung korrigieren |
| `POST /{id}/upload`, `POST /{id}/download` | Neue Dateiversion oder Download einer im Formular ausgewählten Version |
| `POST /{id}/review`, `POST /{id}/evidence` | Begründete Beanstandung oder Wiedereröffnung und eigene versionsgebundene Sichtung |
| `POST /{id}/hold`, `POST /{id}/archive` | Aufbewahrungssperre und Archivierung |

## HTTP-Sicherheit und private Dateien

`PersonnelSyntheticHttpBoundary` prüft expliziten Testmodus, private Fixture-Datei, PHP-Entwicklungsserver mit tatsächlichem Loopback-Listener, Host und Webroot. Proxy-Header dürfen diesen lokalen Weg nicht ersetzen. MariaDB muss dasselbe private Socket- und Datenverzeichnis der Fixture verwenden, Netzwerkzugriff deaktivieren und mit einer eigenen, exakt auf das Scratch-Schema begrenzten DB-Kennung erreichbar sein. Der Test hat separate Sessions mit `SameSite=Strict`, einen privaten PHP-Uploadordner und einen eigenen Dateischlüssel. Auch allgemeine Uploads, Exporte und der Backup-Arbeitsordner dieser Instanz liegen in der Fixture. Der Datenbankwechsel über die Test-Weboberfläche ist gesperrt. Im normalen Webbetrieb wird die Route nicht registriert.

`AdminPersonnelDocumentsController` prüft die aktuelle Serversitzung und das exakte Aktenrecht über `PersonnelAccessService`. Schreibaktionen der UI verlangen `view` und `manage`. Jeder POST verwendet einen an Session und Akteur gebundenen CSRF-Token. Cross-Site-Anfragen werden zusätzlich am Ursprung erkannt. Dienste prüfen tatsächliche Zugehörigkeit und aktuellen Mitarbeiterscope intern erneut. `manage`, `delegate`, `export` und `personnel_compliance.manage` bleiben separate Rechte. Die Oberfläche enthält keinen allgemeinen Export und keine produktive Restore-Aktion.

`PersonnelAdminQueryService` minimiert und begrenzt die ausgegebenen Daten. Zusammengesetzte Lesevorgänge verwenden denselben autorisierten Datenbank-Snapshot mit atomarem Zugriffsaudit. Ein Download nutzt eine eigene abgeschlossene Audittransaktion. Fremde und unbekannte Objekte antworten identisch. Bei Auditfehlern erfolgt keine Datenausgabe.

`PersonnelAdminFileTransfer` akzeptiert vollständige native PHP-Uploads aus dem privaten Testordner und prüft Größe, Typ und die zulässigen SHA-256-Werte der serverseitig erzeugten Testdateien. Ein frei ausgewähltes fremdes Dokument wird schon vor der privaten Verarbeitung abgewiesen. Das bestehende `PersonnelFileRuntime` behält seinen CLI-Testweg. Der zusätzliche HTTP-Weg erhält ausschließlich die explizit gebundene Fixture-Konfiguration. Formatprüfung, Scanner, private Ablage, Authentifizierung der Verschlüsselung und Domain-Audit bleiben die Phase-2B-Dienste.

Ein Upload bindet die Anfragekennung an Dateihash, Größe, erwarteten Dokumentkopf und Vorgängerversion. Der Fachkern sperrt den Dokumentkopf, prüft den aktuellen Zustand und schreibt die neue unveränderliche Version. Ein zweiter Versuch mit identischem Auftrag liefert dessen Ergebnis. Eine andere Datei unter derselben Kennung und eine konkurrierende Version führen zum Konflikt. Die Anzeige hält bei unklarer Übertragung die vorbereiteten Formulardaten im aktiven Tab. Nach einem Sitzungswechsel muss die Akte wieder geöffnet und autorisiert werden.

Der Download gibt höchstens 16 MiB vollständig verifizierte Originalbytes als `application/octet-stream` und `attachment` aus. Prüfschritte und erfolgreiches Audit gehen der Freigabe voraus. Die feste Bezeichnung `personalunterlage.pdf`, `.jpg` oder `.png` kommt aus der erlaubten MIME-Liste. Der tatsächliche Typ bleibt in der Versionsansicht sichtbar. HTTP-Antworten setzen `no-store`, `nosniff` und Schutz gegen eingebettete Frames. `strict-origin` beschränkt die Referrer-Angabe auf den Ursprung, damit Pfade und Suchdaten nicht in Referrer-Header gelangen und native POSTs ihren überprüfbaren Ursprung behalten. Zurücknavigation aus dem Browser-Cache verdeckt die alte Ansicht und prüft die kanonische Route erneut. Die Oberfläche schreibt keine Akteninhalte in Web Storage, Service Worker, Push oder allgemeine Exporte.

## Versionsgebundene Sichtung und Backup

Die additive Migration `20261118208000_personnel_evidence_reviews` führt die unveränderliche Tabelle `employee_document_evidence_reviews` ein. Ein Fremdschlüssel bindet den Nachweis an die konkrete Version und ihr Dokument. Trigger schützen Integrität und Historie. Vor der Erfassung wird die aktuelle technisch geprüfte Datei mit den bestehenden Dateidiensten erneut authentifiziert. Der Fachkern protokolliert Akteur, UTC, `manual_visual` in Version 1, Ergebnis, kontrollierten Grund, Originalart und Papierstatus. Er bestätigt ausschließlich beobachtete Lesbarkeit und Zuordnung im synthetischen Verfahren. Signaturstatus bleibt `unresolved`. Ein Dokumentkopf kann durch diesen Vorgang den früheren pauschalen Status `reviewed` nicht erhalten. Frühere Sichtungen bleiben nach einer neuen Version oder Archivierung lesbar.

Das allgemeine Systembackup behält die Tabelle als leeren privaten Platzhalter und weist bei vorhandenen Akten `partial` aus. Bekannte allgemeine Phase-2B-Archive bleiben validierbar. Das getrennte private Backup schützt die neue Nachweishistorie und überprüft den neuen Triggerbestand. Der isolierte Restore verwendet die passende Schema- und Katalogversion. Die Phase-2B-Sicherungen sind weiterhin auf ihren passenden Restore-Stand gebunden.

## Betriebsgrenzen und Übergabe

Die Testumgebung wird mit `npm run ui:test:personnel` eingerichtet. Sie verwendet ausschließlich erzeugte Beispieldateien und eigene synthetische Akten, Schlüssel und Rollen. Für einen Pilot mit echten Unterlagen sind ein gesondert geprüfter Produktionsadapter, Schlüssel- und Scannerbetrieb mit gepflegten Signaturen, Wiederanlauf in der Zielinfrastruktur, die Abnahme von Rollen und Datensparsamkeit sowie Rechts- und Datenschutzfreigabe nötig. Die fachliche Signaturvalidierung, eine gesetzliche Vollständigkeitsentscheidung und die Aussonderung bleiben offene Arbeitspakete. [Betriebsdetails](../DEPLOY.md) · [Backlog](COMPLIANCE_2027_PHASE2_BACKLOG.md)

## Prüfung und Dateiliste

| Tatsächlich ausgeführter Befehl | Ergebnis |
| --- | --- |
| `vendor/bin/phpunit --configuration phpunit.xml.dist --display-skipped --display-incomplete` | 800 Tests und 5.359 Assertions bestanden. Isolierter Lauf mit PHP 8.2.35, MariaDB 10.11.14, qpdf 11.3 und ClamAV 1.4.6 mit synthetischer Signatur. |
| `vendor/bin/phpunit --configuration phpunit.xml.dist --filter 'PersonnelFileDatabaseTest\|PersonnelPrivateBackupDatabaseTest\|PersonnelComplianceDatabaseTest\|PersonnelDocumentDatabaseTest\|BackupServiceTest'` | 64 Tests und 811 Assertions zu Dateiverifikation, Nachweisen, Schema und Restore im gezielten Lauf. |
| `npm run ui:test:personnel -- tests/e2e/personnel-admin.real.spec.js` und `npm run ui:test:personnel -- tests/e2e/personnel-admin.security.real.spec.js` | 16 Tests bestanden, je 8 in Chromium und Mobile WebKit. Geprüft wurden unter anderem PDF-, JPEG- und PNG-Uploads und byteidentische Downloads, Wiederholung nach Verbindungsabbruch, Versionskonflikte, CSRF, Rechteentzug, Auditfehler und deaktivierter Produktionsmodus. |
| `npm run ui:test:smoke -- --workers=2` und `npm run ui:test:webkit -- --workers=1` | 136 bestehende Chromium- und 24 Mobile-WebKit-Smokes bestanden. Die 21 realen Chromium-Workflows wurden separat geprüft. |
| `npm run ui:test:real` und `UI_REAL_TEST_SPEC=tests/e2e/revisable-accounts.real.spec.js UI_REAL_TEST_BROWSER=webkit npm run ui:test:real` | 21 Chromium- und 3 Mobile-WebKit-Workflows mit isolierten Datenbanken bestanden. |
| `PIO_CMD=<isoliertes-pio> sh terminal/firmware/pkws-time-terminal/build-test.sh` | Firmware 1.0 und die 1.1-Testvariante gebaut. Alle 33 nativen Firmware-Tests bestanden. Ausführung in einem isolierten Archiv des unveränderten Firmware-Quellstands und mit PlatformIO 6.1.19. |
| `composer validate --strict`, `composer check-platform-reqs`, `composer audit --locked --no-interaction`, `npm audit --audit-level=high` | Erfolgreich. Beide Audits meldeten keine Sicherheitslücken. |
| `actionlint`, `php -l`, `node --check`, `bash -n`, `apache2ctl -t`, `git diff --check` | Erfolgreich. Die Syntaxprüfung umfasste 422 PHP-Dateien. Der Produktions-Docker-Job des Ausgangscommits war erfolgreich. Der Entwicklungsserver besitzt weder Docker-Laufzeit noch Docker-Socket für einen neuen lokalen Image-Smoke. |

Code-, UI-, Workflow-, Datenschutz-, Datei- und Datenbankreviews prüften die aktiven Berechtigungen, Akten- und Datei-Zuordnungen, Auditgrenzen sowie allgemeines und privates Backup. Die Befunde führten zur Prüfsummenliste für synthetische Uploads, zur Bindung aller relevanten Testpfade an die Fixture, zur erneuten Autorisierung aus dem Browsercache sowie zu verbessertem Verhalten bei Sitzungsablauf und paralleler Formulareingabe. Der echte Browserlauf zeigte den Einfluss der Referrer-Regel auf native Formulare und eine Inline-Anzeige von Bild-Downloads in WebKit. Die finalen Prüfläufe bestätigten native POSTs mit überprüfbarer Herkunft und byteidentische Downloads als Anhang in beiden Browsern. Für die neuen HTTP-Prüfungen ist ein eigener GitHub-Actions-Job eingerichtet.

### Neue und geänderte Dateien

- Dokumentation und Betrieb verwenden `.env.example`, `AGENTS.md`, `DATABASE.md`, `README.md`, `DEPLOY.md`, `docs/COMPLIANCE_2027_PHASE2_BACKLOG.md` und `docs/COMPLIANCE_2027_PHASE2C_IMPLEMENTATION.md`.
- Einstieg und Authentifizierung liegen in `bootstrap/app.php`, `src/Domain/Admin/AdminContextService.php`, `src/Domain/Auth/CsrfService.php` und `src/Http/Response.php`.
- Die HTTP-Routen und der Dateitransfer liegen in `src/Http/Controllers/AdminPersonnelDocumentsController.php` und `src/Http/PersonnelAdminFileTransfer.php`. Die UI verwendet `src/Presentation/Admin/PersonnelDocumentsView.php`, `public/assets/css/admin-personnel-documents.css` und `public/assets/js/admin-personnel-documents.js`.
- Unter `src/Domain/Personnel/Compliance/` liegen `PersonnelAccessService.php`, `PersonnelAdminQueryService.php`, `PersonnelComplianceCatalogService.php`, `PersonnelDocumentService.php`, `PersonnelFileRuntime.php`, `PersonnelFileUnavailable.php` und `PersonnelSyntheticHttpBoundary.php`.
- Das Schema und die Sicherung verwenden `migrations/20261118208000_personnel_evidence_reviews.php`, `src/Domain/Backup/BackupService.php` und `src/Domain/Personnel/Backup/PersonnelBackupTables.php`.
- PHP-Prüfungen und Fixtures liegen in `tests/Integration/PersonnelAdminQueryDatabaseTest.php`, `tests/Integration/PersonnelComplianceDatabaseTest.php`, `tests/Integration/PersonnelFileDatabaseTest.php`, `tests/Integration/PersonnelPrivateBackupDatabaseTest.php`, `tests/Support/MariaDbTestCase.php` und `tests/Unit/PersonnelDocumentsViewTest.php`.
- Browser und CI verwenden `.github/workflows/ci.yml`, `package.json`, `playwright.config.js`, `playwright.personnel.config.js`, `tests/Support/personnel-admin-ui-fixtures.php`, `tests/Support/run-personnel-admin-ui.sh`, `tests/e2e/personnel-admin.real.spec.js` und `tests/e2e/personnel-admin.security.real.spec.js`.
