# Werkbank-Abnahme – Terminal-Firmware 1.1.2

Gerät/Seriennummer: ____________________  Datum: 16.07.2026  Prüfer: ____________________

## Statuswerte

- **Bestanden – real ausgeführt**
- **Korrektur implementiert – Hardware-Nachtest offen**
- **Nicht ausgeführt – reale Hardware erforderlich**
- **Fehlgeschlagen / Abweichung**

Nur real beobachtete Prüfschritte sind als bestanden markiert. Keine echte
API-Adresse, Zugangsdaten, Token, NFC-UIDs oder Mitarbeiternamen dokumentieren.

| Bereich | Prüfschritt | Status | Ergebnis / Messwert |
| --- | --- | --- | --- |
| Hardware | ESP32-Terminal, LCD, rote/gelbe/grüne LED und Buzzer | Bestanden – real ausgeführt | Terminal startete; Anzeigen und akustische Rückmeldung funktionierten grundsätzlich. |
| Netzwerk | WLAN-Verbindung und HTTPS-Verbindung über das Internet zur entfernten TimeApp | Bestanden – real ausgeführt | API-Konfiguration wurde geladen. |
| HTTPS | Positiver Zertifikats- und Hostname-Test | Bestanden – real ausgeführt | Die konfigurierte HTTPS-Verbindung wurde akzeptiert. |
| Uhr | Aktuelle Europe/Berlin-Zeit im Bereitschaftsbild | Bestanden – real ausgeführt | Die frühere eingefrorene Config-Zeit trat nicht mehr auf. |
| Storage | LittleFS manuell im geschützten Portal formatiert und wieder eingebunden | Bestanden – real ausgeführt | Dateisystem war danach wieder nutzbar. |
| NFC | Tag im serverseitigen Lernmodus angelernt | Bestanden – real ausgeführt | Anlernen erfolgreich. |
| Buchung | Arbeitsbeginn und Arbeitsende | Bestanden – real ausgeführt | Beide Buchungen wurden erfolgreich bestätigt. |
| Anzeige | Positive Serverantwort, Ergebnisanzeige und Rückkehr ins Bereitschaftsbild | Bestanden – real ausgeführt | Grün erschien nach erfolgreicher Buchung; danach erschien wieder das aktuelle Bereitschaftsbild. |
| Signalisierung | Wartepiep vor synchronem HTTPS-Aufruf | Fehlgeschlagen / Abweichung | Der für ungefähr 60 ms vorgesehene Wartepiep blieb wegen des unmittelbar anschließenden synchronen HTTPS-Aufrufs hörbar, bis der Hauptloop wieder ausgeführt werden konnte. |
| Signalisierung | Nicht blockierende Vorlaufzeit, Gelb bis Serverentscheidung und strenge Grünbedingung | Korrektur implementiert – Hardware-Nachtest offen | Automatisiert geprüft; manueller Hör-, LED- und Buchungsnachtest nach erneutem Flash steht aus. |
| HTTPS negativ | Falscher Hostname, unbekannte CA, NTP-Timeout und kein HTTP-Fallback | Nicht ausgeführt – reale Hardware erforderlich |  |
| Netzwerk/Queue | WLAN-Abbruch während Scan, Offline-Queue, Nachsynchronisierung, Queue voll und Dateisystemfehler | Nicht ausgeführt – reale Hardware erforderlich |  |
| Trust | Rollback, Quarantäne und Recovery | Nicht ausgeführt – reale Hardware erforderlich |  |
| Queue | Sperre, authentifizierte Entsperrung und Dead Letter | Nicht ausgeführt – reale Hardware erforderlich |  |
| Stabilität | Stromausfall, Heap-/Stack-Langzeitwerte und 14-tägiger Dauerlauf | Nicht ausgeführt – reale Hardware erforderlich |  |

## Nachtest der Signalkorrektur

Vor Beginn des Pilotlaufs nach manuellem Flash prüfen:

1. Der kurze Wartepiep endet vor DNS, TCP, TLS und HTTP.
2. Gelb bleibt von der sicheren NFC-Erkennung bis zur vollständigen Serverentscheidung aktiv.
3. Grün und das Erfolgsmuster erscheinen nur bei HTTP-2xx, vollständig gelesener und gültiger JSON-Antwort mit `ok: true`.
4. `ok: false`, fehlendes `ok`, Nicht-2xx, Netzwerk-/TLS-Fehler und eine lokale Offline-Speicherung erzeugen niemals Grün oder einen Erfolgston.
5. Die Ergebnisanzeige bleibt für das vollständige serverseitige `hold_ms` sichtbar; anschließend erscheint die aktuelle Uhr.

## Abnahmeentscheidung

- [ ] Werkbanktest bestanden
- [ ] Pilotbetrieb freigegeben
- [ ] Nicht freigegeben; Fehlerreferenz: ____________________
