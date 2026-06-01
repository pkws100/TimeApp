<?php

declare(strict_types=1);

namespace App\Presentation\App;

final class AppView
{
    private const APP_CSS = '/assets/css/app.css';
    private const APP_JS = '/assets/js/app.js';

    public function __construct(private string $appName)
    {
    }

    public function render(string $route, array $bootstrap): string
    {
        $title = htmlspecialchars($this->appName, ENT_QUOTES, 'UTF-8');
        $route = htmlspecialchars($route, ENT_QUOTES, 'UTF-8');
        $payload = json_encode($bootstrap, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $cssUrl = htmlspecialchars($this->assetUrl(self::APP_CSS), ENT_QUOTES, 'UTF-8');
        $jsUrl = htmlspecialchars($this->assetUrl(self::APP_JS), ENT_QUOTES, 'UTF-8');

        return <<<HTML
<!DOCTYPE html>
<html lang="de" data-theme="light" data-theme-mode="system">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="theme-color" content="#d97706">
    <meta name="mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-title" content="{$title}">
    <meta name="apple-mobile-web-app-status-bar-style" content="default">
    <title>{$title}</title>
    <link rel="icon" href="/assets/app-icon.svg" type="image/svg+xml">
    <link rel="icon" href="/assets/app-icon-192.png" sizes="192x192" type="image/png">
    <link rel="apple-touch-icon" href="/assets/apple-touch-icon.png">
    <link rel="apple-touch-icon" sizes="180x180" href="/assets/apple-touch-icon.png">
    <link rel="manifest" href="/app/manifest.json">
    <link rel="stylesheet" href="{$cssUrl}">
</head>
<body data-app-route="{$route}">
    <div id="appRoot"></div>
    <script>window.__APP_BOOTSTRAP__ = {$payload};</script>
    <script src="{$jsUrl}"></script>
</body>
</html>
HTML;
    }

    public static function versionedAssetUrl(string $path): string
    {
        $filesystemPath = base_path('public/' . ltrim($path, '/'));
        $version = is_file($filesystemPath) ? (string) filemtime($filesystemPath) : '1';

        return $path . '?v=' . rawurlencode($version);
    }

    private function assetUrl(string $path): string
    {
        return self::versionedAssetUrl($path);
    }
}
