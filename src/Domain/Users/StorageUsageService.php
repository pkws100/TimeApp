<?php

declare(strict_types=1);

namespace App\Domain\Users;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use FilesystemIterator;

final class StorageUsageService
{
    public function __construct(private string $rootPath)
    {
    }

    public function usage(): array
    {
        if (!is_dir($this->rootPath)) {
            return ['bytes' => 0, 'human' => '0 B'];
        }

        $bytes = 0;
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($this->rootPath, FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            $bytes += $file->getSize();
        }

        return ['bytes' => $bytes, 'human' => $this->formatBytes($bytes)];
    }

    private function formatBytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $value = (float) $bytes;
        $index = 0;

        while ($value >= 1024 && $index < count($units) - 1) {
            $value /= 1024;
            $index++;
        }

        return number_format($value, 2, ',', '.') . ' ' . $units[$index];
    }
}

