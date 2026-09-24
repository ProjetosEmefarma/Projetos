<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Db;
use App\Core\Request;
use App\Core\Response;
use App\Services\StockService;
use App\Support\Present;

/**
 * GET /api/dashboard?from=YYYY-MM-DD&to=YYYY-MM-DD&category_id=&item_id=&department_id=&industry_id=&user_id=
 * Period defaults to the current month. Blocks the user cannot see are returned as null.
 * Step 3 adds request-based consumption; the response shape stays the same.
 */
final class DashboardController
{
    private const OPEN_STATUSES = "'solicitada','aguardando_aprovacao','aprovada','em_separacao','pronta'";

    public function show(Request $request): Response
    {
        $from = StockController::isDate((string) $request->query('from', '')) ? (string) $request->query('from') : date('Y-m-01');
        $to = StockController::isDate((string) $request->query('to', '')) ? (string) $request->query('to') : today();
        if ($from > $to) {
            [$from, $to] = [$to, $from];
        }
        $canStock = Auth::can('stock.view');

        return Response::ok([
            'period' => ['from' => $from, 'to' => $to],
            'stock' => $this->stock($canStock),
            'requests' => $this->requests($from, $to),
            'period_totals' => $canStock ? $this->periodTotals($request, $from, $to) : null,
            'series' => $canStock ? $this->series($request, $from, $to) : null,
            'top_items' => $canStock ? $this->topItems($request, $from, $to) : null,
            'by_department' => $canStock ? $this->groupedExits($request, $from, $to, 'department') : null,
            'by_industry' => $canStock ? $this->groupedExits($request, $from, $to, 'industry') : null,
            'alerts' => $this->alerts(),
            'recent_movements' => $canStock ? $this->recentMovements($request) : null,
            'trade' => \App\Services\TradeService::dashboardExtra(),
        ]);
    }

    private function stock(bool $withValue): array
    {
        $row = Db::fetch(
            "SELECT COUNT(*) AS items_active,
                    COALESCE(SUM(s.qty_on_hand), 0) AS on_hand,
                    COALESCE(SUM(s.qty_reserved), 0) AS reserved,
                    COALESCE(SUM(s.qty_on_hand * COALESCE(i.unit_value, 0)), 0) AS value,
                    COALESCE(SUM(CASE WHEN s.qty_on_hand - s.qty_reserved <= 0 THEN 1 ELSE 0 END), 0) AS zero_stock,
                    COALESCE(SUM(CASE WHEN i.min_stock > 0 AND s.qty_on_hand - s.qty_reserved > 0
                                       AND s.qty_on_hand - s.qty_reserved <= i.min_stock THEN 1 ELSE 0 END), 0) AS low_stock
               FROM items i JOIN stock s ON s.item_id = i.id
              WHERE i.deleted_at IS NULL AND i.status = 'ativo'"
        );
        return [
            'items_active' => (int) $row['items_active'],
            'units_on_hand' => (int) $row['on_hand'],
            'units_reserved' => (int) $row['reserved'],
            'units_available' => (int) $row['on_hand'] - (int) $row['reserved'],
            'stock_value' => $withValue ? round((float) $row['value'], 2) : null,
            'low_stock' => (int) $row['low_stock'],
            'zero_stock' => (int) $row['zero_stock'],
        ];
    }

    /** Request counters, scoped to what the user may see. */
    private function requests(string $from, string $to): array
    {
        [$scope, $params] = $this->requestScope();
        $row = Db::fetch(
            "SELECT COALESCE(SUM(CASE WHEN r.status IN (" . self::OPEN_STATUSES . ") THEN 1 ELSE 0 END), 0) AS pending,
                    COALESCE(SUM(CASE WHEN r.status = 'aguardando_aprovacao' THEN 1 ELSE 0 END), 0) AS awaiting_approval,
                    COALESCE(SUM(CASE WHEN r.status IN ('aprovada','em_separacao') THEN 1 ELSE 0 END), 0) AS to_prepare,
                    COALESCE(SUM(CASE WHEN r.status = 'pronta' THEN 1 ELSE 0 END), 0) AS ready,
                    COALESCE(SUM(CASE WHEN r.status = 'finalizada' AND r.finalized_at BETWEEN ? AND ? THEN 1 ELSE 0 END), 0) AS finalized_period
               FROM requests r WHERE r.deleted_at IS NULL AND r.status <> 'rascunho' {$scope}",
            [$from . ' 00:00:00', $to . ' 23:59:59', ...$params]
        );
        return array_map('intval', $row);
    }

