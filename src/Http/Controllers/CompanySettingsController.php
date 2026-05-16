<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Settings\CompanySettingsService;
use App\Domain\Settings\SmtpTestService;
use App\Domain\Auth\CsrfService;
use App\Http\Request;
use App\Http\Response;
use App\Presentation\Admin\AdminView;
use RuntimeException;

final class CompanySettingsController
{
    public function __construct(
        private AdminView $view,
        private CompanySettingsService $companySettingsService,
        private SmtpTestService $smtpTestService,
        private CsrfService $csrfService,
        private array $mapConfig = []
    ) {
    }

    public function show(Request $request): Response
    {
        $settings = $this->companySettingsService->current();
        $notice = $this->notice($request);
        $settingsTabs = $this->settingsTabs('company');
        $csrfToken = $this->escape($this->csrfService->token());
        $smtpBadgeClass = match ((string) ($settings['smtp_last_test_status'] ?? 'untested')) {
            'success' => 'ok',
            'error' => 'warn',
            default => 'warn',
        };

        $logoInfo = $this->fileInfo($settings, 'logo');
        $agbInfo = $this->fileInfo($settings, 'agb_pdf');
        $privacyInfo = $this->fileInfo($settings, 'datenschutz_pdf');
        $geoEnabled = ((int) ($settings['geo_capture_enabled'] ?? 0) === 1) ? 'checked' : '';
        $geoAck = ((int) ($settings['geo_requires_acknowledgement'] ?? 0) === 1) ? 'checked' : '';
        $smtpPasswordStored = (bool) ($settings['smtp_password_is_set'] ?? false);
        $smtpPasswordHint = $smtpPasswordStored
            ? '<p class="muted">Ein SMTP-Passwort ist gespeichert. Leer lassen zum Beibehalten; neues Passwort eintragen, um es zu ersetzen.</p>'
            : '<p class="muted">Noch kein SMTP-Passwort gespeichert. Ein neues Passwort wird verschluesselt abgelegt.</p>';
        $smtpPasswordPlaceholder = $this->escape($smtpPasswordStored ? 'Passwort ist gespeichert' : 'SMTP-Passwort');
        $logoExisting = trim((string) ($settings['logo_original_name'] ?? '')) !== '' ? '1' : '0';
        $agbExisting = trim((string) ($settings['agb_pdf_original_name'] ?? '')) !== '' ? '1' : '0';
        $privacyExisting = trim((string) ($settings['datenschutz_pdf_original_name'] ?? '')) !== '' ? '1' : '0';
        $geoLatitude = $this->field($settings, 'geo_company_latitude');
        $geoLongitude = $this->field($settings, 'geo_company_longitude');
        $geoLabel = $this->field($settings, 'geo_company_location_label');
        $geoGeocodedAt = trim((string) ($settings['geo_company_geocoded_at'] ?? ''));
        $geoAddress = $this->escape($this->companyAddress($settings));
        $mapTileUrl = $this->escape((string) ($this->mapConfig['tile_url'] ?? 'https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png'));
        $mapAttribution = $this->escape((string) ($this->mapConfig['tile_attribution'] ?? '&copy; OpenStreetMap-Mitwirkende'));
        $mapGeocodeUrl = $this->escape((string) ($this->mapConfig['geocode_url'] ?? 'https://nominatim.openstreetmap.org/search'));
        $geoMeta = $geoGeocodedAt !== ''
            ? '<p class="muted">Letzte Standortsuche: ' . $this->escape($geoGeocodedAt) . '</p>'
            : '<p class="muted">Noch kein Firmenstandort auf der Karte gespeichert.</p>';

        $content = <<<HTML
<header class="page-header">
    <div>
        <p class="eyebrow">Anwendungs-Settings</p>
        <h1>Firma, Rechtstexte, SMTP und GEO</h1>
        <p>Zentrales Firmenprofil fuer Backend, E-Mail, Reports und spaeteres Frontend.</p>
    </div>
</header>

{$settingsTabs}
{$notice}

<section class="grid cards settings-health-grid" data-settings-summary>
    <article class="card metric">
        <h2>Ausgefuellt</h2>
        <p data-settings-count="complete">0</p>
    </article>
    <article class="card metric">
        <h2>Offen</h2>
        <p data-settings-count="optional">0</p>
    </article>
    <article class="card metric">
        <h2>Fehlt</h2>
        <p data-settings-count="missing">0</p>
    </article>
    <article class="card status-card">
        <h2>Pruefstatus</h2>
        <p class="status-value"><span class="badge warn" data-settings-overall>Wird geprueft</span></p>
        <p class="muted" data-settings-overall-text>Die Feldpruefung wird geladen.</p>
    </article>
</section>

<section class="card stack">
    <div class="section-toolbar">
        <div>
            <h2>Logo</h2>
            <p class="muted">Wird separat gespeichert und bleibt geschuetzt ausserhalb des Webroots.</p>
        </div>
    </div>
    <form method="post" action="/admin/settings/company/logo" enctype="multipart/form-data" class="stack" data-logo-upload-form>
        <input type="hidden" name="csrf_token" value="{$csrfToken}">
        <div class="field-group settings-field" data-settings-field data-field-kind="file" data-has-existing-file="{$logoExisting}">
            <span>Firmenlogo</span>
            {$logoInfo}
            <input type="file" name="logo" accept=".png,.jpg,.jpeg,.webp,image/png,image/jpeg,image/webp" data-logo-upload-input>
        </div>
        <div class="toolbar-actions">
            <button class="button" type="submit">Logo speichern</button>
        </div>
    </form>
</section>

<section class="card stack">
    <div class="section-toolbar">
        <div>
            <h2>Rechtstexte</h2>
            <p class="muted">Texte und PDFs werden separat gespeichert, ohne andere Settings zu veraendern.</p>
        </div>
    </div>
    <div class="form-grid">
        <form method="post" action="/admin/settings/company/agb-text" class="full-span stack">
            <input type="hidden" name="csrf_token" value="{$csrfToken}">
            <label class="settings-field" data-settings-field><span>AGB Text</span><textarea name="agb_text" rows="10">{$this->field($settings, 'agb_text')}</textarea></label>
            <div class="toolbar-actions">
                <button class="button" type="submit">AGB Text speichern</button>
            </div>
        </form>
        <form method="post" action="/admin/settings/company/agb-pdf" enctype="multipart/form-data" class="full-span stack" data-file-upload-form>
            <input type="hidden" name="csrf_token" value="{$csrfToken}">
            <div class="field-group settings-field" data-settings-field data-field-kind="file" data-has-existing-file="{$agbExisting}">
                <span>AGB PDF</span>
                {$agbInfo}
                <input type="file" name="agb_pdf" accept=".pdf,application/pdf" data-file-upload-input>
            </div>
            <div class="toolbar-actions">
                <button class="button" type="submit">AGB PDF speichern</button>
            </div>
        </form>
        <form method="post" action="/admin/settings/company/datenschutz-text" class="full-span stack">
            <input type="hidden" name="csrf_token" value="{$csrfToken}">
            <label class="settings-field" data-settings-field><span>Datenschutz Text</span><textarea name="datenschutz_text" rows="10">{$this->field($settings, 'datenschutz_text')}</textarea></label>
            <div class="toolbar-actions">
                <button class="button" type="submit">Datenschutz Text speichern</button>
            </div>
        </form>
        <form method="post" action="/admin/settings/company/datenschutz-pdf" enctype="multipart/form-data" class="full-span stack" data-file-upload-form>
            <input type="hidden" name="csrf_token" value="{$csrfToken}">
            <div class="field-group settings-field" data-settings-field data-field-kind="file" data-has-existing-file="{$privacyExisting}">
                <span>Datenschutz PDF</span>
                {$privacyInfo}
                <input type="file" name="datenschutz_pdf" accept=".pdf,application/pdf" data-file-upload-input>
            </div>
            <div class="toolbar-actions">
                <button class="button" type="submit">Datenschutz PDF speichern</button>
            </div>
        </form>
    </div>
</section>

<form method="post" action="/admin/settings/company" enctype="multipart/form-data" class="stack" data-settings-form>
    <input type="hidden" name="csrf_token" value="{$csrfToken}">
    <section class="card stack">
        <div class="section-toolbar">
            <div>
                <h2>Firma</h2>
                <p class="muted">Rechtlich und betrieblich relevante Stammdaten.</p>
            </div>
            <span class="badge warn" data-settings-section-badge>Wird geprueft</span>
        </div>
        <div class="form-grid">
            <label class="settings-field" data-settings-field><span>App-Name</span><input name="app_display_name" value="{$this->field($settings, 'app_display_name')}" placeholder="Baustellen Zeiterfassung"></label>
            <label class="settings-field" data-settings-field data-required="true"><span>Firmenname</span><input name="company_name" value="{$this->field($settings, 'company_name')}" required></label>
            <label class="settings-field" data-settings-field><span>Rechtsform</span><input name="legal_form" value="{$this->field($settings, 'legal_form')}"></label>
            <label class="settings-field" data-settings-field data-required="true"><span>Strasse</span><input name="street" value="{$this->field($settings, 'street')}"></label>
            <label class="settings-field" data-settings-field data-required="true"><span>Hausnummer</span><input name="house_number" value="{$this->field($settings, 'house_number')}"></label>
            <label class="settings-field" data-settings-field data-required="true"><span>PLZ</span><input name="postal_code" value="{$this->field($settings, 'postal_code')}"></label>
            <label class="settings-field" data-settings-field data-required="true"><span>Ort</span><input name="city" value="{$this->field($settings, 'city')}"></label>
            <label class="settings-field" data-settings-field data-required="true"><span>Land</span><input name="country" value="{$this->field($settings, 'country')}" required></label>
            <label class="settings-field" data-settings-field data-required="true"><span>Allgemeine E-Mail</span><input type="email" name="email" value="{$this->field($settings, 'email')}" required></label>
            <label class="settings-field" data-settings-field data-required="true"><span>Telefon</span><input name="phone" value="{$this->field($settings, 'phone')}"></label>
            <label class="settings-field" data-settings-field><span>Website</span><input name="website" value="{$this->field($settings, 'website')}"></label>
            <label class="settings-field" data-settings-field data-required="true"><span>Vertretungsberechtigte Person</span><input name="managing_director" value="{$this->field($settings, 'managing_director')}"></label>
            <label class="settings-field" data-settings-field><span>Registergericht</span><input name="register_court" value="{$this->field($settings, 'register_court')}"></label>
            <label class="settings-field" data-settings-field><span>Handelsregister</span><input name="commercial_register" value="{$this->field($settings, 'commercial_register')}"></label>
            <label class="settings-field" data-settings-field data-required="true"><span>Umsatzsteuer-ID</span><input name="vat_id" value="{$this->field($settings, 'vat_id')}"></label>
            <label class="settings-field" data-settings-field data-required="true"><span>Steuernummer</span><input name="tax_number" value="{$this->field($settings, 'tax_number')}"></label>
        </div>
    </section>

    <div class="toolbar-actions">
        <button class="button" type="submit">Settings speichern</button>
    </div>
</form>

<section class="card stack">
    <form method="post" action="/admin/settings/company/smtp" class="stack" data-settings-form>
        <input type="hidden" name="csrf_token" value="{$csrfToken}">
        <div class="section-toolbar">
            <div>
                <h2>SMTP</h2>
                <p class="muted">Versandkonfiguration separat speichern, danach gezielt testen.</p>
            </div>
            <div class="status-inline">
                <span class="badge {$smtpBadgeClass}">{$this->escape((string) ($settings['smtp_last_test_status'] ?? 'untested'))}</span>
                <span class="muted">Letzter Test: {$this->escape((string) ($settings['smtp_last_tested_at'] ?? 'noch nicht getestet'))}</span>
            </div>
            <span class="badge warn" data-settings-section-badge>Wird geprueft</span>
        </div>
        <div class="form-grid">
            <label class="settings-field" data-settings-field data-required="true"><span>SMTP Host</span><input name="smtp_host" value="{$this->field($settings, 'smtp_host')}" required></label>
            <label class="settings-field" data-settings-field data-required="true"><span>Port</span><input type="number" name="smtp_port" min="1" max="65535" value="{$this->field($settings, 'smtp_port')}" required></label>
            <label class="settings-field" data-settings-field><span>Benutzername</span><input name="smtp_username" value="{$this->field($settings, 'smtp_username')}"></label>
            <label class="settings-field" data-settings-field data-field-kind="secret" data-secret-set="{$this->escape($smtpPasswordStored ? '1' : '0')}" data-state-label-complete="Gespeichert"><span>Passwort</span><input type="password" name="smtp_password" value="" placeholder="{$smtpPasswordPlaceholder}" autocomplete="off"></label>
            <label class="settings-field" data-settings-field data-required="true"><span>Verschluesselung</span>{$this->select('smtp_encryption', ['tls' => 'TLS / STARTTLS', 'ssl' => 'SSL', 'none' => 'Keine'], (string) ($settings['smtp_encryption'] ?? 'tls'))}</label>
            <label class="settings-field" data-settings-field data-required="true"><span>Absendername</span><input name="smtp_from_name" value="{$this->field($settings, 'smtp_from_name')}" required></label>
            <label class="settings-field" data-settings-field data-required="true"><span>Absender-E-Mail</span><input type="email" name="smtp_from_email" value="{$this->field($settings, 'smtp_from_email')}" required></label>
            <label class="settings-field" data-settings-field><span>Reply-To E-Mail</span><input type="email" name="smtp_reply_to_email" value="{$this->field($settings, 'smtp_reply_to_email')}"></label>
            <div class="full-span">{$smtpPasswordHint}</div>
        </div>
        <div class="notice info">{$this->escape((string) ($settings['smtp_last_test_message'] ?? 'Es wurde noch kein SMTP-Test durchgefuehrt.'))}</div>
        <div class="toolbar-actions">
            <button class="button" type="submit">SMTP speichern</button>
        </div>
    </form>
</section>

<section class="card stack">
    <div class="section-toolbar">
        <div>
            <h2>SMTP-Testversand</h2>
            <p class="muted">Verwendet ausschliesslich die gespeicherten SMTP-Daten.</p>
        </div>
    </div>
    <form method="post" action="/admin/settings/company/smtp-test" class="inline-form">
        <input type="hidden" name="csrf_token" value="{$csrfToken}">
        <input type="email" name="smtp_test_recipient" placeholder="test@example.com" required>
        <button class="button" type="submit">Test-E-Mail senden</button>
    </form>
</section>

<section class="card stack">
    <form method="post" action="/admin/settings/company/geo" class="stack" data-settings-form>
        <input type="hidden" name="csrf_token" value="{$csrfToken}">
        <div class="section-toolbar">
            <div>
                <h2>Standort / GEO</h2>
                <p class="muted">Firmenstandort fuer Karten und Vorbereitung spaeterer Buchungspositionen.</p>
            </div>
            <span class="badge warn" data-settings-section-badge>Wird geprueft</span>
        </div>
        <div class="form-grid">
            <label class="checkbox-item"><input type="hidden" name="geo_capture_enabled" value="0"><input type="checkbox" name="geo_capture_enabled" value="1" {$geoEnabled}> <span>GEO-Erfassung im spaeteren Frontend aktivieren</span></label>
            <label class="checkbox-item"><input type="hidden" name="geo_requires_acknowledgement" value="0"><input type="checkbox" name="geo_requires_acknowledgement" value="1" {$geoAck}> <span>Vor Nutzung ist eine ausdrueckliche Bestaetigung erforderlich</span></label>
            <label class="full-span settings-field" data-settings-field><span>Hinweistext fuer GEO-Erfassung</span><textarea name="geo_notice_text" rows="6">{$this->field($settings, 'geo_notice_text')}</textarea></label>
        </div>
        <div class="geo-map-panel"
            data-company-geo-map
            data-tile-url="{$mapTileUrl}"
            data-tile-attribution="{$mapAttribution}"
            data-geocode-url="{$mapGeocodeUrl}"
            data-address="{$geoAddress}">
            <div class="geo-map-toolbar">
                <div>
                    <strong>Firmenstandort</strong>
                    {$geoMeta}
                </div>
                <button class="button button-secondary" type="button" data-geo-search>Adresse suchen</button>
            </div>
            <div class="geo-map-canvas" data-geo-map-canvas aria-label="Karte fuer Firmenstandort"></div>
            <p class="muted" data-geo-map-message>Marker setzen oder Adresse suchen.</p>
        </div>
        <div class="form-grid">
            <label class="settings-field" data-settings-field><span>Latitude</span><input name="geo_company_latitude" inputmode="decimal" value="{$geoLatitude}" data-geo-latitude></label>
            <label class="settings-field" data-settings-field><span>Longitude</span><input name="geo_company_longitude" inputmode="decimal" value="{$geoLongitude}" data-geo-longitude></label>
            <label class="full-span settings-field" data-settings-field><span>Standort-Label</span><input name="geo_company_location_label" value="{$geoLabel}" data-geo-label></label>
        </div>
        <div class="toolbar-actions">
            <button class="button" type="submit">GEO speichern</button>
        </div>
    </form>
</section>
HTML;

        return Response::html($this->view->render('Settings', $content, '<script src="/assets/vendor/leaflet/leaflet.js"></script><script src="/assets/js/admin-geo-map.js"></script>'));
    }

