<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;
use Phinx\Migration\IrreversibleMigrationException;

final class PersonnelComplianceRollbackBoundary extends AbstractMigration
{
    public function up(): void
    {
        // Phinx records this boundary before the next migration starts autocommit trigger DDL.
    }

    public function preFlightCheck(): void
    {
        parent::preFlightCheck();

        if (!$this->isMigratingUp()) {
            $this->down();
        }
    }

    public function down(): void
    {
        throw new IrreversibleMigrationException(
            'Der Compliance-Katalog ist vor Phinx-Rollbacks geschützt. Eine geprüfte Datenbanksicherung separat wiederherstellen.'
        );
    }
}