    private function requestScope(): array
    {
        if ($iid = Auth::industryId()) {
            return ['AND r.industry_id = ?', [$iid]];
        }
        if (Auth::can('requests.view_all')) {
            return ['', []];
        }
        $user = Auth::user();
        if (Auth::can('requests.view_department') && !empty($user['department_id'])) {
            return ['AND (r.department_id = ? OR r.requester_id = ?)', [(int) $user['department_id'], (int) $user['id']]];
        }
        return ['AND r.requester_id = ?', [(int) $user['id']]];
    }

    private function movementWhere(Request $request, string $from, string $to): array
    {
        [$where, $params] = StockController::filters($request, false);
        $where[] = 'm.created_at BETWEEN ? AND ?';
        array_push($params, $from . ' 00:00:00', $to . ' 23:59:59');
        return ['WHERE ' . implode(' AND ', $where), $params];
    }

    private function periodTotals(Request $request, string $from, string $to): array
    {
        [$where, $params] = $this->movementWhere($request, $from, $to);
        $row = Db::fetch(
            "SELECT COALESCE(SUM(CASE WHEN m.type = 'entrada' THEN m.qty ELSE 0 END), 0) AS entries_units,
                    COALESCE(SUM(CASE WHEN m.type = 'saida' THEN -m.qty ELSE 0 END), 0) AS exits_units,
                    COALESCE(SUM(CASE WHEN m.type = 'saida' THEN -m.qty * COALESCE(m.unit_value, 0) ELSE 0 END), 0) AS exits_value,
                    COALESCE(SUM(CASE WHEN m.type = 'ajuste' THEN 1 ELSE 0 END), 0) AS adjustments
               FROM stock_movements m JOIN items i ON i.id = m.item_id {$where}",
            $params
        );
        return [
            'entries_units' => (int) $row['entries_units'],
            'exits_units' => (int) $row['exits_units'],
            'exits_value' => round((float) $row['exits_value'], 2),
            'adjustments' => (int) $row['adjustments'],
        ];
    }

    /** Daily series (monthly when the period is longer than 92 days), gaps filled with zero. */
    private function series(Request $request, string $from, string $to): array
    {
        [$where, $params] = $this->movementWhere($request, $from, $to);
        $days = (int) ((strtotime($to) - strtotime($from)) / 86400) + 1;
        $monthly = $days > 92;
        $bucket = $monthly ? "DATE_FORMAT(m.created_at, '%Y-%m')" : 'DATE(m.created_at)';
        $rows = Db::fetchAll(
            "SELECT {$bucket} AS bucket,
                    SUM(CASE WHEN m.type = 'entrada' THEN m.qty ELSE 0 END) AS entries,
                    SUM(CASE WHEN m.type = 'saida' THEN -m.qty ELSE 0 END) AS exits
               FROM stock_movements m JOIN items i ON i.id = m.item_id {$where}
              GROUP BY {$bucket} ORDER BY bucket",
            $params
        );
        $byBucket = [];
        foreach ($rows as $r) {
            $byBucket[(string) $r['bucket']] = ['entries' => (int) $r['entries'], 'exits' => (int) $r['exits']];
        }
        $points = [];
        $cursor = new \DateTimeImmutable($monthly ? substr($from, 0, 7) . '-01' : $from);
        $end = new \DateTimeImmutable($to);
        while ($cursor <= $end) {
            $key = $cursor->format($monthly ? 'Y-m' : 'Y-m-d');
            $points[] = ['date' => $key] + ($byBucket[$key] ?? ['entries' => 0, 'exits' => 0]);
            $cursor = $cursor->modify($monthly ? '+1 month' : '+1 day');
        }
        return ['granularity' => $monthly ? 'month' : 'day', 'points' => $points];
    }

