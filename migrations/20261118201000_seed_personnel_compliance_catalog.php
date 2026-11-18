<?php

declare(strict_types=1);

use App\Domain\Personnel\Compliance\InitialPersonnelComplianceCatalog;
use Phinx\Migration\AbstractMigration;

final class SeedPersonnelComplianceCatalog extends AbstractMigration
{
    public function up(): void
    {
        InitialPersonnelComplianceCatalog::seed($this->getAdapter()->getConnection());
    }

    public function down(): void
    {
        // The schema rollback owns these tables. Keep published revisions and any references intact.
    }
}
