<?php

declare(strict_types=1);

namespace App\Domain\Terminals;

use App\Infrastructure\Database\DatabaseConnection;
use DateTimeImmutable;
use RuntimeException;

final class NfcTagService
{
    public function __construct(
        private DatabaseConnection $connection,
        private string $hashKey
    ) {
        $this->hashKey = trim($hashKey);
    }

    public function list(string $scope = 'active'): array
    {
        if (!$this->connection->tableExists('nfc_tags')) {
            return [];
        }

        $where = match ($scope) {
            'pending' => 'COALESCE(nfc_tags.is_deleted, 0) = 0 AND nfc_tags.status = "pending"',
            'archived' => 'COALESCE(nfc_tags.is_deleted, 0) = 1',
            'all' => '1 = 1',
            default => 'COALESCE(nfc_tags.is_deleted, 0) = 0',
        };

        return $this->connection->fetchAll(
            'SELECT
                nfc_tags.*,
                CONCAT(users.first_name, " ", users.last_name) AS user_name,
                users.employee_number,
                projects.project_number,
                projects.name AS project_name,
                terminals.name AS learned_terminal_name
             FROM nfc_tags
             LEFT JOIN users ON users.id = nfc_tags.user_id
             LEFT JOIN projects ON projects.id = nfc_tags.project_id
             LEFT JOIN terminals ON terminals.id = nfc_tags.learned_terminal_id
             WHERE ' . $where . '
             ORDER BY nfc_tags.is_deleted ASC, FIELD(nfc_tags.status, "pending", "active", "disabled"), nfc_tags.updated_at DESC, nfc_tags.id DESC'
        );
    }

    public function find(int $id): ?array
    {
        if ($id <= 0 || !$this->connection->tableExists('nfc_tags')) {
            return null;
        }

        return $this->connection->fetchOne(
            'SELECT * FROM nfc_tags WHERE id = :id LIMIT 1',
            ['id' => $id]
        );
    }

    public function findActiveByUid(string $uid): ?array
    {
        if (!$this->connection->tableExists('nfc_tags')) {
            return null;
        }

        return $this->connection->fetchOne(
            'SELECT *
             FROM nfc_tags
             WHERE uid_hash = :uid_hash
               AND COALESCE(is_deleted, 0) = 0
               AND status = "active"
             LIMIT 1',
            ['uid_hash' => $this->hashUid($uid)]
        );
    }

    public function updateAssignment(int $id, array $payload): array
    {
        if (!$this->connection->tableExists('nfc_tags')) {
            throw new RuntimeException('Die NFC-Tag-Tabelle ist noch nicht verfuegbar.');
        }

        $label = $this->nullableString($payload['label'] ?? null);
        $userId = $this->normalizeId($payload['user_id'] ?? null);
        $projectId = $this->normalizeId($payload['project_id'] ?? null);
        $status = (string) ($payload['status'] ?? 'active');

        if (!in_array($status, ['pending', 'active', 'disabled'], true)) {
            $status = 'pending';
        }

        if ($status === 'active' && $userId === null) {
            throw new RuntimeException('Aktive NFC-Tags brauchen eine User-Zuordnung.');
        }

        if ($userId !== null) {
            $this->assertActiveUser($userId);
        }

        if ($projectId !== null) {
            $this->assertActiveProject($projectId);
        }

        $this->connection->execute(
            'UPDATE nfc_tags
             SET label = :label,
                 user_id = :user_id,
                 project_id = :project_id,
                 status = :status,
                 updated_at = NOW()
             WHERE id = :id
               AND COALESCE(is_deleted, 0) = 0',
            [
                'id' => $id,
                'label' => $label,
                'user_id' => $userId,
                'project_id' => $projectId,
                'status' => $status,
            ]
        );

        return $this->find($id) ?? [];
    }

    public function archive(int $id, ?int $deletedByUserId): void
    {
        if ($id <= 0 || !$this->connection->tableExists('nfc_tags')) {
            return;
        }

        $this->connection->execute(
            'UPDATE nfc_tags
             SET is_deleted = 1, deleted_at = NOW(), deleted_by_user_id = :deleted_by_user_id, updated_at = NOW()
             WHERE id = :id',
            [
                'id' => $id,
                'deleted_by_user_id' => $deletedByUserId,
            ]
        );
    }

    public function startLearnSession(int $terminalId, ?int $adminUserId, int $minutes = 2): array
    {
        if (!$this->connection->tableExists('terminal_learn_sessions')) {
            throw new RuntimeException('Die Terminal-Anlerntabelle ist noch nicht verfuegbar.');
        }

        $this->connection->execute(
            'UPDATE terminal_learn_sessions
             SET status = "expired"
             WHERE terminal_id = :terminal_id
               AND status = "pending"',
            ['terminal_id' => $terminalId]
        );

        $expiresAt = (new DateTimeImmutable('+' . max(1, $minutes) . ' minutes'))->format('Y-m-d H:i:s');

        $this->connection->execute(
            'INSERT INTO terminal_learn_sessions (terminal_id, admin_user_id, status, expires_at, created_at)
             VALUES (:terminal_id, :admin_user_id, "pending", :expires_at, NOW())',
            [
                'terminal_id' => $terminalId,
                'admin_user_id' => $adminUserId,
                'expires_at' => $expiresAt,
            ]
        );

        return $this->connection->fetchOne(
            'SELECT * FROM terminal_learn_sessions WHERE id = :id LIMIT 1',
            ['id' => $this->connection->lastInsertId()]
        ) ?? [];
    }

