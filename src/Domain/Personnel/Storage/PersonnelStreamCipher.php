<?php

declare(strict_types=1);

namespace App\Domain\Personnel\Storage;

use Generator;

final class PersonnelStreamCipher
{
    private const MAGIC = "TAPPPS1\n";
    public const CHUNK_BYTES = 65536;

    public function __construct(private readonly PersonnelKeyring $keys)
    {
    }

    /** @param resource $input @param resource $output */
    public function encrypt($input, $output, string $context): string
    {
        return $this->encryptChunks($this->readChunks($input), $output, $context);
    }

    /** @param iterable<string> $chunks @param resource $output */
    public function encryptChunks(iterable $chunks, $output, string $context): string
    {
        $this->requireContext($context);
        $id = $this->keys->activeId();
        $prefix = self::MAGIC . chr(strlen($id)) . $id;
        $key = $this->keys->derive($id, $context);
        try {
            [$state, $header] = sodium_crypto_secretstream_xchacha20poly1305_init_push($key);
        } finally {
            sodium_memzero($key);
        }
        $aad = $prefix . "\0" . $context;
        try {
            self::writeAll($output, $prefix . $header);
            foreach ($chunks as $chunk) {
                for ($offset = 0, $length = strlen($chunk); $offset < $length; $offset += self::CHUNK_BYTES) {
                    $frame = sodium_crypto_secretstream_xchacha20poly1305_push(
                        $state,
                        substr($chunk, $offset, self::CHUNK_BYTES),
                        $aad,
                        SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_MESSAGE,
                    );
                    self::writeAll($output, pack('N', strlen($frame)) . $frame);
                }
            }
            $final = sodium_crypto_secretstream_xchacha20poly1305_push($state, '', $aad, SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_FINAL);
            self::writeAll($output, pack('N', strlen($final)) . $final);

            return $id;
        } finally {
            sodium_memzero($state);
        }
    }

    /** Output must remain private until the final authentication and consistency checks succeed. @param resource $input @param resource $output */
    public function decrypt($input, $output, string $context, ?string $expectedKeyId = null, ?int $maxPlainBytes = null): string
    {
        $chunks = $this->decryptedChunks($input, $context, $expectedKeyId, $maxPlainBytes);
        foreach ($chunks as $chunk) {
            self::writeAll($output, $chunk);
        }

        return $chunks->getReturn();
    }

    /** The consumer must exhaust the generator before trusting a complete authenticated stream. @param resource $input @return Generator<int, string, void, string> */
    public function decryptedChunks($input, string $context, ?string $expectedKeyId = null, ?int $maxPlainBytes = null): Generator
    {
        $this->requireContext($context);
        if ($maxPlainBytes !== null && $maxPlainBytes < 0) {
            throw new PersonnelStorageException('Ungültige private Größenbegrenzung.');
        }
        $prefix = $this->readExact($input, strlen(self::MAGIC) + 1);
        $idLength = ord($prefix[strlen(self::MAGIC)]);
        if (!str_starts_with($prefix, self::MAGIC) || $idLength < 1 || $idLength > 32) {
            throw self::invalidStream();
        }
        $id = $this->readExact($input, $idLength);
        if (!PersonnelKeyring::validIdentifier($id) || ($expectedKeyId !== null && !hash_equals($expectedKeyId, $id))) {
            throw self::invalidStream();
        }
        $header = $this->readExact($input, SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_HEADERBYTES);
        $key = $this->keys->derive($id, $context);
        try {
            $state = sodium_crypto_secretstream_xchacha20poly1305_init_pull($header, $key);
        } finally {
            sodium_memzero($key);
        }
        $aad = $prefix . $id . "\0" . $context;
        $overhead = SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_ABYTES;
        $read = 0;
        try {
            while (true) {
                $encodedLength = unpack('Nlength', $this->readExact($input, 4))['length'];
                if ($encodedLength < $overhead || $encodedLength > self::CHUNK_BYTES + $overhead) {
                    throw self::invalidStream();
                }
                $result = sodium_crypto_secretstream_xchacha20poly1305_pull($state, $this->readExact($input, $encodedLength), $aad);
                if ($result === false) {
                    throw self::invalidStream();
                }
                [$plaintext, $tag] = $result;
                if ($tag === SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_FINAL) {
                    if ($plaintext !== '' || @fread($input, 1) !== '' || !feof($input)) {
                        throw self::invalidStream();
                    }

                    return $id;
                }
                if ($tag !== SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_MESSAGE || $plaintext === '') {
                    throw self::invalidStream();
                }
                $read += strlen($plaintext);
                if ($maxPlainBytes !== null && $read > $maxPlainBytes) {
                    throw self::invalidStream();
                }
                yield $plaintext;
            }
        } finally {
            sodium_memzero($state);
        }
    }

    /** @param resource $output */
    public static function writeAll($output, string $data): void
    {
        for ($offset = 0, $length = strlen($data); $offset < $length; $offset += $written) {
            $written = @fwrite($output, $offset === 0 ? $data : substr($data, $offset));
            if ($written === false || $written === 0) {
                throw new PersonnelStorageException('Private Daten konnten nicht vollständig geschrieben werden.');
            }
        }
    }

    /** @param resource $input @return Generator<int, string> */
    private function readChunks($input): Generator
    {
        while (!feof($input)) {
            $data = @fread($input, self::CHUNK_BYTES);
            if ($data === false || ($data === '' && !feof($input))) {
                throw new PersonnelStorageException('Private Daten konnten nicht vollständig gelesen werden.');
            }
            if ($data !== '') {
                yield $data;
            }
        }
    }

    /** @param resource $input */
    private function readExact($input, int $length): string
    {
        $data = '';
        while (strlen($data) < $length) {
            $chunk = @fread($input, $length - strlen($data));
            if ($chunk === false || $chunk === '') {
                throw self::invalidStream();
            }
            $data .= $chunk;
        }

        return $data;
    }

    private function requireContext(string $context): void
    {
        if (preg_match('/\A[a-zA-Z0-9][a-zA-Z0-9._:-]{0,191}\z/', $context) !== 1) {
            throw new PersonnelStorageException('Ungültiger privater Verschlüsselungskontext.');
        }
    }

    private static function invalidStream(): PersonnelStorageException
    {
        return new PersonnelStorageException('Die verschlüsselten privaten Daten sind unvollständig oder ungültig.');
    }
}
