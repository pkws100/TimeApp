<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class RevisableTimeAndVacationAccounts extends AbstractMigration
{
    public function up(): void
    {
        $this->createEmployeeAccountCutovers();
        $this->createTimeAccountEntries();
        $this->createVacationAccountEntries();
        $this->extendTimesheets();
    }

    public function down(): void
    {
        if ($this->hasTable('timesheets')) {
            $table = $this->table('timesheets');

            foreach (['credited_minutes', 'absence_reason_code'] as $column) {
                if ($table->hasColumn($column)) {
                    $this->alterSystemVersionedTimesheets(static function () use ($table, $column): void {
                        $table->removeColumn($column)->update();
                    });
                }
            }
        }

        foreach (['vacation_account_entries', 'time_account_entries', 'employee_account_cutovers'] as $table) {
            if ($this->hasTable($table)) {
                $this->table($table)->drop()->save();
            }
        }
    }

    private function createEmployeeAccountCutovers(): void
    {
        if ($this->hasTable('employee_account_cutovers')) {
            return;
        }

        $this->table('employee_account_cutovers')
            ->addColumn('user_id', 'integer', ['signed' => false])
            ->addColumn('active_final_user_id', 'integer', ['signed' => false, 'null' => true])
            ->addColumn('effective_from', 'date')
            ->addColumn('opening_time_balance_minutes', 'integer', ['default' => 0])
            ->addColumn('leave_year', 'integer')
            ->addColumn('annual_leave_entitlement_days', 'decimal', ['precision' => 8, 'scale' => 2, 'default' => 0])
            ->addColumn('leave_carryover_days', 'decimal', ['precision' => 8, 'scale' => 2, 'default' => 0])
            ->addColumn('opening_remaining_leave_days', 'decimal', ['precision' => 8, 'scale' => 2, 'default' => 0])
            ->addColumn('source_reference', 'string', ['limit' => 255, 'null' => true])
            ->addColumn('note', 'text', ['null' => true])
            ->addColumn('status', 'enum', ['values' => ['draft', 'final', 'reversed'], 'default' => 'draft'])
            ->addColumn('created_by_user_id', 'integer', ['signed' => false, 'null' => true])
            ->addColumn('finalized_by_user_id', 'integer', ['signed' => false, 'null' => true])
            ->addColumn('finalized_at', 'datetime', ['null' => true])
            ->addColumn('reversed_by_user_id', 'integer', ['signed' => false, 'null' => true])
            ->addColumn('reversed_at', 'datetime', ['null' => true])
            ->addColumn('reversal_note', 'text', ['null' => true])
            ->addColumn('created_at', 'datetime', ['default' => 'CURRENT_TIMESTAMP'])
            ->addColumn('updated_at', 'datetime', ['default' => 'CURRENT_TIMESTAMP'])
            ->addForeignKey('user_id', 'users', 'id', ['delete' => 'CASCADE'])
            ->addForeignKey('active_final_user_id', 'users', 'id', ['delete' => 'CASCADE'])
            ->addForeignKey('created_by_user_id', 'users', 'id', ['delete' => 'SET_NULL'])
            ->addForeignKey('finalized_by_user_id', 'users', 'id', ['delete' => 'SET_NULL'])
            ->addForeignKey('reversed_by_user_id', 'users', 'id', ['delete' => 'SET_NULL'])
            ->addIndex(['user_id', 'status'])
            ->addIndex(['active_final_user_id'], ['unique' => true])
            ->addIndex(['effective_from'])
            ->create();
    }

    private function createTimeAccountEntries(): void
    {
        if ($this->hasTable('time_account_entries')) {
            return;
        }

        $this->table('time_account_entries')
            ->addColumn('user_id', 'integer', ['signed' => false])
            ->addColumn('effective_date', 'date')
            ->addColumn('minutes', 'integer')
            ->addColumn('entry_type', 'string', ['limit' => 60])
            ->addColumn('source_type', 'string', ['limit' => 80, 'null' => true])
            ->addColumn('source_id', 'integer', ['signed' => false, 'null' => true])
            ->addColumn('description', 'text')
            ->addColumn('created_by_user_id', 'integer', ['signed' => false, 'null' => true])
            ->addColumn('approved_by_user_id', 'integer', ['signed' => false, 'null' => true])
            ->addColumn('reversal_of_id', 'integer', ['signed' => false, 'null' => true])
            ->addColumn('created_at', 'datetime', ['default' => 'CURRENT_TIMESTAMP'])
            ->addForeignKey('user_id', 'users', 'id', ['delete' => 'CASCADE'])
            ->addForeignKey('created_by_user_id', 'users', 'id', ['delete' => 'SET_NULL'])
            ->addForeignKey('approved_by_user_id', 'users', 'id', ['delete' => 'SET_NULL'])
            ->addIndex(['user_id', 'effective_date'])
            ->addIndex(['entry_type'])
            ->addIndex(['source_type', 'source_id', 'entry_type'], ['unique' => true])
            ->addIndex(['reversal_of_id'])
            ->create();

        $this->table('time_account_entries')
            ->addForeignKey('reversal_of_id', 'time_account_entries', 'id', ['delete' => 'RESTRICT'])
            ->update();
    }

    private function createVacationAccountEntries(): void
    {
        if ($this->hasTable('vacation_account_entries')) {
            return;
        }

        $this->table('vacation_account_entries')
            ->addColumn('user_id', 'integer', ['signed' => false])
            ->addColumn('leave_year', 'integer')
            ->addColumn('effective_date', 'date')
            ->addColumn('days', 'decimal', ['precision' => 8, 'scale' => 2])
            ->addColumn('entry_type', 'string', ['limit' => 60])
            ->addColumn('source_type', 'string', ['limit' => 80, 'null' => true])
            ->addColumn('source_id', 'integer', ['signed' => false, 'null' => true])
            ->addColumn('description', 'text')
            ->addColumn('created_by_user_id', 'integer', ['signed' => false, 'null' => true])
            ->addColumn('approved_by_user_id', 'integer', ['signed' => false, 'null' => true])
            ->addColumn('reversal_of_id', 'integer', ['signed' => false, 'null' => true])
            ->addColumn('created_at', 'datetime', ['default' => 'CURRENT_TIMESTAMP'])
            ->addForeignKey('user_id', 'users', 'id', ['delete' => 'CASCADE'])
            ->addForeignKey('created_by_user_id', 'users', 'id', ['delete' => 'SET_NULL'])
            ->addForeignKey('approved_by_user_id', 'users', 'id', ['delete' => 'SET_NULL'])
            ->addIndex(['user_id', 'leave_year', 'effective_date'])
            ->addIndex(['entry_type'])
            ->addIndex(['source_type', 'source_id', 'entry_type'], ['unique' => true])
            ->addIndex(['reversal_of_id'])
            ->create();

        $this->table('vacation_account_entries')
            ->addForeignKey('reversal_of_id', 'vacation_account_entries', 'id', ['delete' => 'RESTRICT'])
            ->update();
    }

    private function extendTimesheets(): void
    {
        if (!$this->hasTable('timesheets')) {
            return;
        }

        $table = $this->table('timesheets');

        if (!$table->hasColumn('credited_minutes')) {
            $this->alterSystemVersionedTimesheets(static function () use ($table): void {
                $table->addColumn('credited_minutes', 'integer', ['null' => true, 'after' => 'net_minutes'])->update();
            });
        }

        $table = $this->table('timesheets');

        if (!$table->hasColumn('absence_reason_code')) {
            $this->alterSystemVersionedTimesheets(static function () use ($table): void {
                $table->addColumn('absence_reason_code', 'string', ['limit' => 80, 'null' => true, 'after' => 'entry_type'])->update();
            });
        }

        $table = $this->table('timesheets');

        if (!$table->hasIndex(['user_id', 'work_date', 'absence_reason_code'])) {
            $this->alterSystemVersionedTimesheets(static function () use ($table): void {
                $table->addIndex(['user_id', 'work_date', 'absence_reason_code'], ['name' => 'idx_timesheets_user_date_absence'])->update();
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
}
