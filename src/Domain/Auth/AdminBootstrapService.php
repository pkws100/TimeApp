<?php

declare(strict_types=1);

namespace App\Domain\Auth;

use App\Infrastructure\Database\DatabaseConnection;
use RuntimeException;

final class AdminBootstrapService
{
    public function __construct(private DatabaseConnection $connection)
    {
    }

    public function bootstrapRequired(): bool
    {
        foreach (['users', 'roles', 'user_roles'] as $table) {
            if (!$this->connection->tableExists($table)) {
                return true;
            }
        }

        return (int) ($this->connection->fetchColumn(
            'SELECT COUNT(DISTINCT users.id)
             FROM users
             INNER JOIN user_roles ON user_roles.user_id = users.id
             INNER JOIN roles ON roles.id = user_roles.role_id
             WHERE roles.slug = :role_slug
               AND roles.is_deleted = 0
               AND COALESCE(users.is_deleted, 0) = 0
               AND users.employment_status = :status',
            [
                'role_slug' => 'administrator',
                'status' => 'active',
            ]
        ) ?? 0) === 0;
    }

    public function createInitialAdministrator(array $payload): array
    {
        $this->assertSchemaReady();
        $this->assertReferenceDataReady();

        if (!$this->bootstrapRequired()) {
            throw new RuntimeException('Es existiert bereits mindestens ein aktiver Administrator. Der Erstaufbau-Command wird nicht erneut ausgefuehrt.');
        }

        $email = trim((string) ($payload['email'] ?? ''));
        $password = (string) ($payload['password'] ?? '');
        $firstName = trim((string) ($payload['first_name'] ?? ''));
        $lastName = trim((string) ($payload['last_name'] ?? ''));
        $employeeNumber = $this->nullableTrimmed($payload['employee_number'] ?? null);
        $forcePasswordReset = (bool) ($payload['force_password_reset'] ?? false);

        if ($email === '' || $password === '' || $firstName === '' || $lastName === '') {
            throw new RuntimeException('E-Mail, Passwort, Vorname und Nachname sind erforderlich.');
        }

        $existingUser = $this->connection->fetchOne(
            'SELECT id, employment_status, COALESCE(is_deleted, 0) AS is_deleted
             FROM users
             WHERE email = :email
             LIMIT 1',
            ['email' => $email]
        );

        if ($existingUser !== null) {
            if ((int) ($existingUser['is_deleted'] ?? 0) === 1 || (string) ($existingUser['employment_status'] ?? '') !== 'active') {
                throw new RuntimeException('Der vorhandene Benutzer ist archiviert oder nicht aktiv. Bitte zuerst den Benutzerstatus bereinigen.');
            }

            $userId = (int) $existingUser['id'];

            $this->connection->execute(
                'UPDATE users SET
                    first_name = :first_name,
                    last_name = :last_name,
                    employee_number = :employee_number,
                    updated_at = NOW()
                 WHERE id = :id',
                [
                    'id' => $userId,
                    'first_name' => $firstName,
                    'last_name' => $lastName,
                    'employee_number' => $employeeNumber,
                ]
            );

            if ($forcePasswordReset) {
                $this->connection->execute(
                    'UPDATE users SET password_hash = :password_hash, updated_at = NOW() WHERE id = :id',
                    [
                        'id' => $userId,
                        'password_hash' => password_hash($password, PASSWORD_DEFAULT),
                    ]
                );
            }

            $created = false;
        } else {
            $this->connection->execute(
                'INSERT INTO users (
                    employee_number, first_name, last_name, email, phone, password_hash, employment_status, emergency_contact_name, emergency_contact_phone, target_hours_month, created_at, updated_at, is_deleted, deleted_at, deleted_by_user_id
                 ) VALUES (
                    :employee_number, :first_name, :last_name, :email, NULL, :password_hash, "active", NULL, NULL, 0, NOW(), NOW(), 0, NULL, NULL
                 )',
                [
                    'employee_number' => $employeeNumber,
                    'first_name' => $firstName,
                    'last_name' => $lastName,
                    'email' => $email,
                    'password_hash' => password_hash($password, PASSWORD_DEFAULT),
                ]
            );

            $userId = $this->connection->lastInsertId();
            $created = true;
        }

        $this->connection->execute(
            'INSERT IGNORE INTO user_roles (user_id, role_id)
             SELECT :user_id, roles.id
             FROM roles
             WHERE roles.slug = :role_slug',
            [
                'user_id' => $userId,
                'role_slug' => 'administrator',
            ]
        );

        return [
            'created' => $created,
            'user_id' => $userId,
            'email' => $email,
            'message' => $created
                ? 'Der erste Administrator wurde erfolgreich angelegt.'
                : 'Der vorhandene Benutzer wurde erfolgreich zum Administrator gemacht.',
        ];
    }

    private function assertSchemaReady(): void
    {
        $missingTables = array_values(array_filter(
            ['users', 'roles', 'user_roles', 'permissions', 'role_permissions'],
            fn (string $table): bool => !$this->connection->tableExists($table)
        ));

        if ($missingTables !== []) {
            throw new RuntimeException(
                'Die Datenbank ist noch nicht bereit. Bitte zuerst `vendor/bin/phinx migrate -c phinx.php` ausfuehren. Fehlende Tabellen: '
                . implode(', ', $missingTables)
            );
        }
    }

    private function assertReferenceDataReady(): void
    {
        $role = $this->connection->fetchOne(
            'SELECT id FROM roles WHERE slug = :role_slug LIMIT 1',
            ['role_slug' => 'administrator']
        );

        if ($role === null) {
            throw new RuntimeException(
                'Die Referenzdaten fehlen. Bitte zuerst `vendor/bin/phinx seed:run -c phinx.php` ausfuehren.'
            );
        }
    }

    private function nullableTrimmed(mixed $value): ?string
    {
        $value = trim((string) ($value ?? ''));

        return $value === '' ? null : $value;
    }
}
