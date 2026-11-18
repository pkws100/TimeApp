<?php

declare(strict_types=1);

namespace App\Domain\Personnel\Compliance;

use RuntimeException;

final class PersonnelDelegationDenied extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('Keine Berechtigung fuer diese geschuetzte Aenderung.');
    }
}
