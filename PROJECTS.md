# PROJECTS.md - Baustellen- und Zeiterfassungs-App

## Projektueberblick
Phase 1 hat das technische Backend-Grundgeruest und eine MVP-nahe mobile Mitarbeiter-PWA geliefert. Der aktuelle Fokus liegt auf Haertung, konsistenter Dokumentation, sicherem Backup-/Restore-Vorbau, Exportreife und kontrolliertem Ausbau bestehender Workflows.

## Frontend-Zielbild und Umsetzungsbedarf
Das Frontend ist als mobile Mitarbeiter-App unter `/app` als Vanilla-JS-PWA umgesetzt. Es ist klar vom bestehenden Admin-Backend getrennt und dient der taeglichen Nutzung durch Mitarbeiter, Kolonnen und mobile Baustellenrollen.

### Frontend-Architektur
- [x] Vanilla-JS-PWA als verbindliches Frontend-Zielbild festhalten und nicht auf React oder ein anderes Framework ausrichten.
- [x] App-Shell fuer mobile Nutzung definieren.
- [x] Clientseitiges Routing fuer Login, Heute, Zeiterfassung, Projekte, Historie und Profil vorsehen.
- [x] API-Client-Schicht fuer geschuetzte und oeffentliche Requests strukturieren.
- [x] Zentralen App-State fuer Auth, Theme, Benutzerkontext und Synchronisationsstatus planen.

### Authentifizierung und Session
- [x] Eigenes App-Login fuer Mitarbeiter vorbereiten.
- [x] Session-/Token-Handling fuer geschuetzte API-Aufrufe definieren.
- [x] Logout und Session-Wiederaufnahme beim App-Start beruecksichtigen.
- [x] Fehlerfall bei abgelaufener oder ungueltiger Session sauber behandeln.
- [x] Auth-Status so vorbereiten, dass Offline-Puffer und spaetere Synchronisation damit kompatibel bleiben.

### Mobile Kernscreens
- [x] Login-Screen fuer die mobile App definieren.
- [x] Startseite `Heute` mit Tagesstatus und Arbeitskontext vorsehen.
- [x] Zeiterfassung fuer Check-in, Check-out, Pause und Tagesabschluss planen.
- [x] Projekt- bzw. Baustellenwahl in der App vorsehen.
- [x] `Meine Zeiten` bzw. Historie als mobiler Bereich einplanen.
- [x] Abwesenheitsstatus fuer krank, Urlaub, Feiertag oder fehlend sichtbar machen.
- [x] Projektdateien und mobile Uploads als Frontend-Baustein umsetzen.
- [x] Profilbereich fuer Theme, Firmenprofil, Rechtstexte, Datenschutz und GEO-Hinweise vorsehen.

### PWA und Offline
- [x] `manifest.json` fuer installierbare App vorbereiten.
- [x] Service Worker fuer App-Shell und statische Assets vorsehen.
- [x] Offline-Caching fuer die Kernnavigation und den App-Start definieren.
- [x] IndexedDB fuer lokal gespeicherte, noch nicht synchronisierte Zeiteintraege einplanen.
- [x] Sync-Warteschlange fuer Wiederverbindung und Nachsendung vorbereiten.
- [x] Visuelle Anzeige fuer Online, Offline und laufende Synchronisation vorsehen.
- [x] Browser-Push fuer Buchungs-Erinnerungen mit Service Worker und Geraeteaktivierung vorbereiten.

### Theme und UI-System
- [x] Dieselben Modi `light`, `dark` und `system` wie im Admin verwenden.
- [x] Dieselbe Storage-Konvention `app.theme` im Frontend uebernehmen.
- [x] Dieselben semantischen Theme-Tokens und das vorhandene Theme-Verhalten als Vorlage nutzen.
- [x] Mobile Touch-Ziele mit mindestens 48px vorsehen.
- [x] Hohe Kontraste fuer Baustellen-, Handschuh- und Sonnenlichtnutzung fest einplanen.

### Frontend-Nutzung vorhandener Backend-Daten
- [x] Firmenprofil ueber `GET /api/v1/settings/company` im Frontend nutzbar machen.
- [x] Projekte ueber `GET /api/v1/projects` als mobile Datenbasis verwenden.
- [x] Timesheet-Logik ueber App-Tageskontext, Sync und Historien-Endpunkte einbinden.
- [x] App-Login auf `POST /api/v1/auth/login` ausrichten.
- [ ] Reports im Frontend vorerst nicht als Kernziel behandeln, sondern nachgelagert einordnen.
- [x] Projekt- und Buchungsdatei-Endpunkte fuer die mobile App anbinden.

