<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\Appointments\AppointmentCalendarExport;
use PHPUnit\Framework\TestCase;
use UnexpectedValueException;

final class AppointmentCalendarExportTest extends TestCase
{
    private const INSTALLATION = 'https://time.example.test';

    public function testCalendarUsesPersistedUtcAcrossTheDstChangeAndExactVersion(): void
    {
        $calendar = $this->render($this->appointment());

        self::assertStringStartsWith("BEGIN:VCALENDAR\r\nVERSION:2.0\r\n", $calendar);
        self::assertStringEndsWith("END:VEVENT\r\nEND:VCALENDAR\r\n", $calendar);
        self::assertStringContainsString("\r\nDTSTAMP:20261024T162030Z\r\n", $calendar);
        self::assertStringContainsString("\r\nDTSTART:20261025T003000Z\r\nDTEND:20261025T013000Z\r\n", $calendar);
        self::assertStringContainsString("\r\nSEQUENCE:3\r\nSTATUS:CONFIRMED\r\n", $calendar);
        self::assertStringContainsString("\r\nLOCATION:Halle Süd\r\n", $calendar);
        self::assertStringNotContainsString('TZID', $calendar);
        self::assertStringNotContainsString('ATTENDEE:', $calendar);
        self::assertStringNotContainsString('ORGANIZER:', $calendar);
    }

    public function testUidRemainsTheSameAcrossVersionsAndExportOptionsWithinTheInstallation(): void
    {
        $appointment = $this->appointment();
        $first = $this->uid($this->render($appointment));
        self::assertMatchesRegularExpression('/\A[a-f0-9]{48}@timeapp\.invalid\z/', $first);
        self::assertSame($first, $this->uid(AppointmentCalendarExport::render(
            array_replace($appointment, ['title' => 'Änderung', 'version' => 4, 'status' => 'cancelled']),
            self::INSTALLATION . '/', true, true
        )));
        self::assertNotSame($first, $this->uid($this->render(array_replace($appointment, ['id' => 465]))));
        self::assertNotSame($first, $this->uid(AppointmentCalendarExport::render($appointment, 'https://other.example.test', false, false)));
        self::assertStringNotContainsString('time.example.test', $this->render($appointment));
    }

    public function testTextEscapingBlocksPropertyInjectionAndProjectIsOptIn(): void
    {
        $appointment = $this->appointment([
            'title' => "A, B; C\\D\r\nBEGIN:VEVENT",
            'description' => "Anfahrt\rATTENDEE:mailto:private@example.test\nHinweis\0\u{2028}Ende",
            'location' => "Halle\r\nURL:https://example.test",
            'project_number' => 'HT,47',
            'project_name' => 'Müller; Dach',
        ]);
        $withProject = $this->unfold($this->render($appointment, includeProject: true));
        self::assertStringContainsString("\r\nSUMMARY:A\\, B\\; C\\\\D\\nBEGIN:VEVENT\r\n", $withProject);
        self::assertStringContainsString("\r\nDESCRIPTION:Anfahrt\\nATTENDEE:mailto:private@example.test\\nHinweis\\nEnde\\n\\nProjekt: HT\\,47 – Müller\\; Dach\r\n", $withProject);
        self::assertStringContainsString("\r\nLOCATION:Halle\\nURL:https://example.test\r\n", $withProject);
        self::assertSame(1, substr_count($withProject, "\r\nBEGIN:VEVENT\r\n"));
        self::assertStringNotContainsString("\r\nATTENDEE:", $withProject);
        self::assertStringNotContainsString("\r\nURL:", $withProject);

        $withoutProject = $this->unfold($this->render($appointment));
        self::assertStringNotContainsString('HT\\,47', $withoutProject);
        self::assertStringNotContainsString('Müller', $withoutProject);
        self::assertStringContainsString('DESCRIPTION:Anfahrt', $withoutProject);
    }

    public function testFoldsByOctetsWithoutSplittingUnicodeAndKeepsOnlyCrlfLineEndings(): void
    {
        $title = str_repeat('a', 65) . '😀' . str_repeat('ö𐍈', 40);
        $description = str_repeat('🚧 Gebäude Ü Süd ', 85);
        $calendar = $this->render($this->appointment(['title' => $title, 'description' => $description]));
        self::assertStringContainsString('SUMMARY:' . str_repeat('a', 65) . "\r\n 😀", $calendar);
        self::assertDoesNotMatchRegularExpression('/(?<!\r)\n|\r(?!\n)/', $calendar);
        foreach (explode("\r\n", $calendar) as $line) {
            self::assertLessThanOrEqual(75, strlen($line));
            self::assertTrue(mb_check_encoding($line, 'UTF-8'));
        }
        $unfolded = $this->unfold($calendar);
        self::assertStringContainsString("\r\nSUMMARY:" . $title . "\r\n", $unfolded);
        self::assertStringContainsString("\r\nDESCRIPTION:" . $description . "\r\n", $unfolded);
    }

