# Compliance 2027 · Phase 2A der digitalen Personalakte

Phase 2A liefert interne PHP-Dienste für Beschäftigungskontexte, Dokumentmetadaten und Versionsketten. Der Ausgangscommit ist `8afef0f15599b43f15290e65325560b643d1db2e`. Die Abnahme verwendet ausschließlich synthetische Daten in MariaDB-Scratch-Instanzen. Der Referenzlauf der Phase 1 war erfolgreich. [GitHub-Actions-Lauf](https://github.com/pkws100/TimeApp/actions/runs/44340682828)

## Dienste und Rechte

| Interner Dienst | Aufgabe und Berechtigung |
| --- | --- |
| `EmploymentContextService` | Kontextrevision hinzufügen oder ein Gültigkeitsintervall ersetzen mit `personnel_documents.manage`. Historie und wirksame Intervalle mit `personnel_documents.view` lesen. Beide Aktionen verlangen den passenden Mitarbeiterbereich. |
| `PersonnelDocumentService` | Metadaten registrieren, Status oder Sperre ändern, neue Metadatenversionen anhängen, archivieren und Zuordnung durch Archivieren und Neuanlegen korrigieren. `personnel_documents.manage` gilt für diese Befehle. Listen, Einzelansicht, Version und Historie verlangen `personnel_documents.view`. |
| `PersonnelComplianceCatalogService` | Quellen, Typen und konkrete Regelrevisionen intern lesen sowie Gültigkeit und überlappende Revisionen prüfen. `personnel_compliance.manage` autorisiert diese Katalogabfragen ohne Mitarbeiteraktenzugriff. Katalogänderungen bleiben versionierten Migrationen vorbehalten. |
| `PersonnelAccessService` | Aus der aktiven Serversitzung Akteur, exaktes DB-Recht und Mitarbeiterbereich prüfen. Mitarbeiterbereiche setzen oder entziehen. Zugriffsaudit für die internen Lese- und Ablehnungspfade. `personnel_documents.export` ist eine eigenständige Autorisierungsprüfung für künftige Exporte. |
| `PersonnelPermissionDelegationService` | Bestehende Rollen- und Benutzeroperationen gegen indirekte Rechteausweitung absichern. Änderungen von und an geschützten Rollen, zugehörigen Konten und privilegierten Rechten werden zentral geprüft. |
| `PersonnelCommandStore` | Idempotenz und atomare Belege für persönliche Schreibaufträge. Fachänderung, Audit und Auftragsbeleg laufen über dieselbe Datenbankverbindung in einer Transaktion. |

Der Mitarbeiterbereich wird pro Grantee und Mitarbeiter explizit geführt. Der jüngste Eintrag gilt. Ein aktiver Benutzer mit der echten Systemrolle `administrator` hat globalen Mitarbeiterbereich, sofern sein konkretes Personalaktenrecht in MariaDB gesetzt ist. Die Systemadministration vergibt und entzieht Mitarbeiterbereiche mit `personnel_documents.delegate`. Andere Rollen benötigen für jede Aktenoperation das konkrete Recht und den Mitarbeiterbereich. Ein widerrufener Bereich sperrt neue Aufrufe und Wiederholungen. Die Mehrrollenlogik bleibt wirksam. Bereich und Authentifizierung sind an dieselbe Datenbankverbindung gebunden.

Allgemeine Personal-, Projekt-, Datei-, Benutzer- und Rollenrechte gewähren keine Akteneinsicht. Personalaktenrechte werden aus expliziten aktiven DB-Zuweisungen abgeleitet. Eine Wildcard allein genügt nicht. Änderungen an einem Konto mit geschützten Rechten, auch Änderungen seiner Zugangsdaten und seines Status, sowie Vergabe, Änderung und Archivierung solcher Rollen erfordern die gesonderte Delegation und CSRF-Prüfung. Die Administratorrolle, die Wildcard und das Delegationsrecht erfordern zusätzlich aktive Systemadministration. Neue Standardzuweisungen gehen ausschließlich an den aktiven Systemadministrator. Die bestehenden API-Schreibpfade akzeptieren den CSRF-Token als `csrf_token` oder `X-CSRF-Token`.

Für einen Datenbankwechsel sind Systemadministration, Delegationsrecht und CSRF-Token erforderlich. Vorhandene Lese- und Backup-Schnittstellen behalten ihren bisherigen Schutz. Eine Browser-Sitzung ist an den gewählten Datenbank-Endpunkt gebunden. Vor Phase 2A aufgebaute Sitzungen benötigen einmalig eine Neuanmeldung. Diese Anwendung betreibt einen Unternehmenskontext. Die Mitarbeiterbereiche bilden die spätere Grundlage für weitere Organisationsgrenzen.

## Beschäftigung, Katalog und Dokumente

- Ein Beschäftigungsschlüssel identifiziert ein Beschäftigungsverhältnis je Mitarbeiter. Die Revisionsnummer steigt pro Schlüssel. Datiert werden inklusive Start- und Endtage. Ein leeres Ende gilt als offen. Verschiedene Schlüssel können parallel gelten. Innerhalb eines Schlüssels sind neue überlappende wirksame Intervalle gesperrt. Eine Ersatzrevision referenziert den verdrängten Eintrag. Alte Revisionen und daran gebundene Dokumente bleiben erhalten. Überlappende alte Einträge ohne belegte Vorgängerreferenz führen zu einem manuellen Klärungsbedarf.
- Der Mitarbeiterstatus wird vor neuen Kontexten, Dokumenten und Versionen geprüft. Historische Akten und Kontexte bleiben mit entsprechender Leseberechtigung auch bei archivierten Mitarbeitern abrufbar. Unbekannte Merkmale bleiben `unknown`. Ein Katalogbefund liefert `manual_review`, auch wenn nur ein Regelkandidat passt. Überlappende Gültigkeitsintervalle desselben Regelcodes werden als mehrdeutig ausgewiesen.
- Bei der Dokumentanlage werden Typ, ggf. konkrete Regelrevision und ggf. Beschäftigungskontext einschließlich Zeitraum und Personenbezug geprüft. Phase 2A akzeptiert die Erfassungsquelle `manual`. Die Zuordnungen und Erstellungsprovenienz sind unveränderlich. Eine Korrektur prüft den alten und den neuen Mitarbeiterbereich und archiviert den alten Kopf in derselben Transaktion, in der sie einen neuen anlegt und die Verknüpfung auditiert.
- Ein Dokument beginnt mit `pending`. Begründete Übergänge erfolgen zwischen `pending` und `rejected`. `reviewed` kann in Phase 2A nicht neu gesetzt werden. Die lesende Rückgabe stellt `evidence_verified` auf `false`, auch bei historisch gespeichertem Status `reviewed`. Der Nachweisstatus muss später an eine konkrete Dateiversion und deren geprüftes Original oder Signaturverfahren gebunden sein.
- Neue Versionen sind Metadateneinträge. Die erwartete Vorgängernummer `0` bezeichnet die erste Version. Nachfolgende Versionen werden serverseitig lückenlos vergeben und auf den direkten Vorgänger bezogen. `storage_key`, `sha256`, `mime_type` und `size_bytes` bleiben leer. Original- und Signaturstatus bleiben unbekannt. Eine Versionskollision oder ein veralteter Kopfstand führt zu einem Konflikt.
- Archivierung verlangt einen klassifizierten Grund und wird bei aktiver Aufbewahrungssperre verweigert. Archivierte Köpfe bleiben lesbar und können eine Sperrentscheidung erhalten. Status und Versionskette können dort nicht weiterbearbeitet werden. Aus der Metadatenfrist oder aus dem Ende einer Sperre folgt in Phase 2A keine Aussonderung.

## Datenbank und Ablauf

| Schema | Erweiterung |
| --- | --- |
| `employee_employment_contexts` | `supersedes_context_id` mit eindeutigem Nachfolger und zusammengesetztem FK auf denselben Mitarbeiter und Beschäftigungsschlüssel. |
| `employee_documents` | `lock_version` für konkurrierende Kopfänderungen und zusätzlicher Trigger für die Erfassungsquelle. |
| `employee_document_audit` | `related_document_id` mit restriktivem FK für die begründete Korrektur. |
| `employee_personnel_scope_events` | Append-only-Historie für Mitarbeiterbereich, Empfänger, Akteur, Entscheidung, Grundcode und Zeitpunkt. |
| `personnel_command_receipts` | Append-only-Auftragsbeleg pro Akteur und Request-Hash mit Operation, Payload-Hash sowie minimiertem Ergebnis. |

Auftragsbelege enthalten keine ursprüngliche Request-ID im Klartext und keine freie Nutzlast. Ein benannter MariaDB-Lock serialisiert Aufträge pro Datenbank, Akteur und Request-Hash. Die Autorisierung läuft vor der Belegabfrage erneut. Dieselbe ID mit identischer normalisierter Payload liefert das gespeicherte Ergebnis, auch wenn die erste Antwort verloren ging. Dieselbe ID mit anderer Operation oder Payload ergibt einen Konflikt. Scheitert die Transaktion, bleibt sie einschließlich Audit und Beleg ohne Teiländerung. Eine neue Anfragekennung bezeichnet einen neuen fachlichen Auftrag.

Die Kontextpflege sperrt den Mitarbeiter vor Revisions- und Überlappungsprüfung. Dokumentbefehle sperren den Kopf für konkurrierende Änderungen und den Vorgänger für die Versionskette. Korrekturen sperren die beteiligten Mitarbeiter in aufsteigender ID-Reihenfolge. Nach den fachlichen Sperren prüfen Schreibvorgänge aktive Rolle, konkretes Recht und Bereich durch aktuelle sperrende Abfragen erneut. Die Sperren bleiben bis zum Commit bestehen. Ein in der Zwischenzeit wirksam gewordener Rollen- oder Bereichsentzug verweigert den wartenden Auftrag. Rollen- und Kontenmutationen verwenden einen separaten, datenbankbezogenen Delegationslock. Die auditierten Datenlesezugriffe verwenden einen konsistenten `REPEATABLE READ`-Stand und geben die Daten nach Commit des Zugriffsaudits zurück. Für Lesezugriffe und Beleg-Replays ist der jeweilige Autorisierungsstand des Aufrufs maßgeblich.

Änderungen protokollieren technische Aktions- und Grundcodes. Beschäftigungsrevisionen selbst tragen Akteur, Vorgänger, Grund und Zeitpunkt. Die Dokumenthistorie zeigt bei Korrekturen den verknüpften Dokumentbezug, wenn beide Mitarbeiterbereiche lesbar sind. Historischer Freitext in Dokument-Audits und Beschäftigungsbegründungen wird bei der Ausgabe auf technische Platzhalter reduziert. Die gespeicherte Historie bleibt erhalten. Neue Zeitstempel schreibt der Dienst in UTC. Erlaubte Lesezugriffe setzen einen erfolgreich geschriebenen Auditdatensatz voraus. Bei authentifizierten Ablehnungen werden technische Aktion und Ergebnis ohne Dokument- oder Versionsbezug gespeichert. Fremde und unbekannte Beziehungen liefern dieselbe Fehlermeldung. Bei einer fehlgeschlagenen Auditierung einer Ablehnung bleibt der Zugriff gesperrt.

## Betrieb und Übergabe an 2B

`20261118204000_personnel_permission_delegation` ergänzt das Delegationsrecht. `20261118205000_personnel_phase_two_foundation` ergänzt das Schema und schützt neue Historie. Das Backup-Format bleibt 2. Die beiden neuen privaten Tabellen sind leere ZIP-Platzhalter. Das Manifest weist vorhandene ausgeschlossene Zeilen nur über den gemeinsamen Abdeckungsstatus aus. Restore-Validate prüft private Platzhalter, Integrität und passende Tabellenlisten. Bekannte Phase-1-Archive bleiben als Dry-Run gegen ihren ursprünglichen Umfang prüfbar. Die bestehende Phinx-Rücklaufgrenze und die Regelkatalogdaten bleiben erhalten. Allgemeine Backups enthalten betriebsnotwendige sensible Daten wie reguläre Login-Hashes. Vor Pilotbeginn die vorhandenen Sicherungsrechte und die Verwahrung der Archive prüfen.

Die Dienste werden intern und in isolierten Tests aufgerufen. Datei-Upload, Download, Personalakten-HTTP-Routen, Admin-Personalaktenansicht, PWA- und Self-Service-Funktionen sowie Prüfexporte folgen später. Für Phase 2B müssen privater Speicher, Quarantäne, unveränderte Originalbytes, serverseitig kontrollierte Storage-Keys, Schlüsseltrennung, zugriffsauditiertes Ausliefern und getrennte Sicherung mit Wiederanlauf gemeinsam erprobt werden. Die Freigabe echter Personaldaten erfordert die rechtliche, organisatorische und betriebliche Abnahme. [Phase-2-Backlog](COMPLIANCE_2027_PHASE2_BACKLOG.md)

## Geänderte Dateien

- Die Dokumentation umfasst `AGENTS.md`, `DATABASE.md`, `README.md`, `docs/COMPLIANCE_2027_PHASE2_BACKLOG.md` und `docs/COMPLIANCE_2027_PHASE2A_IMPLEMENTATION.md`.
- Schema und Referenzdaten liegen in `migrations/20261118204000_personnel_permission_delegation.php`, `migrations/20261118205000_personnel_phase_two_foundation.php`, `config/permissions.php` und `seeds/InitialReferenceSeeder.php`.
- Zum Fachkern unter `src/Domain/Personnel/Compliance/` gehören `EmploymentContextService.php`, `PersonnelAccessDenied.php`, `PersonnelAccessService.php`, `PersonnelCommandStore.php`, `PersonnelComplianceCatalogService.php`, `PersonnelConflict.php`, `PersonnelDate.php`, `PersonnelDelegationDenied.php`, `PersonnelDocumentService.php` und `PersonnelPermissionDelegationService.php`.
- Die bestehenden Dienste und Infrastruktur umfassen `src/Domain/Auth/AuthService.php`, `src/Domain/Backup/BackupService.php`, `src/Domain/Users/RoleService.php`, `src/Domain/Users/UserService.php` und `src/Infrastructure/Database/DatabaseConnection.php`.
- Zu bestehenden Webpfaden gehören `bootstrap/app.php`, `src/Http/Request.php` und unter `src/Http/Controllers/` die Dateien `AdminController.php`, `AdminManagementController.php`, `RoleController.php`, `SettingsController.php` und `UserController.php`.
- Die MariaDB-Tests unter `tests/Integration/` sind `PersonnelAccessScopeDatabaseTest.php`, `PersonnelComplianceDatabaseTest.php`, `PersonnelDelegationDatabaseTest.php`, `PersonnelDocumentDatabaseTest.php` und `PersonnelEmploymentCatalogTest.php`.
- Für Tests und Hilfsprozesse kommen `tests/Support/MariaDbTestCase.php`, `tests/Support/personnel-document-version-worker.php`, `tests/Unit/AdminDashboardControllerTest.php` und `tests/Unit/AdminManagementControllerTest.php` hinzu.

## Abnahme

| Prüfung | Ergebnis mit synthetischen Daten |
| --- | --- |
| Vollständige PHPUnit-Suite unter PHP 8.2.35 und MariaDB 10.11.14 | 747 Tests und 4.839 Assertions bestanden. Enthalten sind die Zeiterfassungs-, Zeitkonto-, Urlaubs- und Abwesenheitsregressionen. |
| Gezielte PHPUnit-Prüfung | 113 Tests und 1.594 Assertions für Personalakten, Berechtigungen, Backup sowie betroffene Admin- und API-Pfade bestanden. |
| Playwright Chromium | 136 Smoke-Tests und 21 separat gegen die isolierte Anwendung ausgeführte Browser-Workflows bestanden. |
| Playwright Mobile WebKit | 24 Smoke-Tests und 3 separat gegen die isolierte Anwendung ausgeführte Zeitkonto- und Abwesenheitsworkflows bestanden. |
| Statische Prüfungen | PHP-Syntax für 384 Dateien und JavaScript-Syntax bestanden. Composer-Validierung und Prüfung der Plattformanforderungen bestanden. |
| Abhängigkeitsprüfung | Composer-Audit für den Lockfile-Bestand und Node-Audit ohne gemeldete Sicherheitslücken. |

Der unabhängige Workflow-, Code-/Sicherheits- und UI-Review prüfte insbesondere den Rollen- und Bereichsentzug zwischen zwei Schreibversuchen, vertrauliche Metadaten im Backup, die Archivierungsattribution und die bestehenden Admin-Formulare. Der erste kombinierte Testlauf zeigte einen Rückgriff auf den Sitzungsakteur bei direkter Archivierung aus einem anderen isolierten Test. Die HTTP-Pfade übergeben den serverseitig authentifizierten Akteur jetzt ausdrücklich. Der erneute Testlauf prüft diesen Pfad gemeinsam mit den übrigen Integrationen. Ein tatsächlicher Chromium-Test prüfte außerdem CSRF-Formularfelder, zulässige gewöhnliche Rollenänderungen, HTTP 403 bei geschützten Zugriffen und den schreibgeschützten Datenbankbereich.
