# AGENTS.md - Zentraler Agenten-Leitfaden fuer die Baustellen- und Zeiterfassungs-App

## 1. Zweck dieser Datei
Diese Datei ist die erste Anlaufstelle fuer neue Agentenlaeufe in diesem Projekt.
Sie beschreibt:

- den aktuellen realen Projektstand
- feste fachliche und technische Entscheidungen
- verbindliche Arbeitsregeln fuer weitere Implementierungen
- offene Punkte, die in kommenden Laeufen priorisiert werden sollen

Diese Datei ist kein vollstaendiges PRD und keine Langform-Spezifikation. Sie soll neue Agenten schnell, eindeutig und ohne veraltete Annahmen arbeitsfaehig machen.

## 2. Rolle des Agents
Der Agent arbeitet als senioriger Full-Stack- und DevOps-Partner fuer diese Anwendung.
Er soll:

- den aktuellen Repo-Stand zuerst verstehen und dann aendern
- produktionsnahe, sichere und nachvollziehbare Aenderungen liefern
- bestehende Entscheidungen respektieren und nicht stillschweigend neu auslegen
- Implementierungen konsistent mit vorhandenen Mustern fortsetzen
- Risiken, Luecken und Folgearbeiten klar benennen

Der Agent soll keine veralteten Annahmen aus frueheren Projektphasen weitertragen, wenn der aktuelle Code oder die aktuelle Doku etwas anderes zeigt.

## 3. Projektziel und aktuelle Phase
Ziel ist eine mobile Baustellen- und Zeiterfassungs-App mit Admin-Backend, Rollen- und Rechteverwaltung, Projekt- und Geraeteverwaltung, Exporten sowie spaeterem mobilem PWA-Frontend.

Aktueller Stand:

- Phase 1 liefert das technische Backend-Grundgeruest.
- Der Schwerpunkt liegt aktuell auf Admin-Backend, API, Datenmodell, Settings, Uploads, Exporte und fachlichen Grundlagen.
- Ein erster Mobile-WebApp-Thin-Slice unter `/app` mit Session-Auth, Heute-Screen, Zeiterfassung, Projektwahl, Profil, Service Worker und Offline-Puffer ist umgesetzt.
- Das eigentliche vollstaendige Offline-First-PWA-Frontend mit Historie, tieferem Sync und erweitertem Ausbau folgt weiterhin spaeter.

## 4. Tech-Stack und Laufzeitumgebung
- Umgebung: Debian 12 in einem unprivilegierten LXC-Container
- Stack: Apache, MariaDB 10.11, PHP 8.2
- App-Stil: monolithische PHP-Anwendung mit gemeinsamem Backend fuer Admin und API
- Routing:
  - Admin unter `/admin/*`
  - API unter `/api/v1/*`
- Bootstrap und Routen: `bootstrap/app.php`
- Datenbankmigrationen: Phinx
- Tests: PHPUnit
- PDF-Generierung: mPDF
- Excel-Generierung: PhpSpreadsheet
- Charts im Admin: vorbereitet fuer Chart.js-Datenquellen

## 5. Architekturstand
Die Anwendung ist aktuell API-first aufgebaut, aber Admin und API laufen innerhalb derselben PHP-App.

Wichtige Architekturbausteine:

- `AdminView` stellt das globale Admin-Layout bereit
- `AppView` stellt die mobile Mitarbeiter-WebApp unter `/app` bereit
- Domain-Services kapseln Fachlogik fuer:
  - Projects
  - Users
  - Roles
  - Assets
  - Files
  - Settings
  - Timesheets
  - Reports
- Controller trennen Admin-Seiten und API-Endpunkte
- Datenbankzugriffe laufen ueber PDO und vorbereitete Statements

Bereits vorhandene Admin-Bereiche:

- Dashboard
- Projekte
- User
- Rollen
- Geraete
- Settings
- Datenbank

## 6. Aktueller Funktionsstand
Bereits umgesetzt:

- Dashboard im Admin-Backend
- CRUD-Basis fuer Projekte, User, Rollen und Geraete
- GoBD-konforme Archivierung statt physischem Loeschen
- Projekt- und Geraeteanhaenge mit Upload und Archivierung
- globale Settings fuer:
  - Firmenprofil
  - Rechtstexte
  - SMTP
  - GEO-Vorbereitung
- Datenbank-Settings mit Live-Status und Verbindungsfeedback
- Theme-Switching im Admin mit `light`, `dark` und `system`
- farbliche Live-Pruefung fuer Settings-Felder
- Rollen- und Rechtebasis mit Mehrfachrollen pro Benutzer
- CSV-Export funktionsfaehig
- XLSX- und PDF-Export technisch vorbereitet
- Dashboard-JSON fuer spaetere Chart.js-Auswertungen
- echte Session-basierte Authentifizierung fuer Admin und Mitarbeiter-App
- zentrale Zugriffskontrolle fuer Admin- und geschuetzte API-Routen
- Erstaufbau des ersten Administrators per CLI-Bootstrap statt fest eingebautem Demo-Login
- mobile Mitarbeiter-WebApp unter `/app` als Vanilla-JS-PWA-Thin-Slice
- App-Login, Session-Status, Heute-Screen, Projektwahl, Zeiterfassung und Profil
- Service Worker, IndexedDB-Cache und Sync-Warteschlange fuer Offline-/Wiederverbindungsfaelle
- optionales GEO-Mitsenden bei App-Zeiterfassung, wenn global aktiviert und lokal bestaetigt

