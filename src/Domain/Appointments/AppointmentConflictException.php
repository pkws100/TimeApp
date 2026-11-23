<?php

declare(strict_types=1);

namespace App\Domain\Appointments;

use RuntimeException;

final class AppointmentConflictException extends RuntimeException
{
}
