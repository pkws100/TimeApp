<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\Settings\SmtpTestService;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use RuntimeException;

final class SmtpTestServiceTest extends TestCase
{
    public function testSmtpErrorDoesNotPersistRawServerResponse(): void
    {
        $service = new SmtpTestService();
        $stream = fopen('php://temp', 'r+');

        self::assertIsResource($stream);

        fwrite($stream, "535 5.7.8 reflected-secret-token\r\n");
        rewind($stream);

        $method = new ReflectionMethod($service, 'expect');
        $method->setAccessible(true);

        try {
            $method->invoke($service, $stream, [235]);
            self::fail('Expected SMTP error.');
        } catch (RuntimeException $exception) {
            self::assertStringContainsString('SMTP-Fehler (Code 535)', $exception->getMessage());
            self::assertStringNotContainsString('reflected-secret-token', $exception->getMessage());
        } finally {
            fclose($stream);
        }
    }

    public function testMailboxHeaderOmitsDuplicateEmailDisplayName(): void
    {
        $header = $this->mailboxHeader('zeiterfassung@example.test', 'zeiterfassung@example.test');

        self::assertSame('<zeiterfassung@example.test>', $header);
    }

    public function testMailboxHeaderQuotesDisplayName(): void
    {
        $header = $this->mailboxHeader('zeiterfassung@example.test', 'HTD Son');

        self::assertSame('"HTD Son" <zeiterfassung@example.test>', $header);
    }

    public function testMailboxHeaderRemovesControlCharactersFromDisplayName(): void
    {
        $header = $this->mailboxHeader('zeiterfassung@example.test', "HTD\r\nBcc: hidden@example.test");

        self::assertSame('"HTD Bcc: hidden@example.test" <zeiterfassung@example.test>', $header);
    }

    private function mailboxHeader(string $email, string $displayName): string
    {
        $service = new SmtpTestService();
        $method = new ReflectionMethod($service, 'mailboxHeader');
        $method->setAccessible(true);

        return (string) $method->invoke($service, $email, $displayName);
    }
}
