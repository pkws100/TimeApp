<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\Personnel\Compliance\InitialPersonnelComplianceCatalog;
use PHPUnit\Framework\TestCase;

final class InitialPersonnelComplianceCatalogTest extends TestCase
{
    public function testCatalogCoversEveryCurrentNumberInSectionEight(): void
    {
        $positions = [
            1 => ['1', '2', '3', '4', '5', '6', '7', '8', '9', '10', '11', '11a', '12', '13', '14', '15', '16', '17', '18', '19'],
            2 => ['1', '2', '3', '3a', '4', '4a', '4b', '6', '7', '7a', '8', '9', '10', '11', '12', '13', '14', '15', '16', '18', '18a', '19', '20'],
        ];
        $expectedCitations = [];
        foreach ($positions as $paragraph => $numbers) {
            foreach ($numbers as $number) {
                $expectedCitations[] = "§ 8 Abs. $paragraph S. 1 Nr. $number BVV";
            }
        }

        $citations = [];
        foreach (InitialPersonnelComplianceCatalog::rules() as $entry) {
            if ($entry['source'] === 'BVV-8') {
                $citations[] = $entry['requirement']['citation'];
            }
        }

        self::assertEqualsCanonicalizing($expectedCitations, array_values(array_unique($citations)));
    }

    public function testSourcesAndStableIdentitiesHaveConsistentMetadata(): void
    {
        $sources = InitialPersonnelComplianceCatalog::sources();
        self::assertSame('2026-07-01', $sources['BVV-8']['version']);
        self::assertSame('2022-04-01', $sources['BVV-9A-GG']['effective_from']);
        self::assertStringContainsString('genehmigt am 31.03.2022', $sources['BVV-9A-GG']['title']);
        foreach ($sources as $code => $source) {
            self::assertSame($code, $source['code']);
            self::assertSame('2026-11-18', $source['checked_on']);
            self::assertStringStartsWith('https://', $source['url']);
        }

        $types = [];
        foreach (InitialPersonnelComplianceCatalog::rules() as $code => $entry) {
            $rule = $entry['requirement'];
            $typeCode = $entry['type']['code'];
            self::assertSame($code, $rule['rule_code']);
            self::assertSame(1, $rule['revision']);
            self::assertArrayHasKey($entry['source'], $sources);
            self::assertSame($sources[$entry['source']]['effective_from'], $rule['valid_from']);
            if (isset($types[$typeCode])) {
                self::assertSame($types[$typeCode], $entry['type']);
            } else {
                $types[$typeCode] = $entry['type'];
            }
        }
    }

    public function testRulesKeepDataDeclarationsAndTimeEvidenceDistinctAndRequireManualReview(): void
    {
        $rules = array_column(InitialPersonnelComplianceCatalog::rules(), 'requirement');

        foreach ($rules as $rule) {
            self::assertSame('manual_review', $rule['applicability_mode'], $rule['rule_code']);
            self::assertNotEmpty($rule['employment_scope']);
            self::assertNotEmpty($rule['evidence_rule']);
            self::assertNotEmpty($rule['signature_rule']);
        }

        $byCode = array_column($rules, null, 'rule_code');
        self::assertSame('master_data', $byCode['BVV-8-1-01']['record_kind']);
        self::assertSame('payroll_period', $byCode['BVV-8-1-11A']['record_frequency']);
        self::assertSame('employer_report', $byCode['BVV-8-2-03']['record_kind']);
        self::assertSame('third_party_data', $byCode['BVV-8-2-03A']['record_kind']);
        self::assertSame('employee_declaration', $byCode['BVV-8-2-04B']['record_kind']);
        self::assertStringContainsString('Eingangsdatum', $byCode['BVV-8-2-04B']['evidence_rule']);
        self::assertStringContainsString('Textform', $byCode['BVV-8-2-15']['signature_rule']);
        self::assertSame('working_time_record', $byCode['BVV-8-2-13-A']['record_kind']);
        self::assertSame('calendar_year', $byCode['SGB-IV-28F-1']['record_frequency']);
    }

    public function testRetentionDistinguishesLastAuditOriginalsAndWorkTimeEvidence(): void
    {
        $rules = array_column(InitialPersonnelComplianceCatalog::rules(), 'requirement');
        $rules = array_column($rules, null, 'rule_code');

        self::assertSame('end_of_year_of_last_social_audit', $rules['BVV-8-2-13-A']['retention_trigger']);
        self::assertSame(1, $rules['BVV-8-2-13-A']['retention_years']);
        self::assertSame('binding_completion_of_social_audit', $rules['BVV-9-5-ORIGINAL']['retention_trigger']);
        self::assertNull($rules['BVV-9-5-ORIGINAL']['retention_years']);
        self::assertSame('relevant_recording_date', $rules['MILOG-17-1']['retention_trigger']);
        self::assertSame(2, $rules['MILOG-17-1']['retention_years']);
        self::assertSame(2, $rules['AENTG-19-1']['retention_years']);
    }
}
