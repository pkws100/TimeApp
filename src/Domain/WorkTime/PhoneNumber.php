<?php

declare(strict_types=1);

namespace App\Domain\WorkTime;

use InvalidArgumentException;

final class PhoneNumber
{
    public static function normalize(string $value): string
    {
        $value = preg_replace('/[\s()\-.\/]/', '', trim($value)) ?? '';
        if (str_starts_with($value, '00')) {
            $value = '+' . substr($value, 2);
        }
        // Require an explicit international country code; never guess it.
        if (!preg_match('/^\+[1-9][0-9]{7,14}$/D', $value)) {
            throw new InvalidArgumentException('Die SMS-Telefonnummer benoetigt eine gueltige internationale Laendervorwahl.');
        }

        return $value;
    }
}
