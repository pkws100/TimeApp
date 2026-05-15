# Third-Party Notices

Dieses Projekt wird unter der GNU General Public License Version 2 oder spaeter
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

- `mpdf/mpdf`: Die Composer-/Packagist-Metadaten weisen `GPL-2.0-only` aus.
  Die Projektlizenz `GPL-2.0-or-later` erlaubt eine Nutzung unter GPLv2 und ist
  damit auf diese Abhaengigkeit ausgerichtet. Bei einer Weitergabe zusammen mit
  mPDF sind die GPLv2-Bedingungen fuer die kombinierte Auslieferung zu beachten.

## JavaScript- und UI-Abhaengigkeiten

Die Playwright-Abhaengigkeiten fuer UI-Smoke-Tests liegen in `package-lock.json`
und werden nicht mit der Produktionsanwendung ausgeliefert.

## Vendored Browser Assets

Leaflet 1.9.4 liegt unter `public/assets/vendor/leaflet/` als vendored Browser-
Asset im Repository. Die lokale Lizenznotiz befindet sich unter
`public/assets/vendor/leaflet/LICENSE`.
