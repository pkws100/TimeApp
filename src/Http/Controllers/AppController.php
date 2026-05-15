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
        $bootstrap = [
            'route' => $route,
            'session' => $this->authService->sessionPayload(),
            'app_name' => $this->appName,
            'company_logo_url' => $this->companySettingsService?->publicLogoUrl(),
        ];

        return Response::html($this->view->render($route, $bootstrap));
    }

    public function manifest(Request $request): Response
    {
        $manifest = [
            'name' => $this->appName,
            'short_name' => 'Zeiten',
            'start_url' => '/app',
            'display' => 'standalone',
            'background_color' => '#f6f3ee',
            'theme_color' => '#d97706',
            'lang' => 'de-DE',
            'scope' => '/app',
            'icons' => [
                [
                    'src' => '/assets/app-icon.svg',
                    'sizes' => 'any',
                    'type' => 'image/svg+xml',
                    'purpose' => 'any maskable',
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
        $script = <<<JS
const CACHE_NAME = 'zeiterfassung-app-v4';
const APP_SHELL = [
  '/app',
  '{$cssUrl}',
  '{$jsUrl}'
];

self.addEventListener('install', (event) => {
  event.waitUntil(caches.open(CACHE_NAME).then((cache) => cache.addAll(APP_SHELL)));
  self.skipWaiting();
});

self.addEventListener('activate', (event) => {
  event.waitUntil(
    caches.keys()
      .then((keys) => Promise.all(keys.filter((key) => key !== CACHE_NAME).map((key) => caches.delete(key))))
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

  const title = payload.title || 'Zeiterfassung';
  const options = {
    body: payload.body || 'Bitte oeffnen Sie die App.',
    icon: '/assets/app-icon.svg',
    badge: '/assets/app-icon.svg',
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

        return new Response($script, 200, ['Content-Type' => 'application/javascript; charset=utf-8']);
    }
}
