<?php

declare(strict_types=1);

namespace App\Http;

final class Request
{
    public function __construct(
        private string $method,
        private string $path,
        private array $query,
        private array $parsedBody,
        private array $headers,
        private array $files,
        private array $server
    ) {
    }

    public static function capture(): self
    {
        $uri = $_SERVER['REQUEST_URI'] ?? '/';
        $path = parse_url($uri, PHP_URL_PATH) ?: '/';
        $method = self::detectMethod();
        $headers = function_exists('getallheaders') ? getallheaders() : [];
        $parsedBody = self::parseBody($method);

        return new self(
            $method,
            $path,
            $_GET,
            $parsedBody,
            $headers,
            $_FILES,
            $_SERVER
        );
    }

    private static function detectMethod(): string
    {
        $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');

        if ($method !== 'POST') {
            return $method;
        }

        $override = $_POST['_method'] ?? ($_SERVER['HTTP_X_HTTP_METHOD_OVERRIDE'] ?? null);

        if (!is_string($override)) {
            return $method;
        }

        $override = strtoupper(trim($override));

        return in_array($override, ['PUT', 'PATCH', 'DELETE'], true) ? $override : $method;
    }

    private static function parseBody(string $method): array
    {
        if ($method === 'GET') {
            return [];
        }

        $contentType = $_SERVER['CONTENT_TYPE'] ?? '';

        if (str_contains($contentType, 'application/json')) {
            $raw = file_get_contents('php://input') ?: '';
            $decoded = json_decode($raw, true);

            return is_array($decoded) ? $decoded : [];
        }

        return $_POST;
    }

    public function method(): string
    {
        return $this->method;
    }

    public function path(): string
    {
        return $this->path;
    }

    public function query(string $key = null, mixed $default = null): mixed
    {
        if ($key === null) {
            return $this->query;
        }

        return $this->query[$key] ?? $default;
    }

    public function input(string $key = null, mixed $default = null): mixed
    {
        if ($key === null) {
            return $this->parsedBody;
        }

        return $this->parsedBody[$key] ?? $default;
    }

    public function files(): array
    {
        return $this->files;
    }

    public function header(string $key, mixed $default = null): mixed
    {
        return $this->headers[$key] ?? $default;
    }

    public function server(string $key, mixed $default = null): mixed
    {
        return $this->server[$key] ?? $default;
    }
}
