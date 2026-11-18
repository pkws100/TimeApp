<?php

declare(strict_types=1);

namespace App\Domain\Personnel\Compliance;

use InvalidArgumentException;

final class PersonnelDate
{
    public static function require(mixed $value): string
    {
        if (!is_string($value) || !preg_match('/^([1-9][0-9]{3})-([0-9]{2})-([0-9]{2})$/D', $value, $parts)
            || !checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1])) {
            throw new InvalidArgumentException('Das Datum ist ungueltig.');
        }

        return $value;
    }
}
