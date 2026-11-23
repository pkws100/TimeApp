<?php

declare(strict_types=1);

namespace App\Domain\Appointments;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

final class AppointmentTime
{
    public const SQL_FORMAT = 'Y-m-d H:i:s';
    private const INPUT_FORMAT = 'Y-m-d\TH:i';

    private readonly DateTimeZone $utc;

    public function __construct(public readonly DateTimeZone $timezone)
    {
        $this->utc = new DateTimeZone('UTC');
    }

    public function toUtc(mixed $value, string $label): string
    {
        if (!is_string($value) || preg_match('/\A\d{4}-\d{2}-\d{2}T\d{2}:\d{2}\z/', $value) !== 1) {
            throw new InvalidArgumentException($label . ' enthält kein gültiges Datum mit Uhrzeit.');
        }

        // First parse the wall-clock reading without applying the local offset or a DST adjustment.
        $wall = DateTimeImmutable::createFromFormat('!' . self::INPUT_FORMAT, $value, $this->utc);
        if ($wall === false || $wall->format(self::INPUT_FORMAT) !== $value) {
            throw new InvalidArgumentException($label . ' enthält kein gültiges Datum mit Uhrzeit.');
        }

        $stamp = $wall->getTimestamp();
        $transitions = $this->timezone->getTransitions($stamp - 172800, $stamp + 172800);
        $offsets = $transitions === false
            ? [$this->timezone->getOffset($wall)]
            : array_unique(array_column($transitions, 'offset'));
        $matches = [];
        foreach ($offsets as $offset) {
            $candidate = $wall->setTimestamp($stamp - $offset);
            if ($candidate->setTimezone($this->timezone)->format(self::INPUT_FORMAT) === $value) {
                $matches[] = $candidate;
            }
        }

        if (count($matches) !== 1) {
            throw new InvalidArgumentException($label . ' enthält eine wegen der Zeitumstellung fehlende oder mehrdeutige Uhrzeit.');
        }

        return $this->formatUtc($matches[0]);
    }

    public function fromUtc(string $value): string
    {
        return (new DateTimeImmutable($value, $this->utc))->setTimezone($this->timezone)->format(self::INPUT_FORMAT);
    }

    public function date(mixed $value): DateTimeImmutable
    {
        if (!is_string($value) || preg_match('/\A\d{4}-\d{2}-\d{2}\z/', $value) !== 1) {
            throw new InvalidArgumentException('Bitte geben Sie einen gültigen Datumsbereich an.');
        }

        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value, $this->timezone);
        if ($date === false || $date->format('Y-m-d') !== $value) {
            throw new InvalidArgumentException('Bitte geben Sie einen gültigen Datumsbereich an.');
        }

        return $date;
    }

    public function formatUtc(DateTimeImmutable $time): string
    {
        $time = $time->setTimezone($this->utc);
        $year = (int) $time->format('Y');
        if ($year < 1000 || $year > 9999) {
            throw new InvalidArgumentException('Das Datum liegt außerhalb des unterstützten Zeitraums.');
        }

        return $time->format(self::SQL_FORMAT);
    }
}
