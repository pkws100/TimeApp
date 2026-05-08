<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class InitialSchema extends AbstractMigration
{
    public function up(): void
    {
        $this->table('permissions')
            ->addColumn('code', 'string', ['limit' => 100])
            ->addColumn('label', 'string', ['limit' => 150])
            ->addColumn('scope', 'string', ['limit' => 50, 'default' => 'backend'])
            ->addColumn('created_at', 'datetime', ['default' => 'CURRENT_TIMESTAMP'])
            ->addIndex(['code'], ['unique' => true])
            ->create();

        $this->table('roles')
            ->addColumn('slug', 'string', ['limit' => 60])
            ->addColumn('name', 'string', ['limit' => 100])
            ->addColumn('description', 'string', ['limit' => 255, 'null' => true])
            ->addColumn('is_system_role', 'boolean', ['default' => true])
            ->addColumn('created_at', 'datetime', ['default' => 'CURRENT_TIMESTAMP'])
            ->addColumn('updated_at', 'datetime', ['default' => 'CURRENT_TIMESTAMP'])
            ->addIndex(['slug'], ['unique' => true])
            ->create();

        $this->table('role_permissions')
            ->addColumn('role_id', 'integer', ['signed' => false])
            ->addColumn('permission_id', 'integer', ['signed' => false])
            ->addForeignKey('role_id', 'roles', 'id', ['delete' => 'CASCADE'])
            ->addForeignKey('permission_id', 'permissions', 'id', ['delete' => 'CASCADE'])
            ->addIndex(['role_id', 'permission_id'], ['unique' => true])
            ->create();

        $this->table('users')
            ->addColumn('employee_number', 'string', ['limit' => 30, 'null' => true])
            ->addColumn('first_name', 'string', ['limit' => 100])
            ->addColumn('last_name', 'string', ['limit' => 100])
            ->addColumn('email', 'string', ['limit' => 150])
            ->addColumn('phone', 'string', ['limit' => 40, 'null' => true])
            ->addColumn('password_hash', 'string', ['limit' => 255])
            ->addColumn('employment_status', 'enum', ['values' => ['active', 'inactive', 'terminated'], 'default' => 'active'])
            ->addColumn('emergency_contact_name', 'string', ['limit' => 150, 'null' => true])
            ->addColumn('emergency_contact_phone', 'string', ['limit' => 40, 'null' => true])
            ->addColumn('target_hours_month', 'decimal', ['precision' => 7, 'scale' => 2, 'default' => 0])
            ->addColumn('created_at', 'datetime', ['default' => 'CURRENT_TIMESTAMP'])
            ->addColumn('updated_at', 'datetime', ['default' => 'CURRENT_TIMESTAMP'])
            ->addIndex(['email'], ['unique' => true])
            ->addIndex(['employee_number'], ['unique' => true])
            ->create();

        $this->table('user_roles')
            ->addColumn('user_id', 'integer', ['signed' => false])
            ->addColumn('role_id', 'integer', ['signed' => false])
            ->addForeignKey('user_id', 'users', 'id', ['delete' => 'CASCADE'])
            ->addForeignKey('role_id', 'roles', 'id', ['delete' => 'CASCADE'])
            ->addIndex(['user_id', 'role_id'], ['unique' => true])
            ->create();

        $this->table('projects')
            ->addColumn('project_number', 'string', ['limit' => 40])
            ->addColumn('name', 'string', ['limit' => 150])
            ->addColumn('customer_name', 'string', ['limit' => 150, 'null' => true])
            ->addColumn('status', 'enum', ['values' => ['planning', 'active', 'paused', 'completed', 'archived'], 'default' => 'planning'])
            ->addColumn('address_line_1', 'string', ['limit' => 150, 'null' => true])
            ->addColumn('postal_code', 'string', ['limit' => 20, 'null' => true])
            ->addColumn('city', 'string', ['limit' => 100, 'null' => true])
            ->addColumn('starts_on', 'date', ['null' => true])
            ->addColumn('ends_on', 'date', ['null' => true])
            ->addColumn('created_at', 'datetime', ['default' => 'CURRENT_TIMESTAMP'])
            ->addColumn('updated_at', 'datetime', ['default' => 'CURRENT_TIMESTAMP'])
            ->addIndex(['project_number'], ['unique' => true])
            ->create();

        $this->table('project_memberships')
            ->addColumn('project_id', 'integer', ['signed' => false])
            ->addColumn('user_id', 'integer', ['signed' => false])
            ->addColumn('assignment_role', 'string', ['limit' => 80, 'null' => true])
            ->addColumn('assigned_from', 'date', ['null' => true])
            ->addColumn('assigned_until', 'date', ['null' => true])
            ->addForeignKey('project_id', 'projects', 'id', ['delete' => 'CASCADE'])
            ->addForeignKey('user_id', 'users', 'id', ['delete' => 'CASCADE'])
            ->addIndex(['project_id', 'user_id'], ['unique' => true])
            ->create();

        $this->table('project_files')
            ->addColumn('project_id', 'integer', ['signed' => false])
            ->addColumn('original_name', 'string', ['limit' => 255])
            ->addColumn('stored_name', 'string', ['limit' => 255])
            ->addColumn('mime_type', 'string', ['limit' => 150])
            ->addColumn('size_bytes', 'biginteger')
            ->addColumn('storage_path', 'string', ['limit' => 255])
            ->addColumn('uploaded_by_user_id', 'integer', ['signed' => false, 'null' => true])
            ->addColumn('uploaded_at', 'datetime', ['default' => 'CURRENT_TIMESTAMP'])
            ->addForeignKey('project_id', 'projects', 'id', ['delete' => 'CASCADE'])
            ->addForeignKey('uploaded_by_user_id', 'users', 'id', ['delete' => 'SET_NULL'])
            ->create();

        $this->table('assets')
            ->addColumn('asset_type', 'enum', ['values' => ['vehicle', 'equipment'], 'default' => 'equipment'])
            ->addColumn('name', 'string', ['limit' => 150])
            ->addColumn('identifier', 'string', ['limit' => 80])
            ->addColumn('status', 'enum', ['values' => ['available', 'assigned', 'maintenance', 'retired'], 'default' => 'available'])
            ->addColumn('notes', 'text', ['null' => true])
            ->addColumn('created_at', 'datetime', ['default' => 'CURRENT_TIMESTAMP'])
            ->addColumn('updated_at', 'datetime', ['default' => 'CURRENT_TIMESTAMP'])
            ->addIndex(['identifier'], ['unique' => true])
            ->create();

        $this->table('asset_assignments')
            ->addColumn('asset_id', 'integer', ['signed' => false])
            ->addColumn('project_id', 'integer', ['signed' => false, 'null' => true])
            ->addColumn('user_id', 'integer', ['signed' => false, 'null' => true])
            ->addColumn('assigned_from', 'datetime')
            ->addColumn('assigned_until', 'datetime', ['null' => true])
            ->addColumn('notes', 'string', ['limit' => 255, 'null' => true])
            ->addForeignKey('asset_id', 'assets', 'id', ['delete' => 'CASCADE'])
            ->addForeignKey('project_id', 'projects', 'id', ['delete' => 'SET_NULL'])
            ->addForeignKey('user_id', 'users', 'id', ['delete' => 'SET_NULL'])
            ->create();

        $this->table('timesheets')
            ->addColumn('user_id', 'integer', ['signed' => false])
            ->addColumn('project_id', 'integer', ['signed' => false, 'null' => true])
            ->addColumn('created_by_user_id', 'integer', ['signed' => false, 'null' => true])
            ->addColumn('work_date', 'date')
            ->addColumn('start_time', 'time', ['null' => true])
            ->addColumn('end_time', 'time', ['null' => true])
            ->addColumn('gross_minutes', 'integer', ['default' => 0])
            ->addColumn('break_minutes', 'integer', ['default' => 0])
            ->addColumn('net_minutes', 'integer', ['default' => 0])
            ->addColumn('expenses_amount', 'decimal', ['precision' => 10, 'scale' => 2, 'default' => 0])
            ->addColumn('entry_type', 'enum', ['values' => ['work', 'sick', 'vacation', 'holiday', 'absent'], 'default' => 'work'])
            ->addColumn('note', 'text', ['null' => true])
            ->addColumn('created_at', 'datetime', ['default' => 'CURRENT_TIMESTAMP'])
            ->addColumn('updated_at', 'datetime', ['default' => 'CURRENT_TIMESTAMP'])
            ->addForeignKey('user_id', 'users', 'id', ['delete' => 'CASCADE'])
            ->addForeignKey('project_id', 'projects', 'id', ['delete' => 'SET_NULL'])
            ->addForeignKey('created_by_user_id', 'users', 'id', ['delete' => 'SET_NULL'])
            ->addIndex(['work_date'])
            ->create();

        $this->execute('ALTER TABLE timesheets ADD SYSTEM VERSIONING;');
    }

    public function down(): void
    {
        $this->execute('ALTER TABLE timesheets DROP SYSTEM VERSIONING;');

        $this->table('timesheets')->drop()->save();
        $this->table('asset_assignments')->drop()->save();
        $this->table('assets')->drop()->save();
        $this->table('project_files')->drop()->save();
        $this->table('project_memberships')->drop()->save();
        $this->table('projects')->drop()->save();
        $this->table('user_roles')->drop()->save();
        $this->table('users')->drop()->save();
        $this->table('role_permissions')->drop()->save();
        $this->table('roles')->drop()->save();
        $this->table('permissions')->drop()->save();
    }
}
