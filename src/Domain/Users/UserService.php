<?php

declare(strict_types=1);

namespace App\Domain\Users;

use App\Domain\App\AppUiSettings;
use App\Infrastructure\Database\DatabaseConnection;
use InvalidArgumentException;

final class UserService
{
    public function __construct(private DatabaseConnection $connection)
    {
    }

    public function list(string $scope = 'active'): array
    {
        if ($this->connection->tableExists('users')) {
            $timeTrackingSelect = $this->timeTrackingSelect('users');
            $appUiSettingsSelect = $this->appUiSettingsSelect('users');
            $timeAccountSelect = $this->timeAccountSelect('users');

            $users = $this->connection->fetchAll(
                'SELECT
                    users.id,
                    users.employee_number,
                    users.first_name,
                    users.last_name,
                    users.email,
                    users.phone,
                    users.employment_status,
                    users.emergency_contact_name,
                    users.emergency_contact_phone,
                    users.target_hours_month,
                    ' . $timeAccountSelect . ',
                    ' . $timeTrackingSelect . ' AS time_tracking_required,
                    ' . $appUiSettingsSelect . ' AS app_ui_settings,
                    users.is_deleted,
                    GROUP_CONCAT(DISTINCT roles.name ORDER BY roles.name SEPARATOR ", ") AS role_names
                 FROM users
                 LEFT JOIN user_roles ON user_roles.user_id = users.id
                 LEFT JOIN roles ON roles.id = user_roles.role_id
                 WHERE ' . $this->scopeWhereClause('users', $scope) . '
                 GROUP BY users.id
                 ORDER BY users.is_deleted ASC, users.last_name, users.first_name'
            );

            return array_map(fn (array $user): array => $this->normalizeUserSettings($user), $users);
        }

        return [
            [
                'id' => 1,
                'employee_number' => 'MA-001',
                'first_name' => 'Jana',
                'last_name' => 'Kluge',
                'email' => 'jana.kluge@example.invalid',
                'phone' => '+49 151 10000001',
                'employment_status' => 'active',
                'emergency_contact_name' => 'Mario Kluge',
                'emergency_contact_phone' => '+49 151 20000001',
                'time_tracking_required' => 1,
                'app_ui_settings' => AppUiSettings::defaults(),
                'role_names' => 'Bauleiter',
                'is_deleted' => 0,
            ],
        ];
    }

    public function find(int $id): ?array
    {
        if (!$this->connection->tableExists('users')) {
            foreach ($this->list('all') as $user) {
                if ((int) $user['id'] === $id) {
                    $user['role_ids'] = [];

                    return $user;
                }
            }

            return null;
        }

        $timeTrackingSelect = $this->timeTrackingSelect();
        $appUiSettingsSelect = $this->appUiSettingsSelect();
        $timeAccountSelect = $this->timeAccountSelect();
        $user = $this->connection->fetchOne(
            'SELECT id, employee_number, first_name, last_name, email, phone, employment_status, emergency_contact_name, emergency_contact_phone, target_hours_month, ' . $timeAccountSelect . ', ' . $timeTrackingSelect . ' AS time_tracking_required, ' . $appUiSettingsSelect . ' AS app_ui_settings, is_deleted, deleted_at
             FROM users
             WHERE id = :id
             LIMIT 1',
            ['id' => $id]
        );

        if ($user === null) {
            return null;
        }

        $roleIds = $this->connection->fetchAll(
            'SELECT role_id FROM user_roles WHERE user_id = :user_id ORDER BY role_id',
            ['user_id' => $id]
        );
        $user['role_ids'] = array_map(static fn (array $row): int => (int) $row['role_id'], $roleIds);

        return $this->normalizeUserSettings($user);
    }

