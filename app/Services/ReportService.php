<?php

declare(strict_types=1);

namespace App\Services;

use App\Controllers\StockController;
use App\Core\Db;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

final class ReportService
{
    public const TYPES = [
        'stock' => 'Estoque atual',
        'entries' => 'Histórico de entradas',
        'exits' => 'Histórico de saídas',
        'movements' => 'Movimentação por período',
        'by_item' => 'Distribuição por brinde',
        'by_department' => 'Distribuição por departamento',
        'by_requester' => 'Distribuição por solicitante',
        'by_industry' => 'Distribuição por indústria',
        'requests' => 'Solicitações',
        'approvals' => 'Aprovações',
        'low_stock' => 'Itens abaixo do estoque mínimo',
        'protocols' => 'Protocolos',
        'event_balance' => 'Saldo por indústria no evento',
        'stock_industry' => 'Estoque por indústria (recebido / retirado / saldo)',
        'withdrawals' => 'Relatório de retiradas',
        'event_report' => 'Relatório do evento (Feirão)',
    ];

    public static function run(string $type, Request $request): array
    {
        if (!isset(self::TYPES[$type])) {
            throw HttpException::notFound('Relatório não encontrado.');
        }
        $from = StockController::isDate((string) $request->query('from', '')) ? (string) $request->query('from') : date('Y-m-01');
        $to = StockController::isDate((string) $request->query('to', '')) ? (string) $request->query('to') : today();
        return match ($type) {
            'stock' => self::stock(),
            'entries' => self::movements('entrada', $from, $to, $request),
            'exits' => self::movements('saida', $from, $to, $request),
            'movements' => self::movements(null, $from, $to, $request),
            'by_item' => self::groupedExits('item', $from, $to, $request),
            'by_department' => self::groupedExits('department', $from, $to, $request),
            'by_requester' => self::groupedExits('requester', $from, $to, $request),
            'by_industry' => self::groupedExits('industry', $from, $to, $request),
            'requests' => self::requests($from, $to, $request),
            'approvals' => self::approvals($from, $to),
            'low_stock' => self::lowStock(),
            'protocols' => self::protocols($from, $to, $request),
            'event_balance' => self::eventBalance($request),
            'stock_industry' => self::stockIndustry($request),
            'withdrawals' => self::withdrawals($from, $to, $request),
            'event_report' => self::eventReport($request),
            default => throw HttpException::notFound('Relatório não encontrado.'),
        };
    }

