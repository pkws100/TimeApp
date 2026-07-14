<?php

declare(strict_types=1);

namespace App\Domain\Terminals;

use App\Domain\Timesheets\AppTimesheetSyncService;
use App\Infrastructure\Database\DatabaseConnection;
use DateTimeImmutable;
use RuntimeException;

final class TerminalPunchService
{
    public function __construct(
        private DatabaseConnection $connection,
        private TerminalService $terminalService,
        private NfcTagService $nfcTagService,
        private AppTimesheetSyncService $syncService,
        private TerminalTrustBundleService $trustBundleService
    ) {
    }

    public function config(array $terminal): array
    {
        $this->terminalService->assertFeatureEnabled();

        $response = [
            'ok' => true,
            'terminal' => [
                'id' => (int) ($terminal['id'] ?? 0),
                'terminal_identifier' => (string) ($terminal['terminal_identifier'] ?? ''),
                'name' => (string) ($terminal['name'] ?? ''),
                'welcome_text' => (string) ($terminal['welcome_text'] ?? 'Willkommen'),
                'settings' => $this->settings((string) ($terminal['settings_json'] ?? '')),
            ],
            'display' => [
                'lines' => $this->lcdLines([
                    (string) ($terminal['welcome_text'] ?? 'Willkommen'),
                    'Tag vorhalten',
                    'Bereit',
                    $this->clockLine(),
                ]),
                'hold_ms' => 3000,
            ],
            'signal' => ['led' => 'green', 'beep' => 'ready'],
            'server_time' => $this->serverTime(),
        ];

        $bundle = $this->trustBundleMetadata();
        if ($bundle !== null) {
            $response['trust_bundle'] = $bundle;
        }

        return $response;
    }

    public function scan(array $terminal, array $payload): array
    {
        $this->terminalService->assertFeatureEnabled();

        $requestId = trim((string) ($payload['request_id'] ?? ''));

        if ($requestId === '' || strlen($requestId) > 120) {
            return $this->failure('request_id_missing', 'Request-ID fehlt.', 422, $terminal, null, null, null, null, $payload);
        }

        $existing = $this->existingEventResponse((int) ($terminal['id'] ?? 0), $requestId);

        if ($existing !== null) {
            return $existing;
        }

        $uid = trim((string) ($payload['nfc_uid'] ?? ''));

        if ($uid === '') {
            return $this->failure('nfc_uid_missing', 'NFC-UID fehlt.', 422, $terminal, null, null, null, null, $payload);
        }

        try {
            $this->nfcTagService->normalizeUid($uid);
        } catch (RuntimeException $exception) {
            return $this->failure('invalid_uid', $exception->getMessage(), 422, $terminal, null, null, null, null, $payload);
        }

        try {
            $learnedTag = $this->nfcTagService->captureLearnScan($terminal, $uid);
        } catch (RuntimeException $exception) {
            return $this->failure('nfc_learn_failed', $exception->getMessage(), 422, $terminal, null, null, null, null, $payload);
        }

        if ($learnedTag !== null) {
            $response = $this->learningResponse($learnedTag);
            $this->recordEvent($terminal, $learnedTag, null, null, null, 'learned', $response['message'], $requestId, $payload, $response);

            return $response;
        }

        try {
            $tag = $this->nfcTagService->findActiveByUid($uid);
        } catch (RuntimeException $exception) {
            return $this->failure('nfc_lookup_failed', $exception->getMessage(), 422, $terminal, null, null, null, null, $payload);
        }

        if ($tag === null) {
            return $this->failure('unknown_tag', 'NFC-Tag unbekannt.', 404, $terminal, null, null, null, null, $payload);
        }

        $user = $this->activeUser((int) ($tag['user_id'] ?? 0));

        if ($user === null) {
            return $this->failure('unassigned_tag', 'NFC-Tag ist keinem aktiven User zugeordnet.', 422, $terminal, $tag, null, null, null, $payload);
        }

        $projectId = $this->projectId($terminal, $tag);

        if ($projectId !== null && !$this->projectIsBookable($projectId)) {
            return $this->failure('project_unavailable', 'Projekt ist nicht aktiv.', 422, $terminal, $tag, $user, $projectId, null, $payload);
        }

        $workDate = (new DateTimeImmutable('today'))->format('Y-m-d');
        $action = $this->nextAction((int) $user['id'], $workDate);

        try {
            $sync = $this->syncService->sync(
                [
                    'id' => (int) $user['id'],
                    'permissions' => ['timesheets.manage'],
                ],
                [
                    'client_request_id' => $this->syncRequestId($terminal, $requestId),
                    'action' => $action,
                    'work_date' => $workDate,
                    'project_id' => $action === 'check_in' ? $projectId : null,
                    'source' => 'terminal',
                    'note' => 'Terminal: ' . (string) ($terminal['name'] ?? ''),
                ]
            );
        } catch (RuntimeException $exception) {
            return $this->failure('booking_failed', $exception->getMessage(), 422, $terminal, $tag, $user, $projectId, $action, $payload);
        }

        $monthly = $this->monthlySummary((int) $user['id'], $workDate);
        $response = $this->successResponse($terminal, $user, $action, $sync, $monthly);
        $this->recordEvent($terminal, $tag, $user, $projectId, $action, 'synced', $response['message'], $requestId, $payload, $response);

        return $response;
    }

