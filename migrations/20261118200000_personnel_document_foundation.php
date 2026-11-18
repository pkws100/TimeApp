<?php

declare(strict_types=1);

use Phinx\Db\Table;
use Phinx\Migration\AbstractMigration;

final class PersonnelDocumentFoundation extends AbstractMigration
{
    private const PERSONNEL_TABLES = [
        'employee_document_access_log',
        'employee_document_audit',
        'employee_document_versions',
        'employee_documents',
        'employee_employment_contexts',
    ];

    public function up(): void
    {
        $this->createCatalogue();
        $this->createEmploymentContexts();
        $this->createDocuments();
        $this->createDocumentVersions();
        $this->createDocumentAudit();
        $this->createAccessLog();
        $this->preventHistoryUpdates();
    }

    public function down(): void
    {
        foreach (self::PERSONNEL_TABLES as $table) {
            if ($this->hasTable($table) && $this->fetchRow("SELECT 1 FROM `{$table}` LIMIT 1") !== false) {
                throw new RuntimeException('Die Personalunterlagen-Migration kann bei vorhandenen Personaldaten nicht zurückgenommen werden.');
            }
        }

        foreach ([...self::PERSONNEL_TABLES, 'personnel_document_requirements', 'personnel_document_types', 'personnel_compliance_sources'] as $table) {
            if ($this->hasTable($table)) {
                $this->table($table)->drop()->save();
            }
        }
    }

