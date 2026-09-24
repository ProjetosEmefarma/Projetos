<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Db;
use App\Core\HttpException;
use App\Core\Paginator;
use App\Core\Request;
use App\Core\Response;
use App\Services\ImageService;
use App\Services\ItemService;
use App\Support\AuditLabels;
use App\Support\Present;

final class ItemsController
{
    /**
     * GET /api/items?q=&category_id=&location_id=&supplier_id=&status=ativo|inativo
     *                &stock_level=ok|low|zero&trashed=1&sort=name|-available|code|updated_at
     */
    public function index(Request $request): Response
    {
        $where = [$request->queryBool('trashed') ? 'i.deleted_at IS NOT NULL' : 'i.deleted_at IS NULL'];
        $params = [];

        if (($q = (string) $request->query('q', '')) !== '') {
            $where[] = '(i.code LIKE ? OR i.name LIKE ? OR i.description LIKE ?)';
            array_push($params, Db::like($q), Db::like($q), Db::like($q));
        }
        foreach (['category_id', 'location_id', 'supplier_id'] as $filter) {
            if (($value = $request->queryInt($filter)) !== null) {
                $where[] = "i.{$filter} = ?";
                $params[] = $value;
            }
        }
        if (in_array($request->query('status'), ['ativo', 'inativo'], true)) {
            $where[] = 'i.status = ?';
            $params[] = $request->query('status');
        }
        $available = '(COALESCE(s.qty_on_hand, 0) - COALESCE(s.qty_reserved, 0))';
        match ($request->query('stock_level')) {
            'zero' => $where[] = "{$available} <= 0",
            'low' => $where[] = "i.min_stock > 0 AND {$available} > 0 AND {$available} <= i.min_stock",
            'ok' => $where[] = "{$available} > 0 AND {$available} > i.min_stock",
            'attention' => $where[] = "({$available} <= 0 OR (i.min_stock > 0 AND {$available} <= i.min_stock))",
            default => null,
        };

        $order = Paginator::orderBy($request, [
            'name' => 'i.name',
            'code' => 'i.code',
            'available' => $available,
            'on_hand' => 's.qty_on_hand',
            'unit_value' => 'i.unit_value',
            'updated_at' => 'i.updated_at',
            'created_at' => 'i.created_at',
        ], 'i.updated_at DESC, i.id DESC');

        [$rows, $meta] = Paginator::run(
            $request,
            ItemService::SELECT,
            ItemService::FROM . ' WHERE ' . implode(' AND ', $where),
            $params,
            $order
        );
        return Response::ok(array_map([Present::class, 'item'], $rows), $meta);
    }

    /** GET /api/items/options?q= - light list of ACTIVE items for pickers (max 50). */
    public function options(Request $request): Response
    {
        $params = [];
        $sql = 'SELECT i.id, i.code, i.name, i.unit_value, i.min_stock, i.photo_path,
                       COALESCE(s.qty_on_hand, 0) AS qty_on_hand, COALESCE(s.qty_reserved, 0) AS qty_reserved
                  FROM items i LEFT JOIN stock s ON s.item_id = i.id
                 WHERE i.deleted_at IS NULL AND i.status = \'ativo\'';
        if (($q = (string) $request->query('q', '')) !== '') {
            $sql .= ' AND (i.code LIKE ? OR i.name LIKE ?)';
            array_push($params, Db::like($q), Db::like($q));
        }
        $rows = Db::fetchAll($sql . ' ORDER BY i.name LIMIT 50', $params);
        return Response::ok(array_map(function (array $r) {
            $available = (int) $r['qty_on_hand'] - (int) $r['qty_reserved'];
            $version = $r['photo_path'] ? substr(md5((string) $r['photo_path']), 0, 8) : null;
            return [
                'id' => (int) $r['id'],
                'code' => $r['code'],
                'name' => $r['name'],
                'unit_value' => Present::money($r['unit_value']),
                'available' => $available,
                'level' => \App\Services\StockService::level($available, (int) $r['min_stock']),
                'thumb_url' => $version ? url('/api/items/' . $r['id'] . '/photo') . '?size=thumb&v=' . $version : null,
            ];
        }, $rows));
    }

    public function nextCode(Request $request): Response
    {
        return Response::ok(['code' => ItemService::previewCode()]);
    }

    public function show(Request $request): Response
    {
        return Response::ok(ItemService::find($request->id(), true));
    }

    public function store(Request $request): Response
    {
        return Response::created(ItemService::create($request->all()));
    }

