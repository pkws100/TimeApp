<?php

declare(strict_types=1);

namespace App\Domain\Appointments;

use DateTimeImmutable;
use DateTimeZone;
use UnexpectedValueException;

final class AppointmentCalendarExport
{
    public static function render(array $appointment, string $installationUrl, bool $includeProject, bool $reminders): string
    {
        [$start, $end] = self::interval($appointment);
        $id = self::positiveInteger($appointment['id'] ?? null);
        $version = self::positiveInteger($appointment['version'] ?? null, 2147483647);
        $installation = rtrim(trim($installationUrl), '/');
        $uid = substr(hash('sha256', 'timeapp-appointment' . "\0" . $installation . "\0" . $id), 0, 48) . '@timeapp.invalid';
        $status = $appointment['status'] ?? '';
        $updated = $appointment['updated_at_utc'] ?? null;
        $summary = self::cleanText($appointment['title'] ?? null);
        $description = self::cleanText($appointment['description'] ?? null);
        $location = self::cleanText($appointment['location'] ?? null);
        if ($includeProject) {
            $number = self::cleanText($appointment['project_number'] ?? null);
            $name = self::cleanText($appointment['project_name'] ?? null);
            $project = implode(' – ', array_filter([$number, $name], static fn (string $part): bool => $part !== ''));
            if ($project !== '') {
                $description .= ($description === '' ? '' : "\n\n") . 'Projekt: ' . $project;
            }
        }

        $lines = [
            'BEGIN:VCALENDAR',
            'VERSION:2.0',
            'PRODID:-//TimeApp//Appointment Calendar Export//DE',
            'CALSCALE:GREGORIAN',
            'BEGIN:VEVENT',
            'UID:' . $uid,
            'DTSTAMP:' . ($updated === null ? gmdate('Ymd\THis\Z') : self::utc($updated)),
            'DTSTART:' . $start,
            'DTEND:' . $end,
            'SEQUENCE:' . $version,
            'STATUS:' . ($status === 'cancelled' ? 'CANCELLED' : 'CONFIRMED'),
            'SUMMARY:' . self::escape($summary),
        ];
        if ($description !== '') {
            $lines[] = 'DESCRIPTION:' . self::escape($description);
        }
        if ($location !== '') {
            $lines[] = 'LOCATION:' . self::escape($location);
        }
        if ($reminders && $status === 'planned') {
            foreach (['-PT24H', '-PT2H'] as $trigger) {
                array_push($lines, 'BEGIN:VALARM', 'ACTION:DISPLAY', 'TRIGGER:' . $trigger,
                    'DESCRIPTION:' . self::escape($summary), 'END:VALARM');
            }
        }

        return implode("\r\n", array_map(self::fold(...), [...$lines, 'END:VEVENT', 'END:VCALENDAR'])) . "\r\n";
    }

    public static function googleUrl(array $appointment): ?string
    {
        if (($appointment['status'] ?? '') === 'cancelled') {
            return null;
        }

        [$start, $end] = self::interval($appointment);
        $timezone = (new DateTimeZone($appointment['timezone']))->getName();
        $query = [
            'action' => 'TEMPLATE',
            'dates' => $start . '/' . $end,
            'stz' => $timezone,
            'etz' => $timezone,
            'details' => 'Termin aus der TimeApp',
            'location' => self::cleanText($appointment['location'] ?? null),
            'text' => self::cleanText($appointment['title'] ?? null),
        ];

        return 'https://calendar.google.com/calendar/r/eventedit?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
    }

    private static function interval(array $appointment): array
    {
        $start = self::utc($appointment['starts_at_utc'] ?? null);
        $end = self::utc($appointment['ends_at_utc'] ?? null);
        if ($end <= $start) {
            throw new UnexpectedValueException('Das Terminende muss nach dem Beginn liegen.');
        }

        return [$start, $end];
    }

    private static function utc(mixed $value): string
    {
        $format = AppointmentTime::SQL_FORMAT;
        $date = is_string($value) && !str_contains($value, "\0")
            ? DateTimeImmutable::createFromFormat('!' . $format, $value, new DateTimeZone('UTC')) : false;
        if ($date === false || $date->format($format) !== $value || (int) $date->format('Y') < 1000) {
            throw new UnexpectedValueException('Die UTC-Terminzeit ist ungültig.');
        }

        return $date->format('Ymd\THis\Z');
    }

    private static function positiveInteger(mixed $value, int $max = 4294967295): int
    {
        $integer = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => $max]]);
        if ($integer === false || (!is_int($value) && !is_string($value))) {
            throw new UnexpectedValueException('Die Terminkennung oder Terminversion ist ungültig.');
        }

        return $integer;
    }

    private static function cleanText(?string $value): string
    {
        $value ??= '';
        if (!mb_check_encoding($value, 'UTF-8')) {
            throw new UnexpectedValueException('Der Termintext enthält ungültige Unicode-Daten.');
        }

        $value = preg_replace('/\R/u', "\n", $value);

        return (string) preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $value);
    }

    private static function escape(string $text): string
    {
        return strtr($text, ['\\' => '\\\\', ';' => '\\;', ',' => '\\,', "\n" => '\\n']);
    }

    private static function fold(string $line): string
    {
        $segments = [];
        $limit = 75;
        while (strlen($line) > $limit) {
            $segment = mb_strcut($line, 0, $limit, 'UTF-8');
            $segments[] = $segment;
            $line = substr($line, strlen($segment));
            $limit = 74;
        }

        return implode("\r\n ", [...$segments, $line]);
    }
}
