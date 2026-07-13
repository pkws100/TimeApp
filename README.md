# Baustellen- und Zeiterfassungs-App

## Ueberblick
Monolithische PHP-Anwendung fuer Admin-Backend, API und mobile Mitarbeiter-WebApp.

- Admin: `/admin`
- Mitarbeiter-App: `/app`
- API-Basis: `/api/v1`
- Stack: Apache, PHP 8.2, MariaDB 10.11, PHPUnit, Phinx

## GitHub und Lizenz
- Repository: `pkws100/TimeApp`
- Lizenz: GNU General Public License Version 2 oder spaeter (`GPL-2.0-or-later`)
- Rechteinhaber und Maintainer: Christian Pittroff (`pkws100`)
- Entwicklung: Christian Pittroff mit OpenAI Codex als KI-gestuetztem Entwicklungspartner
- Finanzierung: HTD-Sonneberg
- Lizenznotiz: `LICENSE`
- Vollstaendiger Lizenztext: `COPYING`
- Drittkomponenten und Lizenzhinweise: `THIRD_PARTY_NOTICES.md`

OpenAI Codex wird als eingesetzter KI-Entwicklungspartner genannt. Daraus folgt
keine Rolle von OpenAI als Rechteinhaber, Maintainer, Lizenzgeber oder
Support-Anbieter dieses Projekts.

Die GPL ist eine Copyleft-Lizenz. Wer das Projekt weitergibt oder abgeleitete
Versionen verteilt, muss die entsprechenden Lizenzpflichten beachten.
Kommerzieller Einsatz ist erlaubt; bezahlter, fair kalkulierbarer Support fuer
Installation, Infrastruktur-Bereitstellung und produktionsnahen Betrieb kann
durch den Maintainer oder einen von ihm benannten Anbieter separat und nach
gesonderter Vereinbarung angeboten werden.

## Wichtige Pfade
- Projektwurzel: `/var/www/html`
- Bootstrap: `bootstrap/app.php`
- Admin-Layout: `src/Presentation/Admin/AdminView.php`
- App-Layout: `src/Presentation/App/AppView.php`
- Datenbank-Config: `config/database.php`
- Aktive DB-Override-Datei: `storage/config/database.override.php`
- Migrationen: `migrations/`
- Seeder: `seeds/`
- Upload- und Laufzeitdaten: `storage/`

## Wichtige URLs
- Admin-Login: `/admin/login`
- Company-Settings: `/admin/settings/company`
- Kalender-Settings: `/admin/settings/calendar`
- Dokumentstatusprofile: `/admin/settings/document-statuses`
- Datenbank-Settings: `/admin/settings/database`
- Session-Status: `GET /api/v1/auth/session`
- App-Tageskontext: `GET /api/v1/app/me/day`
- App-Timesheet-Sync: `POST /api/v1/app/timesheets/sync`
- App-Push-Test: `POST /api/v1/app/push/test`
- App-Projektdateien: `GET/POST /api/v1/app/projects/{id}/files`
- App-Buchungsdateien: `GET/POST /api/v1/app/timesheets/{id}/files`
- App-Kundenbestaetigung: `GET/POST /api/v1/app/timesheets/{id}/signature`
- App-Kundenbestaetigungsbild: `GET /api/v1/app/timesheet-signatures/{id}/image`
- Admin-Buchungsdatei-Abruf: `GET /admin/timesheet-files/{id}/download`
- Admin-Buchungsdatei-Archivierung: `DELETE /admin/timesheet-files/{id}`
- Admin-Buchungsdatei-Status: `POST /admin/timesheet-files/{id}/status`
- Admin-Kundenbestaetigungsbild: `GET /admin/timesheet-signatures/{id}/image`
- Admin-Kundenbestaetigungsarchivierung: `POST /admin/timesheet-signatures/{id}/archive`
- Zeitkonten: `GET /admin/time-accounts`
- Stichtag-Vorschau/Finalisierung: `POST /admin/time-accounts/cutovers/preview` und `POST /admin/time-accounts/cutovers/finalize`
- Stichtagsprotokoll: `GET /admin/time-accounts/cutovers/{id}/protocol`
- Zeit-/Urlaubskonto-Korrekturen: `POST /admin/time-accounts/entries/time` und `POST /admin/time-accounts/entries/vacation`
- Limitierte Journalhistorie Admin: `GET /admin/time-accounts/users/{id}/entries`
- Limitierte Journalhistorie App: `GET /api/v1/app/time-account/entries`
- Admin-AGB-PDF: `GET /admin/settings/company/agb-pdf/preview` und `GET /admin/settings/company/agb-pdf/download`
- Admin-Datenschutz-PDF: `GET /admin/settings/company/datenschutz-pdf/preview` und `GET /admin/settings/company/datenschutz-pdf/download`
- Dashboard-Overview: `GET /api/v1/dashboard/overview`
- Projekte: `GET /api/v1/projects`

