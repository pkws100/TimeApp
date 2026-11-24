<?php

declare(strict_types=1);

use App\Domain\Push\PushSender;

require_once __DIR__ . '/../../bootstrap/autoload.php';

$override = (string) getenv('DB_OVERRIDE_FILE');
$log = (string) getenv('UI_APPOINTMENT_DISPATCH_LOG');
$database = (string) getenv('UI_APPOINTMENT_DISPATCH_DB');
$privateFile = static fn (string $path): bool => realpath($path) === $path
    && is_file($path) && (fileperms($path) & 0777) === 0600;
if (PHP_SAPI !== 'cli-server' || getenv('APP_ENV') !== 'test' || getenv('UI_APPOINTMENT_DISPATCH_FIXTURE') !== '1'
    || ($_SERVER['REMOTE_ADDR'] ?? '') !== '127.0.0.1'
    || dirname($override) !== sys_get_temp_dir() || preg_match('/\Atimeapp-ui-[a-f0-9]{12}-database\.php\z/', basename($override)) !== 1
    || $log !== substr($override, 0, -strlen('-database.php')) . '-appointment-push.jsonl'
    || !$privateFile($override) || !$privateFile($log)
    || preg_match('/\Atimeapp_ui_[a-f0-9]{12}\z/', $database) !== 1) {
    throw new RuntimeException('Ungültige isolierte UI-Testkonfiguration für Termin-Push.');
}
$databaseConfig = require $override;
if (($databaseConfig['database'] ?? null) !== $database) {
    throw new RuntimeException('Die Termin-Push-Testdatenbank stimmt nicht überein.');
}

$appointmentPushSenderOverride = new class($log) implements PushSender {
    public function __construct(private readonly string $log)
    {
    }

    public function isConfigured(): bool
    {
        return true;
    }

    public function send(array $subscriptionRow, array $message, ?string $dedupeKey = null, ?string $workDate = null): array
    {
        $result = match ($subscriptionRow['endpoint']) {
            'https://timeapp-push.example.test/accepted', 'https://timeapp-push.example.test/project-only' => 'accepted',
            'https://timeapp-push.example.test/rejected' => 'rejected',
            default => throw new LogicException('Unerwartetes Testgerät beim Termin-Push.'),
        };
        $entry = [
            'user_id' => (int) $subscriptionRow['user_id'],
            'subscription_id' => (int) $subscriptionRow['id'],
            'result' => $result,
            'url' => $message['url'],
            'is_cancellation' => str_starts_with($message['title'], 'Termin abgesagt'),
            'type' => $message['type'],
            'dedupe_key' => $dedupeKey,
        ];
        if (file_put_contents($this->log, json_encode($entry, JSON_THROW_ON_ERROR) . PHP_EOL, FILE_APPEND | LOCK_EX) === false) {
            throw new RuntimeException('Der Termin-Push-Testnachweis konnte nicht geschrieben werden.');
        }

        return $result === 'accepted' ? ['ok' => true] : ['ok' => false, 'definitive_not_accepted' => true];
    }
};

return require __DIR__ . '/php-server-router.php';
