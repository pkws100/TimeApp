# DATABASE.md - Zielbild fuer Schema, Migrationen und revisionssichere Zeiterfassung

## Grundsatz
- MariaDB 10.11 mit InnoDB.
- Migrationen ausschliesslich per Phinx und `AbstractMigration`.
- Die Tabelle `timesheets` wird nach dem regulären Tabellenbau per `ALTER TABLE ... ADD SYSTEM VERSIONING` revisionssicher gemacht.

## Kernentitaeten
- `permissions`, `roles`, `role_permissions`, `users`, `user_roles`
- `projects`, `project_memberships`, `project_files`, `project_material_entries`
- `project_work_reports`
- `project_dispatches`, `project_dispatch_recipients`
- `assets`, `asset_assignments`, `asset_files`
- `company_settings`
- `timesheets`, `timesheet_customer_signatures`, `absence_periods`, `absence_period_change_log`
- `employee_account_cutovers`, `time_account_entries`, `vacation_account_entries`

## Fachliche Leitlinien
- Rollen und Berechtigungen sind getrennt modelliert, damit Backend-Rechte, Projektrechte und Exportrechte sauber kombiniert werden koennen.
- Fahrzeuge und Geraete sind eigene Stammdaten, nicht Teil des Rollenmodells.
- Projekt- und Geraetedateien werden mit Metadaten in der Datenbank und physisch ausserhalb des finalen Webroots gespeichert.
- Kundenbestaetigungs-Unterschriften zu abgeschlossenen Arbeitsbuchungen werden als PNG ausserhalb von `public/` gespeichert; die Tabelle `timesheet_customer_signatures` haelt Name, Timesheet-/Projektbezug, Hash, Client-Metadaten und Archivierungsfelder.
- Projekte koennen ueber `customer_signature_required` eine Kundenunterschrift beim Abschluss nahelegen und ueber `customer_signature_name` einen Standardnamen fuer die Vorbelegung liefern.
- Projektauftraege speichern ihre Klartext-Arbeitsanweisung direkt in `projects.work_instructions`. `work_instructions_updated_at` und `work_instructions_updated_by_user_id` werden nur bei einer tatsaechlichen Inhaltsaenderung fortgeschrieben.
- `project_material_entries` dokumentiert projektbezogen Mengen, Einheiten, Datum und Notizen. Eintraege werden mit Ersteller- und Archivierungsmetadaten nachvollziehbar gehalten und nicht physisch geloescht; daraus entstehen keine Lager- oder Rechnungsbewegungen.
- `project_work_reports` haelt eigenstaendige Klartext-Arbeitsberichte mit Projekt, Arbeitsdatum, Session-Ersteller und Archivierungsmetadaten. Eine eindeutige Kombination aus Ersteller und `request_id` verhindert Doppelberichte bei Wiederholung; Projekt, Datum und normalisierter Text muessen zum urspruenglichen Speicherauftrag passen. Archivierte Berichte werden durch Wiederholung nicht reaktiviert. Zugriffe folgen der Projektberechtigung; Korrekturen erfolgen durch Archivieren und Neuanlage.
- Arbeitsbericht-Entwuerfe inklusive letzter Versand-ID liegen getrennt je Benutzer und Projekt in IndexedDB. Es gibt keinen automatischen Offline-Versand; Abmeldung und bestaetigter Projekt-Rechteentzug bereinigen die zugehoerigen lokalen Berichte und Entwuerfe.
- Jeder bewusste manuelle Auftrags-Push erzeugt einen Snapshot in `project_dispatches`. `project_dispatch_recipients` haelt pro Empfaenger den Status und die aggregierten Geraeteergebnisse; erneute Sendungen sind eigene Dispatches.
- `company_settings` haelt genau ein globales Firmenprofil fuer Reports, E-Mails, Rechtstexte und spaetere Frontend-Policies.
- Firmenlogo sowie AGB-/Datenschutz-PDFs werden nur per Dateireferenz gespeichert und physisch geschuetzt abgelegt.
- `timesheets` deckt `work`, `sick`, `vacation`, `holiday` und `absent` ab.
- `timesheets.credited_minutes` speichert ausschliesslich Zeitgutschriften fuer nicht geleistete Arbeit; `absence_reason_code` differenziert Abwesenheiten wie bezahlten Urlaub, bezahlte Krankheit, unbezahlte Abwesenheit und unentschuldigtes Fehlen.
- Verbrauchter Erholungsurlaub ist `entry_type = vacation` zusammen mit `absence_reason_code = vacation_paid`. Historische Urlaubstage mit `absence_reason_code IS NULL` gelten weiter als bezahlt; ein explizites `unpaid_leave` ist unabhaengig vom Legacy-`entry_type` eine unbezahlte Abwesenheit und mindert das Urlaubskonto nicht.
- `absence_periods` ist der revisionsfaehige Kopf fuer ganztagige Von-bis-Abwesenheiten. Der Kopf speichert den vollstaendigen Kalenderzeitraum, waehrend `timesheets.absence_period_id` nur die je positivem Solltag erzeugten Tagesbuchungen verknuepft.
- Ein Zeitraum stammt serverseitig aus `admin_calendar`, `admin_vacation` oder `vacation_request`. Direkte Admin-Urlaube bleiben von Mitarbeiterantraegen getrennt; ein genehmigter Antrag ist ueber `vacation_request_id` eindeutig genau einem Zeitraum zugeordnet.
- Die unveraenderliche Quelle steuert die Grundberechtigung: Kalenderzeitraeume verwenden Timesheet-Rechte, Urlaubsbereich und Antrag verwenden Urlaubsrechte. Ein Kalenderzeitraum vom Typ Urlaub benoetigt kumulativ auch das Urlaubsverwaltungsrecht.
- `absence_period_change_log` ist ein unveraenderliches Fach-Audit mit Aktion, Akteur, Pflichtbegruendung und Vorher-/Nachher-Snapshot. Die betroffenen Tageszeilen werden zusaetzlich im bestehenden `timesheet_change_log` protokolliert.
- Ein finaler Einfuehrungsstichtag in `employee_account_cutovers` definiert den verbindlich uebernommenen Zeitkontostand am Ende des Vortages. Zeiten vor `effective_from` veraendern den neuen kumulierten Zeitkontostand nicht mehr.
- `time_account_entries` und `vacation_account_entries` sind unveraenderliche Journale fuer Eroeffnungen, manuelle Korrekturen, Auszahlungen, Verfall und Gegenbuchungen. Fehler werden durch `reversal`-Buchungen mit `reversal_of_id` korrigiert, nicht durch Bearbeiten oder Loeschen alter Journalzeilen.
- Stichtagsfinalisierungen speichern fachliche Nullwerte weiterhin im Stichtagsdatensatz und Protokoll, erzeugen dafuer aber keine wirkungslosen Journalzeilen. Revidierungen gleichen nur offene, von null verschiedene Ursprungsbuchungen aus; bereits ausgeglichene Eintraege und Reversal-Zeilen werden nicht erneut verarbeitet.
- Journalzeilen tragen `cutover_id` und gehoeren dadurch zu genau einer Stichtagsgeneration. Aktive Zeit- und Urlaubskontoberechnungen verwenden nur Eintraege der aktiven finalen Generation; revidierte Generationen bleiben historisch erhalten, sind aber aus aktiven Salden ausgeschlossen.
- Das `leave_year` des aktiven Stichtags wird direkt aus `annual_leave_entitlement_days`, `leave_carryover_days` und `opening_remaining_leave_days` des Stichtagsdatensatzes berechnet. Der implizite Eroeffnungsausgleich ist `opening_remaining_leave_days - annual_leave_entitlement_days - leave_carryover_days`; Stichtags-Journalzeilen derselben Werte werden nicht noch einmal addiert.
- Fuer Jahre nach dem Stichtagsjahr erzeugt `VacationAccountYearService` weiterhin je Generation und Jahr eine idempotente Journaleroeffnung aus den damaligen User-Vorschlagswerten. Ein Stichtagsjahr gilt auch ohne Null-Journalzeilen als bereits eroeffnet.
- Admins koennen finale und revidierte Generationen getrennt lesen. Die Mitarbeiter-/Stichtag-Zuordnung wird serverseitig geprueft; historische Journale sind read-only, waehrend Mitarbeiter-API und aktive Salden ausschliesslich die eigene aktive Generation verwenden.
- `is_open` ist fuer Zeit-Nullzeilen und Urlaubswerte unter `0,005` immer false. Direkte Null-Gegenbuchungen bleiben zusaetzlich durch die Journalvalidierung gesperrt.
- Historische Generationen werden nur bei direkter Stichtagsquelle, ueber die Ursprungsbuchung eines Reversals oder bei genau einem zeitlich belegbaren Kandidaten automatisch zugeordnet. Mehrdeutige Zeilen behalten `cutover_id = NULL`, bleiben saldoneutral und werden ueber `bin/inspect-time-account-generations.php` gemeldet.
- `accounting_closures.source_type/source_id` kennzeichnen interne Stichtagssperren strukturell. `source_type = employee_account_cutover` sperrt Timesheet-Schreibpfade vor dem Stichtag, wird aber aus normalen Abschlusslisten und -exporten herausgefiltert.
- `users.vacation_days_year` und `users.vacation_carryover_days` bleiben als Vorschlagswerte fuer neue Stichtage bzw. Urlaubsjahre erhalten; sobald jahresbezogene Urlaubskonto-Journalbuchungen vorhanden sind, veraendern diese User-Felder historische Urlaubskonten nicht rueckwirkend. Jahreseroeffnungen werden je `user_id`, `leave_year` und `cutover_id` idempotent gebucht.
- GoBD-konforme Archivierung wird ueber `is_deleted`, `deleted_at` und `deleted_by_user_id` auf den relevanten Stammdaten umgesetzt.
- SMTP- und GEO-Vorbereitungsfelder liegen zentral im globalen Settings-Datensatz, damit Backend und spaeteres Frontend dieselbe Quelle nutzen.
- Backup und Restore entdecken Anwendungstabellen dynamisch ueber `information_schema`. Die Projektmaterial- und Dispatch-Tabellen werden dadurch ohne separate Whitelist vollstaendig in neue Backups aufgenommen.

