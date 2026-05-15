<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Auth\CsrfService;
use App\Domain\Push\PushNotificationService;
use App\Domain\Push\PushSettingsService;
use App\Domain\Push\PushSubscriptionService;
use App\Http\Request;
use App\Http\Response;
use App\Presentation\Admin\AdminView;

final class AdminPushController
{
    public function __construct(
        private AdminView $view,
        private PushSettingsService $settingsService,
        private PushSubscriptionService $subscriptionService,
        private PushNotificationService $notificationService,
        private CsrfService $csrfService
    ) {
    }

    public function show(Request $request): Response
    {
        $settings = $this->settingsService->current();
        $notice = $this->notice($request);
        $csrfToken = $this->escape($this->csrfService->token());
        $pushEnabled = $settings['enabled'] ? 'checked' : '';
        $reminderEnabled = $settings['reminder_enabled'] ? 'checked' : '';
        $vapidBadge = $settings['vapid_configured'] ? '<span class="badge ok">konfiguriert</span>' : '<span class="badge warn">fehlt</span>';
        $weekdayOptions = $this->weekdayCheckboxes($settings['reminder_weekdays'] ?? []);
        $deviceRows = $this->deviceRows($csrfToken);

        $content = <<<HTML
<header class="page-header">
    <div>
        <p class="eyebrow">Anwendungs-Settings</p>
        <h1>Browser-Push</h1>
        <p>Buchungs-Erinnerungen, Geraete und Versandstatus fuer die mobile App.</p>
    </div>
</header>

{$this->settingsTabs()}
{$notice}

<section class="card stack">
    <form method="post" action="/admin/settings/push" class="stack">
        <input type="hidden" name="csrf_token" value="{$csrfToken}">
        <div class="section-toolbar">
            <div>
                <h2>Erinnerungen</h2>
                <p class="muted">Der Versand erfolgt per CLI/Cron und nutzt nur aktive Geraete mit Push-Recht.</p>
            </div>
            {$vapidBadge}
        </div>
        <div class="form-grid">
            <label class="checkbox-item"><input type="hidden" name="enabled" value="0"><input type="checkbox" name="enabled" value="1" {$pushEnabled}> <span>Push global aktivieren</span></label>
            <label class="checkbox-item"><input type="hidden" name="reminder_enabled" value="0"><input type="checkbox" name="reminder_enabled" value="1" {$reminderEnabled}> <span>Buchungs-Erinnerung aktivieren</span></label>
            <label class="settings-field"><span>Erinnerungszeit</span><input type="time" name="reminder_time" value="{$this->escape((string) $settings['reminder_time'])}" required></label>
            <div class="field-group full-span">
                <span>Wochentage</span>
                <div class="checkbox-grid">{$weekdayOptions}</div>
            </div>
            <label class="settings-field full-span"><span>Hinweistext</span><textarea name="notice_text" rows="4">{$this->escape((string) $settings['notice_text'])}</textarea></label>
        </div>
        <div class="notice info">VAPID Public Key: {$this->escape($settings['vapid_public_key'] !== '' ? 'gesetzt' : 'nicht gesetzt')} · Subject: {$this->escape((string) $settings['vapid_subject'])}</div>
        <div class="toolbar-actions">
            <button class="button" type="submit">Push speichern</button>
        </div>
    </form>
</section>

<section class="card stack">
    <div class="section-toolbar">
        <div>
            <h2>Aktive Geraete</h2>
            <p class="muted">Mitarbeiter aktivieren Geraete im mobilen Profil. Hier koennen sie deaktiviert oder getestet werden.</p>
        </div>
    </div>
    <div class="table-wrap">
        <table>
            <thead><tr><th>Benutzer</th><th>Geraet</th><th>Status</th><th>Letzte Aktivitaet</th><th>Aktion</th></tr></thead>
            <tbody>{$deviceRows}</tbody>
        </table>
    </div>
</section>
HTML;

        return Response::html($this->view->render('Push', $content));
    }

    public function save(Request $request): Response
    {
        if (!$this->hasValidCsrfToken($request)) {
            return Response::redirect('/admin/settings/push?error=' . rawurlencode('Die Aktion konnte nicht bestaetigt werden. Bitte Seite neu laden.'));
        }

        $this->settingsService->save($request->input());

        return Response::redirect('/admin/settings/push?notice=saved');
    }

    public function updateDevice(Request $request, array $parameters): Response
    {
        if (!$this->hasValidCsrfToken($request)) {
            return Response::redirect('/admin/settings/push?error=' . rawurlencode('Die Aktion konnte nicht bestaetigt werden. Bitte Seite neu laden.'));
        }

        $enabled = (string) $request->input('enabled', '0') === '1';
        $this->subscriptionService->setEnabled((int) ($parameters['id'] ?? 0), $enabled);

        return Response::redirect('/admin/settings/push?notice=device-saved');
    }

