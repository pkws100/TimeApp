<?php

declare(strict_types=1);

namespace App\Domain\WorkTime;

use App\Domain\Timesheets\TimesheetWriteGuard;
use App\Infrastructure\Database\DatabaseConnection;
use InvalidArgumentException;

final class WorkTimeDeviationService
{
    public function __construct(private DatabaseConnection $connection, private WorkTimePolicyService $policies)
    {
    }

    public function listForUser(int $userId): array
    {
        if (!$this->policies->enabled()) {
            return [];
        }

        $rows = $this->connection->fetchAll('SELECT d.*, t.work_date FROM work_time_deviations d JOIN timesheets t ON t.id = d.timesheet_id WHERE d.user_id = :user_id ORDER BY d.segment_start_at DESC, d.id DESC LIMIT 500', ['user_id' => $userId]);
        foreach ($rows as &$row) {
            $row['decisions'] = $this->connection->fetchAll('SELECT d.*, u.first_name, u.last_name FROM work_time_deviation_decisions d JOIN users u ON u.id = d.actor_user_id WHERE d.deviation_id = :id ORDER BY d.id', ['id' => $row['id']]);
        }
        unset($row);

        return $rows;
    }

    /** Authorization and employee scope must also be checked by the calling controller. */
    public function decide(int $deviationId, string $newStatus, int $actorId, string $reason, string $requestId): array
    {
        if (!$this->policies->enabled()) {
            throw new InvalidArgumentException('Arbeitszeitfenster sind deaktiviert.');
        }
        $reason = trim($reason);
        if (!in_array($newStatus, ['pending', 'approved', 'rejected', 'disputed'], true) || mb_strlen($reason) < 3 || mb_strlen($reason) > 4000 || $requestId === '' || strlen($requestId) > 80) {
            throw new InvalidArgumentException('Status, eine Begruendung und eine eindeutige Request-ID sind erforderlich.');
        }
        $guard = new TimesheetWriteGuard($this->connection);

        return $guard->withAccountingWriteLock(function () use ($deviationId, $newStatus, $actorId, $reason, $requestId, $guard): array {
            return $this->connection->transaction(function () use ($deviationId, $newStatus, $actorId, $reason, $requestId, $guard): array {
                $old = $this->connection->fetchOne('SELECT * FROM work_time_deviation_decisions WHERE actor_user_id = :actor_id AND request_id = :request_id', ['actor_id' => $actorId, 'request_id' => $requestId]);
                if ($old !== null) {
                    if ((int) $old['deviation_id'] !== $deviationId || $old['new_status'] !== $newStatus || $old['reason'] !== $reason) {
                        throw new InvalidArgumentException('Die Request-ID wurde bereits fuer eine andere Entscheidung verwendet.');
                    }
                    return $old;
                }
                $row = $this->connection->fetchOne('SELECT d.*, t.work_date, t.project_id, t.is_deleted FROM work_time_deviations d JOIN timesheets t ON t.id = d.timesheet_id WHERE d.id = :id FOR UPDATE', ['id' => $deviationId]);
                if ($row === null || $row['superseded_at'] !== null || (int) $row['is_deleted'] === 1) {
                    throw new InvalidArgumentException('Diese Abweichung ist nicht mehr aktuell. Bitte neu pruefen.');
                }
                $source = $this->connection->fetchOne('SELECT *, row_start AS work_time_source_revision FROM timesheets WHERE id = :id', ['id' => $row['timesheet_id']]);
                $current = $source === null ? null : (new WorkTimePreviewService($this->connection, $this->policies))->forTimesheet($source);
                if ($current === null || $current['calculation_hash'] !== $row['calculation_hash']) {
                    throw new InvalidArgumentException('Die Arbeitsbuchung wurde seit der Abweichungspruefung geaendert. Bitte neu pruefen.');
                }
                $guard->assertAccountingPeriodOpen((int) $row['user_id'], isset($row['project_id']) ? (int) $row['project_id'] : null, $row['work_date']);
                if ($row['status'] === $newStatus) {
                    throw new InvalidArgumentException('Der gewaehlte Status ist bereits gesetzt.');
                }
                $this->connection->execute('INSERT INTO work_time_deviation_decisions (deviation_id, actor_user_id, previous_status, new_status, reason, request_id, created_at) VALUES (:id, :actor, :previous, :new_status, :reason, :request_id, NOW())', ['id' => $deviationId, 'actor' => $actorId, 'previous' => $row['status'], 'new_status' => $newStatus, 'reason' => $reason, 'request_id' => $requestId]);
                $id = $this->connection->lastInsertId();
                $this->connection->execute('UPDATE work_time_deviations SET status = :status WHERE id = :id', ['status' => $newStatus, 'id' => $deviationId]);

                return $this->connection->fetchOne('SELECT * FROM work_time_deviation_decisions WHERE id = :id', ['id' => $id]) ?? [];
            });
        });
    }
}
