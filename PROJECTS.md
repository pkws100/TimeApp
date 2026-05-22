# PROJECTS.md - Offene Folgearbeiten

## Zweck
Diese Datei sammelt nur noch offene fachliche und technische Folgearbeiten.
Erledigte Implementierungsdetails werden hier nicht als Historie gepflegt; die
aktuelle Wahrheit liegt im Code, in Tests und in der Git-Historie.

## Prioritaeten
- Secret-Haertung regelmaessig nachziehen, wenn neue Settings, Tokens oder anderes Credential-Material dauerhaft in MariaDB gespeichert werden; App-Backups redigieren bekannte Legacy-Klartext-Secrets, klassifizieren retained sensitive Fields und schliessen Runtime-DB-Overrides aus.
- Restore-Apply als separaten produktiven Auftrag konzipieren und bauen: explizites Admin-Gate, Dry-Run-Protokoll, Wartungsmodus, Rollback-Konzept und klare Freigabe.
- Monatsberichte mit produktionsreifem mPDF-Layout und finalen Excel-Templates ausbauen.
- Versionshistorie und Archivierungsstrategie fuer Settings-Dateien wie Logo, AGB-PDF und Datenschutz-PDF vervollstaendigen.
- Validierung, Fehlermeldungen und Wiederherstellen archivierter Datensaetze weiter ausbauen.

## Mobile App und Offline
- Verbesserte Sync-Konfliktbehandlung fuer Offline-/Wiederverbindungsfaelle ausbauen.
- Vollstaendige Offline-Datei-Upload-Queue fuer spaetere Synchronisation umsetzen.
- GEO-Erfassung inklusive Einwilligungsfluss, Datenschutz-Policy und fachlicher Auswertung finalisieren.
- Reports in der mobilen App nachgelagert einordnen und erst nach den Kernhaertungen umsetzen.
- Erweiterte GEO-Funktionen wie Karten- oder Distanzansichten spaeter fachlich bewerten.
- Feiertags-Sonderregeln auf Gemeindeebene spaeter fachlich bewerten, z. B. Mariae Himmelfahrt in Teilen Bayerns oder Augsburger Friedensfest.

## Pflegehinweise
- Neue erledigte Arbeiten nicht als lange Checklisten nachtragen.
- Wenn eine offene Aufgabe umgesetzt wurde, den Punkt entfernen oder auf die naechste konkrete Folgearbeit reduzieren.
- Dauerhafte Betriebs-, Architektur- und Einstiegshinweise gehoeren nach `README.md`, `DEPLOY.md`, `DATABASE.md` oder `AGENTS.md`.
