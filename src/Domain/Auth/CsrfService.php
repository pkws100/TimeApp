<?php

declare(strict_types=1);

namespace App\Domain\Auth;

final class CsrfService
{
    private const SESSION_KEY = '_csrf_token';

    public function token(string $context = ''): string
    {
        $existing = $_SESSION[self::SESSION_KEY] ?? null;
        if (!is_string($existing) || $existing === '') {
            $existing = bin2hex(random_bytes(32));
            $_SESSION[self::SESSION_KEY] = $existing;
        }

        return $this->forContext($existing, $context);
    }

    public function isValid(?string $token, string $context = ''): bool
    {
        $sessionToken = $_SESSION[self::SESSION_KEY] ?? null;

        return is_string($token)
            && is_string($sessionToken)
            && $sessionToken !== ''
            && hash_equals($this->forContext($sessionToken, $context), $token);
    }

    private function forContext(string $token, string $context): string
    {
        return $context === '' ? $token : hash_hmac('sha256', $context, $token);
    }
}