    private function createCatalogue(): void
    {
        $this->createTableIfMissing($this->table('personnel_compliance_sources')
            ->addColumn('code', 'string', ['limit' => 80])
            ->addColumn('version', 'string', ['limit' => 80])
            ->addColumn('title', 'string', ['limit' => 255])
            ->addColumn('url', 'string', ['limit' => 500])
            ->addColumn('published_on', 'date', ['null' => true])
            ->addColumn('effective_from', 'date', ['null' => true])
            ->addColumn('checked_on', 'date')
            ->addColumn('created_at', 'datetime', ['default' => 'CURRENT_TIMESTAMP'])
            ->addIndex(['code', 'version'], ['unique' => true, 'name' => 'uq_personnel_source_version']));

        $this->createTableIfMissing($this->table('personnel_document_types')
            ->addColumn('code', 'string', ['limit' => 80])
            ->addColumn('label', 'string', ['limit' => 180])
            ->addColumn('category', 'string', ['limit' => 60])
            ->addColumn('created_at', 'datetime', ['default' => 'CURRENT_TIMESTAMP'])
            ->addIndex(['code'], ['unique' => true, 'name' => 'uq_personnel_document_type_code']));

        $this->createTableIfMissing($this->table('personnel_document_requirements')
            ->addColumn('rule_code', 'string', ['limit' => 80])
            ->addColumn('revision', 'integer', ['signed' => false, 'default' => 1])
            ->addColumn('document_type_id', 'integer', ['signed' => false])
            ->addColumn('source_id', 'integer', ['signed' => false])
            ->addColumn('citation', 'string', ['limit' => 255])
            ->addColumn('valid_from', 'date', ['null' => true])
            ->addColumn('valid_until', 'date', ['null' => true])
            ->addColumn('legal_force', 'enum', ['values' => ['statutory', 'recommendation', 'optional'], 'default' => 'statutory'])
            ->addColumn('record_kind', 'string', ['limit' => 60])
            ->addColumn('record_frequency', 'string', ['limit' => 40])
            ->addColumn('applicability_mode', 'enum', ['values' => ['manual_review'], 'default' => 'manual_review'])
            ->addColumn('employment_scope', 'text')
            ->addColumn('evidence_rule', 'text')
            ->addColumn('signature_rule', 'text')
            ->addColumn('retention_trigger', 'string', ['limit' => 80, 'null' => true])
            ->addColumn('retention_years', 'smallinteger', ['signed' => false, 'null' => true])
            ->addColumn('retention_rule', 'text')
            ->addColumn('created_at', 'datetime', ['default' => 'CURRENT_TIMESTAMP'])
            ->addIndex(['rule_code', 'revision'], ['unique' => true, 'name' => 'uq_personnel_requirement_revision'])
            ->addIndex(['id', 'document_type_id'], ['unique' => true, 'name' => 'uq_personnel_requirement_type_pair'])
            ->addIndex(['document_type_id', 'valid_from', 'valid_until'], ['name' => 'ix_personnel_requirement_type_validity']));
        $this->ensureForeignKeys('personnel_document_requirements', [
            ['document_type_id', 'personnel_document_types'],
            ['source_id', 'personnel_compliance_sources'],
        ]);

        $this->execute("ALTER TABLE personnel_document_requirements
            ADD CONSTRAINT IF NOT EXISTS ck_personnel_requirement_revision CHECK (revision > 0),
            ADD CONSTRAINT IF NOT EXISTS ck_personnel_requirement_validity CHECK (valid_until IS NULL OR (valid_from IS NOT NULL AND valid_until >= valid_from)),
            ADD CONSTRAINT IF NOT EXISTS ck_personnel_requirement_retention CHECK (retention_years IS NULL OR retention_trigger IS NOT NULL),
            ADD CONSTRAINT IF NOT EXISTS ck_personnel_requirement_applicability CHECK (applicability_mode = 'manual_review')");
    }

    private function createEmploymentContexts(): void
    {
        $this->createTableIfMissing($this->table('employee_employment_contexts')
            ->addColumn('user_id', 'integer', ['signed' => false])
            ->addColumn('employment_key', 'string', ['limit' => 64])
            ->addColumn('revision', 'integer', ['signed' => false, 'default' => 1])
            ->addColumn('effective_from', 'date')
            ->addColumn('effective_until', 'date', ['null' => true])
            ->addColumn('employment_type', 'enum', ['values' => ['unknown', 'regular', 'marginal', 'short_term', 'vocational_training', 'other'], 'default' => 'unknown'])
            ->addColumn('student_status', 'enum', ['values' => ['unknown', 'not_applicable', 'working_student', 'other'], 'default' => 'unknown'])
            ->addColumn('pension_insurance_status', 'enum', ['values' => ['unknown', 'mandatory', 'exempt', 'other'], 'default' => 'unknown'])
            ->addColumn('cross_border_status', 'enum', ['values' => ['unknown', 'not_applicable', 'coordination_required', 'other'], 'default' => 'unknown'])
            ->addColumn('work_authorization_status', 'enum', ['values' => ['unknown', 'not_required', 'evidence_required'], 'default' => 'unknown'])
            ->addColumn('source', 'string', ['limit' => 40, 'default' => 'manual'])
            ->addColumn('revision_reason', 'string', ['limit' => 255, 'null' => true])
            ->addColumn('created_by_user_id', 'integer', ['signed' => false])
            ->addColumn('created_at', 'datetime', ['default' => 'CURRENT_TIMESTAMP'])
            ->addIndex(['user_id', 'employment_key', 'revision'], ['unique' => true, 'name' => 'uq_employee_employment_revision'])
            ->addIndex(['id', 'user_id'], ['unique' => true, 'name' => 'uq_employee_employment_user_pair'])
            ->addIndex(['user_id', 'effective_from', 'effective_until'], ['name' => 'ix_employee_employment_validity']));
        $this->ensureForeignKeys('employee_employment_contexts', [
            ['user_id', 'users'],
            ['created_by_user_id', 'users'],
        ]);

        $this->execute("ALTER TABLE employee_employment_contexts
            ADD CONSTRAINT IF NOT EXISTS ck_employee_employment_revision CHECK (revision = 1 OR (revision > 1 AND revision_reason IS NOT NULL AND LENGTH(TRIM(revision_reason)) > 0)),
            ADD CONSTRAINT IF NOT EXISTS ck_employee_employment_validity CHECK (effective_until IS NULL OR effective_until >= effective_from),
            ADD CONSTRAINT IF NOT EXISTS ck_employee_employment_traits CHECK (
                employment_type <> '' AND student_status <> '' AND pension_insurance_status <> ''
                AND cross_border_status <> '' AND work_authorization_status <> ''
            )");
    }

    private function createDocuments(): void
    {
        $this->createTableIfMissing($this->table('employee_documents')
            ->addColumn('user_id', 'integer', ['signed' => false])
            ->addColumn('employment_context_id', 'integer', ['signed' => false, 'null' => true])
            ->addColumn('document_type_id', 'integer', ['signed' => false])
            ->addColumn('document_requirement_id', 'integer', ['signed' => false, 'null' => true])
            ->addColumn('period_from', 'date', ['null' => true])
            ->addColumn('period_until', 'date', ['null' => true])
            ->addColumn('issued_on', 'date', ['null' => true])
            ->addColumn('received_on', 'date', ['null' => true])
            ->addColumn('source', 'string', ['limit' => 40, 'default' => 'manual'])
            ->addColumn('review_status', 'enum', ['values' => ['pending', 'reviewed', 'rejected'], 'default' => 'pending'])
            ->addColumn('retention_anchor_on', 'date', ['null' => true])
            ->addColumn('retain_until', 'date', ['null' => true])
            ->addColumn('retention_hold', 'boolean', ['default' => false])
            ->addColumn('created_by_user_id', 'integer', ['signed' => false])
            ->addColumn('updated_by_user_id', 'integer', ['signed' => false, 'null' => true])
            ->addColumn('archived_at', 'datetime', ['null' => true])
            ->addColumn('archived_by_user_id', 'integer', ['signed' => false, 'null' => true])
            ->addColumn('created_at', 'datetime', ['default' => 'CURRENT_TIMESTAMP'])
            ->addColumn('updated_at', 'datetime', ['default' => 'CURRENT_TIMESTAMP'])
            ->addIndex(['user_id', 'archived_at', 'document_type_id', 'period_from'], ['name' => 'ix_employee_document_listing']));
        $this->ensureForeignKeys('employee_documents', [
            ['user_id', 'users'],
            [['employment_context_id', 'user_id'], 'employee_employment_contexts', ['id', 'user_id']],
            ['document_type_id', 'personnel_document_types'],
            [['document_requirement_id', 'document_type_id'], 'personnel_document_requirements', ['id', 'document_type_id']],
            ['created_by_user_id', 'users'],
            ['updated_by_user_id', 'users'],
            ['archived_by_user_id', 'users'],
        ]);

        $this->execute('ALTER TABLE employee_documents
            ADD CONSTRAINT IF NOT EXISTS ck_employee_document_period CHECK (period_until IS NULL OR (period_from IS NOT NULL AND period_until >= period_from)),
            ADD CONSTRAINT IF NOT EXISTS ck_employee_document_retention CHECK (retention_anchor_on IS NULL OR retain_until IS NULL OR retain_until >= retention_anchor_on),
            ADD CONSTRAINT IF NOT EXISTS ck_employee_document_archive CHECK ((archived_at IS NULL) = (archived_by_user_id IS NULL))');
    }

    private function createDocumentVersions(): void
    {
        $this->createTableIfMissing($this->table('employee_document_versions')
            ->addColumn('document_id', 'integer', ['signed' => false])
            ->addColumn('version_number', 'integer', ['signed' => false])
            ->addColumn('supersedes_version_number', 'integer', ['signed' => false, 'null' => true])
            ->addColumn('storage_key', 'string', ['limit' => 255, 'null' => true])
            ->addColumn('sha256', 'char', ['limit' => 64, 'null' => true])
            ->addColumn('mime_type', 'string', ['limit' => 150, 'null' => true])
            ->addColumn('size_bytes', 'biginteger', ['signed' => false, 'null' => true])
            ->addColumn('original_status', 'enum', ['values' => ['unknown', 'digital_original', 'digitized_copy', 'paper_original_retained', 'not_applicable'], 'default' => 'unknown'])
            ->addColumn('signature_status', 'enum', ['values' => ['unknown', 'unverified', 'verified', 'invalid', 'not_applicable'], 'default' => 'unknown'])
            ->addColumn('created_by_user_id', 'integer', ['signed' => false])
            ->addColumn('created_at', 'datetime', ['default' => 'CURRENT_TIMESTAMP'])
            ->addIndex(['document_id', 'version_number'], ['unique' => true, 'name' => 'uq_employee_document_version_number'])
            ->addIndex(['id', 'document_id'], ['unique' => true, 'name' => 'uq_employee_document_version_pair'])
            ->addIndex(['storage_key'], ['unique' => true, 'name' => 'uq_employee_document_storage_key']));
        $this->ensureForeignKeys('employee_document_versions', [
            ['document_id', 'employee_documents'],
            [['document_id', 'supersedes_version_number'], 'employee_document_versions', ['document_id', 'version_number']],
            ['created_by_user_id', 'users'],
        ]);

        $this->execute("ALTER TABLE employee_document_versions
            ADD CONSTRAINT IF NOT EXISTS ck_employee_document_version_sequence CHECK (
                (version_number = 1 AND supersedes_version_number IS NULL)
                OR (version_number > 1 AND supersedes_version_number IS NOT NULL AND supersedes_version_number + 1 = version_number)
            ),
            ADD CONSTRAINT IF NOT EXISTS ck_employee_document_file_metadata CHECK (
                (storage_key IS NULL AND sha256 IS NULL AND mime_type IS NULL AND size_bytes IS NULL)
                OR (storage_key IS NOT NULL AND storage_key REGEXP BINARY '^[a-z0-9_-]+$'
                    AND sha256 IS NOT NULL AND sha256 REGEXP BINARY '^[a-f0-9]{64}$'
                    AND mime_type IS NOT NULL AND LENGTH(TRIM(mime_type)) > 0
                    AND size_bytes IS NOT NULL AND size_bytes > 0)
            )");
    }

    private function createDocumentAudit(): void
    {
        $this->createTableIfMissing($this->table('employee_document_audit')
            ->addColumn('document_id', 'integer', ['signed' => false])
            ->addColumn('document_version_id', 'integer', ['signed' => false, 'null' => true])
            ->addColumn('action', 'string', ['limit' => 50])
            ->addColumn('actor_user_id', 'integer', ['signed' => false])
            ->addColumn('reason', 'string', ['limit' => 255])
            ->addColumn('created_at', 'datetime', ['default' => 'CURRENT_TIMESTAMP'])
            ->addIndex(['document_id', 'created_at', 'id'], ['name' => 'ix_employee_document_audit_history']));
        $this->ensureForeignKeys('employee_document_audit', [
            ['document_id', 'employee_documents'],
            [['document_version_id', 'document_id'], 'employee_document_versions', ['id', 'document_id']],
            ['actor_user_id', 'users'],
        ]);

        $this->execute('ALTER TABLE employee_document_audit
            ADD CONSTRAINT IF NOT EXISTS ck_employee_document_audit_reason CHECK (LENGTH(TRIM(reason)) > 0)');
    }

    private function createAccessLog(): void
    {
        $this->createTableIfMissing($this->table('employee_document_access_log')
            ->addColumn('document_id', 'integer', ['signed' => false, 'null' => true])
            ->addColumn('document_version_id', 'integer', ['signed' => false, 'null' => true])
            ->addColumn('action', 'string', ['limit' => 50])
            ->addColumn('actor_user_id', 'integer', ['signed' => false])
            ->addColumn('outcome', 'enum', ['values' => ['allowed', 'denied']])
            ->addColumn('created_at', 'datetime', ['default' => 'CURRENT_TIMESTAMP'])
            ->addIndex(['document_id', 'created_at', 'id'], ['name' => 'ix_employee_document_access_history'])
            ->addIndex(['actor_user_id', 'created_at', 'id'], ['name' => 'ix_employee_document_access_actor']));
        $this->ensureForeignKeys('employee_document_access_log', [
            ['document_id', 'employee_documents'],
            [['document_version_id', 'document_id'], 'employee_document_versions', ['id', 'document_id']],
            ['actor_user_id', 'users'],
        ]);

        $this->execute('ALTER TABLE employee_document_access_log
            ADD CONSTRAINT IF NOT EXISTS ck_employee_document_access_version CHECK (document_version_id IS NULL OR document_id IS NOT NULL)');
    }

    private function createTableIfMissing(Table $table): void
    {
        if ($table->exists()) {
            $table->reset();
            return;
        }

        $table->create();
    }

    /** @param list<array{string|list<string>, string, 2?: list<string>}> $references */
    private function ensureForeignKeys(string $name, array $references): void
    {
        // MariaDB commits table creation separately from Phinx's foreign-key ALTER.
        $table = $this->table($name);
        foreach ($references as $reference) {
            [$columns, $referencedTable] = $reference;
            if (!$table->hasForeignKey($columns)) {
                $table->addForeignKey($columns, $referencedTable, $reference[2] ?? 'id', ['delete' => 'RESTRICT']);
            }
        }
        $table->update();
    }

    private function preventHistoryUpdates(): void
    {
        foreach ([
            'personnel_compliance_sources',
            'personnel_document_types',
            'personnel_document_requirements',
            'employee_employment_contexts',
            'employee_document_versions',
            'employee_document_audit',
            'employee_document_access_log',
        ] as $table) {
            $this->execute("CREATE TRIGGER IF NOT EXISTS `immutable_{$table}` BEFORE UPDATE ON `{$table}` FOR EACH ROW
                SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Historische Personalunterlagen-Einträge sind unveränderlich.'");
        }
    }
}
