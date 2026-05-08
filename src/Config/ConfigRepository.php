<?php

declare(strict_types=1);

namespace App\Config;

final class ConfigRepository
{
    public function __construct(private array $items)
    {
    }

    public static function load(array $names): self
    {
        $items = [];

        foreach ($names as $name) {
            $items[$name] = require config_path($name . '.php');
        }

        return new self($items);
    }

    public function get(string $key, mixed $default = null): mixed
    {
        $segments = explode('.', $key);
        $value = $this->items;

        foreach ($segments as $segment) {
            if (!is_array($value) || !array_key_exists($segment, $value)) {
                return $default;
            }

            $value = $value[$segment];
        }

        return $value;
    }
}

