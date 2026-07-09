# Testplan PK-WS TimeApp ESP32 Terminal Firmware V0.1

## Test 1: ESP32 Bootscreen

- Firmware flashen und ESP32 neu starten.
- Erwartung: LCD zeigt vier Zeilen mit `PK-WS TimeApp`, `Terminal startet`, Firmware-Version und `bitte warten`.

## Test 1a: Build und Upload mit Arduino IDE

- `terminal/firmware/pkws-time-terminal/arduino/pkws_time_terminal/pkws_time_terminal.ino` in Arduino IDE oeffnen.
- ESP32-Core und Bibliotheken `MFRC522`, `LiquidCrystal I2C` und `ArduinoJson` installieren.
- Board `DOIT ESP32 DEVKIT V1` oder kompatibles `ESP32 Dev Module` auswaehlen.
- Sketch kompilieren und auf den ESP32 hochladen.
- Erwartung: Build und Upload funktionieren ohne Aenderung an `src/main.cpp`.

## Test 2: LCD I2C

- LCD mit GPIO 21/22 anschliessen.
- Erwartung: Alle vier Zeilen werden stabil und ohne abgeschnittene Restzeichen aktualisiert.
- Wenn keine Ausgabe erscheint, LCD-Adresse `0x27` gegen `0x3F` testen.

## Test 3: Ampel und Buzzer

- ESP32 neu starten.
- Erwartung: Buzzer piept kurz, rote, gelbe und gruene LED werden beim Boot kurz getestet.

## Test 4: Setup-Portal per Handy verbinden

- ESP32 ohne gespeicherte Konfiguration starten.
- Handy mit `PKWS-TimeApp-Setup-<MAC>` verbinden.
- Erwartung: Portal-Login ist unter `http://192.168.4.1` erreichbar; nach Login erscheint die Setup-Seite.

## Test 5: WLAN-Scan anzeigen

- Im Setup-Portal `WLANs suchen` antippen.
- Erwartung: Gefundene WLAN-Netze werden mit SSID und RSSI angezeigt.

## Test 6: WLAN-Konfiguration speichern

- SSID, WLAN-Passwort, API Base URL, Terminal-ID und Terminal-Token eintragen.
- `Speichern und verbinden` antippen.
- Erwartung: LCD zeigt `Konfig gespeichert` und ESP32 startet neu.

## Test 7: Reboot und Verbindung ins Kundennetz

- Nach dem Reboot WLAN-Verbindung beobachten.
- Erwartung: LCD zeigt Verbindungsversuche und danach die lokale IP-Adresse.

## Test 8: API config abrufen

- Im Backend Terminal-Funktion aktivieren und Terminal-ID/Token korrekt hinterlegen.
- Erwartung: Firmware ruft `GET /api/v1/terminal/config` ab und zeigt die vier Server-LCD-Zeilen.

## Test 8a: Portal im Kundennetz oeffnen

- Nach erfolgreicher WLAN-Verbindung die am LCD angezeigte IP-Adresse im Browser oeffnen, z. B. `http://<terminal-ip>/`.
- Erwartung: Das Portal ist im LAN erreichbar, fordert das Portal-Passwort an und zeigt danach WLAN-Status, verbundene SSID, RSSI, WLAN-Qualitaet, Portal-IP, API Base URL und API-Status.

## Test 8b: API-Testbutton nutzen

- Im LAN-Portal API Base URL und Terminal-ID pruefen; Terminal-Token bei Bedarf neu eingeben.
- `API testen` antippen.
- Erwartung: Portal zeigt HTTP-Status, `ok`, Serverzeit und vier Display-Zeilen; bei Erfolg zeigt das LCD kurz die Serverantwort.

## Test 8c: Hardwaretests aus dem Portal

- `LCD`, `LEDs` und `Buzzer` im Portal nacheinander ausloesen.
- Erwartung: LCD zeigt Testzeilen, LEDs schalten Rot/Gelb/Gruen nacheinander, Buzzer spielt eine Tonfolge.
- `WLAN aktualisieren` antippen.
- Erwartung: Der Diagnosebereich zeigt die verbundene WLAN-SSID, RSSI in dBm und Qualitaet in Prozent.
- Erwartung: WLAN-Scan und Hardwaretests funktionieren nur nach Portal-Login mit gueltiger Sitzung.

## Test 8d: NFC-Testmodus aus dem Portal

- `NFC Reader` im Portal starten und innerhalb von 15 Sekunden einen Tag vorhalten.
- Erwartung: Portal zeigt RC522-Version, Reader-Status, Debugmeldung, UID-Laenge und normalisierte UID kurz als Testergebnis; es wird keine Buchung an die API gesendet. Dieselbe UID wird danach fuer ca. 2 Sekunden entprellt.

## Test 9: RC522 UID lesen

- NFC-Tag an den RC522 halten.
- Erwartung: LCD zeigt `Tag erkannt`, `Anfrage laeuft`, `bitte warten`; doppelte Scans desselben Tags innerhalb von 2 Sekunden werden unterdrueckt.

## Test 10: NFC-Scan an API senden

- Einen Tag scannen.
- Erwartung: Firmware sendet `POST /api/v1/terminal/scan` mit normalisierter UID wie `04:A1:B2:C3:D4`.

## Test 11: Unbekannter Tag zeigt Serverfehler sauber auf LCD

- Einen unbekannten Tag ohne aktiven Anlernmodus scannen.
- Erwartung: Serverantwort mit Fehler wird auf vier LCD-Zeilen angezeigt, rote LED und Fehlerpiep aktiv.

## Test 12: Anlernmodus aus Admin testen

- Im Admin fuer das Terminal den Anlernmodus starten.
- Unbekannten Tag scannen.
- Erwartung: LCD zeigt sinngemaess `Tag erfasst`; der Tag erscheint im Admin als pending.

## Test 13: Gueltiger Tag bucht check_in/check_out

- NFC-Tag im Admin einem aktiven User zuordnen.
- Tag scannen.
- Erwartung: Erster Scan bucht `check_in`, naechster Scan bucht `check_out`; LCD, LED und Buzzer folgen der API-Antwort.

## Test 14: WLAN-Ausfall simulieren

- WLAN-Router ausschalten oder ESP32 ausser Reichweite bringen.
- Erwartung: Firmware erkennt den Abbruch, versucht Reconnect und startet nach dauerhaftem Fehler den Setup-Modus.
- Waehrend eines NFC-Scans den WLAN-Ausfall ausloesen und danach WLAN wiederherstellen.
- Erwartung: Der erkannte Scan wird nach Reconnect mit derselben Anfrage fortgesetzt und nicht still verworfen.

## Test 15: Setup-Taster beim Boot erzwingt Setup-Modus

- Setup-Taster gegen GND halten und ESP32 starten.
- Taster mindestens 5 Sekunden halten.
- Erwartung: Setup-Modus startet unabhaengig von gespeicherter Konfiguration.

## Test 16: Konfiguration loeschen und neu einrichten

- Im Setup-Portal `Konfiguration loeschen` ausfuehren.
- ESP32 neu starten.
- Erwartung: Setup-Modus startet erneut und die Daten koennen neu eingegeben werden.

## Regression

- PHP-Backend wurde fuer V0.1 nicht geaendert.
- Optional vorhandene PHP-Tests ausfuehren, um sicherzustellen, dass die Terminal-API weiterhin unveraendert arbeitet.
