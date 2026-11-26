<?php

declare(strict_types=1);

namespace App\Domain\Projects;

use RuntimeException;

final class ProjectAccessDeniedException extends RuntimeException
{
    public const WRITE_DENIED = 'project_write_denied';

    public function __construct(string $message, public readonly ?string $reason = null)
    {
        parent::__construct($message);
    }
}