    public function save(Request $request): Response
    {
        if (!$this->hasValidCsrfToken($request)) {
            return Response::redirect('/admin/settings/company?error=' . rawurlencode('Die Aktion konnte nicht bestaetigt werden. Bitte Seite neu laden.'));
        }

        try {
            $this->companySettingsService->save($request->input(), []);

            return Response::redirect('/admin/settings/company?notice=saved');
        } catch (RuntimeException $exception) {
            return Response::redirect('/admin/settings/company?error=' . rawurlencode($exception->getMessage()));
        }
    }

    public function saveLogo(Request $request): Response
    {
        if (!$this->hasValidCsrfToken($request)) {
            return Response::redirect('/admin/settings/company?error=' . rawurlencode('Die Aktion konnte nicht bestaetigt werden. Bitte Seite neu laden.'));
        }

        try {
            $file = $request->files()['logo'] ?? null;

            if (!is_array($file)) {
                throw new RuntimeException('Bitte eine Logo-Datei auswaehlen.');
            }

            $this->companySettingsService->saveLogo($file);

            return Response::redirect('/admin/settings/company?notice=logo-saved');
        } catch (RuntimeException $exception) {
            return Response::redirect('/admin/settings/company?error=' . rawurlencode($exception->getMessage()));
        }
    }

