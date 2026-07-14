# Firmware 1.1.1 mit Arduino IDE flashen

Diese Anleitung verwendet ausschließlich die Arduino IDE 2.x. PlatformIO wird
nicht benötigt. Sie gilt für das ESP32-Terminal mit `DOIT ESP32 DEVKIT V1`
und Arduino-ESP32 **2.0.17**.

## Vor dem Start

1. Die vollständige Repository-Struktur beibehalten. Den Sketch nicht allein
   in einen anderen Ordner kopieren, weil er die gemeinsame Firmwarequelle
   unter `../../src/main.cpp` einbindet.
2. `include/TrustConfig.example.h` nach
   `include/TrustConfig.local.h` kopieren und dort ausschließlich den echten
   öffentlichen PK-WS-P-256-Prüfschlüssel eintragen. Danach
   `PKWS_TRUST_CONFIGURED` auf `1` setzen. Die lokale Datei ist absichtlich
   ignoriert und darf keinen privaten Schlüssel enthalten.
3. `include/ProvisioningConfig.example.h` nach
   `include/ProvisioningConfig.local.h` kopieren und beide Platzhalter durch
   gerätespezifische, starke Zugangsdaten ersetzen. Diese lokale Datei darf nie
   eingecheckt werden. Zusätzlich `PKWS_PROVISIONING_ID` durch eine eindeutige
   Terminal- oder Batchkennung ersetzen. Danach `PKWS_PROVISIONING_CONFIGURED`
   auf `1` setzen.
   Keine WLAN-Passwörter oder Terminal-Tokens in den Quellcode schreiben.

## Trust-Server vor dem ersten HTTPS-Flash vorbereiten

Der öffentliche Schlüssel im Terminal und der Server müssen zusammenpassen:

1. Den korrespondierenden privaten P-256-Schlüssel offline verwahren.
2. Mit `tools/terminal-trust-bundle/terminal-trust-bundle.php sign` ein
   signiertes CA-Bundle erzeugen und als `TERMINAL_TRUST_BUNDLE_FILE`
   bereitstellen.
3. Den passenden **öffentlichen** PEM-Schlüssel außerhalb des Repositories
   ablegen und `TERMINAL_TRUST_PUBLIC_KEY_FILE` auf diesen Pfad setzen.
4. Vor dem Flash `GET /api/v1/terminal/trust-bundle` prüfen: Der Endpunkt muss
   HTTP 200, JSON, `ETag` und das erwartete Bundle liefern. Ohne gültiges,
   serverseitig geprüftes Bundle darf kein Trust-Recovery-Test als bestanden
   gelten.

## Arduino IDE einrichten

1. Arduino IDE 2.x installieren und starten.
2. Unter **Datei → Voreinstellungen → Zusätzliche Boardverwalter-URLs** diese
   URL ergänzen:

   ```text
   https://espressif.github.io/arduino-esp32/package_esp32_index.json
   ```

3. Unter **Werkzeuge → Board → Boardverwalter** `esp32` von *Espressif
   Systems* in Version **2.0.17** installieren. Eine neuere Major-Version
   nicht ungeprüft verwenden.
4. Unter **Werkzeuge → Bibliotheken verwalten** diese Bibliotheken installieren:

   - `MFRC522` von GithubCommunity/Miguel Balboa, Version 1.4.11 oder 1.4.12
   - `LiquidCrystal I2C` von Marco Schwartz, Version 1.1.4
   - `ArduinoJson` von Benoit Blanchon, Version 6.21.5 oder 6.21.6

   `WiFi`, `WiFiClientSecure`, `HTTPClient`, `LittleFS`, `Preferences`,
   `WebServer`, `DNSServer`, mbedTLS und SPI gehören zum ESP32-Core und werden
   nicht separat aus dem Bibliotheksverwalter installiert.

## Kompilieren und Upload

1. Diesen Sketch in Arduino IDE öffnen:

   ```text
   terminal/firmware/pkws-time-terminal/1.1/arduino/pkws_time_terminal/pkws_time_terminal.ino
   ```

2. Unter **Werkzeuge** einstellen:

   - Board: `DOIT ESP32 DEVKIT V1` (alternativ ein kompatibles `ESP32 Dev Module`)
   - Flash Size: `4MB (32Mb)`
   - Partition Scheme: `Default 4MB with spiffs (1.2MB APP/1.5MB SPIFFS)`;
     die Datenpartition wird vom ESP32-Core als LittleFS-Speicher genutzt und
     muss für Trust-Dateien sowie bis zu 64 Queue-Einträge erhalten bleiben.
   - Port: den tatsächlich verbundenen USB-/Seriell-Port
   - Upload Speed: zunächst `115200`, bei stabilem USB optional höher