## Setup
1. Abhaengigkeiten installieren:
```bash
composer install
```

2. Migrationen ausfuehren:
```bash
vendor/bin/phinx migrate -c phinx.php
```

3. Referenzdaten einspielen:
```bash
vendor/bin/phinx seed:run -c phinx.php -s InitialReferenceSeeder
```

4. Ersten Administrator anlegen:
```bash
php bin/bootstrap-admin.php --email=admin@example.invalid --password='IHR_PASSWORT' --first-name=Admin --last-name=Benutzer
```

## Docker Compose
Der lokale Compose-Stand ist fuer einen Dockerhost mit zwei Diensten vorbereitet:

- `app`: PHP 8.2 + Apache
- `db`: MariaDB 10.11

Vor dem Start muss auf dem Dockerhost eine `.env` mit produktiven Werten angelegt werden. Mindestens erforderlich sind:

```bash
APP_URL=https://zeiterfassung.example.invalid
APP_SECRET=BITTE_LANG_UND_ZUFAELLIG_SETZEN
DOCKER_DB_PASSWORD=BITTE_SICHER_SETZEN
DOCKER_DB_ROOT_PASSWORD=BITTE_SICHER_SETZEN
```

Starten:
```bash
docker compose build
docker compose up -d
```

Die App ist danach standardmaessig unter `http://localhost:18080` erreichbar.

Erstsetup im Container:
```bash
docker compose exec app vendor/bin/phinx migrate -c phinx.php
docker compose exec app vendor/bin/phinx seed:run -c phinx.php -s InitialReferenceSeeder
docker compose exec app php bin/bootstrap-admin.php --email=admin@example.invalid --password='IHR_PASSWORT' --first-name=Admin --last-name=Benutzer
```

Wichtige Hinweise fuer Compose:

- Die Datenbank laeuft im Compose-Stack ueber `DB_HOST=db` und `DB_PORT=3306`.
- Nach aussen wird standardmaessig nur die Web-App veroefentlicht, und zwar auf Host-Port `18080`.
- Die MariaDB wird bewusst **nicht** auf einen Host-Port veroeffentlicht. Fuer Online-Betrieb ist das sicherer als nur einen anderen oeffentlichen DB-Port zu waehlen.
- Das Dockerfile baut nur fehlende PHP-Extensions selbst: `gd`, `pdo_mysql` und `zip`. Core-Extensions aus `php:8.2-apache` wie `dom`, `SimpleXML`, `xml`, `xmlreader`, `xmlwriter`, `fileinfo`, `curl`, `mbstring` und `json` duerfen nicht erneut kompiliert werden.
- `DB_SOCKET` ist im Compose-Betrieb bewusst leer.
- PHP-Abhaengigkeiten werden beim Image-Build installiert. Im laufenden Container ist kein manuelles `composer install` erforderlich.
- Laufzeitdaten bleiben ueber benannte Volumes fuer MariaDB und `storage/` erhalten.
- Der App-Code kommt aus dem gebauten Image. Es gibt bewusst keinen Bind-Mount von `./` nach `/var/www/html`, damit ein Dockerhost reproduzierbar den gebauten Stand ausfuehrt.
- Compose-Umgebungsvariablen uebersteuern `.env`, damit lokale Host-Sockets den Containerbetrieb nicht blockieren.
- Wenn Sie einen anderen Web-Port oder andere DB-Zugangsdaten wollen, koennen Sie vor dem Start z. B. `APP_PORT`, `DOCKER_DB_DATABASE`, `DOCKER_DB_USERNAME`, `DOCKER_DB_PASSWORD` und `DOCKER_DB_ROOT_PASSWORD` setzen.
- Fuer oeffentlichen Betrieb sollte TLS ueber einen vorgeschalteten Reverse Proxy terminiert werden. `APP_URL` muss auf die oeffentliche HTTPS-URL zeigen.
- Falls Sie die Datenbank ausnahmsweise vom Host aus erreichen muessen, ist ein eigener lokaler Override sinnvoller als ein fester Repo-Default, z. B. per zusaetzlichem Compose-Override mit einem nicht-standardisierten Host-Port wie `13306:3306`.

