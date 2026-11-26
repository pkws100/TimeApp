<?php

declare(strict_types=1);

namespace App\Domain\Timesheets;

use RuntimeException;

final class WorkSessionConflict extends RuntimeException
{
    public const ACTIVE_MESSAGE = 'Es läuft bereits ein Arbeitseinsatz. Beenden Sie ihn, bevor Sie einen neuen Einsatz starten.';

    public function __construct(
        public readonly string $reason,
        string $message,
        public readonly ?array $visibleProject = null
    ) {
        parent::__construct($message);
    }
}
