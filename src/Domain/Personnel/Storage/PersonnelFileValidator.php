<?php

declare(strict_types=1);

namespace App\Domain\Personnel\Storage;

use JsonException;

final class PersonnelFileValidator
{
    private const MAX_BYTES = 33554432;
    private const FORMATS = ['application/pdf' => ['pdf'], 'image/png' => ['png'], 'image/jpeg' => ['jpg', 'jpeg']];
    private const PDF_DENIED_NAMES = [
        '/a', '/aa', '/acroform', '/annots', '/collection', '/embeddedfile', '/embeddedfiles', '/encrypt',
        '/ef', '/f', '/fdecodeparms', '/ffilter', '/filespec', '/gotoe', '/gotor', '/importdata', '/javascript',
        '/js', '/launch', '/metadata', '/movie', '/names', '/needsrendering', '/openaction', '/prev', '/ps',
        '/rendition', '/richmedia', '/sound', '/submitform', '/uri', '/xfa', '/3d', '/af', '/thumb',
    ];
    private const PDF_FILTERS = ['/FlateDecode', '/DCTDecode', '/ASCII85Decode', '/ASCIIHexDecode', '/RunLengthDecode', '/CCITTFaxDecode'];

    public function __construct(private readonly string $qpdfPath, private readonly int $timeoutSeconds = 10)
    {
        if (!str_starts_with($qpdfPath, '/') || preg_match('~(?:^|/)\.{1,2}(?:/|$)|[\x00-\x1f\x7f]~', $qpdfPath) !== 0
            || $timeoutSeconds < 1 || $timeoutSeconds > 60) {
            throw new PersonnelStorageException('Ungültige private Formatprüfer-Konfiguration.');
        }
    }

    public function assertReady(): void
    {
        $this->requireImageRuntime();
        $this->requirePdfRuntime();
        $version = $this->runQpdf(['--version'], $this->deadline());
        if (!preg_match('/\bqpdf version (\d+)\./i', $version, $matches) || (int) $matches[1] < 11) {
            throw new PersonnelStorageException('Die private Formatprüfung ist nicht verfügbar.');
        }
    }

    public function validate(string $quarantinePath, ?string $declaredMime = null, ?string $extension = null): string
    {
        if (!class_exists(\finfo::class)) {
            throw new PersonnelStorageException('Die private Formatprüfung ist nicht verfügbar.');
        }
        $file = PersonnelPrivatePath::openPrivateFile($quarantinePath);
        try {
            $size = fstat($file)['size'] ?? 0;
            if ($size < 1 || $size > self::MAX_BYTES) {
                throw self::rejected();
            }
            $buffer = @fread($file, min(8192, $size));
            if ($buffer === false || $buffer === '') {
                throw self::rejected();
            }
            $actual = (new \finfo(FILEINFO_MIME_TYPE))->buffer($buffer);
            if (!is_string($actual) || !isset(self::FORMATS[$actual])
                || ($declaredMime !== null && strtolower(trim($declaredMime)) !== $actual)
                || ($extension !== null && !in_array(strtolower($extension), self::FORMATS[$actual], true))) {
                throw self::rejected();
            }
            rewind($file);
            $deadline = $this->deadline();
            if ($actual === 'application/pdf') {
                $this->requirePdfRuntime();
                $this->validatePdf($quarantinePath, $file, $size, $buffer, $deadline);
            } else {
                $this->requireImageRuntime();
                PersonnelImageValidator::validate($file, $quarantinePath, $actual, $deadline);
            }

            return $actual;
        } finally {
            fclose($file);
        }
    }

