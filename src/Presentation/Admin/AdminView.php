<?php

declare(strict_types=1);

namespace App\Presentation\Admin;

use Closure;

final class AdminView
{
    private const THEME_STORAGE_KEY = 'app.theme';

    public function __construct(
        private string $defaultAppName,
        private string $appUrl,
        private ?Closure $contextResolver = null
    ) {
    }

    public function render(string $title, string $content, ?string $script = null): string
    {
        $context = $this->resolveContext();
        $appName = htmlspecialchars((string) ($context['app_name'] ?? $this->defaultAppName), ENT_QUOTES, 'UTF-8');
        $title = htmlspecialchars($title, ENT_QUOTES, 'UTF-8');
        $themeBootstrap = $this->themeBootstrapScript();
        $navigation = $this->navigationHtml($context['navigation'] ?? $this->defaultNavigation());

        return <<<HTML
<!DOCTYPE html>
<html lang="de" data-theme="light" data-theme-mode="system">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{$title} | {$appName}</title>
    <script>{$themeBootstrap}</script>
    <link rel="stylesheet" href="/assets/css/admin.css">
</head>
<body>
    <div class="shell">
        <aside class="sidebar">
            <div class="brand">{$appName}</div>
            <nav class="nav">{$navigation}</nav>
        </aside>
        <main class="content">
            <header class="shell-toolbar">
                <div class="toolbar-copy">
                    <p class="toolbar-label">Darstellung</p>
                    <strong id="themeStatus">System</strong>
                </div>
                <div class="theme-switcher" role="group" aria-label="Theme wechseln">
                    <button type="button" class="theme-option" data-theme-option="light">Hell</button>
                    <button type="button" class="theme-option" data-theme-option="dark">Dunkel</button>
                    <button type="button" class="theme-option" data-theme-option="system">System</button>
                </div>
            </header>
            {$content}
        </main>
    </div>
    <script>window.APP_URL = {$this->json($this->appUrl)};</script>
    <script src="/assets/js/admin-theme.js"></script>
    <script src="/assets/js/admin-settings.js"></script>
    <script src="/assets/js/admin-dashboard.js"></script>
    <script src="/assets/js/admin-bookings.js"></script>
    {$script}
</body>
</html>
HTML;
    }

    private function resolveContext(): array
    {
        if (!$this->contextResolver instanceof Closure) {
            return [];
        }

        $context = ($this->contextResolver)();

        return is_array($context) ? $context : [];
    }

    private function json(string $value): string
    {
        return json_encode($value, JSON_THROW_ON_ERROR);
    }

    private function navigationHtml(array $items): string
    {
        $currentPath = parse_url($_SERVER['REQUEST_URI'] ?? '/admin', PHP_URL_PATH) ?: '/admin';
        $html = '';

        foreach ($items as $item) {
            $path = (string) ($item['href'] ?? '/admin');
            $activePrefix = (string) ($item['active_prefix'] ?? $path);
            $label = (string) ($item['label'] ?? '');
            $active = $activePrefix === '/admin'
                ? $currentPath === '/admin'
                : $currentPath === $path || str_starts_with($currentPath, $activePrefix . '/');
            $class = $active ? ' class="is-active"' : '';
            $badge = array_key_exists('badge', $item)
                ? '<span class="badge nav-badge">' . htmlspecialchars((string) $item['badge'], ENT_QUOTES, 'UTF-8') . '</span>'
                : '';
            $html .= '<a href="' . htmlspecialchars($path, ENT_QUOTES, 'UTF-8') . '"' . $class . '>'
                . '<span class="nav-label">' . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '</span>'
                . $badge
                . '</a>';
        }

        return $html;
    }

    private function defaultNavigation(): array
    {
        return [
            ['href' => '/admin', 'label' => 'Dashboard', 'active_prefix' => '/admin'],
            ['href' => '/admin/attendance', 'label' => 'Anwesenheit', 'active_prefix' => '/admin/attendance', 'badge' => 0],
            ['href' => '/admin/projects', 'label' => 'Projekte', 'active_prefix' => '/admin/projects', 'badge' => 0],
            ['href' => '/admin/bookings', 'label' => 'Buchungen', 'active_prefix' => '/admin/bookings', 'badge' => 0],
            ['href' => '/admin/users', 'label' => 'User', 'active_prefix' => '/admin/users', 'badge' => 0],
            ['href' => '/admin/roles', 'label' => 'Rollen', 'active_prefix' => '/admin/roles', 'badge' => 0],
            ['href' => '/admin/assets', 'label' => 'Geraete', 'active_prefix' => '/admin/assets', 'badge' => 0],
            ['href' => '/admin/settings/company', 'label' => 'Settings', 'active_prefix' => '/admin/settings'],
        ];
    }

    private function themeBootstrapScript(): string
    {
        $storageKey = self::THEME_STORAGE_KEY;

        return <<<JS
(function () {
    var storageKey = '{$storageKey}';
    var root = document.documentElement;
    var mode = 'system';

    try {
        var savedMode = window.localStorage.getItem(storageKey);

        if (savedMode === 'light' || savedMode === 'dark' || savedMode === 'system') {
            mode = savedMode;
        }
    } catch (error) {
        mode = 'system';
    }

    var resolvedTheme = mode;

    if (mode === 'system') {
        resolvedTheme = window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light';
    }

    root.dataset.themeMode = mode;
    root.dataset.theme = resolvedTheme;
    root.style.colorScheme = resolvedTheme;
}());
JS;
    }
}