    public function saveAgbText(Request $request): Response
    {
        if (!$this->hasValidCsrfToken($request)) {
            return Response::redirect('/admin/settings/company?error=' . rawurlencode('Die Aktion konnte nicht bestaetigt werden. Bitte Seite neu laden.'));
        }

        try {
            $this->companySettingsService->saveAgbText((string) $request->input('agb_text', ''));

            return Response::redirect('/admin/settings/company?notice=agb-text-saved');
        } catch (RuntimeException $exception) {
            return Response::redirect('/admin/settings/company?error=' . rawurlencode($exception->getMessage()));
        }
    }

    public function saveAgbPdf(Request $request): Response
    {
        if (!$this->hasValidCsrfToken($request)) {
            return Response::redirect('/admin/settings/company?error=' . rawurlencode('Die Aktion konnte nicht bestaetigt werden. Bitte Seite neu laden.'));
        }

        try {
            $file = $request->files()['agb_pdf'] ?? null;

            if (!is_array($file)) {
                throw new RuntimeException('Bitte eine AGB-PDF auswaehlen.');
            }

            $this->companySettingsService->saveAgbPdf($file);

            return Response::redirect('/admin/settings/company?notice=agb-pdf-saved');
        } catch (RuntimeException $exception) {
            return Response::redirect('/admin/settings/company?error=' . rawurlencode($exception->getMessage()));
        }
    }

