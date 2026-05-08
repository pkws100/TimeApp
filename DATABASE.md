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
- `timesheets`

## Fachliche Leitlinien
- Rollen und Berechtigungen sind getrennt modelliert, damit Backend-Rechte, Projektrechte und Exportrechte sauber kombiniert werden koennen.
- Fahrzeuge und Geraete sind eigene Stammdaten, nicht Teil des Rollenmodells.
- Projekt- und Geraetedateien werden mit Metadaten in der Datenbank und physisch ausserhalb des finalen Webroots gespeichert.
- `company_settings` haelt genau ein globales Firmenprofil fuer Reports, E-Mails, Rechtstexte und spaetere Frontend-Policies.
- Firmenlogo sowie AGB-/Datenschutz-PDFs werden nur per Dateireferenz gespeichert und physisch geschuetzt abgelegt.
- `timesheets` deckt `work`, `sick`, `vacation`, `holiday` und `absent` ab.
- GoBD-konforme Archivierung wird ueber `is_deleted`, `deleted_at` und `deleted_by_user_id` auf den relevanten Stammdaten umgesetzt.
- SMTP- und GEO-Vorbereitungsfelder liegen zentral im globalen Settings-Datensatz, damit Backend und spaeteres Frontend dieselbe Quelle nutzen.

## Zeiterfassungslogik
- Arbeitsbloecke speichern Brutto-, Pausen- und Netto-Minuten.
- Gesetzliche Pausen werden serverseitig automatisch auf mindestens 30 Minuten bei mehr als 6 Stunden und 45 Minuten bei mehr als 9 Stunden angehoben.
- Spesen werden ueber `expenses_amount` erfasst, damit PDF- und Exportberichte dieselbe Datenbasis verwenden.

## Seeder-Startpunkt
- Standard-Seeds liefern Rollen, Rechte und notwendige Referenzdaten.
- Der erste Administrator wird anschliessend per CLI angelegt:
  `php bin/bootstrap-admin.php --email=... --password=... --first-name=... --last-name=...`
- Beispielbenutzer, Demo-Projekte und Demo-Assets liegen in einem separaten optionalen Demo-Seeder.
