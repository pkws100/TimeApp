# Codex-Vorlage fuer ESP32-Firmware

Nutze diese Vorlage in einem spaeteren Codex-Lauf, um die Arduino/ESP32-Firmware zu erstellen.

## Aufgabe

Erstelle eine Arduino-ESP32-Firmware fuer ein stationaeres NFC-Zeiterfassungsterminal.

## Hardware

- ESP32
- RC522 RFID Reader ueber SPI
- 20x4 LCD ueber I2C
- gruene LED
- rote LED
- KY-012 aktiver Buzzer

## Netzwerk

- WLAN-Konfiguration ueber Konstanten oder spaeteres Captive Portal.
- API-Basis-URL als Konstante.
- Requests mit `X-Terminal-ID` und `Authorization: Bearer <token>`.
- HTTP-Timeout kurz halten, zum Beispiel 5 Sekunden.
- Bei Netzwerkfehler rote LED, Fehlerpiep, LCD-Fehler anzeigen.

## Firmware-Verhalten

1. Bootscreen anzeigen.
2. WLAN verbinden.
3. `GET /api/v1/terminal/config` abrufen.
4. Willkommen-Bildschirm anzeigen.
5. NFC-UID lesen und normalisiert als Hex mit Doppelpunkten senden.
6. Pro Scan eine UUID-artige `request_id` erzeugen und bei Retry gleich behalten.
7. `POST /api/v1/terminal/scan` senden.
8. `display.lines` exakt auf dem LCD anzeigen.
9. `signal.led` und `signal.beep` auswerten.
10. Nach `display.hold_ms` zurueck zum Willkommen-Bildschirm.

## Nicht in V1

- Keine Pausensteuerung.
- Keine lokale Offline-Buchungsqueue.
- Keine Klartextanzeige oder Speicherung von NFC-UIDs ausserhalb des Requests.

## Akzeptanz

- Erfolgreicher Scan zeigt vier Zeilen und gruene LED.
- Fehler zeigt vier Zeilen und rote LED.
- Unbekannter Tag waehrend Admin-Anlernmodus zeigt „Tag erfasst“.
- Doppelte `request_id` erzeugt keine Doppelbuchung.
