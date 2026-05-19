<?php

declare(strict_types=1);

namespace App\Domain\Timesheets;

use App\Infrastructure\Database\DatabaseConnection;
use DateTimeImmutable;

final class TimesheetSignatureService
{
    private const MAX_DATA_URL_LENGTH = 1400000;
    private const MAX_BINARY_BYTES = 1048576;
    private const MIN_BINARY_BYTES = 200;
    private const MAX_PNG_WIDTH = 4096;
    private const MAX_PNG_HEIGHT = 4096;
    private const MAX_PNG_PIXELS = 4194304;
    private const CONFIRMATION_TEXT = 'Ich bestaetige, dass die angezeigten Zeiten fuer diese Leistung stimmen.';

    public function __construct(
        private DatabaseConnection $connection,
        private array $uploadConfig,
        private string $hashSecret = ''
    ) {
    }

    public function statusForApp(int $timesheetId, int $userId): array
    {
        $timesheet = $this->findVisibleTimesheetForUser($timesheetId, $userId);

        if ($timesheet === null) {
            throw new TimesheetSignatureException('Die Zeitbuchung wurde nicht gefunden.', 404);
        }

        return $this->statusPayload($timesheet);
    }

    public function storeForApp(int $timesheetId, int $userId, array $payload, array $clientMeta = []): array
    {
        $timesheet = $this->findVisibleTimesheetForUser($timesheetId, $userId);

        if ($timesheet === null) {
            throw new TimesheetSignatureException('Die Zeitbuchung wurde nicht gefunden.', 404);
        }

        $this->assertSignableTimesheet($timesheet);

        $clientRequestId = $this->nullableString($payload['client_request_id'] ?? null, 100);

        if ($clientRequestId !== null) {
            $existingByRequest = $this->findByClientRequestId($clientRequestId, $userId);

            if ($existingByRequest !== null) {
                if ((int) ($existingByRequest['timesheet_id'] ?? 0) !== $timesheetId) {
                    throw new TimesheetSignatureException('Diese Anfrage-ID wurde bereits fuer eine andere Zeitbuchung verwendet.', 409);
                }

                if (($existingByRequest['archived_at'] ?? null) !== null) {
                    throw new TimesheetSignatureException('Diese Kundenbestaetigung wurde bereits archiviert und kann nicht erneut verwendet werden.', 409);
                }

                return [
                    'signature' => $this->publicSignature($existingByRequest),
                    'status' => $this->statusPayload($timesheet),
                    'idempotent' => true,
                ];
            }
        }

        $existing = $this->findActiveForTimesheet($timesheetId);

        if ($existing !== null) {
            throw new TimesheetSignatureException('Fuer diese Zeitbuchung ist bereits eine Kundenbestaetigung gespeichert.', 409);
        }

        $customerName = $this->normalizeCustomerName($payload['customer_name'] ?? null);
        $customerNameSource = $this->normalizeNameSource($payload['customer_name_source'] ?? null);
        $confirmationText = $this->normalizeConfirmationText($payload['confirmation_text'] ?? null);
        $signatureBinary = $this->decodePngDataUrl($payload['signature_png'] ?? null);
        $clientSignedAt = $this->normalizeDateTime($payload['client_signed_at'] ?? null);
        $stored = $this->storeSignatureFile((int) $timesheet['id'], $signatureBinary);
        $now = (new DateTimeImmutable())->format('Y-m-d H:i:s');
        $ipHash = $this->hashIp((string) ($clientMeta['ip'] ?? ''));
        $userAgent = $this->nullableString($clientMeta['user_agent'] ?? null, 500);
        $signatureId = 0;
        $inserted = false;

        try {
            $signatureId = (int) $this->connection->transaction(function () use (
                $timesheetId,
                $userId,
                $clientRequestId,
                $customerName,
                $customerNameSource,
                $confirmationText,
                $stored,
                $now,
                $clientSignedAt,
                $ipHash,
                $userAgent,
                &$inserted
            ): int {
                $lockedTimesheet = $this->findVisibleTimesheetForUser($timesheetId, $userId, true);

                if ($lockedTimesheet === null) {
                    throw new TimesheetSignatureException('Die Zeitbuchung wurde nicht gefunden.', 404);
                }

                $this->assertSignableTimesheet($lockedTimesheet);

                if ($clientRequestId !== null) {
                    $existingByRequest = $this->findByClientRequestId($clientRequestId, $userId);

                    if ($existingByRequest !== null) {
                        if ((int) ($existingByRequest['timesheet_id'] ?? 0) === $timesheetId && ($existingByRequest['archived_at'] ?? null) === null) {
                            return (int) ($existingByRequest['id'] ?? 0);
                        }

                        throw new TimesheetSignatureException('Diese Anfrage-ID wurde bereits verwendet.', 409);
                    }
                }

                if ($this->findActiveForTimesheet($timesheetId) !== null) {
                    throw new TimesheetSignatureException('Fuer diese Zeitbuchung ist bereits eine Kundenbestaetigung gespeichert.', 409);
                }

                $this->connection->execute(
                    'INSERT INTO timesheet_customer_signatures (
                    timesheet_id,
                    project_id,
                    user_id,
                    customer_name,
                    customer_name_source,
                    confirmation_text,
                    signature_storage_path,
                    signature_mime,
                    signature_sha256,
                    signature_size_bytes,
                    signed_at,
                    client_signed_at,
                    client_request_id,
                    ip_hash,
                    user_agent,
                    created_by_user_id,
                    created_at,
                    archived_at,
                    archived_by_user_id
                 ) VALUES (
                    :timesheet_id,
                    :project_id,
                    :user_id,
                    :customer_name,
                    :customer_name_source,
                    :confirmation_text,
                    :signature_storage_path,
                    "image/png",
                    :signature_sha256,
                    :signature_size_bytes,
                    :signed_at,
                    :client_signed_at,
                    :client_request_id,
                    :ip_hash,
                    :user_agent,
                    :created_by_user_id,
                    :created_at,
                    NULL,
                    NULL
                 )',
                    [
                        'timesheet_id' => (int) $lockedTimesheet['id'],
                        'project_id' => isset($lockedTimesheet['project_id']) ? (int) $lockedTimesheet['project_id'] : null,
                        'user_id' => $userId,
                        'customer_name' => $customerName,
                        'customer_name_source' => $customerNameSource,
                        'confirmation_text' => $confirmationText,
                        'signature_storage_path' => $stored['path'],
                        'signature_sha256' => $stored['sha256'],
                        'signature_size_bytes' => $stored['size'],
                        'signed_at' => $now,
                        'client_signed_at' => $clientSignedAt,
                        'client_request_id' => $clientRequestId,
                        'ip_hash' => $ipHash,
                        'user_agent' => $userAgent,
                        'created_by_user_id' => $userId,
                        'created_at' => $now,
                    ]
                );

                $inserted = true;

                return (int) $this->connection->lastInsertId();
            });
        } catch (\Throwable $throwable) {
            if (is_file((string) $stored['path'])) {
                @unlink((string) $stored['path']);
            }

            if ($throwable instanceof TimesheetSignatureException) {
                throw $throwable;
            }

            if ($this->isDuplicateKeyException($throwable)) {
                $existingByRequest = $clientRequestId !== null ? $this->findByClientRequestId($clientRequestId, $userId) : null;

                if ($existingByRequest !== null && (int) ($existingByRequest['timesheet_id'] ?? 0) === $timesheetId && ($existingByRequest['archived_at'] ?? null) === null) {
                    return [
                        'signature' => $this->publicSignature($existingByRequest),
                        'status' => $this->statusPayload($timesheet),
                        'idempotent' => true,
                    ];
                }

                throw new TimesheetSignatureException('Fuer diese Zeitbuchung ist bereits eine Kundenbestaetigung gespeichert oder die Anfrage-ID wurde bereits verwendet.', 409);
            }

            if ($this->findActiveForTimesheet($timesheetId) !== null) {
                throw new TimesheetSignatureException('Fuer diese Zeitbuchung ist bereits eine Kundenbestaetigung gespeichert.', 409);
            }

            throw $throwable;
        }

