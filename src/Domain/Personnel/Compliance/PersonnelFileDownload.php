<?php

declare(strict_types=1);

namespace App\Domain\Personnel\Compliance;

final class PersonnelFileDownload
{
    /** @param resource $stream */
    public function __construct(private $stream, public readonly string $mimeType, public readonly int $sizeBytes)
    {
    }

    /** @return resource */
    public function stream()
    {
        if (!is_resource($this->stream)) {
            throw new PersonnelFileUnavailable();
        }

        return $this->stream;
    }

    public function close(): void
    {
        if (is_resource($this->stream)) {
            fclose($this->stream);
        }
    }

    public function __destruct()
    {
        $this->close();
    }
}
