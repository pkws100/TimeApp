# DATABASE.md - Zielbild fuer Schema, Migrationen und revisionssichere Zeiterfassung

## Grundsatz
- MariaDB 10.11 mit InnoDB.
- Migrationen ausschliesslich per Phinx und `AbstractMigration`.
- Die Tabelle `timesheets` wird nach dem regulären Tabellenbau per `ALTER TABLE ... ADD SYSTEM VERSIONING` revisionssicher gemacht.

## Kernentitaeten
- `permissions`, `roles`, `role_permissions`, `users`, `user_roles`
- `projects`, `project_memberships`, `project_files`
- `assets`, `asset_assignments`, `asset_files`
- `company_settings`
- `timesheets`, `timesheet_customer_signatures`
- `employee_account_cutovers`, `time_account_entries`, `vacation_account_entries`

## Fachliche Leitlinien
- Rollen und Berechtigungen sind getrennt modelliert, damit Backend-Rechte, Projektrechte und Exportrechte sauber kombiniert werden koennen.
- Fahrzeuge und Geraete sind eigene Stammdaten, nicht Teil des Rollenmodells.
- Projekt- und Geraetedateien werden mit Metadaten in der Datenbank und physisch ausserhalb des finalen Webroots gespeichert.
- Kundenbestaetigungs-Unterschriften zu abgeschlossenen Arbeitsbuchungen werden als PNG ausserhalb von `public/` gespeichert; die Tabelle `timesheet_customer_signatures` haelt Name, Timesheet-/Projektbezug, Hash, Client-Metadaten und Archivierungsfelder.
- Projekte koennen ueber `customer_signature_required` eine Kundenunterschrift beim Abschluss nahelegen und ueber `customer_signature_name` einen Standardnamen fuer die Vorbelegung liefern.
- `company_settings` haelt genau ein globales Firmenprofil fuer Reports, E-Mails, Rechtstexte und spaetere Frontend-Policies.
- Firmenlogo sowie AGB-/Datenschutz-PDFs werden nur per Dateireferenz gespeichert und physisch geschuetzt abgelegt.
- `timesheets` deckt `work`, `sick`, `vacation`, `holiday` und `absent` ab.
- `timesheets.credited_minutes` speichert ausschliesslich Zeitgutschriften fuer nicht geleistete Arbeit; `absence_reason_code` differenziert Abwesenheiten wie bezahlten Urlaub, bezahlte Krankheit, unbezahlte Abwesenheit und unentschuldigtes Fehlen.
- Verbrauchter Erholungsurlaub ist `entry_type = vacation` zusammen mit `absence_reason_code = vacation_paid`. Historische Urlaubstage mit `absence_reason_code IS NULL` gelten weiter als bezahlt; ein explizites `unpaid_leave` ist unabhaengig vom Legacy-`entry_type` eine unbezahlte Abwesenheit und mindert das Urlaubskonto nicht.
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