## Zeiterfassungslogik
- Arbeitsbloecke speichern Brutto-, Pausen- und Netto-Minuten.
- Das Legacy-Verfahren speichert rechnerische Mindestabzuege. Neue Buchungen mit aktivem Pausenmodell speichern dokumentierte Pausen und die daraus ermittelte Arbeitszeit. Die gesetzliche Mindestpause sowie fehlende Minuten sind eigene berechnete Felder.
- Spesen werden ueber `expenses_amount` erfasst, damit PDF- und Exportberichte dieselbe Datenbasis verwenden.
- Tatsaechliche Arbeitszeit (`net_minutes` bei `work`) und Zeitgutschrift (`credited_minutes` bei bezahlten Abwesenheiten) sind getrennte Groessen.
- Zeitkontostand = Eroeffnungssaldo + Arbeitszeit ab Stichtag + bezahlte Abwesenheitsgutschriften - effektives Soll ab Stichtag + Journal-Korrekturen.
- Der hervorgehobene Mitarbeiterstand in `/app` verwendet als fachliches Standdatum ausschliesslich den letzten vollstaendig abgeschlossenen Kalendertag in `Europe/Berlin`. Dadurch wird das Soll des laufenden Tages nicht vor Arbeitsbeginn als Fehlzeit dargestellt; am ersten Monatstag bleibt der Abschlussstand des Vormonats sichtbar und die neue Monatsveraenderung beginnt bei null. Diese Darstellungsregel veraendert die Standdaten von Admin, Exporten und Abrechnung nicht.
- Die Monatsveraenderung ist die Veraenderung innerhalb des betrachteten Monats; der Gesamtstand ist der kumulierte Saldo seit Stichtag.
- Der aktuelle Monat rechnet fuer den aktuellen Kontostand nur bis zum Standdatum, standardmaessig heute. Zukuenftige Arbeitstage erzeugen keine aktuellen Minusstunden.
- Feiertage und bezahlte Betriebsschliessungen reduzieren das Soll. Sie erzeugen keine zusaetzliche automatische Zeitgutschrift, damit keine Doppelwertung entsteht.
- Ohne finalisierten Stichtag bleiben Monatsauswertungen verfuegbar, aber es wird kein kumulierter Zeitkontostand erfunden.
- Die Mitarbeiter-API kennzeichnet einen fehlenden oder noch nicht wirksamen Stichtag semantisch und liefert dafuer keine Saldozahl. Vorlaeufige Urlaubswerte duerfen weiterhin aus User-Vorschlagswerten und vorhandenen Buchungen gebildet werden, muessen in der PWA aber als vorlaeufig erkennbar sein.
- Monate vollstaendig vor dem aktiven Stichtag liefern `cutover_status = not_active_in_period` und zeigen keinen kuenstlichen Eroeffnungs- oder Endbestand.
- Manuelle ganztagige Abwesenheiten sind nur an Tagen mit positivem effektivem Tages-Soll erlaubt. Wochenenden, Feiertage und Betriebsschliessungen erzeugen keinen zusaetzlichen Abwesenheitsgutschrift-Bedarf.
- Von-bis-Abwesenheiten behalten Wochenenden, Feiertage, Betriebsschliessungen und Null-Soll-Tage im Kopfzeitraum, ueberspringen sie aber transparent bei der Erzeugung von Tagesbuchungen.
- Zeitraumkorrekturen bilden atomar die Set-Differenz alter und neuer Buchungstage. Gleiche Daten behalten ihre Timesheet-ID und Anhaenge, entfernte Daten werden archiviert und neue Daten eingefuegt; eine vorhandene Zeile wird nie auf ein anderes Datum verschoben.
- Einzelne verknuepfte Tageszeilen sowie Legacy-Zeilen mit `vacation_request_id` sind gegen Bearbeitung, Bulk-Aenderung, Archivierung und Wiederherstellung geschuetzt.
- Mehrere Arbeitsbuchungen am selben Tag sind zulaessig. Arbeit plus ganztagige Abwesenheit sowie doppelte ganztagige Abwesenheiten werden zentral serverseitig blockiert.
- Dieselben Konflikt- und Tages-Soll-Pruefungen gelten beim Wiederherstellen archivierter Buchungen erneut gegen den aktuellen Kalender- und Buchungsstand.
- Betriebsschliessungen werden fuer Tagesberechnungen ueber `date_from/date_to` nach Kalenderjahresueberlappung geladen; das Hilfsfeld `year` dient nur Listen und Gruppierung.