    public function update(Request $request): Response
    {
        return Response::ok(ItemService::update($request->id(), $request->all()));
    }

    public function activate(Request $request): Response
    {
        return Response::ok(ItemService::setStatus($request->id(), 'ativo'));
    }

    public function deactivate(Request $request): Response
    {
        return Response::ok(ItemService::setStatus($request->id(), 'inativo'));
    }

    public function destroy(Request $request): Response
    {
        ItemService::softDelete($request->id());
        return Response::ok(['id' => $request->id(), 'deleted' => true]);
    }

    public function restore(Request $request): Response
    {
        return Response::ok(ItemService::restore($request->id()));
    }

    /** POST /api/items/{id}/purge {"confirm": "<item code>"} */
    public function purge(Request $request): Response
    {
        ItemService::purge($request->id(), $request->input('confirm'));
        return Response::ok(['id' => $request->id(), 'purged' => true]);
    }

    /** POST multipart/form-data with field "photo" (JPG/PNG/WEBP up to 5 MB). */
    public function uploadPhoto(Request $request): Response
    {
        $file = $request->file('photo');
        if ($file === null) {
            throw HttpException::validation(['photo' => 'Selecione uma imagem.']);
        }
        return Response::ok(ItemService::setPhoto($request->id(), $file));
    }

    public function deletePhoto(Request $request): Response
    {
        return Response::ok(ItemService::removePhoto($request->id()));
    }

    /** GET /api/items/{id}/photo?size=thumb */
    public function photo(Request $request): Response
    {
        $row = Db::fetch('SELECT photo_path, thumb_path FROM items WHERE id = ?', [$request->id()]);
        $path = $request->query('size') === 'thumb' ? ($row['thumb_path'] ?? null) : ($row['photo_path'] ?? null);
        if (!$path || !is_file(ImageService::absolute($path))) {
            throw HttpException::notFound('Foto não encontrada.');
        }
        return Response::file(ImageService::absolute($path), 'image/jpeg', 86400);
    }

    /**
     * GET /api/items/{id}/history - full timeline of the item:
     * stock movements + register changes (audit), newest first, paginated.
     */
    public function history(Request $request): Response
    {
        $id = $request->id();
        ItemService::find($id, true);

        $union = "FROM (
            SELECT 'movement' AS kind, m.id, m.created_at AS at, m.user_id, u.name AS user_name,
                   m.type AS action, m.qty, m.balance_after, m.reason, m.purpose, m.recipient,
                   m.purchase_ticket_no, m.notes, NULL AS before_json, NULL AS after_json
              FROM stock_movements m JOIN users u ON u.id = m.user_id
             WHERE m.item_id = ?
            UNION ALL
            SELECT 'change' AS kind, a.id, a.created_at AS at, a.user_id, a.user_name,
                   a.action, NULL, NULL, NULL, NULL, NULL, NULL, NULL, a.before_json, a.after_json
              FROM audit_log a
             WHERE a.entity_type = 'item' AND a.entity_id = ? AND a.action NOT IN ('stock_entrada', 'stock_saida', 'stock_ajuste')
        ) t";

        [$rows, $meta] = Paginator::run($request, 'SELECT t.*', $union, [$id, $id], 'ORDER BY t.at DESC, t.id DESC', 30);

        $out = array_map(function (array $r) {
            $isMovement = $r['kind'] === 'movement';
            return [
                'kind' => $r['kind'],
                'id' => (int) $r['id'],
                'at' => $r['at'],
                'user' => ['id' => $r['user_id'] !== null ? (int) $r['user_id'] : null, 'name' => $r['user_name']],
                'action' => $r['action'],
                'action_label' => $isMovement
                    ? (\App\Services\StockService::TYPE_LABELS[$r['action']] ?? $r['action'])
                    : AuditLabels::action($r['action']),
                'quantity' => $isMovement ? (int) $r['qty'] : null,
                'balance_after' => $isMovement ? (int) $r['balance_after'] : null,
                'reason' => $r['reason'],
                'purpose' => $r['purpose'],
                'recipient' => $r['recipient'],
                'purchase_ticket_no' => $r['purchase_ticket_no'],
                'notes' => $r['notes'],
                'before' => $r['before_json'] ? json_decode($r['before_json'], true) : null,
                'after' => $r['after_json'] ? json_decode($r['after_json'], true) : null,
            ];
        }, $rows);

        return Response::ok($out, $meta);
    }
}
