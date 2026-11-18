<?php

declare(strict_types=1);

namespace App\Domain\Personnel\Compliance;

use RuntimeException;

final class PersonnelFileUnavailable extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('Die Personalaktendatei kann nicht sicher verarbeitet werden.');
    }
}
