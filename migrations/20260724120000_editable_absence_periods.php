<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class EditableAbsencePeriods extends AbstractMigration
{
    public function up(): void
    {
        $this->createAbsencePeriods();
        $this->createAbsencePeriodChangeLog();
        $this->addTimesheetReference();
        $this->backfillApprovedVacationRequests();
    }

    public function down(): void
    {
        if ($this->hasTable('timesheets')) {
            $table = $this->table('timesheets');

            if ($table->hasForeignKey('absence_period_id')) {
                $this->alterSystemVersionedTimesheets(static function () use ($table): void {
                    $table->dropForeignKey('absence_period_id')->update();
                });
            }

            $table = $this->table('timesheets');

            if ($table->hasColumn('absence_period_id')) {
                $this->alterSystemVersionedTimesheets(static function () use ($table): void {
                    $table->removeColumn('absence_period_id')->update();
                });
            }
        }

        if ($this->hasTable('absence_period_change_log')) {
            $this->table('absence_period_change_log')->drop()->save();
        }

        if ($this->hasTable('absence_periods')) {
            $this->table('absence_periods')->drop()->save();
        }
    }

    private function createAbsencePeriods(): void
    {
        if ($this->hasTable('absence_periods')) {
            return;
        }

        $this->table('absence_periods')
            ->addColumn('user_id', 'integer', ['signed' => false])
            ->addColumn('date_from', 'date')
            ->addColumn('date_to', 'date')
            ->addColumn('entry_type', 'string', ['limit' => 20])
            ->addColumn('absence_reason_code', 'string', ['limit' => 80])
            ->addColumn('source', 'string', ['limit' => 40])
            ->addColumn('vacation_request_id', 'integer', ['signed' => false, 'null' => true])
            ->addColumn('booked_day_count', 'integer', ['signed' => false, 'default' => 0])
            ->addColumn('note', 'text', ['null' => true])
            ->addColumn('lock_version', 'integer', ['signed' => false, 'default' => 1])
            ->addColumn('created_by_user_id', 'integer', ['signed' => false, 'null' => true])
            ->addColumn('updated_by_user_id', 'integer', ['signed' => false, 'null' => true])
            ->addColumn('created_at', 'datetime', ['default' => 'CURRENT_TIMESTAMP'])
            ->addColumn('updated_at', 'datetime', ['default' => 'CURRENT_TIMESTAMP'])
            ->addColumn('is_deleted', 'boolean', ['default' => false])
            ->addColumn('deleted_at', 'datetime', ['null' => true])
            ->addColumn('deleted_by_user_id', 'integer', ['signed' => false, 'null' => true])
            ->addForeignKey('user_id', 'users', 'id', ['delete' => 'RESTRICT'])
            ->addForeignKey('vacation_request_id', 'vacation_requests', 'id', ['delete' => 'RESTRICT'])
            ->addForeignKey('created_by_user_id', 'users', 'id', ['delete' => 'SET_NULL'])
            ->addForeignKey('updated_by_user_id', 'users', 'id', ['delete' => 'SET_NULL'])
            ->addForeignKey('deleted_by_user_id', 'users', 'id', ['delete' => 'SET_NULL'])
            ->addIndex(['vacation_request_id'], ['unique' => true, 'name' => 'uniq_absence_period_vacation_request'])
            ->addIndex(['user_id', 'date_from', 'date_to'], ['name' => 'idx_absence_period_user_range'])
            ->addIndex(['entry_type', 'is_deleted'], ['name' => 'idx_absence_period_type_active'])
            ->create();
    }

    private function createAbsencePeriodChangeLog(): void
    {
        if ($this->hasTable('absence_period_change_log')) {
            return;
        }

        $this->table('absence_period_change_log')
            ->addColumn('absence_period_id', 'integer', ['signed' => false])
            ->addColumn('action_type', 'string', ['limit' => 60])
            ->addColumn('changed_by_user_id', 'integer', ['signed' => false, 'null' => true])
            ->addColumn('change_reason', 'text')
            ->addColumn('before_snapshot', 'text', ['null' => true])
            ->addColumn('after_snapshot', 'text', ['null' => true])
            ->addColumn('created_at', 'datetime', ['default' => 'CURRENT_TIMESTAMP'])
            ->addForeignKey('absence_period_id', 'absence_periods', 'id', ['delete' => 'RESTRICT'])
            ->addForeignKey('changed_by_user_id', 'users', 'id', ['delete' => 'SET_NULL'])
            ->addIndex(['absence_period_id', 'created_at'], ['name' => 'idx_absence_period_log'])
            ->create();
    }

    private function addTimesheetReference(): void
    {
        if (!$this->hasTable('timesheets') || !$this->hasTable('absence_periods')) {
            return;
        }

        $table = $this->table('timesheets');

        if (!$table->hasColumn('absence_period_id')) {
            $this->alterSystemVersionedTimesheets(static function () use ($table): void {
                $table->addColumn('absence_period_id', 'integer', [
                    'signed' => false,
                    'null' => true,
                    'after' => 'vacation_request_id',
                ])->update();
            });
        }

        $table = $this->table('timesheets');

        if (!$table->hasForeignKey('absence_period_id')) {
            $this->alterSystemVersionedTimesheets(static function () use ($table): void {
                $table->addForeignKey('absence_period_id', 'absence_periods', 'id', ['delete' => 'RESTRICT'])->update();
            });
        }

        $table = $this->table('timesheets');

        if (!$table->hasIndex(['absence_period_id'])) {
            $this->alterSystemVersionedTimesheets(static function () use ($table): void {
                $table->addIndex(['absence_period_id'], ['name' => 'idx_timesheets_absence_period'])->update();
            });
        }
    }

    private function backfillApprovedVacationRequests(): void
    {
        if (!$this->hasTable('vacation_requests')
            || !$this->hasTable('absence_periods')
            || !$this->hasTable('timesheets')) {
            return;
        }

        $this->execute(
            'INSERT INTO absence_periods (
                user_id, date_from, date_to, entry_type, absence_reason_code, source,
                vacation_request_id, booked_day_count, note, lock_version,
                created_by_user_id, updated_by_user_id, created_at, updated_at,
                is_deleted, deleted_at, deleted_by_user_id
             )
             SELECT
                vacation_requests.user_id,
                vacation_requests.date_from,
                vacation_requests.date_to,
                "vacation",
                "vacation_paid",
                "vacation_request",
                vacation_requests.id,
                (
                    SELECT COUNT(*)
                    FROM timesheets
                    WHERE timesheets.vacation_request_id = vacation_requests.id
                      AND COALESCE(timesheets.is_deleted, 0) = 0
                ),
                vacation_requests.employee_note,
                1,
                vacation_requests.decided_by_user_id,
                vacation_requests.decided_by_user_id,
                vacation_requests.created_at,
                vacation_requests.updated_at,
                0,
                NULL,
                NULL
             FROM vacation_requests
             WHERE vacation_requests.status = "approved"
               AND COALESCE(vacation_requests.is_deleted, 0) = 0
             ON DUPLICATE KEY UPDATE vacation_request_id = VALUES(vacation_request_id)'
        );

        $this->execute(
            'UPDATE timesheets
             INNER JOIN absence_periods
                ON absence_periods.vacation_request_id = timesheets.vacation_request_id
             SET timesheets.absence_period_id = absence_periods.id
             WHERE timesheets.vacation_request_id IS NOT NULL
               AND timesheets.absence_period_id IS NULL'
        );
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
}
