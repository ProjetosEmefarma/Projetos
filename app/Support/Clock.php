<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Source of "now" for the whole app. Can be frozen by the demo seeder and tests
 * (never in production code paths).
 */
final class Clock
{
    private static ?int $fixed = null;

    public static function set(?string $datetime): void
    {
        self::$fixed = $datetime === null ? null : (int) strtotime($datetime);
    }

    public static function timestamp(): int
    {
        return self::$fixed ?? time();
    }

    public static function now(): string
    {
        return date('Y-m-d H:i:s', self::timestamp());
    }

    public static function today(): string
    {
        return date('Y-m-d', self::timestamp());
    }
}
