<?php

declare(strict_types=1);

namespace App\Domain\Personnel;

use App\Domain\Push\PushNotificationService;
use App\Domain\Push\PushSubscriptionService;
use App\Domain\Settings\CompanySettingsService;
use App\Domain\Settings\SmtpMailService;
use DateTimeImmutable;
use DateTimeZone;

final class PersonnelReminderService
{
    public function __construct(
        private PersonnelEventService $eventService,
        private PushSubscriptionService $pushSubscriptionService,
        private PushNotificationService $pushNotificationService,
        private CompanySettingsService $companySettingsService,
        private SmtpMailService $mailService,
        private string $timezone
    ) {
    }

    public function sendDueReminders(?DateTimeImmutable $now = null, bool $dryRun = false): array
    {
        $now = ($now ?? new DateTimeImmutable('now'))->setTimezone(new DateTimeZone($this->timezone));
        $settings = null;
        $summary = [
            'checked_at' => $now->format(DATE_ATOM),
            'dry_run' => $dryRun,
            'candidates' => 0,
            'sent' => 0,
            'shown' => 0,
            'would_send' => 0,
            'skipped' => 0,
            'errors' => 0,
            'messages' => [],
        ];

        foreach ($this->eventService->dueReminderCandidates($now) as $candidate) {
            foreach ($this->recipientRows($candidate) as $recipient) {
                $summary['candidates']++;
                $channel = (string) ($candidate['channel'] ?? 'admin');
                $recipientUserId = isset($recipient['id']) ? (int) $recipient['id'] : null;
                $recipientRole = (string) ($candidate['recipient_type'] ?? '') === 'admin_role'
                    ? (string) ($candidate['role_slug'] ?? '')
                    : null;
                $dedupeKey = $this->dedupeKey($candidate, $recipientUserId, $recipientRole);

                if ($this->eventService->notificationLogExists($dedupeKey)) {
                    $summary['skipped']++;
                    continue;
                }

                [$title, $body] = $this->message($candidate);

                if ($dryRun) {
                    $summary['would_send']++;
                    continue;
                }

                if ($channel === 'admin') {
                    $this->eventService->logNotification($candidate, $recipientUserId, $recipientRole, $dedupeKey, $title, $body, 'shown');
                    $summary['shown']++;
                    continue;
                }

                if ($channel === 'email') {
                    $settings ??= $this->companySettingsService->smtpSettingsForTest();
                    $email = trim((string) ($recipient['email'] ?? ''));
                    $result = $this->mailService->send($settings, $email, $title, $body);

                    if ($result['ok'] ?? false) {
                        $this->eventService->logNotification($candidate, $recipientUserId, $recipientRole, $dedupeKey, $title, $body, 'sent');
                        $summary['sent']++;
                    } else {
                        $this->eventService->logNotification($candidate, $recipientUserId, $recipientRole, $dedupeKey, $title, $body, 'error', (string) ($result['message'] ?? 'E-Mail-Versand fehlgeschlagen.'));
                        $summary['errors']++;
                    }

                    continue;
                }

                if ($channel === 'push') {
                    $sentForRecipient = false;

                    foreach ($this->pushSubscriptionService->activeSubscriptionsForUser((int) ($recipient['id'] ?? 0)) as $subscription) {
                        $result = $this->pushNotificationService->send($subscription, [
                            'type' => 'personnel_event_reminder',
                            'title' => $title,
                            'body' => $body,
                            'url' => '/app/profil',
                            'tag' => 'personnel-event-' . (int) ($candidate['user_event_id'] ?? $candidate['id'] ?? 0),
                        ], $dedupeKey);

                        if ($result['ok'] ?? false) {
                            $sentForRecipient = true;
                        }
                    }

                    if ($sentForRecipient) {
                        $this->eventService->logNotification($candidate, $recipientUserId, $recipientRole, $dedupeKey, $title, $body, 'sent');
                        $summary['sent']++;
                    } else {
                        $this->eventService->logNotification($candidate, $recipientUserId, $recipientRole, $dedupeKey, $title, $body, 'error', 'Kein aktives Push-Geraet oder Versand fehlgeschlagen.');
                        $summary['errors']++;
                    }
                }
            }
        }

        if ($summary['candidates'] === 0) {
            $summary['messages'][] = 'Keine faelligen Personal-Event-Reminder gefunden.';
        }

        return $summary;
    }

    private function recipientRows(array $candidate): array
    {
        if ((string) ($candidate['recipient_type'] ?? 'user') === 'admin_role') {
            $roleSlug = trim((string) ($candidate['role_slug'] ?? ''));

            return $roleSlug === '' ? [] : $this->eventService->adminRoleUsers($roleSlug);
        }

        return [[
            'id' => (int) ($candidate['user_id'] ?? 0),
            'first_name' => (string) ($candidate['first_name'] ?? ''),
            'last_name' => (string) ($candidate['last_name'] ?? ''),
            'email' => (string) ($candidate['email'] ?? ''),
        ]];
    }

    private function message(array $candidate): array
    {
        $title = 'Personal-Termin faellig: ' . (string) ($candidate['display_title'] ?? $candidate['event_type_name'] ?? 'Termin');
        $userName = trim((string) ($candidate['user_name'] ?? ''));
        $dueOn = (string) ($candidate['due_on'] ?? '');
        $statusLabel = (string) ($candidate['status_label'] ?? 'Faellig');
        $body = trim(implode("\n", array_filter([
            $userName !== '' ? 'Mitarbeiter: ' . $userName : null,
            'Termin/Faelligkeit: ' . $dueOn,
            'Status: ' . $statusLabel,
            trim((string) ($candidate['note'] ?? '')) !== '' ? 'Notiz: ' . trim((string) ($candidate['note'] ?? '')) : null,
        ])));

        return [$title, $body];
    }

    private function dedupeKey(array $candidate, ?int $recipientUserId, ?string $recipientRole): string
    {
        return implode(':', [
            'personnel-event',
            (string) ($candidate['user_event_id'] ?? $candidate['id'] ?? 0),
            (string) ($candidate['reminder_id'] ?? 0),
            (string) ($candidate['channel'] ?? 'admin'),
            $recipientUserId !== null ? 'user-' . $recipientUserId : 'role-' . ($recipientRole ?? 'none'),
            (string) ($candidate['reminder_date'] ?? date('Y-m-d')),
        ]);
    }
}