## Uebergangsschutz
- Arbeitszeitmodell-Aenderungen bei aktiven Zeitkonten mit Bewegungen werden blockiert, weil historische Arbeitszeitmodellversionen noch nicht voll modelliert sind.
- Feiertagsregionen und rueckwirkende Betriebsschliessungen werden blockiert, wenn aktive Zeitkonten betroffen waeren.
- Die feste Sperrreihenfolge fuer Stichtagsfinalisierung und Revidierung lautet: Mitarbeiter-Stichtagslock, globaler `accounting-timesheet-write`-Lock, erneute Vorschau/Pruefung, DB-Transaktion, Freigabe in umgekehrter Reihenfolge.
- Die feste Sperrreihenfolge fuer Anlage, Korrektur und Archivierung eines Abwesenheitszeitraums lautet: `employee-absence-user-{id}`, globaler `accounting-timesheet-write`-Lock, erneute Vorschau und Pruefung aller alten und neuen Tage, DB-Transaktion, Freigabe in umgekehrter Reihenfolge. Konflikte, Abrechnungssperren, Stichtage und veraltete `lock_version` brechen den gesamten Vorgang ab.
- Anlage und Korrektur vergleichen ausserdem ein Vorschau-Token mit der unter den Schreiblocks neu berechneten Vorschau. Aendern sich Buchungstage, Warnungen, Kalenderpolicy oder Urlaubsauswirkung zwischen Vorschau und Speichern, muss der Admin den neuen Stand erneut pruefen.

