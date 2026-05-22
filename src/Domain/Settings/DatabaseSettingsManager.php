<?php

declare(strict_types=1);

namespace App\Domain\Settings;

final class DatabaseSettingsManager
{
    private const ALLOWED_KEYS = ['driver', 'host', 'port', 'database', 'username', 'password', 'charset', 'collation', 'socket'];

    public function __construct(private array $defaults, private string $overrideFile)
    {
    }

    public function current(): array
    {
        $override = [];

        if (is_file($this->overrideFile)) {
            $data = require $this->overrideFile;
            $override = is_array($data) ? $data : [];
        }

        return [...$this->defaults, ...$override];
    }

    public function currentForOutput(): array
    {
        return $this->sanitizeForOutput($this->current());
    }

    public function save(array $payload): array
    {
        $sanitized = $this->sanitize($payload);
        $dir = dirname($this->overrideFile);

        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }

        $content = "<?php\n\nreturn " . var_export($sanitized, true) . ";\n";
        file_put_contents($this->overrideFile, $content);

        return $sanitized;
    }

    public function saveForOutput(array $payload): array
    {
        return $this->sanitizeForOutput($this->save($payload));
    }

    public function sanitize(array $payload): array
    {
        $data = [];
        $current = $this->current();

        foreach (self::ALLOWED_KEYS as $key) {
            $data[$key] = trim((string) ($payload[$key] ?? $this->defaults[$key] ?? ''));
        }

        if (($payload['password'] ?? '') === '') {
            $data['password'] = trim((string) ($current['password'] ?? ''));
        }

        $data['port'] = (int) ($data['port'] !== '' ? $data['port'] : 3306);

        return $data;
    }

    public function overrideFile(): string
    {
        return $this->overrideFile;
    }

    private function sanitizeForOutput(array $settings): array
    {
        $settings['password_is_set'] = trim((string) ($settings['password'] ?? '')) !== '';
        $settings['password'] = '';

        return $settings;
    }
}
