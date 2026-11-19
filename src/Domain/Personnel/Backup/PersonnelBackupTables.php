<?php

declare(strict_types=1);

namespace App\Domain\Personnel\Backup;

use App\Infrastructure\Database\DatabaseConnection;
use Generator;
use PDO;
use PDOException;
use PDOStatement;

final class PersonnelBackupTables
{
    public const TABLES = [
        'employee_employment_contexts' => ['user_id', 'employment_key', 'revision'],
        'employee_documents' => ['id'],
        'employee_document_versions' => ['document_id', 'version_number'],
        'employee_document_evidence_reviews' => ['id'],
        'employee_document_audit' => ['id'],
        'employee_document_access_log' => ['id'],
        'employee_personnel_scope_events' => ['id'],
        'personnel_command_receipts' => ['actor_user_id', 'request_hash'],
    ];

    private const CATALOG = [
        'personnel_compliance_sources',
        'personnel_document_types',
        'personnel_document_requirements',
    ];

    private const USER_REFERENCES = [
        'employee_employment_contexts' => ['user_id', 'created_by_user_id'],
        'employee_documents' => ['user_id', 'created_by_user_id', 'updated_by_user_id', 'archived_by_user_id'],
        'employee_document_versions' => ['created_by_user_id'],
        'employee_document_evidence_reviews' => ['actor_user_id'],
        'employee_document_audit' => ['actor_user_id'],
        'employee_document_access_log' => ['actor_user_id', 'employee_user_id'],
        'employee_personnel_scope_events' => ['employee_user_id', 'grantee_user_id', 'changed_by_user_id'],
        'personnel_command_receipts' => ['actor_user_id'],
    ];

    public function __construct(private readonly DatabaseConnection $connection)
    {
    }

    public function pdo(): PDO
    {
        $pdo = $this->connection->pdo();
        if (!$pdo instanceof PDO || $pdo->inTransaction()) {
            throw new PersonnelBackupFailure('database_unavailable');
        }

        return $pdo;
    }

    /** @return array{version:string, hash:string, columns:array<string,list<string>>} */
    public function schema(): array
    {
        $migrations = $this->connection->fetchAll('SELECT version, migration_name FROM phinxlog ORDER BY version');
        $latest = end($migrations);
        $version = $latest === false ? '' : (string) $latest['version'];
        $checks = $this->connection->fetchOne('SELECT @@foreign_key_checks AS foreign_keys, @@check_constraint_checks AS constraints');
        if ((int) $version < 20261118208000 || (int) ($checks['foreign_keys'] ?? 0) !== 1 || (int) ($checks['constraints'] ?? 0) !== 1) {
            throw new PersonnelBackupFailure('incompatible_schema');
        }

        $definitions = [];
        $columns = [];
        foreach (array_merge(array_keys(self::TABLES), self::CATALOG) as $table) {
            $definition = $this->connection->fetchOne('SHOW CREATE TABLE ' . self::identifier($table));
            $sql = $definition['Create Table'] ?? '';
            if (!is_string($sql) || !str_contains($sql, ' ENGINE=InnoDB ')) {
                throw new PersonnelBackupFailure('incompatible_schema');
            }
            $definitions[$table] = preg_replace('/ AUTO_INCREMENT=[0-9]+(?= |$)/', '', $sql);
            $columns[$table] = array_column($this->connection->fetchAll('SHOW COLUMNS FROM ' . self::identifier($table)), 'Field');
        }

        return [
            'version' => $version,
            'hash' => hash('sha256', PersonnelBackupFrames::json([$migrations, $definitions, $this->protectedTriggers()])),
            'columns' => $columns,
        ];
    }

    private function protectedTriggers(): array
    {
        $required = [
            'protect_employee_document_identity' => ['employee_documents', 'UPDATE', 'BEFORE'],
            'protect_employee_document_source' => ['employee_documents', 'UPDATE', 'BEFORE'],
            'require_personnel_private_object' => ['employee_document_versions', 'INSERT', 'BEFORE'],
            'require_personnel_verified_review_version' => ['employee_document_evidence_reviews', 'INSERT', 'BEFORE'],
        ];
        foreach (array_merge(array_keys(self::TABLES), self::CATALOG) as $table) {
            $required['deny_delete_' . $table] = [$table, 'DELETE', 'BEFORE'];
            if ($table === 'employee_documents') {
                continue;
            }
            $prefix = in_array($table, ['employee_personnel_scope_events', 'personnel_command_receipts'], true)
                ? 'deny_update_' : 'immutable_';
            $required[$prefix . $table] = [$table, 'UPDATE', 'BEFORE'];
        }

        try {
            $rows = $this->connection->fetchAll(
                'SELECT TRIGGER_NAME, EVENT_OBJECT_TABLE, EVENT_MANIPULATION, ACTION_TIMING, ACTION_ORDER, ACTION_STATEMENT, SQL_MODE
                   FROM personnel_schema_trigger_manifest
                  ORDER BY EVENT_OBJECT_TABLE, EVENT_MANIPULATION, ACTION_TIMING, ACTION_ORDER, TRIGGER_NAME'
            );
        } catch (PDOException) {
            throw new PersonnelBackupFailure('protective_triggers_missing_or_invalid');
        }
        $definitions = [];
        foreach ($rows as $row) {
            $name = (string) $row['TRIGGER_NAME'];
            $placement = [
                (string) $row['EVENT_OBJECT_TABLE'],
                strtoupper((string) $row['EVENT_MANIPULATION']),
                strtoupper((string) $row['ACTION_TIMING']),
            ];
            $body = trim(str_replace(["\r\n", "\r"], "\n", (string) $row['ACTION_STATEMENT']));
            $order = (int) $row['ACTION_ORDER'];
            $sqlModes = explode(',', (string) $row['SQL_MODE']);
            sort($sqlModes, SORT_STRING);
            if ($name === '' || $body === '' || $order < 1 || (isset($required[$name]) && $required[$name] !== $placement)) {
                throw new PersonnelBackupFailure('protective_triggers_missing_or_invalid');
            }
            unset($required[$name]);
            $definitions[] = [$name, ...$placement, $order, $sqlModes, $body];
        }
        if ($required !== []) {
            throw new PersonnelBackupFailure('protective_triggers_missing_or_invalid');
        }

        return $definitions;
    }

