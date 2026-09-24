<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Db;
use App\Core\Request;
use App\Core\Response;

final class SearchController
{
    public function show(Request $request): Response
    {
        $q = trim((string) $request->query('q', ''));
        if (mb_strlen($q) < 2) {
            return Response::ok(['items' => [], 'requests' => [], 'deliveries' => [], 'industries' => []]);
        }
        $like = Db::like($q);
        $items = Db::fetchAll(
            'SELECT id, code, name FROM items WHERE deleted_at IS NULL AND (code LIKE ? OR name LIKE ?) ORDER BY name LIMIT 8',
            [$like, $like]
        );
        $requests = Db::fetchAll(
            'SELECT id, code, purpose, status FROM requests WHERE deleted_at IS NULL AND (code LIKE ? OR purpose LIKE ? OR recipient LIKE ?) ORDER BY id DESC LIMIT 8',
            [$like, $like, $like]
        );
        $deliveries = Db::fetchAll(
            'SELECT id, code, received_by_name FROM deliveries WHERE code LIKE ? OR received_by_name LIKE ? ORDER BY id DESC LIMIT 8',
            [$like, $like]
        );
        $industries = Db::fetchAll(
            'SELECT id, name FROM industries WHERE deleted_at IS NULL AND name LIKE ? ORDER BY name LIMIT 8',
            [$like]
        );
        return Response::ok([
            'items' => array_map(fn ($r) => ['id' => (int) $r['id'], 'code' => $r['code'], 'name' => $r['name']], $items),
            'requests' => array_map(fn ($r) => ['id' => (int) $r['id'], 'code' => $r['code'], 'purpose' => $r['purpose'], 'status' => $r['status']], $requests),
            'deliveries' => array_map(fn ($r) => ['id' => (int) $r['id'], 'code' => $r['code'], 'name' => $r['received_by_name']], $deliveries),
            'industries' => array_map(fn ($r) => ['id' => (int) $r['id'], 'name' => $r['name']], $industries),
        ]);
    }
}
