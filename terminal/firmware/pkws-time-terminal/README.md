# PK-WS TimeApp ESP32 Terminal Firmware

## Zweck

Diese Firmware ist die erste praxistaugliche V0.1 fuer das stationaere PK-WS TimeApp NFC-Zeiterfassungsterminal. Ein ESP32 liest NFC-Tags mit einem RC522, ruft die Terminal-API der PHP-TimeApp auf und zeigt Serverantworten auf einem 20x4-I2C-LCD an.

Die Einrichtung erfolgt ohne Serial Monitor ueber ein WLAN-Setup-Portal des ESP32. Nach erfolgreicher WLAN-Verbindung bleibt das Portal auch ueber die lokale Terminal-IP erreichbar.

## Hardware

- ESP32 Dev Board
- RC522 RFID/NFC Reader per SPI
- 20x4 LCD mit I2C-Adapter
- Ampelmodul mit Rot, Gelb und Gruen
- KY-012 aktiver Buzzer
- Setup-Taster gegen GND

Das Peltier-Element ist in V0.1 nicht enthalten.

## Pinout

| Bauteil | ESP32 |
| --- | --- |
| RC522 SDA/SS | GPIO 5 |
| RC522 SCK | GPIO 18 |
| RC522 MOSI | GPIO 23 |
| RC522 MISO | GPIO 19 |
| RC522 RST | GPIO 27 |
| RC522 3.3V | 3V3 |
| RC522 GND | GND |
| LCD I2C SDA | GPIO 21 |
| LCD I2C SCL | GPIO 22 |
| LCD VCC | nach Modul, bevorzugt 3.3V testen |
| LCD GND | GND |
| Ampel Gruen | GPIO 25 |
| Ampel Rot | GPIO 26 |
| Ampel Gelb | GPIO 33 |
| KY-012 Buzzer | GPIO 32 |
| Setup-Taster | GPIO 13 gegen GND, interner Pullup aktiv |

Hinweis: Die Firmware nutzt standardmaessig die LCD-I2C-Adresse `0x27`. Wenn das Display dunkel bleibt oder nur Kaestchen zeigt, im Code `LCD_ADDRESS` auf `0x3F` pruefen.

## Bibliotheken

PlatformIO installiert die nicht im Arduino-ESP32-Core enthaltenen Bibliotheken aus `platformio.ini`:

- `miguelbalboa/MFRC522`
- `marcoschwartz/LiquidCrystal_I2C`
- `bblanchon/ArduinoJson`

Aus dem ESP32/Arduino-Core werden verwendet:

- `WiFi.h`
- `WebServer.h`
- `DNSServer.h`
- `Preferences.h`
- `HTTPClient.h`
- `SPI.h`
- `Wire.h`

## Build und Upload mit PlatformIO

```bash
cd terminal/firmware/pkws-time-terminal
pio run
pio run -t upload
pio device monitor
```

Der Monitor darf Diagnose zeigen, aber keine WLAN-Passwoerter und keine Terminal-Tokens. Die Firmware gibt Secrets nicht aus.

## Build und Upload mit Arduino IDE

Die Firmware kann auch mit der Arduino IDE gebaut und auf den ESP32 uebertragen werden. Der Arduino-Sketch liegt hier:

```text
terminal/firmware/pkws-time-terminal/arduino/pkws_time_terminal/pkws_time_terminal.ino
```

Der Sketch bindet `../../src/main.cpp` ein. Dadurch nutzen Arduino IDE und PlatformIO denselben Firmware-Code.

Arduino IDE vorbereiten:

1. Arduino IDE 2.x installieren.
2. In den Boardverwalter-URLs den ESP32-Boardindex von Espressif eintragen:
   `https://espressif.github.io/arduino-esp32/package_esp32_index.json`
3. Im Boardverwalter `esp32` von Espressif Systems installieren.
4. Im Bibliotheksverwalter installieren:
   - `MFRC522`
   - `LiquidCrystal I2C`
   - `ArduinoJson`

Upload:

1. In Arduino IDE `pkws_time_terminal.ino` oeffnen.
2. Board auswaehlen, z. B. `DOIT ESP32 DEVKIT V1` oder ein kompatibles `ESP32 Dev Module`.
3. Port des ESP32 auswaehlen.
4. `Sketch > Hochladen` ausfuehren.
5. Seriellen Monitor mit `115200 Baud` oeffnen.

Vor produktivem Flashen die Platzhalter `SETUP_AP_PASSWORD` und `PORTAL_ADMIN_PASSWORD` in `src/main.cpp` kundenspezifisch setzen.

## Erster Start

Beim Boot zeigt das LCD:

```text
PK-WS TimeApp
Terminal startet
pkws-time-terminal-v0.1.1
bitte warten
```