    public function captureLearnScan(array $terminal, string $uid): ?array
    {
        if (!$this->connection->tableExists('terminal_learn_sessions') || !$this->connection->tableExists('nfc_tags')) {
            return null;
        }

        $session = $this->connection->fetchOne(
            'SELECT *
             FROM terminal_learn_sessions
             WHERE terminal_id = :terminal_id
               AND status = "pending"
               AND expires_at >= NOW()
             ORDER BY id DESC
             LIMIT 1',
            ['terminal_id' => (int) ($terminal['id'] ?? 0)]
        );

        if ($session === null) {
            return null;
        }

        $this->connection->execute(
            'INSERT INTO nfc_tags (
                uid_hash, uid_masked, status, learned_terminal_id, learned_by_user_id, learned_at, created_at, updated_at
             ) VALUES (
                :uid_hash, :uid_masked, "pending", :terminal_id, :admin_user_id, NOW(), NOW(), NOW()
             )
             ON DUPLICATE KEY UPDATE
                id = LAST_INSERT_ID(id),
                learned_terminal_id = VALUES(learned_terminal_id),
                learned_by_user_id = VALUES(learned_by_user_id),
                learned_at = NOW(),
                updated_at = NOW()',
            [
                'uid_hash' => $this->hashUid($uid),
                'uid_masked' => $this->maskUid($uid),
                'terminal_id' => (int) ($terminal['id'] ?? 0),
                'admin_user_id' => $session['admin_user_id'] ?? null,
            ]
        );
        $tagId = $this->connection->lastInsertId();

        $this->connection->execute(
            'UPDATE terminal_learn_sessions
             SET status = "captured", nfc_tag_id = :nfc_tag_id, completed_at = NOW()
             WHERE id = :id',
            [
                'id' => (int) $session['id'],
                'nfc_tag_id' => $tagId,
            ]
        );

        return $this->find($tagId);
    }

    public function normalizeUid(string $uid): string
    {
        $normalized = strtoupper(preg_replace('/[^0-9A-Fa-f]/', '', $uid) ?? '');

        if ($normalized === '' || strlen($normalized) < 4 || strlen($normalized) > 32 || strlen($normalized) % 2 !== 0) {
            throw new RuntimeException('Die NFC-UID ist ungueltig.');
        }

        return implode(':', str_split($normalized, 2));
    }

    public function hashUid(string $uid): string
    {
        $this->assertHashKeyConfigured();

        return hash_hmac('sha256', $this->normalizeUid($uid), $this->hashKey);
    }

    public function maskUid(string $uid): string
    {
        $parts = explode(':', $this->normalizeUid($uid));

        if (count($parts) <= 2) {
            return $parts[0] . ':...';
        }

        if (count($parts) === 3) {
            return $parts[0] . ':...:' . $parts[2];
        }

        return $parts[0] . ':' . $parts[1] . ':...:' . $parts[count($parts) - 1];
    }

    private function assertHashKeyConfigured(): void
    {
        if ($this->hashKey === '') {
            throw new RuntimeException('NFC-Hashing benoetigt SETTINGS_ENCRYPTION_KEY oder APP_SECRET.');
        }
    }

    private function assertActiveUser(int $userId): void
    {
        if (!$this->connection->tableExists('users')) {
            throw new RuntimeException('Der ausgewaehlte User ist nicht verfuegbar.');
        }

        $count = (int) ($this->connection->fetchColumn(
            'SELECT COUNT(*)
             FROM users
             WHERE id = :id
               AND COALESCE(is_deleted, 0) = 0
               AND employment_status = "active"
             LIMIT 1',
            ['id' => $userId]
        ) ?? 0);

        if ($count < 1) {
            throw new RuntimeException('Der NFC-Tag kann nur einem aktiven User zugeordnet werden.');
        }
    }

    private function assertActiveProject(int $projectId): void
    {
        if (!$this->connection->tableExists('projects')) {
            throw new RuntimeException('Das ausgewaehlte Projekt ist nicht verfuegbar.');
        }

        $count = (int) ($this->connection->fetchColumn(
            'SELECT COUNT(*)
             FROM projects
             WHERE id = :id
               AND COALESCE(is_deleted, 0) = 0
               AND status <> "archived"
             LIMIT 1',
            ['id' => $projectId]
        ) ?? 0);

        if ($count < 1) {
            throw new RuntimeException('Das NFC-Projekt muss aktiv und nicht archiviert sein.');
        }
    }

    private function normalizeId(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        $id = (int) $value;

        return $id > 0 ? $id : null;
    }

    private function nullableString(mixed $value): ?string
    {
        $value = trim((string) ($value ?? ''));

        return $value === '' ? null : $value;
    }
}
