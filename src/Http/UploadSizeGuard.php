<?php

declare(strict_types=1);

namespace App\Http;

final class UploadSizeGuard
{
    public static function exceedsPostMaxSize(Request $request): bool
    {
        $contentLength = (int) $request->server('CONTENT_LENGTH', 0);
        $postMaxBytes = self::iniBytes((string) ini_get('post_max_size'));

        return $contentLength > 0 && $postMaxBytes > 0 && $contentLength > $postMaxBytes;
    }

    public static function message(): string
    {
        $uploadMaxBytes = self::iniBytes((string) ini_get('upload_max_filesize'));
        $postMaxBytes = self::iniBytes((string) ini_get('post_max_size'));
        $limits = array_values(array_filter([$uploadMaxBytes, $postMaxBytes], static fn (int $bytes): bool => $bytes > 0));
        $limit = $limits !== [] ? min($limits) : 0;

        return 'Die Datei ist zu gross. Maximal erlaubt sind ' . self::formatBytes($limit) . '.';
    }

    public static function iniBytes(string $value): int
    {
        $value = trim($value);

        if ($value === '') {
            return 0;
        }

        $unit = strtolower(substr($value, -1));
        $number = (float) $value;

        return match ($unit) {
            'g' => (int) ($number * 1024 * 1024 * 1024),
            'm' => (int) ($number * 1024 * 1024),
            'k' => (int) ($number * 1024),
            default => (int) $number,
        };
    }

    private static function formatBytes(int $bytes): string
    {
        if ($bytes >= 1024 * 1024) {
            return rtrim(rtrim(number_format($bytes / 1024 / 1024, 1, ',', ''), '0'), ',') . ' MB';
        }

        if ($bytes >= 1024) {
            return rtrim(rtrim(number_format($bytes / 1024, 1, ',', ''), '0'), ',') . ' KB';
        }

        return $bytes . ' B';
    }
}
