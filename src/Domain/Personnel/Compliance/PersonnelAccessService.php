<?php

declare(strict_types=1);

namespace App\Domain\Personnel\Compliance;

use App\Domain\Auth\AuthService;
use App\Infrastructure\Database\DatabaseConnection;
use InvalidArgumentException;
use LogicException;
use PDOException;
use RuntimeException;

final class PersonnelAccessService
{
    public const VIEW = 'personnel_documents.view';
    public const MANAGE = 'personnel_documents.manage';
    public const EXPORT = 'personnel_documents.export';
    public const DELEGATE = 'personnel_documents.delegate';
    public const CATALOG = 'personnel_compliance.manage';

    public function __construct(private DatabaseConnection $connection, private AuthService $auth)
    {
        if (!$this->auth->usesConnection($this->connection)) {
            throw new LogicException('Authentifizierung und Personalakte benötigen dieselbe Datenbankverbindung.');
        }
    }

    public function usesConnection(DatabaseConnection $connection): bool
    {
        return $this->connection === $connection;
    }

    public function actorId(): int
    {
        $id = (int) ($this->auth->currentUser()['id'] ?? 0);
        if ($id < 1) {
            throw new PersonnelAccessDenied();
        }

        return $id;
    }

    public function requirePermission(string $permission): int
    {
        if (!in_array($permission, [self::VIEW, self::MANAGE, self::EXPORT, self::DELEGATE, self::CATALOG], true)
            || !$this->auth->hasPermission($permission)) {
            throw new PersonnelAccessDenied();
        }

        return $this->actorId();
    }

    public function requireEmployee(string $permission, int $employeeId, bool $lockScope = false): int
    {
        if (!in_array($permission, [self::VIEW, self::MANAGE, self::EXPORT], true) || $employeeId < 1) {
            throw new PersonnelAccessDenied();
        }

        $lockedAuthority = $lockScope ? $this->auth->lockPersonnelAuthority($permission) : null;
        if ($lockScope && $lockedAuthority === null) {
            throw new PersonnelAccessDenied();
        }
        $actorId = $lockedAuthority['actor_id'] ?? $this->requirePermission($permission);
        $systemAdministrator = $lockedAuthority['system_administrator'] ?? $this->auth->isSystemAdministrator();
        if (!$systemAdministrator && (int) ($this->latestScope($employeeId, $actorId, $lockScope)['is_granted'] ?? 0) !== 1) {
            throw new PersonnelAccessDenied();
        }
        if (!$this->connection->fetchColumn('SELECT id FROM users WHERE id = :employee_id', ['employee_id' => $employeeId])) {
            throw new PersonnelAccessDenied();
        }

        return $actorId;
    }

