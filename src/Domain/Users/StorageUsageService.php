<?php

declare(strict_types=1);

namespace App\Domain\Users;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use UnexpectedValueException;

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

        try {
            $directory = new RecursiveDirectoryIterator($this->rootPath, FilesystemIterator::SKIP_DOTS);
        } catch (UnexpectedValueException) {
            return ['bytes' => 0, 'human' => '0 B'];
        }

        $iterator = new RecursiveIteratorIterator(
            $directory,
            RecursiveIteratorIterator::LEAVES_ONLY,
            RecursiveIteratorIterator::CATCH_GET_CHILD
        );

        $bytes = 0;
        foreach ($iterator as $file) {
            if ($file->isFile()) {
                try {
                    $bytes += $file->getSize();
                } catch (RuntimeException) {
                    // Temporary files can disappear while the dashboard is counting them.
                }
            }
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