    /** @param resource $file */
    private function validatePdf(string $path, $file, int $size, string $prefix, int $deadline): void
    {
        if (!preg_match('/\A%PDF-(?:1\.[0-7]|2\.0)(?:\r\n|[\r\n])/', $prefix)) {
            throw self::rejected();
        }
        if (fseek($file, -min(1024, $size), SEEK_END) !== 0) {
            throw self::rejected();
        }
        $tail = stream_get_contents($file, 1024);
        if ($tail === false || !preg_match('/(?:\r\n|[\r\n])%%EOF[\r\n\x09\x20]{0,16}\z/', $tail)) {
            throw self::rejected();
        }
        rewind($file);
        $this->requireSinglePdfPayload($file, $deadline);
        // qpdf reports structural repairs as warnings with a nonzero exit status, which is a rejection.
        $this->runQpdf(['--check', $path], $deadline);
        $result = $this->runQpdf(['--json=2', '--json-key=qpdf', '--json-key=encrypt', '--json-key=pages', '--json-stream-data=none', $path], $deadline);
        try {
            $pdf = json_decode($result, true, 64, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw self::rejected();
        }
        if (!is_array($pdf) || ($pdf['version'] ?? null) !== 2 || ($pdf['encrypt']['encrypted'] ?? null) !== false
            || !is_array($pdf['pages'] ?? null) || count($pdf['pages']) < 1 || count($pdf['pages']) > 256
            || !is_array($pdf['qpdf'][1] ?? null) || !isset($pdf['qpdf'][1]['trailer'])) {
            throw self::rejected();
        }

        $this->validatePdfValue($pdf['qpdf'][1]);
    }

    /** @param resource $file */
    private function requireSinglePdfPayload($file, int $deadline): void
    {
        $carry = '';
        $endings = 0;
        while (!feof($file)) {
            if (hrtime(true) > $deadline) {
                throw self::rejected();
            }
            $chunk = @fread($file, 65536);
            if ($chunk === false || ($chunk === '' && !feof($file))) {
                throw self::rejected();
            }
            $data = $carry . $chunk;
            foreach (["PK\x05\x06", "PK\x06\x06"] as $archiveEnding) {
                if (str_contains($data, $archiveEnding)) {
                    throw self::rejected();
                }
            }
            $endings += substr_count($data, '%%EOF') - substr_count($carry, '%%EOF');
            $carry = substr($data, -7);
        }
        if ($endings !== 1) {
            throw self::rejected();
        }
    }

    private function validatePdfValue(mixed $value): void
    {
        if (is_string($value) && str_starts_with($value, '/')) {
            if (in_array(strtolower($value), self::PDF_DENIED_NAMES, true)) {
                throw self::rejected();
            }

            return;
        }
        if (!is_array($value)) {
            return;
        }
        foreach ($value as $key => $element) {
            if (is_string($key) && str_starts_with($key, '/')) {
                if (in_array(strtolower($key), self::PDF_DENIED_NAMES, true)) {
                    throw self::rejected();
                }
                if ($key === '/Filter') {
                    foreach (is_array($element) ? $element : [$element] as $filter) {
                        if (!in_array($filter, self::PDF_FILTERS, true)) {
                            throw self::rejected();
                        }
                    }
                }
            }
            $this->validatePdfValue($element);
        }
    }

    private function requireImageRuntime(): void
    {
        if (!class_exists(\finfo::class) || !class_exists(\GdImage::class) || !function_exists('inflate_init')) {
            throw new PersonnelStorageException('Die private Formatprüfung ist nicht verfügbar.');
        }
    }

    private function requirePdfRuntime(): void
    {
        if (!function_exists('proc_open')) {
            throw new PersonnelStorageException('Die private Formatprüfung ist nicht verfügbar.');
        }
        try {
            PersonnelPrivatePath::requireTrustedExecutable($this->qpdfPath);
            PersonnelPrivatePath::requireTrustedExecutable('/usr/bin/prlimit');
        } catch (PersonnelStorageException) {
            throw new PersonnelStorageException('Die private Formatprüfung ist nicht verfügbar.');
        }
    }

    private function deadline(): int
    {
        return hrtime(true) + $this->timeoutSeconds * 1_000_000_000;
    }

    private function runQpdf(array $arguments, int $deadline): string
    {
        $command = ['/usr/bin/prlimit', '--as=268435456', '--cpu=' . $this->timeoutSeconds, '--', $this->qpdfPath, ...$arguments];
        $proc = @proc_open($command, [['file', '/dev/null', 'r'], ['pipe', 'w'], ['pipe', 'w']], $pipes, null, null, ['bypass_shell' => true]);
        if (!is_resource($proc)) {
            throw self::rejected();
        }
        try {
            stream_set_blocking($pipes[1], false);
            stream_set_blocking($pipes[2], false);
            $output = '';
            $errorBytes = 0;
            do {
                $now = hrtime(true);
                if ($now >= $deadline || strlen($output) > 8388608 || $errorBytes > 65536) {
                    throw self::rejected();
                }
                $output .= $this->readQpdfOutput($pipes[1]);
                $errorBytes += strlen($this->readQpdfOutput($pipes[2]));
                $status = proc_get_status($proc);
                if ($status['running'] || !feof($pipes[1]) || !feof($pipes[2])) {
                    usleep(2000);
                }
                $exitCode = $status['exitcode'] >= 0 ? $status['exitcode'] : ($exitCode ?? null);
            } while ($status['running'] || !feof($pipes[1]) || !feof($pipes[2]));
            if ($exitCode !== 0 || strlen($output) > 8388608 || $errorBytes !== 0) {
                throw self::rejected();
            }

            return $output;
        } finally {
            if (proc_get_status($proc)['running']) {
                @proc_terminate($proc, 9);
            }
            fclose($pipes[1]);
            fclose($pipes[2]);
            proc_close($proc);
        }
    }

    /** @param resource $pipe */
    private function readQpdfOutput($pipe): string
    {
        $part = @fread($pipe, 65536);
        if ($part === false) {
            throw self::rejected();
        }

        return $part;
    }

    public static function rejected(): PersonnelStorageException
    {
        return new PersonnelStorageException('Die private Datei entspricht keinem freigegebenen, passiven Dokumentformat.');
    }
}
