<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Presentation\Admin\AdminView;
use App\Presentation\Admin\PersonnelDocumentsView;
use PHPUnit\Framework\TestCase;

final class PersonnelDocumentsViewTest extends TestCase
{
    public function testScopedOverviewUsesPostForPersonSearchAndEscapesVisibleData(): void
    {
        $page = $this->view()->index([
            'search' => '\"><img src=broken onerror=alert(1)>',
            'filter' => 'archived',
            'page' => 1,
            'has_more' => true,
            'employees' => [[
                ...$this->employee(), 'first_name' => '<em>Fiktiv</em>', 'is_deleted' => 1,
                'document_count' => 2, 'pending_count' => 1, 'ambiguous' => true,
            ]],
        ], ['csrf' => 'fixture-token']);

        self::assertStringContainsString('method="post" action="/admin/personnel-documents/search"', $page);
        self::assertStringContainsString('name="csrf_token" value="fixture-token"', $page);
        self::assertStringContainsString('href="/admin/personnel-documents/users/42"', $page);
        self::assertStringContainsString('&lt;em&gt;Fiktiv&lt;/em&gt;', $page);
        self::assertStringNotContainsString('<img src=broken', $page);
        self::assertStringContainsString('Klärungsbedarf', $page);
        self::assertStringContainsString('Nächste Seite', $page);
    }

    public function testEmployeeRetainsPreviousEffectivePeriodWithoutExposingManagementForms(): void
    {
        $first = ['id' => 1, 'employment_key' => 'fixture-a', 'revision' => 1, 'effective_from' => '2026-01-01',
            'effective_until' => '2026-06-30', 'employment_type' => 'marginal', 'revision_reason' => 'initial_record'];
        $second = ['id' => 2, 'employment_key' => 'fixture-a', 'revision' => 2, 'effective_from' => '2026-07-01',
            'effective_until' => null, 'employment_type' => 'regular', 'revision_reason' => 'change_of_circumstances'];
        $page = $this->view()->employee([
            'employee' => $this->employee(), 'employment_history' => [$first, $second],
            'employment_current' => [$first], 'as_of' => '2026-03-01', 'can_manage' => false,
        ], ['csrf' => 'fixture-token']);

        self::assertStringContainsString('Minijob', $page);
        self::assertStringContainsString('01.01.2026 – 30.06.2026', $page);
        self::assertStringContainsString('Reguläre Beschäftigung', $page);
        self::assertStringContainsString('Wirksam am Stichtag', $page);
        self::assertStringContainsString('Änderung ab Stichtag', $page);
        self::assertStringContainsString('action="/admin/personnel-documents/users/42/as-of"', $page);
        self::assertStringNotContainsString('Dokument anlegen', $page);
    }

    public function testVersionReadSeparatesChecksAndSendsOnlyAnAuthorizedPostDownload(): void
    {
        $data = $this->document();
        $data['versions'] = [$this->storedVersion(), ['id' => 17, 'version_number' => 2, 'file_stored' => 0]];
        $page = $this->view()->document($data, ['csrf' => 'fixture-token']);

        self::assertStringContainsString('method="post" action="/admin/personnel-documents/15/download"', $page);
        self::assertStringContainsString('name="version_id" value="16"', $page);
        self::assertStringNotContainsString('name="version_id" value="17"', $page);
        self::assertStringNotContainsString('href="/admin/personnel-documents/15/download', $page);
        self::assertStringContainsString('Nur Metadaten', $page);
        self::assertStringContainsString('application/pdf', $page);
        self::assertStringContainsString('1,5 KiB', $page);
        self::assertStringContainsString('Signaturstatus</dt><dd>Ungeklärt', $page);
        self::assertStringContainsString('Fachlich offen', $page);
        self::assertStringNotContainsString('physical-secret-marker', $page);
        self::assertStringNotContainsString('data-personnel-upload>', $page);
    }

    public function testFailedEvidenceRendersRelevantInputsAndArchiveHonorsRetentionHold(): void
    {
        $data = $this->document();
        $data['can_manage'] = true;
        $data['document']['retention_hold'] = 1;
        $data['versions'] = [$this->storedVersion()];
        $page = $this->view()->document($data, [
            'csrf' => 'fixture-token', 'active_form' => 'evidence', 'error' => '<script>old</script>',
            'old' => ['outcome' => 'objected', 'original_kind' => 'paper', 'reason' => 'illegible', 'version_id' => 16],
            'request_ids' => ['evidence' => 'fixture-request'], 'file_available' => false,
        ]);

        self::assertStringContainsString('&lt;script&gt;old&lt;/script&gt;', $page);
        self::assertStringContainsString('class="personnel-disclosure" open', $page);
        self::assertStringContainsString('value="objected" selected', $page);
        self::assertStringContainsString('value="paper" selected', $page);
        self::assertStringContainsString('value="illegible" selected', $page);
        self::assertStringContainsString('name="request_id" value="fixture-request"', $page);
        self::assertStringContainsString('name="expected_revision" value="7"', $page);
        self::assertStringContainsString('Sperre aufheben', $page);
        self::assertStringContainsString('Die Dateiablage ist für diesen Testlauf zurzeit gesperrt.', $page);
        self::assertStringNotContainsString('/personnel-documents/15/archive', $page);
        self::assertStringNotContainsString('/personnel-documents/15/download', $page);
    }

    public function testEvidenceFormMatchesTheServersLatestVersionAndPendingPolicy(): void
    {
        $data = $this->document();
        $data['can_manage'] = true;
        $data['versions'] = [$this->storedVersion(), ['id' => 17, 'version_number' => 2, 'file_stored' => 0]];
        $data['evidence_history'] = [['version_number' => 1, 'outcome' => 'open', 'actor_user_id' => 2]];
        $page = $this->view()->document($data, ['csrf' => 'fixture-token']);

        self::assertStringContainsString('Nachweis offen', $page);
        self::assertStringNotContainsString('data-personnel-evidence>', $page);

        $data['versions'] = [$this->storedVersion()];
        $data['document']['review_status'] = 'rejected';
        self::assertStringNotContainsString('data-personnel-evidence>', $this->view()->document($data, ['csrf' => 'fixture-token']));
    }

    private function view(): PersonnelDocumentsView
    {
        return new PersonnelDocumentsView(new AdminView('Synthetischer Test', 'http://127.0.0.1'));
    }

    private function employee(): array
    {
        return ['id' => 42, 'first_name' => 'Ada', 'last_name' => 'Muster', 'employee_number' => 'S-42', 'employment_status' => 'active', 'is_deleted' => 0];
    }

    private function document(): array
    {
        return ['document' => ['id' => 15, 'user_id' => 42, 'lock_version' => 7, 'type_label' => 'Musterbeleg', 'review_status' => 'pending'],
            'employee' => $this->employee(), 'can_manage' => false];
    }

    private function storedVersion(): array
    {
        return ['id' => 16, 'version_number' => 1, 'file_stored' => 1, 'size_bytes' => 1536, 'mime_type' => 'application/pdf',
            'format_verified_at' => '2026-11-01 10:00:00', 'malware_scanned_at' => '2026-11-01 10:00:00',
            'signature_status' => 'verified', 'storage_key' => 'physical-secret-marker'];
    }
}
