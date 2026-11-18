<?php

declare(strict_types=1);

namespace App\Domain\Personnel\Storage;

final class PersonnelStagedFile
{
    /** @param resource $lease */
    public function __construct(
        private readonly string $path,
        private readonly int $size,
        private readonly string $sha256,
        private $lease,
    ) {
    }

    public function size(): int
    {
        return $this->size;
    }

    public function sha256(): string
    {
        return $this->sha256;
    }

    public function path(): string
    {
        return $this->path;
    }

    /** @return resource */
    public function open()
    {
        if (!is_resource($this->lease)) {
            throw new PersonnelStorageException('Die private Quarantänedatei ist nicht verfügbar.');
        }

        return PersonnelPrivatePath::openPrivateFile($this->path);
    }

    public function discard(): void
    {
        if (!is_resource($this->lease)) {
            return;
        }
        try {
            if (!@unlink($this->path)) {
                throw new PersonnelStorageException('Die private Quarantänedatei konnte nicht entfernt werden.');
            }
            PersonnelPrivatePath::syncDirectory(dirname($this->path));
        } finally {
            fclose($this->lease);
            $this->lease = null;
        }
    }

    public function __destruct()
    {
        try {
            $this->discard();
        } catch (PersonnelStorageException) {
            // The opaque staging name remains eligible for the explicit recovery command.
        }
    }
}
