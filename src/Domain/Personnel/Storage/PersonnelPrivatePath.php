<?php

declare(strict_types=1);

namespace App\Domain\Personnel\Storage;

final class PersonnelPrivatePath
{
    /** @param list<string> $forbiddenRoots */
    public static function requireRoot(string $root, array $forbiddenRoots): string
    {
        $path = self::absolute($root);
        self::requireTrustedAncestors(dirname($path));
        self::requireDirectory($path);
        self::requireSeparate($path, $forbiddenRoots);

        return $path;
    }

    /** @param list<string> $forbiddenRoots */
    public static function requireSeparate(string $path, array $forbiddenRoots): void
    {
        $canonicalPath = self::canonical(self::absolute($path));
        $mounts = self::mounts();
        $physicalPath = self::physicalLocation($canonicalPath, $mounts);

        foreach ($forbiddenRoots as $forbidden) {
            $canonicalForbidden = self::canonicalForbidden(self::absolute($forbidden, false));
            if (self::overlaps($canonicalPath, $canonicalForbidden)
                || self::sameTree($physicalPath, self::physicalLocation($canonicalForbidden, $mounts))) {
                throw self::invalidLocation();
            }
            foreach ($mounts as $mount) {
                if (self::isWithin($mount['point'], $canonicalForbidden)
                    && self::sameTree($physicalPath, [$mount['device'], $mount['root']])) {
                    throw self::invalidLocation();
                }
            }
        }

        foreach ($mounts as $mount) {
            // A private root may itself be a dedicated volume. Its contents cannot contain other mounts.
            if ($mount['point'] !== $canonicalPath && self::isWithin($mount['point'], $canonicalPath)) {
                throw self::invalidLocation();
            }
        }
    }

    public static function requireDirectory(string $path): void
    {
        clearstatcache(true, $path);
        $stat = @lstat($path);
        if ($stat === false || ($stat['mode'] & 0177777) !== 0040700 || $stat['uid'] !== self::effectiveUser()) {
            throw self::invalidLocation();
        }
    }

    public static function requireTmpfs(string $path): int
    {
        $path = self::canonical(self::absolute($path));
        $mount = self::mountFor($path, self::mounts());
        clearstatcache(true, $path);
        $stat = @lstat($path);
        if ($stat === false || $mount['type'] !== 'tmpfs') {
            throw self::invalidLocation();
        }

        $device = $stat['dev'];
        // Linux dev_t encodes the device number reported by /proc/self/mountinfo.
        $major = (($device >> 8) & 0xfff) | (($device >> 32) & 0xfffff000);
        $minor = ($device & 0xff) | (($device >> 12) & 0xffffff00);
        if ($mount['device'] !== $major . ':' . $minor) {
            throw self::invalidLocation();
        }

        return $device;
    }

    /** @return array{int,int,int,int,int} */
    public static function scannerSocketIdentity(string $path): array
    {
        $path = self::absolute($path);
        clearstatcache(true, $path);
        $stat = @lstat($path);
        if ($stat === false || ($stat['mode'] & 0170000) !== 0140000 || ($stat['mode'] & 0007) !== 0 || realpath($path) !== $path) {
            throw self::invalidLocation();
        }
        self::requireTrustedAncestors(dirname($path), $stat['uid'], $stat['uid']);

        return [$stat['dev'], $stat['ino'], $stat['uid'], $stat['gid'], $stat['mode']];
    }

    public static function requireTrustedExecutable(string $path): void
    {
        $path = self::absolute($path);
        clearstatcache(true, $path);
        $stat = @lstat($path);
        if ($stat === false || ($stat['mode'] & 0170000) !== 0100000 || ($stat['mode'] & 0022) !== 0
            || !in_array($stat['uid'], [0, self::effectiveUser()], true) || !is_executable($path) || realpath($path) !== $path) {
            throw self::invalidLocation();
        }
        self::requireTrustedAncestors(dirname($path), childUid: $stat['uid']);
    }

    /** @return resource */
    public static function openSecret(string $path)
    {
        $path = self::absolute($path);
        self::requireTrustedAncestors(dirname($path));
        clearstatcache(true, $path);
        $stat = @lstat($path);
        if ($stat === false || ($stat['mode'] & 0170000) !== 0100000 || $stat['nlink'] !== 1
            || !in_array($stat['uid'], [0, self::effectiveUser()], true)
            || !in_array($stat['mode'] & 07777, [0400, 0600], true)) {
            throw new PersonnelStorageException('Der private Schlüsselbestand ist nicht sicher lesbar.');
        }

        return self::openVerified($path, $stat);
    }

    /** @return resource */
    public static function openPrivateFile(string $path)
    {
        clearstatcache(true, $path);
        $stat = @lstat($path);
        if ($stat === false || ($stat['mode'] & 0177777) !== 0100600 || $stat['uid'] !== self::effectiveUser()) {
            throw self::invalidLocation();
        }

        return self::openVerified($path, $stat);
    }

    /** @return resource */
    public static function createExclusive(string $path)
    {
        $previousMask = umask(0077);
        try {
            $handle = @fopen($path, 'x+b');
        } finally {
            umask($previousMask);
        }
        if (!is_resource($handle)) {
            throw self::invalidLocation();
        }

        return $handle;
    }

    /** @param resource $file */
    public static function sync($file): void
    {
        if (!@fflush($file) || !@fsync($file)) {
            throw new PersonnelStorageException('Private Daten konnten nicht dauerhaft geschrieben werden.');
        }
    }

