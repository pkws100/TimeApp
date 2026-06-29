<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Presentation\Admin\PersonnelIconRenderer;
use PHPUnit\Framework\TestCase;

final class PersonnelIconRendererTest extends TestCase
{
    public function testBadgeRendersAwardIconInsteadOfRawIconKey(): void
    {
        $html = PersonnelIconRenderer::badge([
            'name' => 'Informatiker',
            'color' => '#ff00ae',
            'icon' => 'award',
        ]);

        self::assertStringContainsString('personnel-label__icon', $html);
        self::assertStringContainsString('<svg viewBox="0 0 24 24"', $html);
        self::assertStringContainsString('Informatiker', $html);
        self::assertStringNotContainsString('award · Informatiker', $html);
    }

    public function testBadgeEscapesNameAndFallsBackToSafeColor(): void
    {
        $html = PersonnelIconRenderer::badge([
            'name' => '<b>Label</b>',
            'color' => 'not-a-color',
            'icon' => 'fa-solid-truck',
        ]);

        self::assertStringContainsString('--personnel-label-color:#2563eb', $html);
        self::assertStringContainsString('&lt;b&gt;Label&lt;/b&gt;', $html);
        self::assertStringContainsString('<circle cx="7" cy="18" r="2"></circle>', $html);
    }
}
