# PROJECT_STATUS.md - Aktueller MVP- und Restore-Stand

## Stand
- Admin-Backend, API und mobile Mitarbeiter-PWA unter `/app` sind MVP-nah umgesetzt.
- Die mobile App umfasst Login, Heute, Zeiten, Projektwahl, Historie, Profil, Theme, Offline-Puffer, Uploads, Push-Test und GEO-Hinweise bzw. optionale GEO-Erfassung.
- Docker-Compose ist lokal und fuer Produktion vorbereitet; Produktionsbetrieb nutzt `docker-compose.prod.yml` mit `timeapp-web`, `timeapp-db` und `timeapp-scheduler`.
- SMTP-Passwoerter werden verschluesselt in den Settings gespeichert. `SETTINGS_ENCRYPTION_KEY` oder `APP_SECRET` muss in Produktion stabil gesetzt und getrennt gesichert werden. Legacy-Klartextwerte aus frueheren Versionen werden beim naechsten gezielten SMTP-Speichern verschluesselt.
- AGB- und Datenschutz-PDFs bleiben im geschuetzten Upload-Storage und sind im Admin ueber `settings.manage` als Preview oder Download abrufbar.

## Backup und Restore
- Backup-Export erzeugt ein ZIP mit `manifest.json`, Datenbank-JSON-Dateien, Upload-Kandidaten und optionalem Runtime-Hinweis.
- Backup-Import ist aktuell ein geschuetzter Validate-Dry-Run unter `POST /api/v1/system/backup/import/validate`.
- Der Validate-Dry-Run prueft Manifest, Version, Schema, Tabellen-JSON, Upload-Kandidaten und Pfadsicherheit.
- Ein produktiver Restore-Apply ist bewusst nicht implementiert.
- Runtime-Overrides werden nicht automatisch zurueckgespielt.
- Ein Upload fuehrt niemals automatisch eine Wiederherstellung aus.

## Zuletzt validierte Checks
- `composer validate --strict`
- `vendor/bin/phinx migrate -c phinx.php`
- `vendor/bin/phpunit tests/Unit/SettingsSecretServiceTest.php tests/Unit/CompanySettingsServiceTest.php tests/Unit/SmtpTestServiceTest.php`
- `vendor/bin/phpunit tests/Unit/CompanySettingsServiceTest.php tests/Integration/RouterSmokeTest.php`
- `vendor/bin/phpunit tests/Unit/BackupServiceTest.php`
- `COMPOSER_ALLOW_SUPERUSER=1 composer test`
- `git diff --check`

## Offene Folgearbeiten
- Restore-Apply mit explizitem Admin-Gate, Dry-Run-Protokoll, Wartungsmodus und Rollback-Konzept.
- Secret-Haertung fuer weitere sensible Settings pruefen und bei Bedarf erweitern.
- Versionshistorie und Archivierungsstrategie fuer Settings-Dateien.
- Exportlayouts und produktionsreife Berichtstemplates finalisieren.
- Tiefere Offline-Konfliktbehandlung und vollstaendige Offline-Datei-Upload-Queue.