## Zeiterfassungslogik
- Arbeitsbloecke speichern Brutto-, Pausen- und Netto-Minuten.
- Gesetzliche Pausen werden serverseitig automatisch auf mindestens 30 Minuten bei mehr als 6 Stunden und 45 Minuten bei mehr als 9 Stunden angehoben.
- Spesen werden ueber `expenses_amount` erfasst, damit PDF- und Exportberichte dieselbe Datenbasis verwenden.
- Tatsaechliche Arbeitszeit (`net_minutes` bei `work`) und Zeitgutschrift (`credited_minutes` bei bezahlten Abwesenheiten) sind getrennte Groessen.
- Zeitkontostand = Eroeffnungssaldo + Arbeitszeit ab Stichtag + bezahlte Abwesenheitsgutschriften - effektives Soll ab Stichtag + Journal-Korrekturen.
- Die Monatsveraenderung ist die Veraenderung innerhalb des betrachteten Monats; der Gesamtstand ist der kumulierte Saldo seit Stichtag.
- Der aktuelle Monat rechnet fuer den aktuellen Kontostand nur bis zum Standdatum, standardmaessig heute. Zukuenftige Arbeitstage erzeugen keine aktuellen Minusstunden.
- Feiertage und bezahlte Betriebsschliessungen reduzieren das Soll. Sie erzeugen keine zusaetzliche automatische Zeitgutschrift, damit keine Doppelwertung entsteht.
- Ohne finalisierten Stichtag bleiben Monatsauswertungen verfuegbar, aber es wird kein kumulierter Zeitkontostand erfunden.
- Monate vollstaendig vor dem aktiven Stichtag liefern `cutover_status = not_active_in_period` und zeigen keinen kuenstlichen Eroeffnungs- oder Endbestand.
- Manuelle ganztagige Abwesenheiten sind nur an Tagen mit positivem effektivem Tages-Soll erlaubt. Wochenenden, Feiertage und Betriebsschliessungen erzeugen keinen zusaetzlichen Abwesenheitsgutschrift-Bedarf.
- Mehrere Arbeitsbuchungen am selben Tag sind zulaessig. Arbeit plus ganztagige Abwesenheit sowie doppelte ganztagige Abwesenheiten werden zentral serverseitig blockiert.
- Dieselben Konflikt- und Tages-Soll-Pruefungen gelten beim Wiederherstellen archivierter Buchungen erneut gegen den aktuellen Kalender- und Buchungsstand.
- Betriebsschliessungen werden fuer Tagesberechnungen ueber `date_from/date_to` nach Kalenderjahresueberlappung geladen; das Hilfsfeld `year` dient nur Listen und Gruppierung.

## Uebergangsschutz
- Arbeitszeitmodell-Aenderungen bei aktiven Zeitkonten mit Bewegungen werden blockiert, weil historische Arbeitszeitmodellversionen noch nicht voll modelliert sind.
- Feiertagsregionen und rueckwirkende Betriebsschliessungen werden blockiert, wenn aktive Zeitkonten betroffen waeren.
- Die feste Sperrreihenfolge fuer Stichtagsfinalisierung und Revidierung lautet: Mitarbeiter-Stichtagslock, globaler `accounting-timesheet-write`-Lock, erneute Vorschau/Pruefung, DB-Transaktion, Freigabe in umgekehrter Reihenfolge.

## Seeder-Startpunkt
- Standard-Seeds liefern Rollen, Rechte und notwendige Referenzdaten.
- Der erste Administrator wird anschliessend per CLI angelegt:
  `php bin/bootstrap-admin.php --email=... --password=... --first-name=... --last-name=...`
- Beispielbenutzer, Demo-Projekte und Demo-Assets liegen in einem separaten optionalen Demo-Seeder.

## MariaDB-Integrationstests
- Zeitkonto-, Lock-, Migrations-, Foreign-Key- und System-Versioning-Tests verwenden pro Testklasse eine zufaellige Scratch-Datenbank auf MariaDB.
- Die Testbasis migriert das Schema mit Phinx, leert Testdaten zwischen Methoden und entfernt die Datenbank danach. Produktivdatenbanken werden nicht als Testziel verwendet.
- Lokale Testzugriffe koennen mit `TIMEAPP_TEST_DB_*` konfiguriert werden. Ein isolierter App-Prozess kann `DB_OVERRIDE_FILE` auf einen separaten oder nicht vorhandenen Override-Pfad setzen.
- Der reale Playwright-Zeitkonto-Runner erstellt ausschliesslich zufaellige Datenbanken mit dem Praefix `timeapp_ui_`, verwendet synthetische Benutzer und entfernt Datenbank, Serverlogs und Browserartefakte auch bei Fehlern. Jahresuebergreifende Betriebsschliessungen werden relativ zum aktuellen Jahr erzeugt, damit der Workflow nicht durch veraltete feste Testdaten ausfaellt.
