# Werkbank-Abnahme – Terminal-Firmware 1.1.1

Gerät/Seriennummer: ____________________  Datum: ____________________  Prüfer: ____________________

Status je Zeile: `Bestanden` / `Fehlgeschlagen` / `Nicht ausgeführt – reale Hardware erforderlich`.
Nur tatsächlich durchgeführte Prüfschritte als bestanden markieren.

| Bereich | Prüfschritt | Status | Ergebnis / Messwert |
| --- | --- | --- | --- |
| Hardware | LCD, rote/gelbe/grüne LED, Buzzer |  |  |
| Hardware | RC522, UID-Entprellung, Setup-Taster |  |  |
| Portal | Setup-AP, Login, WLAN-Scan, WLAN-Reconnect |  |  |
| HTTP | Lokale HTTP-API ohne NTP, Buchung, LCD-Antwort |  |  |
| HTTP | Netzverlust, Queue-Ablage, Nachsynchronisierung |  |  |
| HTTPS | Gültiges Zertifikat und Hostname |  |  |
| HTTPS | Falscher Hostname, unbekannte CA, NTP-Timeout |  |  |
| Trust | Warnung, Recovery-GET ohne Token/UID, Bundle-Installation |  |  |
| Trust | Neustart während Installation, Rollback auf Previous |  |  |
| Queue | WLAN-Verlust während Scan, Dateisystemfehler, Queue voll |  |  |
| Queue | HTTP 500, 429, 401/Dead Letter, defekter Eintrag, Neustart |  |  |
| Queue | Idempotente `request_id` und manuelle Sync-Sperre bei Live-Scan |  |  |
| Speicher | Heap/Minimum Heap/Stack: Boot, WLAN, HTTPS, Trust, volle Queue, Sync |  |  |
| Rollback | 1.1 → 1.0, Konfiguration erhalten, Rückkehr 1.1, Queue vorher synchronisiert |  |  |

## Abnahmeentscheidung

- [ ] Werkbanktest bestanden
- [ ] Pilotbetrieb freigegeben
- [ ] Nicht freigegeben; Fehlerreferenz: ____________________
