# Native Betriebsdateien für die Personalakten-Beta

Die Dateien gelten für Debian 12 mit Apache `prefork`, mod_php 8.2 und MariaDB. In der Vorlage stehen `timeapp.example.invalid` für den öffentlichen Host und `192.0.2.10` für die TCP-Adresse des TLS-Proxys. Der Proxy muss `X-Forwarded-Proto` durch den tatsächlichen Protokollwert ersetzen, auch bei widersprüchlichen Client-Headern. Für die Herkunftsprüfung zählen der TCP-Peer, der `Host`-Header und der normalisierte `X-Forwarded-Proto`.

## Scanner und flüchtige Verzeichnisse

```sh
apt-get update
apt-get install --no-install-recommends qpdf clamav-daemon clamav-freshclam
bash ops/personnel-beta/configure-scanner.sh
```

Das Skript richtet den Debian-ClamAV-Dienst für diese Installation ein und sichert dessen vorherige Konfiguration. Die Signaturquelle bleibt der reguläre FreshClam-Dienst. Der lokale Socket ist nur für `clamav` und `www-data` erreichbar. Die Prüfung läuft als `www-data` und verlangt qpdf, `PING`, die Freigabe sauberer Testdaten und die Erkennung des harmlosen EICAR-Prüfmusters. Bei einem bereits anderweitig genutzten ClamAV-Dienst vorab die Socket-Nutzer klären.

Der persistente Objektpfad lautet `/var/lib/timeapp-personnel/store`. Temporäre PHP-Uploads und unverschlüsselte Quarantänedateien verwenden `/run/timeapp-personnel/incoming` und `/run/timeapp-personnel/quarantine` auf `tmpfs`. Der Scanner arbeitet in einem eigenen Verzeichnis unter `/run/timeapp-personnel/scanner`. `systemd-tmpfiles` erstellt diese Verzeichnisse beim Start mit ihren jeweiligen Dienstkennungen neu.

Datei- und Backup-Schlüssel werden getrennt vom Repository bereitgestellt. Der Webprozess erhält seinen Dateischlüssel unter `/etc/timeapp-personnel/object-keyring.json` mit Modus `0400`. Die getrennte Betriebskennung für das private Backup braucht eine geschützte Kopie desselben Dateischlüssels und einen eigenen Backup-Schlüssel. Basis- und Aktensicherung müssen sich im freigegebenen Wiederanlauf gemeinsam wiederherstellen lassen.

## Apache

`apache-vhost.conf` root-eigen nach `/etc/apache2/conf-available/timeapp-personnel-beta-vhost.conf` kopieren. In der Kopie beide Beispielwerte durch den verifizierten öffentlichen Host und die tatsächliche TCP-Adresse des TLS-Proxys ersetzen. Die lokale Datei mit `Include` am Ende des bestehenden TimeApp-`VirtualHost` auf Port 80 einbinden. Die Installation verwendet `mod_rewrite`, `mod_setenvif`, `mod_headers` und mod_php. Der Eintrag für `upload_tmp_dir` gilt für alle PHP-Uploads des TimeApp-VHosts.

Der VHost setzt das native PHP-Signal `HTTPS=on` nur für den exakten Host und TLS-Requests vom festgelegten TCP-Peer. Dieselbe Host/Peer-Kombination mit HTTP-Signal leitet per `308` auf den festen HTTPS-Ursprung um. Direkte Requests mit dem öffentlichen Host und Proxy-Requests ohne gültiges Protokollsignal werden abgewiesen. Vor einem Proxy-Wechsel die neue Peer-Adresse und Header-Normalisierung im Zielnetz erneut prüfen.

```sh
apachectl configtest
systemctl reload apache2
```

Vor Aktivierung an beiden Einstiegspunkten prüfen, dass öffentliches HTTP zu HTTPS umleitet, öffentliches HTTPS funktionsfähig bleibt und direktes Backend-HTTP mit dem öffentlichen Host abgewiesen wird. Nach einem Neustart müssen die flüchtigen Verzeichnisse wieder den Betriebskennungen gehören. Der Dateischlüssel, persistente Objektpfad, private Backup-Pfad und Scannerstatus gehören zur Prüfung der Zielumgebung.