### Recht, Datenschutz und GEO
- [x] AGB und Datenschutz im Frontend lesbar anzeigen.
- [x] GEO-Policy aus den globalen Settings beziehen.
- [x] GEO-Zustimmung und Hinweistext im Frontend vorbereiten.
- [x] Optionale Positionsspeicherung bei App-Zeiterfassung umsetzen und in Historie/Admin sichtbar machen.

### Frontend-MVP
- [x] Login
- [x] Heute-Screen
- [x] Zeiten erfassen
- [x] Geo Positionen mit erfassen
- [x] Zustand und Zeiterfassung lokal Speichern, wenn Verbindung zum Server unterbrochen ist
- [x] Zustand und Zeiterfassung mit Server Syncrosnisren wenn Serververbindung wieder besteht
- [x] Projektwahl
- [x] Offline-Puffer fuer Zeiteintraege
- [x] Theme-Umschaltung
- [x] Rechtstexte und Firmenprofil anzeigen

### Frontend nach MVP
- [x] Datei-Uploads im Frontend ermöglichen (Projekt und Buchungsbezogen)
- [x] Datei-Uploads auch von Handykameras entgegen nehmen und anhand von Metadaten sauber Rotieren und Speichern / Anzeigen im Frontend
- [x] Tiefere Historienansichten
- [ ] Verbesserte Sync-Konfliktbehandlung
- [ ] Vollstaendige Offline-Datei-Upload-Queue fuer spaetere Synchronisation ausbauen
- [x] Push- und Benachrichtigungslogik
- [ ] Erweiterte GEO-Funktionen
- [ ] Möglichkeit Kunden/Auftraggeber eine Zeiterfassung (Start und Ende) mit Unterschrift auf dem Touchscreen bestätigen zu lassen

