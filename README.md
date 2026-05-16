# Baustellen- und Zeiterfassungs-App

## Ueberblick
Monolithische PHP-Anwendung fuer Admin-Backend, API und mobile Mitarbeiter-WebApp.

- Admin: `/admin`
- Mitarbeiter-App: `/app`
- API-Basis: `/api/v1`
- Stack: Apache, PHP 8.2, MariaDB 10.11, PHPUnit, Phinx

## GitHub und Lizenz
- Geplantes Repository: `pkws100/TimeApp`
- Sichtbarkeit fuer die Erstveroeffentlichung: privat vorbereiten, nach finalem Check oeffentlich schalten
- Lizenz: GNU General Public License Version 2 oder spaeter (`GPL-2.0-or-later`)
- Rechteinhaber: pkws100
- Lizenztext: `COPYING`
- Drittkomponenten und Lizenzhinweise: `THIRD_PARTY_NOTICES.md`

Die GPL ist eine Copyleft-Lizenz. Wer das Projekt weitergibt oder abgeleitete
Versionen verteilt, muss die entsprechenden Lizenzpflichten beachten.

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
- Datenbank-Settings: `/admin/settings/database`
- Session-Status: `GET /api/v1/auth/session`
- App-Tageskontext: `GET /api/v1/app/me/day`
- App-Timesheet-Sync: `POST /api/v1/app/timesheets/sync`
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
Der Compose-Stand ist fuer einen Dockerhost mit zwei Diensten vorbereitet:

- `app`: PHP 8.2 + Apache
- `db`: MariaDB 10.11

Vor dem Start muss auf dem Dockerhost eine `.env` mit produktiven Werten angelegt werden. Mindestens erforderlich sind:

```bash
APP_URL=https://zeiterfassung.example.invalid
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
`bin/update-prod.sh`; read-only Smoke- und Status-Checks laufen ueber
`bin/deploy-prod-check.sh`.

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
- Die aktive DB-Override-Datei kann lokale Verbindungsdaten enthalten:
  `storage/config/database.override.php`
- Produktive Secrets sollten spaeter weiter gehaertet werden.
- Vor einer Veroeffentlichung muessen `.env`, Datenbank-Dumps, Uploads,
  Session-Dateien und sonstige Runtime-Daten ausserhalb des Git-Repos bleiben.

## Veroeffentlichung auf GitHub
Vorgesehene Repository-Daten:

- Owner/Repo: `pkws100/TimeApp`
- Default-Branch: `main`
- Beschreibung: `Baustellen- und Zeiterfassungs-App mit PHP-Admin-Backend, API und mobiler PWA-Zeiterfassung`
- Topics: `php`, `mariadb`, `time-tracking`, `pwa`, `construction`, `self-hosted`, `docker`

Nach Anlage des privaten GitHub-Repositories:

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
- Projektstand und offene Punkte: `PROJECTS.md`
- Datenbank-Zielbild: `DATABASE.md`
