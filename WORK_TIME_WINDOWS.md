# Optionale Kernarbeitszeit und Arbeitszeitfenster

## Betriebszustand und fachliche Einordnung

Standard: `WORK_TIME_WINDOWS_ENABLED=false`. Zusaetzlich braucht jeder Mitarbeiter
eine ausdrueckliche, zeitlich gueltige Regelzuordnung. Ohne Zuordnung gelten
bisherige Buchungs- und Kontoberechnungen. Neue Regeln gelten fruehestens morgen
in `Europe/Berlin`.

08:00–16:00 beschreibt eine zeitliche Lage, keine acht Nettoarbeitsstunden.
`work_started` dokumentiert mit Check-in den Arbeitsbeginn. `presence_only`
dokumentiert einen fruehen Check-in separat als Anwesenheit. Der Mitarbeiter
bestaetigt den wirklichen Arbeitsbeginn, auch wenn er schon vor dem Fenster
arbeitet. Anwesenheit wird nie automatisch zum Fensterbeginn in Arbeit umgewandelt.
Arbeitsbereitschaft darf nicht allein wegen der Uhrzeit als reine Anwesenheit gelten.

Die abweichende Anrechnung bleibt **Vorschau**. `net_minutes`, Abwesenheits-
gutschriften, vorhandene Stichtage und Journale werden nicht umgeschrieben.
Ungeklaerte Aussenzeiten/Pausenlagen markieren betroffene Salden als vorlaeufig.
Neue verbindliche Abschluesse und Stichtage werden bei betroffenen ungeklaerten
Anteilen abgefangen. Verbindlich andere Abrechnungsregeln brauchen eine gesonderte
fachliche Freigabe.