    private function existingEventResponse(int $terminalId, string $requestId): ?array
    {
        if (!$this->connection->tableExists('terminal_events')) {
            return null;
        }

        $event = $this->connection->fetchOne(
            'SELECT response_json FROM terminal_events WHERE terminal_id = :terminal_id AND request_id = :request_id LIMIT 1',
            [
                'terminal_id' => $terminalId,
                'request_id' => $requestId,
            ]
        );

        if ($event === null || trim((string) ($event['response_json'] ?? '')) === '') {
            return null;
        }

        $decoded = json_decode((string) $event['response_json'], true);

        return is_array($decoded) ? $decoded : null;
    }

    private function learningResponse(array $tag): array
    {
        return [
            'ok' => true,
            'action' => 'learn',
            'message' => 'NFC-Tag erfasst.',
            'display' => [
                'lines' => $this->lcdLines(['Tag erfasst', (string) ($tag['uid_masked'] ?? ''), 'Im Admin', 'zuordnen']),
                'hold_ms' => 15000,
            ],
            'signal' => ['led' => 'green', 'beep' => 'success'],
            'server_time' => $this->serverTime(),
            'tag' => [
                'id' => (int) ($tag['id'] ?? 0),
                'uid_masked' => (string) ($tag['uid_masked'] ?? ''),
                'status' => (string) ($tag['status'] ?? 'pending'),
            ],
        ];
    }

    private function successResponse(array $terminal, array $user, string $action, array $sync, array $monthly): array
    {
        $firstName = trim((string) ($user['first_name'] ?? ''));
        $time = (new DateTimeImmutable())->format('H:i:s');
        $actionLabel = $action === 'check_out' ? 'Feierabend' : 'Arbeitsbeginn';

        return [
            'ok' => true,
            'action' => $action,
            'message' => ($action === 'check_out' ? 'Auf Wiedersehen ' : 'Willkommen ') . $firstName,
            'display' => [
                'lines' => $this->lcdLines([
                    'Hallo ' . $firstName,
                    $actionLabel,
                    $time,
                    'Soll ' . $this->minutesToHours((int) $monthly['target_minutes']),
                ]),
                'hold_ms' => 15000,
            ],
            'signal' => ['led' => 'green', 'beep' => 'success'],
            'server_time' => $this->serverTime(),
            'monthly' => $monthly,
            'data' => $sync,
            'terminal' => [
                'id' => (int) ($terminal['id'] ?? 0),
                'terminal_identifier' => (string) ($terminal['terminal_identifier'] ?? ''),
            ],
        ];
    }