## Produktions-Deploy
Fuer VPS-/Reverse-Proxy-Setups gibt es zusaetzlich `docker-compose.prod.yml` mit
den Diensten `timeapp-web`, `timeapp-db` und `timeapp-scheduler`. Die MariaDB
hat dort keinen oeffentlichen Port, Storage und DB nutzen stabile benannte
Volumes, und der Webdienst haengt am externen Proxy-Netz plus lokalem
Smoke-Check-Port.

Details stehen in `DEPLOY.md`. Wiederkehrende Updates laufen ueber
`bin/update-prod.sh`; das Skript fuehrt Migrationen, den idempotenten
Referenz-Seeder und eine Post-Update-Pruefung fuer kritische Schema- und
Rechte-Backfills aus. Read-only Smoke- und Status-Checks laufen ueber
`bin/deploy-prod-check.sh`.

Bei nativen Apache/PHP-Installationen ohne Docker nach dem Code-Update diesen
Update-Helper ausfuehren:

```bash
bin/update-native.sh
```

Das Script installiert Composer-Abhaengigkeiten, fuehrt `vendor/bin/phinx
migrate -c phinx.php` aus, spielt den idempotenten Referenz-Seeder ein und
prueft anschliessend kritische Schema- und Rechte-Backfills.

Nach nativen Updates sollten mindestens `/admin/login` und `/app` im Browser
oder per HTTP-Smoke-Check geprueft werden.

## Backup und Restore-Status
- `GET /api/v1/system/backup/export` erstellt ein ZIP mit Manifest, Datenbank-JSON, Upload-Kandidaten und Security-/Runtime-Hinweisen.
- `POST /api/v1/system/backup/import/validate` validiert ein hochgeladenes Backup als Dry-Run.
- Der Validate-Endpunkt prueft Manifest, `backup_version`, `schema_version`, deklarierte Tabellen-JSON-Dateien und unsichere Archivpfade. Zeitkonto-Backups muessen die Stichtags- und Journal-Tabellen vollstaendig enthalten.
- Es gibt bewusst noch keinen produktiven Restore-Apply. Ein Upload fuehrt niemals automatisch einen Restore aus.
- Runtime-Overrides wie `storage/config/database.override.php` werden aus App-Backup-ZIPs ausgeschlossen und nur im Manifest als Hinweis gefuehrt.
- Backup- und Restore-Validierung sind mit `settings.database.manage` geschuetzt.
- Verschluesselte Settings-Secrets wie SMTP-Passwoerter bleiben im Backup verschluesselt; Legacy-Klartextwerte werden redigiert und im Manifest unter `security.redacted_database_fields` ausgewiesen. Passwort-Hashes und Push-Subscription-Daten bleiben fuer einen spaeteren Restore erhalten, werden aber unter `security.retained_sensitive_database_fields` klassifiziert. Der passende `.env`-Key wird nicht im Backup mitgeliefert. Nach einem Upgrade mit altem Klartext-SMTP-Passwort die SMTP-Settings einmal mit gesetztem Key speichern, bevor ein Backup erstellt wird.

## Geschuetzte Settings-Dateien
- Firmenlogo wird weiterhin ueber den bestehenden oeffentlichen Logo-Endpunkt fuer Branding ausgeliefert.
- AGB- und Datenschutz-PDFs werden im Admin unter `settings.manage` ueber geschuetzte Preview-/Download-Endpunkte ausgeliefert.
- Die Dateien bleiben im geschuetzten Upload-Storage; Admin-HTML enthaelt keine direkte `storage/`- oder `public/`-Datei-URL.

## Kalender- und Dokumentstatus-Settings
- Gesetzliche Feiertage werden lokal je eingestelltem Bundesland berechnet und im Admin-Kalender sichtbar gemacht.
- Feiertage und geplanter Betriebsurlaub erzeugen keine automatische `Fehlt`-Wertung und keine Fehlbuchungs-Pushes; freiwillige Buchungen bleiben moeglich.
- Dokumentstatusprofile fuer Uploads werden unter `/admin/settings/document-statuses` verwaltet. Neue Uploads erhalten den aktiven Defaultstatus, bestehende Dateien ohne Status bleiben gueltig.