    public function changeEmployeeScope(int $employeeId, int $granteeId, bool $grant, string $reason, string $requestId): array
    {
        if ($employeeId < 1 || $granteeId < 1 || !in_array($reason, $grant ? ['assignment', 'correction'] : ['revocation', 'correction'], true)) {
            throw new InvalidArgumentException('Ungültiger Vorgang für den Mitarbeiterbereich.');
        }
        $authorize = function (): int {
            $actorId = $this->requirePermission(self::DELEGATE);
            if (!$this->auth->isSystemAdministrator()) {
                throw new PersonnelAccessDenied();
            }

            return $actorId;
        };
        $actorId = $authorize();
        $commands = new PersonnelCommandStore($this->connection);

        return $commands->execute(
            $actorId,
            $requestId,
            'scope.change',
            ['employee_id' => $employeeId, 'grantee_id' => $granteeId, 'granted' => $grant, 'reason_code' => $reason],
            $authorize,
            function () use ($actorId, $employeeId, $granteeId, $grant, $reason): array {
                $users = array_column($this->connection->fetchAll(
                    'SELECT id, is_deleted, employment_status FROM users WHERE id IN (:employee_id, :grantee_id) ORDER BY id FOR UPDATE',
                    ['employee_id' => $employeeId, 'grantee_id' => $granteeId]
                ), null, 'id');
                if (!isset($users[$employeeId], $users[$granteeId])
                    || ($grant && ((int) $users[$granteeId]['is_deleted'] === 1 || $users[$granteeId]['employment_status'] !== 'active'))) {
                    throw new PersonnelAccessDenied();
                }
                $authority = $this->auth->lockPersonnelAuthority(self::DELEGATE);
                if ($authority === null || $authority['actor_id'] !== $actorId || !$authority['system_administrator']) {
                    throw new PersonnelAccessDenied();
                }

                $previous = $this->latestScope($employeeId, $granteeId, true);
                if ((bool) ($previous['is_granted'] ?? false) === $grant) {
                    return ['scope_event_id' => isset($previous['id']) ? (int) $previous['id'] : null, 'is_granted' => $grant];
                }

                $this->connection->execute(
                    'INSERT INTO employee_personnel_scope_events (employee_user_id, grantee_user_id, changed_by_user_id, is_granted, reason_code, created_at)
                     VALUES (:employee_id, :grantee_id, :actor_id, :granted, :reason_code, UTC_TIMESTAMP())',
                    ['employee_id' => $employeeId, 'grantee_id' => $granteeId, 'actor_id' => $actorId, 'granted' => (int) $grant, 'reason_code' => $reason]
                );

                return ['scope_event_id' => $this->connection->lastInsertId(), 'is_granted' => $grant];
            }
        );
    }

    public function auditAccess(string $action, bool $allowed, ?int $documentId = null, ?int $versionId = null): void
    {
        if (preg_match('/^[a-z][a-z0-9._]{0,49}$/D', $action) !== 1) {
            throw new InvalidArgumentException('Ungültige Kennung für das Personalaktenprotokoll.');
        }

        try {
            $actor = $this->actorId();
            $recorded = $this->connection->execute(
                'INSERT INTO employee_document_access_log (document_id, document_version_id, action, actor_user_id, outcome, created_at)
                 VALUES (:document_id, :version_id, :action, :actor_id, :outcome, UTC_TIMESTAMP())',
                ['document_id' => $allowed ? $documentId : null, 'version_id' => $allowed ? $versionId : null,
                    'action' => $action, 'actor_id' => $actor, 'outcome' => $allowed ? 'allowed' : 'denied']
            );
            if ($allowed && !$recorded) {
                throw new RuntimeException('Der Personalaktenzugriff konnte nicht sicher protokolliert werden.');
            }
        } catch (PDOException | PersonnelAccessDenied) {
            if ($allowed) {
                throw new RuntimeException('Der Personalaktenzugriff konnte nicht sicher protokolliert werden.');
            }
        }
    }

    /** @param callable(): array{0:array,1:?int,2:?int} $read */
    public function auditedRead(string $action, callable $read): array
    {
        $pdo = $this->connection->pdo();
        if ($pdo === null || $pdo->inTransaction()) {
            throw new RuntimeException('Ein protokollierter Personalaktenzugriff ist momentan nicht möglich.');
        }

        try {
            $pdo->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
            return $this->connection->transaction(function () use ($action, $read): array {
                [$result, $documentId, $versionId] = $read();
                $this->auditAccess($action, true, $documentId, $versionId);

                return $result;
            });
        } catch (PersonnelAccessDenied $exception) {
            $this->auditAccess($action, false);
            throw $exception;
        } catch (PDOException) {
            throw new RuntimeException('Ein protokollierter Personalaktenzugriff ist momentan nicht möglich.');
        }
    }

    private function latestScope(int $employeeId, int $granteeId, bool $forUpdate = false): ?array
    {
        $sql = 'SELECT id, is_granted FROM employee_personnel_scope_events WHERE employee_user_id = :employee_id AND grantee_user_id = :grantee_id ORDER BY id DESC LIMIT 1';

        return $this->connection->fetchOne($sql . ($forUpdate ? ' FOR UPDATE' : ''), ['employee_id' => $employeeId, 'grantee_id' => $granteeId]);
    }
}
