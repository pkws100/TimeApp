<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Domain\Terminals\NfcTagService;
use RuntimeException;
use Tests\Support\MariaDbTestCase;

final class NfcTagAssignmentDatabaseTest extends MariaDbTestCase
{
    public function testAssignsMultipleFreePendingTagsWithoutActivatingThem(): void
    {
        $userId = $this->createUser();
        $firstTagId = $this->createPendingTag();
        $secondTagId = $this->createPendingTag();
        $service = new NfcTagService($this->connection(), 'test-key');

        self::assertSame(2, $service->assignPendingTagsToUser([$firstTagId, (string) $secondTagId], $userId));

        $tags = $this->connection()->fetchAll(
            'SELECT id, user_id, status FROM nfc_tags WHERE id IN (:first_id, :second_id) ORDER BY id',
            ['first_id' => $firstTagId, 'second_id' => $secondTagId]
        );
        self::assertCount(2, $tags);
        foreach ($tags as $tag) {
            self::assertSame($userId, (int) $tag['user_id']);
            self::assertSame('pending', $tag['status']);
        }
    }

    public function testAssignmentRollsBackWhenAnySelectedTagIsNoLongerFree(): void
    {
        $targetUserId = $this->createUser();
        $otherUserId = $this->createUser();
        $freeTagId = $this->createPendingTag();
        $assignedTagId = $this->createPendingTag($otherUserId);
        $service = new NfcTagService($this->connection(), 'test-key');

        try {
            $service->assignPendingTagsToUser([$freeTagId, $assignedTagId], $targetUserId);
            self::fail('Expected an exception for an already assigned tag.');
        } catch (RuntimeException $exception) {
            self::assertStringContainsString('nicht mehr frei', $exception->getMessage());
        }

        self::assertNull($this->connection()->fetchColumn('SELECT user_id FROM nfc_tags WHERE id = :id', ['id' => $freeTagId]));
        self::assertSame($otherUserId, (int) $this->connection()->fetchColumn('SELECT user_id FROM nfc_tags WHERE id = :id', ['id' => $assignedTagId]));
    }

    public function testRejectsInactiveUsersAndMalformedTagIds(): void
    {
        $inactiveUserId = $this->createUser(['employment_status' => 'inactive']);
        $tagId = $this->createPendingTag();
        $service = new NfcTagService($this->connection(), 'test-key');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('aktiven User');
        $service->assignPendingTagsToUser([$tagId], $inactiveUserId);
    }

    public function testRejectsNestedTagIdPayloads(): void
    {
        $userId = $this->createUser();
        $this->createPendingTag();
        $service = new NfcTagService($this->connection(), 'test-key');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Auswahl ist ungueltig');
        $service->assignPendingTagsToUser([[1]], $userId);
    }

    public function testRejectsArchivedUsersAndArchivedTagsWithoutPartialAssignment(): void
    {
        $archivedUserId = $this->createUser();
        $archivedUserTagId = $this->createPendingTag();
        $this->connection()->execute('UPDATE users SET is_deleted = 1 WHERE id = :id', ['id' => $archivedUserId]);
        $service = new NfcTagService($this->connection(), 'test-key');

        try {
            $service->assignPendingTagsToUser([$archivedUserTagId], $archivedUserId);
            self::fail('Expected an exception for an archived user.');
        } catch (RuntimeException $exception) {
            self::assertStringContainsString('aktiven User', $exception->getMessage());
        }
        self::assertNull($this->connection()->fetchColumn('SELECT user_id FROM nfc_tags WHERE id = :id', ['id' => $archivedUserTagId]));

        $activeUserId = $this->createUser();
        $freeTagId = $this->createPendingTag();
        $archivedTagId = $this->createPendingTag();
        $this->connection()->execute('UPDATE nfc_tags SET is_deleted = 1 WHERE id = :id', ['id' => $archivedTagId]);

        try {
            $service->assignPendingTagsToUser([$freeTagId, $archivedTagId], $activeUserId);
            self::fail('Expected an exception for an archived tag.');
        } catch (RuntimeException $exception) {
            self::assertStringContainsString('nicht mehr frei', $exception->getMessage());
        }
        self::assertNull($this->connection()->fetchColumn('SELECT user_id FROM nfc_tags WHERE id = :id', ['id' => $freeTagId]));
    }

