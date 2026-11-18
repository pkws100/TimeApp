<?php

declare(strict_types=1);

namespace App\Domain\Personnel\Backup;

use Generator;
use JsonException;

/** Reads strict, length-bounded records from authenticated stream chunks. */
final class PersonnelBackupFrames
{
    private const MAX_FRAME_BYTES = 131072;

    private string $input = '';
    private bool $started = false;

    public function __construct(private readonly Generator $chunks)
    {
    }

    public static function encode(array $record): string
    {
        $json = self::json($record);
        if (strlen($json) > self::MAX_FRAME_BYTES) {
            throw new PersonnelBackupFailure('record_too_large');
        }

        return pack('N', strlen($json)) . $json;
    }

    public static function json(array $value): string
    {
        return json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }

    public function next(): array
    {
        $size = unpack('Nsize', $this->read(4))['size'];
        if ($size < 4 || $size > self::MAX_FRAME_BYTES) {
            throw new PersonnelBackupFailure('invalid_record');
        }
        $json = $this->read($size);
        try {
            $record = json_decode($json, true, 16, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new PersonnelBackupFailure('invalid_record');
        }
        if (!is_array($record) || !array_is_list($record) || !is_string($record[0] ?? null) || self::json($record) !== $json) {
            throw new PersonnelBackupFailure('invalid_record');
        }

        return $record;
    }

    public function requireEnd(): string
    {
        if ($this->input !== '' || $this->advance() !== null) {
            throw new PersonnelBackupFailure('trailing_archive_data');
        }

        return $this->chunks->getReturn();
    }

    private function read(int $length): string
    {
        while (strlen($this->input) < $length) {
            $chunk = $this->advance();
            if ($chunk === null) {
                throw new PersonnelBackupFailure('incomplete_archive');
            }
            $this->input .= $chunk;
        }
        $result = substr($this->input, 0, $length);
        $this->input = substr($this->input, $length);

        return $result;
    }

    private function advance(): ?string
    {
        if ($this->started) {
            $this->chunks->next();
        }
        $this->started = true;

        return $this->chunks->valid() ? $this->chunks->current() : null;
    }
}
