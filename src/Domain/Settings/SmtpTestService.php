<?php

declare(strict_types=1);

namespace App\Domain\Settings;

use RuntimeException;

final class SmtpTestService
{
    public function sendTestMail(array $settings, string $recipient): array
    {
        $recipient = trim($recipient);

        if ($recipient === '') {
            return ['ok' => false, 'message' => 'Bitte eine Empfaenger-E-Mail fuer den SMTP-Test angeben.'];
        }

        if (filter_var($recipient, FILTER_VALIDATE_EMAIL) === false) {
            return ['ok' => false, 'message' => 'Bitte eine gueltige Empfaenger-E-Mail fuer den SMTP-Test angeben.'];
        }

        $host = trim((string) ($settings['smtp_host'] ?? ''));
        $fromEmail = trim((string) ($settings['smtp_from_email'] ?? ''));
        $fromName = trim((string) ($settings['smtp_from_name'] ?? ''));
        $replyTo = trim((string) ($settings['smtp_reply_to_email'] ?? ''));

        if ($host === '' || $fromEmail === '') {
            return ['ok' => false, 'message' => 'SMTP Host und Absender-E-Mail muessen zuerst gespeichert werden.'];
        }

        if (filter_var($fromEmail, FILTER_VALIDATE_EMAIL) === false) {
            return ['ok' => false, 'message' => 'Die gespeicherte Absender-E-Mail ist ungueltig.'];
        }

        if ($replyTo !== '' && filter_var($replyTo, FILTER_VALIDATE_EMAIL) === false) {
            return ['ok' => false, 'message' => 'Die gespeicherte Reply-To-E-Mail ist ungueltig.'];
        }

        try {
            $socket = $this->openSocket($settings);
            $this->expect($socket, [220]);
            $this->command($socket, 'EHLO zeiterfassung.local', [250]);

            $encryption = strtolower(trim((string) ($settings['smtp_encryption'] ?? '')));

            if ($encryption === 'tls') {
                $this->command($socket, 'STARTTLS', [220]);

                if (!stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                    throw new RuntimeException('STARTTLS konnte nicht aktiviert werden.');
                }

                $this->command($socket, 'EHLO zeiterfassung.local', [250]);
            }

            $username = trim((string) ($settings['smtp_username'] ?? ''));
            $password = (string) ($settings['smtp_password'] ?? '');

            if ($username !== '') {
                $this->command($socket, 'AUTH LOGIN', [334]);
                $this->command($socket, base64_encode($username), [334]);
                $this->command($socket, base64_encode($password), [235]);
            }

            $this->command($socket, 'MAIL FROM:<' . $fromEmail . '>', [250]);
            $this->command($socket, 'RCPT TO:<' . $recipient . '>', [250, 251]);
            $this->command($socket, 'DATA', [354]);

            $subject = 'SMTP Test aus Baustellen-Zeiterfassung';
            $bodyLines = array_filter([
                'From: ' . ($fromName !== '' ? $fromName . ' ' : '') . '<' . $fromEmail . '>',
                'To: <' . $recipient . '>',
                $replyTo !== '' ? 'Reply-To: <' . $replyTo . '>' : null,
                'Subject: ' . $subject,
                'Date: ' . gmdate(DATE_RFC2822),
                'Content-Type: text/plain; charset=UTF-8',
                '',
                'Dies ist eine Test-E-Mail aus dem Settings-Bereich der Baustellen-Zeiterfassung.',
                'Wenn Sie diese Nachricht erhalten, funktioniert die hinterlegte SMTP-Konfiguration.',
            ], static fn (?string $line): bool => $line !== null);
            $body = preg_replace("/(^|\\r\\n)\\./", '$1..', implode("\r\n", $bodyLines)) ?? '';
            fwrite($socket, $body . "\r\n.\r\n");
            $this->expect($socket, [250]);
            $this->command($socket, 'QUIT', [221]);
            fclose($socket);

            return ['ok' => true, 'message' => 'SMTP-Testmail wurde erfolgreich versendet.'];
        } catch (RuntimeException $exception) {
            return ['ok' => false, 'message' => $exception->getMessage()];
        }
    }

    private function openSocket(array $settings)
    {
        $host = trim((string) ($settings['smtp_host'] ?? ''));
        $port = (int) ($settings['smtp_port'] ?? 587);
        $encryption = strtolower(trim((string) ($settings['smtp_encryption'] ?? '')));
        $transport = $encryption === 'ssl' ? 'ssl://' : '';
        $errorNumber = 0;
        $errorMessage = '';

        $socket = @stream_socket_client(
            $transport . $host . ':' . $port,
            $errorNumber,
            $errorMessage,
            10
        );

        if (!is_resource($socket)) {
            throw new RuntimeException('SMTP-Verbindung fehlgeschlagen: ' . ($errorMessage !== '' ? $errorMessage : 'Unbekannter Fehler'));
        }

        stream_set_timeout($socket, 10);

        return $socket;
    }

    private function command($socket, string $command, array $expectedCodes): string
    {
        fwrite($socket, $command . "\r\n");

        return $this->expect($socket, $expectedCodes);
    }

    private function expect($socket, array $expectedCodes): string
    {
        $response = '';

        while (($line = fgets($socket, 515)) !== false) {
            $response .= $line;

            if (strlen($line) < 4 || $line[3] !== '-') {
                break;
            }
        }

        if ($response === '') {
            throw new RuntimeException('Der SMTP-Server hat nicht geantwortet.');
        }

        $code = (int) substr($response, 0, 3);

        if (!in_array($code, $expectedCodes, true)) {
            $message = $code > 0
                ? 'SMTP-Fehler (Code ' . $code . '). Bitte Server, Zugangsdaten und Verschluesselung pruefen.'
                : 'SMTP-Fehler. Bitte Server, Zugangsdaten und Verschluesselung pruefen.';

            throw new RuntimeException($message);
        }

        return $response;
    }
}