    public function testAlarmsRequireOptInAndAreSuppressedForCancelledAndCompletedAppointments(): void
    {
        $appointment = $this->appointment();
        $normal = $this->render($appointment);
        self::assertStringNotContainsString('VALARM', $normal);
        $reminders = $this->render($appointment, reminders: true);
        self::assertSame(2, substr_count($reminders, 'BEGIN:VALARM'));
        self::assertStringContainsString("\r\nACTION:DISPLAY\r\nTRIGGER:-PT24H\r\nDESCRIPTION:Prüfung\r\nEND:VALARM\r\n", $reminders);
        self::assertStringContainsString("\r\nACTION:DISPLAY\r\nTRIGGER:-PT2H\r\nDESCRIPTION:Prüfung\r\nEND:VALARM\r\n", $reminders);
        self::assertSame($this->uid($normal), $this->uid($reminders));

        $cancelled = $this->render(array_replace($appointment, ['status' => 'cancelled', 'version' => 4]), reminders: true);
        self::assertStringContainsString("\r\nSEQUENCE:4\r\nSTATUS:CANCELLED\r\n", $cancelled);
        self::assertStringNotContainsString('VALARM', $cancelled);
        $finished = $this->render(array_replace($appointment, ['status' => 'completed']), reminders: true);
        self::assertStringNotContainsString('VALARM', $finished);
    }

    public function testGoogleLinkUsesDocumentedParametersAndExcludesInternalDescriptionsAndIdentifiers(): void
    {
        $appointment = $this->appointment(['title' => 'Kälte & Wärme', 'location' => 'Halle A+B, Südeingang']);
        $url = AppointmentCalendarExport::googleUrl($appointment);
        self::assertIsString($url);
        self::assertStringStartsWith('https://calendar.google.com/calendar/r/eventedit?', $url);
        self::assertStringContainsString('stz=Europe%2FBerlin', $url);
        self::assertStringContainsString('text=K%C3%A4lte%20%26%20W%C3%A4rme', $url);
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
        self::assertSame([
            'action' => 'TEMPLATE',
            'dates' => '20261025T003000Z/20261025T013000Z',
            'stz' => 'Europe/Berlin',
            'etz' => 'Europe/Berlin',
            'details' => 'Termin aus der TimeApp',
            'location' => 'Halle A+B, Südeingang',
            'text' => 'Kälte & Wärme',
        ], $query);
        self::assertStringNotContainsString('projekt-intern', $url);
        self::assertStringNotContainsString('intern-notiz', $url);
        self::assertStringNotContainsString('team-intern', $url);
        self::assertNull(AppointmentCalendarExport::googleUrl(array_replace($appointment, ['status' => 'cancelled'])));
    }

    public function testRejectsAStoredDateThatWouldSilentlyRollOver(): void
    {
        $this->expectException(UnexpectedValueException::class);
        $this->render($this->appointment(['starts_at_utc' => '2026-09-31 09:00:00']));
    }

    private function appointment(array $overrides = []): array
    {
        return $overrides + [
            'id' => 464,
            'version' => 3,
            'status' => 'planned',
            'title' => 'Prüfung',
            'description' => 'intern-notiz',
            'location' => 'Halle Süd',
            'project_name' => 'projekt-intern',
            'project_number' => 'HT-47',
            'participants' => ['team-intern@example.test'],
            'starts_at_utc' => '2026-10-25 00:30:00',
            'ends_at_utc' => '2026-10-25 01:30:00',
            'updated_at_utc' => '2026-10-24 16:20:30',
            'timezone' => 'Europe/Berlin',
        ];
    }

    private function render(array $appointment, bool $includeProject = false, bool $reminders = false): string
    {
        return AppointmentCalendarExport::render($appointment, self::INSTALLATION, $includeProject, $reminders);
    }

    private function uid(string $calendar): string
    {
        preg_match('/\r\nUID:([^\r\n]+)\r\n/', $calendar, $match);

        return $match[1];
    }

    private function unfold(string $calendar): string
    {
        return str_replace("\r\n ", '', $calendar);
    }
}
