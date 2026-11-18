<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\Personnel\Storage\ClamAvPersonnelScanner;
use App\Domain\Personnel\Storage\PersonnelFileValidator;
use App\Domain\Personnel\Storage\PersonnelKeyring;
use App\Domain\Personnel\Storage\PersonnelPrivatePath;
use App\Domain\Personnel\Storage\PersonnelPrivateStorage;
use App\Domain\Personnel\Storage\PersonnelStorageException;
use App\Domain\Personnel\Storage\PersonnelStreamCipher;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

final class PersonnelStoragePrimitivesTest extends TestCase
{
    private string $testRoot;

    protected function setUp(): void
    {
        $this->testRoot = sys_get_temp_dir() . '/timeapp-private-unit-' . bin2hex(random_bytes(8));
        self::assertTrue(mkdir($this->testRoot, 0700));
    }

    protected function tearDown(): void
    {
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($this->testRoot, RecursiveDirectoryIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($files as $file) {
            $file->isDir() && !$file->isLink() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }
        rmdir($this->testRoot);
    }

    public function testStreamingCipherPreservesOriginalAndOldKeysDuringRotation(): void
    {
        $oldKey = random_bytes(32);
        $newKey = random_bytes(32);
        $original = str_repeat('synthetic file ' . "\0\xff", 20000);
        $context = 'personnel.object.v1:synthetic';
        $oldCipher = new PersonnelStreamCipher(new PersonnelKeyring('files-1', ['files-1' => $oldKey]));
        $rotatedCipher = new PersonnelStreamCipher(new PersonnelKeyring('files-2', ['files-1' => $oldKey, 'files-2' => $newKey]));
        $encrypted = $this->memory();
        try {
            self::assertSame('files-1', $oldCipher->encryptChunks([substr($original, 0, 230000), substr($original, 230000)], $encrypted, $context));
            rewind($encrypted);
            $ciphertext = stream_get_contents($encrypted);
            self::assertStringNotContainsString('synthetic file', $ciphertext);
            rewind($encrypted);
            $chunks = $rotatedCipher->decryptedChunks($encrypted, $context, 'files-1', strlen($original));
            self::assertSame(hash('sha256', $original), hash('sha256', implode('', iterator_to_array($chunks))));
            self::assertSame('files-1', $chunks->getReturn());
            ftruncate($encrypted, 0);
            rewind($encrypted);
            self::assertSame('files-2', $rotatedCipher->encryptChunks([$original], $encrypted, $context));
            rewind($encrypted);
            self::assertNotSame($ciphertext, stream_get_contents($encrypted));
        } finally {
            fclose($encrypted);
        }
    }

    #[DataProvider('invalidCiphertexts')]
    public function testCorruptionWrongKeyTruncationAndWrongContextFailClosed(string $case): void
    {
        $key = random_bytes(32);
        $cipher = new PersonnelStreamCipher(new PersonnelKeyring('files-1', ['files-1' => $key]));
        $original = $this->memory(str_repeat('fixture', 24000));
        $encrypted = $this->memory();
        $privateOutput = $this->memory();
        $input = null;
        try {
            $cipher->encrypt($original, $encrypted, 'object:real');
            rewind($encrypted);
            $data = stream_get_contents($encrypted);
            $input = $this->memory(match ($case) {
                'corrupt' => substr_replace($data, chr(ord($data[80]) ^ 1), 80, 1),
                'missing_final' => substr($data, 0, -21),
                'extra' => $data . 'extra',
                'bad_tag' => substr_replace($data, chr(ord($data[-1]) ^ 1), -1, 1),
                default => $data,
            });
            if ($case === 'wrong_key') {
                $cipher = new PersonnelStreamCipher(new PersonnelKeyring('files-1', ['files-1' => random_bytes(32)]));
            } elseif ($case === 'missing_key') {
                $cipher = new PersonnelStreamCipher(new PersonnelKeyring('files-2', ['files-2' => random_bytes(32)]));
            }
            $this->expectException(PersonnelStorageException::class);
            $cipher->decrypt($input, $privateOutput, $case === 'wrong_context' ? 'backup:wrong' : 'object:real', 'files-1', $case === 'size' ? 100 : null);
        } finally {
            foreach ([$original, $encrypted, $input, $privateOutput] as $stream) {
                if (is_resource($stream)) {
                    fclose($stream);
                }
            }
        }
    }

    public static function invalidCiphertexts(): array
    {
        return array_map(static fn (string $case): array => [$case], ['corrupt', 'bad_tag', 'missing_final', 'extra', 'missing_key', 'wrong_key', 'wrong_context', 'size']);
    }

    public function testKeyringsRejectReuseAndPublicSecretFiles(): void
    {
        $stored = random_bytes(32);
        $file = $this->testRoot . '/keyring.json';
        file_put_contents($file, json_encode(['active' => 'version-1', 'keys' => ['version-1' => base64_encode($stored)]], JSON_THROW_ON_ERROR));
        chmod($file, 0600);
        $loaded = PersonnelKeyring::fromFile($file);
        self::assertSame('version-1', $loaded->activeId());
        $this->rejected(fn () => $loaded->assertSeparateFrom(new PersonnelKeyring('backups-1', ['backups-1' => $stored])));
        $this->rejected(fn () => $loaded->assertSeparateFromSecrets(bin2hex($stored)));
        chmod($file, 0644);
        $this->rejected(fn () => PersonnelKeyring::fromFile($file));
        chmod($file, 0600);
        self::assertTrue(symlink($file, $this->testRoot . '/alias.json'));
        $this->rejected(fn () => PersonnelKeyring::fromFile($this->testRoot . '/alias.json'));
    }

    public function testDocumentsStageAndPublishWithoutOverwritingExistingObject(): void
    {
        $root = $this->makeFolder('private');
        $storage = new PersonnelPrivateStorage($root, [base_path('public')], maxConcurrent: 1);
        $key = $storage->newStorageKey();
        $bytes = 'synthetic fixture';
        $storage->withLock(false, function () use ($storage, $bytes, $key): void {
            $source = $this->memory($bytes);
            $staged = $storage->stageUpload($source, strlen($bytes));
            self::assertSame(strlen($bytes), $staged->size());
            self::assertSame(hash('sha256', $bytes), $staged->sha256());
            self::assertSame(0600, fileperms($staged->path()) & 0777);
            $source2 = $this->memory($bytes);
            try {
                $this->rejected(fn () => $storage->stageUpload($source2, strlen($bytes)));
                $sealed = $storage->beginObject($key);
                PersonnelStreamCipher::writeAll($sealed, 'stand-in ciphertext');
                fclose($sealed);
                $storage->publishObject($key);
                $second = $storage->beginObject($key);
                PersonnelStreamCipher::writeAll($second, 'invalid replacement');
                fclose($second);
                $this->rejected(fn () => $storage->publishObject($key));
                $storage->abortObject($key);
            } finally {
                $staged->discard();
                fclose($source);
                fclose($source2);
            }
        });
        $stored = $storage->openObject($key);
        self::assertSame('stand-in ciphertext', stream_get_contents($stored));
        fclose($stored);
        $storage->withLock(true, function () use ($storage, $key): void {
            self::assertSame([$key], array_keys($storage->listObjectKeys()));
            self::assertSame([], $storage->listStagingKeys());
            self::assertSame([], $storage->listQuarantineKeys());
            $storage->removeOrphanObject($key);
            $storage->assertEmpty();
        });
    }

    public function testStorageRejectsBadPermissionsTraversalAndSymlinkObjects(): void
    {
        $public = $this->makeFolder('public');
        $root = $this->makeFolder('private');
        self::assertTrue(symlink($public, $this->testRoot . '/public-alias'));
        $this->rejected(fn () => new PersonnelPrivateStorage($this->testRoot . '/public-alias', [$public]));
        $this->rejected(fn () => new PersonnelPrivateStorage($public, [$public]));
        self::assertTrue(symlink($root, $this->testRoot . '/private-alias'));
        $this->rejected(fn () => new PersonnelPrivateStorage($root, [$this->testRoot . '/private-alias']));
        $this->rejected(fn () => new PersonnelPrivateStorage($root, [$public . '/../private-alias']));
        $this->rejected(fn () => new PersonnelPrivateStorage($public . '/../private', [$public]));
        self::assertTrue(chmod($root, 0755));
        $this->rejected(fn () => new PersonnelPrivateStorage($root, [$public]));
        self::assertTrue(chmod($root, 0700));
        $storage = new PersonnelPrivateStorage($root, [$root . '/../public']);
        $storage->withLock(false, function () use ($storage): void {
            $this->rejected(fn () => $storage->beginObject('../invalid'));
            $this->rejected(fn () => $storage->withLock(false, static fn () => null));
        });
        $key = $storage->newStorageKey();
        self::assertTrue(symlink($public, $root . '/objects/' . $key));
        $this->rejected(fn () => $storage->openObject($key));
        $this->rejected(fn () => $storage->withLock(true, fn () => $storage->listObjectKeys()));
        $this->rejected(fn () => $storage->assertSeparateFrom($root . '/objects'));
    }

    public function testStagingRejectsIncompleteOverlongAndCapacityLimitedStreams(): void
    {
        $storage = new PersonnelPrivateStorage($this->makeFolder('private'), [base_path('public')], maxBytes: 16, maxConcurrent: 1);
        $storage->withLock(false, function () use ($storage): void {
            foreach ([0, 3, 9, 16, 17] as $expected) {
                $stream = $this->memory('synthetic');
                try {
                    if ($expected === 9) {
                        $staged = $storage->stageUpload($stream, $expected);
                        $staged->discard();
                    } else {
                        $this->rejected(fn () => $storage->stageUpload($stream, $expected));
                    }
                } finally {
                    fclose($stream);
                }
            }
        });
        $storage->withLock(true, fn () => $storage->assertEmpty());
        $tooSmall = new PersonnelPrivateStorage($this->makeFolder('capacity'), [base_path('public')], minFreeBytes: PHP_INT_MAX);
        $stream = $this->memory('a');
        try {
            $this->rejected(fn () => $tooSmall->withLock(false, fn () => $tooSmall->stageUpload($stream, 1)));
        } finally {
            fclose($stream);
        }
    }

    public function testStagingRejectsOpenSocketBeforeAttemptingToRead(): void
    {
        $storage = new PersonnelPrivateStorage($this->makeFolder('private'), [base_path('public')]);
        [$incoming, $openPeer] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        try {
            $this->expectException(PersonnelStorageException::class);
            $this->expectExceptionMessage('vollständig empfangener Dateistream');
            $storage->withLock(false, fn () => $storage->stageUpload($incoming, 1));
        } finally {
            fclose($incoming);
            fclose($openPeer);
        }
    }

    public function testPngAndJpegAreIndependentlyValidatedAndUnmodified(): void
    {
        $validator = new PersonnelFileValidator('/nonexistent/qpdf');
        $image = imagecreatetruecolor(16, 24);
        foreach (['png' => 'image/png', 'jpeg' => 'image/jpeg'] as $extension => $mime) {
            ob_start();
            $extension === 'png' ? imagepng($image) : imagejpeg($image);
            $bytes = ob_get_clean();
            $file = $this->privateFile($extension, $bytes);
            self::assertSame($mime, $validator->validate($file, $mime, $extension));
            self::assertSame($bytes, file_get_contents($file));
            $this->rejected(fn () => $validator->validate($file, 'application/pdf', $extension));
            $this->rejected(fn () => $validator->validate($file, $mime, 'pdf'));
            $this->rejected(fn () => $validator->validate($this->privateFile($extension . '-trailer', $bytes . 'extra')));
            $this->rejected(fn () => $validator->validate($this->privateFile($extension . '-short', substr($bytes, 0, -6))));
        }
        $this->rejected(fn () => $validator->validate($this->privateFile('text', 'synthetic text')));
        $this->rejected(fn () => $validator->validate($this->privateFile('empty', '')));
    }

    public function testPdfNeedsAWorkingParserAndOnlyAcceptsPassiveStructure(): void
    {
        $pdf = self::pdf();
        $file = $this->privateFile('passive', $pdf);
        $this->rejected(fn () => (new PersonnelFileValidator('/nonexistent/qpdf'))->validate($file));
        $configuredPath = getenv('PERSONNEL_TEST_QPDF') ?: '/usr/bin/qpdf';
        if (is_executable($configuredPath) || getenv('PERSONNEL_TEST_QPDF') !== false) {
            $validator = new PersonnelFileValidator($configuredPath);
            $validator->assertReady();
            self::assertSame('application/pdf', $validator->validate($file, 'application/pdf', 'pdf'));
            self::assertSame($pdf, file_get_contents($file));
            $this->rejected(fn () => $validator->validate($this->privateFile('action', self::pdf('/OpenAction 3 0 R'))));
            $this->rejected(fn () => $validator->validate($this->privateFile('encoded-action', self::pdf('/OpenA#63tion 3 0 R'))));
            $this->rejected(fn () => $validator->validate($this->privateFile('short', substr($pdf, 0, -12))));
            $this->rejected(fn () => $validator->validate($this->privateFile('trailing', $pdf . 'other bytes')));
        }
    }

    public function testRealClamAvAndUnavailableSocketWhenConfigured(): void
    {
        $missing = new ClamAvPersonnelScanner($this->testRoot . '/missing.sock', timeoutSeconds: 1);
        $this->rejected($missing->assertReady(...));
        $configuredSocket = getenv('PERSONNEL_TEST_CLAMD_SOCKET');
        if ($configuredSocket !== false) {
            $scanner = new ClamAvPersonnelScanner($configuredSocket);
            $scanner->assertReady();
            $clean = $this->memory('synthetic-file-for-accepted-probe');
            $detected = $this->memory('synthetic-file-for-signature-guard');
            try {
                $scanner->scan($clean);
                $this->rejected(fn () => $scanner->scan($detected));
            } finally {
                fclose($clean);
                fclose($detected);
            }
        }
    }

    public function testScannerRejectsUnsafeSocketAncestorBeforeConnecting(): void
    {
        $parent = $this->makeFolder('unsafe');
        $directory = $this->makeFolder('unsafe/scanner');
        self::assertTrue(chmod($parent, 0775));
        $path = $directory . '/clamd.sock';
        $server = stream_socket_server('unix://' . $path);
        self::assertIsResource($server);
        try {
            self::assertTrue(chmod($path, 0600));
            $scanner = new ClamAvPersonnelScanner($path, timeoutSeconds: 1);
            $this->rejected($scanner->assertReady(...));
            self::assertFalse(@stream_socket_accept($server, 0));
        } finally {
            fclose($server);
        }
    }

    public function testPdfRejectsExecutableFromUnsafeDirectory(): void
    {
        $directory = $this->makeFolder('unsafe');
        $path = $this->privateFile('unsafe/qpdf', "#!/bin/sh\nprintf 'qpdf version 11.0.0\\n'\n");
        self::assertTrue(chmod($path, 0700));
        self::assertTrue(chmod($directory, 0775));
        $validator = new PersonnelFileValidator($path, timeoutSeconds: 1);
        $this->rejected($validator->assertReady(...));
    }

    #[DataProvider('badClamAvReplies')]
    public function testScannerTimeoutAndMalformedRepliesFailClosed(string $reply): void
    {
        $socket = $this->testRoot . '/clamd.sock';
        $worker = <<<'PHP'
            $server = stream_socket_server('unix://' . $argv[1]);
            chmod($argv[1], 0600);
            echo "ready\n";
            $client = stream_socket_accept($server, 4);
            if ($client === false || stream_get_line($client, 32, "\0") !== 'zINSTREAM') {
                exit(7);
            }
            while (($size = unpack('Nsize', fread($client, 4))['size']) !== 0) {
                stream_get_contents($client, $size);
            }
            if ($argv[2] === 'timeout') {
                sleep(3);
            } else {
                fwrite($client, $argv[2] . "\0");
            }
            fclose($client);
            fclose($server);
            PHP;
        $proc = proc_open([PHP_BINARY, '-r', $worker, $socket, $reply], [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
        self::assertIsResource($proc);
        $source = $this->memory('synthetic file');
        try {
            stream_set_timeout($pipes[1], 5);
            self::assertSame("ready\n", fgets($pipes[1]));
            $scanner = new ClamAvPersonnelScanner($socket, timeoutSeconds: 1);
            $this->rejected(fn () => $scanner->scan($source));
        } finally {
            fclose($source);
            fclose($pipes[1]);
            proc_terminate($proc);
            proc_close($proc);
        }
    }

    public static function badClamAvReplies(): array
    {
        return [['timeout'], ['stream: scanning error ERROR'], ['stream: UNRECOGNIZED']];
    }

    private function makeFolder(string $name): string
    {
        $path = $this->testRoot . '/' . $name;
        self::assertTrue(mkdir($path, 0700));

        return $path;
    }

    private function privateFile(string $name, string $bytes): string
    {
        $path = $this->testRoot . '/' . $name;
        $handle = PersonnelPrivatePath::createExclusive($path);
        PersonnelStreamCipher::writeAll($handle, $bytes);
        fclose($handle);

        return $path;
    }

    private function rejected(callable $callback): void
    {
        try {
            $callback();
            self::fail('Private operation unexpectedly accepted invalid input.');
        } catch (PersonnelStorageException $exception) {
            self::assertStringNotContainsString($this->testRoot, $exception->getMessage());
        }
    }

    /** @return resource */
    private function memory(string $bytes = '')
    {
        $stream = fopen('php://memory', 'w+b');
        PersonnelStreamCipher::writeAll($stream, $bytes);
        rewind($stream);

        return $stream;
    }

    private static function pdf(string $extraCatalogKeys = ''): string
    {
        $objects = [
            '<< /Type /Catalog /Pages 2 0 R ' . $extraCatalogKeys . ' >>',
            '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
            '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 120 120] /Resources << >> /Contents 4 0 R >>',
            "<< /Length 0 >>\nstream\nendstream",
        ];
        $document = "%PDF-1.4\n";
        $xref = "xref\n0 5\n0000000000 65535 f \n";
        foreach ($objects as $index => $object) {
            $xref .= sprintf('%010d 00000 n ', strlen($document)) . "\n";
            $document .= ($index + 1) . " 0 obj\n" . $object . "\nendobj\n";
        }
        return $document . $xref . "trailer\n<< /Size 5 /Root 1 0 R >>\nstartxref\n" . strlen($document) . "\n%%EOF\n";
    }
}
