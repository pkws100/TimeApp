# Mitarbeitertermine, Bestätigung und manueller Push

## Mitarbeiter-App

**Termine** öffnet `/app/termine`. Angemeldete Mitarbeiter sehen dort ihre aktiven Zuordnungen. **Heute** listet Termine im heutigen Kalendertag. **Kommende Termine** enthält geplante Termine bis zu deren Ende. **Vergangene und abgesagte Termine** zeigt abgelaufene, erledigte und abgesagte Termine. Die Seiten enthalten bis zu 50 Einträge.

Ein Link wie `/app/termine?id=123` öffnet die Termindetails. Die API prüft die Zuordnung des aktuell angemeldeten Mitarbeiters bei jedem Abruf. Sie liefert Titel, Uhrzeiten, Beschreibung, Status, Einsatzort und gegebenenfalls Projektname und Projektnummer. Projektdateien und andere Projektdaten unterliegen ihren eigenen Zugriffsregeln. Die App lädt Termindaten online und hält sie für die aktuelle Ansicht im Arbeitsspeicher. Bei Verbindungsverlust weist sie auf die Online-Anforderung hin.

**Termin zur Kenntnis genommen** speichert den UTC-Zeitpunkt und die geladene Terminversion für den angemeldeten Teilnehmer. Wiederholungen für dieselbe Version behalten den ursprünglichen Zeitpunkt. Erst geänderte Termindaten oder Teilnehmer ergeben eine neue Version. Der bisherige Stand bleibt erkennbar, und der aktuelle Stand kann erneut bestätigt werden. Die Bestätigung entsteht durch die ausdrückliche Aktion. Ein Zustellversuch, das Öffnen des Termins und eine Bestätigung sind getrennte Vorgänge.

| Methode | Route | Zweck |
| --- | --- | --- |
| GET | `/api/v1/app/appointments?filter=upcoming&page=1` | Liste der eigenen Termine (`today`, `upcoming`, `past`) |
| GET | `/api/v1/app/appointments/{id}` | Eigener Termin und CSRF-Token |
| POST | `/api/v1/app/appointments/{id}/acknowledge` | Angezeigte `version` mit `csrf_token` bestätigen |

Die Endpunkte nutzen die bestehende Sitzung und die Kontobindung `X-TimeApp-User-Id`. Antworten mit Termindaten setzen `Cache-Control: private, no-store`. Eine fehlende Zuordnung und eine unbekannte ID ergeben denselben Fehler. Bei veralteter Terminversion antwortet die Bestätigung mit HTTP 409.

## Administratoren und Disposition

Die Detailseite unter `/admin/appointments/{id}` zeigt die Bestätigungen pro Teilnehmer und kennzeichnet deren Bezug zur Terminversion. **Termin jetzt per Push senden** öffnet eine Vorschau mit Push-Berechtigung, aktiven Geräten und Versandfähigkeit pro Teilnehmer. Das Posten des bestätigten Formulars löst den Versand aus. Der Empfängerkreis besteht aus den zugewiesenen Mitarbeitern. Für abgesagte Termine enthält die Nachricht einen ausdrücklichen Absagehinweis.

Der manuelle Versand und die Versandhistorie benötigen `appointments.view` und `appointments.dispatch`. Die bestehende Projektberechtigung gilt auch für diesen Zugriff. Migration und Seeder vergeben `appointments.dispatch` standardmäßig an Administrator, Geschäftsführung, Disposition und Bauleitung. Für die persönliche Mitarbeiteransicht genügt die Anmeldung mit einer aktiven Terminzuordnung.

| Methode | Route | Zweck |
| --- | --- | --- |
| GET | `/admin/appointments/{id}/dispatch` | Version und Empfänger vor dem Versand prüfen |
| POST | `/admin/appointments/{id}/dispatch` | Manuell bestätigten Auftrag ausführen |
| GET | `/admin/appointments/{id}/dispatches` | Ergebnisse und Empfängerhistorie mit Seitennavigation |

