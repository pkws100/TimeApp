<?php

declare(strict_types=1);

namespace App\Infrastructure\Database;

use PDO;
use PDOException;
use Throwable;

final class DatabaseConnection
{
    private ?PDO $pdo = null;
    private ?string $lastError = null;

    public function __construct(private array $config)
    {
    }

    public function isAvailable(): bool
    {
        return $this->pdo() instanceof PDO;
    }

    public function pdo(): ?PDO
    {
        if ($this->pdo instanceof PDO) {
            return $this->pdo;
        }

        try {
            $dsn = $this->buildDsn($this->config);
            $this->pdo = new PDO($dsn, (string) ($this->config['username'] ?? ''), (string) ($this->config['password'] ?? ''), [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]);
            $this->lastError = null;
        } catch (PDOException $exception) {
            $this->lastError = $exception->getMessage();
            $this->pdo = null;
        }

        return $this->pdo;
    }

    public function test(array $config): array
    {
        try {
            $pdo = new PDO(
                $this->buildDsn($config),
                (string) ($config['username'] ?? ''),
                (string) ($config['password'] ?? ''),
                [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES => false,
                ]
            );
            $pdo->query('SELECT 1');

            return ['ok' => true, 'message' => 'Verbindung erfolgreich getestet.'];
        } catch (PDOException $exception) {
            return ['ok' => false, 'message' => $exception->getMessage()];
        }
    }

    public function lastError(): ?string
    {
        return $this->lastError;
    }

    public function tableExists(string $table): bool
    {
        $pdo = $this->pdo();

        if (!$pdo instanceof PDO) {
            return false;
        }

        $statement = $pdo->prepare(
            'SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = :schema AND table_name = :table'
        );
        $statement->execute([
            'schema' => $this->config['database'] ?? '',
            'table' => $table,
        ]);

        return (int) $statement->fetchColumn() > 0;
    }

    public function columnExists(string $table, string $column): bool
    {
        $pdo = $this->pdo();

        if (!$pdo instanceof PDO) {
            return false;
        }

        $statement = $pdo->prepare(
            'SELECT COUNT(*)
             FROM information_schema.columns
             WHERE table_schema = :schema
               AND table_name = :table
               AND column_name = :column'
        );
        $statement->execute([
            'schema' => $this->config['database'] ?? '',
            'table' => $table,
            'column' => $column,
        ]);

        return (int) $statement->fetchColumn() > 0;
    }

    public function fetchAll(string $sql, array $params = []): array
    {
        $pdo = $this->pdo();

        if (!$pdo instanceof PDO) {
            return [];
        }

        $statement = $pdo->prepare($sql);
        $statement->execute($params);

        return $statement->fetchAll() ?: [];
    }

    public function fetchOne(string $sql, array $params = []): ?array
    {
        $rows = $this->fetchAll($sql, $params);

        return $rows[0] ?? null;
    }

    public function execute(string $sql, array $params = []): bool
    {
        $pdo = $this->pdo();

        if (!$pdo instanceof PDO) {
            return false;
        }

        $statement = $pdo->prepare($sql);

        return $statement->execute($params);
    }

    public function fetchColumn(string $sql, array $params = []): mixed
    {
        $pdo = $this->pdo();

        if (!$pdo instanceof PDO) {
            return null;
        }

        $statement = $pdo->prepare($sql);
        $statement->execute($params);

        return $statement->fetchColumn();
    }

    public function lastInsertId(): int
    {
        $pdo = $this->pdo();

        if (!$pdo instanceof PDO) {
            return 0;
        }

        return (int) $pdo->lastInsertId();
    }

    public function transaction(callable $callback): mixed
    {
        $pdo = $this->pdo();

        if (!$pdo instanceof PDO) {
            return $callback();
        }

        $pdo->beginTransaction();

        try {
            $result = $callback($pdo);
            $pdo->commit();

            return $result;
        } catch (Throwable $throwable) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            throw $throwable;
        }
    }

    public function statusSummary(): array
    {
        $checkedAt = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        $pdo = $this->pdo();

        if (!$pdo instanceof PDO) {
            return [
                'connected' => false,
                'checked_at' => $checkedAt,
                'message' => $this->lastError() ?? 'Die Verbindung zur Datenbank konnte nicht hergestellt werden.',
                'host' => $this->config['host'] ?? '',
                'port' => (string) ($this->config['port'] ?? ''),
                'socket' => $this->config['socket'] ?? '',
                'database' => $this->config['database'] ?? '',
                'server_version' => null,
                'table_count' => 0,
            ];
        }

        $databaseName = (string) ($this->fetchColumn('SELECT DATABASE()') ?? ($this->config['database'] ?? ''));
        $tableCount = (int) ($this->fetchColumn(
            'SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = :schema',
            ['schema' => $databaseName]
        ) ?? 0);

        return [
            'connected' => true,
            'checked_at' => $checkedAt,
            'message' => 'Die Verbindung zur Datenbank steht.',
            'host' => $this->config['host'] ?? '',
            'port' => (string) ($this->config['port'] ?? ''),
            'socket' => $this->config['socket'] ?? '',
            'database' => $databaseName,
            'server_version' => (string) ($this->fetchColumn('SELECT VERSION()') ?? ''),
            'table_count' => $tableCount,
        ];
    }

    private function buildDsn(array $config): string
    {
        if (!empty($config['socket'])) {
            return sprintf(
                'mysql:unix_socket=%s;dbname=%s;charset=%s',
                $config['socket'],
                $config['database'] ?? '',
                $config['charset'] ?? 'utf8mb4'
            );
        }

        return sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=%s',
            $config['host'] ?? '127.0.0.1',
            (int) ($config['port'] ?? 3306),
            $config['database'] ?? '',
            $config['charset'] ?? 'utf8mb4'
        );
    }
}
