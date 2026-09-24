<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Auth;
use App\Core\Db;
use App\Core\HttpException;
use App\Controllers\StockController;
use App\Core\Paginator;
use App\Core\Request;
use App\Core\Validator;
use App\Support\Present;

final class RequestService
{
    public const SELECT = 'SELECT r.*, u.name AS requester_name, u.email AS requester_email, u.role_id AS requester_role_id,
            d.name AS department_name, ind.name AS industry_name, e.name AS event_name,
            ap.id AS approval_id, ap.decision AS approval_decision, ap.justification AS approval_justification,
            ap.decided_at AS approval_decided_at, ap.approver_user_id, ap.approver_role_id, ap.rule_id,
            ru.name AS rule_name, du.name AS decided_by_name,
            dv.id AS delivery_id, dv.code AS delivery_code';

    public const FROM = 'FROM requests r
            JOIN users u ON u.id = r.requester_id
       LEFT JOIN departments d ON d.id = r.department_id
       LEFT JOIN industries ind ON ind.id = r.industry_id
       LEFT JOIN events e ON e.id = r.event_id
       LEFT JOIN approvals ap ON ap.request_id = r.id
       LEFT JOIN approval_rules ru ON ru.id = ap.rule_id
       LEFT JOIN users du ON du.id = ap.decided_by
       LEFT JOIN deliveries dv ON dv.request_id = r.id';

    public static function scope(): array
    {
        if ($iid = Auth::industryId()) {
            return [' AND r.industry_id = ?', [$iid]];
        }
        if (Auth::can('requests.view_all') || Auth::isAdmin()) {
            return ['', []];
        }
        $user = Auth::user();
        $parts = ['r.requester_id = ?'];
        $params = [(int) $user['id']];
        if (Auth::can('requests.view_department') && !empty($user['department_id'])) {
            $parts[] = 'r.department_id = ?';
            $params[] = (int) $user['department_id'];
        }
        if (Auth::can('requests.approve')) {
            $parts[] = "EXISTS (SELECT 1 FROM approvals a WHERE a.request_id = r.id AND (a.approver_user_id = ? OR a.approver_role_id = ?))";
            $params[] = (int) $user['id'];
            $params[] = (int) $user['role_id'];
        }
        return [' AND (' . implode(' OR ', $parts) . ')', $params];
    }

    public static function list(Request $request): array
    {
        [$scopeSql, $scopeParams] = self::scope();
        $where = ['r.deleted_at IS NULL AND r.status <> \'rascunho\'' . $scopeSql];
        $params = $scopeParams;
        if (($q = (string) $request->query('q', '')) !== '') {
            $where[] = '(r.code LIKE ? OR r.purpose LIKE ? OR r.recipient LIKE ? OR u.name LIKE ?)';
            array_push($params, Db::like($q), Db::like($q), Db::like($q), Db::like($q));
        }
        if (($st = (string) $request->query('status', '')) !== '' && isset(RequestWorkflow::LABELS[$st])) {
            $where[] = 'r.status = ?';
            $params[] = $st;
        }
        $statusIn = trim((string) $request->query('status_in', ''));
        if ($statusIn !== '') {
            $parts = array_values(array_filter(
                array_map('trim', explode(',', $statusIn)),
                static fn (string $s): bool => $s !== '' && isset(RequestWorkflow::LABELS[$s])
            ));
            if ($parts !== []) {
                $where[] = 'r.status IN (' . implode(',', array_fill(0, count($parts), '?')) . ')';
                array_push($params, ...$parts);
            }
        }
        if (($flow = (string) $request->query('flow', '')) !== '' && in_array($flow, ['interna', 'trade'], true)) {
            $where[] = 'r.flow = ?';
            $params[] = $flow;
        }
        foreach (['department_id' => 'r.department_id', 'industry_id' => 'r.industry_id', 'requester_id' => 'r.requester_id', 'event_id' => 'r.event_id'] as $key => $col) {
            if (($v = $request->queryInt($key)) !== null) {
                $where[] = "{$col} = ?";
                $params[] = $v;
            }
        }
        if (StockController::isDate($from = (string) $request->query('from', ''))) {
            $where[] = 'r.created_at >= ?';
            $params[] = $from . ' 00:00:00';
        }
        if (StockController::isDate($to = (string) $request->query('to', ''))) {
            $where[] = 'r.created_at <= ?';
            $params[] = $to . ' 23:59:59';
        }
        $fromSql = self::FROM . ' WHERE ' . implode(' AND ', $where);
        [$rows, $meta] = Paginator::run($request, self::SELECT, $fromSql, $params, 'ORDER BY r.created_at DESC, r.id DESC');
        $counts = self::statusCounts($scopeSql, $scopeParams);
        $meta['counts'] = $counts;
        return [array_map([self::class, 'presentRow'], $rows), $meta];
    }

