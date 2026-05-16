# Produktions-Deploy auf einem Ionos VPS

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
DOCKER_DB_PASSWORD=BITTE_LANG_UND_ZUFAELLIG_SETZEN
DOCKER_DB_ROOT_PASSWORD=BITTE_ANDERS_UND_ZUFAELLIG_SETZEN
PUSH_VAPID_SUBJECT=mailto:admin@example.de
```

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

## Erstsetup und Updates

Migrationen ausfuehren:

```bash
docker compose -f docker-compose.prod.yml --env-file .env exec timeapp-web vendor/bin/phinx migrate -c phinx.php
```

Referenzdaten einspielen. Der Seeder ist idempotent und darf mehrfach laufen:

```bash
docker compose -f docker-compose.prod.yml --env-file .env exec timeapp-web vendor/bin/phinx seed:run -c phinx.php -s InitialReferenceSeeder
docker compose -f docker-compose.prod.yml --env-file .env exec timeapp-web vendor/bin/phinx seed:run -c phinx.php -s InitialReferenceSeeder
```

Ersten Administrator anlegen:

```bash
docker compose -f docker-compose.prod.yml --env-file .env exec timeapp-web php bin/bootstrap-admin.php --email=admin@example.de --password='BITTE_SICHER_SETZEN' --first-name=Admin --last-name=Benutzer
```

Push-Scheduler pruefen:

```bash
docker compose -f docker-compose.prod.yml --env-file .env exec timeapp-web php bin/send-push-reminders.php --dry-run
```

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
- die produktive `.env` getrennt vom Git-Repository

Beispiel fuer einen Datenbank-Dump:

```bash
docker compose -f docker-compose.prod.yml --env-file .env exec timeapp-db mariadb-dump -u root -p zeiterfassung > timeapp-db.sql
```

Uploads und Konfigurationsdateien liegen im Storage-Volume. Dieses Volume nicht
loeschen, wenn nur ein neues Image deployed wird.