    public function testArchivesOnlyCurrentTagsAndReportsRepeatedCalls(): void
    {
        $tagId = $this->createPendingTag();
        $service = new NfcTagService($this->connection(), 'test-key');

        self::assertTrue($service->archive($tagId, null));
        self::assertSame(1, (int) $this->connection()->fetchColumn('SELECT is_deleted FROM nfc_tags WHERE id = :id', ['id' => $tagId]));
        self::assertFalse($service->archive($tagId, null));
        self::assertFalse($service->archive($tagId + 99999, null));
    }

    public function testRelearningArchivedTagRestoresItAsUnassignedPendingTagWithAuditNotice(): void
    {
        $service = new NfcTagService($this->connection(), 'test-key');
        $formerUserId = $this->createUser();
        $formerProjectId = $this->createProject();
        $terminalId = $this->createTerminal();
        $uid = '04:A1:B2:C3';

        $this->connection()->execute(
            'INSERT INTO nfc_tags (
                uid_hash, uid_masked, label, user_id, project_id, status,
                is_deleted, deleted_at, deleted_by_user_id, created_at, updated_at
             ) VALUES (
                :uid_hash, :uid_masked, :label, :user_id, :project_id, "active",
                1, NOW(), :deleted_by_user_id, NOW(), NOW()
             )',
            [
                'uid_hash' => $service->hashUid($uid),
                'uid_masked' => '04:A1:...:C3',
                'label' => 'Alter Mitarbeitertag',
                'user_id' => $formerUserId,
                'project_id' => $formerProjectId,
                'deleted_by_user_id' => $formerUserId,
            ]
        );
        $tagId = $this->connection()->lastInsertId();

        $service->startLearnSession($terminalId, $formerUserId);
        $tag = $service->captureLearnScan(['id' => $terminalId], $uid);

        self::assertNotNull($tag);
        self::assertSame($tagId, (int) $tag['id']);
        self::assertSame('pending', $tag['status']);
        self::assertSame(0, (int) $tag['is_deleted']);
        self::assertNull($tag['user_id']);
        self::assertNull($tag['project_id']);
        self::assertNull($tag['label']);
        self::assertNotEmpty($tag['relearned_from_archive_at']);

        $session = $this->connection()->fetchOne('SELECT status, nfc_tag_id FROM terminal_learn_sessions LIMIT 1');
        self::assertSame('captured', $session['status']);
        self::assertSame($tagId, (int) $session['nfc_tag_id']);
    }

    private function createPendingTag(?int $userId = null): int
    {
        $suffix = bin2hex(random_bytes(8));
        $this->connection()->execute(
            'INSERT INTO nfc_tags (uid_hash, uid_masked, user_id, status, created_at, updated_at, is_deleted)
             VALUES (:uid_hash, :uid_masked, :user_id, "pending", NOW(), NOW(), 0)',
            [
                'uid_hash' => hash('sha256', $suffix),
                'uid_masked' => '04:AA:...:' . strtoupper(substr($suffix, -2)),
                'user_id' => $userId,
            ]
        );

        return $this->connection()->lastInsertId();
    }

    private function createTerminal(): int
    {
        $suffix = bin2hex(random_bytes(6));
        $this->connection()->execute(
            'INSERT INTO terminals (
                terminal_identifier, name, token_hash, welcome_text, is_active, is_deleted, created_at, updated_at
             ) VALUES (
                :terminal_identifier, :name, :token_hash, "Willkommen", 1, 0, NOW(), NOW()
             )',
            [
                'terminal_identifier' => 'test-terminal-' . $suffix,
                'name' => 'Testterminal ' . $suffix,
                'token_hash' => password_hash('test-token', PASSWORD_DEFAULT),
            ]
        );

        return $this->connection()->lastInsertId();
    }
}
