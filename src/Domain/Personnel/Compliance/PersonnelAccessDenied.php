<?php

declare(strict_types=1);

namespace App\Domain\Personnel\Compliance;

final class PersonnelAccessDenied extends \RuntimeException
{
    public function __construct()
    {
        parent::__construct('Der Personalaktenzugriff ist nicht verfügbar.');
    }
}