    public function create(array $payload): array
    {
        $record = $this->normalize($payload);

        if ($record['password'] === null) {
            throw new InvalidArgumentException('Beim Anlegen eines Users ist ein Passwort erforderlich.');
        }

        if (!$this->connection->tableExists('users')) {
            $record['id'] = random_int(1000, 9999);
            $record['role_ids'] = $this->normalizeRoleIds($payload['role_ids'] ?? []);

            return $record;
        }

        $timeTrackingColumns = $this->timeTrackingColumnExists() ? ', time_tracking_required' : '';
        $timeTrackingValues = $this->timeTrackingColumnExists() ? ', :time_tracking_required' : '';
        $timeTrackingBindings = $this->timeTrackingColumnExists()
            ? ['time_tracking_required' => $record['time_tracking_required'] ? 1 : 0]
            : [];
        $appUiSettingsColumns = $this->appUiSettingsColumnExists() ? ', app_ui_settings' : '';
        $appUiSettingsValues = $this->appUiSettingsColumnExists() ? ', :app_ui_settings' : '';
        $appUiSettingsBindings = $this->appUiSettingsColumnExists()
            ? ['app_ui_settings' => AppUiSettings::encode($record['app_ui_settings'])]
            : [];
        [$timeAccountColumns, $timeAccountValues, $timeAccountBindings] = $this->timeAccountInsertParts($record);

        return $this->connection->transaction(function () use ($record, $payload, $timeTrackingColumns, $timeTrackingValues, $timeTrackingBindings, $appUiSettingsColumns, $appUiSettingsValues, $appUiSettingsBindings, $timeAccountColumns, $timeAccountValues, $timeAccountBindings) {
            $this->connection->execute(
                'INSERT INTO users (
                    employee_number, first_name, last_name, email, phone, password_hash, employment_status, emergency_contact_name, emergency_contact_phone, target_hours_month' . $timeAccountColumns . $timeTrackingColumns . $appUiSettingsColumns . ', is_deleted, deleted_at, deleted_by_user_id, created_at, updated_at
                ) VALUES (
                    :employee_number, :first_name, :last_name, :email, :phone, :password_hash, :employment_status, :emergency_contact_name, :emergency_contact_phone, :target_hours_month' . $timeAccountValues . $timeTrackingValues . $appUiSettingsValues . ', 0, NULL, NULL, NOW(), NOW()
                )',
                [
                    'employee_number' => $record['employee_number'],
                    'first_name' => $record['first_name'],
                    'last_name' => $record['last_name'],
                    'email' => $record['email'],
                    'phone' => $record['phone'],
                    'password_hash' => password_hash($record['password'], PASSWORD_DEFAULT),
                    'employment_status' => $record['employment_status'],
                    'emergency_contact_name' => $record['emergency_contact_name'],
                    'emergency_contact_phone' => $record['emergency_contact_phone'],
                    'target_hours_month' => $record['target_hours_month'],
                ] + $timeAccountBindings + $timeTrackingBindings + $appUiSettingsBindings
            );

            $userId = $this->connection->lastInsertId();
            $this->syncRoles($userId, $this->normalizeRoleIds($payload['role_ids'] ?? []));

            return $this->find($userId) ?? [];
        });
    }

    public function emailExists(string $email, ?int $excludeUserId = null): bool
    {
        $email = trim($email);

        if ($email === '' || !$this->connection->tableExists('users')) {
            return false;
        }

        $sql = 'SELECT COUNT(*) FROM users WHERE LOWER(email) = LOWER(:email)';
        $bindings = ['email' => $email];

        if ($excludeUserId !== null) {
            $sql .= ' AND id <> :exclude_user_id';
            $bindings['exclude_user_id'] = $excludeUserId;
        }

        return (int) $this->connection->fetchColumn($sql, $bindings) > 0;
    }

    public function employeeNumberExists(string $employeeNumber, ?int $excludeUserId = null): bool
    {
        $employeeNumber = trim($employeeNumber);

        if ($employeeNumber === '' || !$this->connection->tableExists('users')) {
            return false;
        }

        $sql = 'SELECT COUNT(*) FROM users WHERE employee_number = :employee_number';
        $bindings = ['employee_number' => $employeeNumber];

        if ($excludeUserId !== null) {
            $sql .= ' AND id <> :exclude_user_id';
            $bindings['exclude_user_id'] = $excludeUserId;
        }

        return (int) $this->connection->fetchColumn($sql, $bindings) > 0;
    }

    public function update(int $id, array $payload): ?array
    {
        $existing = $this->find($id);

        if (!array_key_exists('time_tracking_required', $payload)) {
            if ($existing !== null) {
                $payload['time_tracking_required'] = $existing['time_tracking_required'] ?? true;
            }
        }

        if (!array_key_exists('app_ui_settings', $payload)) {
            if ($existing !== null) {
                $payload['app_ui_settings'] = $existing['app_ui_settings'] ?? AppUiSettings::defaults();
            }
        }

        $record = $this->normalize($payload);
        $this->assertTimeModelChangeAllowed($id, $existing, $record);

        if (!$this->connection->tableExists('users')) {
            $record['id'] = $id;
            $record['role_ids'] = $this->normalizeRoleIds($payload['role_ids'] ?? []);

            return $record;
        }

        $timeTrackingAssignment = $this->timeTrackingColumnExists() ? ',
                    time_tracking_required = :time_tracking_required' : '';
        $appUiSettingsAssignment = $this->appUiSettingsColumnExists() ? ',
                    app_ui_settings = :app_ui_settings' : '';
        [$timeAccountAssignment, $timeAccountBindings] = $this->timeAccountUpdateParts($record);

        return $this->connection->transaction(function () use ($id, $record, $payload, $timeTrackingAssignment, $appUiSettingsAssignment, $timeAccountAssignment, $timeAccountBindings) {
            $bindings = [
                'id' => $id,
                'employee_number' => $record['employee_number'],
                'first_name' => $record['first_name'],
                'last_name' => $record['last_name'],
                'email' => $record['email'],
                'phone' => $record['phone'],
                'employment_status' => $record['employment_status'],
                'emergency_contact_name' => $record['emergency_contact_name'],
                'emergency_contact_phone' => $record['emergency_contact_phone'],
                'target_hours_month' => $record['target_hours_month'],
            ] + $timeAccountBindings;

            if ($timeTrackingAssignment !== '') {
                $bindings['time_tracking_required'] = $record['time_tracking_required'] ? 1 : 0;
            }

            if ($appUiSettingsAssignment !== '') {
                $bindings['app_ui_settings'] = AppUiSettings::encode($record['app_ui_settings']);
            }

            $passwordSql = '';

            if ($record['password'] !== null) {
                $passwordSql = ', password_hash = :password_hash';
                $bindings['password_hash'] = password_hash($record['password'], PASSWORD_DEFAULT);
            }

            $this->connection->execute(
                'UPDATE users SET
                    employee_number = :employee_number,
                    first_name = :first_name,
                    last_name = :last_name,
                    email = :email,
                    phone = :phone,
                    employment_status = :employment_status,
                    emergency_contact_name = :emergency_contact_name,
                    emergency_contact_phone = :emergency_contact_phone,
                    target_hours_month = :target_hours_month' . $timeAccountAssignment . $timeTrackingAssignment . $appUiSettingsAssignment . $passwordSql . ',
                    updated_at = NOW()
                 WHERE id = :id',
                $bindings
            );

            $this->syncRoles($id, $this->normalizeRoleIds($payload['role_ids'] ?? []));

            return $this->find($id);
        });
    }

    public function archive(int $id, ?int $deletedByUserId = null): bool
    {
        if (!$this->connection->tableExists('users')) {
            return true;
        }

        return $this->connection->execute(
            'UPDATE users SET is_deleted = 1, deleted_at = NOW(), deleted_by_user_id = :deleted_by_user_id, updated_at = NOW() WHERE id = :id',
            ['id' => $id, 'deleted_by_user_id' => $deletedByUserId]
        );
    }

    private function syncRoles(int $userId, array $roleIds): void
    {
        if (!$this->connection->tableExists('user_roles')) {
            return;
        }

        $this->connection->execute('DELETE FROM user_roles WHERE user_id = :user_id', ['user_id' => $userId]);

        foreach ($roleIds as $roleId) {
            $this->connection->execute(
                'INSERT INTO user_roles (user_id, role_id) VALUES (:user_id, :role_id)',
                ['user_id' => $userId, 'role_id' => $roleId]
            );
        }
    }

    private function normalize(array $payload): array
    {
        $password = trim((string) ($payload['password'] ?? ''));

        return [
            'employee_number' => $this->nullableString($payload['employee_number'] ?? null),
            'first_name' => trim((string) ($payload['first_name'] ?? '')),
            'last_name' => trim((string) ($payload['last_name'] ?? '')),
            'email' => trim((string) ($payload['email'] ?? '')),
            'phone' => $this->nullableString($payload['phone'] ?? null),
            'password' => $password === '' ? null : $password,
            'employment_status' => trim((string) ($payload['employment_status'] ?? 'active')),
            'emergency_contact_name' => $this->nullableString($payload['emergency_contact_name'] ?? null),
            'emergency_contact_phone' => $this->nullableString($payload['emergency_contact_phone'] ?? null),
            'target_hours_month' => (float) ($payload['target_hours_month'] ?? 0),
            'target_hours_mode' => $this->normalizeTargetHoursMode($payload['target_hours_mode'] ?? 'month'),
            'target_hours_week' => $this->nullableNonNegativeFloat($payload['target_hours_week'] ?? null),
            'workdays_mask' => $this->normalizeWorkdaysMask($payload['workdays_mask'] ?? null),
            'vacation_days_year' => $this->nonNegativeFloat($payload['vacation_days_year'] ?? 0),
            'vacation_carryover_days' => $this->nonNegativeFloat($payload['vacation_carryover_days'] ?? 0),
            'time_tracking_required' => $this->normalizeBoolean($payload['time_tracking_required'] ?? true),
            'app_ui_settings' => AppUiSettings::normalize($payload['app_ui_settings'] ?? null),
        ];
    }

    private function normalizeBoolean(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if (is_int($value)) {
            return $value === 1;
        }

        $value = strtolower(trim((string) $value));

        return in_array($value, ['1', 'true', 'yes', 'on'], true);
    }

    private function timeTrackingSelect(string $table = ''): string
    {
        if (!$this->timeTrackingColumnExists()) {
            return '1';
        }

        $column = $table === '' ? 'time_tracking_required' : $table . '.time_tracking_required';

        return 'COALESCE(' . $column . ', 1)';
    }

    private function timeTrackingColumnExists(): bool
    {
        return $this->connection->columnExists('users', 'time_tracking_required');
    }

    private function appUiSettingsSelect(string $table = ''): string
    {
        if (!$this->appUiSettingsColumnExists()) {
            return 'NULL';
        }

        $column = $table === '' ? 'app_ui_settings' : $table . '.app_ui_settings';

        return $column;
    }

    private function appUiSettingsColumnExists(): bool
    {
        return $this->connection->columnExists('users', 'app_ui_settings');
    }

    private function normalizeUserSettings(array $user): array
    {
        $user['app_ui_settings'] = AppUiSettings::normalize($user['app_ui_settings'] ?? null);
        $user['target_hours_month'] = (float) ($user['target_hours_month'] ?? 0);
        $user['target_hours_mode'] = $this->normalizeTargetHoursMode($user['target_hours_mode'] ?? 'month');
        $targetHoursWeek = $user['target_hours_week'] ?? null;
        $user['target_hours_week'] = $targetHoursWeek === null || $targetHoursWeek === ''
            ? null
            : (float) $targetHoursWeek;
        $user['workdays_mask'] = $this->normalizeWorkdaysMask($user['workdays_mask'] ?? null);
        $user['vacation_days_year'] = $this->nonNegativeFloat($user['vacation_days_year'] ?? 0);
        $user['vacation_carryover_days'] = $this->nonNegativeFloat($user['vacation_carryover_days'] ?? 0);

        return $user;
    }

    private function timeAccountSelect(string $table = ''): string
    {
        $prefix = $table === '' ? '' : $table . '.';

        return implode(', ', [
            $this->connection->columnExists('users', 'target_hours_mode')
                ? 'COALESCE(' . $prefix . 'target_hours_mode, "month") AS target_hours_mode'
                : '"month" AS target_hours_mode',
            $this->connection->columnExists('users', 'target_hours_week')
                ? $prefix . 'target_hours_week AS target_hours_week'
                : 'NULL AS target_hours_week',
            $this->connection->columnExists('users', 'workdays_mask')
                ? 'COALESCE(' . $prefix . 'workdays_mask, "1,2,3,4,5") AS workdays_mask'
                : '"1,2,3,4,5" AS workdays_mask',
            $this->connection->columnExists('users', 'vacation_days_year')
                ? 'COALESCE(' . $prefix . 'vacation_days_year, 0) AS vacation_days_year'
                : '0 AS vacation_days_year',
            $this->connection->columnExists('users', 'vacation_carryover_days')
                ? 'COALESCE(' . $prefix . 'vacation_carryover_days, 0) AS vacation_carryover_days'
                : '0 AS vacation_carryover_days',
        ]);
    }

    private function timeAccountInsertParts(array $record): array
    {
        $columns = [];
        $values = [];
        $bindings = [];

        foreach ($this->timeAccountColumns() as $column) {
            $columns[] = $column;
            $values[] = ':' . $column;
            $bindings[$column] = $record[$column] ?? null;
        }

        return [
            $columns === [] ? '' : ', ' . implode(', ', $columns),
            $values === [] ? '' : ', ' . implode(', ', $values),
            $bindings,
        ];
    }

    private function timeAccountUpdateParts(array $record): array
    {
        $assignments = [];
        $bindings = [];

        foreach ($this->timeAccountColumns() as $column) {
            $assignments[] = $column . ' = :' . $column;
            $bindings[$column] = $record[$column] ?? null;
        }

        return [
            $assignments === [] ? '' : ', ' . implode(', ', $assignments),
            $bindings,
        ];
    }

    private function timeAccountColumns(): array
    {
        return array_values(array_filter(
            ['target_hours_mode', 'target_hours_week', 'workdays_mask', 'vacation_days_year', 'vacation_carryover_days'],
            fn (string $column): bool => $this->connection->columnExists('users', $column)
        ));
    }

    private function normalizeTargetHoursMode(mixed $value): string
    {
        $value = strtolower(trim((string) $value));

        return $value === 'week' ? 'week' : 'month';
    }

    private function normalizeWorkdaysMask(mixed $value): string
    {
        $values = is_array($value) ? $value : preg_split('/[,\s;|]+/', trim((string) ($value ?? '')));
        $days = [];

        foreach ($values ?: [] as $day) {
            $day = (int) $day;

            if ($day >= 1 && $day <= 7) {
                $days[] = $day;
            }
        }

        $days = array_values(array_unique($days));
        sort($days);

        return $days === [] ? '1,2,3,4,5' : implode(',', $days);
    }

    private function nullableNonNegativeFloat(mixed $value): ?float
    {
        $value = trim((string) ($value ?? ''));

        if ($value === '') {
            return null;
        }

        return max(0.0, (float) $value);
    }

    private function nonNegativeFloat(mixed $value): float
    {
        return max(0.0, (float) ($value ?? 0));
    }

    private function nullableString(mixed $value): ?string
    {
        $value = trim((string) ($value ?? ''));

        return $value === '' ? null : $value;
    }

    private function normalizeRoleIds(mixed $roleIds): array
    {
        $roleIds = is_array($roleIds) ? $roleIds : [$roleIds];
        $normalized = array_values(array_unique(array_filter(array_map(static fn (mixed $value): int => (int) $value, $roleIds))));
        sort($normalized);

        return $normalized;
    }

    private function assertTimeModelChangeAllowed(int $userId, ?array $existing, array $record): void
    {
        if ($existing === null || !$this->connection->tableExists('employee_account_cutovers')) {
            return;
        }

        $changed = $this->normalizeTargetHoursMode($existing['target_hours_mode'] ?? 'month') !== $this->normalizeTargetHoursMode($record['target_hours_mode'] ?? 'month')
            || $this->nullableNumericChanged($existing['target_hours_week'] ?? null, $record['target_hours_week'] ?? null)
            || abs((float) ($existing['target_hours_month'] ?? 0) - (float) ($record['target_hours_month'] ?? 0)) > 0.0001
            || $this->normalizeWorkdaysMask($existing['workdays_mask'] ?? null) !== $this->normalizeWorkdaysMask($record['workdays_mask'] ?? null);

        if ($changed && $this->hasActiveTimeAccountMovements($userId)) {
            throw new InvalidArgumentException('Das Arbeitszeitmodell ist Bestandteil eines aktiven Zeitkontos. Rueckwirkende Aenderungen benoetigen ein zeitlich gueltiges Arbeitszeitmodell oder eine dokumentierte Kontokorrektur.');
        }
    }

    private function nullableNumericChanged(mixed $before, mixed $after): bool
    {
        $before = $before === null || trim((string) $before) === '' ? null : (float) $before;
        $after = $after === null || trim((string) $after) === '' ? null : (float) $after;

        if ($before === null || $after === null) {
            return $before !== $after;
        }

        return abs($before - $after) > 0.0001;
    }

    private function hasActiveTimeAccountMovements(int $userId): bool
    {
        $cutover = $this->connection->fetchOne(
            'SELECT id, effective_from
             FROM employee_account_cutovers
             WHERE user_id = :user_id
               AND status = "final"
               AND active_final_user_id = :user_id_active
             ORDER BY effective_from DESC, id DESC
             LIMIT 1',
            ['user_id' => $userId, 'user_id_active' => $userId]
        );

        if ($cutover === null) {
            return false;
        }

        $timesheets = $this->connection->tableExists('timesheets')
            ? (int) ($this->connection->fetchColumn(
                'SELECT COUNT(*) FROM timesheets WHERE user_id = :user_id AND work_date >= :effective_from AND COALESCE(is_deleted, 0) = 0',
                ['user_id' => $userId, 'effective_from' => (string) $cutover['effective_from']]
            ) ?? 0)
            : 0;

        if ($timesheets > 0) {
            return true;
        }

        foreach (['time_account_entries', 'vacation_account_entries'] as $table) {
            if (!$this->connection->tableExists($table)) {
                continue;
            }

            $clauses = ['user_id = :user_id'];
            $bindings = ['user_id' => $userId];

            if ($this->connection->columnExists($table, 'cutover_id')) {
                $clauses[] = 'cutover_id = :cutover_id';
                $bindings['cutover_id'] = (int) $cutover['id'];
            } else {
                $clauses[] = 'effective_date >= :effective_from';
                $bindings['effective_from'] = (string) $cutover['effective_from'];
            }

            if ((int) ($this->connection->fetchColumn('SELECT COUNT(*) FROM ' . $table . ' WHERE ' . implode(' AND ', $clauses), $bindings) ?? 0) > 0) {
                return true;
            }
        }

        return false;
    }

    private function scopeWhereClause(string $table, string $scope): string
    {
        return match ($scope) {
            'archived' => $table . '.is_deleted = 1',
            'all' => '1 = 1',
            default => $table . '.is_deleted = 0',
        };
    }
}
