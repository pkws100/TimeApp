<?php

declare(strict_types=1);

namespace App\Domain\Personnel\Storage;

final class ClamAvPersonnelScanner implements PersonnelMalwareScanner
{
    public function __construct(
        private readonly string $socketPath,
        private readonly int $timeoutSeconds = 10,
        private readonly int $maxBytes = 16777216,
    ) {
        if (preg_match('~\A/[a-zA-Z0-9./_-]{1,100}\z~', $socketPath) !== 1
            || $timeoutSeconds < 1 || $timeoutSeconds > 120 || $maxBytes < 1 || $maxBytes > 33554432) {
            throw new PersonnelStorageException('Ungültige private Scanner-Konfiguration.');
        }
    }

    public function assertReady(): void
    {
        $deadline = $this->deadline();
        $socket = $this->connect($deadline);
        try {
            $this->write($socket, "zPING\0", $deadline);
            if ($this->readReply($socket, $deadline) !== 'PONG') {
                throw self::unavailable();
            }
        } finally {
            fclose($socket);
        }
    }

    public function scan($stream): void
    {
        if (!is_resource($stream) || get_resource_type($stream) !== 'stream') {
            throw self::unavailable();
        }
        $deadline = $this->deadline();
        $socket = $this->connect($deadline);
        try {
            $this->write($socket, "zINSTREAM\0", $deadline);
            $count = 0;
            while (!feof($stream)) {
                $data = @fread($stream, min(65536, $this->maxBytes + 1 - $count));
                if ($data === false || ($data === '' && !feof($stream))) {
                    throw self::unavailable();
                }
                if ($data === '') {
                    break;
                }
                $count += strlen($data);
                if ($count > $this->maxBytes) {
                    throw new PersonnelStorageException('Die private Datei überschreitet die Scannergrenze.');
                }
                $this->write($socket, pack('N', strlen($data)) . $data, $deadline);
            }
            if ($count === 0) {
                throw self::unavailable();
            }
            $this->write($socket, pack('N', 0), $deadline);
            $reply = $this->readReply($socket, $deadline);
            if (str_starts_with($reply, 'stream: ') && str_ends_with($reply, ' FOUND')) {
                throw new PersonnelStorageException('Die private Datei wurde von der Schadsoftwareprüfung zurückgewiesen.');
            }
            if ($reply !== 'stream: OK') {
                throw self::unavailable();
            }
        } finally {
            fclose($socket);
        }
    }

    private function deadline(): int
    {
        return hrtime(true) + ($this->timeoutSeconds * 1_000_000_000);
    }

    /** @return resource */
    private function connect(int $deadline)
    {
        try {
            $before = PersonnelPrivatePath::scannerSocketIdentity($this->socketPath);
        } catch (PersonnelStorageException) {
            throw self::unavailable();
        }
        $socket = @stream_socket_client('unix://' . $this->socketPath, $error, $message, max(0.001, ($deadline - hrtime(true)) / 1_000_000_000));
        if (!is_resource($socket)) {
            throw self::unavailable();
        }
        try {
            if (PersonnelPrivatePath::scannerSocketIdentity($this->socketPath) !== $before || !stream_set_blocking($socket, false)) {
                throw self::unavailable();
            }
        } catch (PersonnelStorageException) {
            fclose($socket);
            throw self::unavailable();
        }

        return $socket;
    }

    /** @param resource $socket */
    private function write($socket, string $data, int $deadline): void
    {
        for ($offset = 0, $length = strlen($data); $offset < $length; $offset += $bytes) {
            $this->waitFor($socket, $deadline, false);
            $bytes = @fwrite($socket, $offset === 0 ? $data : substr($data, $offset));
            if ($bytes === false || $bytes === 0) {
                throw self::unavailable();
            }
        }
    }

    /** @param resource $socket */
    private function readReply($socket, int $deadline): string
    {
        $reply = '';
        while (strlen($reply) <= 512) {
            $this->waitFor($socket, $deadline, true);
            $block = @fread($socket, 513 - strlen($reply));
            if ($block === false || $block === '') {
                throw self::unavailable();
            }
            $reply .= $block;
            $end = strpos($reply, "\0");
            if ($end !== false && $end === strlen($reply) - 1) {
                // No second response or additional bytes are permitted on this one-command connection.
                $this->waitFor($socket, $deadline, true);
                if (@fread($socket, 1) !== '' || !feof($socket)) {
                    throw self::unavailable();
                }

                return substr($reply, 0, -1);
            }
            if ($end !== false || strlen($reply) > 512) {
                break;
            }
        }

        throw self::unavailable();
    }

    /** @param resource $socket */
    private function waitFor($socket, int $deadline, bool $read): void
    {
        $remainingMicros = intdiv($deadline - hrtime(true), 1000);
        if ($remainingMicros < 1) {
            throw self::unavailable();
        }
        $reading = $read ? [$socket] : [];
        $writing = $read ? [] : [$socket];
        $errors = [];
        if (@stream_select($reading, $writing, $errors, intdiv($remainingMicros, 1000000), $remainingMicros % 1000000) !== 1) {
            throw self::unavailable();
        }
    }

    private static function unavailable(): PersonnelStorageException
    {
        return new PersonnelStorageException('Die Schadsoftwareprüfung konnte nicht sicher abgeschlossen werden.');
    }
}