    public function catalogHash(): string
    {
        $hash = hash_init('sha256');
        foreach (self::CATALOG as $table) {
            hash_update($hash, PersonnelBackupFrames::encode([$table]));
            foreach ($this->orderedRows($table, ['id']) as $row) {
                hash_update($hash, PersonnelBackupFrames::encode(array_values($row)));
            }
        }

        return hash_final($hash);
    }

    /** @return array{0:int,1:string} */
    public function usersBasis(): array
    {
        $union = [];
        foreach (self::USER_REFERENCES as $table => $columns) {
            foreach ($columns as $column) {
                $column = self::identifier($column);
                $union[] = 'SELECT ' . $column . ' AS person FROM ' . self::identifier($table) . ' WHERE ' . $column . ' IS NOT NULL';
            }
        }
        // The archive requires the same actor and employee identities as the accompanying baseline.
        $sql = 'SELECT expected.person, u.id, u.employee_number, u.email, u.created_at FROM (' . implode(' UNION ', $union)
            . ') expected LEFT JOIN users u ON u.id = expected.person ORDER BY expected.person';
        $pdo = $this->connection->pdo();
        $hash = hash_init('sha256');
        $count = 0;
        $pdo->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, false);
        try {
            $statement = $pdo->query($sql);
            try {
                while (($row = $statement->fetch()) !== false) {
                    if ($row['id'] === null) {
                        throw new PersonnelBackupFailure('missing_employee_reference');
                    }
                    hash_update($hash, PersonnelBackupFrames::encode(array_values($row)));
                    ++$count;
                }
            } finally {
                $statement->closeCursor();
            }
        } finally {
            $pdo->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, true);
        }

        return [$count, hash_final($hash)];
    }

    public function assertScratchTarget(): void
    {
        $identity = $this->connection->fetchOne('SELECT DATABASE() AS database_name, CURRENT_USER() AS database_user, CURRENT_ROLE() AS role_name');
        $name = $identity['database_name'] ?? '';
        if (!preg_match('/\Atimeapp_personnel_restore_([0-9a-f]{12})\z/D', $name, $matches)
            || !str_starts_with((string) ($identity['database_user'] ?? ''), 'timeapp_pr_' . $matches[1] . '@')
            || ($identity['role_name'] ?? null) !== null) {
            throw new PersonnelBackupFailure('scratch_target_denied');
        }

        $target = self::identifier(str_replace('_', '\\_', $name)) . '.*';
        $hasScopedWrite = false;
        foreach ($this->connection->fetchAll('SHOW GRANTS') as $row) {
            $grant = (string) reset($row);
            // Reject grant options, proxy grants, roles, broad DDL/AUTH, and access to other schemas.
            if (preg_match('/\AGRANT USAGE ON \*\.\* TO /D', $grant) && !str_contains($grant, 'WITH GRANT OPTION')) {
                continue;
            }
            if (str_starts_with($grant, 'GRANT SELECT, INSERT ON ' . $target . ' TO ') && !str_contains($grant, 'WITH GRANT OPTION')) {
                $hasScopedWrite = true;
                continue;
            }
            throw new PersonnelBackupFailure('scratch_target_denied');
        }
        if (!$hasScopedWrite) {
            throw new PersonnelBackupFailure('scratch_target_denied');
        }
    }

    public function isEmpty(): bool
    {
        foreach (array_keys(self::TABLES) as $table) {
            if ((int) $this->connection->fetchColumn('SELECT EXISTS(SELECT 1 FROM ' . self::identifier($table) . ')') === 1) {
                return false;
            }
        }

        return true;
    }

    public function rows(string $table): Generator
    {
        yield from $this->orderedRows($table, self::TABLES[$table]);
    }

    public function objectRows(): Generator
    {
        yield from $this->orderedRows('employee_document_versions', ['storage_key'], self::identifier('storage_key') . ' IS NOT NULL');
    }

    public function restoreInsert(string $table, array $columns): PDOStatement
    {
        return $this->connection->pdo()->prepare(
            'INSERT INTO ' . self::identifier($table) . ' (' . implode(', ', array_map(self::identifier(...), $columns)) . ') VALUES ('
            . implode(', ', array_fill(0, count($columns), '?')) . ')'
        );
    }

    private function orderedRows(string $table, array $order, string $filter = '1=1'): Generator
    {
        $cursor = [];
        $orderedColumns = implode(', ', array_map(self::identifier(...), $order));
        do {
            $conditions = $filter;
            if ($cursor !== []) {
                $conditions .= ' AND (' . $orderedColumns . ') > (' . implode(', ', array_fill(0, count($order), '?')) . ')';
            }
            $rows = $this->connection->fetchAll(
                'SELECT * FROM ' . self::identifier($table) . ' WHERE ' . $conditions . ' ORDER BY ' . $orderedColumns . ' LIMIT 128',
                $cursor
            );
            foreach ($rows as $row) {
                yield $row;
            }
            if ($rows !== []) {
                $cursor = array_map(static fn (string $column): mixed => $row[$column], $order);
            }
        } while (count($rows) === 128);
    }

    private static function identifier(string $value): string
    {
        return '`' . str_replace('`', '``', $value) . '`';
    }
}
