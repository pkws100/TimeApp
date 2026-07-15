# Werkbank-Abnahme – Terminal-Firmware 1.1.1

Gerät/Seriennummer: ____________________  Datum: ____________________  Prüfer: ____________________

Status je Zeile: `Bestanden` / `Fehlgeschlagen` / `Nicht ausgeführt – reale Hardware erforderlich`.
Nur tatsächlich durchgeführte Prüfschritte als bestanden markieren.

| Bereich | Prüfschritt | Status | Ergebnis / Messwert |
| --- | --- | --- | --- |
| Hardware | LCD, rote/gelbe/grüne LED, Buzzer | Nicht ausgeführt – reale Hardware erforderlich |  |
| Hardware | RC522, UID-Entprellung, Setup-Taster | Nicht ausgeführt – reale Hardware erforderlich |  |
| Portal | Setup-AP, Login, WLAN-Scan, WLAN-Reconnect | Nicht ausgeführt – reale Hardware erforderlich |  |
| HTTP | Lokale HTTP-API ohne NTP, Buchung, LCD-Antwort | Nicht ausgeführt – reale Hardware erforderlich |  |
| HTTP | Netzverlust, Queue-Ablage, Nachsynchronisierung | Nicht ausgeführt – reale Hardware erforderlich |  |
| HTTPS | Gültiges Zertifikat und Hostname | Nicht ausgeführt – reale Hardware erforderlich |  |
| HTTPS | Falscher Hostname, unbekannte CA, NTP-Timeout | Nicht ausgeführt – reale Hardware erforderlich |  |
| Trust | Warnung, Recovery-GET ohne Token/UID, Bundle-Installation | Nicht ausgeführt – reale Hardware erforderlich |  |
| Trust | Neustart während Installation, Rollback und Quarantäne | Nicht ausgeführt – reale Hardware erforderlich |  |
| Queue | WLAN-Verlust während Scan, Dateisystemfehler, Queue voll | Nicht ausgeführt – reale Hardware erforderlich |  |
| Queue | HTTP 425/429/500, globaler 401-Block, datensatzbezogenes Dead Letter | Nicht ausgeführt – reale Hardware erforderlich |  |
| Queue | Idempotente `request_id`, Neustart und authentifizierte Entsperrung | Nicht ausgeführt – reale Hardware erforderlich |  |
| Speicher | Heap/Minimum Heap/Stack: Boot, WLAN, HTTPS, Trust, volle Queue, Sync | Nicht ausgeführt – reale Hardware erforderlich |  |
| Rollback | 1.1 → 1.0, Konfiguration erhalten, Rückkehr 1.1, Queue vorher synchronisiert | Nicht ausgeführt – reale Hardware erforderlich |  |

## Abnahmeentscheidung

- [ ] Werkbanktest bestanden
- [ ] Pilotbetrieb freigegeben
- [ ] Nicht freigegeben; Fehlerreferenz: ____________________