    public function saveDatenschutzText(Request $request): Response
    {
        if (!$this->hasValidCsrfToken($request)) {
            return Response::redirect('/admin/settings/company?error=' . rawurlencode('Die Aktion konnte nicht bestaetigt werden. Bitte Seite neu laden.'));
        }

        try {
            $this->companySettingsService->saveDatenschutzText((string) $request->input('datenschutz_text', ''));

            return Response::redirect('/admin/settings/company?notice=datenschutz-text-saved');
        } catch (RuntimeException $exception) {
            return Response::redirect('/admin/settings/company?error=' . rawurlencode($exception->getMessage()));
        }
    }

    public function saveDatenschutzPdf(Request $request): Response
    {
        if (!$this->hasValidCsrfToken($request)) {
            return Response::redirect('/admin/settings/company?error=' . rawurlencode('Die Aktion konnte nicht bestaetigt werden. Bitte Seite neu laden.'));
        }

        try {
            $file = $request->files()['datenschutz_pdf'] ?? null;

            if (!is_array($file)) {
                throw new RuntimeException('Bitte eine Datenschutz-PDF auswaehlen.');
            }

            $this->companySettingsService->saveDatenschutzPdf($file);

            return Response::redirect('/admin/settings/company?notice=datenschutz-pdf-saved');
        } catch (RuntimeException $exception) {
            return Response::redirect('/admin/settings/company?error=' . rawurlencode($exception->getMessage()));
        }
    }