## Optionale Arbeitszeitfenster

- Additive Tabellen: `work_time_policy_templates`, `work_time_policy_versions`, `employee_work_time_assignments`, `employee_work_time_supervisors`, `work_time_policy_audit`, `work_time_deviations`, `work_time_deviation_decisions`, `work_time_checkout_proposals`, `work_time_checkout_proposal_actions`, `work_time_presence_sessions`, `work_time_notification_events`.
- Standardmaessig global aus; pro Mitarbeiter nur mit kuenftig gueltiger Zuordnung. Historische FKs verwenden `RESTRICT`.
- Fensterwerte sind separate Vorschauen. `net_minutes`, Gutschriften, vorhandene Stichtage und Journale bleiben erhalten. Ungeklaerte Anteile markieren betroffene Salden als vorlaeufig und sichern neue Abschluesse ab.
- Anwesenheit und unbestaetigte Vorschlaege erfinden keine Arbeitszeit. Bestaetigung und Abschluss laufen atomar unter dem zentralen Schreiblock. Details: [WORK_TIME_WINDOWS.md](WORK_TIME_WINDOWS.md).
- Hardening ergaenzt `work_time_reconciliation_queue` und `work_time_reconciliation_runs`: Trigger erfassen jede relevante Quellenrevision, der Worker verarbeitet begrenzte Batches und behaelt Berechnungslauf und Quellhash nachvollziehbar. Die Warteschlange hat bewusst keinen kaskadierenden Quellen-FK, damit auch geloeschte Scratch-/Legacy-Quellen als Tombstone abgearbeitet werden koennen. `timesheets` erhaelt weder neue Spalten noch neue Indizes.
- Berechnungen werden ausserhalb des Accounting-Locks vorbereitet; unter Lock und Transaktion werden Queue-Revision, frische Quellen und Periodensperren erneut geprueft. Mitarbeiter-Zuordnungen verwenden Mitarbeiter-Zuordnungslock, Accounting-Lock, Transaktion in dieser Reihenfolge. Netzwerkversand haelt keinen Accounting-Lock.
- `work_time_notification_attempts`, `work_time_notification_retry_requests` und `work_time_notification_history` ergaenzen Versandereignisse um Providerannahme, sichere Fehlerklassen und begruendete Wiederholungsanfragen. Unklare/teilweise angenommene Sendungen werden nicht wiederholt. Bestehende Versandzustaende bleiben als Legacy-Snapshot erhalten; alte Fehler werden vorsichtig als unklar eingeordnet.
- `work_time_pause_corrections` dokumentiert nachgewiesene Intervallnachtraege mit Vorher-/Nachher-Snapshot, Akteur, Begruendung, Quellenbindung und Request-ID. Nettozeit und versionierte Buchungszeile bleiben unveraendert; bestehende Signaturen und Periodensperren blockieren Nachtraege.
- Aeltere Buchungswege pruefen einen vorhandenen Pausenauditmarker bzw. `timesheet_breaks.source = admin_evidence` vor Writes innerhalb Accounting-Lock und Transaktion. Geschuetzte Stempel-/Dauer-/Herkunftsdaten und Intervalle sind dort nicht veraenderbar; der Schutz gilt auch bei deaktiviertem WorkTime. Metadaten und logisch archivierte Nachweise behalten ihre Historie.
- Die Migration `20261118131000_work_time_assignment_history.php` erhaelt ersetzte Zuordnungsintervalle, referenziert fortgefuehrte Regelabschnitte und bindet die Freigabe neuer Live-Ereignisse an den Erfassungszeitpunkt. Das zeitlich gebundene Vorschautoken wird unter dem Mitarbeiter- und Accounting-Lock erneut geprueft.
- `20261118170000_work_time_adjacent_windows_queue.php` ergaenzt Revisions-Trigger fuer Arbeitsbuchungen an den Grenzen einer Fensterzuordnung. Der einmalige Abgleich meldet betroffene Buchungen an die vorhandene Warteschlange. Die Vorschau bindet relevante Vortags- und Folgetagsfenster an ihren Quellenstand.