Die Trennung orientiert sich an der Aufzeichnung tatsaechlicher Arbeit:
[§ 2 ArbZG](https://www.gesetze-im-internet.de/arbzg/__2.html),
[BMAS-FAQ](https://www.bmas.de/DE/Arbeit/Arbeitsrecht/Arbeitnehmerrechte/Regelungen-zur-Arbeitszeit/Fragen-und-Antworten/faq-arbeitszeiterfassung.html).
Ein abgelehnter Softwarestatus loescht keine Arbeit und entscheidet nicht
abschliessend ueber arbeitsrechtliche oder Verguetungsansprueche.

## Bestandsaufnahme der Berechnungspfade

| Pfad | Verantwortung |
| --- | --- |
| `AppTimesheetSyncService` | Gemeinsamer Schreibweg fuer PWA, Offline, NFC und Bestaetigungen; bestehender Accounting-Lock und Transaktion. |
| `TimesheetCalculator` | Unveraenderte Dauerabzuege; rechnerische Mindestpause belegt kein Pausenintervall. |
| `WorkdayStateCalculator` / `MobileAppService` | Bestehender Tagesstatus und Live-Timer; Anwesenheit und Vorschlaege erscheinen daneben. |
| `WorkTimeWindowCalculator` | Reine Intervallschnittmengen aus Arbeit, dokumentierten Pausen, Wochenfenstern und Entscheidungen. |
| `WorkTimePreviewService` | Zentrale Quelle fuer PWA, Admin, Zeitkonten, Exporte und Abschlusspruefungen; Lesen erzeugt keine Ereignisse. |
| `TimeAccountService` | Bestehende Salden plus Vorschau; Mitarbeiterstand weiterhin nur bis Ende des Vortages. |
| `AccountingClosureService` / `EmployeeAccountCutoverService` | Pruefen neue Abschluesse unter bestehenden Locks; alte Abschluesse bleiben erhalten. |
| `TerminalPunchService` | Zentraler Schreibweg; trennt Anwesenheit von echter Stempelung und beruecksichtigt offene Nachteinsaetze. |

## Admin, Rechte und Historie

`/admin/work-time`: Vorlagen, unveraenderliche Revisionen, Wochenfenster,
individuelle Regeln und Mitarbeiterzuordnungen. Vor dem Speichern muss eine
Beispielbuchung geprueft werden; ein serverseitiges Token bindet dieselben
Regeleingaben. Endvorschlaege sowie Mitarbeiter-/Vorgesetztenkanaele sind getrennt
konfigurierbar. Eine spaetere Zuordnung beendet eine vorherige offene am Vortag;
widerspruechliche Zeitraeume werden abgewiesen. Ein begruendetes Ende fuer die
Zukunft ermoeglicht die mitarbeiterbezogene Deaktivierung.

Rechte: `work_time_policies.view`, `work_time_policies.manage`,
`work_time_deviations.view`, `work_time_deviations.manage`,
`work_time_notifications.manage`. Nur Administratoren erhalten sie bei Migration
automatisch. Vorgesetzte brauchen neben dem Rollenrecht konkrete Mitarbeiter-
zuordnungen. Ein Recht allein ist keine globale Mitarbeiterzustaendigkeit.
Mitarbeiter sehen/bestaetigen nur eigene Daten.

07:42–16:35, dokumentierte Pause 12:00–12:30, Fenster 08:00–16:00: 503 Minuten
Arbeit, 450 im Fenster, 18 davor, 35 danach. Die 53 Aussenminuten bleiben offen.
Status: `pending`, `approved`, `rejected`, `disputed`; Entscheidungen brauchen
Akteur, Zeitpunkt, Begruendung und Request-ID. Genehmigte Intervalle erhoehen
ausschliesslich die separate Vorschau.

Reine Pausendauer/rechnerischer Zusatzabzug ohne bekannte Lage fuehrt zu
`needs_clarification` und `null` bei betroffenen Fensterwerten. Es wird keine
Pause willkuerlich verteilt. Laufende Einsaetze haben noch keine abschliessende
Anrechnung; ihre Quelle bleibt der vorhandene Timer. Nachtfenster und Tageswechsel
werden geschnitten. Mehrdeutige/nicht existierende Ortszeiten bei Zeitumstellung
werden zur Klaerung abgewiesen, weil bestehende Timesheet-Uhrzeiten keinen
UTC-Offset halten. Kalender-/Sollservices bleiben fuer Feiertage/Solltage zustaendig.

Der Worker materialisiert Abweichungen. Geaenderte Quellen entwerten vorherige
Entscheidungen fuer die aktuelle Vorschau; historische Nachweise bleiben erhalten.
Historische FKs verwenden `RESTRICT`; `timesheets` wird nicht strukturell veraendert.
Basismigration: `20261111120000_work_time_windows.php`. Die drei additiven
Hardening-Migrationen enden auf `reconciliation_queue`, `notification_delivery`
und `pause_corrections`; Einzelheiten und Pruefnachweise stehen in
[WORK_TIME_HARDENING_RC.md](WORK_TIME_HARDENING_RC.md).

Im Admin zeigt die Mitarbeiteransicht betroffene Buchungen mit gespeicherter
Pausendauer, vorhandenen Intervallen und fehlenden Minuten. Mit
`timesheets.manage` und `work_time_deviations.manage` koennen zustaendige Personen
nachweislich genommene Intervalle ergaenzen: ausdrueckliche Bestaetigung,
Pflichtbegruendung, Request-ID und Quelltoken sind erforderlich. Der Nachtrag
ergaenzt nur die bekannte Lage der gespeicherten Dauer; er aendert weder die
urspruenglichen Stempelzeiten noch `net_minutes`. Vorhandene Kundenunterschriften,
Archivierung, Buchhaltungsabschluesse und Stichtagssperren blockieren den Nachtrag.
Vorher-/Nachher-Snapshots und bisherige Berechnungen bleiben erhalten.

Belegte Pausen sind zusaetzlich gegen alte manuelle App-/Admin-Schreibwege
geschuetzt: Auditmarker und `admin_evidence` verhindern dort Aenderungen an
Intervallen, Stempeln und Dauer, auch nach Abschalten des Features. Normale
unbelegte Pausen bleiben nutzbar. Die Benutzerbearbeitung zeigt Sollstundenmodell
und individuell gueltige Arbeitszeitfenster getrennt mit berechtigtem Direktlink.
Details und neue Pruefnachweise: [WORK_TIME_PAUSE_FORM_STABILITY.md](WORK_TIME_PAUSE_FORM_STABILITY.md).

## Anwesenheit, Endvorschlaege und Offline

`work_time_presence_sessions` dokumentiert Anwesenheit ohne Arbeitsminuten.
Ein ausdruecklicher Arbeitsbeginn kann auch nach Mitternacht erfolgen. Ein
Endvorschlag erzeugt nur `work_time_checkout_proposals`, keine echte Endzeit.
Erst Bestaetigung/Korrektur schliesst genau den betroffenen Einsatz atomar mit
der Aktionshistorie ueber den zentralen Sync-Service ab. „Ich arbeite weiter“
laesst die Buchung offen. Nach einem bestaetigten Abschluss ist ein neuer
Check-in am selben Tag moeglich.

Ein vom Vortag laufender Einsatz bleibt fuer individuell zugeordnete Mitarbeiter
auch ohne Endvorschlag in der PWA sichtbar und kann mit seiner urspruenglichen
Buchungs-ID ausdruecklich beendet werden. Offene Pausen werden separat bestaetigt.
Mehrdeutige oder ueberlange Einsaetze gehen zur Admin-Klaerung; Enddatum und
darstellbare Grenze von maximal 24 Stunden werden serverseitig geprueft.

PWA-Bestaetigungen und Anwesenheitsentscheidungen benoetigen eine Verbindung.
Zuerst werden wartende Offline-Buchungen synchronisiert; nach Wiederverbindung
werden Vorschlaege neu geladen. Normale Arbeitsbuchungen behalten den Offline-
Puffer. Request-IDs, eindeutige Schluessel und Locks sichern Doppelanfragen ab.

## Worker und Kanaele

```bash
php bin/process-worktime-events.php --dry-run
php bin/process-worktime-events.php --batch-size=100
# Nur fuer einen ausdruecklichen Wartungsabgleich:
php bin/process-worktime-events.php --full-reconcile --user-id=123 --batch-size=100
```

Dry-Run schreibt/versendet nichts. Der normale Minutenlauf verarbeitet eine
persistente Revisionswarteschlange, standardmaessig hoechstens 100 Buchungen
(konfigurierbar bis 1.000). Datenbanktrigger erfassen Buchungs-, Pausen-,
Zuordnungs- und Entscheidungswechsel. Ein unveraenderter historischer Bestand
wird nicht erneut berechnet. Ein abgebrochener Worker wird unter dem
datenbankspezifischen Advisory-Lock wiederaufgenommen. Wiederholte Aenderungen
waehrend einer Berechnung entwerten deren Revision und stellen sie erneut an.
Aufwendige Vorbereitung erfolgt vor dem kurzen Accounting-Schreiblock;
Quellstand und Sperren werden vor dem Commit erneut geprueft. Zuordnungen nehmen
den Mitarbeiter-Zuordnungslock vor dem Accounting-Lock und der DB-Transaktion.
`--full-reconcile` stellt die komplette relevante Historie ausdruecklich erneut
an; `--user-id` ist ausschliesslich fuer diesen Wartungsmodus erlaubt.
Docker verwendet einen
separaten `timeapp-worktime-scheduler` im Minutenintervall. Das bestehende
Push-Reminder-Intervall bleibt erhalten. Nativ nach Betriebsfreigabe:

```cron
* * * * * cd /var/www/html && /usr/bin/php bin/process-worktime-events.php >> storage/logs/worktime-events.log 2>&1
```

`work_time_outside_window_started` ist vom Endvorschlag unabhaengig. Bei aktivierter
Option entsteht es fuer einen weiterhin offenen Einsatz ab Fensterende bzw. an
einem Tag ohne Fenster. Die neue Option ist standardmaessig aus; die Verzoegerung
ab tatsaechlichem Arbeitsbeginn betraegt standardmaessig fuenf Minuten. Eigene
und Vorgesetztenkanaele bleiben getrennt schaltbar. Nachtfenster werden ueber
Mitternacht beruecksichtigt. Das Ereignis erzeugt keine Endzeit und schliesst
keine Buchung.

Je Ereignis/Empfaenger/Kanal ein Versanddatensatz. Zustaende: `waiting`, `sending`,
`provider_accepted`, `failed` (nachweislich nicht angenommen), `not_configured`, `uncertain` sowie
`manual_retry_requested`, `cancelled` und `test_simulated`. Providerannahme ist kein Nachweis
der Zustellung auf dem Geraet. Nur ausdruecklich als nicht angenommen und
wiederholbar klassifizierte Fehler erlauben bis zu drei automatische Versuche
mit Backoff; insgesamt sind maximal fuenf Versuche moeglich. Unklare Ergebnisse,
Teilannahmen und beim Prozessabbruch verbliebenes `sending` werden `uncertain`
und nicht automatisch oder manuell erneut versendet. Netzwerkzugriffe finden
ausserhalb des Accounting-Locks statt.

`/admin/work-time/notifications` bietet Ereignis, Mitarbeiter, Empfaenger, Kanal,
Zeitpunkt, Versuche, sichere Fehlertexte und Historie. Berechtigte, zustaendige Personen
koennen bei eindeutig fehlgeschlagenem oder nicht konfiguriertem Versand eine
erneute Pruefung/Sendung mit Begruendung anfordern. CSRF, Request-ID und Audit
sichern diese Aktion; angenommene und unklare Sendungen sind gesperrt.

Web-Push bleibt eigenstaendig. SMS verwendet `SmsProvider` und standardmaessig
einen deaktivierten `MockSmsProvider`: keine echten SMS, keine Provider-Secrets.
Ein spaeterer Adapter braucht separate Freigabe und Secret-Schutz entsprechend
SMTP. Telefonnummern werden vor Testversand nach E.164 normalisiert/validiert.
Die Schnittstelle `SmsProvider::send()` nimmt Telefonnummer, Nachricht und einen
stabilen Idempotenzschluessel entgegen. Sie liefert neben `ok` gegebenenfalls
`definitive_not_accepted`, `retryable` und `provider_message_id`. Netzwerkfehler ohne sichere
Nichtannahme muessen unklar bleiben. Simulationen kennzeichnet
`simulated=true`; sie gelten ausschliesslich als `test_simulated`.

## Tests und Einfuehrung

Gezielte Tests: `tests/Unit/WorkTime*`, `tests/Integration/WorkTime*`,
`tests/e2e/work-time.spec.js`. Datenbanktests verwenden eigene Scratch-Datenbanken.
Der reale Browserrunner erstellt einen isolierten Entwicklungsserver.

Kein automatischer Merge, Produktionsdeployment oder Kundenschalter. Auf dem
isolierten Entwicklungsserver separat migrieren, Feature-off mit synthetischen
Benutzern testen, dann nur einen Testmitarbeiter kuenftig zuordnen. Anwesenheit,
Ende, Weiterarbeit, NFC, Offline und Dry-Run pruefen. Verbindliche Anrechnung und
betriebliche Genehmigungszustaendigkeiten bleiben gesondert freizugeben.

## Technischer Pruefstand der Basisimplementierung

- PHPUnit: 398 Unit-Tests / 1.689 Assertions erfolgreich. MariaDB: 47 Fenster-/HTTP-/Migrations-/Accounting-Tests / 225 Assertions; nach den Nacht-Ergaenzungen 31 Workflow-/HTTP-Regressionsfaelle / 131 Assertions sowie zwei gezielte Pausen-DST-Datenbanktests erfolgreich. Alle verwenden Scratch-Datenbanken.
- Playwright: 100 Browser-Smokes und fuenf zusaetzliche Nacht-/Pausen-/Konfliktpruefungen erfolgreich. Sechs reale Browserablaeufe erfolgreich auf einem automatisch erzeugten isolierten PHP-Server und eigener Scratch-Datenbank, einschliesslich bestehender Konten-/Abwesenheitsablaeufe.
- UI-, Workflow- und Code-Review unabhaengig durchgefuehrt; Findings zu Nachtarbeit, Sitzungsbindung, Quellrevisionen, Berechtigungen und veralteten Antworten behoben.
- Dockerfile und beide Compose-Dateien geprueft; YAML validiert und bestehendes Push-Intervall erhalten. Ein Docker-Build konnte in dieser Umgebung nicht ausgefuehrt werden, da Docker nicht installiert ist.
- Merge/Fast-Forward und Push nach `main` erfolgen nur auf ausdruecklichen Folgeauftrag. Produktionsmigration, Produktionsdeployment und Kundenaktivierung benoetigen weiterhin eine separate Freigabe.

Der aktuelle Hardening-Pruefstand mit Benchmark, Migrationen und GitHub-CI ist in
[WORK_TIME_HARDENING_RC.md](WORK_TIME_HARDENING_RC.md) dokumentiert. Der
Hardening-Auftrag erlaubt ausschliesslich den Feature-Branch und keinen Merge.
