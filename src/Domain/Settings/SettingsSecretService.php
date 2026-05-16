<?php

declare(strict_types=1);

namespace App\Domain\Settings;

use RuntimeException;

final class SettingsSecretService
{
    private const PREFIX = 'enc:v1:';
    private const CIPHER = 'aes-256-gcm';

    public function __construct(private string $keyMaterial)
    {
    }

    public function isEncrypted(?string $value): bool
    {
        return is_string($value) && str_starts_with($value, self::PREFIX);
    }

    public function encrypt(?string $plaintext): ?string
    {
        if ($plaintext === null || $plaintext === '') {
            return null;
        }

        $this->ensureAvailable();

        $iv = random_bytes(12);
        $tag = '';
        $ciphertext = openssl_encrypt(
            $plaintext,
            self::CIPHER,
            $this->key(),
            OPENSSL_RAW_DATA,
            $iv,
            $tag,
            '',
            16
        );

        if ($ciphertext === false || $tag === '') {
            throw new RuntimeException('Das SMTP-Passwort konnte nicht verschluesselt werden.');
        }

        return self::PREFIX
            . $this->base64UrlEncode($iv) . ':'
            . $this->base64UrlEncode($tag) . ':'
            . $this->base64UrlEncode($ciphertext);
    }

    public function decrypt(?string $storedValue): ?string
    {
        if ($storedValue === null || $storedValue === '') {
            return null;
        }

        if (!$this->isEncrypted($storedValue)) {
            return $storedValue;
        }

        $this->ensureAvailable();

        $payload = substr($storedValue, strlen(self::PREFIX));
        $parts = explode(':', $payload);

        if (count($parts) !== 3) {
            throw new RuntimeException('Das gespeicherte SMTP-Passwort hat ein ungueltiges Secret-Format.');
        }

        [$encodedIv, $encodedTag, $encodedCiphertext] = $parts;
        $iv = $this->base64UrlDecode($encodedIv);
        $tag = $this->base64UrlDecode($encodedTag);
        $ciphertext = $this->base64UrlDecode($encodedCiphertext);

        if ($iv === null || $tag === null || $ciphertext === null || strlen($iv) !== 12 || strlen($tag) !== 16) {
            throw new RuntimeException('Das gespeicherte SMTP-Passwort hat ein ungueltiges Secret-Format.');
        }

        $plaintext = openssl_decrypt(
            $ciphertext,
            self::CIPHER,
            $this->key(),
            OPENSSL_RAW_DATA,
            $iv,
            $tag
        );

        if ($plaintext === false) {
            throw new RuntimeException('Das gespeicherte SMTP-Passwort konnte nicht entschluesselt werden.');
        }

        return $plaintext;
    }

    private function ensureAvailable(): void
    {
        if (!function_exists('openssl_encrypt') || !function_exists('openssl_decrypt')) {
            throw new RuntimeException('Die OpenSSL-Erweiterung ist fuer verschluesselte Settings erforderlich.');
        }

        if (trim($this->keyMaterial) === '') {
            throw new RuntimeException('Bitte SETTINGS_ENCRYPTION_KEY oder APP_SECRET setzen, bevor SMTP-Passwoerter gespeichert oder genutzt werden.');
        }
    }

    private function key(): string
    {
        return hash('sha256', $this->keyMaterial, true);
    }

    private function base64UrlEncode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    private function base64UrlDecode(string $value): ?string
    {
        if ($value === '') {
            return null;
        }

        $padding = strlen($value) % 4;

        if ($padding > 0) {
            $value .= str_repeat('=', 4 - $padding);
        }

        $decoded = base64_decode(strtr($value, '-_', '+/'), true);

        return is_string($decoded) ? $decoded : null;
    }
}
