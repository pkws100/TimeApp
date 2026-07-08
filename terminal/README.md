# Terminal-Konzept

Dieses Verzeichnis beschreibt die stationaeren NFC-Terminals fuer die Zeiterfassung. Die erste ESP32-Firmware liegt unter `terminal/firmware/pkws-time-terminal/`; diese Dokumentation bleibt die verbindliche Vorlage fuer Hardware, API und Ablauf.

## Ziel

Ein ESP32-Terminal im LAN liest NFC-Tags, sendet die UID an die TimeApp und zeigt die Serverantwort auf einem 20x4-I2C-LCD an. Der Server entscheidet anhand des NFC-Tags, des Terminal-Projekts und der aktuellen Tagesbuchung, ob ein Kommen oder Gehen gebucht wird.

## Ablauf

1. Terminal startet, verbindet sich mit WLAN/LAN und ruft `GET /api/v1/terminal/config` ab.
2. LCD zeigt den Willkommenstext und wartet auf einen NFC-Tag.
3. Bei Tag-Scan sendet die Firmware `POST /api/v1/terminal/scan`.
4. Server authentifiziert Terminal-ID und Bearer-Token.
5. Server sucht den gehashten NFC-Tag.
6. Ist ein Admin-Anlernfenster aktiv, wird ein unbekannter Tag als pending erfasst.
7. Ist der Tag aktiv zugeordnet, bucht der Server automatisch:
   - kein offener Arbeitseintrag heute: `check_in`
   - offener Arbeitseintrag heute: `check_out`
   - zuletzt geschlossener Eintrag: neuer `check_in`
8. Terminal zeigt vier LCD-Zeilen, LED-Signal und Piepton aus der Antwort fuer 15 Sekunden.
9. Terminal geht zurueck zum Willkommen-Bildschirm.

## Sicherheit

- Authentifizierung: `Authorization: Bearer <token>` und `X-Terminal-ID`.
- Der Token wird im Admin nur einmalig angezeigt und serverseitig nur gehasht gespeichert.
- NFC-UIDs werden normalisiert und per HMAC-SHA256 gespeichert; Admins sehen nur eine maskierte UID.
- Optional kann pro Terminal eine IP-Allowlist hinterlegt werden.
- Serverzeit ist fuer Buchungen massgeblich; die Terminalzeit dient nur der Diagnose.

## Admin-Bedienung

- `/admin/settings/company`: Terminal-Funktion aktivieren.
- `/admin/terminals`: Terminals anlegen, Tokens resetten, Anlernmodus starten, NFC-Tags Usern und optional Projekten zuordnen.
- Terminal-Projekt hat Vorrang vor NFC-Tag-Projekt.

## Grenzen von V1

- V1 bucht nur Kommen und Gehen.
- Pausensteuerung am Terminal ist nicht enthalten.
- Offline-Queue in der Firmware ist spaeter sinnvoll, aber nicht Teil dieser Server-Schnittstelle.
