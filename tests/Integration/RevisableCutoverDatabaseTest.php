<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Domain\TimeAccounts\AccountJournalService;
use App\Domain\TimeAccounts\EmployeeAccountCutoverService;
use App\Domain\TimeAccounts\VacationAccountYearService;
use App\Domain\Timesheets\TimesheetWriteGuard;
use InvalidArgumentException;
use Tests\Support\MariaDbTestCase;

final class RevisableCutoverDatabaseTest extends MariaDbTestCase
{
    public function testZeroOpeningValuesCanBeFinalizedAndReversed(): void
    {
        $adminId = $this->createUser(['employee_number' => 'ADMIN-1', 'email' => 'admin1@example.test']);
        $userId = $this->createUser();
        $service = $this->service();
        $cutover = $service->finalize($this->payload($userId), $adminId);

        $reversed = $service->reverse((int) $cutover['id'], $adminId, 'Testrevidierung');

        self::assertSame('reversed', $reversed['status']);
        self::assertSame(0, (int) $this->connection()->fetchColumn('SELECT COUNT(*) FROM time_account_entries WHERE cutover_id = :id', ['id' => $cutover['id']]));
        self::assertSame(2, (int) $this->connection()->fetchColumn('SELECT COUNT(*) FROM vacation_account_entries WHERE cutover_id = :id', ['id' => $cutover['id']]));
    }

    public function testAlreadyReversedAdjustmentDoesNotBlockCutoverReversal(): void
    {
        $adminId = $this->createUser(['employee_number' => 'ADMIN-2', 'email' => 'admin2@example.test']);
        $userId = $this->createUser();
        $service = $this->service();
        $cutover = $service->finalize($this->payload($userId, '+10:00'), $adminId);
        $entryId = $service->addManualTimeAdjustment($userId, '2026-01-15', 120, 'Korrektur', $adminId);
        $service->reverseTimeEntry($entryId, $adminId, 'Einzeln ausgeglichen');

        $reversed = $service->reverse((int) $cutover['id'], $adminId, 'Stichtag neu aufsetzen');
        self::assertSame('reversed', $reversed['status']);
        self::assertSame(1, (int) $this->connection()->fetchColumn('SELECT COUNT(*) FROM time_account_entries WHERE reversal_of_id = :id', ['id' => $entryId]));

        $new = $service->finalize($this->payload($userId, '+15:00'), $adminId);
        self::assertSame(900, (int) $new['opening_time_balance_minutes']);

        $this->expectException(InvalidArgumentException::class);
        $service->reverse((int) $cutover['id'], $adminId, 'Doppelt');
    }

    public function testConcurrentFinalizationCreatesOnlyOneActiveCutover(): void
    {
        $adminId = $this->createUser(['employee_number' => 'ADMIN-C', 'email' => 'admin-c@example.test']);
        $userId = $this->createUser();
        $configFile = sys_get_temp_dir() . '/timeapp-cutover-config-' . bin2hex(random_bytes(5)) . '.json';
        $barrier = sys_get_temp_dir() . '/timeapp-cutover-barrier-' . bin2hex(random_bytes(5));
        file_put_contents($configFile, json_encode($this->connectionConfig(), JSON_THROW_ON_ERROR));
        chmod($configFile, 0600);
        $payload = base64_encode(json_encode($this->payload($userId, '+01:00'), JSON_THROW_ON_ERROR));
        $command = [PHP_BINARY, base_path('tests/Support/finalize-cutover-worker.php'), $configFile, $payload, (string) $adminId, $barrier];
        $workers = [];
        $results = [];

        try {
            for ($index = 0; $index < 2; $index++) {
                $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, base_path());
                self::assertIsResource($process);
                $workers[] = [$process, $pipes];
            }
            touch($barrier);
            foreach ($workers as [$process, $pipes]) {
                $stdout = stream_get_contents($pipes[1]);
                $stderr = stream_get_contents($pipes[2]);
                fclose($pipes[1]);
                fclose($pipes[2]);
                self::assertSame(0, proc_close($process), $stderr);
                $results[] = json_decode($stdout, true, 512, JSON_THROW_ON_ERROR);
            }
        } finally {
            if (!is_file($barrier)) {
                touch($barrier);
            }

            foreach ($workers as [$process, $pipes]) {
                foreach ($pipes as $pipe) {
                    if (is_resource($pipe)) {
                        fclose($pipe);
                    }
                }

                if (is_resource($process)) {
                    proc_terminate($process);
                    proc_close($process);
                }
            }

            @unlink($barrier);
            @unlink($configFile);
        }

        self::assertSame(1, count(array_filter($results, static fn (array $result): bool => $result['ok'] === true)));
        self::assertSame(1, (int) $this->connection()->fetchColumn('SELECT COUNT(*) FROM employee_account_cutovers WHERE status = "final" AND active_final_user_id = :user_id', ['user_id' => $userId]));
        self::assertSame(1, (int) $this->connection()->fetchColumn('SELECT COUNT(*) FROM time_account_entries WHERE entry_type = "opening_balance"'));
        self::assertSame(1, (int) $this->connection()->fetchColumn('SELECT COUNT(*) FROM accounting_closures WHERE source_type = "employee_account_cutover" AND status = "final"'));
    }

    private function service(): EmployeeAccountCutoverService
    {
        $journal = new AccountJournalService($this->connection());
        $service = new EmployeeAccountCutoverService($this->connection(), $journal, new TimesheetWriteGuard($this->connection()));
        $service->setVacationYearService(new VacationAccountYearService($this->connection(), $journal, $service));

        return $service;
    }

    private function payload(int $userId, string $opening = '0:00'): array
    {
        return [
            'user_id' => $userId,
            'effective_from' => '2026-01-01',
            'opening_time_balance' => $opening,
            'leave_year' => 2026,
            'annual_leave_entitlement_days' => 30,
            'leave_carryover_days' => 0,
            'opening_remaining_leave_days' => 30,
        ];
    }
}