Noch nicht final umgesetzt:

- Secret-Haertung fuer sensible Daten wie SMTP-Passwoerter
- Download-/Preview-Endpunkte fuer Settings- und Objektdateien
- finales mPDF-Berichtslayout und produktionsreife Excel-Templates
- mobiles PWA-Frontend ist als Thin Slice vorhanden, aber noch nicht vollstaendig fuer Historie, Uploads und tiefere Konfliktbehandlung ausgebaut
- produktive GEO-Erfassung in der Zeiterfassung

## 7. Fachliche Festlegungen
Diese Entscheidungen gelten aktuell als gesetzt und sollen nicht ohne expliziten Grund neu aufgerollt werden:

- `Projekt` und `Baustelle` sind in Phase 1 dieselbe Entitaet.
- Fahrzeuge und Geraete sind eigene Ressourcen und keine Rollen.
- Benutzer koennen mehrere Rollen gleichzeitig haben.
- Effektive Rechte ergeben sich aus allen zugewiesenen Rollen.
- Stammdaten werden nicht physisch geloescht, sondern archiviert.
- `timesheets` bleiben system-versioniert in MariaDB.
- Arbeitszeitlogik beruecksichtigt gesetzliche Pausen:
  - 30 Minuten bei mehr als 6 Stunden
  - 45 Minuten bei mehr als 9 Stunden
- `timesheets` decken mindestens `work`, `sick`, `vacation`, `holiday` und `absent` ab.
- Das Firmenprofil ist ein globaler Singleton-Datensatz in `company_settings`.
- SMTP-Settings liegen aktuell in MariaDB.
- GEO ist fachlich vorbereitet, aber noch nicht produktiv Teil der Zeiterfassung.
- Theme-Default ist `system`.

## 8. Datenmodell und Migrationen
Migrationen laufen ausschliesslich ueber Phinx. Rohe SQL-Dateien fuer Schemaaufbau sind zu vermeiden.

Wichtige Tabellen / Bereiche:

- `permissions`, `roles`, `role_permissions`
- `users`, `user_roles`
- `projects`, `project_memberships`, `project_files`
- `assets`, `asset_assignments`, `asset_files`
- `company_settings`
- `timesheets`

Wichtige Migrationssaetze im Repo:

- Initialschema
- Management-Backend-Erweiterungen
- `company_settings` fuer globales Firmenprofil und Settings

Regeln:

- `timesheets` bleiben revisionssicher per MariaDB System Versioning
- Archivierungsfelder sind Teil der Historien- und GoBD-Strategie
- Beziehungen und Historie duerfen durch Archivierung nicht unlesbar werden

## 9. Rollen, Rechte und Zugriffe
Das Rechtekonzept ist getrennt von der Rollendefinition modelliert.

Aktuelle Kernrechte decken unter anderem ab:

- Dashboard
- User-Verwaltung
- Rollen-Verwaltung
- Projekte ansehen/verwalten
- Dateien ansehen/hochladen/verwalten
- Assets verwalten/zuweisen
- Zeiten erfassen/verwalten
- Reports exportieren
- globale Settings verwalten
- Datenbank-Settings verwalten

Wichtige Rollen aus der aktuellen Konfiguration:

- `administrator`
- `geschaeftsfuehrung`
- `bauleiter`
- `kolonnenfuehrer`
- `mitarbeiter`
- `disposition`

## 10. Uploads, Storage und Exporte
Uploads werden geschuetzt ausserhalb des Webroots gespeichert.

Verbindliche Regeln:

- Uploads niemals in `public/` speichern
- Datei-Metadaten in der Datenbank halten
- Projekt- und Asset-Dateien logisch getrennt behandeln
- Settings-Dateien wie Logo, AGB-PDF und Datenschutz-PDF ebenfalls geschuetzt speichern
- Archivierung bedeutet auch bei Dateien keine unkontrollierte physische Loeschung

Exportstrategie:

- CSV aktiv nutzbar
- Excel ueber PhpSpreadsheet
- PDF ueber mPDF

## 11. Settings, Theme, SMTP und GEO
Aktuell existiert ein globaler Settings-Bereich unter `/admin/settings/company`.

Er umfasst:

- Firmenstammdaten
- Logo
- AGB als Text und optionales PDF
- Datenschutztext und optionales PDF
- SMTP-Konfiguration
- SMTP-Testversand
- GEO-Feature-Flag und Hinweistext

Theme-System:

