<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class CompanySettingsArea extends AbstractMigration
{
    public function up(): void
    {
        if (!$this->hasTable('company_settings')) {
            $this->table('company_settings', ['id' => false, 'primary_key' => ['id']])
                ->addColumn('id', 'integer', ['signed' => false])
                ->addColumn('company_name', 'string', ['limit' => 150, 'default' => ''])
                ->addColumn('legal_form', 'string', ['limit' => 80, 'null' => true])
                ->addColumn('street', 'string', ['limit' => 150, 'null' => true])
                ->addColumn('house_number', 'string', ['limit' => 20, 'null' => true])
                ->addColumn('postal_code', 'string', ['limit' => 20, 'null' => true])
                ->addColumn('city', 'string', ['limit' => 100, 'null' => true])
                ->addColumn('country', 'string', ['limit' => 100, 'default' => 'Deutschland'])
                ->addColumn('email', 'string', ['limit' => 150, 'null' => true])
                ->addColumn('phone', 'string', ['limit' => 40, 'null' => true])
                ->addColumn('website', 'string', ['limit' => 150, 'null' => true])
                ->addColumn('managing_director', 'string', ['limit' => 150, 'null' => true])
                ->addColumn('register_court', 'string', ['limit' => 150, 'null' => true])
                ->addColumn('commercial_register', 'string', ['limit' => 100, 'null' => true])
                ->addColumn('vat_id', 'string', ['limit' => 60, 'null' => true])
                ->addColumn('tax_number', 'string', ['limit' => 60, 'null' => true])
                ->addColumn('logo_original_name', 'string', ['limit' => 255, 'null' => true])
                ->addColumn('logo_stored_name', 'string', ['limit' => 255, 'null' => true])
                ->addColumn('logo_mime_type', 'string', ['limit' => 150, 'null' => true])
                ->addColumn('logo_path', 'string', ['limit' => 255, 'null' => true])
                ->addColumn('logo_size_bytes', 'biginteger', ['null' => true])
                ->addColumn('agb_text', 'text', ['null' => true])
                ->addColumn('agb_pdf_original_name', 'string', ['limit' => 255, 'null' => true])
                ->addColumn('agb_pdf_stored_name', 'string', ['limit' => 255, 'null' => true])
                ->addColumn('agb_pdf_mime_type', 'string', ['limit' => 150, 'null' => true])
                ->addColumn('agb_pdf_path', 'string', ['limit' => 255, 'null' => true])
                ->addColumn('agb_pdf_size_bytes', 'biginteger', ['null' => true])
                ->addColumn('datenschutz_text', 'text', ['null' => true])
                ->addColumn('datenschutz_pdf_original_name', 'string', ['limit' => 255, 'null' => true])
                ->addColumn('datenschutz_pdf_stored_name', 'string', ['limit' => 255, 'null' => true])
                ->addColumn('datenschutz_pdf_mime_type', 'string', ['limit' => 150, 'null' => true])
                ->addColumn('datenschutz_pdf_path', 'string', ['limit' => 255, 'null' => true])
                ->addColumn('datenschutz_pdf_size_bytes', 'biginteger', ['null' => true])
                ->addColumn('smtp_host', 'string', ['limit' => 150, 'null' => true])
                ->addColumn('smtp_port', 'integer', ['default' => 587])
                ->addColumn('smtp_username', 'string', ['limit' => 190, 'null' => true])
                ->addColumn('smtp_password', 'string', ['limit' => 255, 'null' => true])
                ->addColumn('smtp_encryption', 'enum', ['values' => ['tls', 'ssl', 'none'], 'default' => 'tls'])
                ->addColumn('smtp_from_name', 'string', ['limit' => 150, 'null' => true])
                ->addColumn('smtp_from_email', 'string', ['limit' => 150, 'null' => true])
                ->addColumn('smtp_reply_to_email', 'string', ['limit' => 150, 'null' => true])
                ->addColumn('smtp_last_tested_at', 'datetime', ['null' => true])
                ->addColumn('smtp_last_test_status', 'enum', ['values' => ['untested', 'success', 'error'], 'default' => 'untested'])
                ->addColumn('smtp_last_test_message', 'text', ['null' => true])
                ->addColumn('geo_capture_enabled', 'boolean', ['default' => false])
                ->addColumn('geo_notice_text', 'text', ['null' => true])
                ->addColumn('geo_requires_acknowledgement', 'boolean', ['default' => false])
                ->addColumn('created_at', 'datetime', ['default' => 'CURRENT_TIMESTAMP'])
                ->addColumn('updated_at', 'datetime', ['default' => 'CURRENT_TIMESTAMP'])
                ->create();
        }

        $this->execute(
            "INSERT IGNORE INTO company_settings (
                id, company_name, country, smtp_port, smtp_encryption, smtp_last_test_status, geo_capture_enabled, geo_requires_acknowledgement, created_at, updated_at
            ) VALUES (
                1, '', 'Deutschland', 587, 'tls', 'untested', 0, 0, NOW(), NOW()
            )"
        );

        $this->ensurePermission('settings.manage', 'Globale Settings verwalten', 'backend');
        $this->assignRolePermissions('administrator', ['settings.manage']);
        $this->assignRolePermissions('geschaeftsfuehrung', ['settings.manage']);
    }

    public function down(): void
    {
        if ($this->hasTable('company_settings')) {
            $this->table('company_settings')->drop()->save();
        }
    }

    private function ensurePermission(string $code, string $label, string $scope): void
    {
        $this->execute(
            sprintf(
                "INSERT IGNORE INTO permissions (code, label, scope, created_at) VALUES ('%s', '%s', '%s', NOW())",
                addslashes($code),
                addslashes($label),
                addslashes($scope)
            )
        );
    }

    private function assignRolePermissions(string $roleSlug, array $permissionCodes): void
    {
        foreach ($permissionCodes as $permissionCode) {
            $this->execute(
                sprintf(
                    "INSERT IGNORE INTO role_permissions (role_id, permission_id)
                     SELECT roles.id, permissions.id
                     FROM roles
                     INNER JOIN permissions ON permissions.code = '%s'
                     WHERE roles.slug = '%s'",
                    addslashes($permissionCode),
                    addslashes($roleSlug)
                )
            );
        }
    }
}