    public function saveSmtp(Request $request): Response
    {
        if (!$this->hasValidCsrfToken($request)) {
            return Response::redirect('/admin/settings/company?error=' . rawurlencode('Die Aktion konnte nicht bestaetigt werden. Bitte Seite neu laden.'));
        }

        try {
            $this->companySettingsService->saveSmtpSettings($request->input());

            return Response::redirect('/admin/settings/company?notice=smtp-saved');
        } catch (RuntimeException $exception) {
            return Response::redirect('/admin/settings/company?error=' . rawurlencode($exception->getMessage()));
        }
    }

    public function saveGeo(Request $request): Response
    {
        if (!$this->hasValidCsrfToken($request)) {
            return Response::redirect('/admin/settings/company?error=' . rawurlencode('Die Aktion konnte nicht bestaetigt werden. Bitte Seite neu laden.'));
        }

        try {
            $this->companySettingsService->saveGeoSettings($request->input());

            return Response::redirect('/admin/settings/company?notice=geo-saved');
        } catch (RuntimeException $exception) {
            return Response::redirect('/admin/settings/company?error=' . rawurlencode($exception->getMessage()));
        }
    }

    public function smtpTest(Request $request): Response
    {
        if (!$this->hasValidCsrfToken($request)) {
            return Response::redirect('/admin/settings/company?error=' . rawurlencode('Die Aktion konnte nicht bestaetigt werden. Bitte Seite neu laden.'));
        }

        try {
            $settings = $this->companySettingsService->smtpSettingsForTest();
            $result = $this->smtpTestService->sendTestMail(
                $settings,
                (string) $request->input('smtp_test_recipient', '')
            );
        } catch (RuntimeException $exception) {
            $result = ['ok' => false, 'message' => $exception->getMessage()];
        }

        $this->companySettingsService->recordSmtpTest($result['ok'], $result['message']);

        return $result['ok']
            ? Response::redirect('/admin/settings/company?notice=smtp-tested')
            : Response::redirect('/admin/settings/company?error=' . rawurlencode($result['message']));
    }

