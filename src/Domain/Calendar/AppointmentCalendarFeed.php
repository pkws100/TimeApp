<?php

declare(strict_types=1);

namespace App\Domain\Calendar;

use App\Domain\Appointments\AppointmentService;
use DateTimeImmutable;
use DateTimeZone;

final class AppointmentCalendarFeed
{
    private const PREVIEW_LIMIT = 2;

    private DateTimeZone $zone;
    private DateTimeZone $utc;

    public function __construct(private AppointmentService $appointments, string $timezone)
    {
        $this->zone = new DateTimeZone($timezone);
        $this->utc = new DateTimeZone('UTC');
    }

    public function month(array $actor, array $month): array
    {
        $first = $month['days'][0]['date'];
        $last = $month['days'][array_key_last($month['days'])]['date'];
        $gridStart = new DateTimeImmutable($first, $this->zone);
        $gridEnd = (new DateTimeImmutable($last, $this->zone))->modify('+1 day');
        $counts = [];
        $previews = [];

        foreach ($this->appointments->calendarBetween($actor, $first, $last, includeDetails: false) as $appointment) {
            $start = $this->localTime($appointment['starts_at_utc'])->setTime(0, 0);
            $end = $this->localTime($appointment['ends_at_utc']);
            for ($date = max($start, $gridStart); $date < $end && $date < $gridEnd; $date = $date->modify('+1 day')) {
                $key = $date->format('Y-m-d');
                $counts[$key] = ($counts[$key] ?? 0) + 1;
                if ($counts[$key] <= self::PREVIEW_LIMIT) {
                    $previews[$key][] = $appointment['title'];
                }
            }
        }

        foreach ($month['days'] as &$day) {
            $day['appointment_count'] = $counts[$day['date']] ?? 0;
            $day['appointment_titles'] = $previews[$day['date']] ?? [];
        }
        unset($day);

        return $month;
    }

    public function day(array $actor, string $date): array
    {
        $items = $this->appointments->calendarBetween($actor, $date, $date);
        foreach ($items as &$appointment) {
            $start = $this->localTime($appointment['starts_at_utc']);
            $end = $this->localTime($appointment['ends_at_utc']);
            $format = $start->format('Y-m-d') === $end->format('Y-m-d') ? 'H:i' : 'd.m.Y H:i';
            $appointment['time_label'] = $start->format($format) . ' – ' . $end->format($format);
        }
        unset($appointment);

        return $items;
    }

    private function localTime(string $utc): DateTimeImmutable
    {
        return (new DateTimeImmutable($utc, $this->utc))->setTimezone($this->zone);
    }
}
