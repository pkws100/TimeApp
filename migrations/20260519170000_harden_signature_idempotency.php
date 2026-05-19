<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class HardenSignatureIdempotency extends AbstractMigration
{
    public function up(): void
    {
        if ($this->hasTable('timesheet_customer_signatures')) {
            $table = $this->table('timesheet_customer_signatures');

            if (!$table->hasColumn('active_timesheet_id')) {
                $this->execute(
                    'ALTER TABLE timesheet_customer_signatures
                     ADD COLUMN active_timesheet_id INT UNSIGNED
                     GENERATED ALWAYS AS (CASE WHEN archived_at IS NULL THEN timesheet_id ELSE NULL END) STORED'
                );
            }

            if (!$this->indexExists('timesheet_customer_signatures', 'uniq_active_timesheet_signature')) {
                $this->execute('CREATE UNIQUE INDEX uniq_active_timesheet_signature ON timesheet_customer_signatures (active_timesheet_id)');
            }

            if (!$this->indexExists('timesheet_customer_signatures', 'uniq_signature_client_request')) {
                $this->execute('CREATE UNIQUE INDEX uniq_signature_client_request ON timesheet_customer_signatures (user_id, client_request_id)');
            }
        }

        if ($this->hasTable('app_sync_operations') && !$this->indexExists('app_sync_operations', 'uniq_app_sync_user_request')) {
            $this->execute('CREATE UNIQUE INDEX uniq_app_sync_user_request ON app_sync_operations (user_id, client_request_id)');
        }
    }

    public function down(): void
    {
        if ($this->hasTable('app_sync_operations') && $this->indexExists('app_sync_operations', 'uniq_app_sync_user_request')) {
            $this->execute('DROP INDEX uniq_app_sync_user_request ON app_sync_operations');
        }

        if ($this->hasTable('timesheet_customer_signatures')) {
            if ($this->indexExists('timesheet_customer_signatures', 'uniq_signature_client_request')) {
                $this->execute('DROP INDEX uniq_signature_client_request ON timesheet_customer_signatures');
            }

            if ($this->indexExists('timesheet_customer_signatures', 'uniq_active_timesheet_signature')) {
                $this->execute('DROP INDEX uniq_active_timesheet_signature ON timesheet_customer_signatures');
            }

            $table = $this->table('timesheet_customer_signatures');

            if ($table->hasColumn('active_timesheet_id')) {
                $this->execute('ALTER TABLE timesheet_customer_signatures DROP COLUMN active_timesheet_id');
            }
        }
    }

    private function indexExists(string $table, string $index): bool
    {
        $row = $this->fetchRow(
            'SELECT COUNT(*) AS count
             FROM information_schema.statistics
             WHERE table_schema = DATABASE()
               AND table_name = \'' . addslashes($table) . '\'
               AND index_name = \'' . addslashes($index) . '\''
        );

        return (int) ($row['count'] ?? 0) > 0;
    }
}
