<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Auth\AuthService;
use App\Domain\Settings\CompanySettingsService;
use App\Http\Request;
use App\Http\Response;
use App\Presentation\App\AppView;

final class AppController
{
    public const OFFLINE_SHELL_QUERY = 'offline-shell';

    public function __construct(
        private AppView $view,
        private AuthService $authService,
        private string $appName,
        private ?CompanySettingsService $companySettingsService = null
    ) {
    }

    public function shell(Request $request): Response
    {
        $route = $request->path();
        $offlineShell = $route === '/app' && $request->query(self::OFFLINE_SHELL_QUERY) === '1';
        $session = $offlineShell
            ? ['authenticated' => false, 'user' => null, 'bootstrap_required' => $this->authService->bootstrapRequired()]
            : $this->authService->sessionPayload();
        $bootstrap = [
            'route' => $route,
            'session' => $session,
            'app_name' => $this->appName,
            'company_logo_url' => $this->companySettingsService?->publicLogoUrl(),
        ];

        return Response::html($this->view->render($route, $bootstrap));
    }

    public function manifest(Request $request): Response
    {
        $manifest = [
            'name' => $this->appName,
            'short_name' => $this->appName,
            'start_url' => '/app',
            'display' => 'standalone',
            'background_color' => '#f6f3ee',
            'theme_color' => '#d97706',
            'lang' => 'de-DE',
            'scope' => '/app',
            'icons' => [
                [
                    'src' => '/assets/app-icon-192.png',
                    'sizes' => '192x192',
                    'type' => 'image/png',
                    'purpose' => 'any',
                ],
                [
                    'src' => '/assets/app-icon-512.png',
                    'sizes' => '512x512',
                    'type' => 'image/png',
                    'purpose' => 'any',
                ],
                [
                    'src' => '/assets/app-icon-maskable-512.png',
                    'sizes' => '512x512',
                    'type' => 'image/png',
                    'purpose' => 'maskable',
                ],
                [
                    'src' => '/assets/app-icon.svg',
                    'sizes' => 'any',
                    'type' => 'image/svg+xml',
                    'purpose' => 'any',
                ],
            ],
        ];

        return new Response(
            json_encode($manifest, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            200,
            ['Content-Type' => 'application/manifest+json; charset=utf-8']
        );
    }

    public function serviceWorker(Request $request): Response
    {
        $cssUrl = AppView::versionedAssetUrl('/assets/css/app.css');
        $jsUrl = AppView::versionedAssetUrl('/assets/js/app.js');
        $icon192Url = AppView::versionedAssetUrl('/assets/app-icon-192.png');
        $icon512Url = AppView::versionedAssetUrl('/assets/app-icon-512.png');
        $maskableIconUrl = AppView::versionedAssetUrl('/assets/app-icon-maskable-512.png');
        $appleTouchIconUrl = AppView::versionedAssetUrl('/assets/apple-touch-icon.png');
        $appName = json_encode($this->appName, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $offlineShellQuery = self::OFFLINE_SHELL_QUERY;
        $script = <<<JS
const CACHE_NAME = 'zeiterfassung-app-v9';
const APP_NAME = {$appName};
const APP_FALLBACK_URL = '/app';
const APP_SHELL = [
  APP_FALLBACK_URL + '?{$offlineShellQuery}=1',
  '{$cssUrl}',
  '{$jsUrl}',
  '{$icon192Url}',
  '{$icon512Url}',
  '{$maskableIconUrl}',
  '{$appleTouchIconUrl}'
];

self.addEventListener('install', (event) => {
  const publicShell = APP_SHELL.map((url) => new Request(url, { credentials: 'omit' }));
  event.waitUntil(caches.open(CACHE_NAME).then(async (cache) => {
    await cache.addAll(publicShell);
    const fallback = await cache.match(publicShell[0]);
    await cache.put(APP_FALLBACK_URL, new Response(fallback.body, { headers: fallback.headers }));
  }));
  self.skipWaiting();
});

self.addEventListener('activate', (event) => {
  event.waitUntil(
    caches.keys()
      .then((keys) => Promise.all(keys.filter((key) => key.startsWith('zeiterfassung-app-') && key !== CACHE_NAME).map((key) => caches.delete(key))))
      .then(() => self.clients.claim())
  );
});

self.addEventListener('fetch', (event) => {
  const request = event.request;

  if (request.method !== 'GET') {
    return;
  }

  const url = new URL(request.url);
  const isAppShellRequest = (url.pathname === '/app' || url.pathname.startsWith('/app/'))
    && url.pathname !== '/app/sw.js'
    && url.pathname !== '/app/manifest.json';
  const isStaticAssetRequest = url.pathname.startsWith('/assets/');

  if (!isAppShellRequest && !isStaticAssetRequest) {
    return;
  }

  if (isAppShellRequest) {
    event.respondWith(fetch(request).catch(() => caches.match(APP_FALLBACK_URL)));
    return;
  }

  event.respondWith(
    caches.match(request).then((cached) => {
      if (cached) {
        return cached;
      }

      return fetch(request).then((response) => {
        if (response.ok) {
          const cloned = response.clone();
          caches.open(CACHE_NAME).then((cache) => cache.put(request, cloned));
        }

        return response;
      }).catch(() => caches.match('/app'));
    })
  );
});

self.addEventListener('push', (event) => {
  let payload = {};

  if (event.data) {
    try {
      payload = event.data.json();
    } catch (error) {
      payload = { body: event.data.text() };
    }
  }

  const title = payload.title || APP_NAME;
  const options = {
    body: payload.body || 'Bitte oeffnen Sie die App.',
    icon: '/assets/app-icon-192.png',
    badge: '/assets/app-icon-maskable-512.png',
    tag: payload.tag || 'zeiterfassung-push',
    data: {
      url: payload.url || '/app'
    }
  };

  event.waitUntil(self.registration.showNotification(title, options));
});

self.addEventListener('notificationclick', (event) => {
  event.notification.close();
  const targetUrl = event.notification.data && event.notification.data.url ? event.notification.data.url : '/app';

  event.waitUntil(
    self.clients.matchAll({ type: 'window', includeUncontrolled: true }).then((clientList) => {
      for (const client of clientList) {
        if ('focus' in client && client.url.includes('/app')) {
          if ('navigate' in client) {
            return client.navigate(targetUrl).then(() => client.focus());
          }

          return client.focus();
        }
      }

      if (self.clients.openWindow) {
        return self.clients.openWindow(targetUrl);
      }

      return undefined;
    })
  );
});
JS;

        return new Response($script, 200, [
            'Content-Type' => 'application/javascript; charset=utf-8',
            'Cache-Control' => 'no-cache',
            'Service-Worker-Allowed' => '/app',
        ]);
    }
}
