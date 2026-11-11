# Pausennachweise, Projektformulare und Materialentwuerfe

## Ausgangspunkt und Grenzen

- Ausgangscommit: `9774a446ffb247d27eb5a40c7d6d9e73d1a7942b`; nach erneutem Fetch identisch mit `origin/main`.
- Arbeitsbranch: `fix/worktime-pause-and-form-stability`.
- Implementierungscommit: `ac5b2de486074c208bd82f41e3dbaf9f00efe08b`. Der abschliessende Ergebnisstand mit Pruefnachweisen steht in [Draft-PR #15](https://github.com/pkws100/TimeApp/pull/15) und in der Uebergabe.
- Keine Produktionsmigration, kein Deployment, kein Force-Push und kein automatischer Merge nach main. Keine Aktivierung bei realen Mitarbeitern; Fensteranrechnung bleibt Vorschau.
- Die vorher vorhandenen lokalen Aenderungen an `DEPLOY.md` und `AdminAuthController.php` wurden beim urspruenglichen Abschluss ausgespart. Am 12.11.2026 hat der Nutzer deren Einchecken und den Fast-Forward auf GitHub ausdruecklich beauftragt; siehe Nachtrag unten.

## Reproduzierte Fehler und Umsetzung

### Belegte Pausen und alte Schreibwege

Der neue Integrationstest wurde zuerst gegen den unveraenderten Domaincode des
Ausgangscommits ausgefuehrt. Nach einem belegten Intervall 12:00–12:30 mit
`source=admin_evidence` loeschte die alte Aktion
`pause/manual_break_minutes=30` saemtliche Intervalle und erzeugte eine neue
Timesheet-Quellversion, waehrend der Nachweis in `work_time_pause_corrections`
weiterbestand. Roter Beleg: `/tmp/timeapp-pause-evidence-baseline-red.log`.

`TimesheetPauseEvidenceGuard` prueft nun vor alten Schreibvorgaengen unter
Accounting-Lock und Transaktion auf Nachweisaudit oder `admin_evidence`.
Geschuetzt sind Mitarbeiter, Arbeitstag, Stempel, Brutto-/Pausen-/Nettodauer,
Buchungsart und Herkunft sowie Eingriffe in die Pausenintervalle. Der Schutz
haengt nicht am WorkTime-Feature-Flag. Bereits durch alte Versionen verlorene
Intervalle bleiben ueber den Auditmarker gegen weitere Umdeutung geschuetzt.

App-/Offline-/Terminal-Sync, Admin-Korrektur und defensive Korrektur einer falsch
verknuepften alten Abwesenheitszeile verwenden denselben Guard. Abweisungen
erfolgen vor Teilschreibungen und vor einer Signaturarchivierung. Metadaten und
nachvollziehbares Archivieren/Wiederherstellen bleiben moeglich; Nachweisdaten
werden dabei erhalten. Normale manuelle Pausen ohne geschuetzten Nachweis
funktionieren weiterhin. Eine Aenderung belegter Zeitdaten ueber den alten Weg
wird mit Hinweis auf einen ausdruecklichen revisionsfaehigen Korrekturweg
abgewiesen. Es wird keine stille Ersetzung alter Nachweise eingefuehrt.

Der vorhandene `WorkTimePauseCorrectionService` ergaenzt bislang fehlende
tatsaechliche Intervalle zur unveraenderten gespeicherten Pausendauer. Bereits
vollstaendig belegte Nachweise ersetzt er nicht. Ein allgemeiner Revisions-/
Ersetzungsweg fuer fehlerhafte Nachweise ist weiterhin nicht implementiert;
entsprechende Legacy-Aenderungen bleiben gesperrt. Bereits historisch verlorene
Intervalle werden nicht automatisch rekonstruiert.

Gepruefte weitere Writer: `TimesheetService` liest/berechnet nur; der
Backup-Restore kann weiterhin nur validieren. Die vorhandenen CLI-Fixtures
schreiben ausschliesslich synthetische Testdaten. Eine Struktur- oder
Historienaenderung an `timesheets` ist fuer diesen Schutz nicht erforderlich.

Die Schutzmatrix fand zudem zwei echte Wrapperfehler: Ein ueber den Guard
ausgefuehrter Callback mit Rueckgabewert `null`/`void` wurde durch
`?->... ?? $callback()` nochmals ausgefuehrt. Bei Admin-Archiv/Restore entstanden
doppelte Auditaktionen; beim Account-Write-Wrapper verlor der zweite Aufruf den
Accounting-Lock. Beide belegten Faelle verwenden jetzt eine explizite
Guard-Praesenzpruefung. Bestehende Historien und Salden werden nicht veraendert.
Rote Belege: `/tmp/timeapp-pause-evidence-metadata-debug.log` und
`/tmp/timeapp-account-void-wrapper-baseline-red.log`.

### Admin-Projektstammdaten

In `admin-projects.js` und dem globalen Admin-Layout wurde kein regelmaessiger
Reload gefunden. Die nachgewiesenen Verlustpfade waren Fremdformulare/Links und
Navigationen; die bisherige Dirty-Pruefung deckte hauptsaechlich Dispatch ab.

Der bestehende Master-Dirty-State schuetzt nun Fremdposts, Links und
Browsernavigation. Der Dialog bietet bewusstes Speichern, ausdrueckliches
Verwerfen oder Weiterbearbeiten. Ein Fremdpost wird nicht automatisch nach
einem Save ausgefuehrt. Verwerfen liest den gespeicherten Stand neu; ein
Abbruch verwirft auch die zugehoerige Aktionsabsicht. Die Abbruchschaltflaeche
bleibt auf Touchgeraeten waehrend dieses Lesevorgangs benutzbar.

Nur ein ausdrueckliches Update eines bestehenden Masterformulars wird per
`fetch` gespeichert. Servervalidierung bleibt massgeblich. Bei 422 werden
feldbezogene Fehler in dieselben Formular-/Eingabeknoten uebernommen;
Texte, andere Feldwerte und Dateiauswahlen bleiben erhalten. Sitzungs- und
Transportfehler behalten die Eingaben ohne automatische POST-Wiederholung.
Einzel-Submit-Sperren verhindern Mehrfachanfragen. Wenn waehrend des Saves neue Eingaben
eintreffen, bleiben diese ungespeichert sichtbar statt durch eine Navigation
verloren zu gehen.

Nach bestaetigtem Update werden Feld, Cursor und Scrollposition restauriert.
Hierfuer speichert `sessionStorage` nur Positionsmetadaten, keine Arbeitsanweisung.
Der echte Browsertest deckte einen weiteren Positionsfehler auf: `change`/Blur
feuerte nach dem automatischen Scroll zum Fremdaktionsbutton und ueberschrieb
die Bearbeitungsposition. Die Position wird jetzt aus eigentlichen Eingabe-/
Cursorereignissen und bewussten Scrollgesten erfasst, nicht aus diesem Blur.
Die strikte Positionspruefung blieb erhalten.

Eine vorhandene Server-422-Seite bleibt clientseitig ungespeichert statt als
neue saubere Ausgangsbasis zu gelten. Neue Projektanlagen behalten den nativen
Submit-/Redirect-Pfad mit Doppel-Submit-Sperre; damit entsteht bei spaeteren
Eingaben waehrend einer Anlage kein zweiter Create-Pfad. Nach dem Redirect
arbeitet das Formular mit der neu vergebenen ID als Update.

### PWA-Materialentwuerfe

Ein zusaetzlicher roter Browsertest mit dem unveraenderten `app.js` des
Ausgangscommits zeigte den Verlust der Materialbeschreibung nach echtem Reload
(`/tmp/timeapp-material-baseline.log`). Das bestehende DOM-Patching wurde
beibehalten.

Materialentwuerfe nutzen nun den vorhandenen IndexedDB-Cache mit Schluesseln
`project_material_draft_v1_user_{id}_project_{id}` und einer geordneten
Speicherqueue. Erst eine Benutzeraktion macht den Entwurf schreibwuerdig.
Beschreibung, Menge, Einheit, Notiz und Datum ueberstehen Neuinitialisierung
und Reload. Ein Entwurf erzeugt keine automatische Materialbuchung.

Sessiongeneration, Mitarbeiter-/Projektbezug und bestaetigte Projektberechtigung
begrenzen jede Wiederherstellung und spaete Antwort. Ausdruecklicher Logout
bereinigt eigene Entwuerfe; Sitzungsablauf erlaubt eine sichere Wiederaufnahme
fuer denselben Mitarbeiter. Bestaetigter Berechtigungsentzug entfernt den
Projektentwurf, waehrend ein voruebergehender Serverfehler ihn erhaelt.
Lokale Speicher-/Loeschfehler werden ohne vertrauliche Konsolenausgaben angezeigt.
Ein erfolgreicher Server-Save mit anschliessendem Listenladefehler stellt den
bereits gespeicherten Entwurf nicht wieder her.

Vor einem ausdruecklichen Save werden UUID und Payload lokal gesichert. Bei
unklarer Antwort bleibt genau diese Speicherabsicht erhalten und kann bewusst
erneut geprueft werden; die Felder werden fuer diese Absicht nicht still
geaendert. Eine klare Validierungsabweisung erlaubt Korrektur, ohne eine neue
Serverbuchung zu erzeugen. Erfolgreiche Bereinigung beachtet die genaue
Entwurfsrevision sowie Projekt-/Sessionkontext. DOM-Knoten, Fokus, Cursor,
visuelle Scrollposition und bereits ausgewaehlte Dateien anderer Abschnitte
bleiben bei Hintergrundantworten erhalten.

Das Backend akzeptiert optional `client_request_id` als UUID. Ein eindeutiger
Index je Autor/Anfrage und ein Hash normalisierter Materialfelder sichern
parallele bzw. verlorene Antworten ab. Wiederholung derselben Buchung ergibt
200 statt 201; andere Angaben unter derselben ID ergeben 409. Projektzugriff
wird vor jedem Replay neu geprueft. Archivierte Buchungen behalten ihren Key
und werden bei einem Replay nicht neu angelegt. Alte Clients ohne ID bleiben
kompatibel. Die vorhandene Service-Methode `create()` behaelt ihre Rueckgabe.

Additive Phinx-Migration:
`20261111220000_project_material_request_ids.php` erweitert nur
`project_material_entries` um zwei nullable Metadatenspalten und den Unique-Index.
Altmaterial, Projekte und systemversionierte Buchungen werden nicht umgeschrieben.

### Kernarbeitszeit in Benutzerverwaltung und PWA

Das bestehende Feld heisst eindeutig **Sollstundenmodell**. Ein eigener
Abschnitt **Kernarbeitszeit / Arbeitszeitfenster** zeigt berechtigten Personen
aktuelle und naechste geplante Zuordnung, individuelle Gueltigkeit und kompakte
Wochentags-/Nachtfenster sowie einen mitarbeiterbezogenen Verwaltungslink.
Die Zielauswahl wird vorausgewaehlt. Bei global ausgeschaltetem Feature wird
die gespeicherte Konfiguration ausdruecklich als unwirksam gekennzeichnet.
Fehlende Rechte und fremde Supervisor-Zustaendigkeiten geben keine Regeldaten
preis. Nur kuenftig zustaendige Vorgesetzte erhalten den passenden Lese-Link.

Die PWA bezeichnet den Wert jetzt als **Fensteranrechnung (unverbindliche
Vorschau)**. Tatsaechliche Arbeitszeit und offene Aussenzeit bleiben getrennt;
keine verbindlichen Salden oder Anrechnungsregeln wurden geaendert.

## Gezielte Pruefnachweise

| Pruefung | Bisher bestaetigtes Ergebnis |
| --- | --- |
| Pausenschutz und echte parallele Admin-/Nachweisschreibsperre | 13 Tests / 74 Assertions gruen. |
| Vorhandene Admin-/App-Units | 18 Tests / 76 Assertions gruen. |
| Material-Backend, Replay, Archiv, Rechte, zwei parallele Prozesse, Migration/Retry | 7 Tests / 38 Assertions gruen. |
| UserSummary inklusive echter Useredit-Ausgabe, Berlin-Grenzen, Scope und Read-only-Historie | 7 Tests / 63 Assertions gruen. |
| Neue Material-Browserfaelle, Chromium | 12/12 gruen. |
| Vorhandene Refresh-/WorkReport-/Suche-/WorkTimefaelle plus erste Materialfaelle | 84/84 gruen. |
| Mobile WebKit-Materialgruppe | 12/12 gruen. |
| `TZ=UTC npm run ui:test:smoke` | 121 bestanden, 17 uebersprungen: 15 reale Faelle laufen separat; zwei optionale Live-Login-Pruefungen benoetigen nicht konfigurierte Zugangsdaten. |
| `composer test`, vollstaendiger CI-Lauf | 660 Tests / 3.245 Assertions gruen. |
| `npm run ui:test:real`, vollstaendiger CI-Lauf | 15/15 gruen: neun vorhandene Workflows plus sechs neue echte Projekt-/Materialfaelle. DB-Volltext, Minute, Fokus/Cursor/Scroll, 422, Discard/Cancel, Sitzung/Transport, native Anlage und Material-Reload/Save sind enthalten. |
| `composer validate --strict` | Exit 0. |
| `composer audit --locked --no-interaction` | Exit 0, keine Advisories. |
| `npm audit --audit-level=high` | Exit 0, keine Sicherheitsmeldungen. |

Der vollstaendige PHP-Pruefbefehl ist
`COMPOSER_ALLOW_SUPERUSER=1 COMPOSER_PROCESS_TIMEOUT=0 composer test`, ohne Tests
auszulassen. Ein erster toolgebundener lokaler Versuch endete vorzeitig mit 143
ohne Testfehler und zaehlt nicht als Erfolgsnachweis.

Der vollstaendige [GitHub-CI-Lauf #69](https://github.com/pkws100/TimeApp/actions/runs/43114453733)
fuer `ac5b2de486074c208bd82f41e3dbaf9f00efe08b` ist erfolgreich abgeschlossen:
PHP/Node inklusive aller obigen Browsergruppen, Produktions-Docker-Smoke sowie
Firmware-Builds und native Tests. Composer- und npm-Audits melden keine
Sicherheitsluecken. Die zusaetzliche WebKit-Konfiguration erhaelt die bestehende
Chromium-Gruppe; der Standard-Realrunner bindet die neue Projektformular-Spec
ein. Die abschliessende Variante prueft die simulierte Minute auch unmittelbar
vor dem absichtlich ungueltigen Projekt-Save und beruecksichtigt einen
Datumswechsel beim leeren Materialformular. Der genaue Ergebniscommit und
seine erneute vollstaendige CI sind in [PR #15](https://github.com/pkws100/TimeApp/pull/15)
und in der Uebergabe verlinkt.

## Geaenderte Dateien

```text
.github/workflows/ci.yml
AGENTS.md
DATABASE.md
DEPLOY.md
WORK_TIME_PAUSE_FORM_STABILITY.md
WORK_TIME_WINDOWS.md
bin/run-revisable-account-ui-tests.php
bootstrap/app.php
migrations/20261111220000_project_material_request_ids.php
package.json
playwright.webkit.config.js
public/assets/js/admin-projects.js
public/assets/js/app.js
src/Domain/Projects/ProjectMaterialConflictException.php
src/Domain/Projects/ProjectMaterialService.php
src/Domain/TimeAccounts/EmployeeAccountCutoverService.php
src/Domain/Timesheets/AbsencePeriodService.php
src/Domain/Timesheets/AdminBookingService.php
src/Domain/Timesheets/AppTimesheetSyncService.php
src/Domain/Timesheets/TimesheetPauseEvidenceGuard.php
src/Domain/WorkTime/WorkTimeUserSummaryService.php
src/Http/Controllers/AdminAuthController.php
src/Http/Controllers/AdminManagementController.php
src/Http/Controllers/AdminWorkTimeController.php
src/Http/Controllers/AppProjectMaterialController.php
src/Presentation/Admin/WorkTimeUserSummaryRenderer.php
tests/Integration/PauseEvidenceProtectionDatabaseTest.php
tests/Integration/ProjectMaterialRequestsDatabaseTest.php
tests/Integration/WorkTimeUserSummaryDatabaseTest.php
tests/Support/pause-evidence-worker.php
tests/Support/project-form-ui.php
tests/Support/project-material-request-worker.php
tests/e2e/admin-project-forms.real.spec.js
tests/e2e/project-material-drafts.spec.js
tests/e2e/work-time.spec.js
```

## Review, Betrieb und verbleibende Grenzen

Unabhaengige Workflow-/Code-Reviews fanden unter anderem den Create/Late-edit-
Randfall und die mobile Abbruchbedienung; beide wurden gezielt korrigiert.
Der UI-Review pruefte den echten Benutzeruebersicht-Renderer mit dem vorhandenen
Admin-CSS bei 390 Pixeln in Light/Dark; der Verwaltungslink bleibt ohne Overflow
mit ausreichender Touchflaeche bedienbar. Es bestehen keine offenen bestaetigten
P1-/P2-Reviewbefunde.
Der vorhandene geordnete PWA-Renderer wurde nicht ersetzt. Es wurde weder ein
unbelegter Admin-Minutenreload noch eine neue globale Refresharchitektur gebaut.

Alle Datenbanktests und echten Browserablaeufe nutzen synthetische
`timeapp_test_*`-/`timeapp_ui_*`-Datenbanken. Der reale Runner startet eigene
lokale PHP-Server. Ein weiterer Entwicklungsserver wurde mangels eindeutigem
Ziel und freigegebenem Deploymentverfahren nicht veraendert.

Vor einer spaeteren Entwicklungsfreigabe Code und Material-Migration zusammen
aktualisieren und einen geeigneten Sicherungspunkt herstellen. Die neue
Material-PWA sendet Anfrage-IDs; die zugehoerige Migration muss vor ihrer
Nutzung bereitstehen. Persistente Browserentwuerfe ersetzen keine Serverbuchung
und sind bei explizitem Logout geloescht. Nicht bestaetigte Speicherabsichten
werden bewusst nicht unter einer neuen ID automatisch wiederholt.

Technische Empfehlung: nach erfolgreicher Head-CI fuer die fachliche Abnahme
auf dem isolierten Entwicklungsserver bereitstellen. Produktive Aktivierung,
verbindlich andere Fensteranrechnung und produktiver SMS-Versand bleiben
ausserhalb dieses Auftrags.

## Nachtrag: ausdrueckliche GitHub-Freigabe vom 12.11.2026

Der Nutzer hat nach dem erfolgreichen Abschluss das Einchecken aller offenen
Aenderungen und einen Fast-Forward von GitHub-main beauftragt. Damit werden auch
die bereits vorher vorhandene Login-Theme-Anpassung und die PHP-Sicherheitsupdate-
Dokumentation aufgenommen. Die Anleitung erstellt nun auch den vorhandenen
`timeapp-worktime-scheduler` neu und prueft dessen PHP-Version, damit alle drei
PHP-Dienste vom aktualisierten Docker-Image profitieren.

Die Login-Aenderung verwendet das bestehende Theme-Script; Authentifizierung,
Session und Weiterleitungen bleiben unveraendert. Die vorhandenen Auth-Units
bestanden mit drei Tests und acht Assertions. Unabhaengige UI-, Workflow- und
Code-Reviews pruefen die Ergaenzungen. Die vollstaendige CI des abschliessenden
Commits und der tatsaechliche Fast-Forward werden in PR #15 dokumentiert.
Beim erneuten CI-Lauf nach Berliner Mitternacht zeigte sich ein Zeitzonenfehler
im Pausenschutz-Testfixture: UTC-„morgen“ war bereits Berlin-heute. Das Fixture
verwendet nun auch fuer die kuenftige Zuordnung ausdruecklich `Europe/Berlin`.
Die fachliche Zukunftspruefung und saemtliche Schutz-Assertions bleiben erhalten.
Diese Git-Freigabe umfasst keine Produktionsmigration oder Aktivierung bei
realen Mitarbeitern.