## Aktivierung der Anwendung

Die Freischaltung setzt die Phinx-Migration `20261118209000` und sichere Session-Cookies voraus. Der Menüpunkt trägt die BETA-Kennzeichnung und erscheint für aktive Systemadministratoren mit dem Personalaktenrecht `personnel_documents.view`. Für Schreibvorgänge ist zusätzlich `personnel_documents.manage` erforderlich.

Das reguläre App-Konto erhält Tabellenrechte für den normalen Betrieb und `SELECT` auf `personnel_backup_presence`. Die acht privaten Personalaktentabellen liegen außerhalb seiner Berechtigungen. Für den lokalen Beta-Zugang gibt `PersonnelProductionHttpBoundary::databaseGrants()` die erwarteten Tabellenrechte an. `DB_MIGRATION_IDENTITY_FILE` verweist auf ein von Root geschütztes JSON mit `database`, `socket`, `username` und `password` für Phinx. Phinx prüft Schema und Socket gegen die Laufzeitkonfiguration. Der Webprozess kann diese Datei weder lesen noch verwenden.

Die tatsächlichen Werte für `PERSONNEL_BETA_DB_SCHEMA`, `PERSONNEL_BETA_DB_SOCKET`, `PERSONNEL_BETA_DB_USERNAME` und `PERSONNEL_BETA_DB_PASSWORD` in der privaten Umgebung ergänzen. In der Umgebung außerdem setzen:

```dotenv
PERSONNEL_BETA_ORIGIN=https://timeapp.example.invalid
PERSONNEL_BETA_INCOMING_ROOT=/run/timeapp-personnel/incoming
PERSONNEL_BETA_QUARANTINE_ROOT=/run/timeapp-personnel/quarantine
PERSONNEL_FILE_ROOT=/var/lib/timeapp-personnel/store
PERSONNEL_FILE_KEYRING=/etc/timeapp-personnel/object-keyring.json
PERSONNEL_CLAMD_SOCKET=/run/clamav/clamd.ctl
PERSONNEL_QPDF=/usr/bin/qpdf
PERSONNEL_BACKUP_ROOT=/var/lib/timeapp-personnel-backup
```

`PERSONNEL_HTTP_MODE=production_beta` schaltet die Oberfläche nach Betriebsprüfung und Einrichtung der privaten Sicherung frei. `PERSONNEL_HTTP_MODE=disabled` schließt sie bei einem Rückbau.

## Private Sicherung

Die Produktionssicherung verwendet eine root-eigene, geschützte Betriebskonfiguration mit `mode` gleich `production_beta` und einem getrennten lokalen, ausschließlich lesenden MariaDB-Konto. `PersonnelBackupTables::liveSourceTables()` liefert dessen erwartete Tabellenliste. `source_storage_uid` entspricht dem Eigentümer des Webspeichers `www-data`. Der Operator prüft und nutzt die vorhandene Speichersperre. Er arbeitet mit getrenntem Archivschlüssel und einer geschützten Kopie des Dateischlüssels.

```sh
php bin/personnel-private-backup.php create-live /etc/timeapp-personnel/backup/operator.json
```

Für die Gegenprobe wird die passende verschlüsselte Basissicherung mit dem gleichen Phinx-Stand auf einer separaten MariaDB-Instanz bereitgestellt. Dort prüfen `validate` und `restore-scratch` die gebundenen Stammdaten, Auditdaten und Objekte. Falls der ursprüngliche Definer beim Restore unter einem anderen Schemanamen keine Trigger lesen kann, erstellt der Migrationsbetreiber die View `personnel_schema_trigger_manifest` auf dem Scratch-Ziel mit der unveränderten Definition aus Migration `20261118208000` neu. Die Prüfung der Triggerdefinitionen erfolgt danach weiterhin durch den Backup-Dienst. Das allgemeine Systembackup enthält leere Platzhalter für die privaten Tabellen. Die View `personnel_backup_presence` liefert dem App-Konto deren Belegungsstatus.