## Revisionsfaehige Zeit- und Urlaubskonten
- Zeitkonten koennen je Mitarbeiter mit einem Einfuehrungsstichtag finalisiert werden. Der Eroeffnungssaldo ist der uebernommene Stand am Ende des Vortages; ab dem Stichtag berechnet die App den kumulierten Stand selbst.
- Finale Stichtage erzeugen unveraenderliche Journalbuchungen fuer Zeitkonto und Urlaubskonto sowie eine userbezogene Sperre des Altzeitraums ueber `accounting_closures`.
- Journalbuchungen gehoeren ueber `cutover_id` zu genau einer Stichtagsgeneration. Aktive Berechnungen beruecksichtigen nur Journalzeilen der aktiven finalen Generation; revidierte Generationen bleiben historisch sichtbar, wirken aber nicht mehr in aktuelle Salden.
- Neue Finalisierungen erzeugen keine wirkungslosen Null-Journalzeilen. Revidierungen verarbeiten nur offene Ursprungsbuchungen; historische Nullzeilen und bereits einzeln ausgeglichene Korrekturen blockieren die Revidierung nicht.
- Nicht eindeutig belegbare Altzuordnungen bleiben mit `cutover_id = NULL` aus aktiven Salden ausgeschlossen. Der read-only Bericht `php bin/inspect-time-account-generations.php` beziehungsweise `--json` zeigt betroffene Journalzeilen und moegliche Stichtage.
- Interne Stichtagssperren sind in `accounting_closures` mit `source_type = employee_account_cutover` gekennzeichnet. Sie wirken fuer Timesheet-Schreibschutz, werden in normalen Abschlusslisten und Exporten aber ausgeblendet.
- Finalisierung und Revidierung sperren zuerst den Mitarbeiter-Stichtag, danach den globalen `accounting-timesheet-write`-Lock, pruefen die Vorschau erneut und schreiben erst dann innerhalb der DB-Transaktion.
- Beim Wiederherstellen archivierter Buchungen werden aktuelle Periodensperren, Tageskonflikte und die Anrechenbarkeit des Tages erneut geprueft. Betriebsschliessungen ueber einen Jahreswechsel werden in beiden Jahren beruecksichtigt.
- Korrekturen, Auszahlungen, Freizeitausgleich, Urlaubsanpassungen und Revidierungen laufen ueber Journal- und Gegenbuchungen, nicht ueber physische Loeschung oder direkte Aenderung alter Journalzeilen.

MariaDB-Kernintegrationstests laufen mit zufaelligen Scratch-Datenbanken und benoetigen `CREATE DATABASE` fuer den Testbenutzer (lokal standardmaessig `root` ueber den MariaDB-Socket):

```bash
vendor/bin/phpunit tests/Integration/RevisableCutoverDatabaseTest.php
vendor/bin/phpunit tests/Integration/RevisableAccountMigrationTest.php
```

Mit `TIMEAPP_TEST_DB_*` lassen sich separate Testzugangsdaten setzen. `DB_OVERRIDE_FILE` erlaubt Testprozessen einen vom produktiven Runtime-Override getrennten Konfigurationspfad.

`npm run ui:test` fuehrt zuerst die schnellen Playwright-Smokes und danach den realen Zeitkonto-Workflow gegen eine automatisch erzeugte `timeapp_ui_*`-Scratch-Datenbank aus. Der Runner verwendet nur synthetische Benutzer, einen eigenen PHP-Testserver und temporaere Artefakte. Fuer einen reinen Smoke-Lauf ohne MariaDB-Fixture steht `npm run ui:test:smoke` bereit.
- Bezahlte Abwesenheiten speichern eine separate Zeitgutschrift in `timesheets.credited_minutes`; tatsaechliche Arbeitszeit bleibt davon getrennt.
- Urlaubskonten werden jahresbezogen aus dem Urlaubskonto-Journal berechnet. Die User-Felder fuer Jahresurlaub und Uebertrag dienen weiter als Vorschlagswerte fuer neue Stichtage und neue Urlaubsjahre. Vor der ersten schreibenden Urlaubskonto-Bewegung eines Jahres wird die Jahreseroeffnung je `user_id`, `leave_year` und `cutover_id` idempotent gebucht.
- Arbeit plus ganztagige Abwesenheit sowie doppelte ganztagige Abwesenheiten am selben Tag werden serverseitig blockiert; mehrere Arbeitsbuchungen pro Tag bleiben erlaubt.
- Rueckwirkende Arbeitszeitmodell-, Feiertagsregion- und Betriebsschliessungs-Aenderungen werden bei betroffenen aktiven Zeitkonten blockiert, bis ein versioniertes historisches Regelmodell eingefuehrt ist.
- Die Mitarbeiter-App zeigt Zeitkontostand, Monatsveraenderung, Arbeitszeit, Zeitgutschriften, Resturlaub und offene bzw. zukuenftig genehmigte Urlaube lesend an. Journalhistorien werden separat und limitiert geladen.

