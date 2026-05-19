<?php

declare(strict_types=1);

namespace App\Domain\Timesheets;

use RuntimeException;

final class TimesheetSignatureException extends RuntimeException
{
    public function __construct(string $message, private int $statusCode = 422)
    {
        parent::__construct($message);
    }

    public function statusCode(): int
    {
        return $this->statusCode;
    }
}
