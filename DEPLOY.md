# Produktions-Deploy auf einem Ionos VPS

Arbeitszeitfenster werden standardmaessig mit `WORK_TIME_WINDOWS_ENABLED=false`
ausgeliefert. Zusaetzlich ist eine kuenftige Zuordnung pro Mitarbeiter erforderlich.
`timeapp-worktime-scheduler` fuehrt den separaten Worker minuetlich aus; das
bisherige Push-Reminder-Intervall bleibt erhalten. Dry-Run, Betriebsgrenzen und
gesonderte fachliche Freigabe: [WORK_TIME_WINDOWS.md](WORK_TIME_WINDOWS.md).

Diese Anleitung beschreibt den Docker-Compose-Betrieb fuer die TimeApp hinter
einem Reverse Proxy. Sie enthaelt nur Platzhalter und keine produktiven Secrets.

## Voraussetzungen

- Docker Engine und Docker Compose Plugin sind installiert.
- `curl` ist auf dem Host fuer Smoke-Checks verfuegbar.
- Das Repository liegt auf dem VPS, z. B. unter `/opt/timeapp`.
- Ein Reverse Proxy wie Traefik, Nginx Proxy Manager, Caddy oder ein eigener
  Nginx/Apache-Proxy terminiert TLS.
- Das externe Docker-Netz fuer den Proxy existiert. Standardname: `proxy`.

Proxy-Netz einmalig anlegen, falls es noch nicht existiert:

```bash
docker network create proxy
```

## `.env` vorbereiten

```bash
cp .env.example .env
```

Mindestens diese Werte produktiv setzen:

```bash
APP_URL=https://zeiterfassung.example.de
APP_SECRET=BITTE_LANG_UND_ZUFAELLIG_SETZEN
DOCKER_DB_PASSWORD=BITTE_LANG_UND_ZUFAELLIG_SETZEN
DOCKER_DB_ROOT_PASSWORD=BITTE_ANDERS_UND_ZUFAELLIG_SETZEN
PUSH_VAPID_SUBJECT=mailto:admin@example.de
```

`APP_SECRET` wird fuer verschluesselte Settings-Secrets verwendet, sofern kein
dedizierter `SETTINGS_ENCRYPTION_KEY` gesetzt ist. Fuer beide Werte gilt: nicht
committen, getrennt sichern und nach einem Restore wieder identisch setzen,
damit gespeicherte SMTP-Secrets entschluesselt werden koennen.

Die produktive `.env` schuetzen:

```bash
chmod 600 .env
```

Nur vertrauenswuerdige Administratoren sollten Zugriff auf Docker bzw. die
Docker-Gruppe haben. Compose-Environment-Werte sind fuer Docker-Administratoren
sichtbar; Ausgaben von `docker compose config` deshalb nicht in Tickets oder
Logs kopieren.

VAPID-Keys fuer Browser-Push erzeugen:

```bash
docker compose -f docker-compose.prod.yml --env-file .env build timeapp-web
docker compose -f docker-compose.prod.yml --env-file .env run --rm --no-deps timeapp-web php bin/generate-vapid-keys.php
```

Die ausgegebenen Werte als `PUSH_VAPID_PUBLIC_KEY` und
`PUSH_VAPID_PRIVATE_KEY` in `.env` eintragen.

## Pre-Deploy-Checks

Vor einem produktiven Build sollten die lokalen Tests gruen sein:

```bash
composer validate --strict
composer check-platform-reqs --no-interaction
COMPOSER_ALLOW_SUPERUSER=1 composer test
docker compose -f docker-compose.prod.yml --env-file .env config >/dev/null
```

## Dockerfile-Hinweis

Das Image basiert auf `php:8.2-apache`. Dieses Basisimage bringt mehrere
Core-Extensions bereits mit, darunter `dom`, `SimpleXML`, `xml`, `xmlreader`,
`xmlwriter`, `fileinfo`, `curl`, `mbstring` und `json`. Diese Extensions nicht
erneut im Dockerfile kompilieren, weil das bei XML/DOM zu Build-Fehlern wie
`fatal error: ext/dom/dom_ce.h: No such file or directory` fuehren kann.

Das Dockerfile baut aktuell nur die fehlenden Extensions `gd` (inklusive JPEG-,
PNG- und WebP-Unterstuetzung), `pdo_mysql` und `zip`.

## Start