    public function publicProfile(Request $request): Response
    {
        return Response::json(['data' => $this->companySettingsService->publicProfile()]);
    }

    public function publicLogo(Request $request): Response
    {
        $logo = $this->companySettingsService->publicLogoFile();

        if ($logo === null) {
            return new Response('Logo nicht gefunden.', 404, ['Content-Type' => 'text/plain; charset=utf-8']);
        }

        $content = file_get_contents((string) $logo['path']);

        if ($content === false) {
            return new Response('Logo nicht gefunden.', 404, ['Content-Type' => 'text/plain; charset=utf-8']);
        }

        return new Response($content, 200, [
            'Content-Type' => (string) $logo['mime_type'],
            'Content-Length' => (string) strlen($content),
            'Cache-Control' => 'public, max-age=3600',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function previewAgbPdf(Request $request): Response
    {
        return $this->protectedPdfResponse('agb_pdf', true);
    }

    public function downloadAgbPdf(Request $request): Response
    {
        return $this->protectedPdfResponse('agb_pdf', false);
    }

    public function previewDatenschutzPdf(Request $request): Response
    {
        return $this->protectedPdfResponse('datenschutz_pdf', true);
    }

    public function downloadDatenschutzPdf(Request $request): Response
    {
        return $this->protectedPdfResponse('datenschutz_pdf', false);
    }

    private function settingsTabs(string $active): string
    {
        $companyClass = $active === 'company' ? 'scope-link is-active' : 'scope-link';
        $databaseClass = $active === 'database' ? 'scope-link is-active' : 'scope-link';
        $pushClass = $active === 'push' ? 'scope-link is-active' : 'scope-link';

        return '<section class="section-toolbar"><div class="scope-switch">'
            . '<a class="' . $companyClass . '" href="/admin/settings/company">Settings</a>'
            . '<a class="' . $databaseClass . '" href="/admin/settings/database">Datenbank</a>'
            . '<a class="' . $pushClass . '" href="/admin/settings/push">Push</a>'
            . '</div></section>';
    }

    private function hasValidCsrfToken(Request $request): bool
    {
        return $this->csrfService->isValid((string) $request->input('csrf_token', ''));
    }

    private function notice(Request $request): string
    {
        $notice = (string) $request->query('notice', '');
        $error = (string) $request->query('error', '');

        if ($error !== '') {
            return '<p class="notice error">' . $this->escape(urldecode($error)) . '</p>';
        }

        if ($notice === '') {
            return '';
        }

        $message = match ($notice) {
            'saved' => 'Die globalen Settings wurden erfolgreich gespeichert.',
            'logo-saved' => 'Das Firmenlogo wurde erfolgreich gespeichert.',
            'agb-text-saved' => 'Der AGB-Text wurde erfolgreich gespeichert.',
            'agb-pdf-saved' => 'Die AGB-PDF wurde erfolgreich gespeichert.',
            'datenschutz-text-saved' => 'Der Datenschutztext wurde erfolgreich gespeichert.',
            'datenschutz-pdf-saved' => 'Die Datenschutz-PDF wurde erfolgreich gespeichert.',
            'smtp-saved' => 'Die SMTP-Settings wurden gespeichert. Bitte jetzt den Testversand ausfuehren.',
            'geo-saved' => 'Die GEO-Settings und der Firmenstandort wurden gespeichert.',
            'smtp-tested' => 'Die SMTP-Testmail wurde erfolgreich versendet.',
            default => 'Die Settings wurden aktualisiert.',
        };

        return '<p class="notice success">' . $this->escape($message) . '</p>';
    }

    private function fileInfo(array $settings, string $prefix): string
    {
        $name = trim((string) ($settings[$prefix . '_original_name'] ?? ''));
        $size = (string) ($settings[$prefix . '_size_bytes'] ?? '');

        if ($name === '') {
            return '<p class="muted">Noch keine Datei hinterlegt.</p>';
        }

        $links = $this->settingsFileLinks($prefix);
        $fileIsAvailable = $links !== null && $this->companySettingsService->protectedPdfFile($prefix) !== null;
        $label = $this->settingsFileLabel($prefix);
        $linkMarkup = $links === null
            ? ''
            : ($fileIsAvailable
                ? ' <span class="table-actions">'
                . '<a class="button button-secondary" href="' . $this->escape($links['preview']) . '" target="_blank" rel="noopener" aria-label="' . $this->escape($label . ' anzeigen') . '">Anzeigen</a>'
                . '<a class="button button-secondary" href="' . $this->escape($links['download']) . '" aria-label="' . $this->escape($label . ' herunterladen') . '">Download</a>'
                . '</span>'
                : ' <span class="badge warn">Datei nicht abrufbar</span>');

        return '<p class="muted">Aktuell hinterlegt: ' . $this->escape($name) . ($size !== '' ? ' (' . $this->escape($size) . ' Bytes)' : '') . $linkMarkup . '</p>';
    }

    private function protectedPdfResponse(string $prefix, bool $inline): Response
    {
        $file = $this->companySettingsService->protectedPdfFile($prefix);

        if ($file === null) {
            return new Response('Datei nicht gefunden.', 404, ['Content-Type' => 'text/plain; charset=utf-8']);
        }

        $content = file_get_contents((string) $file['path']);

        if ($content === false) {
            return new Response('Datei nicht gefunden.', 404, ['Content-Type' => 'text/plain; charset=utf-8']);
        }

        $filename = preg_replace('/[^a-zA-Z0-9._-]/', '-', (string) ($file['original_name'] ?? '')) ?: 'settings.pdf';
        $disposition = ($inline ? 'inline' : 'attachment') . '; filename="' . $filename . '"';

        return new Response($content, 200, [
            'Content-Type' => (string) $file['mime_type'],
            'Content-Length' => (string) strlen($content),
            'Content-Disposition' => $disposition,
            'Cache-Control' => 'private, max-age=300',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    private function settingsFileLinks(string $prefix): ?array
    {
        return match ($prefix) {
            'agb_pdf' => [
                'preview' => '/admin/settings/company/agb-pdf/preview',
                'download' => '/admin/settings/company/agb-pdf/download',
            ],
            'datenschutz_pdf' => [
                'preview' => '/admin/settings/company/datenschutz-pdf/preview',
                'download' => '/admin/settings/company/datenschutz-pdf/download',
            ],
            default => null,
        };
    }

    private function settingsFileLabel(string $prefix): string
    {
        return match ($prefix) {
            'agb_pdf' => 'AGB-PDF',
            'datenschutz_pdf' => 'Datenschutz-PDF',
            default => 'Settings-Datei',
        };
    }

    private function companyAddress(array $settings): string
    {
        $streetLine = trim(implode(' ', array_filter([
            trim((string) ($settings['street'] ?? '')),
            trim((string) ($settings['house_number'] ?? '')),
        ])));
        $cityLine = trim(implode(' ', array_filter([
            trim((string) ($settings['postal_code'] ?? '')),
            trim((string) ($settings['city'] ?? '')),
        ])));

        return trim(implode(', ', array_filter([
            $streetLine,
            $cityLine,
            trim((string) ($settings['country'] ?? 'Deutschland')),
        ])));
    }

    private function select(string $name, array $options, string $selected): string
    {
        $html = '<select name="' . $this->escape($name) . '">';

        foreach ($options as $value => $label) {
            $isSelected = $value === $selected ? ' selected' : '';
            $html .= '<option value="' . $this->escape($value) . '"' . $isSelected . '>' . $this->escape($label) . '</option>';
        }

        $html .= '</select>';

        return $html;
    }

    private function field(array $settings, string $key): string
    {
        return $this->escape((string) ($settings[$key] ?? ''));
    }

    private function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    }
}
