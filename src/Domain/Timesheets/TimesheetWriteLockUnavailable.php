<?php

declare(strict_types=1);

namespace App\Domain\Timesheets;

use InvalidArgumentException;

final class TimesheetWriteLockUnavailable extends InvalidArgumentException
{
}
