<?php

declare(strict_types=1);

namespace App\Core;

final class Config
{
    private static array $items = [];

    public static function load(string $file): void
    {
        self::$items = require $file;
    }

    /** Dot-notation access: Config::get('db.host'). */
    public static function get(string $key, mixed $default = null): mixed
    {
        $value = self::$items;
        foreach (explode('.', $key) as $segment) {
            if (!is_array($value) || !array_key_exists($segment, $value)) {
                return $default;
            }
            $value = $value[$segment];
        }
        return $value;
    }

    public static function set(string $key, mixed $value): void
    {
        $ref = &self::$items;
        foreach (explode('.', $key) as $segment) {
            if (!isset($ref[$segment]) || !is_array($ref[$segment])) {
                $ref[$segment] = [];
            }
            $ref = &$ref[$segment];
        }
        $ref = $value;
    }

    /** URL path prefix when installed in a sub-folder ('' at domain root). */
    public static function basePath(): string
    {
        $path = parse_url((string) self::get('app.url', ''), PHP_URL_PATH) ?: '';
        return rtrim($path, '/');
    }

    public static function isTesting(): bool
    {
        return self::get('app.env') === 'testing';
    }
}