    public function test(Request $request): Response
    {
        if (!$this->hasValidCsrfToken($request)) {
            return Response::redirect('/admin/settings/push?error=' . rawurlencode('Die Aktion konnte nicht bestaetigt werden. Bitte Seite neu laden.'));
        }

        $subscriptionId = (int) $request->input('subscription_id', 0);
        $subscription = null;

        foreach ($this->subscriptionService->adminList() as $row) {
            if ((int) ($row['id'] ?? 0) === $subscriptionId) {
                $subscription = $row;
                break;
            }
        }

        if ($subscription === null) {
            return Response::redirect('/admin/settings/push?error=' . rawurlencode('Das Geraet wurde nicht gefunden.'));
        }

        $result = $this->notificationService->send($subscription, [
            'type' => 'admin_test',
            'title' => 'Push-Test',
            'body' => 'Diese Testbenachrichtigung wurde aus dem Admin-Backend gesendet.',
            'url' => '/app/profil',
            'tag' => 'admin-push-test',
        ]);

        return ($result['ok'] ?? false)
            ? Response::redirect('/admin/settings/push?notice=test-sent')
            : Response::redirect('/admin/settings/push?error=' . rawurlencode((string) ($result['message'] ?? 'Push-Test fehlgeschlagen.')));
    }

    private function settingsTabs(): string
    {
        return '<section class="section-toolbar"><div class="scope-switch">'
            . '<a class="scope-link" href="/admin/settings/company">Settings</a>'
            . '<a class="scope-link" href="/admin/settings/database">Datenbank</a>'
            . '<a class="scope-link is-active" href="/admin/settings/push">Push</a>'
            . '</div></section>';
    }

    private function weekdayCheckboxes(array $activeDays): string
    {
        $labels = [1 => 'Mo', 2 => 'Di', 3 => 'Mi', 4 => 'Do', 5 => 'Fr', 6 => 'Sa', 7 => 'So'];
        $html = '';

        foreach ($labels as $value => $label) {
            $checked = in_array($value, $activeDays, true) ? 'checked' : '';
            $html .= '<label class="checkbox-item"><input type="checkbox" name="reminder_weekdays[]" value="' . $value . '" ' . $checked . '> <span>' . $label . '</span></label>';
        }

        return $html;
    }

    private function deviceRows(string $csrfToken): string
    {
        $rows = $this->subscriptionService->adminList();

        if ($rows === []) {
            return '<tr><td colspan="5" class="muted">Noch keine Push-Geraete aktiviert.</td></tr>';
        }

        return implode('', array_map(function (array $row) use ($csrfToken): string {
            $id = (int) ($row['id'] ?? 0);
            $enabled = (int) ($row['is_enabled'] ?? 0) === 1;
            $name = trim(((string) ($row['first_name'] ?? '')) . ' ' . ((string) ($row['last_name'] ?? '')));
            $status = $enabled ? '<span class="badge ok">aktiv</span>' : '<span class="badge warn">inaktiv</span>';
            $toggleLabel = $enabled ? 'Deaktivieren' : 'Aktivieren';
            $toggleValue = $enabled ? '0' : '1';

            return '<tr>'
                . '<td><strong>' . $this->escape($name !== '' ? $name : (string) ($row['email'] ?? '')) . '</strong><br><span class="muted">' . $this->escape((string) ($row['email'] ?? '')) . '</span></td>'
                . '<td>' . $this->escape((string) ($row['device_label'] ?? 'Browser-Geraet')) . '<br><span class="muted">' . $this->escape(mb_substr((string) ($row['user_agent'] ?? ''), 0, 80)) . '</span></td>'
                . '<td>' . $status . '</td>'
                . '<td><span class="muted">gesehen:</span> ' . $this->escape((string) ($row['last_seen_at'] ?? '-')) . '<br><span class="muted">Erfolg:</span> ' . $this->escape((string) ($row['last_success_at'] ?? '-')) . '</td>'
                . '<td><div class="toolbar-actions">'
                . '<form method="post" action="/admin/settings/push/subscriptions/' . $id . '"><input type="hidden" name="csrf_token" value="' . $csrfToken . '"><input type="hidden" name="enabled" value="' . $toggleValue . '"><button class="button button-secondary" type="submit">' . $toggleLabel . '</button></form>'
                . '<form method="post" action="/admin/settings/push/test"><input type="hidden" name="csrf_token" value="' . $csrfToken . '"><input type="hidden" name="subscription_id" value="' . $id . '"><button class="button" type="submit">Test senden</button></form>'
                . '</div></td>'
                . '</tr>';
        }, $rows));
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
            'saved' => 'Die Push-Settings wurden gespeichert.',
            'device-saved' => 'Der Geraetestatus wurde aktualisiert.',
            'test-sent' => 'Die Testbenachrichtigung wurde gesendet.',
            default => 'Push wurde aktualisiert.',
        };

        return '<p class="notice success">' . $this->escape($message) . '</p>';
    }

    private function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    }
}