## Bereits umgesetzt
- [x] Composer-, PHPUnit- und Phinx-Grundkonfiguration angelegt.
- [x] Front Controller unter `public/index.php` und Root-Bridge ueber `index.php` eingerichtet.
- [x] Konfigurationslayer fuer App, Datenbank, Rechte, Uploads und Exporte aufgebaut.
- [x] Runtime-Override fuer aktive Datenbankverbindung unter `storage/config/database.override.php` vorbereitet.
- [x] Admin-Backend mit Dashboard und Datenbank-Einstellungsseite erstellt.
- [x] Globales Theme-Switching fuer das Admin-Backend mit `light`, `dark` und `system` eingebaut.
- [x] REST-Endpunkte fuer Auth, Dashboard, Benutzer, Rollen, Projekte, Assets, Zeiterfassung, Uploads, Reports und DB-Settings angelegt.
- [x] Arbeitszeitlogik fuer gesetzlichen Pausenabzug implementiert.
- [x] Migrations- und Seeder-Basis fuer Rollen, Rechte, Projekte, Assets und revisionssichere `timesheets` erstellt.
- [x] Chart.js-Datenendpunkt vorbereitet.
- [x] CSV-Export funktionsfaehig implementiert; XLSX/PDF sind an `PhpSpreadsheet` und `mPDF` angebunden.
- [x] Backend-Menues fuer Projekte, User, Rollen, Geraete und Datenbankverwaltung aufgebaut.
- [x] CRUD-Basis fuer Projekte, User, Rollen und Geraete inklusive GoBD-konformer Archivierung umgesetzt.
- [x] Mehrfachrollen pro Benutzer ueber `user_roles` in Backend und API vorbereitet.
- [x] Projekt- und Geraeteanhaenge inkl. Upload und Archivierung im Backend integriert.
- [x] Datenbank-Menue um Live-Status, Technikdetails und klare Feedbacks erweitert.
- [x] Globaler `Settings`-Bereich fuer Firmenprofil, Rechtstexte, SMTP und GEO-Vorbereitung integriert.
- [x] Firmenlogo sowie AGB- und Datenschutz-PDFs koennen geschuetzt ausserhalb des Webroots hochgeladen werden.
- [x] Interner API-Zugriff auf das aktive Firmenprofil unter `GET /api/v1/settings/company` vorbereitet.
- [x] SMTP-Konfiguration inkl. Testversand-Workflow und letzter Testmeldung im Backend vorbereitet.
- [x] GEO-Policy mit globalem Feature-Flag, Hinweistext und optionaler Bestaetigung fuer das spaetere Frontend hinterlegt.
- [x] Backend um ein weiters nav Element erweitern: Anwesenheit (Zeigt wer heute da ist und wo er/sie/es sich befindet (Projekt/Baustelle/usw) Anzahl mit <span class="badge"></span> versehen damit Änderungen sofort sichtbar sind.
- [x] Backend Navigation (class="nav") mit bootstarp <span class="badge"></span> versehen um Anzahl der Projekte, User, Rollen und Geräte sofort sichtbar zu machen.
- [x] Name der App (class="brand" und Browser title) in den Settings zum ändern verfügbar machen.
- [x] Backend vorbereitung vor Statistische Auswertung mit Chart.js und CO.
- [x] Backend vorbereitung für Buchlatungsexport um Zeiterfassung an Buchlatung zu geben
- [x] Backend-Vorbereitung fuer Datensicherung und Restore-Validierung umgesetzt; produktiver Restore-Apply ist bewusst noch nicht implementiert.
- [x] Session-basierte Authentifizierung mit HttpOnly-Cookie, Admin-Login und Rechtemiddleware fuer Admin/API eingebaut.
- [x] Erstaufbau ohne fest eingebauten Demo-Login: erster Administrator wird per `php bin/bootstrap-admin.php` angelegt.
- [x] Standard-Seeds liefern nur Referenzdaten; Demo-Benutzer, Demo-Projekte und Demo-Assets liegen in einem separaten optionalen Seeder.
- [x] Mobile Mitarbeiter-WebApp unter `/app` als Vanilla-JS-PWA-App-Shell mit Login, Heute, Zeiten, Projektwahl und Profil umgesetzt.
- [x] Offline-Puffer per IndexedDB, Service Worker, lokaler Today-Cache und Sync-Warteschlange fuer die Mitarbeiter-App vorbereitet.
- [x] Neue App-Endpunkte fuer Sessionstatus, Tageskontext und synchronisierbare Zeiterfassung inklusive optionaler GEO-Uebermittlung eingefuehrt.
- [x] Backend mit echter Authentifizierung, Sessions und Rechtemiddleware absichern.
- [x] Session-/Token-taugliche API-Nutzung fuer die mobile Mitarbeiter-App ausbauen.
- [x] Endpunkte fuer benutzerbezogene Tagesdaten bzw. eine mobile Startansicht vorbereiten, falls das bestehende Dashboard dafuer nicht passend ist.
- [x] Robuste Sync-faehige Zeiterfassungs-Endpunkte fuer Offline-Nachsendung definieren.
- [x] Browser-Push fuer fehlende Tagesbuchungen mit Rollenfreigabe, Admin-Settings, Geraeteverwaltung und Cron-CLI eingebaut.
- [x] Mobile Projekt- und Buchungsdatei-Uploads mit geschuetzten Abruf-URLs, Kamera-Inputs und JPEG-EXIF-Orientation-Normalisierung umgesetzt.
- [x] Backend-Anzeige fuer Buchungsanhaenge in Buchungen und Kalender inklusive geschuetztem Download, Bildvorschau und Archivierung umgesetzt.
- [x] Abgelaufene App-Sessions fuehren sauber zum Login, ohne Offline-Queue oder lokale Caches zu verlieren.
- [x] Abwesenheitsstatus fuer Krank, Urlaub, Feiertag und automatisch abgeleitetes Fehlen an Werktagen in Admin, Kalender und App sichtbar gemacht.
- [x] Mobile App-Topbar zeigt den aktuellen Tagesstatus seitenuebergreifend inklusive Projekt, Startzeit, Pause, Abschluss oder Fehlend-Hinweis.
- [x] Mobile Historie als Monats-/Tagesansicht mit Summen, Buchungsdetails, Pausen, Anhaengen und Offline-Cache ausgebaut.
- [x] Mobile Historie repariert: frische Monatsdaten werden nach Sync/Upload geladen, Projektbuchungen und Anhaenge bleiben nicht mehr auf Nullstaenden haengen.
- [x] Gespeicherte Standortdaten zu Buchungen werden in mobiler Historie sowie in Admin-Buchungen/Kalender mit Kartenlink sichtbar gemacht.
- [x] Push-Test aus dem mobilen Profil sendet eine echte serverseitige Web-Push-Testnachricht an das aktive Geraet.
- [x] Zeiterfassungspflicht pro User steuerbar; freiwillige Admin-/Notfalluser bleiben aktiv, werden aber nicht als fehlend gewertet oder erinnert.
- [x] Backup-Import-Validierung als sicherer Dry-Run gehaertet: Manifest, Version, Schema, Tabellen-JSON und Pfade werden geprueft; Restore-Apply bleibt getrennt.
- [x] Möglichkeit schaffen das System auch in Docker-Compose zu deplyen
- [x] Produktions-Compose fuer VPS/Reverse Proxy mit `timeapp-web`, `timeapp-db`, `timeapp-scheduler`, privaten DB-Netz, Proxy-Netz und stabilen Volumes validiert.
- [x] Dockerfile fuer `php:8.2-apache` bereinigt: nur fehlende Extensions werden gebaut; bereits enthaltene Core-Extensions werden nicht erneut kompiliert.

## Offene Punkte

### MVP-Haertung
- [ ] Sensitive Secrets wie `smtp_password` verschluesseln bzw. in ein dediziertes Secret-Konzept ueberfuehren.
- [ ] Backup-Restore-Apply mit explizitem Admin-Gate, Dry-Run-Protokoll, Rollback-/Wartungsmodus-Konzept und separatem Auftrag produktionsreif bauen.
- [ ] Validierung, Fehlermeldungen und Wiederherstellen archivierter Datensaetze weiter ausbauen.
- [ ] Download- und Vorschaufunktion fuer Settings-Dateien wie Logo, AGB und Datenschutz inklusive Rechtepruefung vervollstaendigen.
- [ ] Monatsberichte mit echtem mPDF-Layout und Excel-Export mit finalem Template ausbauen.

### Nach-MVP-Funktionen
- [ ] Verbesserte Sync-Konfliktbehandlung fuer Offline-/Wiederverbindungsfaelle ausbauen.
- [ ] Vollstaendige Offline-Datei-Upload-Queue fuer spaetere Synchronisation ausbauen.
- [ ] GEO-Erfassung inklusive Einwilligungsfluss, Datenschutz-Policy und fachlicher Auswertung finalisieren.
- [ ] Settings-Dateien mit Versionshistorie vervollstaendigen.

### Spaetere Komfortfunktionen
- [ ] Reports in der mobilen App nachgelagert einordnen.
- [ ] Erweiterte GEO-Funktionen wie Karten-/Distanzansichten ausbauen.
- [ ] Kunden/Auftraggeber koennen eine Zeiterfassung mit Unterschrift auf dem Touchscreen bestaetigen.

## Sofort nutzbare Einstiegspunkte
- Admin-Backend: `/admin`
- Settings: `/admin/settings/company`
- Datenbankeinstellungen: `/admin/settings/database`
- Push-Einstellungen: `/admin/settings/push`
- App-Login API: `POST /api/v1/auth/login`
- App-Push-Status API: `GET /api/v1/app/push/status`
- App-Push-Test API: `POST /api/v1/app/push/test`
- VAPID-Key-Generator: `php bin/generate-vapid-keys.php`
- Push-Reminder CLI: `php bin/send-push-reminders.php`
- CLI-Erstadministrator: `php bin/bootstrap-admin.php --email=... --password=... --first-name=... --last-name=...`
- Dashboard API: `/api/v1/dashboard/overview`
- Firmenprofil API: `/api/v1/settings/company`
- Projektliste API: `/api/v1/projects`
- App-Projektdateien API: `GET/POST /api/v1/app/projects/{id}/files`
- App-Projektdatei-Abruf: `GET /api/v1/app/project-files/{id}/download`
- App-Buchungsdateien API: `GET/POST /api/v1/app/timesheets/{id}/files`
- App-Buchungsdatei-Abruf: `GET /api/v1/app/timesheet-files/{id}/download`
- Admin-Buchungsdatei-Abruf: `GET /admin/timesheet-files/{id}/download`
- Admin-Buchungsdatei-Archivierung: `DELETE /admin/timesheet-files/{id}`
- Timesheets API: `/api/v1/timesheets`
- Timesheet-Kalkulation: `POST /api/v1/timesheets/calculate`
- CSV-Export: `/api/v1/reports/export?format=csv&period=month`