Der Terminservice und der Versanddienst verwenden denselben Datensatz und dieselbe Versionssperre. Vor dem Kontakt zum Push-Dienst speichert der Versanddienst den Auftrag und alle Empfänger in einer Transaktion. Die eindeutige Kombination aus Termin, Auslöser und Auftragskennung bindet Wiederholungen desselben Formulars an den gespeicherten Auftrag. Eine frisch geöffnete Vorschau liefert eine neue Kennung für einen bewussten erneuten Versand. Nach einer parallelen Terminänderung wird ein alter Auftrag nicht neu begonnen. Vor weiteren Übermittlungen prüft der Dienst erneut Termin, Teilnehmer und Gerätezuordnung.

Der Dienst verwendet `PushSettingsService`, `PushSubscriptionService` und `PushSender`. Der Nachrichtentext ist begrenzt und verweist auf den jeweiligen Termin in der App. Termin-Push verwendet öffentliche HTTPS-Endpunkte auf Port 443 mit geprüften DNS-Adressen und direkter TLS-Verbindung. Die Historie trennt Providerannahme, Fehler, unklaren Übermittlungsstatus, fehlende Geräte und fehlende Rechte. Providerannahme und Kenntnisnahme werden getrennt angezeigt. Unterbrochene oder unklare Versuche bleiben im Protokoll. Ein erneuter Versand wird bewusst aus der Vorschau gestartet.

## Schema und Betriebsprüfung

Die additive Migration `20261124120000_appointment_acknowledgement_and_dispatch.php` ergänzt `acknowledged_at_utc` und `acknowledged_version` an `appointment_participants`. `appointment_dispatches` speichert Terminversion, Auslöser, Zeitpunkte, Kanal, Nachricht und Auftragskennung. `appointment_dispatch_recipients` speichert den Empfängersnapshot, Status und Geräteergebnisse. Die Migration `20261124123000_appointment_dispatch_project_scope.php` hält den Projektbezug des versendeten Stands fest. Projektgebundene Historie setzt Zugriff auf den aktuellen Termin und den historischen Projektbezug voraus. Fremdschlüssel und eindeutige Schlüssel schützen die Beziehungen. Bestätigung und Versand schreiben ausschließlich in die Termin- und Versandtabellen sowie in die bestehende Push-Protokollierung. Zeitbuchungen und Projektmitgliedschaften werden getrennt verwaltet.

Im Entwicklungscheckout zuerst die wirksame Datenbankverbindung einschließlich `DB_OVERRIDE_FILE` prüfen, dann `vendor/bin/phinx migrate -c phinx.php` ausführen. Auf Systemen mit getrennten Konten braucht das App-Konto zusätzlich `UPDATE` auf `appointment_participants` sowie `SELECT`, `INSERT` und `UPDATE` auf beiden neuen Versandtabellen. Ein getrenntes Systembackup-Konto braucht `SELECT` auf diesen Tabellen. Allgemeine Sicherungen enthalten Termine, Teilnehmer mit Bestätigungen, Versandaufträge und Empfänger auch nach Folgemigrationen. Unterstützte ältere Archive bleiben im Dry-Run prüfbar.

Für lokale Integrationstests `TIMEAPP_TEST_DB_*` auf eine isolierte MariaDB setzen. Der Playwright-Workflow benutzt synthetische Nutzer, die isolierte Testdatenbank und einen `PushSender`-Fake. CI prüft die Versandlogik ohne externe Übermittlung. Auf dem Entwicklungsserver gehören persönliche Ansicht, negative Fremd-ID-Prüfung, Bestätigung, Vorschau und Historie zur Funktionsprüfung. End-to-End-Zustellung an reale Push-Geräte ist ein eigener Test mit ausdrücklich dafür freigegebenen Empfängern.

Zeitgesteuerte Erinnerungen, wiederkehrende Termine, SMS und externe Kalenderanbindungen sind spätere Phasen.
