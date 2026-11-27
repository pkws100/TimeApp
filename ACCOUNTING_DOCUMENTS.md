# Abrechnungsdokumente

Unter `/admin/accounting` ergänzen Monatsabrechnungen und Mitarbeiterdokumente die Buchungsliste um Urlaub, Krankheit und Kernarbeitszeit. Die Vorschau zeigt dieselben Mitarbeiterwerte wie PDF und Excel. Das ZIP enthält weiterhin Gesamtdokumente und getrennte Mitarbeiter- und Projektdokumente.

Eine Monatsübersicht ohne Mitarbeiter- oder Projektfilter schließt auch aktive zeiterfassungspflichtige Mitarbeiter ohne Buchungen ein. Ein ausdrücklich ausgewählter Mitarbeiter erhält seine Kontenübersicht ebenfalls ohne Buchungen.

## Urlaub und Krankheit

- **Urlaub im Zeitraum** zählt bezahlte Urlaubstage im gewählten Abrechnungszeitraum bis zum Standdatum. Unbezahlter Urlaub wird separat ausgewiesen und verbraucht keinen Erholungsurlaub.
- **Krankheit im Zeitraum** zählt gebuchte Krankheitstage auf anrechenbaren Arbeitstagen. Die Abwesenheitsgutschrift ist von geleisteter Arbeitszeit getrennt.
- **Urlaub genommen bis Standdatum** zählt die vorhandenen bezahlten Urlaubsbuchungen des angegebenen Kalenderjahrs, auch vor einem unterjährigen Kontostart. Das Standdatum ist höchstens heute in `Europe/Berlin` und höchstens das Ende des gewählten Zeitraums. Der Kontoverbrauch seit dem Kontostart wird separat ausgewiesen, damit ein übernommener Restbestand nachvollziehbar bleibt.
- **Resturlaub am Standdatum** zieht den bereits genommenen Urlaub vom Jahresbudget ab. **Rest nach künftig gebuchtem Urlaub** berücksichtigt zusätzlich spätere genehmigte Buchungen. Offene Anträge werden im Zeitkontenexport separat berücksichtigt.
- Ohne verbindliche Kontogrundlage werden Urlaubswerte ausdrücklich als vorläufig gekennzeichnet. Ein Stichtagsbestand gilt ab seinem Einführungsdatum. Davor genommener Urlaub wird nicht erneut vom übernommenen Bestand abgezogen.

Beispiel ohne Übertrag oder Korrekturen bei 30 Tagen Jahresbudget, fünf genommenen und drei künftig gebuchten Urlaubstagen beträgt der Rest am Standdatum 25 Tage und nach künftig gebuchtem Urlaub 22 Tage.

## Kernarbeitszeit

Die Dokumente zeigen die datiert zugeordneten Regeln mit Version, Wochentagen, Zeitfenstern, Zeitzone und Check-out-Modus. Eine global deaktivierte Regel wird entsprechend gekennzeichnet. Ohne Zuordnung erscheint ein Hinweis. Es werden durch den Export keine Regeln oder Mitarbeiterzuordnungen angelegt.

Die Aufteilung der Arbeitszeit innerhalb und außerhalb der Fenster bleibt eine Vorschau und ersetzt keine erfasste Arbeitszeit oder Überstundengenehmigung. Ungeklärte Zeiten und Pausen verhindern weiterhin eine verbindliche Festschreibung.

## Festschreibung und Zugriff

Neue Festschreibungen speichern die zusätzlichen Nachweise einschließlich Urlaubsstand und Regelversionen in `accounting_closures.document_summary_json`. Der Abschlusshash berücksichtigt diesen Snapshot. Spätere Änderungen an Mitarbeiterstammdaten, Konten oder Zuordnungen ändern die festgeschriebenen Werte nicht.

Ältere Abschlüsse ohne diesen Snapshot bleiben lesbar. Ihre zusätzlichen Urlaubskonten und Krankheitsübersichten werden als historisch nicht verfügbar gekennzeichnet und nicht aus heutigen Daten rekonstruiert. Die additive Migration ist ausschließlich vorwärts ausführbar. Ein automatischer Schema-Rollback wird abgewiesen, auch wenn noch keine Nachweise gespeichert wurden.

Projektbezogene Dokumente enthalten die Buchungen und deren zugehörige Arbeitszeitregel, aber keine persönlichen Jahresurlaubskonten oder projektfremden Krankheitsdaten. Mitarbeiterdateien im ZIP werden anhand der Benutzer-ID getrennt, auch bei gleichen Namen oder Personalnummern. Die bestehenden Exportrechte bleiben erforderlich.

## Zeitkontenexport

Unter `/admin/time-accounts` enthalten PDF, Excel und CSV zusätzlich datierte Urlaubs- und Kernarbeitszeitangaben. Vorhandene CSV- und Excel-Spalten behalten ihre Reihenfolge und Bedeutung. Die ergänzten Spalten unterscheiden genommenen, insgesamt gebuchten und künftig genehmigten Urlaub ausdrücklich. PDF gruppiert die Werte je Mitarbeiter in lesbare Abschnitte. Excel enthält Filter, fixierte Überschriften und Lesehinweise.
