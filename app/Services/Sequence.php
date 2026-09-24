<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Db;

/** Atomic counters for human-readable codes (safe under concurrency). */
final class Sequence
{
    public static function next(string $name, string $period = 'all'): int
    {
        if (Db::isSqlite()) {
            return (int) Db::transaction(function () use ($name, $period) {
                $row = Db::fetch('SELECT last_value FROM sequences WHERE name = ? AND period = ?', [$name, $period]);
                if ($row === null) {
                    Db::insert('sequences', ['name' => $name, 'period' => $period, 'last_value' => 1]);
                    return 1;
                }
                $next = (int) $row['last_value'] + 1;
                Db::update('sequences', ['last_value' => $next], ['name' => $name, 'period' => $period]);
                return $next;
            });
        }
        Db::query(
            'INSERT INTO sequences (name, period, last_value) VALUES (?, ?, LAST_INSERT_ID(1))
             ON DUPLICATE KEY UPDATE last_value = LAST_INSERT_ID(last_value + 1)',
            [$name, $period]
        );
        return (int) Db::value('SELECT LAST_INSERT_ID()');
    }
}