## Pausenmodelle und Nachweise

- `20261118150000_pause_policy_models.php` legt Vorlagen, unveraenderliche Versionen, zeitliche Standard- und Mitarbeiterzuordnungen sowie deren Audit an. Die Migration aktiviert keine Regel.
- `20261118160000_timesheet_pause_records.php` fuehrt `timesheet_pause_policy_snapshots` und `timesheet_pause_durations` ein. Der Snapshot bindet die effektive Regelversion an eine neu erstellte Buchung. Datierte Pausen bleiben in `timesheet_breaks`. Bestaetigte Dauern ohne Uhrzeiten werden separat gespeichert. Eine spaeter nachgewiesene Lage markiert die Dauer als zugeordnet und protokolliert das Intervall im vorhandenen Korrekturaudit.
- `20261118180000_timesheet_break_instants.php` ergaenzt in `timesheet_breaks` optionale Unix-Sekunden fuer eindeutig uebermittelte Stempel in der doppelten Berliner Herbststunde. Die bisherigen lokalen Werte bleiben erhalten, vorhandene Zeilen werden nicht rueckwirkend gedeutet. Die beiden Zeitpunkte bleiben fuer Dauerberechnung, Formularvalidierung und Offline-Replay unterscheidbar.
- `20261118190000_accounting_pause_assessments.php` ergaenzt in `accounting_closure_items` einen optionalen Snapshot der Tages-Pausenpruefung. Neue Abschluesse nehmen ihn in den Buchungs-Hash auf. Historische Abschluesse und ihre Hashes behalten den gespeicherten Stand.
- Die neuen Tabellen veraendern weder das Schema von `timesheets` noch vorhandene Buchungen, Journale oder Abschluesse. In neuen Buchungen mit Pausenmodell sind `net_minutes` die aus den tatsaechlichen Nachweisen ermittelten Arbeitsminuten. Die Pflichtpause und Fehlmenge entstehen in der Leseauswertung.

