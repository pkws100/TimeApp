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

    public function testTerminalMonthlyStatusUsesTheTimeAccountCalculation(): void
    {
        $source = (string) file_get_contents(base_path('src/Domain/Terminals/TerminalPunchService.php'));
        $bootstrap = (string) file_get_contents(base_path('bootstrap/app.php'));

        self::assertStringContainsString('use App\\Domain\\TimeAccounts\\TimeAccountService;', $source);
        self::assertStringContainsString('$this->timeAccountService?->monthlyAccount(', $source);
        self::assertStringContainsString("'credited_minutes' => \$creditedMinutes", $source);
        self::assertStringContainsString("'manual_adjustment_minutes' => \$manualAdjustmentMinutes", $source);
        self::assertStringContainsString('$terminalTrustBundleService, $timeAccountService', $bootstrap);
    }

    public function testTerminalApiExposesConfigAndScanRoutes(): void
    {
        $source = (string) file_get_contents(base_path('bootstrap/app.php'));

        self::assertStringContainsString('/api/v1/terminal/config', $source);
        self::assertStringContainsString('/api/v1/terminal/scan', $source);
        self::assertStringContainsString('/api/v1/terminal/trust-bundle', $source);
        self::assertStringContainsString('/admin/terminals', $source);
        self::assertStringContainsString('/admin/terminals/{id}/settings', $source);
    }

    public function testTerminalAdminOffersValidatedDisplaySettingsModal(): void
    {
        $controller = (string) file_get_contents(base_path('src/Http/Controllers/TerminalAdminController.php'));
        $script = (string) file_get_contents(base_path('public/assets/js/admin-terminals.js'));
        $service = (string) file_get_contents(base_path('src/Domain/Terminals/TerminalService.php'));

        self::assertStringContainsString('data-terminal-settings-open', $controller);
        self::assertStringContainsString('data-terminal-settings-modal', $controller);
        self::assertStringContainsString('updateDisplaySettings', $controller);
        self::assertStringContainsString('function updateDisplaySettings', $service);
        self::assertStringContainsString('data-terminal-settings-form', $script);
        self::assertStringContainsString('event.key === \'Escape\'', $script);
    }

    public function testTerminalDisplayResponsesKeepTheFixedSignalSemantics(): void
    {
        $source = (string) file_get_contents(base_path('src/Domain/Terminals/TerminalPunchService.php'));

        self::assertStringContainsString("'signal' => ['led' => 'green', 'beep' => 'success']", $source);
        self::assertStringContainsString("'signal' => ['led' => 'red', 'beep' => 'error']", $source);
        self::assertStringContainsString("'signal' => ['led' => 'yellow', 'beep' => 'ready']", $source);
        self::assertStringContainsString("'hold_ms' => \$displaySettings['hold_ms']['success']", $source);
        self::assertStringContainsString("'hold_ms' => \$displaySettings['hold_ms']['error']", $source);
        self::assertStringContainsString("'hold_ms' => \$displaySettings['hold_ms']['learning']", $source);
        self::assertStringContainsString('TerminalDisplaySettings::renderLines', $source);
    }

    public function testArchivedTerminalsCanBeRestoredThroughDedicatedLifecycleRoute(): void
    {
        $service = (string) file_get_contents(base_path('src/Domain/Terminals/TerminalService.php'));
        $controller = (string) file_get_contents(base_path('src/Http/Controllers/TerminalAdminController.php'));
        $routes = (string) file_get_contents(base_path('bootstrap/app.php'));

        self::assertStringContainsString('function restore(int $id): bool', $service);
        self::assertStringContainsString('SET is_deleted = 0, deleted_at = NULL, deleted_by_user_id = NULL', $service);
        self::assertStringContainsString('FOR UPDATE', $service);
        self::assertStringContainsString('function restore(Request $request, array $params): Response', $controller);
        self::assertStringContainsString('Archivierte Terminals anzeigen', $controller);
        self::assertStringContainsString('Wiederherstellen', $controller);
        self::assertStringContainsString('/admin/terminals/{id}/restore', $routes);
    }

    public function testTerminalTagUiUsesCurrentScopeTableAndDedicatedModalScript(): void
    {
        $controller = (string) file_get_contents(base_path('src/Http/Controllers/TerminalAdminController.php'));
        $script = (string) file_get_contents(base_path('public/assets/js/admin-terminals.js'));

        self::assertStringContainsString('$tagScope = $this->tagScope($request);', $controller);
        self::assertStringContainsString('$this->nfcTagService->list($tagScope)', $controller);
        self::assertStringContainsString('data-admin-table="terminal-tags"', $controller);
        self::assertStringContainsString('Konfiguration erforderlich', $controller);
        self::assertStringContainsString('scope=archived', $controller);
        self::assertStringContainsString('data-terminal-tag-modal', $controller);
        self::assertStringContainsString('data-terminal-tag-archive-form', $controller);
        self::assertStringContainsString('Tag archivieren', $controller);
        self::assertStringContainsString('data-terminal-tag-modal-relearn-warning', $controller);
        self::assertStringContainsString('aria-describedby="terminalTagModalReuseWarning"', $controller);
        self::assertStringContainsString('data-terminal-tag-row', $script);
        self::assertStringContainsString('archiveForm.hidden = Boolean(tag.is_deleted)', $script);
        self::assertStringContainsString('relearnWarning.hidden = !tag.relearned_from_archive_at', $script);
        self::assertStringContainsString("dialog.setAttribute('aria-describedby', 'terminalTagModalReuseWarning')", $script);
        self::assertStringContainsString("event.key === 'Escape'", $script);
    }

    public function testRelearningAnArchivedTagRestoresItAsPendingWithoutPriorAssignment(): void
    {
        $service = (string) file_get_contents(base_path('src/Domain/Terminals/NfcTagService.php'));
        $punchService = (string) file_get_contents(base_path('src/Domain/Terminals/TerminalPunchService.php'));

        self::assertStringContainsString('FOR UPDATE', $service);
        self::assertStringContainsString('label = NULL', $service);
        self::assertStringContainsString('user_id = NULL', $service);
        self::assertStringContainsString('project_id = NULL', $service);
        self::assertStringContainsString('status = "pending"', $service);
        self::assertStringContainsString('is_deleted = 0', $service);
        self::assertStringContainsString('relearned_from_archive_at = NOW()', $service);
        self::assertStringContainsString('Bereits verwendeter NFC-Tag erneut erfasst', $punchService);
    }

    public function testPendingTagAssignmentIsTransactionalAndDoesNotActivateTags(): void
    {
        $service = (string) file_get_contents(base_path('src/Domain/Terminals/NfcTagService.php'));
        $management = (string) file_get_contents(base_path('src/Http/Controllers/AdminManagementController.php'));
        $routes = (string) file_get_contents(base_path('bootstrap/app.php'));

        self::assertStringContainsString('function listForUser', $service);
        self::assertStringContainsString('function listFreePending', $service);
        self::assertStringContainsString('function assignPendingTagsToUser', $service);
        self::assertStringContainsString('FOR UPDATE', $service);
        self::assertStringContainsString('AND status = "pending"', $service);
        self::assertStringContainsString('SET user_id = :user_id, updated_at = NOW()', $service);
        self::assertStringContainsString('renderUserTerminalTagsSection', $management);
        self::assertStringContainsString('userAssignTerminalTags', $management);
        self::assertStringContainsString('/admin/users/{id}/nfc-tags', $routes);
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