## Wichtige Composer-Befehle
```bash
composer test
composer migrate
composer seed
composer bootstrap-admin -- --email=admin@example.invalid --password='IHR_PASSWORT' --first-name=Admin --last-name=Benutzer
```

`composer seed` spielt nur die notwendigen Referenzdaten ein. Demo-Daten sind
bewusst separat und duerfen nicht in produktiven Setups ausgefuehrt werden.

## Seeder-Strategie
- `InitialReferenceSeeder`: Rollen, Rechte und notwendige Referenzdaten
- `DemoDataSeeder`: optionale Demo-Benutzer, Demo-Projekte und Demo-Assets fuer Entwicklung

Optionaler Demo-Seeder:
```bash
vendor/bin/phinx seed:run -c phinx.php -s DemoDataSeeder
```

## Auth und Erstaufbau
- Es gibt keinen fest eingebauten Demo-Login mehr.
- Wenn kein aktiver Administrator existiert, zeigt `/admin/login` einen Setup-Hinweis.
- Der erste Administrator wird per CLI angelegt.
- `GET /api/v1/auth/session` liefert auch `bootstrap_required`.

## Aktive Projektwerte
- App-Name Default: `Baustellen Zeiterfassung`
- Theme-Modi: `light`, `dark`, `system`
- Theme-Storage-Key: `app.theme`
- Session-Cookie: ueber `SESSION_NAME` steuerbar; Compose-Default ist `zeiterfassung_session`, Code-Fallback ist `baustelle_session`; `SESSION_SECURE_COOKIE` sollte in Produktion aktiv sein
- Standard-Datenbankname: `zeiterfassung`

## Hinweise zu sensiblen Daten
- Sensible Zugangsdaten und Passwoerter gehoeren nicht dauerhaft ins Repo.
- `APP_SECRET` muss in Produktion auf einen langen Zufallswert gesetzt werden.
- Optional kann `SETTINGS_ENCRYPTION_KEY` als dedizierter Key fuer verschluesselte Settings-Secrets gesetzt werden; wenn er leer ist, wird `APP_SECRET` verwendet.
- SMTP-Passwoerter werden in `company_settings.smtp_password` verschluesselt gespeichert und im Admin nie als Klartext ausgegeben. Das Passwortfeld leer lassen, um ein bestehendes Secret beizubehalten; ein neuer Wert ersetzt es verschluesselt. Bestehende Klartextwerte aus frueheren Versionen werden beim naechsten gezielten SMTP-Speichern verschluesselt.
- Backups enthalten verschluesselte `enc:v1`-SMTP-Werte sowie betriebsnotwendige sensible Werte wie Passwort-Hashes und Push-Subscription-Daten. Legacy-Klartextwerte werden beim Backup-Export redigiert; der passende `APP_SECRET` bzw. `SETTINGS_ENCRYPTION_KEY` muss getrennt und sicher aufbewahrt werden, sonst kann ein wiederhergestelltes SMTP-Secret nicht genutzt werden.
- Die aktive DB-Override-Datei kann lokale Verbindungsdaten enthalten:
  `storage/config/database.override.php`
- Diese DB-Override-Datei wird nicht in App-Backup-ZIPs aufgenommen und muss,
  falls fuer den Betrieb noetig, getrennt gesichert werden.
- Vor einer Veroeffentlichung muessen `.env`, Datenbank-Dumps, Uploads,
  Session-Dateien und sonstige Runtime-Daten ausserhalb des Git-Repos bleiben.

## Veroeffentlichung auf GitHub
Repository-Daten:

- Owner/Repo: `pkws100/TimeApp`
- Default-Branch: `main`
- Beschreibung: `Baustellen- und Zeiterfassungs-App mit PHP-Admin-Backend, API und mobiler PWA-Zeiterfassung`
- Topics: `php`, `mariadb`, `time-tracking`, `pwa`, `construction`, `self-hosted`, `docker`

Remote fuer frische Klone bzw. neue Arbeitskopien:

```bash
git remote add origin git@github.com:pkws100/TimeApp.git
git push -u origin main
```

Empfohlener Check vor dem Push:

```bash
git status --short --ignored
grep -RInE "(password|secret|token|api[_-]?key|PRIVATE KEY)" --exclude-dir=.git --exclude-dir=vendor --exclude-dir=node_modules --exclude-dir=storage .
COMPOSER_ALLOW_SUPERUSER=1 composer test
```

## Dokumentation im Projekt
- Agenten-Leitfaden: `AGENTS.md`
- Offene Folgearbeiten: `PROJECTS.md`
- Datenbank-Zielbild: `DATABASE.md`