## Material-Erfassungskontext und Anfrage-IDs

- Die additive Migration `20261111220000_project_material_request_ids.php` ergaenzt nullable `client_request_id` und `request_payload_hash` an `project_material_entries`; ein Unique-Index je Autor/Anfrage serialisiert parallele Wiederholungen.
- Altclients ohne ID bleiben kompatibel. Replays pruefen zuerst die aktuelle Projektberechtigung, aendern keine gespeicherten Daten und legen archivierte Buchungen nicht erneut an. Geaenderte normalisierte Angaben unter derselben ID ergeben einen Konflikt statt einer zweiten Buchung.
- Die additive Migration `20261118120000_project_material_capture_context.php` speichert `capture_type`, `captured_work_project_id` und `captured_timesheet_id` am Materialeintrag. `project_id` bleibt das Zielprojekt, `work_date` das Materialdatum. `created_at` und `created_by_user_id` erfassen Zeitpunkt und Mitarbeiter.
- Der Server bestimmt beim ersten erfolgreichen Speichern den laufenden Zeiteintrag des Mitarbeiters. Massgeblich ist die offene Tagesbuchung oder der nach Kernarbeitszeitregeln gueltige Vortagseinsatz. `capture_type` unterscheidet `standard`, `cross_project`, `backdated` und `cross_project_backdated`. Fuer `backdated` liegt das Materialdatum vor dem lokalen Erfassungstag in `Europe/Berlin`. Ist ein anderes Projekt im laufenden Zeiteintrag hinterlegt, gilt `cross_project`. Die zwei Merkmale koennen zusammen auftreten.
- Der Server verwendet fuer Snapshot und Materialanlage denselben Schreiblock wie Zeitbuchungsaenderungen. Der Snapshot bleibt bei Replays und spaeteren Zeitbuchungsaenderungen erhalten. Alteintraege haben einen leeren Kontext. Zeitbuchungsreferenzen sind intern. Projektinformationen zum damaligen Einsatz werden bei der Anzeige anhand der aktuellen Zugriffsrechte gefiltert.

## Elektronische Personal- und Entgeltunterlagen

