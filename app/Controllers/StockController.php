<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Db;
use App\Core\Paginator;
use App\Core\Request;
use App\Core\Response;
use App\Core\Validator;
use App\Services\ExitOrderService;
use App\Services\StockService;
use App\Support\Present;

final class StockController
{
    /**
     * GET /api/stock/movements?item_id=&type=entrada|saida|ajuste&from=YYYY-MM-DD&to=YYYY-MM-DD
     *                         &user_id=&industry_id=&department_id=&q=&sort=-created_at
     */
    public function movements(Request $request): Response
    {
        [$where, $params] = self::filters($request);
        $order = Paginator::orderBy($request, ['created_at' => 'm.created_at', 'item' => 'i.name', 'qty' => 'm.qty'], 'm.created_at DESC');
        [$rows, $meta] = Paginator::run(
            $request,
            StockService::MOVEMENT_SELECT,
            StockService::MOVEMENT_FROM . ($where ? ' WHERE ' . implode(' AND ', $where) : ''),
            $params,
            $order . ', m.id DESC'
        );
        return Response::ok(array_map([Present::class, 'movement'], $rows), $meta);
    }

    public function showMovement(Request $request): Response
    {
        return Response::ok(StockService::findMovement($request->id()));
    }

    /** GET /api/stock/summary - current stock totals (not period based). */
    public function summary(Request $request): Response
    {
        // Auto heal missing movement entries or stock positions before computing summary
        StockService::reconcileAll();

        $row = Db::fetch(
            "SELECT COUNT(*) AS items_active,
                    COALESCE(SUM(s.qty_on_hand), 0) AS units_on_hand,
                    COALESCE(SUM(s.qty_reserved), 0) AS units_reserved,
                    COALESCE(SUM(s.qty_on_hand * COALESCE(i.unit_value, 0)), 0) AS stock_value,
                    COALESCE(SUM(CASE WHEN s.qty_on_hand - s.qty_reserved <= 0 THEN 1 ELSE 0 END), 0) AS zero_stock,
                    COALESCE(SUM(CASE WHEN i.min_stock > 0 AND s.qty_on_hand - s.qty_reserved > 0
                                       AND s.qty_on_hand - s.qty_reserved <= i.min_stock THEN 1 ELSE 0 END), 0) AS low_stock
               FROM items i JOIN stock s ON s.item_id = i.id
              WHERE i.deleted_at IS NULL AND i.status = 'ativo'"
        );
        return Response::ok([
            'items_active' => (int) $row['items_active'],
            'units_on_hand' => (int) $row['units_on_hand'],
            'units_reserved' => (int) $row['units_reserved'],
            'units_available' => (int) $row['units_on_hand'] - (int) $row['units_reserved'],
            'stock_value' => round((float) $row['stock_value'], 2),
            'low_stock' => (int) $row['low_stock'],
            'zero_stock' => (int) $row['zero_stock'],
        ]);
    }

    /** POST /api/stock/reconcile - Manual reconciliation trigger for administrators. */
    public function reconcile(Request $request): Response
    {
        $result = StockService::reconcileAll();
        return Response::ok($result);
    }

    /** POST /api/stock/entries  (header Idempotency-Key required) */
    public function entry(Request $request): Response
    {
        $data = Validator::validate($request->all(), [
            'item_id' => 'required|int|exists:items',
            'quantity' => 'required|int|min:1|max:1000000',
            'unit_value' => 'nullable|numeric|min:0|max:9999999999',
            'supplier_id' => 'nullable|int|exists:suppliers',
            'industry_id' => 'nullable|int|exists:industries',
            'purchase_ticket_no' => 'nullable|string|max:50',
            'document_ref' => 'nullable|string|max:60',
            'notes' => 'nullable|string|max:1000',
        ]);
        $data = self::withNota($request, $data);
        $result = StockService::entry($data['item_id'], $data['quantity'], $data);
        return Response::created($result);
    }

    /** POST /api/stock/exits  (header Idempotency-Key required) */
    public function exit(Request $request): Response
    {
        $data = Validator::validate($request->all(), [
            'item_id' => 'required|int|exists:items',
            'quantity' => 'required|int|min:1|max:1000000',
            'purpose' => 'required|string|max:255',
            'recipient' => 'nullable|string|max:150',
            'industry_id' => 'nullable|int|exists:industries',
            'department_id' => 'nullable|int|exists:departments',
            'requester_id' => 'nullable|int|exists:users',
            'purchase_ticket_no' => 'nullable|string|max:50',
            'document_ref' => 'nullable|string|max:60',
            'notes' => 'nullable|string|max:1000',
        ]);
        $data = self::withNota($request, $data);
        $result = ExitOrderService::authorize($data['item_id'], $data['quantity'], $data);
        return Response::created($result);
    }

    public function exitOrders(Request $request): Response
    {
        $status = (string) $request->query('status', 'autorizada');
        return Response::ok(ExitOrderService::list($status));
    }

    public function showExitOrder(Request $request): Response
    {
        return Response::ok(ExitOrderService::find($request->id()));
    }

    public function lookupExitOrder(Request $request): Response
    {
        $code = (string) ($request->query('code', '') ?: $request->input('code', ''));
        return Response::ok(ExitOrderService::lookup($code));
    }