Danach testet die Firmware kurz Buzzer und Ampel-LEDs. Wenn keine gueltige Konfiguration in Preferences/NVS gespeichert ist, startet automatisch der Setup-Modus.

## Setup-Portal

Im Setup-Modus startet der ESP32 einen Access Point:

- SSID: `PKWS-TimeApp-Setup-<letzte 4 MAC-Zeichen>`
- Passwort: `change-me-setup`
- Adresse: `http://192.168.4.1`
- Portal-Passwort: `change-me-portal`

Die Passwoerter sind Platzhalter und muessen vor produktiver Auslieferung im Code geaendert werden. Keine echten Kundendaten, WLAN-Passwoerter oder Terminal-Tokens gehoeren ins Repository.
Die speichernden Setup-Formulare nutzen zusaetzlich einen pro Setup-Start erzeugten Formularschluessel. Das ersetzt kein starkes AP-Passwort; der Setup-Modus sollte nur waehrend der Einrichtung aktiv sein.

Das Portal bietet:

- Statusseite
- WLAN-Scan
- WLAN-SSID und WLAN-Passwort
- TimeApp API Base URL, z. B. `http://192.168.1.10`
- Terminal-ID, z. B. `terminal-empfang`
- Terminal-Token
- optionaler Geraetename
- API-Test gegen `GET /api/v1/terminal/config`
- Hardwaretests fuer LCD, LEDs, Buzzer und NFC/RC522
- Konfiguration speichern und neu starten
- Konfiguration loeschen
- Neustart

Nach dem Speichern startet der ESP32 neu und verbindet sich mit dem konfigurierten WLAN. Bei dauerhaftem WLAN-Fehler startet wieder der Setup-Modus.

Nach erfolgreicher WLAN-Verbindung zeigt das LCD die lokale IP-Adresse. Das Portal ist dann unter `http://<terminal-ip>/` erreichbar. Dort kann die API Base URL angepasst, mit `API testen` geprueft und die Hardware vor Ort getestet werden.
Vor der Nutzung des Portals ist das Portal-Passwort erforderlich. Nach dem Login setzt die Firmware eine lokale Cookie-Session fuer diesen Browser.

Der API-Test nutzt die gespeicherten oder gerade im Formular eingetragenen Terminal-Daten. Leere WLAN-Passwort- und Terminal-Token-Felder behalten gespeicherte Werte bei. Tokens und WLAN-Passwoerter werden nicht im Portal-Ergebnis, LCD oder Serial Monitor ausgegeben.

Die Hardwaretests:

- `LCD`: zeigt vier Testzeilen fuer einige Sekunden.
- `LEDs`: schaltet Rot, Gelb und Gruen nacheinander.
- `Buzzer`: spielt eine kurze Tonfolge.
- `NFC Reader`: oeffnet fuer ca. 15 Sekunden ein Testfenster und zeigt die normalisierte UID im Portal an; dieser Test sendet keine Buchung an die API.

## API

Die Firmware nutzt:

- `GET <api_base_url>/api/v1/terminal/config`
- `POST <api_base_url>/api/v1/terminal/scan`

Jeder Request sendet:

```http
X-Terminal-ID: <terminal_id>
Authorization: Bearer <terminal_token>
Content-Type: application/json
```

Scan-Requests enthalten eine eindeutige `request_id`, die normalisierte NFC-UID, `device_time` als ISO-Zeit wenn verfuegbar oder `null`, und die Firmware-Version.

## Wiederherstellung und Reset

Setup-Modus wird gestartet, wenn:

- keine gueltige Konfiguration gespeichert ist
- der Setup-Taster beim Boot mindestens 5 Sekunden gehalten wird
- die WLAN-Verbindung nach mehreren Versuchen fehlschlaegt
- der Setup-Taster im Betrieb mindestens 5 Sekunden gehalten wird

Im Setup-Portal kann die Konfiguration geloescht und anschliessend neu erfasst werden.

## Grenzen von V0.1

- Keine Offline-Scan-Queue
- Keine Pausensteuerung am Terminal
- Kein OTA-Update-Mechanismus
- Keine Peltier-Steuerung
- `device_time` bleibt `null`, bis NTP-Zeit verfuegbar ist; die Serverzeit ist fuer Buchungen massgeblich
- HTTPS-Zertifikatsvalidierung ist fuer diese lokale V0.1 nicht gesondert konfiguriert
- WLAN-Scan und HTTP-Requests laufen synchron mit kurzen Timeouts; waehrenddessen kann die Bedienung fuer wenige Sekunden reagieren wie "beschaeftigt"
- Das LAN-Portal nutzt nur ein lokales Platzhalter-Passwort plus Formularschluessel; fuer Produktion `PORTAL_ADMIN_PASSWORD` und `SETUP_AP_PASSWORD` vor dem Flashen kundenspezifisch setzen
