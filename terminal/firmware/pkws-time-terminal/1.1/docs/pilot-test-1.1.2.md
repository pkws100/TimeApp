# Pilotprotokoll – Terminal-Firmware 1.1.2

## Pilotübersicht

| Feld | Wert |
| --- | --- |
| Firmware | `pkws-time-terminal-v1.1.2` |
| Pilotdauer geplant | 14 Kalendertage |
| Status | Vorbereitet, noch nicht abschließend bestanden |
| Gerät/Seriennummer | ____________________ |
| Startdatum | ____________________ |
| Enddatum | ____________________ |
| Prüfer | ____________________ |
| Teststandort | ____________________ |
| Art der Verbindung | ____________________ |
| Beteiligte Mitarbeiter | ____________________ |
| Firmware-Commit | ____________________ |
| SHA-256 der geflashten Binärdatei | ____________________ |

Keine Netzwerkadressen, Zugangsdaten, Token, NFC-UIDs oder personenbezogenen
Mitarbeiternamen eintragen.

## Bereits bestanden – real ausgeführt

- ESP32-Terminal, LCD, rote/gelbe/grüne LED und Buzzer grundsätzlich funktionsfähig.
- WLAN, entfernte HTTPS-TimeApp, API-Konfiguration sowie positiver Zertifikats- und Hostname-Test erfolgreich.
- Aktuelle Europe/Berlin-Zeit sichtbar; die eingefrorene Config-Zeit trat nicht mehr auf.
- LittleFS im geschützten Portal formatiert und wieder eingebunden.
- NFC-Tag angelernt sowie Arbeitsbeginn und Arbeitsende erfolgreich gebucht.
- Positive Serverantwort führte zu Grün, Ergebnisanzeige und korrekter Rückkehr in das Bereitschaftsbild.
- Am 17.07.2026 wurden die je Terminal konfigurierten Bereitschafts-, Arbeitsbeginn- und Feierabendtexte nach Neustart geprüft; die lokale Uhr erschien weiterhin in Zeile vier.
- Arbeitsbeginn und Feierabend wurden mit Wartepiep, gelber Serverwartephase und Grün/Erfolgston erst nach bestätigter Serverbuchung vom Betreiber als erledigt bestätigt.
- Test 5 bestanden: Während der konfigurierten Ergebnisanzeige wurde kein weiterer Tag verarbeitet; anschließend war das Terminal wieder buchungsbereit.
- Test 6 bestanden: Ein unbekannter oder deaktivierter Tag erzeugte Rot und Fehlerton, aber kein Grün und keinen Erfolgston.
- Test 7 bestanden: Das NFC-Anlernen zeigte einen eindeutigen Lernstatus ohne Buchungs-Erfolgssignal.
- Test 9 bestanden: Einstellungen, Bereitschaftstexte und lokale Uhr blieben nach Stromausfall beziehungsweise Neustart erhalten.

## Beobachtete und bearbeitete Abweichung

Der ungefähr 60-ms-Wartepiep blieb beim vorherigen Stand hörbar, weil der
unmittelbar folgende synchrone HTTPS-Aufruf den Hauptloop blockierte. Die
Firmware enthält jetzt einen 160-ms-Wartepiep und eine nicht blockierende Vorlaufzeit von 180 ms. Gelb
bleibt bis zur Serverentscheidung aktiv; Grün ist nur bei einer bestätigten
Serverbuchung erlaubt. Die positiven Funktionsnachtests der oben dokumentierten
Punkte 1 bis 7 sowie 9 wurden am 17.07.2026 vom Betreiber als erledigt bestätigt.
Test 8 mit WLAN-Abbruch, Offline-Speicherung und Queue-Nachsynchronisierung sowie
die vollständigen HTTPS-Negativ-, Recovery- und Langzeittests bleiben offen.

## Vor dem 14-Tage-Lauf noch prüfen

- Offline-Speicherung erzeugt niemals Grün oder einen Erfolgston; Queue-Nachsynchronisierung erzeugt keine fehlende oder doppelte Buchung.
- Fehlendes `ok`, weitere Nicht-2xx-Antworten sowie Netzwerk-/TLS-Fehler erzeugen niemals Grün oder einen Erfolgston.

## Während der 14 Tage erfassen

| Kennzahl | Wert / Bemerkung |
| --- | --- |
| Scanversuche; erfolgreiche Ein- und Ausbuchungen |  |
| Fachliche Ablehnungen; Netzwerk- und TLS-Fehler; Retries; WLAN-Reconnects |  |
| Queue-Einträge; nachsynchronisierte Einträge; Dead Letter; Queue-Sperren |  |
| Doppelte oder fehlende Buchungen; unerwartete Neustarts |  |
| Trust-Warnungen und Trust-Recovery |  |
| Free Heap; Minimum Free Heap; Stack High Water Mark |  |
| Durchschnittliche und längste Antwortzeit |  |
| Bedienbarkeit sowie LCD-, LED- und Buzzer-Auffälligkeiten |  |

## Vorläufige Antwortzeitbewertung

| Antwortzeit | Bewertung |
| --- | --- |
| Unter 2 Sekunden | Sehr gut |
| 2 bis 4 Sekunden | Für eine entfernte HTTPS-Verbindung gut vertretbar |
| 4 bis 5 Sekunden | Spürbar, weiter beobachten |
| Regelmäßig über 5 Sekunden | Optimierung untersuchen |
| Timeout oder Retry | Als Ereignis dokumentieren |

Diese Werte sind Bedienbarkeitsziele, keine Protokoll- oder Sicherheitsgrenzen.

## Abnahmekriterien nach 14 Tagen

Der Pilot kann als bestanden gelten, wenn keine Buchung verloren geht oder
ungewollt doppelt entsteht, Arbeitsbeginn/-ende korrekt wechseln, Datum und Uhr
korrekt bleiben, Reconnect und Offline-Nachsynchronisierung zuverlässig
arbeiten, kein unberechtigtes Grün erscheint, Gelb bis zur Bestätigung aktiv
bleibt, Fehler eindeutig signalisiert werden, der Wartepiep nicht als Dauerton
auftritt, Minimum Heap nicht fortlaufend absinkt, keine unerklärte Queue- oder
Trust-Sperre entsteht und das Terminal zuverlässig in den Bereitschaftszustand
zurückkehrt. Die Bedienbarkeit muss für die Mitarbeiter akzeptabel sein.

`Pilotbetrieb bestanden` darf erst nach vollständigem 14-Tage-Nachweis markiert
werden.