    public static function find(int $id, bool $withItems = true): array
    {
        [$scopeSql, $scopeParams] = self::scope();
        $row = Db::fetch(self::SELECT . ' ' . self::FROM . ' WHERE r.id = ? AND r.deleted_at IS NULL' . $scopeSql, [$id, ...$scopeParams]);
        if ($row === null) {
            throw HttpException::notFound('Solicitação não encontrada.');
        }
        $out = self::presentRow($row);
        if ($withItems) {
            $out['items'] = self::items($id);
            $out['history'] = self::history($id);
        }
        $out['next_action'] = RequestWorkflow::nextAction($out);
        $code = (string) ($out['public_code'] ?? $out['code'] ?? '');
        if ($code !== '' && ($out['flow'] ?? '') === 'trade') {
            try {
                $out['qr_svg'] = \App\Services\QrService::svg($code);
                $out['qr_png'] = \App\Services\QrService::pngDataUri($code);
            } catch (\Throwable) {
            }
        }
        return $out;
    }

    public static function create(array $input, bool $submit): array
    {
        $header = Validator::validate($input, [
            'department_id' => 'nullable|int|exists:departments',
            'industry_id' => 'nullable|int|exists:industries',
            'event_id' => 'nullable|int|exists:events',
            'purpose' => 'required|string|max:255',
            'recipient' => 'nullable|string|max:150',
            'needed_date' => 'nullable|date',
            'purchase_ticket_no' => 'nullable|string|max:50',
            'notes' => 'nullable|string|max:2000',
        ]);
        $lines = self::parseItems($input['items'] ?? null);
        $user = Auth::user();
        $header['department_id'] ??= $user['department_id'] ? (int) $user['department_id'] : null;
        $header['requester_id'] = (int) $user['id'];

        $id = Db::transaction(function () use ($header, $lines, $submit) {
            $year = date('Y');
            $code = sprintf('SOL-%s-%04d', $year, Sequence::next('request', $year));
            $total = 0.0;
            foreach ($lines as $l) {
                $total += $l['qty_requested'] * (float) ($l['unit_value'] ?? 0);
            }
            $avail = self::availability($lines);
            $needs = $avail['needs_purchase'] ? 1 : 0;
            $now = now();
            $id = Db::insert('requests', $header + [
                'code' => $code,
                'status' => 'rascunho',
                'total_value' => round($total, 2),
                'needs_purchase' => $needs,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            foreach ($lines as $l) {
                Db::insert('request_items', $l + ['request_id' => $id, 'created_at' => $now, 'updated_at' => $now]);
            }
            Audit::log('create', 'request', $id, null, ['code' => $code, 'purpose' => $header['purpose']], $code);
            if ($submit) {
                self::submitLocked($id);
            }
            return $id;
        });
        $found = self::find($id);
        if ($submit) {
            NotificationService::requestSubmitted($found);
            NotificationService::flush();
        }
        return $found;
    }

    public static function update(int $id, array $input): array
    {
        return Db::transaction(function () use ($id, $input) {
            $row = self::lock($id);
            if ($row['status'] !== 'rascunho') {
                throw HttpException::rule('NOT_EDITABLE', 'Somente rascunhos podem ser editados.');
            }
            self::assertOwner($row);
            $header = Validator::validate($input, [
                'department_id' => 'sometimes|nullable|int|exists:departments',
                'industry_id' => 'sometimes|nullable|int|exists:industries',
                'event_id' => 'sometimes|nullable|int|exists:events',
                'purpose' => 'sometimes|required|string|max:255',
                'recipient' => 'sometimes|nullable|string|max:150',
                'needed_date' => 'sometimes|nullable|date',
                'purchase_ticket_no' => 'sometimes|nullable|string|max:50',
                'notes' => 'sometimes|nullable|string|max:2000',
            ]);
            if (isset($input['items'])) {
                $lines = self::parseItems($input['items']);
                Db::query('DELETE FROM request_items WHERE request_id = ?', [$id]);
                $total = 0.0;
                foreach ($lines as $l) {
                    $total += $l['qty_requested'] * (float) ($l['unit_value'] ?? 0);
                    Db::insert('request_items', $l + ['request_id' => $id, 'created_at' => now(), 'updated_at' => now()]);
                }
                $header['total_value'] = round($total, 2);
                $header['needs_purchase'] = self::availability($lines)['needs_purchase'] ? 1 : 0;
            }
            if ($header !== []) {
                Db::update('requests', $header + ['updated_at' => now()], ['id' => $id]);
                Audit::logUpdate('request', $id, $row, $header, $row['code']);
            }
            return self::find($id);
        });
    }

    public static function submit(int $id): array
    {
        Db::transaction(fn () => self::submitLocked($id));
        $found = self::find($id);
        NotificationService::requestSubmitted($found);
        NotificationService::flush();
        return $found;
    }

    public static function cancel(int $id, ?string $reason): array
    {
        Db::transaction(function () use ($id, $reason) {
            $row = self::lock($id);
            $owner = (int) $row['requester_id'] === (int) Auth::id();
            if (!$owner && !Auth::can('requests.cancel_any') && !Auth::isAdmin()) {
                throw HttpException::forbidden();
            }
            if (in_array($row['status'], ['aprovada', 'em_separacao'], true) && !Auth::isAdmin() && !Auth::can('requests.cancel_any')) {
                throw HttpException::forbidden('Somente o administrador pode cancelar após a aprovação.');
            }
            self::releaseReservations($id);
            RequestWorkflow::apply($id, 'cancelada', $reason ?: 'Cancelada');
        });
        return self::find($id);
    }

    public static function approve(int $id, array $input): array
    {
        self::assertCanDecide($id);
        $qtys = $input['items'] ?? null;
        Db::transaction(function () use ($id, $qtys, $input) {
            $row = self::lock($id);
            if ($row['status'] !== 'aguardando_aprovacao') {
                RequestWorkflow::assertTransition($row['status'], 'aprovada');
            }
            $lines = Db::fetchAll('SELECT * FROM request_items WHERE request_id = ? FOR UPDATE', [$id]);
            foreach ($lines as $line) {
                $approved = (int) $line['qty_requested'];
                if (is_array($qtys)) {
                    foreach ($qtys as $q) {
                        if ((int) ($q['item_id'] ?? 0) === (int) $line['item_id'] && isset($q['qty_approved'])) {
                            $approved = (int) $q['qty_approved'];
                        }
                    }
                }
                if ($approved < 0 || $approved > (int) $line['qty_requested']) {
                    throw HttpException::validation(['items' => 'Quantidade aprovada inválida para um dos itens.']);
                }
                Db::update('request_items', ['qty_approved' => $approved, 'updated_at' => now()], ['id' => (int) $line['id']]);
            }
            self::reserveApproved($id);
            Db::query("UPDATE approvals SET decision = 'aprovado', justification = ?, decided_by = ?, decided_at = ? WHERE request_id = ? AND decision = 'pendente'", [
                $input['justification'] ?? null, Auth::id(), now(), $id,
            ]);
            RequestWorkflow::apply($id, 'aprovada', $input['justification'] ?? null);
        });
        $found = self::find($id);
        NotificationService::requestDecided($found, 'aprovado', $input['justification'] ?? null);
        NotificationService::flush();
        return $found;
    }

    public static function reject(int $id, array $input): array
    {
        self::assertCanDecide($id);
        $data = Validator::validate($input, ['justification' => 'required|string|min:3|max:1000']);
        Db::transaction(function () use ($id, $data) {
            $row = self::lock($id);
            RequestWorkflow::assertTransition($row['status'], 'reprovada');
            Db::query("UPDATE approvals SET decision = 'reprovado', justification = ?, decided_by = ?, decided_at = ? WHERE request_id = ? AND decision = 'pendente'", [
                $data['justification'], Auth::id(), now(), $id,
            ]);
            RequestWorkflow::apply($id, 'reprovada', $data['justification']);
        });
        $found = self::find($id);
        NotificationService::requestDecided($found, 'reprovado', $data['justification']);
        NotificationService::flush();
        return $found;
    }

    public static function startPicking(int $id): array
    {
        Auth::authorize('requests.process');
        Db::transaction(function () use ($id) {
            $row = self::lock($id);
            RequestWorkflow::apply($id, 'em_separacao');
            unset($row);
        });
        return self::find($id);
    }

    public static function markReady(int $id): array
    {
        Auth::authorize('requests.process');
        Db::transaction(function () use ($id) {
            self::lock($id);
            RequestWorkflow::apply($id, 'pronta');
        });
        $found = self::find($id);
        NotificationService::requestReady($found);
        NotificationService::flush();
        return $found;
    }

    public static function checkAvailability(array $items): array
    {
        $lines = [];
        foreach ($items as $it) {
            if (!is_array($it)) {
                continue;
            }
            $id = (int) ($it['item_id'] ?? 0);
            $qty = (int) ($it['quantity'] ?? $it['qty_requested'] ?? 0);
            if ($id < 1 || $qty < 1) {
                continue;
            }
            $lines[] = ['item_id' => $id, 'qty_requested' => $qty];
        }
        return self::availability($lines);
    }

    // -----------------------------------------------------------------

    private static function submitLocked(int $id): void
    {
        $row = self::lock($id);
        self::assertOwner($row);
        if ($row['status'] !== 'rascunho' && $row['status'] !== 'solicitada') {
            throw HttpException::rule('ALREADY_SUBMITTED', 'Esta solicitação já foi enviada.');
        }
        $lines = Db::fetchAll(
            'SELECT ri.*, i.category_id, i.unit_value AS item_unit_value, i.status AS item_status, i.name AS item_name
               FROM request_items ri JOIN items i ON i.id = ri.item_id WHERE ri.request_id = ?',
            [$id]
        );
        if ($lines === []) {
            throw HttpException::validation(['items' => 'Inclua pelo menos um brinde.']);
        }
        foreach ($lines as &$line) {
            $line['unit_value'] = $line['unit_value'] ?? $line['item_unit_value'];
            if ($line['item_status'] !== 'ativo') {
                throw HttpException::rule('ITEM_INACTIVE', "O brinde {$line['item_name']} está inativo.");
            }
        }
        unset($line);
        $requesterRole = (int) Db::value('SELECT role_id FROM users WHERE id = ?', [(int) $row['requester_id']]);
        $rule = ApprovalEngine::match($row, $lines, $requesterRole);
        $avail = self::availability($lines);
        Db::update('requests', ['needs_purchase' => $avail['needs_purchase'] ? 1 : 0], ['id' => $id]);

        if ($rule) {
            RequestWorkflow::apply($id, 'aguardando_aprovacao');
            Db::insert('approvals', [
                'request_id' => $id,
                'rule_id' => (int) $rule['id'],
                'approver_role_id' => $rule['approver_role_id'] ? (int) $rule['approver_role_id'] : null,
                'approver_user_id' => $rule['approver_user_id'] ? (int) $rule['approver_user_id'] : null,
                'decision' => 'pendente',
                'created_at' => now(),
            ]);
        } else {
            foreach ($lines as $line) {
                Db::update('request_items', ['qty_approved' => (int) $line['qty_requested']], ['id' => (int) $line['id']]);
            }
            RequestWorkflow::apply($id, 'aprovada');
            self::reserveApproved($id);
        }
        Audit::log('submit', 'request', $id, ['status' => $row['status']], ['status' => $rule ? 'aguardando_aprovacao' : 'aprovada'], $row['code']);
    }

    private static function reserveApproved(int $requestId): void
    {
        $lines = Db::fetchAll('SELECT * FROM request_items WHERE request_id = ?', [$requestId]);
        foreach ($lines as $line) {
            $qty = (int) ($line['qty_approved'] ?? $line['qty_requested']);
            if ($qty <= 0) {
                continue;
            }
            $snap = StockService::snapshot((int) $line['item_id']);
            $take = min($qty, $snap['available']);
            if ($take > 0) {
                StockService::reserve((int) $line['item_id'], $take, 'solicitação #' . $requestId);
            }
            Db::update('request_items', ['qty_reserved' => $take, 'updated_at' => now()], ['id' => (int) $line['id']]);
            if ($take < $qty) {
                Db::update('requests', ['needs_purchase' => 1], ['id' => $requestId]);
            }
        }
    }

    public static function releaseReservations(int $requestId): void
    {
        $lines = Db::fetchAll('SELECT * FROM request_items WHERE request_id = ?', [$requestId]);
        foreach ($lines as $line) {
            $qty = (int) $line['qty_reserved'] - (int) $line['qty_delivered'];
            if ($qty > 0) {
                StockService::release((int) $line['item_id'], $qty, 'cancelamento solicitação #' . $requestId);
                Db::update('request_items', ['qty_reserved' => (int) $line['qty_delivered']], ['id' => (int) $line['id']]);
            }
        }
    }

    private static function availability(array $lines): array
    {
        $out = [];
        $needs = false;
        foreach ($lines as $line) {
            $itemId = (int) $line['item_id'];
            $req = (int) $line['qty_requested'];
            $snap = StockService::snapshot($itemId);
            $ok = $req <= $snap['available'];
            if (!$ok) {
                $needs = true;
            }
            $item = Db::fetch('SELECT code, name FROM items WHERE id = ?', [$itemId]);
            $out[] = [
                'item_id' => $itemId,
                'code' => $item['code'] ?? null,
                'name' => $item['name'] ?? null,
                'requested' => $req,
                'available' => $snap['available'],
                'sufficient' => $ok,
            ];
        }
        return ['items' => $out, 'needs_purchase' => $needs];
    }

    private static function parseItems(mixed $items): array
    {
        if (!is_array($items) || $items === []) {
            throw HttpException::validation(['items' => 'Inclua pelo menos um brinde.']);
        }
        $out = [];
        $seen = [];
        foreach ($items as $i => $it) {
            if (!is_array($it)) {
                continue;
            }
            $row = Validator::validate($it, [
                'item_id' => 'required|int|exists:items',
                'qty_requested' => 'required|int|min:1|max:1000000',
                'notes' => 'nullable|string|max:255',
            ]);
            if (isset($seen[$row['item_id']])) {
                throw HttpException::validation(['items' => 'O mesmo brinde não pode aparecer duas vezes.']);
            }
            $seen[$row['item_id']] = true;
            $item = Db::fetch('SELECT unit_value, status FROM items WHERE id = ? AND deleted_at IS NULL', [$row['item_id']]);
            if (!$item || $item['status'] !== 'ativo') {
                throw HttpException::validation(['items.' . $i . '.item_id' => 'Brinde inválido ou inativo.']);
            }
            $out[] = [
                'item_id' => $row['item_id'],
                'qty_requested' => $row['qty_requested'],
                'qty_approved' => null,
                'qty_reserved' => 0,
                'qty_delivered' => 0,
                'unit_value' => $item['unit_value'],
                'notes' => $row['notes'] ?? null,
            ];
        }
        if ($out === []) {
            throw HttpException::validation(['items' => 'Inclua pelo menos um brinde.']);
        }
        return $out;
    }

    private static function items(int $requestId): array
    {
        $rows = Db::fetchAll(
            'SELECT ri.*, i.code, i.name, i.photo_path, i.min_stock, s.qty_on_hand, s.qty_reserved AS stock_reserved
               FROM request_items ri
               JOIN items i ON i.id = ri.item_id
          LEFT JOIN stock s ON s.item_id = i.id
              WHERE ri.request_id = ? ORDER BY ri.id',
            [$requestId]
        );
        return array_map(function (array $r) {
            $on = (int) ($r['qty_on_hand'] ?? 0);
            $res = (int) ($r['stock_reserved'] ?? 0);
            $version = $r['photo_path'] ? substr(md5((string) $r['photo_path']), 0, 8) : null;
            $unit = Present::money($r['unit_value']);
            $qty = (int) ($r['qty_approved'] ?? $r['qty_requested']);
            return [
                'id' => (int) $r['id'],
                'item' => [
                    'id' => (int) $r['item_id'],
                    'code' => $r['code'],
                    'name' => $r['name'],
                    'thumb_url' => $version ? url('/api/items/' . $r['item_id'] . '/photo') . '?size=thumb&v=' . $version : null,
                ],
                'qty_requested' => (int) $r['qty_requested'],
                'qty_approved' => $r['qty_approved'] !== null ? (int) $r['qty_approved'] : null,
                'qty_reserved' => (int) $r['qty_reserved'],
                'qty_delivered' => (int) $r['qty_delivered'],
                'qty_received' => (int) ($r['qty_received'] ?? 0),
                'unit_value' => $unit,
                'line_value' => $unit !== null ? round($qty * $unit, 2) : null,
                'available' => $on - $res,
                'notes' => $r['notes'],
            ];
        }, $rows);
    }

    private static function history(int $requestId): array
    {
        $rows = Db::fetchAll(
            'SELECT h.*, u.name AS user_name FROM request_status_history h LEFT JOIN users u ON u.id = h.user_id
              WHERE h.request_id = ? ORDER BY h.id ASC',
            [$requestId]
        );
        return array_map(fn ($h) => [
            'from' => $h['from_status'],
            'from_label' => RequestWorkflow::LABELS[$h['from_status']] ?? $h['from_status'],
            'to' => $h['to_status'],
            'to_label' => RequestWorkflow::LABELS[$h['to_status']] ?? $h['to_status'],
            'user' => ['id' => $h['user_id'] ? (int) $h['user_id'] : null, 'name' => $h['user_name']],
            'comment' => $h['comment'],
            'created_at' => $h['created_at'],
        ], $rows);
    }

    public static function presentRow(array $r): array
    {
        $status = $r['status'];
        return [
            'id' => (int) $r['id'],
            'code' => $r['code'],
            'status' => $status,
            'status_label' => (($r['flow'] ?? '') === 'trade' && $status === 'solicitada')
                ? 'Aguardando aprovação'
                : (RequestWorkflow::LABELS[$status] ?? $status),
            'requester_id' => (int) $r['requester_id'],
            'requester' => ['id' => (int) $r['requester_id'], 'name' => $r['requester_name'] ?? null],
            'department' => $r['department_id'] ? ['id' => (int) $r['department_id'], 'name' => $r['department_name'] ?? null] : null,
            'industry' => $r['industry_id'] ? ['id' => (int) $r['industry_id'], 'name' => $r['industry_name'] ?? null] : null,
            'event' => $r['event_id'] ? ['id' => (int) $r['event_id'], 'name' => $r['event_name'] ?? null] : null,
            'purpose' => $r['purpose'],
            'recipient' => $r['recipient'],
            'needed_date' => $r['needed_date'],
            'purchase_ticket_no' => $r['purchase_ticket_no'] ?? null,
            'invoice_no' => $r['invoice_no'] ?? null,
            'invoice_url' => !empty($r['invoice_path']) ? url('/api/trade/requests/' . $r['id'] . '/invoice') : null,
            'action_type' => $r['action_type'] ?? null,
            'action_type_label' => TradeService::ACTION_TYPES[$r['action_type'] ?? ''] ?? ($r['action_type'] ?? null),
            'delivery_place' => $r['delivery_place'] ?? null,
            'public_code' => $r['public_code'] ?? $r['code'],
            'flow' => $r['flow'] ?? 'interna',
            'needs_purchase' => (bool) $r['needs_purchase'],
            'total_value' => Present::money($r['total_value']),
            'notes' => $r['notes'],
            'approval' => !empty($r['approval_id']) ? [
                'id' => (int) $r['approval_id'],
                'decision' => $r['approval_decision'],
                'justification' => $r['approval_justification'],
                'rule' => $r['rule_name'],
                'decided_at' => $r['approval_decided_at'],
                'decided_by' => $r['decided_by_name'],
            ] : null,
            'delivery' => !empty($r['delivery_id']) ? ['id' => (int) $r['delivery_id'], 'code' => $r['delivery_code']] : null,
            'submitted_at' => $r['submitted_at'] ?? null,
            'approved_at' => $r['approved_at'] ?? null,
            'finalized_at' => $r['finalized_at'] ?? null,
            'created_at' => $r['created_at'],
            'updated_at' => $r['updated_at'],
        ];
    }

    private static function lock(int $id): array
    {
        $row = Db::fetch('SELECT * FROM requests WHERE id = ? AND deleted_at IS NULL FOR UPDATE', [$id]);
        if ($row === null) {
            throw HttpException::notFound('Solicitação não encontrada.');
        }
        return $row;
    }

    private static function assertOwner(array $row): void
    {
        if ((int) $row['requester_id'] !== (int) Auth::id() && !Auth::isAdmin()) {
            throw HttpException::forbidden();
        }
    }

    private static function assertCanDecide(int $id): void
    {
        Auth::authorize('requests.approve');
        if (Auth::isAdmin()) {
            return;
        }
        $ap = Db::fetch('SELECT * FROM approvals WHERE request_id = ? AND decision = \'pendente\'', [$id]);
        if ($ap === null) {
            throw HttpException::rule('NOT_PENDING', 'Não há aprovação pendente nesta solicitação.');
        }
        $ok = ((int) ($ap['approver_user_id'] ?? 0) === (int) Auth::id())
            || ((int) ($ap['approver_role_id'] ?? 0) === (int) Auth::user()['role_id']);
        if (!$ok) {
            throw HttpException::forbidden('Esta solicitação não está atribuída a você.');
        }
    }

    private static function statusCounts(string $scopeSql, array $scopeParams): array
    {
        $rows = Db::fetchAll(
            "SELECT r.status, COUNT(*) AS n FROM requests r JOIN users u ON u.id = r.requester_id
              WHERE r.deleted_at IS NULL AND r.status <> 'rascunho' {$scopeSql} GROUP BY r.status",
            $scopeParams
        );
        $out = ['all' => 0];
        foreach (array_keys(RequestWorkflow::LABELS) as $s) {
            $out[$s] = 0;
        }
        foreach ($rows as $r) {
            $out[$r['status']] = (int) $r['n'];
            $out['all'] += (int) $r['n'];
        }
        return $out;
    }
}
