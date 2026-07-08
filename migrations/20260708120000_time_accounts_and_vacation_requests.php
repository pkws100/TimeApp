<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class TimeAccountsAndVacationRequests extends AbstractMigration
{
    /**
     * @var array<string, array{label: string, scope: string}>
     */
    private array $permissions = [
        'time_accounts.view' => ['label' => 'Zeitkonten ansehen', 'scope' => 'timesheets'],
        'time_accounts.manage' => ['label' => 'Zeitkonten verwalten', 'scope' => 'timesheets'],
        'vacation_requests.view' => ['label' => 'Urlaubsantraege ansehen', 'scope' => 'timesheets'],
        'vacation_requests.manage' => ['label' => 'Urlaubsantraege verwalten', 'scope' => 'timesheets'],
    ];

    public function up(): void
    {
        $this->addUserTimeAccountColumns();
        $this->createVacationRequestsTable();
        $this->addTimesheetVacationRequestReference();
        $this->seedPermissions();
    }

    public function down(): void
    {
        if ($this->hasTable('timesheets')) {
            $table = $this->table('timesheets');

            if ($table->hasForeignKey('vacation_request_id')) {
                $this->alterSystemVersionedTimesheets(static function () use ($table): void {
                    $table->dropForeignKey('vacation_request_id')->update();
                });
            }

            $table = $this->table('timesheets');

            if ($table->hasColumn('vacation_request_id')) {
                $this->alterSystemVersionedTimesheets(static function () use ($table): void {
                    $table->removeColumn('vacation_request_id')->update();
                });
            }
        }

        if ($this->hasTable('vacation_requests')) {
            $this->table('vacation_requests')->drop()->save();
        }

        if ($this->hasTable('users')) {
            $table = $this->table('users');

            foreach ([
                'vacation_carryover_days',
                'vacation_days_year',
                'workdays_mask',
                'target_hours_week',
                'target_hours_mode',
            ] as $column) {
                if ($table->hasColumn($column)) {
                    $table->removeColumn($column);
                }
            }

            $table->update();
        }
    }

    private function addUserTimeAccountColumns(): void
    {
        if (!$this->hasTable('users')) {
            return;
        }

        $table = $this->table('users');

        if (!$table->hasColumn('target_hours_mode')) {
            $table->addColumn('target_hours_mode', 'string', ['limit' => 20, 'default' => 'month', 'after' => 'target_hours_month']);
        }

        if (!$table->hasColumn('target_hours_week')) {
            $table->addColumn('target_hours_week', 'decimal', ['precision' => 7, 'scale' => 2, 'null' => true, 'after' => 'target_hours_mode']);
        }

        if (!$table->hasColumn('workdays_mask')) {
            $table->addColumn('workdays_mask', 'string', ['limit' => 32, 'null' => true, 'default' => '1,2,3,4,5', 'after' => 'target_hours_week']);
        }

        if (!$table->hasColumn('vacation_days_year')) {
            $table->addColumn('vacation_days_year', 'decimal', ['precision' => 6, 'scale' => 2, 'default' => 0, 'after' => 'workdays_mask']);
        }

        if (!$table->hasColumn('vacation_carryover_days')) {
            $table->addColumn('vacation_carryover_days', 'decimal', ['precision' => 6, 'scale' => 2, 'default' => 0, 'after' => 'vacation_days_year']);
        }

        $table->update();
    }

    private function createVacationRequestsTable(): void
    {
        if ($this->hasTable('vacation_requests')) {
            return;
        }

        $this->table('vacation_requests')
            ->addColumn('user_id', 'integer', ['signed' => false])
            ->addColumn('date_from', 'date')
            ->addColumn('date_to', 'date')
            ->addColumn('day_count', 'decimal', ['precision' => 6, 'scale' => 2, 'default' => 0])
            ->addColumn('status', 'enum', ['values' => ['pending', 'approved', 'rejected', 'cancelled'], 'default' => 'pending'])
            ->addColumn('employee_note', 'text', ['null' => true])
            ->addColumn('decision_note', 'text', ['null' => true])
            ->addColumn('requested_at', 'datetime', ['default' => 'CURRENT_TIMESTAMP'])
            ->addColumn('decided_at', 'datetime', ['null' => true])
            ->addColumn('decided_by_user_id', 'integer', ['signed' => false, 'null' => true])
            ->addColumn('created_at', 'datetime', ['default' => 'CURRENT_TIMESTAMP'])
            ->addColumn('updated_at', 'datetime', ['default' => 'CURRENT_TIMESTAMP'])
            ->addColumn('is_deleted', 'boolean', ['default' => false])
            ->addColumn('deleted_at', 'datetime', ['null' => true])
            ->addColumn('deleted_by_user_id', 'integer', ['signed' => false, 'null' => true])
            ->addForeignKey('user_id', 'users', 'id', ['delete' => 'CASCADE'])
            ->addForeignKey('decided_by_user_id', 'users', 'id', ['delete' => 'SET_NULL'])
            ->addForeignKey('deleted_by_user_id', 'users', 'id', ['delete' => 'SET_NULL'])
            ->addIndex(['user_id', 'date_from', 'date_to'])
            ->addIndex(['status'])
            ->addIndex(['is_deleted'])
            ->create();
    }

    private function addTimesheetVacationRequestReference(): void
    {
        if (!$this->hasTable('timesheets') || !$this->hasTable('vacation_requests')) {
            return;
        }

        $table = $this->table('timesheets');

        if (!$table->hasColumn('vacation_request_id')) {
            $options = [
                'signed' => false,
                'null' => true,
            ];

            if ($table->hasColumn('source')) {
                $options['after'] = 'source';
            }

            $this->alterSystemVersionedTimesheets(static function () use ($table, $options): void {
                $table->addColumn('vacation_request_id', 'integer', $options)->update();
            });
        }

        $table = $this->table('timesheets');

        if (!$table->hasForeignKey('vacation_request_id')) {
            $this->alterSystemVersionedTimesheets(static function () use ($table): void {
                $table->addForeignKey('vacation_request_id', 'vacation_requests', 'id', ['delete' => 'SET_NULL'])->update();
            });
        }

        $table = $this->table('timesheets');

        if (!$table->hasIndex(['vacation_request_id'])) {
            $this->alterSystemVersionedTimesheets(static function () use ($table): void {
                $table->addIndex(['vacation_request_id'])->update();
            });
        }
    }

    private function alterSystemVersionedTimesheets(callable $alter): void
    {
        $this->execute('SET SESSION system_versioning_alter_history = KEEP;');

        try {
            $alter();
        } finally {
            $this->execute('SET SESSION system_versioning_alter_history = ERROR;');
        }
    }

    private function seedPermissions(): void
    {
        foreach ($this->permissions as $code => $permission) {
            $this->execute(sprintf(
                "INSERT INTO permissions (code, label, scope, created_at)
                 VALUES ('%s', '%s', '%s', NOW())
                 ON DUPLICATE KEY UPDATE label = VALUES(label), scope = VALUES(scope)",
                addslashes($code),
                addslashes($permission['label']),
                addslashes($permission['scope'])
            ));
        }

        foreach (['administrator', 'geschaeftsfuehrung'] as $roleSlug) {
            foreach (array_keys($this->permissions) as $permissionCode) {
                $this->execute(sprintf(
                    "INSERT IGNORE INTO role_permissions (role_id, permission_id)
                     SELECT roles.id, permissions.id
                     FROM roles
                     INNER JOIN permissions ON permissions.code = '%s'
                     WHERE roles.slug = '%s'",
                    addslashes($permissionCode),
                    addslashes($roleSlug)
                ));
            }
        }
    }
}
