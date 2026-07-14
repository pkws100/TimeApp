<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

final class TerminalImplementationTest extends TestCase
{
    public function testTerminalServiceStoresOnlyHashedTokensAndUsesBearerAuth(): void
    {
        $source = (string) file_get_contents(base_path('src/Domain/Terminals/TerminalService.php'));

        self::assertStringContainsString('password_hash($plainToken, PASSWORD_DEFAULT)', $source);
        self::assertStringContainsString('password_verify($token', $source);
        self::assertStringContainsString('Authorization', $source);
        self::assertStringContainsString('X-Terminal-ID', $source);
    }

    public function testTerminalPunchPrioritizesTerminalProjectBeforeTagProject(): void
    {
        $source = (string) file_get_contents(base_path('src/Domain/Terminals/TerminalPunchService.php'));

        self::assertStringContainsString('$terminalProjectId = (int) ($terminal[\'default_project_id\'] ?? 0);', $source);
        self::assertStringContainsString('if ($terminalProjectId > 0)', $source);
        self::assertStringContainsString('$tagProjectId = (int) ($tag[\'project_id\'] ?? 0);', $source);
    }

    public function testTerminalSyncRequestIdIsHashedToFitStorageLimit(): void
    {
        $source = (string) file_get_contents(base_path('src/Domain/Terminals/TerminalPunchService.php'));

        self::assertStringContainsString('private function syncRequestId(array $terminal, string $requestId): string', $source);
        self::assertStringContainsString('return \'terminal:\' . hash(\'sha256\'', $source);
        self::assertStringNotContainsString('\'terminal:\' . (string) ($terminal[\'terminal_identifier\']', $source);
    }

    public function testTerminalLearnScanRunsBeforeTimesheetLookup(): void
    {
        $source = (string) file_get_contents(base_path('src/Domain/Terminals/TerminalPunchService.php'));

        self::assertLessThan(
            strpos($source, '$tag = $this->nfcTagService->findActiveByUid($uid)'),
            strpos($source, '$learnedTag = $this->nfcTagService->captureLearnScan($terminal, $uid)')
        );
    }

    public function testTerminalProjectIsOnlySentForCheckIn(): void
    {
        $source = (string) file_get_contents(base_path('src/Domain/Terminals/TerminalPunchService.php'));

        self::assertStringContainsString('\'project_id\' => $action === \'check_in\' ? $projectId : null', $source);
    }

    public function testTerminalApiExposesConfigAndScanRoutes(): void
    {
        $source = (string) file_get_contents(base_path('bootstrap/app.php'));

        self::assertStringContainsString('/api/v1/terminal/config', $source);
        self::assertStringContainsString('/api/v1/terminal/scan', $source);
        self::assertStringContainsString('/api/v1/terminal/trust-bundle', $source);
        self::assertStringContainsString('/admin/terminals', $source);
    }

    public function testTransportDiagnosticsAreOptionalAndTrustBundleIsPublicOnly(): void
    {
        $service = (string) file_get_contents(base_path('src/Domain/Terminals/TerminalService.php'));
        $controller = (string) file_get_contents(base_path('src/Http/Controllers/TerminalApiController.php'));

        self::assertStringContainsString('X-Terminal-Transport', $service);
        self::assertStringContainsString('X-Terminal-Trust-Version', $service);
        self::assertStringContainsString('public, max-age=3600', $controller);
        self::assertStringContainsString("'ETag'", $controller);
        self::assertStringContainsString('function trustBundle', $controller);
    }
}