    private function topItems(Request $request, string $from, string $to): array
    {
        [$where, $params] = $this->movementWhere($request, $from, $to);
        $rows = Db::fetchAll(
            "SELECT i.id, i.code, i.name, SUM(-m.qty) AS units, SUM(-m.qty * COALESCE(m.unit_value, 0)) AS value
               FROM stock_movements m JOIN items i ON i.id = m.item_id {$where} AND m.type = 'saida'
              GROUP BY i.id, i.code, i.name ORDER BY units DESC, i.name LIMIT 5",
            $params
        );
        return array_map(fn ($r) => [
            'item' => ['id' => (int) $r['id'], 'code' => $r['code'], 'name' => $r['name']],
            'units' => (int) $r['units'],
            'value' => round((float) $r['value'], 2),
        ], $rows);
    }

    private function groupedExits(Request $request, string $from, string $to, string $by): array
    {
        [$where, $params] = $this->movementWhere($request, $from, $to);
        $table = $by === 'department' ? 'departments' : 'industries';
        $column = $by === 'department' ? 'm.department_id' : 'm.industry_id';
        $rows = Db::fetchAll(
            "SELECT g.id, g.name, SUM(-m.qty) AS units, SUM(-m.qty * COALESCE(m.unit_value, 0)) AS value
               FROM stock_movements m JOIN items i ON i.id = m.item_id
          LEFT JOIN {$table} g ON g.id = {$column}
               {$where} AND m.type = 'saida'
              GROUP BY g.id, g.name ORDER BY units DESC LIMIT 10",
            $params
        );
        return array_map(fn ($r) => [
            'id' => $r['id'] !== null ? (int) $r['id'] : null,
            'name' => $r['name'] ?? ($by === 'department' ? 'Sem departamento' : 'Sem indústria'),
            'units' => (int) $r['units'],
            'value' => round((float) $r['value'], 2),
        ], $rows);
    }

    /** Items at zero or at/below the minimum stock (max 10, most critical first). */
    private function alerts(): array
    {
        $rows = Db::fetchAll(
            "SELECT i.id, i.code, i.name, i.min_stock, s.qty_on_hand, s.qty_reserved
               FROM items i JOIN stock s ON s.item_id = i.id
              WHERE i.deleted_at IS NULL AND i.status = 'ativo'
                AND (s.qty_on_hand - s.qty_reserved <= 0 OR (i.min_stock > 0 AND s.qty_on_hand - s.qty_reserved <= i.min_stock))
              ORDER BY (s.qty_on_hand - s.qty_reserved) ASC, i.name LIMIT 10"
        );
        return array_map(function ($r) {
            $available = (int) $r['qty_on_hand'] - (int) $r['qty_reserved'];
            $level = StockService::level($available, (int) $r['min_stock']);
            return [
                'item' => ['id' => (int) $r['id'], 'code' => $r['code'], 'name' => $r['name']],
                'available' => $available,
                'min_stock' => (int) $r['min_stock'],
                'level' => $level,
                'level_label' => StockService::LEVEL_LABELS[$level],
            ];
        }, $rows);
    }

    private function recentMovements(Request $request): array
    {
        [$where, $params] = StockController::filters($request, false);
        $rows = Db::fetchAll(
            StockService::MOVEMENT_SELECT . ' ' . StockService::MOVEMENT_FROM
            . ($where ? ' WHERE ' . implode(' AND ', $where) : '')
            . ' ORDER BY m.created_at DESC, m.id DESC LIMIT 10',
            $params
        );
        return array_map([Present::class, 'movement'], $rows);
    }
}