    public function exitOrderQr(Request $request): Response
    {
        $png = ExitOrderService::qrPng($request->id());
        return new Response(200, [
            'Content-Type' => 'image/png',
            'Content-Length' => (string) strlen($png),
            'Cache-Control' => 'private, max-age=120',
            'X-Content-Type-Options' => 'nosniff',
        ], $png);
    }

    public function confirmExit(Request $request): Response
    {
        return Response::ok(ExitOrderService::confirm($request->id()));
    }

    public function exitOrderAttachment(Request $request): Response
    {
        $row = ExitOrderService::attachment($request->id());
        $path = \App\Services\ImageService::absolute((string) $row['attachment_path']);
        if (!is_file($path)) {
            throw \App\Core\HttpException::notFound('Arquivo da nota não encontrado.');
        }
        $ext = pathinfo($path, PATHINFO_EXTENSION);
        $name = 'NF-' . ($row['document_ref'] ?: $row['code']) . '.' . $ext;
        return Response::file($path, \App\Services\DocumentService::mime($path), 0, $name);
    }

    /**
     * POST /api/stock/adjustments  (header Idempotency-Key required)
     * mode=set   -> quantity is the counted balance (inventory count)
     * mode=delta -> quantity is +/- units
     */
    public function adjust(Request $request): Response
    {
        $data = Validator::validate($request->all(), [
            'item_id' => 'required|int|exists:items',
            'mode' => 'required|in:set,delta',
            'quantity' => 'required|int|min:-1000000|max:1000000',
            'reason' => 'required|string|min:3|max:255',
            'notes' => 'nullable|string|max:1000',
        ]);
        if ($data['mode'] === 'set' && $data['quantity'] < 0) {
            throw \App\Core\HttpException::validation(['quantity' => 'A contagem não pode ser negativa.']);
        }
        if ($data['mode'] === 'delta' && $data['quantity'] === 0) {
            throw \App\Core\HttpException::validation(['quantity' => 'Informe uma quantidade diferente de zero.']);
        }
        $result = StockService::adjust($data['item_id'], $data['mode'], $data['quantity'], $data['reason'], ['notes' => $data['notes'] ?? null]);
        return Response::created($result);
    }

    public function attachment(Request $request): Response
    {
        $movement = StockService::findMovement($request->id());
        $row = Db::fetch('SELECT attachment_path, document_ref FROM stock_movements WHERE id = ?', [$request->id()]);
        if ($row === null || empty($row['attachment_path'])) {
            throw \App\Core\HttpException::notFound('Não há nota anexada nesta movimentação.');
        }
        $path = \App\Services\ImageService::absolute((string) $row['attachment_path']);
        if (!is_file($path)) {
            throw \App\Core\HttpException::notFound('Arquivo da nota não encontrado.');
        }
        $ext = pathinfo($path, PATHINFO_EXTENSION);
        $name = 'NF-' . ($row['document_ref'] ?: $movement['id']) . '.' . $ext;
        return Response::file($path, \App\Services\DocumentService::mime($path), 0, $name);
    }

    /** @param array<string,mixed> $data */
    private static function withNota(Request $request, array $data): array
    {
        $file = $request->file('invoice') ?? $request->file('nota');
        if ($file !== null) {
            $data['attachment_path'] = \App\Services\DocumentService::storeNota($file, 'invoice');
        }
        return $data;
    }

    /** Shared movement filters (also used by the dashboard, which sets its own period). */
    public static function filters(Request $request, bool $withPeriod = true): array
    {
        $where = [];
        $params = [];
        foreach (['item_id' => 'm.item_id', 'user_id' => 'm.user_id', 'industry_id' => 'm.industry_id',
                  'department_id' => 'm.department_id', 'requester_id' => 'm.requester_id',
                  'event_id' => 'm.event_id', 'request_id' => 'm.request_id', 'category_id' => 'i.category_id'] as $key => $column) {
            if (($value = $request->queryInt($key)) !== null) {
                $where[] = "{$column} = ?";
                $params[] = $value;
            }
        }
        if (in_array($request->query('type'), ['entrada', 'saida', 'ajuste', 'transferencia', 'estorno'], true)) {
            $where[] = 'm.type = ?';
            $params[] = $request->query('type');
        }
        if ($iid = \App\Core\Auth::industryId()) {
            $where[] = 'm.industry_id = ?';
            $params[] = $iid;
        }
        if ($withPeriod && self::isDate($from = (string) $request->query('from', ''))) {
            $where[] = 'm.created_at >= ?';
            $params[] = $from . ' 00:00:00';
        }
        if ($withPeriod && self::isDate($to = (string) $request->query('to', ''))) {
            $where[] = 'm.created_at <= ?';
            $params[] = $to . ' 23:59:59';
        }
        if (($q = (string) $request->query('q', '')) !== '') {
            $where[] = '(i.code LIKE ? OR i.name LIKE ? OR m.recipient LIKE ? OR m.purpose LIKE ? OR m.purchase_ticket_no LIKE ? OR m.document_ref LIKE ?)';
            array_push($params, ...array_fill(0, 6, Db::like($q)));
        }
        return [$where, $params];
    }

    public static function isDate(string $value): bool
    {
        $d = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        return $d !== false && $d->format('Y-m-d') === $value;
    }
}
