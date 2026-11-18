<?php

declare(strict_types=1);

namespace App\Domain\Personnel\Compliance;

use App\Infrastructure\Database\DatabaseConnection;
use InvalidArgumentException;
use JsonException;
use PDOException;
use RuntimeException;

final class PersonnelCommandStore
{
    public function __construct(private DatabaseConnection $connection)
    {
    }

    /**
     * Both callbacks run on the command connection. Write returns only the stable receipt fields.
     *
     * @param array<string, mixed> $payload
     * @param callable(): mixed $authorize
     * @param callable(): array $write
     */
    public function execute(int $actorId, string $requestId, string $operation, array $payload, callable $authorize, callable $write): array
    {
        $this->validateIdentity($actorId, $requestId, $operation);
        $pdo = $this->connection->pdo();
        if ($pdo === null || $pdo->inTransaction()) {
            throw new RuntimeException('Der Personalaktenauftrag benötigt eine eigene Datenbanktransaktion.');
        }

        $requestHash = hash('sha256', $requestId);
        $payloadHash = hash('sha256', $this->encode($this->canonicalize($payload)));
        try {
            $database = (string) $this->connection->fetchColumn('SELECT DATABASE()');
            $lock = 'personnel:' . substr(hash('sha256', $database . ':' . $actorId . ':' . $requestHash), 0, 52);
            if ((int) $this->connection->fetchColumn('SELECT GET_LOCK(:lock_name, 5)', ['lock_name' => $lock]) !== 1) {
                throw new PersonnelConflict('Der Personalaktenauftrag wird bereits verarbeitet. Bitte mit derselben Anfragekennung wiederholen.');
            }

            try {
                return $this->connection->transaction(function () use ($actorId, $requestHash, $operation, $payloadHash, $authorize, $write): array {
                    $authorize();
                    $key = ['actor_user_id' => $actorId, 'request_hash' => $requestHash];
                    $receipt = $this->findReceipt($key);
                    if ($receipt !== null) {
                        return $this->readReceipt($receipt, $operation, $payloadHash);
                    }

                    $result = $write();
                    $this->connection->execute(
                        'INSERT INTO personnel_command_receipts (actor_user_id, request_hash, operation, payload_hash, result_json, created_at)
                         VALUES (:actor_user_id, :request_hash, :operation, :payload_hash, :result_json, UTC_TIMESTAMP())',
                        $key + ['operation' => $operation, 'payload_hash' => $payloadHash, 'result_json' => $this->encode($result)]
                    );

                    return $result;
                });
            } finally {
                $this->connection->fetchColumn('SELECT RELEASE_LOCK(:lock_name)', ['lock_name' => $lock]);
            }
        } catch (PDOException $exception) {
            if (in_array($exception->errorInfo[1] ?? null, [1205, 1213], true)) {
                throw new PersonnelConflict();
            }

            throw new RuntimeException('Der Personalaktenauftrag konnte nicht sicher gespeichert werden.');
        } catch (JsonException) {
            throw new RuntimeException('Der Personalaktenauftrag konnte nicht sicher verarbeitet werden.');
        }
    }

    /** Check an existing receipt before expensive file processing. execute() still arbitrates concurrent first attempts. */
    public function replayIfCommitted(int $actorId, string $requestId, string $operation, array $payload, callable $authorize): ?array
    {
        $this->validateIdentity($actorId, $requestId, $operation);
        $pdo = $this->connection->pdo();
        if ($pdo === null || $pdo->inTransaction()) {
            throw new RuntimeException('Der Personalaktenauftrag benötigt eine eigene Datenbanktransaktion.');
        }

        return $this->connection->transaction(function () use ($actorId, $requestId, $operation, $payload, $authorize): ?array {
            $receipt = $this->findReceipt(['actor_user_id' => $actorId, 'request_hash' => hash('sha256', $requestId)]);
            if ($receipt === null) {
                return null;
            }

            $authorize();

            return $this->readReceipt($receipt, $operation, hash('sha256', $this->encode($this->canonicalize($payload))));
        });
    }

    private function validateIdentity(int $actorId, string $requestId, string $operation): void
    {
        if ($actorId < 1 || preg_match('/^[A-Za-z0-9._:-]{1,100}$/D', $requestId) !== 1
            || preg_match('/^[a-z][a-z0-9._]{0,63}$/D', $operation) !== 1) {
            throw new InvalidArgumentException('Ungültige Kennung für den Personalaktenauftrag.');
        }
    }

    private function findReceipt(array $key): ?array
    {
        return $this->connection->fetchOne(
            'SELECT operation, payload_hash, result_json FROM personnel_command_receipts
             WHERE actor_user_id = :actor_user_id AND request_hash = :request_hash',
            $key
        );
    }

    private function readReceipt(array $receipt, string $operation, string $payloadHash): array
    {
        if ($receipt['operation'] !== $operation || !hash_equals((string) $receipt['payload_hash'], $payloadHash)) {
            throw new PersonnelConflict('Die Anfragekennung wurde bereits für einen anderen Personalaktenauftrag verwendet.');
        }

        try {
            $result = json_decode((string) $receipt['result_json'], true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            $result = null;
        }
        if (!is_array($result)) {
            throw new RuntimeException('Der Personalaktenauftrag konnte nicht sicher gelesen werden.');
        }

        return $result;
    }

    private function canonicalize(array $fields): array
    {
        if (!array_is_list($fields)) {
            ksort($fields, SORT_STRING);
        }
        foreach ($fields as &$value) {
            if (is_array($value)) {
                $value = $this->canonicalize($value);
            }
        }
        unset($value);

        return $fields;
    }

    private function encode(array $data): string
    {
        try {
            return json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
        } catch (JsonException) {
            throw new InvalidArgumentException('Ungültige Daten für den Personalaktenauftrag.');
        }
    }
}
