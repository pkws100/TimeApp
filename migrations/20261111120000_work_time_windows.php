<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/** Additive only: existing versioned timesheets and journals are never rewritten. */
final class WorkTimeWindows extends AbstractMigration
{
    public function up(): void
    {
        if (!$this->hasTable('work_time_policy_templates')) {
            $this->table('work_time_policy_templates')
                ->addColumn('name', 'string', ['limit' => 150])
                ->addColumn('description', 'text', ['null' => true])
                ->addColumn('is_active', 'boolean', ['default' => true])
                ->addColumn('created_by_user_id', 'integer', ['signed' => false])
                ->addColumn('created_at', 'datetime', ['default' => 'CURRENT_TIMESTAMP'])
                ->addColumn('archived_at', 'datetime', ['null' => true])
                ->addColumn('archived_by_user_id', 'integer', ['signed' => false, 'null' => true])
                ->addForeignKey('created_by_user_id', 'users', 'id', ['delete' => 'RESTRICT'])
                ->addForeignKey('archived_by_user_id', 'users', 'id', ['delete' => 'RESTRICT'])
                ->create();
        }
        if (!$this->hasTable('work_time_policy_versions')) {
            $this->table('work_time_policy_versions')
                ->addColumn('template_id', 'integer', ['signed' => false])
                ->addColumn('revision', 'integer', ['signed' => false])
                ->addColumn('policy_json', 'text')
                ->addColumn('created_by_user_id', 'integer', ['signed' => false])
                ->addColumn('created_at', 'datetime', ['default' => 'CURRENT_TIMESTAMP'])
                ->addForeignKey('template_id', 'work_time_policy_templates', 'id', ['delete' => 'RESTRICT'])
                ->addForeignKey('created_by_user_id', 'users', 'id', ['delete' => 'RESTRICT'])
                ->addIndex(['template_id', 'revision'], ['unique' => true])
                ->create();
        }
        if (!$this->hasTable('employee_work_time_assignments')) {
            $this->table('employee_work_time_assignments')
                ->addColumn('user_id', 'integer', ['signed' => false])
                ->addColumn('policy_version_id', 'integer', ['signed' => false])
                ->addColumn('effective_from', 'date')
                ->addColumn('effective_to', 'date', ['null' => true])
                ->addColumn('overrides_json', 'text')
                ->addColumn('created_by_user_id', 'integer', ['signed' => false])
                ->addColumn('created_at', 'datetime', ['default' => 'CURRENT_TIMESTAMP'])
                ->addForeignKey('user_id', 'users', 'id', ['delete' => 'RESTRICT'])
                ->addForeignKey('policy_version_id', 'work_time_policy_versions', 'id', ['delete' => 'RESTRICT'])
                ->addForeignKey('created_by_user_id', 'users', 'id', ['delete' => 'RESTRICT'])
                ->addIndex(['user_id', 'effective_from'], ['unique' => true, 'name' => 'uniq_work_time_assignment_start'])
                ->create();
        }
        if (!$this->hasTable('employee_work_time_supervisors')) {
            $this->table('employee_work_time_supervisors')
                ->addColumn('assignment_id', 'integer', ['signed' => false])
                ->addColumn('user_id', 'integer', ['signed' => false])
                ->addForeignKey('assignment_id', 'employee_work_time_assignments', 'id', ['delete' => 'RESTRICT'])
                ->addForeignKey('user_id', 'users', 'id', ['delete' => 'RESTRICT'])
                ->addIndex(['assignment_id', 'user_id'], ['unique' => true])
                ->create();
        }
        if (!$this->hasTable('work_time_policy_audit')) {
            $this->table('work_time_policy_audit')
                ->addColumn('actor_user_id', 'integer', ['signed' => false])
                ->addColumn('action', 'string', ['limit' => 60])
                ->addColumn('entity_id', 'integer', ['signed' => false])
                ->addColumn('before_json', 'text', ['null' => true])
                ->addColumn('after_json', 'text')
                ->addColumn('created_at', 'datetime', ['default' => 'CURRENT_TIMESTAMP'])
                ->addForeignKey('actor_user_id', 'users', 'id', ['delete' => 'RESTRICT'])
                ->create();
        }
        if (!$this->hasTable('work_time_deviations')) {
            $this->table('work_time_deviations')
                ->addColumn('timesheet_id', 'integer', ['signed' => false])
                ->addColumn('user_id', 'integer', ['signed' => false])
                ->addColumn('policy_version_id', 'integer', ['signed' => false])
                ->addColumn('assignment_id', 'integer', ['signed' => false])
                ->addColumn('segment_start_at', 'datetime')
                ->addColumn('segment_end_at', 'datetime')
                ->addColumn('minutes', 'integer', ['signed' => false])
                ->addColumn('kind', 'string', ['limit' => 20])
                ->addColumn('status', 'string', ['limit' => 20, 'default' => 'pending'])
                ->addColumn('calculation_hash', 'string', ['limit' => 64])
                ->addColumn('superseded_at', 'datetime', ['null' => true])
                ->addColumn('created_at', 'datetime', ['default' => 'CURRENT_TIMESTAMP'])
                ->addForeignKey('timesheet_id', 'timesheets', 'id', ['delete' => 'RESTRICT'])
                ->addForeignKey('user_id', 'users', 'id', ['delete' => 'RESTRICT'])
                ->addForeignKey('policy_version_id', 'work_time_policy_versions', 'id', ['delete' => 'RESTRICT'])
                ->addForeignKey('assignment_id', 'employee_work_time_assignments', 'id', ['delete' => 'RESTRICT'])
                ->addIndex(['timesheet_id', 'calculation_hash', 'kind', 'segment_start_at', 'segment_end_at'], ['unique' => true, 'name' => 'uniq_work_time_deviation_source'])
                ->addIndex(['user_id', 'status', 'superseded_at'], ['name' => 'idx_work_time_deviation_status'])
                ->create();
        }
        if (!$this->hasTable('work_time_deviation_decisions')) {
            $this->table('work_time_deviation_decisions')
                ->addColumn('deviation_id', 'integer', ['signed' => false])
                ->addColumn('actor_user_id', 'integer', ['signed' => false])
                ->addColumn('previous_status', 'string', ['limit' => 20])
                ->addColumn('new_status', 'string', ['limit' => 20])
                ->addColumn('reason', 'text')
                ->addColumn('request_id', 'string', ['limit' => 80])
                ->addColumn('created_at', 'datetime', ['default' => 'CURRENT_TIMESTAMP'])
                ->addForeignKey('deviation_id', 'work_time_deviations', 'id', ['delete' => 'RESTRICT'])
                ->addForeignKey('actor_user_id', 'users', 'id', ['delete' => 'RESTRICT'])
                ->addIndex(['actor_user_id', 'request_id'], ['unique' => true, 'name' => 'uniq_work_time_decision_request'])
                ->create();
        }
        if (!$this->hasTable('work_time_checkout_proposals')) {
            $this->table('work_time_checkout_proposals')
                ->addColumn('user_id', 'integer', ['signed' => false])
                ->addColumn('timesheet_id', 'integer', ['signed' => false])
                ->addColumn('policy_version_id', 'integer', ['signed' => false])
                ->addColumn('proposed_end_at', 'datetime')
                ->addColumn('status', 'string', ['limit' => 20, 'default' => 'pending'])
                ->addColumn('resolved_at', 'datetime', ['null' => true])
                ->addColumn('result_timesheet_id', 'integer', ['signed' => false, 'null' => true])
                ->addColumn('created_at', 'datetime', ['default' => 'CURRENT_TIMESTAMP'])
                ->addForeignKey('user_id', 'users', 'id', ['delete' => 'RESTRICT'])
                ->addForeignKey('timesheet_id', 'timesheets', 'id', ['delete' => 'RESTRICT'])
                ->addForeignKey('policy_version_id', 'work_time_policy_versions', 'id', ['delete' => 'RESTRICT'])
                ->addForeignKey('result_timesheet_id', 'timesheets', 'id', ['delete' => 'RESTRICT'])
                ->addIndex(['timesheet_id'], ['unique' => true])
                ->addIndex(['user_id', 'status'])
                ->create();
        }
        if (!$this->hasTable('work_time_checkout_proposal_actions')) {
            $this->table('work_time_checkout_proposal_actions')
                ->addColumn('proposal_id', 'integer', ['signed' => false])
                ->addColumn('user_id', 'integer', ['signed' => false])
                ->addColumn('actor_user_id', 'integer', ['signed' => false, 'null' => true])
                ->addColumn('request_id', 'string', ['limit' => 80])
                ->addColumn('action', 'string', ['limit' => 30])
                ->addColumn('actual_end_at', 'datetime', ['null' => true])
                ->addColumn('created_at', 'datetime', ['default' => 'CURRENT_TIMESTAMP'])
                ->addForeignKey('proposal_id', 'work_time_checkout_proposals', 'id', ['delete' => 'RESTRICT'])
                ->addForeignKey('user_id', 'users', 'id', ['delete' => 'RESTRICT'])
                ->addForeignKey('actor_user_id', 'users', 'id', ['delete' => 'RESTRICT'])
                ->addIndex(['user_id', 'request_id'], ['unique' => true, 'name' => 'uniq_work_time_proposal_request'])
                ->create();
        }
        if (!$this->hasTable('work_time_presence_sessions')) {
            $this->table('work_time_presence_sessions')
                ->addColumn('user_id', 'integer', ['signed' => false])
                ->addColumn('policy_version_id', 'integer', ['signed' => false])
                ->addColumn('work_date', 'date')
                ->addColumn('project_id', 'integer', ['signed' => false, 'null' => true])
                ->addColumn('source', 'string', ['limit' => 20])
                ->addColumn('started_at', 'datetime')
                ->addColumn('ended_at', 'datetime', ['null' => true])
                ->addColumn('work_started_at', 'datetime', ['null' => true])
                ->addColumn('work_timesheet_id', 'integer', ['signed' => false, 'null' => true])
                ->addColumn('request_id', 'string', ['limit' => 80])
                ->addColumn('created_at', 'datetime', ['default' => 'CURRENT_TIMESTAMP'])
                ->addForeignKey('user_id', 'users', 'id', ['delete' => 'RESTRICT'])
                ->addForeignKey('policy_version_id', 'work_time_policy_versions', 'id', ['delete' => 'RESTRICT'])
                ->addForeignKey('project_id', 'projects', 'id', ['delete' => 'RESTRICT'])
                ->addForeignKey('work_timesheet_id', 'timesheets', 'id', ['delete' => 'RESTRICT'])
                ->addIndex(['user_id', 'request_id'], ['unique' => true, 'name' => 'uniq_work_time_presence_request'])
                ->addIndex(['user_id', 'ended_at'])
                ->create();
        }
        if (!$this->hasTable('work_time_notification_events')) {
            $this->table('work_time_notification_events')
                ->addColumn('event_key', 'string', ['limit' => 120])
                ->addColumn('recipient_user_id', 'integer', ['signed' => false])
                ->addColumn('channel', 'string', ['limit' => 20])
                ->addColumn('status', 'string', ['limit' => 20, 'default' => 'pending'])
                ->addColumn('payload_json', 'text')
                ->addColumn('attempts', 'integer', ['signed' => false, 'default' => 0])
                ->addColumn('error_message', 'text', ['null' => true])
                ->addColumn('sent_at', 'datetime', ['null' => true])
                ->addColumn('last_attempt_at', 'datetime', ['null' => true])
                ->addColumn('created_at', 'datetime', ['default' => 'CURRENT_TIMESTAMP'])
                ->addColumn('updated_at', 'datetime', ['default' => 'CURRENT_TIMESTAMP'])
                ->addForeignKey('recipient_user_id', 'users', 'id', ['delete' => 'RESTRICT'])
                ->addIndex(['event_key', 'recipient_user_id', 'channel'], ['unique' => true, 'name' => 'uniq_work_time_notification_event'])
                ->create();
        }
        $labels = [
            'work_time_policies.view' => 'Arbeitszeitregeln ansehen',
            'work_time_policies.manage' => 'Arbeitszeitregeln verwalten',
            'work_time_deviations.view' => 'Arbeitszeitabweichungen ansehen',
            'work_time_deviations.manage' => 'Arbeitszeitabweichungen entscheiden',
            'work_time_notifications.manage' => 'Arbeitszeitbenachrichtigungen verwalten',
        ];
        foreach ($labels as $code => $label) {
            $this->execute('INSERT IGNORE INTO permissions (code, label, scope, created_at) VALUES (' . $this->getAdapter()->getConnection()->quote($code) . ', ' . $this->getAdapter()->getConnection()->quote($label) . ', "backend", NOW())');
            $this->execute('INSERT IGNORE INTO role_permissions (role_id, permission_id) SELECT roles.id, permissions.id FROM roles JOIN permissions ON permissions.code = ' . $this->getAdapter()->getConnection()->quote($code) . ' WHERE roles.slug = "administrator"');
        }
    }

    public function down(): void
    {
        // Explicit rollback is a development operation. Never remove evidence during ordinary usage.
        foreach (['work_time_notification_events', 'work_time_presence_sessions', 'work_time_checkout_proposal_actions', 'work_time_checkout_proposals', 'work_time_deviation_decisions', 'work_time_deviations', 'work_time_policy_audit', 'employee_work_time_supervisors', 'employee_work_time_assignments', 'work_time_policy_versions', 'work_time_policy_templates'] as $table) {
            if ($this->hasTable($table)) { $this->table($table)->drop()->save(); }
        }
    }
}
