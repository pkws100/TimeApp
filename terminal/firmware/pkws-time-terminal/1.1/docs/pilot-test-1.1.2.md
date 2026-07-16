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

## Beobachtete und bearbeitete Abweichung

Der ungefähr 60-ms-Wartepiep blieb beim vorherigen Stand hörbar, weil der
unmittelbar folgende synchrone HTTPS-Aufruf den Hauptloop blockierte. Die
Firmware enthält jetzt eine nicht blockierende Vorlaufzeit von 100 ms. Gelb
bleibt bis zur Serverentscheidung aktiv; Grün ist nur bei einer bestätigten
Serverbuchung erlaubt. Der reale Nachtest nach erneutem Flash ist noch offen.

## Vor dem 14-Tage-Lauf noch prüfen

- Wartepiep endet vor dem HTTPS-Aufruf; der Buzzer bleibt während HTTPS still.
- Gelb bleibt während des vollständigen HTTPS-Vorgangs aktiv.
- Grün erst nach erfolgreicher Serverbestätigung; `ok: false` erzeugt niemals Grün.
- Fehlerantworten erzeugen Rot beziehungsweise die bestehende Fehlerbehandlung.
- Offline-Speicherung erzeugt niemals Grün oder einen Erfolgston.
- Ergebnisanzeige bleibt für das vollständige `hold_ms` sichtbar; danach erscheint die aktuelle Uhr.

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