    public static function export(string $type, string $format, Request $request): Response
    {
        $report = self::run($type, $request);
        $filename = $type . '_' . date('Ymd');
        if ($format === 'csv') {
            $csv = "\xEF\xBB\xBF";
            $csv .= implode(';', $report['columns']) . "\r\n";
            foreach ($report['rows'] as $row) {
                $csv .= implode(';', array_map([self::class, 'csvCell'], $row)) . "\r\n";
            }
            return Response::download($csv, $filename . '.csv', 'text/csv; charset=utf-8');
        }
        $ss = new Spreadsheet();
        $sheet = $ss->getActiveSheet();
        $sheet->setTitle(mb_substr(self::TYPES[$type], 0, 31));
        $col = 1;
        foreach ($report['columns'] as $h) {
            $sheet->setCellValue([$col++, 1], $h);
        }
        $r = 2;
        foreach ($report['rows'] as $row) {
            $c = 1;
            foreach ($row as $v) {
                $sheet->setCellValue([$c++, $r], $v);
            }
            $r++;
        }
        $tmp = sys_get_temp_dir() . '/' . $filename . '.xlsx';
        (new Xlsx($ss))->save($tmp);
        $bin = (string) file_get_contents($tmp);
        @unlink($tmp);
        return Response::download($bin, $filename . '.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    }

    private static function industryAgg(string $expr = 'ind.name'): string
    {
        return Db::isSqlite() ? "GROUP_CONCAT({$expr})" : "GROUP_CONCAT(DISTINCT {$expr})";
    }

    private static function csvCell(mixed $v): string
    {
        $s = (string) ($v ?? '');
        $s = str_replace('"', '""', $s);
        return '"' . $s . '"';
    }

    private static function stock(): array
    {
        $indAgg = self::industryAgg();
        $rows = Db::fetchAll(
            "SELECT i.code, i.name, c.name AS category, l.name AS location,
                    {$indAgg} AS industry,
                    s.qty_on_hand, s.qty_reserved,
                    (s.qty_on_hand - s.qty_reserved) AS available, i.min_stock, i.unit_value, i.status
               FROM items i JOIN stock s ON s.item_id = i.id
          LEFT JOIN categories c ON c.id = i.category_id
          LEFT JOIN locations l ON l.id = i.location_id
          LEFT JOIN stock_movements m ON m.item_id = i.id AND m.industry_id IS NOT NULL
          LEFT JOIN industries ind ON ind.id = m.industry_id
              WHERE i.deleted_at IS NULL
           GROUP BY i.id, i.code, i.name, c.name, l.name, s.qty_on_hand, s.qty_reserved, i.min_stock, i.unit_value, i.status
           ORDER BY i.name"
        );
        return [
            'title' => self::TYPES['stock'],
            'columns' => ['Código', 'Nome', 'Categoria', 'Local', 'Indústria', 'Em estoque', 'Reservado', 'Disponível', 'Mínimo', 'Valor unit.', 'Status'],
            'rows' => array_map(fn ($r) => [$r['code'], $r['name'], $r['category'], $r['location'], $r['industry'] ?: '—', $r['qty_on_hand'], $r['qty_reserved'], $r['available'], $r['min_stock'], $r['unit_value'], $r['status']], $rows),
        ];
    }

    private static function movements(?string $type, string $from, string $to, Request $request): array
    {
        $where = ['m.created_at BETWEEN ? AND ?'];
        $params = [$from . ' 00:00:00', $to . ' 23:59:59'];
        if ($type) {
            $where[] = 'm.type = ?';
            $params[] = $type;
        }
        if (($id = $request->queryInt('item_id'))) {
            $where[] = 'm.item_id = ?';
            $params[] = $id;
        }
        $rows = Db::fetchAll(
            'SELECT m.created_at, m.type, i.code, i.name, ind.name AS industry, m.qty, m.balance_after, u.name AS user_name, m.recipient, m.purpose, m.purchase_ticket_no
               FROM stock_movements m JOIN items i ON i.id = m.item_id JOIN users u ON u.id = m.user_id
          LEFT JOIN industries ind ON ind.id = m.industry_id
              WHERE ' . implode(' AND ', $where) . ' ORDER BY m.created_at DESC',
            $params
        );
        return [
            'title' => self::TYPES[$type === 'entrada' ? 'entries' : ($type === 'saida' ? 'exits' : 'movements')],
            'columns' => ['Data', 'Tipo', 'Código', 'Brinde', 'Indústria', 'Qtd', 'Saldo após', 'Usuário', 'Destinatário', 'Finalidade', 'Chamado'],
            'rows' => array_map(fn ($r) => [$r['created_at'], $r['type'], $r['code'], $r['name'], $r['industry'] ?: '—', $r['qty'], $r['balance_after'], $r['user_name'], $r['recipient'], $r['purpose'], $r['purchase_ticket_no']], $rows),
        ];
    }

    private static function groupedExits(string $by, string $from, string $to, Request $request): array
    {
        $indAgg = self::industryAgg();
        $select = match ($by) {
            'item' => "i.code, i.name, {$indAgg} AS industry",
            'department' => "d.name, {$indAgg} AS industry",
            'requester' => "rq.name, {$indAgg} AS industry",
            default => 'ind.name',
        };
        $join = match ($by) {
            'item' => 'LEFT JOIN industries ind ON ind.id = m.industry_id',
            'department' => 'LEFT JOIN departments d ON d.id = m.department_id LEFT JOIN industries ind ON ind.id = m.industry_id',
            'requester' => 'LEFT JOIN users rq ON rq.id = m.requester_id LEFT JOIN industries ind ON ind.id = m.industry_id',
            default => 'LEFT JOIN industries ind ON ind.id = m.industry_id',
        };
        $group = match ($by) {
            'item' => 'i.id, i.code, i.name',
            'department' => 'd.id, d.name',
            'requester' => 'rq.id, rq.name',
            default => 'ind.id, ind.name',
        };
        $rows = Db::fetchAll(
            "SELECT {$select}, SUM(-m.qty) AS units, SUM(-m.qty * COALESCE(m.unit_value,0)) AS value
               FROM stock_movements m JOIN items i ON i.id = m.item_id {$join}
              WHERE m.type = 'saida' AND m.created_at BETWEEN ? AND ?
              GROUP BY {$group} ORDER BY units DESC",
            [$from . ' 00:00:00', $to . ' 23:59:59']
        );
        $cols = $by === 'item' ? ['Código', 'Nome', 'Indústria', 'Unidades', 'Valor'] : ($by === 'industry' ? ['Nome', 'Unidades', 'Valor'] : ['Nome', 'Indústria', 'Unidades', 'Valor']);
        $data = array_map(function ($r) use ($by) {
            if ($by === 'item') {
                return [$r['code'], $r['name'], $r['industry'] ?: '—', $r['units'], $r['value']];
            }
            if ($by === 'industry') {
                return [$r['name'] ?? '—', $r['units'], $r['value']];
            }
            return [$r['name'] ?? '—', $r['industry'] ?: '—', $r['units'], $r['value']];
        }, $rows);
        return ['title' => self::TYPES['by_' . ($by === 'item' ? 'item' : ($by === 'department' ? 'department' : ($by === 'requester' ? 'requester' : 'industry')))], 'columns' => $cols, 'rows' => $data];
    }

    private static function requests(string $from, string $to, Request $request): array
    {
        $rows = Db::fetchAll(
            "SELECT r.code, r.status, u.name AS requester, d.name AS department, ind.name AS industry, r.purpose, r.recipient,
                    r.purchase_ticket_no, r.total_value, r.created_at, r.finalized_at
               FROM requests r JOIN users u ON u.id = r.requester_id
          LEFT JOIN departments d ON d.id = r.department_id
          LEFT JOIN industries ind ON ind.id = r.industry_id
              WHERE r.deleted_at IS NULL AND r.created_at BETWEEN ? AND ? ORDER BY r.id DESC",
            [$from . ' 00:00:00', $to . ' 23:59:59']
        );
        return [
            'title' => 'Solicitações',
            'columns' => ['Código', 'Status', 'Solicitante', 'Depto', 'Indústria', 'Finalidade', 'Destinatário', 'Chamado', 'Valor', 'Criada', 'Finalizada'],
            'rows' => array_map(fn ($r) => [$r['code'], $r['status'], $r['requester'], $r['department'], $r['industry'], $r['purpose'], $r['recipient'], $r['purchase_ticket_no'], $r['total_value'], $r['created_at'], $r['finalized_at']], $rows),
        ];
    }

    private static function approvals(string $from, string $to): array
    {
        $rows = Db::fetchAll(
            "SELECT r.code, a.decision, a.justification, u.name AS decided_by, a.decided_at, ru.name AS rule_name
               FROM approvals a JOIN requests r ON r.id = a.request_id
          LEFT JOIN users u ON u.id = a.decided_by
          LEFT JOIN approval_rules ru ON ru.id = a.rule_id
              WHERE a.created_at BETWEEN ? AND ? ORDER BY a.id DESC",
            [$from . ' 00:00:00', $to . ' 23:59:59']
        );
        return [
            'title' => 'Aprovações',
            'columns' => ['Solicitação', 'Decisão', 'Justificativa', 'Por', 'Em', 'Regra'],
            'rows' => array_map(fn ($r) => [$r['code'], $r['decision'], $r['justification'], $r['decided_by'], $r['decided_at'], $r['rule_name']], $rows),
        ];
    }

    private static function lowStock(): array
    {
        $indAgg = self::industryAgg();
        $rows = Db::fetchAll(
            "SELECT i.code, i.name, {$indAgg} AS industry,
                    s.qty_on_hand - s.qty_reserved AS available, i.min_stock
               FROM items i JOIN stock s ON s.item_id = i.id
          LEFT JOIN stock_movements m ON m.item_id = i.id AND m.industry_id IS NOT NULL
          LEFT JOIN industries ind ON ind.id = m.industry_id
              WHERE i.deleted_at IS NULL AND i.status = 'ativo'
                AND (s.qty_on_hand - s.qty_reserved <= 0 OR (i.min_stock > 0 AND s.qty_on_hand - s.qty_reserved <= i.min_stock))
           GROUP BY i.id, i.code, i.name, s.qty_on_hand, s.qty_reserved, i.min_stock
              ORDER BY available ASC"
        );
        return [
            'title' => 'Abaixo do mínimo',
            'columns' => ['Código', 'Nome', 'Indústria', 'Disponível', 'Mínimo'],
            'rows' => array_map(fn ($r) => [$r['code'], $r['name'], $r['industry'] ?: '—', $r['available'], $r['min_stock']], $rows),
        ];
    }

    private static function protocols(string $from, string $to, Request $request): array
    {
        $where = ['d.created_at BETWEEN ? AND ?'];
        $params = [$from . ' 00:00:00', $to . ' 23:59:59'];
        if (($eid = $request->queryInt('event_id'))) {
            $where[] = 'd.event_id = ?';
            $params[] = $eid;
        }
        $rows = Db::fetchAll(
            'SELECT d.code, d.type, d.received_by_name, u.name AS delivered_by, e.name AS event_name, ind.name AS industry, d.created_at
               FROM deliveries d JOIN users u ON u.id = d.delivered_by
          LEFT JOIN events e ON e.id = d.event_id LEFT JOIN industries ind ON ind.id = d.industry_id
              WHERE ' . implode(' AND ', $where) . ' ORDER BY d.id DESC',
            $params
        );
        return [
            'title' => 'Protocolos',
            'columns' => ['Código', 'Tipo', 'Recebedor', 'Entregue por', 'Evento', 'Indústria', 'Data'],
            'rows' => array_map(fn ($r) => [$r['code'], $r['type'], $r['received_by_name'], $r['delivered_by'], $r['event_name'], $r['industry'], $r['created_at']], $rows),
        ];
    }

    private static function eventBalance(Request $request): array
    {
        $eid = $request->queryInt('event_id');
        if (!$eid) {
            throw HttpException::validation(['event_id' => 'Informe o evento.']);
        }
        $rows = Db::fetchAll(
            'SELECT ind.name AS industry, i.code, i.name, a.qty_allocated, a.qty_withdrawn,
                    a.qty_allocated - a.qty_withdrawn AS saldo
               FROM event_allocations a JOIN industries ind ON ind.id = a.industry_id JOIN items i ON i.id = a.item_id
              WHERE a.event_id = ? ORDER BY ind.name, i.name',
            [$eid]
        );
        return [
            'title' => 'Saldo por indústria',
            'columns' => ['Indústria', 'Código', 'Brinde', 'Alocado', 'Retirado', 'Saldo'],
            'rows' => array_map(fn ($r) => [$r['industry'], $r['code'], $r['name'], $r['qty_allocated'], $r['qty_withdrawn'], $r['saldo']], $rows),
        ];
    }

    private static function stockIndustry(Request $request): array
    {
        $rows = TradeService::stockByIndustry($request->queryInt('industry_id'));
        return [
            'title' => 'Estoque por indústria',
            'columns' => ['Indústria', 'Código', 'Brinde', 'Recebido', 'Retirado', 'Saldo'],
            'rows' => array_map(fn ($r) => [
                $r['industry']['name'], $r['item']['code'], $r['item']['name'],
                $r['received'], $r['withdrawn'], $r['saldo'],
            ], $rows),
        ];
    }

    private static function withdrawals(string $from, string $to, Request $request): array
    {
        $where = ["m.type = 'saida'", 'm.created_at BETWEEN ? AND ?'];
        $params = [$from . ' 00:00:00', $to . ' 23:59:59'];
        if ($iid = \App\Core\Auth::industryId() ?: $request->queryInt('industry_id')) {
            $where[] = 'm.industry_id = ?';
            $params[] = $iid;
        }
        if (($eid = $request->queryInt('event_id'))) {
            $where[] = 'm.event_id = ?';
            $params[] = $eid;
        }
        $rows = Db::fetchAll(
            'SELECT m.created_at, ind.name AS industry, i.code, i.name, -m.qty AS qty,
                    m.recipient, u.name AS user_name, e.name AS event_name, m.purpose
               FROM stock_movements m
               JOIN items i ON i.id = m.item_id
               JOIN users u ON u.id = m.user_id
          LEFT JOIN industries ind ON ind.id = m.industry_id
          LEFT JOIN events e ON e.id = m.event_id
              WHERE ' . implode(' AND ', $where) . ' ORDER BY m.created_at DESC',
            $params
        );
        return [
            'title' => 'Retiradas',
            'columns' => ['Data/hora', 'Indústria', 'Código', 'Brinde', 'Qtd', 'Quem retirou', 'Responsável', 'Evento', 'Finalidade'],
            'rows' => array_map(fn ($r) => [
                $r['created_at'], $r['industry'], $r['code'], $r['name'], $r['qty'],
                $r['recipient'], $r['user_name'], $r['event_name'], $r['purpose'],
            ], $rows),
        ];
    }

    private static function eventReport(Request $request): array
    {
        $eid = $request->queryInt('event_id');
        if (!$eid) {
            throw HttpException::validation(['event_id' => 'Informe o evento.']);
        }
        $event = Db::fetch('SELECT name FROM events WHERE id = ?', [$eid]);
        $rows = Db::fetchAll(
            'SELECT ind.name AS industry, i.code, i.name,
                    a.qty_allocated AS inicial, a.qty_withdrawn AS retirado,
                    a.qty_allocated - a.qty_withdrawn AS saldo
               FROM event_allocations a
               JOIN industries ind ON ind.id = a.industry_id
               JOIN items i ON i.id = a.item_id
              WHERE a.event_id = ? ORDER BY ind.name, i.name',
            [$eid]
        );
        $who = Db::fetchAll(
            'SELECT d.created_at, d.received_by_name, i.name AS item_name, di.qty, ind.name AS industry
               FROM deliveries d
               JOIN delivery_items di ON di.delivery_id = d.id
               JOIN items i ON i.id = di.item_id
          LEFT JOIN industries ind ON ind.id = d.industry_id
              WHERE d.event_id = ? ORDER BY d.created_at',
            [$eid]
        );
        $data = array_map(fn ($r) => [$r['industry'], $r['code'], $r['name'], $r['inicial'], $r['retirado'], $r['saldo']], $rows);
        foreach ($who as $w) {
            $data[] = ['RETIRADA', $w['created_at'], $w['industry'], $w['item_name'], $w['qty'], $w['received_by_name']];
        }
        return [
            'title' => 'Evento: ' . ($event['name'] ?? $eid),
            'columns' => ['Indústria / tipo', 'Código / data', 'Brinde', 'Estoque inicial', 'Retirado', 'Saldo / quem'],
            'rows' => $data,
        ];
    }
}
