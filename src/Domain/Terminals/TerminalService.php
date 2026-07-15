<?php

declare(strict_types=1);

namespace App\Domain\Terminals;

use App\Domain\Settings\CompanySettingsService;
use App\Http\Request;
use App\Infrastructure\Database\DatabaseConnection;
use RuntimeException;

final class TerminalService
{
    public function __construct(
        private DatabaseConnection $connection,
        private CompanySettingsService $companySettingsService
    ) {
    }

    public function featureEnabled(): bool
    {
        $settings = $this->companySettingsService->current();

        return (int) ($settings['terminal_enabled'] ?? 0) === 1;
    }

    public function assertFeatureEnabled(): void
    {
        if (!$this->featureEnabled()) {
            throw new RuntimeException('terminal_disabled');
        }
    }

    public function list(string $scope = 'active'): array
    {
        if (!$this->connection->tableExists('terminals')) {
            return [];
        }

        $where = match ($scope) {
            'archived' => 'COALESCE(terminals.is_deleted, 0) = 1',
            'all' => '1 = 1',
            default => 'COALESCE(terminals.is_deleted, 0) = 0',
        };

        return $this->connection->fetchAll(
            'SELECT terminals.*, projects.project_number, projects.name AS project_name
             FROM terminals
             LEFT JOIN projects ON projects.id = terminals.default_project_id
             WHERE ' . $where . '
             ORDER BY terminals.is_deleted ASC, terminals.name ASC, terminals.id ASC'
        );
    }

    public function activeCount(): int
    {
        if (!$this->connection->tableExists('terminals')) {
            return 0;
        }

        return (int) ($this->connection->fetchColumn(
            'SELECT COUNT(*) FROM terminals WHERE COALESCE(is_deleted, 0) = 0 AND COALESCE(is_active, 1) = 1'
        ) ?? 0);
    }

    public function find(int $id): ?array
    {
        if ($id <= 0 || !$this->connection->tableExists('terminals')) {
            return null;
        }

        return $this->connection->fetchOne(
            'SELECT * FROM terminals WHERE id = :id LIMIT 1',
            ['id' => $id]
        );
    }

    public function create(array $payload): array
    {
        $this->assertStorageReady();
        $record = $this->normalize($payload);
        $plainToken = $this->newPlainToken();

        $this->connection->execute(
            'INSERT INTO terminals (
                terminal_identifier, name, ip_allowlist, token_hash, welcome_text, default_project_id, settings_json, is_active, created_at, updated_at
             ) VALUES (
                :terminal_identifier, :name, :ip_allowlist, :token_hash, :welcome_text, :default_project_id, :settings_json, :is_active, NOW(), NOW()
             )',
            [
                ...$record,
                'token_hash' => password_hash($plainToken, PASSWORD_DEFAULT),
            ]
        );