```bash
docker compose -f docker-compose.prod.yml --env-file .env config >/dev/null
docker compose -f docker-compose.prod.yml --env-file .env build
docker compose -f docker-compose.prod.yml --env-file .env up -d
```

Die Dienste heissen:

- `timeapp-web`: Apache/PHP-App, im Proxy-Netz und auf `127.0.0.1:${APP_PORT}`
- `timeapp-db`: MariaDB im privaten internen DB-Netz, ohne oeffentliche Ports
- `timeapp-scheduler`: Push-Reminder-Loop mit Zugriff auf DB und Storage

Persistente Volumes:

- `timeapp_timeapp-db-data`
- `timeapp_timeapp-storage`

Upload-Grenzen:

- Im Docker-Image setzt `docker/php/custom.ini` `upload_max_filesize=32M` und
  `post_max_size=40M`.
- `UPLOAD_MAX_FILESIZE` muss dazu passen; Standard in `.env.example` und Compose
  ist `33554432` Byte.
- Bei nativen Apache/PHP-Installationen ausserhalb von Docker muessen diese
  PHP-INI-Werte separat in der Apache-SAPI gesetzt und Apache neu geladen
  werden, sonst greifen oft die PHP-Defaults `2M`/`8M` und Handyfotos werden vor
  der Anwendung abgelehnt.

## Erstsetup und Updates

Fuer wiederkehrende Updates ist `bin/update-prod.sh` der Standardpfad. Das
Skript validiert Compose, baut das Image, stellt den Datenbankdienst bereit,
fuehrt Migrationen und den idempotenten Referenz-Seeder mit dem neuen Web-Image
aus, prueft danach kritische Schema- und Rechte-Backfills und startet erst dann
den aktualisierten Stack. Anschliessend prueft es den Push-Scheduler per
Dry-Run:

```bash
bin/update-prod.sh
```

Dieser Docker-Pfad fuehrt `vendor/bin/phinx migrate -c phinx.php` und den
`InitialReferenceSeeder` automatisch aus. Neue Felder, Tabellen und Rechte
werden dadurch beim Update nachgezogen.

Nuetzliche Varianten:

```bash
bin/update-prod.sh --no-build
bin/update-prod.sh --skip-migrations
bin/update-prod.sh --skip-seed
bin/update-prod.sh --skip-migrations --skip-seed
```

Die `--skip-*` Optionen sind fuer Diagnose- und Wiederholungsläufe gedacht. Die
abschliessende Post-Update-Pruefung laeuft trotzdem und bricht ab, wenn fuer den
ausgerollten Code notwendige Migrationen oder Referenzdaten fehlen.

Bei nativen Apache/PHP-Installationen ohne Docker ist `bin/update-native.sh`
der entsprechende Standardpfad nach einem Code-Update:

```bash
bin/update-native.sh
```

Das Script fuehrt Composer-Install, Phinx-Migrationen, den idempotenten
Referenz-Seeder und die Post-Update-Pruefung direkt im Repository aus.

Die Einzelschritte bleiben fuer Erstsetup und Fehlerdiagnose verfuegbar. Phinx
laedt dabei die `.env` ueber `phinx.php`, damit Migrationen, Seeder und
Post-Update-Pruefung dieselbe Datenbankkonfiguration verwenden.

Native Einzelschritte:

```bash
composer install --no-dev --optimize-autoloader
vendor/bin/phinx migrate -c phinx.php
vendor/bin/phinx seed:run -c phinx.php -s InitialReferenceSeeder
php bin/verify-update.php
```

Docker-Einzelschritte:

Migrationen manuell ausfuehren:

```bash
docker compose -f docker-compose.prod.yml --env-file .env exec -T timeapp-web vendor/bin/phinx migrate -c phinx.php
```

Referenzdaten manuell einspielen. Der Seeder ist idempotent und darf mehrfach
laufen; ein Lauf reicht im Normalfall aus:

```bash
docker compose -f docker-compose.prod.yml --env-file .env exec -T timeapp-web vendor/bin/phinx seed:run -c phinx.php -s InitialReferenceSeeder
```

Post-Update-Pruefung manuell ausfuehren:

```bash
docker compose -f docker-compose.prod.yml --env-file .env exec -T timeapp-web php bin/verify-update.php
```

Ersten Administrator anlegen:

```bash
docker compose -f docker-compose.prod.yml --env-file .env exec timeapp-web php bin/bootstrap-admin.php --email=admin@example.de --password='BITTE_SICHER_SETZEN' --first-name=Admin --last-name=Benutzer
```

Push-Scheduler pruefen:

```bash
docker compose -f docker-compose.prod.yml --env-file .env exec -T timeapp-web php bin/send-push-reminders.php --dry-run
docker compose -f docker-compose.prod.yml --env-file .env exec -T timeapp-web php bin/send-personnel-event-reminders.php --dry-run
```

## PHP-Sicherheitsupdates

PHP-Sicherheitsupdates sind ein eigener Betriebsschritt. `bin/update-native.sh`
aktualisiert die Anwendung, installiert aber keine PHP-Systempakete.

Die Luecken CVE-2026-109618 und CVE-2026-109619 sind fuer Debian 12 ab
`8.2.35-1~deb12u1` korrigiert. Massgeblich sind der
[Debian-Sicherheitsstatus](https://security-tracker.debian.org/tracker/DLA-4848-1)
und der [PHP-Sicherheitshinweis vom 21.10.2026](https://www.php.net/archive/2026.php#2026-10-21-5).
Reine INI-Aenderungen schliessen diese Luecken nicht vollstaendig.

Bei der nativen Debian-12-Installation alle installierten PHP-8.2-Pakete aus
dem signierten `bookworm-security`-Repository aktualisieren. Fuer die hier
verwendete Apache/mod_php-Installation als root:

```bash
apt-get update
apt-get --simulate --only-upgrade install \
  php8.2 libapache2-mod-php8.2 php8.2-cli php8.2-common php8.2-curl \
  php8.2-gd php8.2-intl php8.2-mbstring php8.2-mysql php8.2-opcache \
  php8.2-readline php8.2-xml php8.2-zip
apt-get --only-upgrade install \
  php8.2 libapache2-mod-php8.2 php8.2-cli php8.2-common php8.2-curl \
  php8.2-gd php8.2-intl php8.2-mbstring php8.2-mysql php8.2-opcache \
  php8.2-readline php8.2-xml php8.2-zip
apache2ctl configtest
systemctl restart apache2
systemctl is-active apache2
php -v
```

Vorher die PHP-/Apache-Konfiguration sichern und den simulierten Paketplan
pruefen. Zusaetzlich installierte PHP-Pakete bzw. SAPIs ebenfalls
aktualisieren und deren Dienste neu starten. Ein vollstaendiger Apache-Neustart
ist bei mod_php notwendig: Ein blosses Reload kann das alte Modul im Speicher
behalten. `php -v` belegt nur die CLI-Version. Fuer den Abschluss auch die
Version im echten Webprozess ueber einen kurzzeitig angelegten, ausschliesslich
lokal abrufbaren Versionscheck pruefen und diesen sofort wieder entfernen.
Kein oeffentliches `phpinfo()` bereitstellen. Alte Worker duerfen danach nicht
weiterlaufen; Login, `/app` und API-Zugriffsschutz anschliessend pruefen.

Im Docker-Betrieb das Basisimage vor dem Neubau aktualisieren und Webdienst
sowie Scheduler aus dem neuen Image neu erstellen:

```bash
docker compose -f docker-compose.prod.yml --env-file .env build --pull timeapp-web
docker compose -f docker-compose.prod.yml --env-file .env up -d --no-build --force-recreate timeapp-web timeapp-scheduler timeapp-worktime-scheduler
docker compose -f docker-compose.prod.yml --env-file .env exec -T timeapp-web php -v
docker compose -f docker-compose.prod.yml --env-file .env exec -T timeapp-scheduler php -v
docker compose -f docker-compose.prod.yml --env-file .env exec -T timeapp-worktime-scheduler php -v
```

Auch dort die tatsaechlich laufende PHP-Version pruefen; der bewegliche Tag
`php:8.2-apache` allein belegt keinen installierten Sicherheitsfix.

Am 23.10.2026 wurden auf der nativen Installation alle 13 installierten
PHP-8.2-Pakete von `8.2.31-1~deb12u1` auf `8.2.35-1~deb12u1` aktualisiert.
Der zuvor noch geladene Webprozess meldete PHP 8.2.30; nach vollstaendigem
Neustart meldeten 20 lokale HTTP-Pruefungen PHP 8.2.35 mit `apache2handler`.
Alle alten Apache-Prozesse waren beendet und alle neuen Prozesse verwendeten
die installierte Moduldatei. Dieser Befund gilt fuer diesen Server und diesen
Pruefzeitpunkt; separat betriebene Dockerhosts wurden dabei nicht aktualisiert.

## Smoke-Checks

```bash
curl -I http://127.0.0.1:${APP_PORT:-18080}/app
curl -I http://127.0.0.1:${APP_PORT:-18080}/admin/login
docker compose -f docker-compose.prod.yml --env-file .env logs --tail=100 timeapp-web
docker compose -f docker-compose.prod.yml --env-file .env logs --tail=100 timeapp-db
docker compose -f docker-compose.prod.yml --env-file .env logs --tail=100 timeapp-scheduler
```

Das Repo-Skript fuehrt standardmaessig nur read-only Checks aus:

```bash
bin/deploy-prod-check.sh
```

Migrationen und den idempotenten Seeder fuehrt es nur mit explizitem Apply-Gate
aus:

```bash
bin/deploy-prod-check.sh --apply
```

## Backup-Hinweise

Regelmaessig sichern:

- MariaDB-Daten aus `timeapp_timeapp-db-data`
- Runtime-Dateien aus `timeapp_timeapp-storage`
- die produktive `.env` getrennt vom Git-Repository, insbesondere `APP_SECRET`
  bzw. `SETTINGS_ENCRYPTION_KEY`

Beispiel fuer einen Datenbank-Dump:

```bash
docker compose -f docker-compose.prod.yml --env-file .env exec timeapp-db mariadb-dump -u root -p zeiterfassung > timeapp-db.sql
```

Uploads und Konfigurationsdateien liegen im Storage-Volume. Dieses Volume nicht
loeschen, wenn nur ein neues Image deployed wird.

SMTP-Passwoerter liegen in der Datenbank verschluesselt. Das Backup enthaelt
verschluesselte `enc:v1`-Werte, aber nicht den benoetigten Settings-Key aus `.env`.
Nach einem Upgrade von einer Version mit Klartext-SMTP-Passwort die
SMTP-Settings einmal mit gesetztem Key speichern, bevor ein Backup erstellt
wird; dabei wird ein vorhandener Legacy-Wert verschluesselt. Falls dennoch ein
Legacy-Klartextwert im Backup-Export gefunden wird, wird er im Datenbank-JSON
redigiert und im Manifest unter `security.redacted_database_fields` ausgewiesen.
Betriebsnotwendiges Credential-Material wie Passwort-Hashes und Push-Subscription-
Daten bleibt fuer einen spaeteren Restore erhalten, wird aber im Manifest unter
`security.retained_sensitive_database_fields` klassifiziert. App-Backup-ZIPs sind
deshalb insgesamt wie Secrets zu behandeln.

`storage/config/database.override.php` kann lokale DB-Zugangsdaten enthalten und
wird deshalb nicht mehr in App-Backup-ZIPs aufgenommen. Der Export vermerkt eine
vorhandene Override-Datei nur im Manifest unter `runtime` und `security.warnings`.
Die produktive `.env`, der stabile `APP_SECRET` bzw. `SETTINGS_ENCRYPTION_KEY`
und notwendige Runtime-Konfigurationen muessen getrennt gesichert werden.

## Restore-Status

Die App besitzt aktuell einen geschuetzten Validate-Endpunkt fuer Backup-ZIPs.
Dieser prueft das Archiv als Dry-Run, fuehrt aber keinen produktiven Restore aus.
Ein Backup-Upload darf daher nicht als Wiederherstellung verstanden werden.

Validiert werden insbesondere:

- `manifest.json`
- `backup_version`
- `schema_version`
- deklarierte Tabellen und JSON-Struktur
- unsichere Archivpfade wie `../`
- Upload-Kandidaten ohne Extraktion
- Runtime-Hinweise ohne automatisches Zurueckspielen
- Security-Hinweise zu ausgeschlossenen Runtime-Overrides und redigierten
  Secret-Feldern

Ein produktiver Restore-Apply braucht einen separaten Auftrag mit explizitem
Admin-Gate, Wartungs-/Rollback-Konzept und klarer Freigabe. Runtime-Overrides
wie `storage/config/database.override.php` duerfen nicht ungefragt aus einem
Backup zurueckgespielt werden.
