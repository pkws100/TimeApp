<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Auth\AuthService;
use App\Domain\Settings\CompanySettingsService;
use App\Http\Request;
use App\Http\Response;

final class AdminAuthController
{
    public function __construct(
        private AuthService $authService,
        private ?CompanySettingsService $companySettingsService = null
    )
    {
    }

    public function show(Request $request): Response
    {
        if ($this->authService->currentUser() !== null) {
            return Response::redirect('/admin');
        }

        $error = trim((string) $request->query('error', ''));
        $next = (string) $request->query('next', '/admin');
        $notice = $error !== '' ? '<p style="padding:0.9rem 1rem;background:#fee2e2;border-radius:0.8rem;color:#991b1b;">' . htmlspecialchars(urldecode($error), ENT_QUOTES, 'UTF-8') . '</p>' : '';
        $bootstrapHint = '';
        $logo = $this->loginLogo();

        if ($this->authService->bootstrapRequired()) {
            $bootstrapHint = '<p style="padding:0.9rem 1rem;background:#fef3c7;border-radius:0.8rem;color:#92400e;">'
                . 'Das System ist noch nicht initialisiert. Legen Sie zuerst per CLI den ersten Administrator an: '
                . '<code>php bin/bootstrap-admin.php --email=admin@example.invalid --password=... --first-name=Admin --last-name=Benutzer</code>'
                . '</p>';
        }

        $html = <<<HTML
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Admin Login</title>
    <link rel="icon" href="/assets/app-icon.svg" type="image/svg+xml">
    <link rel="stylesheet" href="/assets/css/admin.css">
</head>
<body>
    <main style="max-width:28rem;margin:8vh auto;padding:1.5rem;">
        <section class="card stack">
            <header>
                {$logo}
                <p class="eyebrow">Admin-Zugang</p>
                <h1>Anmelden</h1>
                <p class="muted">Mit demselben Benutzerkonto wie in der Mitarbeiter-App.</p>
            </header>
            {$notice}
            {$bootstrapHint}
            <form method="post" action="/admin/login" class="stack">
                <input type="hidden" name="next" value="{$this->escape($next)}">
                <label><span>E-Mail</span><input type="email" name="email" required></label>
                <label><span>Passwort</span><input type="password" name="password" required></label>
                <button class="button" type="submit">Anmelden</button>
            </form>
        </section>
    </main>
</body>
</html>
HTML;

        return Response::html($html);
    }

    public function login(Request $request): Response
    {
        $result = $this->authService->login(
            (string) $request->input('email', ''),
            (string) $request->input('password', '')
        );

        if (!($result['ok'] ?? false)) {
            return Response::redirect('/admin/login?error=' . rawurlencode((string) ($result['message'] ?? 'Login fehlgeschlagen.')));
        }

        $next = (string) $request->input('next', '/admin');

        return Response::redirect($next !== '' ? $next : '/admin');
    }

    public function logout(Request $request): Response
    {
        $this->authService->logout();

        return Response::redirect('/admin/login');
    }

    private function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    }

    private function loginLogo(): string
    {
        $url = $this->companySettingsService?->publicLogoUrl();

        if ($url === null) {
            return '';
        }

        return '<img class="login-logo" src="' . $this->escape($url) . '" alt="Firmenlogo">';
    }
}
