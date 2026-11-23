<?php

declare(strict_types=1);

namespace App\Domain\Appointments;

use RuntimeException;

final class AppointmentAccessDeniedException extends RuntimeException
{
}
