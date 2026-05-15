# Third-Party Notices

Dieses Projekt wird unter der GNU General Public License Version 3 oder spaeter
veroeffentlicht. Zusaetzlich werden Drittkomponenten genutzt, deren jeweilige
Lizenzbedingungen zu beachten sind.

## PHP-Abhaengigkeiten

Die Composer-Abhaengigkeiten werden in `composer.lock` versioniert. Eine aktuelle
Uebersicht kann lokal erzeugt werden mit:

```bash
composer licenses
```

Die meisten direkten und transitiven Composer-Abhaengigkeiten sind unter MIT-
oder BSD-Lizenzen veroeffentlicht. Lizenzrelevant fuer die Copyleft-Bewertung ist
insbesondere:

- `mpdf/mpdf`: Die mPDF-Projektdokumentation beschreibt die Lizenz als GNU GPL
  Version 2 oder spaeter. Die Composer-/Packagist-Metadaten koennen je nach
  Version `GPL-2.0-only` ausweisen. Vor einer oeffentlichen Freigabe sollte diese
  Abweichung juristisch oder technisch abschliessend bewertet werden.

## JavaScript- und UI-Abhaengigkeiten

Die Playwright-Abhaengigkeiten fuer UI-Smoke-Tests liegen in `package-lock.json`
und werden nicht mit der Produktionsanwendung ausgeliefert.

## Vendored Browser Assets

Leaflet 1.9.4 liegt unter `public/assets/vendor/leaflet/` als vendored Browser-
Asset im Repository. Die lokale Lizenznotiz befindet sich unter
`public/assets/vendor/leaflet/LICENSE`.