    private function failure(
        string $code,
        string $message,
        int $status,
        array $terminal,
        ?array $tag,
        ?array $user,
        ?int $projectId,
        ?string $action,
        array $payload
    ): array {
        $response = [
            'ok' => false,
            'code' => $code,
            'action' => $action,
            'message' => $message,
            'display' => [
                'lines' => $this->lcdLines(['Fehler', $message, 'Bitte Admin', 'informieren']),
                'hold_ms' => 15000,
            ],
            'signal' => ['led' => 'red', 'beep' => 'error'],
            'server_time' => $this->serverTime(),
            'http_status' => $status,
        ];

        $this->recordEvent($terminal, $tag, $user, $projectId, $action, $code, $message, (string) ($payload['request_id'] ?? ''), $payload, $response);

        return $response;
    }

    private function activeUser(int $userId): ?array
    {
        if ($userId <= 0 || !$this->connection->tableExists('users')) {
            return null;
        }

        return $this->connection->fetchOne(
            'SELECT id, employee_number, first_name, last_name, target_hours_month
             FROM users
             WHERE id = :id
               AND COALESCE(is_deleted, 0) = 0
               AND employment_status = "active"
             LIMIT 1',
            ['id' => $userId]
        );
    }

    private function projectId(array $terminal, array $tag): ?int
    {
        $terminalProjectId = (int) ($terminal['default_project_id'] ?? 0);

        if ($terminalProjectId > 0) {
            return $terminalProjectId;
        }

        $tagProjectId = (int) ($tag['project_id'] ?? 0);

        return $tagProjectId > 0 ? $tagProjectId : null;
    }

    private function syncRequestId(array $terminal, string $requestId): string
    {
        $terminalIdentifier = (string) ($terminal['terminal_identifier'] ?? (string) ($terminal['id'] ?? ''));

        return 'terminal:' . hash('sha256', $terminalIdentifier . ':' . $requestId);
    }

    private function projectIsBookable(int $projectId): bool
    {
        if (!$this->connection->tableExists('projects')) {
            return false;
        }

        return (int) ($this->connection->fetchColumn(
            'SELECT COUNT(*)
             FROM projects
             WHERE id = :id
               AND COALESCE(is_deleted, 0) = 0
               AND status <> "archived"
             LIMIT 1',
            ['id' => $projectId]
        ) ?? 0) > 0;
    }

    private function nextAction(int $userId, string $workDate): string
    {
        if (!$this->connection->tableExists('timesheets')) {
            return 'check_in';
        }

        $entry = $this->connection->fetchOne(
            'SELECT start_time, end_time
             FROM timesheets
             WHERE user_id = :user_id
               AND work_date = :work_date
               AND entry_type = "work"
               AND COALESCE(is_deleted, 0) = 0
             ORDER BY
               CASE WHEN start_time IS NOT NULL AND end_time IS NULL THEN 0 ELSE 1 END ASC,
               COALESCE(end_time, start_time, "00:00:00") DESC,
               id DESC
             LIMIT 1',
            [
                'user_id' => $userId,
                'work_date' => $workDate,
            ]
        );

        if ($entry !== null && ($entry['start_time'] ?? null) !== null && ($entry['end_time'] ?? null) === null) {
            return 'check_out';
        }

        return 'check_in';
    }

    private function monthlySummary(int $userId, string $workDate): array
    {
        $user = $this->activeUser($userId) ?? [];
        $monthStart = (new DateTimeImmutable($workDate))->modify('first day of this month')->format('Y-m-d');
        $monthEnd = (new DateTimeImmutable($workDate))->modify('last day of this month')->format('Y-m-d');
        $tracked = 0;

        if ($this->connection->tableExists('timesheets')) {
            $tracked = (int) ($this->connection->fetchColumn(
                'SELECT COALESCE(SUM(net_minutes), 0)
                 FROM timesheets
                 WHERE user_id = :user_id
                   AND work_date BETWEEN :month_start AND :month_end
                   AND entry_type = "work"
                   AND COALESCE(is_deleted, 0) = 0',
                [
                    'user_id' => $userId,
                    'month_start' => $monthStart,
                    'month_end' => $monthEnd,
                ]
            ) ?? 0);
        }

        $targetMinutes = (int) round(((float) ($user['target_hours_month'] ?? 0)) * 60);

        return [
            'target_minutes' => $targetMinutes,
            'tracked_minutes' => $tracked,
            'balance_minutes' => $tracked - $targetMinutes,
            'target_label' => $this->minutesToHours($targetMinutes),
            'tracked_label' => $this->minutesToHours($tracked),
            'balance_label' => $this->signedMinutesToHours($tracked - $targetMinutes),
        ];
    }

