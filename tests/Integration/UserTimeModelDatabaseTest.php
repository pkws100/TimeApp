<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Domain\Users\UserService;
use InvalidArgumentException;
use Tests\Support\MariaDbTestCase;

final class UserTimeModelDatabaseTest extends MariaDbTestCase
{
    public function testFormattingOnlyNumericChangesRemainAllowedWithActiveMovements(): void
    {
        $adminId = $this->createUser(['employee_number' => 'ADMIN-U', 'email' => 'admin-u@example.test']);
        $userId = $this->createUser(['target_hours_month' => '160.00']);
        $this->seedActiveCutoverMovement($userId, $adminId);
        $service = new UserService($this->connection());
        $before = $service->find($userId);

        $updated = $service->update($userId, $this->payload($before ?? [], ['phone' => '+49 123', 'target_hours_month' => 160]));
        self::assertSame('+49 123', $updated['phone']);

        $this->expectException(InvalidArgumentException::class);
        $service->update($userId, $this->payload($updated, ['target_hours_month' => 150]));
    }

    public function testInactiveWeeklyTargetDoesNotBlockUnrelatedUserChanges(): void
    {
        $adminId = $this->createUser(['employee_number' => 'ADMIN-I', 'email' => 'admin-i@example.test']);
        $userId = $this->createUser([
            'target_hours_mode' => 'month',
            'target_hours_month' => '160.00',
            'target_hours_week' => null,
        ]);
        $this->seedActiveCutoverMovement($userId, $adminId);
        $service = new UserService($this->connection());
        $before = $service->find($userId);

        $updated = $service->update($userId, $this->payload($before ?? [], [
            'phone' => '+49 456',
            'target_hours_week' => '0',
        ]));

        self::assertSame('+49 456', $updated['phone']);
        self::assertSame('month', $updated['target_hours_mode']);
        self::assertSame(160.0, $updated['target_hours_month']);
    }

    private function seedActiveCutoverMovement(int $userId, int $adminId): void
    {
        $this->connection()->execute(
            'INSERT INTO employee_account_cutovers (
                user_id, active_final_user_id, effective_from, opening_time_balance_minutes, leave_year,
                annual_leave_entitlement_days, leave_carryover_days, opening_remaining_leave_days,
                status, created_by_user_id, finalized_by_user_id, finalized_at, created_at, updated_at
             ) VALUES (:user_id, :active_user_id, "2026-01-01", 0, 2026, 30, 0, 30, "final", :admin_id, :admin_id_final, NOW(), NOW(), NOW())',
            ['user_id' => $userId, 'active_user_id' => $userId, 'admin_id' => $adminId, 'admin_id_final' => $adminId]
        );
        $cutoverId = $this->connection()->lastInsertId();
        $this->connection()->execute(
            'INSERT INTO time_account_entries (user_id, cutover_id, effective_date, minutes, entry_type, description, created_at)
             VALUES (:user_id, :cutover_id, "2026-01-01", 60, "manual_adjustment", "Bewegung", NOW())',
            ['user_id' => $userId, 'cutover_id' => $cutoverId]
        );
    }

    private function payload(array $user, array $overrides): array
    {
        return $overrides + [
            'employee_number' => $user['employee_number'] ?? null,
            'first_name' => $user['first_name'] ?? 'Test',
            'last_name' => $user['last_name'] ?? 'Mitarbeiter',
            'email' => $user['email'] ?? 'user@example.test',
            'phone' => $user['phone'] ?? null,
            'employment_status' => $user['employment_status'] ?? 'active',
            'target_hours_month' => $user['target_hours_month'] ?? 160,
            'target_hours_mode' => $user['target_hours_mode'] ?? 'month',
            'target_hours_week' => $user['target_hours_week'] ?? null,
            'workdays_mask' => $user['workdays_mask'] ?? '1,2,3,4,5',
            'vacation_days_year' => $user['vacation_days_year'] ?? 30,
            'vacation_carryover_days' => $user['vacation_carryover_days'] ?? 0,
            'time_tracking_required' => 1,
            'role_ids' => [],
        ];
    }
}
