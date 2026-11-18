<?php

declare(strict_types=1);

namespace App\Domain\Personnel\Backup;

use RuntimeException;

final class PersonnelBackupFailure extends RuntimeException
{
    public function __construct(public readonly string $reason)
    {
        parent::__construct('Die private Personalaktensicherung wurde abgebrochen.');
    }
}
