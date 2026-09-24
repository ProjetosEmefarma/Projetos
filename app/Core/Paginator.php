<?php

declare(strict_types=1);

namespace App\Core;

final class Paginator
{
    /**
     * Runs "SELECT ... {$from}" with LIMIT/OFFSET plus a COUNT(*) on the same FROM/WHERE.
     *
     * @param string $select  e.g. "SELECT i.*, c.name AS category_name"
     * @param string $from    e.g. "FROM items i JOIN ... WHERE ..." (no ORDER BY)
     * @param string $orderBy e.g. "ORDER BY i.name ASC"
     * @return array{0: array, 1: array} [rows, meta]
     */
    public static function run(Request $request, string $select, string $from, array $params, string $orderBy, int $default = 25): array
    {
        $page = max(1, (int) ($request->query('page') ?: 1));
        $perPage = max(1, min(100, (int) ($request->query('per_page') ?: $default)));
        $total = (int) Db::value("SELECT COUNT(*) {$from}", $params);
        $rows = Db::fetchAll(
            "{$select} {$from} {$orderBy} LIMIT ? OFFSET ?",
            [...$params, $perPage, ($page - 1) * $perPage]
        );
        return [$rows, self::meta($page, $perPage, $total)];
    }

    public static function meta(int $page, int $perPage, int $total): array
    {
        return [
            'page' => $page,
            'per_page' => $perPage,
            'total' => $total,
            'last_page' => max(1, (int) ceil($total / $perPage)),
        ];
    }

    /**
     * Whitelisted sort: ?sort=name or ?sort=-name (desc).
     * @param array<string,string> $allowed public name => SQL column
     */
    public static function orderBy(Request $request, array $allowed, string $default): string
    {
        $sort = (string) $request->query('sort', '');
        $desc = str_starts_with($sort, '-');
        $key = ltrim($sort, '-');
        if (!isset($allowed[$key])) {
            return 'ORDER BY ' . $default;
        }
        return 'ORDER BY ' . $allowed[$key] . ($desc ? ' DESC' : ' ASC');
    }
}
