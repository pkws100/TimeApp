<?php

declare(strict_types=1);

namespace App\Domain\Auth;

final class CsrfService
{
    private const SESSION_KEY = '_csrf_token';

    public function token(): string
    {
        $existing = $_SESSION[self::SESSION_KEY] ?? null;

        if (is_string($existing) && $existing !== '') {
            return $existing;
        }

        $token = bin2hex(random_bytes(32));
        $_SESSION[self::SESSION_KEY] = $token;

        return $token;
    }

    public function isValid(?string $token): bool
    {
        $sessionToken = $_SESSION[self::SESSION_KEY] ?? null;

        return is_string($token)
            && is_string($sessionToken)
            && $sessionToken !== ''
            && hash_equals($sessionToken, $token);
    }
}
