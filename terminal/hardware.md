# Hardware-Anforderungen

## Komponenten

- ESP32-Devboard mit stabiler WLAN-Verbindung.
- RFID/NFC: AZDelivery RFID Kit RC522.
- Display: 20x4 LCD mit I2C-Adapter.
- Signale:
  - gruene LED fuer Erfolg/Bereit
  - rote LED fuer Fehler
  - KY-012 aktiver Piezo-Buzzer fuer Piepton
- Stromversorgung:
  - USB-C-Netzteil direkt am ESP32 oder
  - DC-DC-Wandler mit stabiler 5V-Schiene und ESP32-geeigneter Versorgung
- Gehaeuse:
  - 3D-gedruckt
  - Display gut ablesbar
  - NFC-Lesefeld klar markiert
  - Zugentlastung fuer Stromversorgung
  - Wartungszugang fuer USB/Flashen

## Elektrische Hinweise

- RC522 arbeitet mit 3.3V, nicht mit 5V betreiben.
- I2C-LCD-Module werden haeufig mit 5V betrieben; Pegelvertraeglichkeit des I2C-Backpacks pruefen.
- Alle Module muessen gemeinsame Masse haben.
- LED-Vorwiderstaende einplanen.
- KY-012 ist aktiv; GPIO HIGH erzeugt Ton.

## Gehaeuse-Konzept

- Front: LCD oben, NFC-Markierung mittig, LEDs sichtbar.
- Innen: ESP32 und RC522 mit Abstandshaltern befestigen.
- RC522 moeglichst nah an die Gehaeuseoberflaeche setzen.
- Keine Metallplatte direkt hinter der Antenne.
- Rueckseite mit Schrauben oder Serviceklappe.
