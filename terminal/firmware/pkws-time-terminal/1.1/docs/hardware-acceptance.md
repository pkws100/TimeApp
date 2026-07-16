# Werkbank-Abnahme – Terminal-Firmware 1.1.2

Gerät/Seriennummer: ____________________  Datum: ____________________  Prüfer: ____________________

Status je Zeile: `Bestanden` / `Fehlgeschlagen` / `Nicht ausgeführt – reale Hardware erforderlich`.
Nur tatsächlich durchgeführte Prüfschritte als bestanden markieren.

| Bereich | Prüfschritt | Status | Ergebnis / Messwert |
| --- | --- | --- | --- |
| Hardware | LCD, rote/gelbe/grüne LED, Buzzer | Nicht ausgeführt – reale Hardware erforderlich |  |
| Hardware | RC522, UID-Entprellung, Setup-Taster | Nicht ausgeführt – reale Hardware erforderlich |  |
| Portal | Setup-AP, Login, WLAN-Scan, WLAN-Reconnect | Nicht ausgeführt – reale Hardware erforderlich |  |
| HTTP | Lokale HTTP-API ohne NTP, Buchung, LCD-Antwort | Nicht ausgeführt – reale Hardware erforderlich |  |
| HTTP | Ohne NTP zuerst Platzhalter, danach ohne Neustart aktuelle Europe/Berlin-Uhr; Minutenwechsel | Nicht ausgeführt – reale Hardware erforderlich |  |
| HTTP | Buchungsergebnis bis `hold_ms`, danach sofort aktuelle Uhr | Nicht ausgeführt – reale Hardware erforderlich |  |
| HTTP | Netzverlust, Queue-Ablage, Nachsynchronisierung | Nicht ausgeführt – reale Hardware erforderlich |  |
| HTTPS | Gültiges Zertifikat und Hostname | Nicht ausgeführt – reale Hardware erforderlich |  |
| HTTPS | Falscher Hostname, unbekannte CA, NTP-Timeout | Nicht ausgeführt – reale Hardware erforderlich |  |
| HTTPS | NTP-Wartepflicht, zwingende Zertifikatsprüfung und kein HTTP-Fallback; danach Europe/Berlin-Uhr | Nicht ausgeführt – reale Hardware erforderlich |  |
| Trust | Warnung, Recovery-GET ohne Token/UID, Bundle-Installation | Nicht ausgeführt – reale Hardware erforderlich |  |
| Trust | Neustart während Installation, Rollback und Quarantäne | Nicht ausgeführt – reale Hardware erforderlich |  |
| Queue | WLAN-Verlust während Scan, Dateisystemfehler, Queue voll | Nicht ausgeführt – reale Hardware erforderlich |  |
| Queue | HTTP 425/429/500, globaler 401-Block, datensatzbezogenes Dead Letter | Nicht ausgeführt – reale Hardware erforderlich |  |
| Queue | Idempotente `request_id`, Neustart und authentifizierte Entsperrung | Nicht ausgeführt – reale Hardware erforderlich |  |
| Zustandswechsel | WLAN trennen/reconnecten; aktuelle Uhr nach Reconnect, ohne Überschreiben durch Queue/TLS-Recovery/Setup/Hardwaretest | Nicht ausgeführt – reale Hardware erforderlich |  |
| Zustandswechsel | Nach temporären Anzeigen keine alte Config-Zeit; Neustart zeigt nach NTP aktuelle Uhr | Nicht ausgeführt – reale Hardware erforderlich |  |
| Sommer-/Winterzeit | Native CET/CEST-Nachweis dokumentiert; keine manuelle Uhrumstellung erforderlich | Nicht ausgeführt – reale Hardware erforderlich |  |
| Speicher | Heap/Minimum Heap/Stack: Boot, WLAN, HTTPS, Trust, volle Queue, Sync | Nicht ausgeführt – reale Hardware erforderlich |  |
| Rollback | 1.1 → 1.0, Konfiguration erhalten, Rückkehr 1.1, Queue vorher synchronisiert | Nicht ausgeführt – reale Hardware erforderlich |  |

## Zusätzliche Live-Uhr-Werkbankpunkte

Alle folgenden Punkte sind **Nicht ausgeführt – reale Hardware erforderlich**.

### HTTP

1. Terminal mit HTTP-API starten.
2. Config und NFC-Buchung bei zunächst nicht bereitem NTP prüfen.
3. Bis zur Zeitsynchronisierung den Platzhalter prüfen.
4. Nach NTP ohne Neustart die aktuelle Europe/Berlin-Zeit prüfen.
5. Den Minutenwechsel prüfen.
6. Nach einer Buchung die vollständige Ergebnisanzeige prüfen.
7. Danach sofort die aktuelle Uhr prüfen.

### HTTPS

1. Prüfen, dass HTTPS weiterhin auf gültige Zeit wartet.
2. Prüfen, dass Zertifikatsprüfung zwingend aktiv bleibt.
3. Nach NTP und TLS die korrekte Berliner Zeit prüfen.
4. Falschen Hostnamen und falsche CA weiterhin abweisen.
5. Bestätigen, dass kein automatischer HTTP-Fallback erfolgt.

### Zustandswechsel

1. WLAN trennen und wieder verbinden.
2. Die korrekte Uhr nach Reconnect prüfen.
3. Prüfen, dass Queue-Sync und TLS-Recovery die Uhranzeige nicht überschreiben.
4. Prüfen, dass Setup- und Hardwaretests nicht überschrieben werden.
5. Nach temporären Anzeigen keine alte Zeit sehen.
6. Nach Neustart und Synchronisierung aktuelle Zeit statt Config-Zeit sehen.

Die Sommer-/Winterzeitlogik ist durch die nativen CET-/CEST-Tests nachgewiesen;
eine manuelle Umstellung der realen Uhr ist nicht erforderlich.

## Abnahmeentscheidung

- [ ] Werkbanktest bestanden
- [ ] Pilotbetrieb freigegeben
- [ ] Nicht freigegeben; Fehlerreferenz: ____________________
