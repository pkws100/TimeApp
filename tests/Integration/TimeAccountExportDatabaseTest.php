<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Domain\Calendar\CalendarPolicyService;
use App\Domain\TimeAccounts\TimeAccountService;
use App\Domain\WorkTime\WorkTimePolicyService;
use DateTimeImmutable;
use DateTimeZone;
use Tests\Support\MariaDbTestCase;

final class TimeAccountExportDatabaseTest extends MariaDbTestCase
{
    public function testConfiguredSchedulesRemainVisibleWithoutBookingsAndWhileFeatureDisabled(): void
    {
        $actor = $this->createUser();
        $employee = $this->createUser();
        $start = (new DateTimeImmutable('first day of next month', new DateTimeZone('Europe/Berlin')))->setTime(0, 0);
        $change = $start->modify('+10 days');
        $policies = new WorkTimePolicyService($this->connection(), true);
        $template = $policies->createTemplate(['name' => 'Abrechnung Kernzeit', 'policy' => [
            'windows' => [1 => [['start' => '08:00', 'end' => '16:00']]], 'checkout_mode' => 'automatic',
        ]], $actor);
        $policies->assign(['user_id' => $employee, 'policy_version_id' => $template['version']['id'], 'effective_from' => $start->format('Y-m-d')], $actor);
        $revision = $policies->addVersion($template['id'], ['windows' => [1 => [['start' => '22:00', 'end' => '06:00']]], 'checkout_mode' => 'manual'], $actor);
        $policies->assign(['user_id' => $employee, 'policy_version_id' => $revision['id'], 'effective_from' => $change->format('Y-m-d')], $actor);
        $service = new TimeAccountService($this->connection(), new CalendarPolicyService($this->connection()));
        $service->setWorkTimePolicyService($policies);

        $rows = $service->adminExportRows((int) $start->format('Y'), (int) $start->format('n'), ['user_id' => $employee]);

        self::assertCount(1, $rows);
        self::assertSame('Aktiv', $rows[0]['Kernarbeitszeit Status']);
        self::assertSame($start->format('Y-m-d') . ' bis ' . $start->modify('last day of this month')->format('Y-m-d'), $rows[0]['Kernarbeitszeit Zeitraum']);
        $schedule = $rows[0]['Kernarbeitszeit Konfiguration'];
        self::assertStringContainsString($start->format('Y-m-d') . ' bis ' . $change->modify('-1 day')->format('Y-m-d'), $schedule);
        self::assertStringContainsString('Abrechnung Kernzeit v1', $schedule);
        self::assertStringContainsString('Mo 08:00–16:00', $schedule);
        self::assertStringContainsString('Checkout Automatisch', $schedule);
        self::assertStringContainsString('Abrechnung Kernzeit v2', $schedule);
        self::assertStringContainsString('Mo 22:00–06:00 (+1 Tag)', $schedule);
        self::assertStringContainsString('Checkout Manuell', $schedule);
        self::assertArrayNotHasKey('Fenster Anrechnung Vorschau (Min)', $rows[0]);

        $service->setWorkTimePolicyService(new WorkTimePolicyService($this->connection(), false));
        $disabled = $service->adminExportRows((int) $start->format('Y'), (int) $start->format('n'), ['user_id' => $employee]);
        self::assertSame('Global deaktiviert', $disabled[0]['Kernarbeitszeit Status']);
        self::assertSame($schedule, $disabled[0]['Kernarbeitszeit Konfiguration']);
        self::assertSame(0, (int) $this->connection()->fetchColumn('SELECT COUNT(*) FROM timesheets'));
        self::assertSame(0, (int) $this->connection()->fetchColumn('SELECT COUNT(*) FROM work_time_deviations'));
    }
}
