<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\Auth\AuthService;
use App\Domain\Users\PermissionMatrix;
use App\Http\Controllers\AppController;
use App\Http\Request;
use App\Infrastructure\Database\DatabaseConnection;
use App\Presentation\App\AppView;
use PHPUnit\Framework\TestCase;

final class AppPwaMetadataTest extends TestCase
{
    public function testAppShellUsesDynamicAppNameForIosMetadata(): void
    {
        $view = new AppView('HTD Zeiterfassung');

        $html = $view->render('/app', [
            'route' => '/app',
            'session' => ['authenticated' => false, 'user' => null],
            'app_name' => 'HTD Zeiterfassung',
            'company_logo_url' => null,
        ]);

        self::assertStringContainsString('<title>HTD Zeiterfassung</title>', $html);
        self::assertStringContainsString('<meta name="apple-mobile-web-app-title" content="HTD Zeiterfassung">', $html);
        self::assertStringContainsString('<meta name="apple-mobile-web-app-capable" content="yes">', $html);
        self::assertStringContainsString('<meta name="mobile-web-app-capable" content="yes">', $html);
        self::assertStringContainsString('<link rel="apple-touch-icon" href="/assets/apple-touch-icon.png">', $html);
    }

    public function testManifestUsesDynamicAppNameAndInstallableIcons(): void
    {
        $controller = new AppController(
            new AppView('HTD Zeiterfassung'),
            new AuthService(new DatabaseConnection([]), new PermissionMatrix([], [])),
            'HTD Zeiterfassung'
        );

        $response = $controller->manifest(new Request('GET', '/app/manifest.json', [], [], [], [], []));

        ob_start();
        $response->send();
        $payload = ob_get_clean() ?: '';
        $manifest = json_decode($payload, true, 512, JSON_THROW_ON_ERROR);

        self::assertSame('HTD Zeiterfassung', $manifest['name']);
        self::assertSame('HTD Zeiterfassung', $manifest['short_name']);
        self::assertSame('/app', $manifest['start_url']);
        self::assertSame('/app', $manifest['scope']);
        self::assertSame('standalone', $manifest['display']);

        $iconsBySrc = [];
        foreach ($manifest['icons'] as $icon) {
            $iconsBySrc[$icon['src']] = $icon;
        }

        self::assertSame('192x192', $iconsBySrc['/assets/app-icon-192.png']['sizes'] ?? null);
        self::assertSame('512x512', $iconsBySrc['/assets/app-icon-512.png']['sizes'] ?? null);
        self::assertSame('512x512', $iconsBySrc['/assets/app-icon-maskable-512.png']['sizes'] ?? null);
        self::assertSame('maskable', $iconsBySrc['/assets/app-icon-maskable-512.png']['purpose'] ?? null);
    }

    public function testServiceWorkerUsesDynamicAppNameForPushFallbackTitle(): void
    {
        $controller = new AppController(
            new AppView('HTD Zeiterfassung'),
            new AuthService(new DatabaseConnection([]), new PermissionMatrix([], [])),
            'HTD Zeiterfassung'
        );

        $response = $controller->serviceWorker(new Request('GET', '/app/sw.js', [], [], [], [], []));

        ob_start();
        $response->send();
        $script = ob_get_clean() ?: '';

        self::assertStringContainsString('const APP_NAME = "HTD Zeiterfassung";', $script);
        self::assertStringContainsString("const APP_FALLBACK_URL = '/app';", $script);
        self::assertStringContainsString('if (isAppShellRequest) {', $script);
        self::assertStringContainsString('fetch(request).then((response) => {', $script);
        self::assertStringContainsString('const title = payload.title || APP_NAME;', $script);
        self::assertStringNotContainsString("payload.title || 'Zeiterfassung'", $script);
    }
}
