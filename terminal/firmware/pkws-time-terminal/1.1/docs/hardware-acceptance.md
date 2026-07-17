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
| Signalisierung | Wartepiep vor synchronem HTTPS-Aufruf – historischer Stand | Fehlgeschlagen / Abweichung | Beim vorherigen Firmwarestand blieb der für ungefähr 60 ms vorgesehene Wartepiep wegen des unmittelbar anschließenden synchronen HTTPS-Aufrufs hörbar. Dieser Befund wurde durch die nachfolgende Korrektur abgelöst. |
| Signalisierung | Nicht blockierende Vorlaufzeit, Gelb bis Serverentscheidung und strenge Grünbedingung | Bestanden – real ausgeführt | Am 17.07.2026 vom Betreiber als erledigt bestätigt: Wartepiep endet vor der Serveranfrage; Gelb bleibt während der Serveranfrage aktiv; Grün und Erfolgston erscheinen erst nach bestätigter Buchung. |
| Bedienung | Sperrzeit während der Ergebnisanzeige und anschließende Buchungsbereitschaft | Bestanden – real ausgeführt | Test 5 am 17.07.2026 vom Betreiber als erfolgreich bestätigt. Während der konfigurierten Ergebnisanzeige wurde kein weiterer Tag verarbeitet; anschließend war das Terminal wieder bereit. |
| Fehlerfeedback | Unbekannter oder deaktivierter Tag | Bestanden – real ausgeführt | Test 6 am 17.07.2026 vom Betreiber als erfolgreich bestätigt: Rot und Fehlerton, kein Grün und kein Erfolgston. |
| NFC | Signalverhalten beim Anlernen | Bestanden – real ausgeführt | Test 7 am 17.07.2026 vom Betreiber als erfolgreich bestätigt: eindeutiger Lernstatus ohne Buchungs-Erfolgssignal. |
| Persistenz | Einstellungen nach Stromausfall beziehungsweise Neustart | Bestanden – real ausgeführt | Test 9 am 17.07.2026 vom Betreiber als erfolgreich bestätigt; Bereitschaftstexte und Uhr erschienen erneut korrekt. |
| HTTPS negativ | Falscher Hostname, unbekannte CA, NTP-Timeout und kein HTTP-Fallback | Nicht ausgeführt – reale Hardware erforderlich |  |
| Netzwerk/Queue | WLAN-Abbruch während Scan, Offline-Queue, Nachsynchronisierung, Queue voll und Dateisystemfehler | Nicht ausgeführt – reale Hardware erforderlich | Test 8 bleibt offen. |
| Trust | Rollback, Quarantäne und Recovery | Nicht ausgeführt – reale Hardware erforderlich |  |
| Queue | Sperre, authentifizierte Entsperrung und Dead Letter | Nicht ausgeführt – reale Hardware erforderlich |  |
| Stabilität | Stromausfall, Heap-/Stack-Langzeitwerte und 14-tägiger Dauerlauf | Nicht ausgeführt – reale Hardware erforderlich |  |

## Nachtest der Signalkorrektur

- [x] Der kurze Wartepiep endet vor DNS, TCP, TLS und HTTP.
- [x] Gelb bleibt von der sicheren NFC-Erkennung bis zur vollständigen Serverentscheidung aktiv.
- [x] Grün und das Erfolgsmuster erscheinen bei der bestätigten positiven Serverbuchung erst nach der Serverentscheidung.
- [x] Die positive Ergebnisanzeige bleibt für das konfigurierte serverseitige `hold_ms` sichtbar; anschließend erscheint das Bereitschaftsbild mit aktueller Uhr.
- [x] Fachliche Ablehnung durch unbekannten oder deaktivierten Tag erzeugt Rot und Fehlerton, niemals Grün oder Erfolgston.
- [ ] Fehlendes `ok`, weitere Nicht-2xx-Antworten, Netzwerk-/TLS-Fehler und lokale Offline-Speicherung separat prüfen; dabei dürfen niemals Grün oder Erfolgston erscheinen.

## Bestätigter Funktionsnachtest vom 17.07.2026

Die folgenden Prüfschritte wurden vom Betreiber als erledigt bestätigt:

- [x] Testwerte und erkennbare Bereitschafts-, Arbeitsbeginn- und Feierabendtexte je Terminal eingestellt.
- [x] Terminal neu gestartet; Bereitschaftsbild mit drei konfigurierten Zeilen und lokaler Uhr in Zeile vier geprüft.
- [x] Arbeitsbeginn mit Wartepiep, gelber Serverwartephase, anschließendem Grün/Erfolgston und gerenderter Vorlage geprüft.
- [x] Feierabend mit gleichem Signalablauf, gerenderter Feierabendvorlage und erfolgreicher Serverbuchung geprüft.
- [x] Test 5: Konfigurierte Ergebnis-Sperrzeit und anschließende erneute Buchungsbereitschaft geprüft.
- [x] Test 6: Fehlerfall mit unbekanntem oder deaktiviertem Tag, roter LED und Fehlerton ohne Erfolgssignal geprüft.
- [x] Test 7: NFC-Anlernen mit eindeutigem Lernstatus ohne Buchungs-Erfolgssignal geprüft.
- [ ] Test 8: WLAN-Abbruch, Offline-Speicherung und spätere Queue-Nachsynchronisierung noch offen.
- [x] Test 9: Einstellungen und Bereitschaftsbild nach Stromausfall beziehungsweise Neustart erneut geprüft.

Von den Funktionsprüfungen 1 bis 9 bleibt damit Test 8 offen. Die weitergehenden HTTPS-, Trust-, Queue-Sperr-, Recovery- und Langzeitprüfungen bleiben davon unberührt.

## Abnahmeentscheidung

- [ ] Werkbanktest bestanden
- [ ] Pilotbetrieb freigegeben
- [ ] Nicht freigegeben; Fehlerreferenz: ____________________
