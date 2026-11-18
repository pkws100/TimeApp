<?php

declare(strict_types=1);

namespace App\Domain\WorkTime;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

final class WorkTimeLocalTime
{
    /** Normalize explicit API input before PHP can repair an invalid wall clock. */
    public static function fromInput(string $value): DateTimeImmutable
    {
        $value = trim($value);
        if (!preg_match('/^(\d{4}-\d{2}-\d{2})[T ]((?:[01]\d|2[0-3]):[0-5]\d)(?::([0-5]\d))?(?:\.(\d{1,6}))?(Z|[+-](?:[01]\d|2[0-3]):[0-5]\d)?$/D', $value, $parts)) {
            throw new InvalidArgumentException('Bitte einen gueltigen vollstaendigen Pausenzeitpunkt angeben.');
        }
        $seconds = $parts[3] ?? '';
        $fraction = $parts[4] ?? '';
        $offset = $parts[5] ?? '';
        if ($fraction !== '' && ($seconds === '' || $offset === '')) {
            throw new InvalidArgumentException('Sekundenbruchteile benoetigen einen ISO-Zeitpunkt mit UTC-Offset.');
        }
        $wall = $parts[1] . ' ' . $parts[2] . ':' . ($seconds === '' ? '00' : $seconds);
        $calendar = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $wall, new DateTimeZone('UTC'));
        if ((int) substr($parts[1], 0, 4) < 1000 || $calendar === false || $calendar->format('Y-m-d H:i:s') !== $wall) {
            throw new InvalidArgumentException('Das Pausendatum ist ungueltig.');
        }
        if ($offset === '') {
            return self::parse($wall);
        }
        $instant = new DateTimeImmutable(str_replace(' ', 'T', $wall) . ($fraction === '' ? '' : '.' . $fraction) . $offset);
        return $instant->setTimezone(new DateTimeZone('Europe/Berlin'));
    }

    /** Keep the historical wall-clock representation whenever it identifies one instant. */
    public static function formatForStorage(DateTimeImmutable $instant): string
    {
        $instant = $instant->setTimezone(new DateTimeZone('Europe/Berlin'));
        $local = $instant->format('Y-m-d H:i:s');
        return count(self::matchingInstants($local)) === 1 ? $local : $instant->format(DATE_ATOM);
    }

    public static function parse(string $local): DateTimeImmutable
    {
        $matches = self::matchingInstants($local);
        if (count($matches) !== 1) {
            throw new InvalidArgumentException('Dieser lokale Zeitpunkt ist wegen der Zeitumstellung unklar. Bitte den Arbeitszeitnachweis im Admin pruefen.');
        }
        return reset($matches);
    }

    private static function matchingInstants(string $local): array
    {
        $zone = new DateTimeZone('Europe/Berlin');
        $naive = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $local, new DateTimeZone('UTC'));
        if ($naive === false || $naive->format('Y-m-d H:i:s') !== $local) {
            throw new InvalidArgumentException('Der Zeitpunkt ist ungueltig.');
        }
        $matches = [];
        foreach ($zone->getTransitions($naive->getTimestamp() - 172800, $naive->getTimestamp() + 172800) ?: [] as $transition) {
            $candidate = (new DateTimeImmutable('@' . ($naive->getTimestamp() - (int) $transition['offset'])))->setTimezone($zone);
            if ($candidate->format('Y-m-d H:i:s') === $local) {
                $matches[$candidate->getTimestamp()] = $candidate;
            }
        }
        return $matches;
    }
}
