<?php

declare(strict_types=1);

namespace App\Domain\Auth;

final class ProjectSessionDirectory
{
    public static function prepare(string $storageRoot): ?string
    {
        $cachePath = rtrim($storageRoot, '/') . '/cache';
        $sessionPath = $cachePath . '/sessions';

        if (is_dir($sessionPath) && is_writable($sessionPath)) {
            return $sessionPath;
        }

        // Select the project directory on the first request, before an export
        // creates cache/. Changing the session path later loses existing logins.
        $canCreate = (is_dir($cachePath) && is_writable($cachePath))
            || (is_dir($storageRoot) && is_writable($storageRoot));
        if ($canCreate && (@mkdir($sessionPath, 0775, true) || is_dir($sessionPath))
            && is_writable($sessionPath)) {
            return $sessionPath;
        }

        return null;
    }
}