- Die additiven Compliance-2027-Migrationen fuehren `personnel_compliance_sources`, `personnel_document_types`, `personnel_document_requirements`, `employee_employment_contexts`, `employee_documents`, `employee_document_versions`, `employee_document_audit` und `employee_document_access_log` ein.
- Dokumente referenzieren `users.id` und optional eine konkrete Kontext- und Regelrevision. Historische Referenzen verwenden `RESTRICT`. Benutzerarchivierung erhaelt die Beziehungen. Eine neue Dokumentversion verweist auf ihren direkten Vorgaenger.
- `20261118202500_personnel_compliance_rollback_boundary` setzt die Phinx-Ruecklaufgrenze. `20261118203000_harden_personnel_compliance_history` ergaenzt die DELETE-Sperre fuer Historie und Dokumentkoepfe. Mitarbeiter, Typ, Kontext, Regel und Erstellungsprovenienz eines Dokumentkopfs bleiben fest. Archiv- und Prueffelder bleiben aenderbar. Fachlich freigegebene Aussonderung erfordert einen gesonderten Wartungsprozess.
- Der gesetzliche Basiskatalog wird in Phinx versioniert und ohne Mitarbeiterdaten eingespielt. Die Anwendbarkeit bleibt `manual_review`. Unbekannte Beschaeftigungsmerkmale werden nicht aus Benutzerstatus, Berechtigungen oder Projektzuordnung abgeleitet.
- Phase 1 enthaelt keine Dateioperationen und keine automatische Frist-, Anwendbarkeits- oder Loeschentscheidung. Das Systembackup enthaelt den Katalog und leere Eintraege fuer private Tabellen. Manifestformat 2 weist die Sicherungsabdeckung und belegte Ausschluesse aus. Der Dry-Run akzeptiert private Tabellen nur ohne personenbezogene Zeilen.
- Quellen, Historienregeln, Rechte und Backup-Grenzen sind im [Datenmodell](docs/COMPLIANCE_2027_DATA_MODEL.md) beschrieben.

## Seeder-Startpunkt
- Standard-Seeds liefern Rollen, Rechte und notwendige Referenzdaten.
- Der erste Administrator wird anschliessend per CLI angelegt:
  `php bin/bootstrap-admin.php --email=... --password=... --first-name=... --last-name=...`
- Beispielbenutzer, Demo-Projekte und Demo-Assets liegen in einem separaten optionalen Demo-Seeder.

## MariaDB-Integrationstests
- Zeitkonto-, Lock-, Migrations-, Foreign-Key- und System-Versioning-Tests verwenden pro Testklasse eine zufaellige Scratch-Datenbank auf MariaDB.
- Die Testbasis migriert das Schema mit Phinx, leert Testdaten zwischen Methoden und entfernt die Datenbank danach. Append-only-Tabellen werden nur in der verifizierten, vom Test selbst erzeugten Scratch-Datenbank mittels `TRUNCATE` zurueckgesetzt. Historische Migrationstests starten bei ihrem jeweiligen Zielstand. Produktivdatenbanken werden nicht als Testziel verwendet.
- Lokale Testzugriffe koennen mit `TIMEAPP_TEST_DB_*` konfiguriert werden. Ein isolierter App-Prozess kann `DB_OVERRIDE_FILE` auf einen separaten oder nicht vorhandenen Override-Pfad setzen.
- `bin/inspect-absence-periods.php` prueft historische Antrags-, Zeitraum- und Tagesverknuepfungen read-only und meldet Inkonsistenzen, ohne freie Einzelbuchungen heuristisch zu veraendern.
- Der reale Playwright-Zeitkonto-Runner erstellt ausschliesslich zufaellige Datenbanken mit dem Praefix `timeapp_ui_` und verwendet synthetische Benutzer. Datenbank und Verbindungsdateien werden auch nach Fehlern entfernt. Browser- und Serverdiagnosen bleiben dort bei Fehlern erhalten und werden in CI kurzzeitig als Artefakte bereitgestellt. Jahresuebergreifende Betriebsschliessung und Testverlaengerung werden ausgehend vom Testdatum erzeugt.