        $signature = $this->find($signatureId);

        if (!$inserted) {
            if (is_file((string) $stored['path'])) {
                @unlink((string) $stored['path']);
            }

            return [
                'signature' => $signature !== null ? $this->publicSignature($signature) : null,
                'status' => $this->statusPayload($timesheet),
                'idempotent' => true,
            ];
        }

        return [
            'signature' => $signature !== null ? $this->publicSignature($signature) : null,
            'status' => $this->statusPayload($timesheet),
            'idempotent' => false,
        ];
    }

    public function findImageForApp(int $signatureId, int $userId): ?array
    {
        $signature = $this->find($signatureId);

        if ($signature === null || ($signature['archived_at'] ?? null) !== null) {
            return null;
        }

        if ((int) ($signature['user_id'] ?? 0) !== $userId) {
            return null;
        }

        if (!$this->isActiveTimesheetVisibleForUser((int) ($signature['timesheet_id'] ?? 0), $userId)) {
            return null;
        }

        return $this->downloadableSignature($signature);
    }

    public function findImageForAdmin(int $signatureId): ?array
    {
        $signature = $this->find($signatureId);

        if ($signature === null || $signature['archived_at'] !== null) {
            return null;
        }

        return $this->downloadableSignature($signature);
    }

    public function archive(int $signatureId, ?int $adminUserId): bool
    {
        if (!$this->connection->tableExists('timesheet_customer_signatures')) {
            return false;
        }

        return $this->connection->execute(
            'UPDATE timesheet_customer_signatures
             SET archived_at = NOW(), archived_by_user_id = :archived_by_user_id
             WHERE id = :id AND archived_at IS NULL',
            [
                'id' => $signatureId,
                'archived_by_user_id' => $adminUserId,
            ]
        );
    }

    public function archiveActiveForTimesheet(int $timesheetId, ?int $archivedByUserId): bool
    {
        if ($timesheetId <= 0 || !$this->connection->tableExists('timesheet_customer_signatures')) {
            return false;
        }

        return $this->connection->execute(
            'UPDATE timesheet_customer_signatures
             SET archived_at = NOW(), archived_by_user_id = :archived_by_user_id
             WHERE timesheet_id = :timesheet_id
               AND archived_at IS NULL',
            [
                'timesheet_id' => $timesheetId,
                'archived_by_user_id' => $archivedByUserId,
            ]
        );
    }

    public function listForTimesheetsGrouped(array $timesheetIds, bool $adminUrls = true): array
    {
        $timesheetIds = array_values(array_unique(array_filter(
            array_map(static fn (mixed $id): int => (int) $id, $timesheetIds),
            static fn (int $id): bool => $id > 0
        )));

        if ($timesheetIds === [] || !$this->connection->tableExists('timesheet_customer_signatures')) {
            return [];
        }

        $placeholders = [];
        $bindings = [];

        foreach ($timesheetIds as $index => $timesheetId) {
            $key = 'id_' . $index;
            $placeholders[] = ':' . $key;
            $bindings[$key] = $timesheetId;
        }

        $rows = $this->connection->fetchAll(
            'SELECT *
             FROM timesheet_customer_signatures
             WHERE timesheet_id IN (' . implode(', ', $placeholders) . ')
               AND archived_at IS NULL
             ORDER BY signed_at DESC, id DESC',
            $bindings
        );

        $grouped = [];

        foreach ($rows as $row) {
            $timesheetId = (int) ($row['timesheet_id'] ?? 0);

            if ($timesheetId > 0 && !isset($grouped[$timesheetId])) {
                $grouped[$timesheetId] = $this->publicSignature($row, $adminUrls);
            }
        }

        return $grouped;
    }

    public function findActiveForTimesheet(int $timesheetId): ?array
    {
        if (!$this->connection->tableExists('timesheet_customer_signatures')) {
            return null;
        }

        return $this->connection->fetchOne(
            'SELECT *
             FROM timesheet_customer_signatures
             WHERE timesheet_id = :timesheet_id
               AND archived_at IS NULL
             ORDER BY signed_at DESC, id DESC
             LIMIT 1',
            ['timesheet_id' => $timesheetId]
        );
    }

    private function statusPayload(array $timesheet): array
    {
        $signature = $this->findActiveForTimesheet((int) $timesheet['id']);
        $suggested = $this->suggestedCustomerName($timesheet);

        return [
            'can_sign' => $this->isSignableTimesheet($timesheet) && $signature === null,
            'suggested_customer_name' => $suggested['name'],
            'suggested_customer_name_source' => $suggested['source'],
            'signature' => $signature !== null ? $this->publicSignature($signature) : null,
            'timesheet_summary' => $this->timesheetSummary($timesheet),
        ];
    }

    private function findVisibleTimesheetForUser(int $timesheetId, int $userId, bool $forUpdate = false): ?array
    {
        if ($timesheetId <= 0 || $userId <= 0 || !$this->connection->tableExists('timesheets')) {
            return null;
        }

        $projectColumns = $this->projectSignatureColumns();

        return $this->connection->fetchOne(
            'SELECT
                timesheets.*,
                projects.name AS project_name,
                projects.project_number,
                projects.customer_name AS project_customer_name,
                ' . $projectColumns['required'] . ' AS project_customer_signature_required,
                ' . $projectColumns['name'] . ' AS project_customer_signature_name,
                users.first_name,
                users.last_name,
                users.employee_number
             FROM timesheets
             LEFT JOIN projects ON projects.id = timesheets.project_id
             LEFT JOIN users ON users.id = timesheets.user_id
             WHERE timesheets.id = :id
               AND timesheets.user_id = :user_id
               AND COALESCE(timesheets.is_deleted, 0) = 0
             LIMIT 1' . ($forUpdate ? ' FOR UPDATE' : ''),
            [
                'id' => $timesheetId,
                'user_id' => $userId,
            ]
        );
    }

    private function projectSignatureColumns(): array
    {
        $required = $this->connection->columnExists('projects', 'customer_signature_required')
            ? 'COALESCE(projects.customer_signature_required, 0)'
            : '0';
        $name = $this->connection->columnExists('projects', 'customer_signature_name')
            ? 'projects.customer_signature_name'
            : 'NULL';

        return [
            'required' => $required,
            'name' => $name,
        ];
    }

    private function findByClientRequestId(string $clientRequestId, int $userId): ?array
    {
        if (!$this->connection->tableExists('timesheet_customer_signatures')) {
            return null;
        }

        return $this->connection->fetchOne(
            'SELECT *
             FROM timesheet_customer_signatures
             WHERE client_request_id = :client_request_id
               AND user_id = :user_id
             ORDER BY id DESC
             LIMIT 1',
            [
                'client_request_id' => $clientRequestId,
                'user_id' => $userId,
            ]
        );
    }

    private function isActiveTimesheetVisibleForUser(int $timesheetId, int $userId): bool
    {
        if ($timesheetId <= 0 || $userId <= 0 || !$this->connection->tableExists('timesheets')) {
            return false;
        }

        $count = $this->connection->fetchColumn(
            'SELECT COUNT(*)
             FROM timesheets
             WHERE id = :timesheet_id
               AND user_id = :user_id
               AND COALESCE(is_deleted, 0) = 0',
            [
                'timesheet_id' => $timesheetId,
                'user_id' => $userId,
            ]
        );

        return (int) ($count ?? 0) > 0;
    }

    private function find(int $signatureId): ?array
    {
        if ($signatureId <= 0 || !$this->connection->tableExists('timesheet_customer_signatures')) {
            return null;
        }

        return $this->connection->fetchOne(
            'SELECT * FROM timesheet_customer_signatures WHERE id = :id LIMIT 1',
            ['id' => $signatureId]
        );
    }

    private function assertSignableTimesheet(array $timesheet): void
    {
        if (!$this->isSignableTimesheet($timesheet)) {
            throw new TimesheetSignatureException('Kundenbestaetigungen sind nur fuer abgeschlossene Arbeitsbuchungen moeglich.', 422);
        }
    }

    private function isSignableTimesheet(array $timesheet): bool
    {
        return (string) ($timesheet['entry_type'] ?? '') === 'work'
            && trim((string) ($timesheet['end_time'] ?? '')) !== ''
            && (int) ($timesheet['is_deleted'] ?? 0) === 0;
    }

    private function suggestedCustomerName(array $timesheet): array
    {
        $signatureName = trim((string) ($timesheet['project_customer_signature_name'] ?? ''));

        if ($signatureName !== '') {
            return [
                'name' => $signatureName,
                'source' => 'project_default',
            ];
        }

        $customerName = trim((string) ($timesheet['project_customer_name'] ?? ''));

        if ($customerName !== '') {
            return [
                'name' => $customerName,
                'source' => 'project_default',
            ];
        }

        return [
            'name' => '',
            'source' => 'manual',
        ];
    }

    private function timesheetSummary(array $timesheet): array
    {
        $employeeName = trim(((string) ($timesheet['first_name'] ?? '')) . ' ' . ((string) ($timesheet['last_name'] ?? '')));

        return [
            'id' => (int) ($timesheet['id'] ?? 0),
            'project_id' => isset($timesheet['project_id']) ? (int) $timesheet['project_id'] : null,
            'project_name' => trim((string) ($timesheet['project_name'] ?? '')) ?: 'Nicht zugeordnet',
            'project_number' => (string) ($timesheet['project_number'] ?? ''),
            'work_date' => (string) ($timesheet['work_date'] ?? ''),
            'start_time' => $timesheet['start_time'] ?? null,
            'end_time' => $timesheet['end_time'] ?? null,
            'break_minutes' => (int) ($timesheet['break_minutes'] ?? 0),
            'net_minutes' => (int) ($timesheet['net_minutes'] ?? 0),
            'employee_name' => $employeeName !== '' ? $employeeName : 'Unbekannter Benutzer',
            'customer_signature_required' => (int) ($timesheet['project_customer_signature_required'] ?? 0) === 1,
        ];
    }

    private function publicSignature(array $signature, bool $admin = false): array
    {
        $id = (int) ($signature['id'] ?? 0);

        return [
            'id' => $id,
            'timesheet_id' => (int) ($signature['timesheet_id'] ?? 0),
            'project_id' => isset($signature['project_id']) ? (int) $signature['project_id'] : null,
            'user_id' => (int) ($signature['user_id'] ?? 0),
            'customer_name' => (string) ($signature['customer_name'] ?? ''),
            'customer_name_source' => (string) ($signature['customer_name_source'] ?? 'manual'),
            'confirmation_text' => (string) ($signature['confirmation_text'] ?? self::CONFIRMATION_TEXT),
            'signed_at' => $signature['signed_at'] ?? null,
            'client_signed_at' => $signature['client_signed_at'] ?? null,
            'sha256' => (string) ($signature['signature_sha256'] ?? ''),
            'size_bytes' => (int) ($signature['signature_size_bytes'] ?? 0),
            'mime_type' => (string) ($signature['signature_mime'] ?? 'image/png'),
            'image_url' => $id > 0 ? ($admin ? '/admin/timesheet-signatures/' . $id . '/image' : '/api/v1/app/timesheet-signatures/' . $id . '/image') : null,
            'archived_at' => $signature['archived_at'] ?? null,
        ];
    }

    private function downloadableSignature(array $signature): ?array
    {
        $path = (string) ($signature['signature_storage_path'] ?? '');
        $root = rtrim($this->signatureRoot(), '/');
        $realRoot = realpath($root);
        $realPath = realpath($path);

        if ($realRoot === false || $realPath === false || !is_file($realPath)) {
            return null;
        }

        $normalizedRoot = rtrim($realRoot, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;

        if (!str_starts_with($realPath, $normalizedRoot)) {
            return null;
        }

        return [
            'path' => $realPath,
            'mime_type' => 'image/png',
            'size_bytes' => (int) filesize($realPath),
            'filename' => 'kundenbestaetigung-' . (int) ($signature['timesheet_id'] ?? 0) . '.png',
        ];
    }

    private function normalizeCustomerName(mixed $value): string
    {
        $name = trim((string) ($value ?? ''));
        $name = preg_replace('/\s+/', ' ', $name) ?? $name;

        if (mb_strlen($name) < 2 || mb_strlen($name) > 190) {
            throw new TimesheetSignatureException('Bitte den Namen in Druckbuchstaben eintragen.', 422);
        }

        return $name;
    }

    private function normalizeNameSource(mixed $value): string
    {
        $source = trim((string) ($value ?? 'manual'));

        return in_array($source, ['project_default', 'manual'], true) ? $source : 'manual';
    }

    private function normalizeConfirmationText(mixed $value): string
    {
        unset($value);

        return self::CONFIRMATION_TEXT;
    }

    private function decodePngDataUrl(mixed $value): string
    {
        $dataUrl = trim((string) ($value ?? ''));

        if ($dataUrl === '' || strlen($dataUrl) > self::MAX_DATA_URL_LENGTH) {
            throw new TimesheetSignatureException('Die Unterschrift ist leer oder zu gross.', 422);
        }

        if (!preg_match('/^data:image\/png;base64,([A-Za-z0-9+\/=]+)$/', $dataUrl, $matches)) {
            throw new TimesheetSignatureException('Bitte eine gueltige PNG-Unterschrift uebermitteln.', 422);
        }

        $binary = base64_decode($matches[1], true);

        if (!is_string($binary) || $binary === '') {
            throw new TimesheetSignatureException('Bitte eine gueltige PNG-Unterschrift uebermitteln.', 422);
        }

        $size = strlen($binary);

        if ($size > self::MAX_BINARY_BYTES) {
            throw new TimesheetSignatureException('Die Unterschrift ist zu gross.', 422);
        }

        if ($size < self::MIN_BINARY_BYTES || substr($binary, 0, 8) !== "\x89PNG\r\n\x1a\n") {
            throw new TimesheetSignatureException('Bitte eine gueltige PNG-Unterschrift uebermitteln.', 422);
        }

        $this->assertPngDimensionsAreSafe($binary);
        $this->assertPngHasInk($binary);

        return $binary;
    }

    private function assertPngDimensionsAreSafe(string $binary): void
    {
        if (strlen($binary) < 33 || substr($binary, 12, 4) !== 'IHDR') {
            throw new TimesheetSignatureException('Bitte eine gueltige PNG-Unterschrift uebermitteln.', 422);
        }

        $width = unpack('N', substr($binary, 16, 4))[1] ?? 0;
        $height = unpack('N', substr($binary, 20, 4))[1] ?? 0;

        if (
            $width < 1
            || $height < 1
            || $width > self::MAX_PNG_WIDTH
            || $height > self::MAX_PNG_HEIGHT
            || ($width * $height) > self::MAX_PNG_PIXELS
        ) {
            throw new TimesheetSignatureException('Die Unterschrift ist zu gross.', 422);
        }
    }

    private function assertPngHasInk(string $binary): void
    {
        if (!function_exists('imagecreatefromstring') || !function_exists('imagecolorat') || !function_exists('imagesx') || !function_exists('imagesy')) {
            return;
        }

        $image = @imagecreatefromstring($binary);

        if ($image === false) {
            throw new TimesheetSignatureException('Bitte eine gueltige PNG-Unterschrift uebermitteln.', 422);
        }

        $width = imagesx($image);
        $height = imagesy($image);
        $darkPixels = 0;
        $sampleStepX = max(1, (int) floor($width / 80));
        $sampleStepY = max(1, (int) floor($height / 40));

        for ($y = 0; $y < $height; $y += $sampleStepY) {
            for ($x = 0; $x < $width; $x += $sampleStepX) {
                $color = imagecolorat($image, $x, $y);
                $red = ($color >> 16) & 0xFF;
                $green = ($color >> 8) & 0xFF;
                $blue = $color & 0xFF;

                if ($red < 245 || $green < 245 || $blue < 245) {
                    $darkPixels++;
                }

                if ($darkPixels >= 3) {
                    imagedestroy($image);
                    return;
                }
            }
        }

        imagedestroy($image);

        throw new TimesheetSignatureException('Bitte zuerst auf der Flaeche unterschreiben.', 422);
    }

    private function storeSignatureFile(int $timesheetId, string $binary): array
    {
        $targetDirectory = rtrim($this->signatureRoot(), '/') . '/' . date('Y/m') . '/timesheet-' . $timesheetId;

        if (!is_dir($targetDirectory) && !mkdir($targetDirectory, 0775, true) && !is_dir($targetDirectory)) {
            throw new TimesheetSignatureException('Signaturverzeichnis konnte nicht angelegt werden.', 422);
        }

        $storedName = 'signature_' . bin2hex(random_bytes(16)) . '.png';
        $targetPath = $targetDirectory . '/' . $storedName;

        if (file_put_contents($targetPath, $binary, LOCK_EX) === false) {
            throw new TimesheetSignatureException('Unterschrift konnte nicht gespeichert werden.', 422);
        }

        return [
            'path' => $targetPath,
            'sha256' => hash_file('sha256', $targetPath) ?: hash('sha256', $binary),
            'size' => strlen($binary),
        ];
    }

    private function signatureRoot(): string
    {
        $root = (string) ($this->uploadConfig['root'] ?? storage_path('app/uploads'));

        return rtrim($root, '/') . '/timesheet-signatures';
    }

    private function normalizeDateTime(mixed $value): ?string
    {
        $value = trim((string) ($value ?? ''));

        if ($value === '') {
            return null;
        }

        try {
            return (new DateTimeImmutable($value))->format('Y-m-d H:i:s');
        } catch (\Exception) {
            return null;
        }
    }

    private function nullableString(mixed $value, int $maxLength): ?string
    {
        $value = trim((string) ($value ?? ''));

        if ($value === '') {
            return null;
        }

        return mb_substr($value, 0, $maxLength);
    }

    private function hashIp(string $ip): ?string
    {
        $ip = trim($ip);

        if ($ip === '') {
            return null;
        }

        $secret = $this->hashSecret !== '' ? $this->hashSecret : 'timeapp-signature-ip';

        return hash_hmac('sha256', $ip, $secret);
    }

    private function isDuplicateKeyException(\Throwable $throwable): bool
    {
        if (!$throwable instanceof \PDOException) {
            return false;
        }

        $errorInfo = $throwable->errorInfo ?? [];

        return (string) ($errorInfo[0] ?? $throwable->getCode()) === '23000'
            || (int) ($errorInfo[1] ?? 0) === 1062;
    }
}