- drei Modi: `light`, `dark`, `system`
- Speicherung clientseitig ueber `localStorage`
- das Admin-Layout nutzt ein zentrales Theme-System
- neue Admin-Seiten muessen mit denselben Theme-Tokens und Mustern arbeiten

Settings-Felder:

- werden im Backend live farblich markiert
- gruene Markierung bedeutet sauber ausgefuellt
- gelbe Markierung bedeutet optional/offen
- rote Markierung bedeutet fehlend oder ungueltig

## 12. Frontend-Zielbild fuer den naechsten grossen Schritt
Das spaetere Frontend soll als mobile, offlinefaehige PWA entstehen.

Zielvorgaben:

- Mobile First
- Offline-First fuer Baustellen mit schlechter Verbindung
- lokale Zwischenspeicherung per IndexedDB
- spaetere Synchronisation per Background Sync
- grosse Touch-Ziele, auch mit Handschuhen bedienbar
- hohe Kontraste fuer Sonnenlicht und Baustellenumgebung
- Theme-Mechanismus soll mit dem Backend konsistent wiederverwendet werden
- Firmenprofil und GEO-Policy sollen spaeter im Frontend lesbar nutzbar sein

## 13. Wichtige Einstiegspunkte
Relevante aktuelle URLs und Schnittstellen:

- `/admin`
- `/admin/login`
- `/app`
- `/admin/settings/company`
- `/admin/settings/database`
- `/api/v1/auth/session`
- `/api/v1/app/me/day`
- `/api/v1/app/timesheets/sync`
- `/api/v1/dashboard/overview`
- `/api/v1/projects`
- `/api/v1/settings/company`
- `/api/v1/reports/export`

Wichtige Code-Einstiegspunkte:

- `bootstrap/app.php`
- `bin/bootstrap-admin.php`
- `src/Presentation/Admin/AdminView.php`
- `config/permissions.php`
- `config/uploads.php`
- `PROJECTS.md`
- `DATABASE.md`

## 14. Verbindliche Arbeitsregeln fuer kuenftige Agents
Vor jeder groesseren Aenderung:

- zuerst den aktuellen Repo-Stand lesen
- Routing, Doku und vorhandene Muster pruefen
- neue Arbeit an den realen Ist-Stand anpassen

Beim Implementieren:

- bestehende Entscheidungen nicht stillschweigend ueberschreiben
- Admin-Muster, Theme-Muster und Settings-Muster konsistent weiterverwenden
- Geschaeftslogik von Praesentation trennen
- PDO mit vorbereiteten Statements nutzen
- Migrationen nur ueber Phinx anlegen
- keine physische Loeschung fuer archivierte Stammdaten einbauen
- geschuetzte Uploads ausserhalb des Webroots halten
- neue Features moeglichst auch fuer Admin und API konsistent denken

Beim Aendern von Dokumentation:

- `AGENTS.md` ist die erste Orientierung
- `PROJECTS.md` beschreibt Umsetzungsstand und offene Folgearbeiten
- `DATABASE.md` beschreibt das Zielbild und die fachlichen DB-Leitlinien
- `DATABSE.md` ist nur noch ein Hinweis auf `DATABASE.md`

## 15. Was Agents nicht tun sollen
- keine alten Planungsannahmen gegen den aktuellen Code durchsetzen
- keine rohen SQL-Schemadateien statt Phinx-Migrationen einfuehren
- keine Uploads in `public/` legen
- keine Stammdaten physisch loeschen, wenn Archivierung vorgesehen ist
- keine neue Parallelarchitektur erfinden, solange der bestehende Monolith mit Admin und API gemeinsam weitergefuehrt wird
- das Frontend nicht als bereits fertig oder verbindlich vorhanden behandeln

## 16. Offene Prioritaeten
Die naechsten wahrscheinlichen Arbeitsbereiche sind:

- Authentifizierung, Sessions und Rechtemiddleware
- Secret-Haertung fuer sensible Settings
- Download und Vorschau fuer hochgeladene Dateien
- produktionsreife Exportlayouts
- Frontend-Nutzung von Firmenprofil, Theme und GEO-Policy
- echte mobile PWA mit Offline-Sync
- spaetere GEO-Erfassung in der Zeiterfassung

## 17. Kurzfazit fuer neue Laeufe
Wenn ein neuer Agentenlauf startet, gilt:

- Das Backend-Grundgeruest ist bereits vorhanden.
- Settings, Theme, CRUD, Uploads und DB-/Admin-Basis existieren schon.
- Der naechste grosse Schritt ist nicht mehr das Grundgeruest, sondern die sichere Vertiefung und das mobile Frontend.
- Vor jeder neuen Umsetzung muessen `AGENTS.md`, `PROJECTS.md`, `DATABASE.md` und `bootstrap/app.php` gemeinsam gegen den aktuellen Stand gelesen werden.
- Nach Beendigung einer Arbeitsanweisung, einen Ui-Agent sowie einen Workflow-Agent und einen Code-Review der Änderungen laufen lassen. Findigs oder Probleme bitte direkt Fixen/beheben.