        return [
            'terminal' => $this->find($this->connection->lastInsertId()),
            'plain_token' => $plainToken,
        ];
    }

    public function update(int $id, array $payload): array
    {
        $this->assertStorageReady();
        $record = $this->normalize($payload);

        $this->connection->execute(
            'UPDATE terminals
             SET terminal_identifier = :terminal_identifier,
                 name = :name,
                 ip_allowlist = :ip_allowlist,
                 welcome_text = :welcome_text,
                 default_project_id = :default_project_id,
                 settings_json = :settings_json,
                 is_active = :is_active,
                 updated_at = NOW()
             WHERE id = :id',
            ['id' => $id, ...$record]
        );

        return $this->find($id) ?? [];
    }

    public function archive(int $id, ?int $deletedByUserId): void
    {
        if ($id <= 0 || !$this->connection->tableExists('terminals')) {
            return;
        }

        $this->connection->execute(
            'UPDATE terminals
             SET is_deleted = 1, deleted_at = NOW(), deleted_by_user_id = :deleted_by_user_id, updated_at = NOW()
             WHERE id = :id',
            [
                'id' => $id,
                'deleted_by_user_id' => $deletedByUserId,
            ]
        );
    }

    public function restore(int $id): bool
    {
        if ($id <= 0 || !$this->connection->tableExists('terminals')) {
            return false;
        }

        return $this->connection->transaction(function () use ($id): bool {
            $terminal = $this->connection->fetchOne(
                'SELECT id
                 FROM terminals
                 WHERE id = :id
                   AND COALESCE(is_deleted, 0) = 1
                 FOR UPDATE',
                ['id' => $id]
            );

            if ($terminal === null) {
                return false;
            }

            $this->connection->execute(
                'UPDATE terminals
                 SET is_deleted = 0, deleted_at = NULL, deleted_by_user_id = NULL, updated_at = NOW()
                 WHERE id = :id
                   AND COALESCE(is_deleted, 0) = 1',
                ['id' => $id]
            );

            return true;
        });
    }

    public function resetToken(int $id): string
    {
        $this->assertStorageReady();
        $plainToken = $this->newPlainToken();

        $this->connection->execute(
            'UPDATE terminals SET token_hash = :token_hash, updated_at = NOW() WHERE id = :id',
            [
                'id' => $id,
                'token_hash' => password_hash($plainToken, PASSWORD_DEFAULT),
            ]
        );

        return $plainToken;
    }

    public function authenticate(Request $request): array
    {
        $this->assertFeatureEnabled();

        if (!$this->connection->tableExists('terminals')) {
            throw new RuntimeException('terminal_storage_missing');
        }

        $terminalIdentifier = $this->normalizeIdentifier((string) $this->header($request, 'X-Terminal-ID', ''));
        $token = $this->bearerToken($request);

        if ($terminalIdentifier === '' || $token === '') {
            throw new RuntimeException('terminal_auth_required');
        }

        $terminal = $this->connection->fetchOne(
            'SELECT *
             FROM terminals
             WHERE terminal_identifier = :terminal_identifier
               AND COALESCE(is_deleted, 0) = 0
               AND COALESCE(is_active, 1) = 1
             LIMIT 1',
            ['terminal_identifier' => $terminalIdentifier]
        );

        if ($terminal === null || !password_verify($token, (string) ($terminal['token_hash'] ?? ''))) {
            throw new RuntimeException('terminal_auth_failed');
        }

        $remoteIp = (string) $request->server('REMOTE_ADDR', '');

        if (!$this->ipAllowed((string) ($terminal['ip_allowlist'] ?? ''), $remoteIp)) {
            throw new RuntimeException('terminal_ip_denied');
        }

        $this->connection->execute(
            'UPDATE terminals SET last_seen_at = NOW(), last_seen_ip = :last_seen_ip, updated_at = NOW() WHERE id = :id',
            [
                'id' => (int) $terminal['id'],
                'last_seen_ip' => $remoteIp !== '' ? $remoteIp : null,
            ]
        );

        $terminal['last_seen_ip'] = $remoteIp;
        $this->recordTransportDiagnostics($terminal, $request);

        return $terminal;
    }

    private function assertStorageReady(): void
    {
        if (!$this->connection->tableExists('terminals')) {
            throw new RuntimeException('Die Terminal-Tabellen sind noch nicht verfuegbar. Bitte zuerst die Migration ausfuehren.');
        }
    }

    private function normalize(array $payload): array
    {
        $identifier = $this->normalizeIdentifier((string) ($payload['terminal_identifier'] ?? ''));
        $name = trim((string) ($payload['name'] ?? ''));

        if ($identifier === '') {
            throw new RuntimeException('Bitte eine Terminal-ID angeben.');
        }

        if (!preg_match('/^[a-z0-9._-]{3,80}$/', $identifier)) {
            throw new RuntimeException('Die Terminal-ID darf nur Kleinbuchstaben, Zahlen, Punkte, Unterstriche und Bindestriche enthalten.');
        }

        if ($name === '') {
            throw new RuntimeException('Bitte einen Terminal-Namen angeben.');
        }

        $projectId = $this->normalizeProjectId($payload['default_project_id'] ?? null);

        if ($projectId !== null) {
            $this->assertActiveProject($projectId);
        }

        return [
            'terminal_identifier' => $identifier,
            'name' => $name,
            'ip_allowlist' => $this->nullableString($payload['ip_allowlist'] ?? null),
            'welcome_text' => trim((string) ($payload['welcome_text'] ?? 'Willkommen')) ?: 'Willkommen',
            'default_project_id' => $projectId,
            'settings_json' => $this->normalizeSettingsJson($payload['settings_json'] ?? ''),
            'is_active' => (string) ($payload['is_active'] ?? '0') === '1' ? 1 : 0,
        ];
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
            throw new RuntimeException('Das Terminal-Projekt muss aktiv und nicht archiviert sein.');
        }
    }

    private function normalizeSettingsJson(mixed $value): ?string
    {
        $json = trim((string) ($value ?? ''));

        if ($json === '') {
            return null;
        }

        json_decode($json, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new RuntimeException('Die Terminal-Settings muessen gueltiges JSON sein.');
        }

        return $json;
    }

    private function normalizeProjectId(mixed $value): ?int
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

    private function normalizeIdentifier(string $value): string
    {
        return strtolower(trim($value));
    }

    private function newPlainToken(): string
    {
        return bin2hex(random_bytes(24));
    }

    private function bearerToken(Request $request): string
    {
        $authorization = (string) $this->header($request, 'Authorization', '');

        if (preg_match('/^Bearer\s+(.+)$/i', $authorization, $matches) !== 1) {
            return '';
        }

        return trim($matches[1]);
    }

    private function header(Request $request, string $name, mixed $default = null): mixed
    {
        $headers = [
            $name,
            strtolower($name),
            strtoupper(str_replace('-', '_', $name)),
            'HTTP_' . strtoupper(str_replace('-', '_', $name)),
        ];

        foreach ($headers as $header) {
            $value = $request->header($header, null);

            if ($value !== null) {
                return $value;
            }

            $serverValue = $request->server($header, null);

            if ($serverValue !== null) {
                return $serverValue;
            }
        }

        return $default;
    }

    private function ipAllowed(string $allowlist, string $remoteIp): bool
    {
        $allowlist = trim($allowlist);

        if ($allowlist === '') {
            return true;
        }

        $allowed = array_filter(array_map('trim', explode(',', $allowlist)));

        foreach ($allowed as $entry) {
            if (hash_equals($entry, $remoteIp)) {
                return true;
            }
        }

        return false;
    }

    private function recordTransportDiagnostics(array $terminal, Request $request): void
    {
        $id = (int) ($terminal['id'] ?? 0);
        if ($id <= 0) {
            return;
        }

        $transport = strtolower(trim((string) $this->header($request, 'X-Terminal-Transport', '')));
        if (!in_array($transport, ['http', 'https'], true)) {
            $transport = null;
        }
        $trustVersion = (int) $this->header($request, 'X-Terminal-Trust-Version', '0');
        $queueDepth = (int) $this->header($request, 'X-Terminal-Queue-Depth', '-1');
        $tlsState = mb_substr(trim((string) $this->header($request, 'X-Terminal-TLS-State', '')), 0, 40);
        $warningState = mb_substr(trim((string) $this->header($request, 'X-Terminal-Trust-State', '')), 0, 40);
        $recoveryStatus = mb_substr(trim((string) $this->header($request, 'X-Terminal-Recovery-Status', '')), 0, 80);
        $firmware = mb_substr(trim((string) $this->header($request, 'X-Terminal-Firmware', '')), 0, 120);
        if (!in_array($tlsState, ['not-applicable', 'not-checked', 'time-invalid', 'trust-missing', 'connecting', 'verified', 'validation-failed', 'recovery'], true)) {
            $tlsState = '';
        }
        if (!in_array($warningState, ['current', 'warning', 'replace-required', 'recovery', 'invalid', 'not-applicable', 'not-checked'], true)) {
            $warningState = '';
        }

        try {
            $this->connection->execute(
                'UPDATE terminals
                 SET last_firmware_version = COALESCE(:firmware, last_firmware_version),
                     last_transport = COALESCE(:transport, last_transport),
                     last_tls_state = COALESCE(:tls_state, last_tls_state),
                     active_trust_bundle_version = COALESCE(:trust_version, active_trust_bundle_version),
                     last_trust_checked_at = CASE WHEN :trust_checked = 1 THEN NOW() ELSE last_trust_checked_at END,
                     trust_warning_state = COALESCE(:warning_state, trust_warning_state),
                     offline_queue_depth = COALESCE(:queue_depth, offline_queue_depth),
                     last_https_success_at = CASE WHEN :https_success = 1 THEN NOW() ELSE last_https_success_at END,
                     last_recovery_status = COALESCE(:recovery_status, last_recovery_status)
                 WHERE id = :id',
                [
                    'id' => $id,
                    'firmware' => $firmware !== '' ? $firmware : null,
                    'transport' => $transport,
                    'tls_state' => $tlsState !== '' ? $tlsState : null,
                    'trust_version' => $trustVersion > 0 ? $trustVersion : null,
                    'trust_checked' => $transport === 'https' && ($trustVersion > 0 || $warningState !== '') ? 1 : 0,
                    'warning_state' => $warningState !== '' ? $warningState : null,
                    'queue_depth' => $queueDepth >= 0 ? min($queueDepth, 65535) : null,
                    'https_success' => $transport === 'https' && $tlsState === 'verified' ? 1 : 0,
                    'recovery_status' => $recoveryStatus !== '' ? $recoveryStatus : null,
                ]
            );
        } catch (\Throwable) {
            // Allows old installations to continue while the additive migration is pending.
        }
    }
}
