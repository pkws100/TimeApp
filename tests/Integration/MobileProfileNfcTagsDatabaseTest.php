<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Domain\App\MobileAppService;
use App\Domain\Files\FileAttachmentService;
use App\Domain\Projects\ProjectService;
use App\Domain\Settings\CompanySettingsService;
use App\Domain\Terminals\NfcTagService;
use App\Domain\Timesheets\WorkdayStateCalculator;
use Tests\Support\MariaDbTestCase;

final class MobileProfileNfcTagsDatabaseTest extends MariaDbTestCase
{
    public function testDayContextExposesOnlyCurrentTagsOfTheAuthenticatedUserWithMinimalFields(): void
    {
        $ownUserId = $this->createUser();
        $otherUserId = $this->createUser();
        $this->createTag($ownUserId, '04:AA:...:01', 'Aktiver Tag', 'active', false);
        $this->createTag($ownUserId, '04:AA:...:02', 'Archivierter Tag', 'disabled', true);
        $this->createTag($otherUserId, '04:AA:...:03', 'Fremder Tag', 'pending', false);

        $service = new MobileAppService(
            $this->connection(),
            new ProjectService($this->connection()),
            new CompanySettingsService($this->connection(), []),
            new WorkdayStateCalculator(),
            new FileAttachmentService($this->connection(), []),
            null,
            null,
            null,
            null,
            null,
            new NfcTagService($this->connection(), 'test-key')
        );

        $payload = $service->dayContext([
            'id' => $ownUserId,
            'first_name' => 'Eigener',
            'last_name' => 'Mitarbeiter',
            'email' => 'own@example.test',
            'time_tracking_required' => 1,
            'roles' => [],
        ]);

        self::assertSame([
            [
                'uid_masked' => '04:AA:...:01',
                'label' => 'Aktiver Tag',
                'status' => 'active',
            ],
        ], $payload['nfc_tags']);
    }

    private function createTag(int $userId, string $uidMasked, string $label, string $status, bool $archived): void
    {
        $this->connection()->execute(
            'INSERT INTO nfc_tags (uid_hash, uid_masked, label, user_id, status, is_deleted, created_at, updated_at)
             VALUES (:uid_hash, :uid_masked, :label, :user_id, :status, :is_deleted, NOW(), NOW())',
            [
                'uid_hash' => hash('sha256', $uidMasked . random_bytes(8)),
                'uid_masked' => $uidMasked,
                'label' => $label,
                'user_id' => $userId,
                'status' => $status,
                'is_deleted' => $archived ? 1 : 0,
            ]
        );
    }
}
