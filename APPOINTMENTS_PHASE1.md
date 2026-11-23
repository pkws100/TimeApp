# Terminverwaltung in Phase 1

Unter `/admin/appointments` stehen die Terminliste und die Formulare für betriebliche Termine bereit. Der bestehende Kalender unter `/admin/calendar` kann sie zusammen mit Buchungen und Personal-Events anzeigen.

## Bedienung

- Die Liste beginnt mit den noch bevorstehenden, nicht archivierten Terminen. Sie sortiert nach Beginn und zeigt 25 Ergebnisse pro Seite. Die Auswahl für heute, die nächsten 7 oder 30 Tage beginnt jeweils am heutigen lokalen Kalendertag. Im benutzerdefinierten Zeitraum zählen beide angegebenen Tage mit.
- Zeitraum, Projekt, Mitarbeiter, Status und Suchtext lassen sich kombinieren. Die Suche berücksichtigt Titel, Beschreibung, Einsatzort, Projektname und Projektnummer. Zeiträume finden auch Termine, die vor dem ausgewählten Zeitraum beginnen und in ihn hineinreichen.
- Beim Anlegen und Bearbeiten sind ein Titel, Beginn und Ende erforderlich. Projekt, Einsatzort und Teilnehmer sind optional. Die Mitarbeiterauswahl lässt sich durchsuchen. Neue Zuordnungen sind für aktive Mitarbeiter und zugängliche, aktive Projekte möglich. Bestehende Referenzen zu archivierten Stammdaten können erhalten oder entfernt werden, soweit die Akteure weiterhin auf den Termin zugreifen dürfen.
- Der Standardstatus lautet **Geplant**. Beim Bearbeiten kann ein geplanter Termin auf **Erledigt** oder **Abgesagt** gesetzt werden. Erledigte und abgesagte Termine behalten ihren Status. Archivieren blendet den Termin aus Liste und Kalender aus. Die Detailseite bleibt mit Berechtigung über die Termin-ID erreichbar.
- Jede Bearbeitung und Archivierung prüft die geladene Version. Bei einem Konflikt zeigt die Anwendung einen Hinweis zum erneuten Laden. Vor einem neuen Speicherversuch den aktuellen Stand mit den eigenen Eingaben abgleichen.

## Kalender und Rechte

| Ansicht | Inhalt | Recht |
| --- | --- | --- |
| Alle | Die freigegebenen Kalenderquellen | Mindestens eines der folgenden Ansichtsrechte |
| Buchungen | Zeitbuchungen, Abwesenheiten, Feiertage und Betriebsurlaub | `timesheets.view` |
| Termine | Betriebliche Termine | `appointments.view` |
| Personal | Schulungen, Prüfungen, Fälligkeiten und Reminder aus `user_events` | `personnel.view` |

Die Monatszelle zählt geplante Termine, zeigt bis zu zwei Titel und nennt die Anzahl weiterer Termine. Die Tagesdetails zeigen alle nicht archivierten Termine für den Tag mit Uhrzeit, Status, Projekt, Teilnehmern und gegebenenfalls Einsatzort. Ein Termin, der um Mitternacht endet, erscheint nur bis zum Vortag. Über **Termin anlegen** wird das Datum übernommen.

`appointments.view` erlaubt Terminliste, Detailansicht und Kalendertermine. Für Anlage, Bearbeitung, Statuswechsel und Archivierung ist zusätzlich `appointments.manage` erforderlich. Beide Rechte werden den Systemrollen `administrator`, `geschaeftsfuehrung`, `disposition` und `bauleiter` zugewiesen. Individuelle Zuweisungen laufen über die Rollenverwaltung. Die bestehende Projektzugriffslogik gilt für verknüpfte Termine und die Projektwahl. Buchungen, Personal-Events und Personalakten unterliegen ihren eigenen Rechten.

## Datenmodell und Routen

`AppointmentService` speichert den Termin und seine Teilnehmer in einer gemeinsamen Transaktion. `appointments` enthält Stammdaten, UTC-Beginn und -Ende, Eingabezeitzone, Status, Version sowie Erstellungs-, Änderungs- und Archivierungsdaten. `appointment_participants` enthält jeweils eine eindeutige Zuordnung zwischen Termin und Benutzer. Fremdschlüssel sichern die Referenzen. Schreibvorgänge der Terminverwaltung betreffen ausschließlich diese beiden Tabellen.

