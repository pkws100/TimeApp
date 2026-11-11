<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class WorkTimeNotificationDelivery extends AbstractMigration
{
    public function up(): void
    {
        $columns = [
            'employee_user_id' => ['integer', ['signed' => false, 'null' => true]],
            'timesheet_id' => ['integer', ['signed' => false, 'null' => true]],
            'proposal_id' => ['integer', ['signed' => false, 'null' => true]],
            'policy_version_id' => ['integer', ['signed' => false, 'null' => true]],
            'event_type' => ['string', ['limit' => 80, 'default' => 'work_time_checkout_proposal']],
            'next_attempt_at' => ['datetime', ['null' => true]],
            'accepted_at' => ['datetime', ['null' => true]],
            'provider_message_id' => ['string', ['limit' => 200, 'null' => true]],
            'retryable' => ['boolean', ['default' => false]],
            'legacy_status' => ['string', ['limit' => 40, 'null' => true]],
        ];
        foreach ($columns as $name => [$type, $options]) {
            if (!$this->table('work_time_notification_events')->hasColumn($name)) {
                $this->table('work_time_notification_events')->addColumn($name, $type, $options)->update();
            }
        }
        $events = $this->table('work_time_notification_events');
        $events->changeColumn('status', 'string', ['limit' => 40, 'default' => 'waiting'])->update();
        foreach (['employee_user_id' => ['users', 'id'], 'timesheet_id' => ['timesheets', 'id'], 'proposal_id' => ['work_time_checkout_proposals', 'id'], 'policy_version_id' => ['work_time_policy_versions', 'id']] as $column => [$table, $key]) {
            if (!$events->hasForeignKey($column)) {
                $events->addForeignKey($column, $table, $key, ['delete' => 'RESTRICT'])->update();
            }
        }
        if (!$events->hasIndex(['status', 'next_attempt_at', 'id'])) {
            $events->addIndex(['status', 'next_attempt_at', 'id'], ['name' => 'idx_work_time_notification_due'])->update();
        }
        if (!$events->hasIndex(['employee_user_id', 'id'])) {
            $events->addIndex(['employee_user_id', 'id'], ['name' => 'idx_work_time_notification_employee'])->update();
        }
        $this->execute('UPDATE work_time_notification_events e JOIN work_time_checkout_proposals p ON e.event_key = CONCAT("checkout-proposal:", p.id) SET e.employee_user_id = p.user_id, e.timesheet_id = p.timesheet_id, e.proposal_id = p.id, e.policy_version_id = p.policy_version_id WHERE e.employee_user_id IS NULL');
        // Preserve old status/timestamps. The legacy SMS implementation was a
        // network-free mock; its "sent" status proves simulation only. Push "sent"
        // proves provider acceptance, never device delivery. Old errors remain unclear.
        $this->execute('UPDATE work_time_notification_events SET legacy_status = status, accepted_at = CASE WHEN status = "sent" AND channel = "sms" THEN NULL WHEN status = "sent" THEN sent_at ELSE accepted_at END, status = CASE status WHEN "pending" THEN "waiting" WHEN "sent" THEN CASE WHEN channel = "sms" THEN "test_simulated" ELSE "provider_accepted" END WHEN "disabled" THEN "not_configured" WHEN "failed" THEN "uncertain" ELSE status END WHERE legacy_status IS NULL');
        if (!$this->hasTable('work_time_notification_retry_requests')) {
            $this->table('work_time_notification_retry_requests')
                ->addColumn('notification_event_id', 'integer', ['signed' => false])
                ->addColumn('actor_user_id', 'integer', ['signed' => false])
                ->addColumn('request_id', 'string', ['limit' => 80])
                ->addColumn('reason', 'text')
                ->addColumn('previous_status', 'string', ['limit' => 40])
                ->addColumn('created_at', 'datetime', ['default' => 'CURRENT_TIMESTAMP'])
                ->addForeignKey('notification_event_id', 'work_time_notification_events', 'id', ['delete' => 'RESTRICT'])
                ->addForeignKey('actor_user_id', 'users', 'id', ['delete' => 'RESTRICT'])
                ->addIndex(['actor_user_id', 'request_id'], ['unique' => true, 'name' => 'uniq_work_time_notification_retry_request'])
                ->create();
        }
        if (!$this->hasTable('work_time_notification_attempts')) {
            $this->table('work_time_notification_attempts')
                ->addColumn('notification_event_id', 'integer', ['signed' => false])
                ->addColumn('attempt_number', 'integer', ['signed' => false])
                ->addColumn('trigger_type', 'string', ['limit' => 20])
                ->addColumn('retry_request_id', 'integer', ['signed' => false, 'null' => true])
                ->addColumn('provider_request_key', 'string', ['limit' => 200])
                ->addColumn('status', 'string', ['limit' => 40])
                ->addColumn('provider_message_id', 'string', ['limit' => 200, 'null' => true])
                ->addColumn('error_message', 'text', ['null' => true])
                ->addColumn('accepted_at', 'datetime', ['null' => true])
                ->addColumn('finished_at', 'datetime', ['null' => true])
                ->addColumn('created_at', 'datetime', ['default' => 'CURRENT_TIMESTAMP'])
                ->addForeignKey('notification_event_id', 'work_time_notification_events', 'id', ['delete' => 'RESTRICT'])
                ->addForeignKey('retry_request_id', 'work_time_notification_retry_requests', 'id', ['delete' => 'RESTRICT'])
                ->addIndex(['notification_event_id', 'attempt_number'], ['unique' => true, 'name' => 'uniq_work_time_notification_attempt'])
                ->create();
        }
        if (!$this->hasTable('work_time_notification_history')) {
            $this->table('work_time_notification_history')
                ->addColumn('notification_event_id', 'integer', ['signed' => false])
                ->addColumn('actor_user_id', 'integer', ['signed' => false, 'null' => true])
                ->addColumn('action', 'string', ['limit' => 40])
                ->addColumn('previous_status', 'string', ['limit' => 40, 'null' => true])
                ->addColumn('new_status', 'string', ['limit' => 40])
                ->addColumn('reason', 'text', ['null' => true])
                ->addColumn('created_at', 'datetime', ['default' => 'CURRENT_TIMESTAMP'])
                ->addForeignKey('notification_event_id', 'work_time_notification_events', 'id', ['delete' => 'RESTRICT'])
                ->addForeignKey('actor_user_id', 'users', 'id', ['delete' => 'RESTRICT'])
                ->addIndex(['notification_event_id', 'id'])
                ->create();
        }
    }

    public function down(): void
    {
        foreach (['work_time_notification_history', 'work_time_notification_attempts', 'work_time_notification_retry_requests'] as $table) {
            if ($this->hasTable($table)) { $this->table($table)->drop()->save(); }
        }
        $events = $this->table('work_time_notification_events');
        foreach (['employee_user_id', 'timesheet_id', 'proposal_id', 'policy_version_id'] as $column) {
            if ($events->hasForeignKey($column)) { $events->dropForeignKey($column)->update(); }
        }
        foreach (['idx_work_time_notification_due', 'idx_work_time_notification_employee'] as $index) {
            if ($events->hasIndexByName($index)) { $events->removeIndexByName($index)->update(); }
        }
        $this->execute('UPDATE work_time_notification_events SET status = CASE WHEN legacy_status IN ("pending", "sent", "disabled", "failed", "sending", "uncertain") THEN legacy_status ELSE CASE status WHEN "waiting" THEN "pending" WHEN "provider_accepted" THEN "sent" WHEN "not_configured" THEN "disabled" WHEN "manual_retry_requested" THEN "pending" WHEN "test_simulated" THEN "disabled" ELSE status END END');
        foreach (['employee_user_id', 'timesheet_id', 'proposal_id', 'policy_version_id', 'event_type', 'next_attempt_at', 'accepted_at', 'provider_message_id', 'retryable', 'legacy_status'] as $column) {
            if ($events->hasColumn($column)) { $events->removeColumn($column)->update(); }
        }
        $events->changeColumn('status', 'string', ['limit' => 20, 'default' => 'pending'])->update();
    }
}
