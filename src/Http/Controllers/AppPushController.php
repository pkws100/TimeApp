<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Auth\AuthService;
use App\Domain\Push\PushNotificationService;
use App\Domain\Push\PushSettingsService;
use App\Domain\Push\PushSubscriptionService;
use App\Http\Request;
use App\Http\Response;
use InvalidArgumentException;

final class AppPushController
{
    public function __construct(
        private PushSettingsService $settingsService,
        private PushSubscriptionService $subscriptionService,
        private PushNotificationService $notificationService,
        private AuthService $authService
    ) {
    }

    public function status(Request $request): Response
    {
        $user = $this->authService->currentUser();

        if ($user === null) {
            return Response::json(['ok' => false, 'message' => 'Bitte erneut anmelden.'], 401);
        }

        $settings = $this->settingsService->current();
        $canReceive = $this->authService->hasPermission('push.receive');

        return Response::json([
            'ok' => true,
            'data' => [
                'enabled' => (bool) $settings['enabled'],
                'can_subscribe' => $canReceive && (bool) $settings['enabled'] && (bool) $settings['vapid_configured'],
                'permission_required' => !$canReceive,
                'vapid_configured' => (bool) $settings['vapid_configured'],
                'vapid_public_key' => $canReceive ? (string) $settings['vapid_public_key'] : '',
                'reminder_time' => (string) $settings['reminder_time'],
                'notice_text' => (string) $settings['notice_text'],
                'devices' => $this->subscriptionService->devicesForUser((int) $user['id']),
            ],
        ]);
    }

    public function store(Request $request): Response
    {
        $user = $this->authService->currentUser();

        if ($user === null) {
            return Response::json(['ok' => false, 'message' => 'Bitte erneut anmelden.'], 401);
        }

        if (!$this->authService->hasPermission('push.receive')) {
            return Response::json(['ok' => false, 'message' => 'Push ist fuer Ihre Rolle nicht freigegeben.'], 403);
        }

        $settings = $this->settingsService->current();

        if (!$settings['enabled'] || !$settings['vapid_configured']) {
            return Response::json(['ok' => false, 'message' => 'Push ist aktuell nicht aktiv.'], 409);
        }

        try {
            $subscription = $this->subscriptionService->storeForUser(
                $user,
                $request->input(),
                (string) $request->server('HTTP_USER_AGENT', '')
            );

            return Response::json([
                'ok' => true,
                'message' => 'Push wurde fuer dieses Geraet aktiviert.',
                'data' => [
                    'subscription_id' => (int) ($subscription['id'] ?? 0),
                    'devices' => $this->subscriptionService->devicesForUser((int) $user['id']),
                ],
            ]);
        } catch (InvalidArgumentException $exception) {
            return Response::json(['ok' => false, 'message' => $exception->getMessage()], 422);
        }
    }

    public function disable(Request $request, array $parameters): Response
    {
        $user = $this->authService->currentUser();

        if ($user === null) {
            return Response::json(['ok' => false, 'message' => 'Bitte erneut anmelden.'], 401);
        }

        $this->subscriptionService->setEnabled((int) ($parameters['id'] ?? 0), false, (int) $user['id']);

        return Response::json([
            'ok' => true,
            'message' => 'Push wurde fuer dieses Geraet deaktiviert.',
            'data' => [
                'devices' => $this->subscriptionService->devicesForUser((int) $user['id']),
            ],
        ]);
    }

    public function test(Request $request): Response
    {
        $user = $this->authService->currentUser();

        if ($user === null) {
            return Response::json(['ok' => false, 'message' => 'Bitte erneut anmelden.'], 401);
        }

        if (!$this->authService->hasPermission('push.receive')) {
            return Response::json(['ok' => false, 'message' => 'Push ist fuer Ihre Rolle nicht freigegeben.'], 403);
        }

        $settings = $this->settingsService->current();

        if (!$settings['enabled'] || !$settings['vapid_configured'] || !$this->notificationService->isConfigured()) {
            return Response::json(['ok' => false, 'message' => 'Push ist aktuell nicht vollstaendig konfiguriert.'], 409);
        }

        $subscription = $this->subscriptionService->activeSubscriptionForUserEndpoint(
            (int) $user['id'],
            (string) $request->input('endpoint', '')
        );

        if ($subscription === null) {
            return Response::json(['ok' => false, 'message' => 'Dieses Geraet ist nicht aktiv fuer Push registriert. Bitte Push erneut aktivieren.'], 404);
        }

        $result = $this->notificationService->send($subscription, [
            'type' => 'app_profile_test',
            'title' => 'Push-Test',
            'body' => 'Diese Testbenachrichtigung wurde aus Ihrem App-Profil gesendet.',
            'url' => '/app/profil',
            'tag' => 'app-profile-push-test',
        ]);

        if (!($result['ok'] ?? false)) {
            return Response::json([
                'ok' => false,
                'message' => (string) ($result['message'] ?? 'Push-Test konnte nicht gesendet werden.'),
                'data' => [
                    'devices' => $this->subscriptionService->devicesForUser((int) $user['id']),
                ],
            ], 502);
        }

        return Response::json([
            'ok' => true,
            'message' => 'Push-Test wurde an dieses Geraet gesendet.',
            'data' => [
                'devices' => $this->subscriptionService->devicesForUser((int) $user['id']),
            ],
        ]);
    }
}