Die Formulare verwenden die Anwendungszeitzone `APP_TIMEZONE`, standardmäßig `Europe/Berlin`. Zeitpunkte werden als UTC gespeichert. Ein Zeitpunkt, den die Uhr beim Wechsel zur Sommer- oder Winterzeit überspringt oder doppelt anzeigt, wird bei der Eingabe abgewiesen. Die betroffene Uhrzeit muss eindeutig gewählt werden.

| Methode | Route | Funktion |
| --- | --- | --- |
| GET | `/admin/appointments` | Gefilterte Terminliste |
| GET | `/admin/appointments/new` | Anlage, optional mit `?date=YYYY-MM-DD` |
| GET | `/admin/appointments/{id}` | Termindetails |
| GET | `/admin/appointments/{id}/edit` | Bearbeitungsformular |
| POST | `/admin/appointments` | Anlage speichern |
| POST | `/admin/appointments/{id}` | Änderungen und Statuswechsel speichern |
| POST | `/admin/appointments/{id}/archive` | Archivieren |

Die bestehenden Kalender-Endpunkte `/admin/calendar/month` und `/admin/calendar/day` behalten ihre `data`-Struktur. Mit `view=all`, `bookings`, `appointments` oder `personnel` wird die Quelle gewählt. Ohne `view` gilt **Alle** im Rahmen der aktuellen Rechte. Der Monats-Feed ergänzt Terminanzahl und Titelvorschau. Die Tagesantwort ergänzt HTML-Terminliste und Terminanzahl. Die Buchungskennzahlen werden weiterhin aus Buchungen berechnet. Die Schreibformulare verwenden sitzungs- und benutzergebundene CSRF-Tokens.

## Entwicklung und Migration

1. Die effektive Entwicklungsverbindung inklusive `DB_OVERRIDE_FILE` prüfen. Auf diesem Server liegt die Produktionsinstanz im Checkout `/var/www/html`. Änderungen an Dateien dort werden direkt von Apache ausgeliefert. Für diesen Entwicklungsstand wird `/srv/timeapp-dev` verwendet.
2. Im Entwicklungscheckout `vendor/bin/phinx migrate -c phinx.php` ausführen. `20261123120000_create_appointment_management.php` ergänzt die beiden Tabellen, Indizes, Fremdschlüssel und Rechte. Bei einem Neuaufbau danach `vendor/bin/phinx seed:run -c phinx.php -s InitialReferenceSeeder` ausführen. Der Rücklauf ist gesperrt, damit gespeicherte Termine und Zuordnungen erhalten bleiben.
3. Bei getrennten Datenbankkonten benötigt die Laufzeit Zugriff auf beide neuen Tabellen. Für Termine sind `SELECT`, `INSERT` und `UPDATE` erforderlich. Für die Zuordnungstabelle kommen `SELECT`, `INSERT` und `DELETE` für das Ändern von Teilnehmerlisten zum Einsatz. Ein separates Konto für das allgemeine Systembackup benötigt `SELECT` auf beiden Tabellen. Migration und Rechtevergabe bleiben Aufgaben des Migrationskontos.
4. Browser und Anwendung auf die Entwicklungsverbindung richten. Mit einem synthetischen Benutzer einen Termin anlegen, zuordnen, in der Liste und im Kalender prüfen, ändern, absagen und abschließend archivieren. Die automatisierten Integrationstests verwenden isolierte MariaDB-Schemata. Für lokale Tests den Test-Socket explizit über `TIMEAPP_TEST_DB_*` konfigurieren.

Das allgemeine Systembackup enthält beide Termintabellen. Die Dry-Run-Prüfung unterstützt die bisherigen Archivformate aus der Zeit vor der Terminmigration weiterhin.

## Umfang weiterer Phasen

Phase 1 umfasst die Terminverwaltung im Admin-Bereich. Push, Erinnerungen, Versandhistorie, Mitarbeiteransicht, Bestätigungen, wiederkehrende Termine und die fachliche Verbindung zu Personal-Fälligkeiten sind für spätere Phasen vorgesehen. Die Terminliste und der Kalender werden in der konfigurierten Anwendungszeitzone angezeigt. Archivierte Termine lassen sich in dieser Phase über ihre Detailadresse einsehen. Ein Archivfilter und eine Wiederherstellung sind nicht Teil der Oberfläche.
