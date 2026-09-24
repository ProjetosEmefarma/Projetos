<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Auth;
use App\Core\Db;
use App\Core\HttpException;
use App\Core\Validator;

/**
 * TRADE purchase flow (as the client operates it):
 * TRADE solicita a compra → você aprova e informa o chamado →
 * o brinde chega no CD e a NF é anexada → estoque alimentado →
 * transferência / retirada com QR.
 *
 * Receiving writes an entrada. Withdrawal writes a saida. Transfer is not a saida.
 */
final class TradeService
{
    public const ACTION_TYPES = [
        'campanha' => 'Campanha',
        'premiacao' => 'Premiação',
        'evento' => 'Evento',
        'feirao' => 'Feirão',
        'outro' => 'Outro',
    ];

    public const KIND_LABELS = [
        'fisico' => 'Produto físico',
        'voucher' => 'Voucher',
        'cartao' => 'Cartão pré-pago / crédito',
        'outro' => 'Outro',
    ];

    public static function create(array $input): array
    {
        Auth::authorize('requests.create');
        $header = Validator::validate($input, [
            'industry_id' => 'required|int|exists:industries',
            'purpose' => 'required|string|max:255',
            'recipient' => 'nullable|string|max:150',
            'action_type' => 'nullable|in:campanha,premiacao,evento,feirao,outro',
            'delivery_place' => 'nullable|string|max:190',
            'event_id' => 'nullable|int|exists:events',
            'notes' => 'nullable|string|max:2000',
        ]);
        self::assertIndustryAccess((int) $header['industry_id']);
        $lines = self::parseLines($input['items'] ?? null);

        $id = Db::transaction(function () use ($header, $lines) {
            $code = QrService::nextPublicCode();
            $total = 0.0;
            foreach ($lines as $l) {
                $total += $l['qty_requested'] * (float) ($l['unit_value'] ?? 0);
            }
            $now = now();
            $id = Db::insert('requests', [
                'code' => $code,
                'public_code' => $code,
                'flow' => 'trade',
                'status' => 'solicitada',
                'requester_id' => Auth::id(),
                'department_id' => Auth::user()['department_id'] ?? null,
                'industry_id' => $header['industry_id'],
                'event_id' => $header['event_id'] ?? null,
                'purpose' => $header['purpose'],
                'recipient' => $header['recipient'] ?? null,
                'purchase_ticket_no' => null,
                'invoice_no' => null,
                'action_type' => $header['action_type'] ?? null,
                'delivery_place' => $header['delivery_place'] ?? 'CD Belford Roxo',
                'needs_purchase' => 1,
                'total_value' => round($total, 2),
                'notes' => $header['notes'] ?? null,
                'submitted_at' => $now,
                'status_changed_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            foreach ($lines as $l) {
                Db::insert('request_items', $l + ['request_id' => $id, 'created_at' => $now, 'updated_at' => $now]);
            }
            Db::insert('request_status_history', [
                'request_id' => $id,
                'from_status' => null,
                'to_status' => 'solicitada',
                'user_id' => Auth::id(),
                'comment' => 'Solicitação TRADE criada',
                'created_at' => $now,
            ]);
            Audit::log('create', 'request', $id, null, ['code' => $code, 'flow' => 'trade'], $code);
            return $id;
        });
        return RequestService::find($id);
    }

    /**
     * You approve the TRADE request and, only then, fill the purchase ticket number.
     * The invoice is NOT attached here — that happens when the goods arrive at the CD.
     */
    public static function approveWithTicket(int $id, array $input): array
    {
        Auth::authorize('requests.approve');
        $data = Validator::validate($input, [
            'purchase_ticket_no' => 'required|string|min:1|max:50',
            'notes' => 'nullable|string|max:2000',
        ]);
        Db::transaction(function () use ($id, $data) {
            $row = self::lockTrade($id);
            if ($row['status'] !== 'solicitada') {
                throw HttpException::conflict('INVALID_TRANSITION', 'Só é possível informar o chamado em uma solicitação aguardando aprovação.');
            }
            Db::update('requests', [
                'purchase_ticket_no' => trim($data['purchase_ticket_no']),
                'notes' => $data['notes'] ?? $row['notes'],
                'updated_at' => now(),
            ], ['id' => $id]);
            RequestWorkflow::apply($id, 'compra_realizada', 'Aprovado. Chamado de compra: ' . trim($data['purchase_ticket_no']));
            RequestWorkflow::apply($id, 'aguardando_recebimento', 'Aguardando chegada no CD para anexar a NF');
        });
        return RequestService::find($id);
    }

    /** Kept as alias so older clients still work; chamado is required, NF is not used here. */
    public static function markPurchased(int $id, array $input): array
    {
        return self::approveWithTicket($id, $input);
    }

    public static function reject(int $id, array $input): array
    {
        Auth::authorize('requests.approve');
        $data = Validator::validate($input, [
            'reason' => 'required|string|min:3|max:500',
        ]);
        Db::transaction(function () use ($id, $data) {
            $row = self::lockTrade($id);
            if ($row['status'] !== 'solicitada') {
                throw HttpException::conflict('INVALID_TRANSITION', 'Só é possível reprovar uma solicitação aguardando aprovação.');
            }
            RequestWorkflow::apply($id, 'reprovada', $data['reason']);
        });
        return RequestService::find($id);
    }

    public static function awaitReceipt(int $id): array
    {
        Auth::authorize('requests.create');
        Db::transaction(function () use ($id) {
            self::lockTrade($id);
            RequestWorkflow::apply($id, 'aguardando_recebimento', 'Aguardando recebimento no CD');
        });
        return RequestService::find($id);
    }

    /**
     * CD receiving. The NF is attached here, when the gift arrives.
     * Body: { items, invoice_no?, location_id?, notes? } + optional file "invoice"
     */
    public static function receive(int $id, array $input, ?array $invoiceFile = null): array
    {
        Auth::authorize(['stock.receive', 'stock.entry']);
        if (is_string($input['items'] ?? null)) {
            $decoded = json_decode((string) $input['items'], true);
            if (is_array($decoded)) {
                $input['items'] = $decoded;
            }
        }
        $data = Validator::validate($input, [
            'invoice_no' => 'nullable|string|max:60',
            'location_id' => 'nullable|int|exists:locations',
            'notes' => 'nullable|string|max:1000',
        ]);
        $lines = $input['items'] ?? [];
        if (!is_array($lines) || $lines === []) {
            throw HttpException::validation(['items' => 'Informe as quantidades recebidas.']);
        }
        if ($invoiceFile !== null && (int) ($invoiceFile['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
            throw HttpException::validation(['invoice' => 'Falha no envio da NF. Tente novamente.']);
        }
        if ($invoiceFile === null && empty($data['invoice_no']) && empty($input['invoice_no'])) {
            throw HttpException::validation(['invoice' => 'Anexe a NF (PDF ou imagem) quando o brinde chegar no CD.']);
        }

        Db::transaction(function () use ($id, $data, $lines, $invoiceFile) {
            $req = self::lockTrade($id);
            if (!in_array($req['status'], ['aguardando_recebimento', 'compra_realizada', 'recebido_cd'], true)) {
                throw HttpException::conflict('INVALID_TRANSITION', 'Só é possível receber no CD depois da aprovação e do número do chamado.');
            }
            $patch = ['updated_at' => now()];
            if (!empty($data['invoice_no'])) {
                $patch['invoice_no'] = $data['invoice_no'];
            }
            if ($invoiceFile !== null) {
                $patch['invoice_path'] = self::storeInvoice($invoiceFile);
            }
            if (count($patch) > 1) {
                Db::update('requests', $patch, ['id' => $id]);
            }
            $locationId = (int) ($data['location_id'] ?? StockService::cdLocationId());
            $reqItems = Db::fetchAll('SELECT * FROM request_items WHERE request_id = ? FOR UPDATE', [$id]);
            $byItem = [];
            foreach ($reqItems as $ri) {
                $byItem[(int) $ri['item_id']] = $ri;
            }
            $any = false;
            foreach ($lines as $i => $line) {
                if (!is_array($line)) {
                    continue;
                }
                $itemId = (int) ($line['item_id'] ?? 0);
                $qty = (int) ($line['qty'] ?? $line['quantity'] ?? 0);
                if ($itemId < 1 || $qty < 1) {
                    continue;
                }
                if (!isset($byItem[$itemId])) {
                    throw HttpException::validation(['items.' . $i => 'Este brinde não pertence à solicitação.']);
                }
                $any = true;
                StockService::entry($itemId, $qty, [
                    'industry_id' => $req['industry_id'],
                    'request_id' => $id,
                    'document_ref' => $data['invoice_no'] ?? $req['invoice_no'],
                    'purchase_ticket_no' => $req['purchase_ticket_no'],
                    'purpose' => 'Recebimento no CD',
                    'notes' => $data['notes'] ?? null,
                    'location_id' => $locationId,
                    'unit_value' => $byItem[$itemId]['unit_value'],
                ]);
                Db::update('request_items', [
                    'qty_received' => (int) $byItem[$itemId]['qty_received'] + $qty,
                    'updated_at' => now(),
                ], ['id' => (int) $byItem[$itemId]['id']]);
                $byItem[$itemId]['qty_received'] = (int) $byItem[$itemId]['qty_received'] + $qty;
            }
            if (!$any) {
                throw HttpException::validation(['items' => 'Informe ao menos uma quantidade recebida.']);
            }
            $all = Db::fetchAll('SELECT qty_requested, qty_received FROM request_items WHERE request_id = ?', [$id]);
            $complete = true;
            foreach ($all as $row) {
                if ((int) $row['qty_received'] < (int) $row['qty_requested']) {
                    $complete = false;
                    break;
                }
            }
            if ($req['status'] === 'compra_realizada') {
                RequestWorkflow::apply($id, 'aguardando_recebimento', 'NF anexada no recebimento do CD');
            }
            $st = (string) Db::value('SELECT status FROM requests WHERE id = ?', [$id]);
            if ($complete) {
                if ($st === 'aguardando_recebimento') {
                    RequestWorkflow::apply($id, 'recebido_cd', 'Recebido no CD');
                    RequestWorkflow::apply($id, 'pronta', 'Disponível para retirada');
                } elseif ($st === 'recebido_cd') {
                    RequestWorkflow::apply($id, 'pronta', 'Disponível para retirada');
                }
            } elseif ($st === 'aguardando_recebimento') {
                // partial — stay waiting
            }
        });
        return RequestService::find($id);
    }

    /**
     * Digital pickup (replaces the printed protocol).
     * { items: [{item_id, qty}], received_by_name, received_by_document?, recipient?, signature, notes? }
     */
    public static function withdraw(int $id, array $input): array
    {
        Auth::authorize(['requests.process', 'stock.exit', 'events.withdraw']);
        $data = Validator::validate($input, [
            'received_by_name' => 'required|string|max:150',
            'received_by_email' => 'nullable|email|max:190',
            'received_by_document' => 'nullable|string|max:30',
            'received_by_phone' => 'nullable|string|max:30',
            'recipient' => 'nullable|string|max:150',
            'signature' => 'required|string',
            'notes' => 'nullable|string|max:1000',
            'event_id' => 'nullable|int|exists:events',
            'location_id' => 'nullable|int|exists:locations',
        ]);
        $lines = $input['items'] ?? [];
        if (!is_array($lines) || $lines === []) {
            throw HttpException::validation(['items' => 'Informe o que está sendo retirado.']);
        }

        $deliveryId = Db::transaction(function () use ($id, $data, $lines) {
            $req = self::lockTrade($id);
            if (!in_array($req['status'], ['pronta', 'recebido_cd', 'retirado'], true)) {
                throw HttpException::conflict('INVALID_TRANSITION', 'A retirada só pode ser feita quando o brinde está pronto para retirada.');
            }
            $sig = ImageService::persistSignature((string) $data['signature']);
            $year = date('Y');
            $code = sprintf('PROT-%s-%04d', $year, Sequence::next('protocol', $year));
            $locationId = (int) ($data['location_id'] ?? StockService::cdLocationId());
            $did = Db::insert('deliveries', [
                'code' => $code,
                'type' => !empty($data['event_id']) ? 'evento' : 'dia_a_dia',
                'request_id' => $id,
                'event_id' => $data['event_id'] ?? $req['event_id'],
                'industry_id' => $req['industry_id'],
                'delivered_by' => Auth::id(),
                'received_by_name' => $data['received_by_name'],
                'received_by_email' => $data['received_by_email'] ?? null,
                'received_by_document' => $data['received_by_document'] ?? null,
                'received_by_phone' => $data['received_by_phone'] ?? null,
                'signature_path' => $sig['path'],
                'signature_png' => $sig['png'],
                'notes' => $data['notes'] ?? null,
                'created_at' => now(),
            ]);
            $reqItems = Db::fetchAll('SELECT * FROM request_items WHERE request_id = ? FOR UPDATE', [$id]);
            $byItem = [];
            foreach ($reqItems as $ri) {
                $byItem[(int) $ri['item_id']] = $ri;
            }
            $any = false;
            foreach ($lines as $line) {
                if (!is_array($line)) {
                    continue;
                }
                $itemId = (int) ($line['item_id'] ?? 0);
                $qty = (int) ($line['qty'] ?? $line['quantity'] ?? 0);
                if ($itemId < 1 || $qty < 1) {
                    continue;
                }
                if (!isset($byItem[$itemId])) {
                    throw HttpException::rule('ITEM_NOT_IN_REQUEST', 'Este brinde não pertence à solicitação.');
                }
                $ri = $byItem[$itemId];
                $max = (int) $ri['qty_received'] - (int) $ri['qty_delivered'];
                if ($max < 1) {
                    $max = (int) $ri['qty_requested'] - (int) $ri['qty_delivered'];
                }
                if ($qty > $max) {
                    throw HttpException::rule(
                        'STOCK_INSUFFICIENT',
                        "Não é possível realizar a retirada. Saldo disponível nesta solicitação: {$max} unidade(s).",
                        ['available' => $max, 'requested' => $qty, 'item_id' => $itemId]
                    );
                }
                $any = true;
                $before = StockService::snapshot($itemId)['available'];
                $result = StockService::exit($itemId, $qty, [
                    'industry_id' => $req['industry_id'],
                    'request_id' => $id,
                    'delivery_id' => $did,
                    'event_id' => $data['event_id'] ?? $req['event_id'],
                    'purpose' => $req['purpose'],
                    'recipient' => $data['recipient'] ?? $data['received_by_name'],
                    'purchase_ticket_no' => $req['purchase_ticket_no'],
                    'document_ref' => $req['invoice_no'],
                    'location_id' => $locationId,
                    'notes' => $data['notes'] ?? null,
                ]);
                Db::insert('delivery_items', [
                    'delivery_id' => $did,
                    'item_id' => $itemId,
                    'qty' => $qty,
                    'balance_after' => $result['stock']['available'],
                ]);
                Db::update('request_items', [
                    'qty_delivered' => (int) $ri['qty_delivered'] + $qty,
                    'updated_at' => now(),
                ], ['id' => (int) $ri['id']]);
                $byItem[$itemId]['qty_delivered'] = (int) $ri['qty_delivered'] + $qty;
                unset($before);
                DeliveryService::maybeStockAlert($itemId);
            }
            if (!$any) {
                throw HttpException::validation(['items' => 'Informe ao menos um item para retirada.']);
            }
            $left = (int) Db::value(
                'SELECT COALESCE(SUM((CASE WHEN qty_received > qty_requested THEN qty_received ELSE qty_requested END) - qty_delivered),0) FROM request_items WHERE request_id = ?',
                [$id]
            );
            $st = (string) Db::value('SELECT status FROM requests WHERE id = ?', [$id]);
            if ($st === 'recebido_cd') {
                RequestWorkflow::apply($id, 'pronta', 'Retirada');
                $st = 'pronta';
            }
            if ($st === 'pronta') {
                RequestWorkflow::apply($id, 'retirado', 'Protocolo ' . $code);
                $st = 'retirado';
            }
            if ($left <= 0 && $st === 'retirado') {
                RequestWorkflow::apply($id, 'entregue', 'Saldo da solicitação zerado');
            }
            Audit::log('withdraw', 'delivery', $did, null, ['code' => $code, 'request' => $req['code']], $code);
            return $did;
        });

        $mail = DeliveryService::finalizePdfAndMail($deliveryId);
        $out = DeliveryService::find($deliveryId);
        $out['mail'] = $mail;
        return $out;
    }

    public static function markDelivered(int $id): array
    {
        Auth::authorize('requests.process');
        Db::transaction(function () use ($id) {
            self::lockTrade($id);
            RequestWorkflow::apply($id, 'entregue', 'Entrega confirmada ao destinatário final');
        });
        return RequestService::find($id);
    }

    public static function lookup(string $code): array
    {
        $code = trim($code);
        if ($code === '') {
            throw HttpException::validation(['code' => 'Informe o código ou leia o QR Code.']);
        }
        [$scopeSql, $scopeParams] = RequestService::scope();
        $row = Db::fetch(
            RequestService::SELECT . ' ' . RequestService::FROM
            . ' WHERE r.deleted_at IS NULL AND (r.public_code = ? OR r.code = ?) ' . $scopeSql,
            [$code, $code, ...$scopeParams]
        );
        if ($row === null) {
            $ev = Db::fetch('SELECT id FROM events WHERE deleted_at IS NULL AND name = ?', [$code]);
            if ($ev) {
                return ['type' => 'event', 'event' => EventService::find((int) $ev['id'])];
            }
            throw HttpException::notFound('Código não encontrado.');
        }
        $out = RequestService::find((int) $row['id']);
        $out['type'] = 'request';
        $code = (string) ($out['public_code'] ?? $out['code']);
        $out['qr_svg'] = QrService::svg($code);
        $out['qr_png'] = $out['qr_png'] ?? QrService::pngDataUri($code, 5);
        return $out;
    }

    public static function stockByIndustry(?int $industryId = null): array
    {
        $where = ['i.deleted_at IS NULL'];
        $params = [];
        if ($forced = Auth::industryId()) {
            $where[] = 'm.industry_id = ?';
            $params[] = $forced;
        } elseif ($industryId) {
            $where[] = 'm.industry_id = ?';
            $params[] = $industryId;
        } else {
            $where[] = 'm.industry_id IS NOT NULL';
        }
        $rows = Db::fetchAll(
            "SELECT ind.id AS industry_id, ind.name AS industry_name,
                    i.id AS item_id, i.code, i.name AS item_name, i.kind,
                    COALESCE(SUM(CASE WHEN m.type = 'entrada' THEN m.qty ELSE 0 END), 0) AS received,
                    COALESCE(SUM(CASE WHEN m.type = 'saida' THEN -m.qty ELSE 0 END), 0) AS withdrawn,
                    COALESCE(SUM(CASE WHEN m.type IN ('entrada','saida','ajuste','estorno') THEN m.qty ELSE 0 END), 0) AS saldo
               FROM stock_movements m
               JOIN items i ON i.id = m.item_id
               JOIN industries ind ON ind.id = m.industry_id
              WHERE " . implode(' AND ', $where) . '
              GROUP BY ind.id, ind.name, i.id, i.code, i.name, i.kind
              ORDER BY ind.name, i.name',
            $params
        );
        return array_map(fn ($r) => [
            'industry' => ['id' => (int) $r['industry_id'], 'name' => $r['industry_name']],
            'item' => ['id' => (int) $r['item_id'], 'code' => $r['code'], 'name' => $r['item_name'], 'kind' => $r['kind']],
            'received' => (int) $r['received'],
            'withdrawn' => (int) $r['withdrawn'],
            'saldo' => (int) $r['saldo'],
        ], $rows);
    }

    public static function positions(?int $itemId = null): array
    {
        $where = ['p.qty > 0', 'i.deleted_at IS NULL'];
        $params = [];
        if ($itemId) {
            $where[] = 'p.item_id = ?';
            $params[] = $itemId;
        }
        $rows = Db::fetchAll(
            'SELECT p.item_id, i.code, i.name AS item_name, p.location_id, l.name AS location_name, l.kind, p.qty
               FROM stock_positions p
               JOIN items i ON i.id = p.item_id
               JOIN locations l ON l.id = p.location_id
              WHERE ' . implode(' AND ', $where) . ' ORDER BY l.kind, l.name, i.name',
            $params
        );
        return array_map(fn ($r) => [
            'item' => ['id' => (int) $r['item_id'], 'code' => $r['code'], 'name' => $r['item_name']],
            'location' => ['id' => (int) $r['location_id'], 'name' => $r['location_name'], 'kind' => $r['kind']],
            'qty' => (int) $r['qty'],
        ], $rows);
    }

    /**
     * Transfer CD → event (or any two locations). Not a withdrawal.
     * { item_id, quantity, industry_id, from_location_id?, event_id?, to_location_id?, notes? }
     */
    public static function transfer(array $input): array
    {
        Auth::authorize(['stock.transfer', 'stock.exit']);
        $data = Validator::validate($input, [
            'item_id' => 'required|int|exists:items',
            'quantity' => 'required|int|min:1|max:1000000',
            'industry_id' => 'required|int|exists:industries',
            'from_location_id' => 'nullable|int|exists:locations',
            'to_location_id' => 'nullable|int|exists:locations',
            'event_id' => 'nullable|int|exists:events',
            'notes' => 'nullable|string|max:1000',
        ]);
        self::assertIndustryAccess((int) $data['industry_id']);
        if (empty($data['event_id']) && empty($data['to_location_id'])) {
            throw HttpException::validation(['event_id' => 'Informe o evento ou o local de destino.']);
        }

        return Db::transaction(function () use ($data) {
            $from = (int) ($data['from_location_id'] ?? StockService::cdLocationId());
            $eventId = $data['event_id'] ?? null;
            $to = (int) ($data['to_location_id'] ?? 0);
            $event = null;
            if ($eventId) {
                $event = Db::fetch('SELECT * FROM events WHERE id = ? AND deleted_at IS NULL FOR UPDATE', [$eventId]);
                if ($event === null) {
                    throw HttpException::notFound('Evento não encontrado.');
                }
                if ($event['status'] === 'encerrado' || $event['status'] === 'cancelado') {
                    throw HttpException::rule('EVENT_CLOSED', 'Não é possível transferir para um evento encerrado.');
                }
                $to = self::ensureEventLocation($event);
            }
            $result = StockService::transfer((int) $data['item_id'], (int) $data['quantity'], $from, $to, [
                'industry_id' => $data['industry_id'],
                'event_id' => $eventId,
                'purpose' => $event ? ('Transferência para ' . $event['name']) : 'Transferência de estoque',
                'notes' => $data['notes'] ?? null,
            ]);
            if ($eventId) {
                $alloc = Db::fetch(
                    'SELECT * FROM event_allocations WHERE event_id = ? AND industry_id = ? AND item_id = ? FOR UPDATE',
                    [$eventId, $data['industry_id'], $data['item_id']]
                );
                if ($alloc === null) {
                    Db::insert('event_allocations', [
                        'event_id' => $eventId,
                        'industry_id' => $data['industry_id'],
                        'item_id' => $data['item_id'],
                        'qty_allocated' => $data['quantity'],
                        'qty_withdrawn' => 0,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                } else {
                    Db::update('event_allocations', [
                        'qty_allocated' => (int) $alloc['qty_allocated'] + (int) $data['quantity'],
                        'updated_at' => now(),
                    ], ['id' => (int) $alloc['id']]);
                }
                if ($event && $event['status'] === 'aberto') {
                    StockService::reserve((int) $data['item_id'], (int) $data['quantity'], 'transferência evento #' . $eventId);
                }
            }
            return $result + [
                'event_id' => $eventId,
                'industry_id' => (int) $data['industry_id'],
            ];
        });
    }

    public static function dashboardExtra(): array
    {
        [$scope, $params] = RequestService::scope();
        $row = Db::fetch(
            "SELECT COALESCE(SUM(CASE WHEN r.flow = 'trade' THEN 1 ELSE 0 END), 0) AS total,
                    COALESCE(SUM(CASE WHEN r.flow = 'trade' AND r.status = 'aguardando_recebimento' THEN 1 ELSE 0 END), 0) AS awaiting_receipt,
                    COALESCE(SUM(CASE WHEN r.flow = 'trade' AND r.status IN ('pronta','recebido_cd') THEN 1 ELSE 0 END), 0) AS ready,
                    COALESCE(SUM(CASE WHEN r.flow = 'trade' AND r.status = 'solicitada' THEN 1 ELSE 0 END), 0) AS awaiting_approval,
                    COALESCE(SUM(CASE WHEN r.flow = 'trade' AND r.status IN ('solicitada','compra_realizada','aguardando_recebimento') THEN 1 ELSE 0 END), 0) AS pending
               FROM requests r WHERE r.deleted_at IS NULL AND r.status <> 'rascunho' {$scope}",
            $params
        );
        $event = Db::fetch(
            "SELECT e.id, e.name,
                    COALESCE(SUM(a.qty_allocated),0) AS allocated,
                    COALESCE(SUM(a.qty_withdrawn),0) AS withdrawn
               FROM events e
          LEFT JOIN event_allocations a ON a.event_id = e.id
              WHERE e.deleted_at IS NULL AND e.status = 'aberto'
              GROUP BY e.id, e.name ORDER BY e.starts_on DESC, e.id DESC LIMIT 1"
        );
        return [
            'total' => (int) ($row['total'] ?? 0),
            'awaiting_receipt' => (int) ($row['awaiting_receipt'] ?? 0),
            'ready' => (int) ($row['ready'] ?? 0),
            'pending' => (int) ($row['pending'] ?? 0),
            'current_event' => $event ? [
                'id' => (int) $event['id'],
                'name' => $event['name'],
                'allocated' => (int) $event['allocated'],
                'withdrawn' => (int) $event['withdrawn'],
                'saldo' => (int) $event['allocated'] - (int) $event['withdrawn'],
            ] : null,
        ];
    }

    public static function invoiceAbsolute(int $id): string
    {
        $row = Db::fetch('SELECT industry_id, invoice_path FROM requests WHERE id = ? AND deleted_at IS NULL', [$id]);
        if ($row === null) {
            throw HttpException::notFound('Solicitação não encontrada.');
        }
        self::assertIndustryAccess($row['industry_id'] ? (int) $row['industry_id'] : null);
        if (empty($row['invoice_path'])) {
            throw HttpException::notFound('NF não anexada.');
        }
        $path = ImageService::absolute((string) $row['invoice_path']);
        if (!is_file($path)) {
            throw HttpException::notFound('Arquivo da NF não encontrado.');
        }
        return $path;
    }

    public static function invoiceMime(string $path): string
    {
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        return match ($ext) {
            'pdf' => 'application/pdf',
            'png' => 'image/png',
            default => 'image/jpeg',
        };
    }

    private static function storeInvoice(array $file): string
    {
        $tmp = (string) ($file['tmp_name'] ?? '');
        if ($tmp === '' || !is_file($tmp)) {
            throw HttpException::validation(['invoice' => 'Não foi possível ler o arquivo da NF.']);
        }
        if (!\App\Core\Config::isTesting() && !is_uploaded_file($tmp)) {
            throw HttpException::validation(['invoice' => 'Upload inválido.']);
        }
        $size = (int) ($file['size'] ?? 0);
        if ($size < 1 || $size > 10 * 1024 * 1024) {
            throw HttpException::validation(['invoice' => 'A NF deve ter no máximo 10 MB.']);
        }
        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $mime = (string) $finfo->file($tmp);
        $ext = match ($mime) {
            'application/pdf' => 'pdf',
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            default => null,
        };
        if ($ext === null) {
            throw HttpException::validation(['invoice' => 'Anexe a NF em PDF, JPG ou PNG.']);
        }
        $relative = 'uploads/invoices/' . bin2hex(random_bytes(12)) . '.' . $ext;
        $dest = ImageService::absolute($relative);
        if (!is_dir(dirname($dest))) {
            mkdir(dirname($dest), 0775, true);
        }
        if (!@copy($tmp, $dest)) {
            throw HttpException::validation(['invoice' => 'Falha ao gravar a NF.']);
        }
        return $relative;
    }

    private static function parseLines(mixed $items): array
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
                'unit_value' => 'nullable|numeric|min:0|max:9999999999',
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
                'qty_approved' => $row['qty_requested'],
                'qty_reserved' => 0,
                'qty_delivered' => 0,
                'qty_received' => 0,
                'unit_value' => $row['unit_value'] ?? $item['unit_value'],
                'notes' => $row['notes'] ?? null,
            ];
        }
        if ($out === []) {
            throw HttpException::validation(['items' => 'Inclua pelo menos um brinde.']);
        }
        return $out;
    }

    private static function lockTrade(int $id): array
    {
        $row = Db::fetch('SELECT * FROM requests WHERE id = ? AND deleted_at IS NULL FOR UPDATE', [$id]);
        if ($row === null) {
            throw HttpException::notFound('Solicitação não encontrada.');
        }
        if (($row['flow'] ?? 'interna') !== 'trade') {
            throw HttpException::rule('NOT_TRADE', 'Esta operação é exclusiva do fluxo TRADE.');
        }
        self::assertIndustryAccess($row['industry_id'] ? (int) $row['industry_id'] : null);
        return $row;
    }

    private static function assertIndustryAccess(?int $industryId): void
    {
        $mine = Auth::industryId();
        if ($mine && $industryId !== $mine) {
            throw HttpException::forbidden('Você só pode acessar dados da sua indústria.');
        }
    }

    private static function ensureEventLocation(array $event): int
    {
        if (!empty($event['location_id'])) {
            return (int) $event['location_id'];
        }
        $lid = (int) Db::insert('locations', [
            'name' => 'Evento: ' . $event['name'],
            'description' => 'Estoque temporário do evento (não é retirada)',
            'kind' => 'evento',
            'active' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        Db::update('events', ['location_id' => $lid, 'updated_at' => now()], ['id' => (int) $event['id']]);
        return $lid;
    }
}
