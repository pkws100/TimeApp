<?php

declare(strict_types=1);

namespace App\Domain\Personnel\Storage;

use JsonException;

final class PersonnelKeyring
{
    /** @param array<string, string> $keys Raw 32-byte keys, keyed by public version identifiers. */
    public function __construct(private readonly string $active, private readonly array $keys)
    {
        if (!function_exists('sodium_crypto_secretstream_xchacha20poly1305_init_push') || !isset($keys[$active]) || count($keys) > 64) {
            throw new PersonnelStorageException('Der private Schlüsselbestand ist nicht verfügbar.');
        }

        $seen = [];
        foreach ($keys as $id => $key) {
            if (!is_string($id) || !self::validIdentifier($id) || !is_string($key) || strlen($key) !== SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_KEYBYTES) {
                throw new PersonnelStorageException('Ungültiger privater Schlüsselbestand.');
            }
            $fingerprint = hash('sha256', $key);
            if (isset($seen[$fingerprint])) {
                throw new PersonnelStorageException('Ungültiger privater Schlüsselbestand.');
            }
            $seen[$fingerprint] = true;
        }
    }

    public static function fromFile(string $path): self
    {
        $stream = PersonnelPrivatePath::openSecret($path);
        try {
            $json = stream_get_contents($stream, 16385);
            if ($json === false || strlen($json) > 16384) {
                throw new PersonnelStorageException('Ungültiger privater Schlüsselbestand.');
            }
            $data = json_decode($json, true, 4, JSON_THROW_ON_ERROR);
            if (!is_array($data) || count($data) !== 2 || !is_string($data['active'] ?? null) || !is_array($data['keys'] ?? null)) {
                throw new PersonnelStorageException('Ungültiger privater Schlüsselbestand.');
            }
            $keys = [];
            foreach ($data['keys'] as $id => $encoded) {
                $key = is_string($encoded) ? base64_decode($encoded, true) : false;
                if ($key === false || base64_encode($key) !== $encoded) {
                    throw new PersonnelStorageException('Ungültiger privater Schlüsselbestand.');
                }
                $keys[$id] = $key;
            }

            return new self($data['active'], $keys);
        } catch (JsonException) {
            throw new PersonnelStorageException('Ungültiger privater Schlüsselbestand.');
        } finally {
            fclose($stream);
        }
    }

    public static function validIdentifier(string $id): bool
    {
        return preg_match('/\A[a-zA-Z][a-zA-Z0-9._-]{0,31}\z/', $id) === 1;
    }

    public function activeId(): string
    {
        return $this->active;
    }

    public function assertSeparateFrom(self $other): void
    {
        foreach ($this->keys as $key) {
            foreach ($other->keys as $otherKey) {
                if (hash_equals($key, $otherKey)) {
                    throw new PersonnelStorageException('Private Schlüssel müssen getrennt bereitgestellt werden.');
                }
            }
        }
    }

    public function assertSeparateFromSecrets(string ...$secrets): void
    {
        foreach ($secrets as $secret) {
            if ($secret === '') {
                continue;
            }
            foreach ($this->keys as $key) {
                // Existing app settings accept textual secrets, including copied hex or base64 keys.
                if (hash_equals($key, $secret) || hash_equals(bin2hex($key), strtolower($secret)) || hash_equals(base64_encode($key), $secret)) {
                    throw new PersonnelStorageException('Private Schlüssel müssen getrennt bereitgestellt werden.');
                }
            }
        }
    }

    public function derive(string $id, string $context): string
    {
        if (!isset($this->keys[$id])) {
            throw new PersonnelStorageException('Der erforderliche private Schlüssel ist nicht verfügbar.');
        }

        return sodium_crypto_generichash("TimeApp personnel storage v1\0" . $context, $this->keys[$id], SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_KEYBYTES);
    }
}