    public static function syncDirectory(string $path): void
    {
        $directory = @fopen($path, 'rb');
        if (!is_resource($directory)) {
            throw self::invalidLocation();
        }
        try {
            if (!@fsync($directory)) {
                throw new PersonnelStorageException('Private Daten konnten nicht dauerhaft geschrieben werden.');
            }
        } finally {
            fclose($directory);
        }
    }

    private static function effectiveUser(): int
    {
        if (!function_exists('posix_geteuid')) {
            throw self::invalidLocation();
        }

        return posix_geteuid();
    }

    private static function requireTrustedAncestors(string $directory, ?int $serviceUid = null, ?int $childUid = null): void
    {
        $effectiveUser = self::effectiveUser();
        while (true) {
            clearstatcache(true, $directory);
            $stat = @lstat($directory);
            if ($stat === false || ($stat['mode'] & 0170000) !== 0040000 || !in_array($stat['uid'], [0, $effectiveUser, $serviceUid], true)) {
                throw self::invalidLocation();
            }
            if (($stat['mode'] & 0022) !== 0
                && (($stat['mode'] & 01000) === 0 || $stat['uid'] !== 0 || ($childUid !== null && !in_array($childUid, [0, $effectiveUser], true)))) {
                throw self::invalidLocation();
            }
            if ($directory === '/') {
                return;
            }
            if ($childUid !== null) {
                // A sticky public directory must be entered through a child anchored by the app or root.
                $childUid = $stat['uid'];
            }
            $directory = dirname($directory);
        }
    }

    /** @return resource */
    private static function openVerified(string $path, array $stat)
    {
        $handle = @fopen($path, 'rb');
        $opened = is_resource($handle) ? fstat($handle) : false;
        if ($opened === false || $opened['dev'] !== $stat['dev'] || $opened['ino'] !== $stat['ino']
            || $opened['mode'] !== $stat['mode'] || $opened['uid'] !== $stat['uid']) {
            if (is_resource($handle)) {
                fclose($handle);
            }
            throw self::invalidLocation();
        }

        return $handle;
    }

    private static function absolute(string $path, bool $strict = true): string
    {
        if (preg_match('~\A/[^\x00-\x1f\x7f]*\z~', $path) !== 1
            || ($strict && ($path === '/' || str_contains($path, '//') || preg_match('~(?:^|/)\.{1,2}(?:/|$)~', $path) === 1))) {
            throw self::invalidLocation();
        }

        return rtrim($path, '/') ?: '/';
    }

    private static function canonical(string $path): string
    {
        clearstatcache(true, $path);
        $real = realpath($path);
        if ($real !== false) {
            return $real;
        }
        if (@lstat($path) !== false) {
            throw self::invalidLocation();
        }

        return rtrim(self::canonical(dirname($path)), '/') . '/' . basename($path);
    }

    private static function canonicalForbidden(string $path): string
    {
        $resolved = '/';
        foreach (explode('/', $path) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }
            if ($segment === '..') {
                $resolved = dirname($resolved);
                continue;
            }
            $resolved = self::canonical(rtrim($resolved, '/') . '/' . $segment);
        }

        return $resolved;
    }

    /** @return list<array{device:string, root:string, point:string, type:string}> */
    private static function mounts(): array
    {
        $lines = @file('/proc/self/mountinfo', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($lines === false || $lines === []) {
            throw self::invalidLocation();
        }

        $mounts = [];
        foreach ($lines as $line) {
            $fields = explode(' ', $line, 7);
            $separator = strpos($line, ' - ');
            if ($separator === false || count($fields) !== 7 || preg_match('/\A\d+:\d+\z/', $fields[2]) !== 1) {
                throw self::invalidLocation();
            }
            $mounts[] = [
                'device' => $fields[2],
                'root' => self::unescapeMountPath($fields[3]),
                'point' => self::unescapeMountPath($fields[4]),
                'type' => explode(' ', substr($line, $separator + 3), 2)[0],
            ];
        }

        return $mounts;
    }

    private static function unescapeMountPath(string $path): string
    {
        return preg_replace_callback('/\\\\([0-7]{3})/', static fn (array $match): string => chr(octdec($match[1])), $path);
    }

    /** @return array{string,string} */
    private static function physicalLocation(string $path, array $mounts): array
    {
        $mount = self::mountFor($path, $mounts);
        $suffix = ltrim(substr($path, strlen($mount['point'])), '/');
        $physical = $suffix === '' ? $mount['root'] : rtrim($mount['root'], '/') . '/' . $suffix;

        return [$mount['device'], $physical];
    }

    /** @return array{device:string, root:string, point:string, type:string} */
    private static function mountFor(string $path, array $mounts): array
    {
        $match = null;
        foreach ($mounts as $mount) {
            if (self::isWithin($path, $mount['point'])
                && ($match === null || strlen($mount['point']) >= strlen($match['point']))) {
                $match = $mount;
            }
        }
        if ($match === null) {
            throw self::invalidLocation();
        }
        return $match;
    }

    private static function sameTree(array $a, array $b): bool
    {
        return $a[0] === $b[0] && self::overlaps($a[1], $b[1]);
    }

    private static function overlaps(string $a, string $b): bool
    {
        return self::isWithin($a, $b) || self::isWithin($b, $a);
    }

    private static function isWithin(string $path, string $root): bool
    {
        return $path === $root || str_starts_with($path, rtrim($root, '/') . '/');
    }

    private static function invalidLocation(): PersonnelStorageException
    {
        return new PersonnelStorageException('Der private Speicherpfad ist nicht verfügbar oder unsicher.');
    }
}
