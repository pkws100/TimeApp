<?php

declare(strict_types=1);

namespace App\Domain\Personnel\Compliance;

final class PersonnelConflict extends \RuntimeException
{
    public function __construct(string $message = 'Der Personalaktenstand hat sich geändert. Bitte neu laden und prüfen.')
    {
        parent::__construct($message);
    }
}