3. Zuerst **Sketch → Überprüfen/Kompilieren** ausführen. Fehlt eine der beiden
   lokalen Konfigurationsdateien, ist der Abbruch beabsichtigt: Kein Beispiel-
   oder Testschlüssel und keine Default-Passwörter dürfen versehentlich
   geflasht werden.
4. Den seriellen Monitor schließen, damit der Port für den Upload frei ist.
   **Sketch → Hochladen** wählen. Bleibt die IDE bei `Connecting...` stehen,
   die Boot-Taste des ESP32 gedrückt halten, bis der Upload startet; nach dem
   Upload bei Bedarf die `EN`-/Reset-Taste drücken.
5. Den seriellen Monitor mit **115200 Baud** öffnen. Er darf Firmware-Version,
   MAC-Adresse und Fehlerdiagnosen zeigen, aber keine WLAN-Passwörter oder
   Terminal-Tokens.

## Nach dem Flash

- Das LCD muss `pkws-time-terminal-v1.1.1` anzeigen.
- Ohne gespeicherte Konfiguration erscheint der Setup-Access-Point
  `PKWS-TimeApp-Setup-<MAC-Endung>`; das Portal ist unter
  `http://192.168.4.1` erreichbar.
- Mit dem Access-Point über `PKWS_SETUP_AP_PASSWORD` verbinden und sich im
  Portal mit `PKWS_PORTAL_ADMIN_PASSWORD` anmelden. SSID, WLAN-Passwort,
  `http://`- oder `https://`-API-Base-URL, Terminal-ID und Terminal-Token
  eintragen; **Speichern und verbinden** ausführen und den Neustart abwarten.
  Erst danach den Portal-API-Test durchführen.
- Meldet das Portal `Dateisystem nicht eingebunden`, kann ein neues oder
  beschädigtes LittleFS ausschließlich nach Login und doppelter Bestätigung
  über **Trust und Queue → Dateisystem doppelt bestätigt formatieren**
  initialisiert werden; dazu muss im Formular `FORMATIEREN` eingetragen werden.
  Das löscht Trust- und Offline-Queue-Daten; es ist kein normaler Flash-Schritt.
- Bei HTTPS zuerst NTP-Zeit und danach eine verifizierte API-Verbindung prüfen.
  HTTP benötigt keine NTP-Zeit, wird aber im Portal ausdrücklich als
  unverschlüsselt markiert.
- Einen NFC-Scan, WLAN-Reconnect und den Portal-API-Test durchführen. Der
  vollständige Hardware- und Recovery-Testplan steht in `testplan.md`.

Bei unverändertem Board und unverändertem Partitionsschema bleibt LittleFS bei
einem normalen Sketch-Upload üblicherweise erhalten. Ein abweichendes
Partitionsschema oder ein Full-Flash-Erase kann es jedoch unlesbar machen oder
löschen. Vor einem Downgrade auf 1.0 die Offline-Queue synchronisieren;
Firmware 1.0 verwendet verbliebene 1.1-Trust-/Queue-Dateien nicht. Das
Dateisystem nur über die doppelt bestätigte Wartungsfunktion formatieren, nie
als normalen Flash-Schritt.
Insbesondere keine IDE-Option zum vollständigen Flash-Erase auswählen, solange
Trust- oder Offline-Queue-Daten erhalten bleiben müssen.

## Rollback auf Firmware 1.0 mit Arduino IDE

1. In 1.1 zuerst die Offline-Queue im Portal synchronisieren und den Erfolg
   prüfen.
2. In Arduino IDE den unabhängigen 1.0-Wrapper öffnen:

   ```text
   terminal/firmware/pkws-time-terminal/1.0/arduino/pkws_time_terminal/pkws_time_terminal.ino
   ```

3. Dasselbe Board und denselben Port auswählen; keinen Full-Flash-Erase und
   keinen Dateisystem-Upload ausführen.
4. **Sicherheitswarnung:** Der eingefrorene 1.0-Stand enthält historische
   Standard-AP-/Portalpasswörter. Er darf nur als zeitlich begrenzter
   Notfall-Rollback in einem isolierten Netz verwendet werden, nicht als
   produktiv abgesicherter Terminalstand.
5. Sketch hochladen, neu starten und im LCD bzw. seriellen Monitor
   `pkws-time-terminal-v0.1.1` bestätigen.
6. Im lokalen Portal von 1.0 den API-Test ausführen. Die gemeinsame
   Terminal-Konfiguration liegt in NVS und bleibt bei einem normalen
   App-Upload erhalten.