    private function recordEvent(
        array $terminal,
        ?array $tag,
        ?array $user,
        ?int $projectId,
        ?string $action,
        string $result,
        string $message,
        string $requestId,
        array $payload,
        array $response
    ): void {
        if ($requestId === '' || !$this->connection->tableExists('terminal_events')) {
            return;
        }

        try {
            $this->connection->execute(
                'INSERT INTO terminal_events (
                    terminal_id, nfc_tag_id, user_id, project_id, request_id, action, result, message,
                    firmware_version, device_time, response_json, created_at
                 ) VALUES (
                    :terminal_id, :nfc_tag_id, :user_id, :project_id, :request_id, :action, :result, :message,
                    :firmware_version, :device_time, :response_json, NOW()
                 )',
                [
                    'terminal_id' => (int) ($terminal['id'] ?? 0),
                    'nfc_tag_id' => isset($tag['id']) ? (int) $tag['id'] : null,
                    'user_id' => isset($user['id']) ? (int) $user['id'] : null,
                    'project_id' => $projectId,
                    'request_id' => $requestId,
                    'action' => $action,
                    'result' => $result,
                    'message' => mb_substr($message, 0, 255),
                    'firmware_version' => $this->nullableString($payload['firmware_version'] ?? null),
                    'device_time' => $this->deviceTime($payload['device_time'] ?? null),
                    'response_json' => json_encode($response, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                ]
            );
        } catch (\Throwable) {
            // Duplicate request IDs are answered from the stored event before processing.
        }
    }

    private function settings(string $json): array
    {
        if (trim($json) === '') {
            return [];
        }

        $decoded = json_decode($json, true);

        return is_array($decoded) ? $decoded : [];
    }

    private function trustBundleMetadata(): ?array
    {
        return $this->trustBundleService->metadata();
    }

    private function deviceTime(mixed $value): ?string
    {
        $value = trim((string) ($value ?? ''));

        if ($value === '') {
            return null;
        }

        try {
            return (new DateTimeImmutable($value))->format('Y-m-d H:i:s');
        } catch (\Throwable) {
            return null;
        }
    }

    private function nullableString(mixed $value): ?string
    {
        $value = trim((string) ($value ?? ''));

        return $value === '' ? null : $value;
    }

    private function serverTime(): string
    {
        return (new DateTimeImmutable())->format(DATE_ATOM);
    }

    private function clockLine(): string
    {
        return (new DateTimeImmutable())->format('d.m.Y H:i');
    }

    /**
     * @param list<string> $lines
     * @return list<string>
     */
    private function lcdLines(array $lines): array
    {
        $normalized = array_slice(array_map(
            static fn (string $line): string => mb_substr(trim(preg_replace('/\s+/', ' ', $line) ?? ''), 0, 20),
            $lines
        ), 0, 4);

        while (count($normalized) < 4) {
            $normalized[] = '';
        }

        return $normalized;
    }

    private function minutesToHours(int $minutes): string
    {
        $hours = intdiv(max(0, $minutes), 60);
        $remaining = max(0, $minutes) % 60;

        return sprintf('%d:%02d', $hours, $remaining);
    }

    private function signedMinutesToHours(int $minutes): string
    {
        $sign = $minutes < 0 ? '-' : '+';
        $absolute = abs($minutes);

        return $sign . sprintf('%d:%02d', intdiv($absolute, 60), $absolute % 60);
    }
}
