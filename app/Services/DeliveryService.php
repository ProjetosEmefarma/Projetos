<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Auth;
use App\Core\Db;
use App\Core\HttpException;
use App\Core\Paginator;
use App\Core\Request;
use App\Core\Validator;
use App\Support\Present;
use Throwable;

final class DeliveryService
{
    public const SELECT = 'SELECT d.id, d.code, d.type, d.request_id, d.event_id, d.industry_id,
            d.delivered_by, d.received_by_name, d.received_by_document, d.received_by_email, d.received_by_phone,
            d.signature_path, d.pdf_path, d.verification_hash, d.notes, d.created_at,
            (d.signature_path IS NOT NULL OR d.signature_png IS NOT NULL) AS has_signature,
            u.name AS delivered_by_name, i.name AS industry_name, i.contact_email AS industry_email, r.code AS request_code, e.name AS event_name';
    public const FROM = 'FROM deliveries d
            JOIN users u ON u.id = d.delivered_by
       LEFT JOIN industries i ON i.id = d.industry_id
       LEFT JOIN requests r ON r.id = d.request_id
       LEFT JOIN events e ON e.id = d.event_id';

    public static function list(Request $request): array
    {
        $where = [];
        $params = [];
        if ($iid = Auth::industryId()) {
            $where[] = 'd.industry_id = ?';
            $params[] = $iid;
        }
        if (in_array($request->query('type'), ['dia_a_dia', 'evento'], true)) {
            $where[] = 'd.type = ?';
            $params[] = $request->query('type');
        }
        foreach (['event_id' => 'd.event_id', 'industry_id' => 'd.industry_id', 'request_id' => 'd.request_id'] as $k => $c) {
            if (($v = $request->queryInt($k)) !== null) {
                $where[] = "{$c} = ?";
                $params[] = $v;
            }
        }
        if (($q = (string) $request->query('q', '')) !== '') {
            $where[] = '(d.code LIKE ? OR d.received_by_name LIKE ? OR r.code LIKE ?)';
            array_push($params, Db::like($q), Db::like($q), Db::like($q));
        }
        if (\App\Controllers\StockController::isDate($from = (string) $request->query('from', ''))) {
            $where[] = 'd.created_at >= ?';
            $params[] = $from . ' 00:00:00';
        }
        if (\App\Controllers\StockController::isDate($to = (string) $request->query('to', ''))) {
            $where[] = 'd.created_at <= ?';
            $params[] = $to . ' 23:59:59';
        }
        $sqlWhere = $where ? ' WHERE ' . implode(' AND ', $where) : '';
        [$rows, $meta] = Paginator::run($request, self::SELECT, self::FROM . $sqlWhere, $params, 'ORDER BY d.created_at DESC, d.id DESC');
        return [array_map([self::class, 'present'], $rows), $meta];
    }

    public static function find(int $id): array
    {
        $row = Db::fetch(self::SELECT . ' ' . self::FROM . ' WHERE d.id = ?', [$id]);
        if ($row === null) {
            throw HttpException::notFound('Protocolo não encontrado.');
        }
        $out = self::present($row);
        $out['items'] = self::items($id);
        return $out;
    }

    /** Day-to-day delivery: request must be `pronta`. Duplicate write-off is blocked. */
    public static function deliverRequest(int $requestId, array $input): array
    {
        Auth::authorize('requests.process');
        $data = Validator::validate($input, [
            'received_by_name' => 'required|string|max:150',
            'received_by_email' => 'nullable|email|max:190',
            'received_by_document' => 'nullable|string|max:30',
            'received_by_phone' => 'nullable|string|max:30',
            'signature' => 'required|string',
            'notes' => 'nullable|string|max:1000',
        ]);
        $deliveryId = Db::transaction(function () use ($requestId, $data) {
            $req = Db::fetch('SELECT * FROM requests WHERE id = ? AND deleted_at IS NULL FOR UPDATE', [$requestId]);
            if ($req === null) {
                throw HttpException::notFound('Solicitação não encontrada.');
            }
            if ($req['status'] !== 'pronta') {
                throw HttpException::conflict('INVALID_TRANSITION', 'A entrega só pode ser registrada quando a solicitação está pronta para retirada.');
            }
            $existing = Db::fetch('SELECT id FROM deliveries WHERE request_id = ?', [$requestId]);
            if ($existing) {
                throw HttpException::conflict('DUPLICATE_DELIVERY', 'Esta solicitação já possui um protocolo de entrega.');
            }
            $sig = ImageService::persistSignature((string) $data['signature']);
            $year = date('Y');
            $code = sprintf('PROT-%s-%04d', $year, Sequence::next('protocol', $year));
            $id = Db::insert('deliveries', [
                'code' => $code,
                'type' => 'dia_a_dia',
                'request_id' => $requestId,
                'industry_id' => $req['industry_id'],
                'delivered_by' => Auth::id(),
                'received_by_name' => $data['received_by_name'],
                'received_by_email' => $data['received_by_email'],
                'received_by_document' => $data['received_by_document'],
                'received_by_phone' => $data['received_by_phone'],
                'signature_path' => $sig['path'],
                'signature_png' => $sig['png'],
                'notes' => $data['notes'] ?? null,
                'created_at' => now(),
            ]);
            $lines = Db::fetchAll('SELECT * FROM request_items WHERE request_id = ? FOR UPDATE', [$requestId]);
            foreach ($lines as $line) {
                $qty = (int) $line['qty_reserved'] - (int) $line['qty_delivered'];
                if ($qty <= 0) {
                    continue;
                }
                $result = StockService::consumeReserved((int) $line['item_id'], $qty, [
                    'purpose' => $req['purpose'],
                    'recipient' => $data['received_by_name'],
                    'industry_id' => $req['industry_id'],
                    'department_id' => $req['department_id'],
                    'requester_id' => $req['requester_id'],
                    'request_id' => $requestId,
                    'delivery_id' => $id,
                    'purchase_ticket_no' => $req['purchase_ticket_no'],
                ]);
                $after = $result['stock']['on_hand'];
                Db::insert('delivery_items', [
                    'delivery_id' => $id,
                    'item_id' => (int) $line['item_id'],
                    'qty' => $qty,
                    'balance_after' => $after,
                ]);
                Db::update('request_items', [
                    'qty_delivered' => (int) $line['qty_delivered'] + $qty,
                    'qty_reserved' => (int) $line['qty_delivered'] + $qty,
                ], ['id' => (int) $line['id']]);
                self::maybeStockAlert((int) $line['item_id']);
            }
            RequestWorkflow::apply($requestId, 'finalizada', 'Protocolo ' . $code);
            Audit::log('deliver', 'delivery', $id, null, ['code' => $code, 'request' => $req['code']], $code);
            return $id;
        });

        self::finalizePdfAndMail($deliveryId);
        return self::find($deliveryId);
    }

    public static function present(array $d): array
    {
        return [
            'id' => (int) $d['id'],
            'code' => $d['code'],
            'type' => $d['type'],
            'type_label' => $d['type'] === 'evento' ? 'Evento' : 'Dia a dia',
            'request' => $d['request_id'] ? ['id' => (int) $d['request_id'], 'code' => $d['request_code'] ?? null] : null,
            'event' => $d['event_id'] ? ['id' => (int) $d['event_id'], 'name' => $d['event_name'] ?? null] : null,
            'industry' => $d['industry_id'] ? [
                'id' => (int) $d['industry_id'],
                'name' => $d['industry_name'] ?? null,
                'contact_email' => $d['industry_email'] ?? null,
            ] : null,
            'delivered_by' => ['id' => (int) $d['delivered_by'], 'name' => $d['delivered_by_name'] ?? null],
            'received_by_name' => $d['received_by_name'],
            'received_by_email' => $d['received_by_email'],
            'received_by_document' => $d['received_by_document'],
            'received_by_phone' => $d['received_by_phone'],
            'signature_url' => (!empty($d['signature_path']) || !empty($d['has_signature']) || !empty($d['signature_png']))
                ? url('/api/deliveries/' . $d['id'] . '/signature') : null,
            'pdf_url' => absolute_url('/api/deliveries/' . $d['id'] . '/pdf'),
            'verification_hash' => $d['verification_hash'],
            'notes' => $d['notes'],
            'created_at' => $d['created_at'],
        ];
    }

    public static function items(int $deliveryId): array
    {
        $delivery = Db::fetch('SELECT request_id, event_id, industry_id FROM deliveries WHERE id = ?', [$deliveryId]);
        $rows = Db::fetchAll(
            'SELECT di.*, i.code, i.name FROM delivery_items di JOIN items i ON i.id = di.item_id WHERE di.delivery_id = ?',
            [$deliveryId]
        );
        return array_map(function (array $r) use ($delivery) {
            $qty = (int) $r['qty'];
            $stored = $r['balance_after'] !== null ? (int) $r['balance_after'] : null;
            $remaining = self::remainingForLine($delivery ?? [], (int) $r['item_id'], $stored);
            return [
                'item' => ['id' => (int) $r['item_id'], 'code' => $r['code'], 'name' => $r['name']],
                'qty' => $qty,
                'qty_withdrawn' => $qty,
                'balance_after' => $remaining,
                'remaining' => $remaining,
            ];
        }, $rows);
    }

    /** Remaining units after this withdrawal: event quota, TRADE request, or stock. */
    private static function remainingForLine(array $delivery, int $itemId, ?int $stored): int
    {
        if (!empty($delivery['event_id']) && !empty($delivery['industry_id'])) {
            $alloc = Db::fetch(
                'SELECT qty_allocated, qty_withdrawn FROM event_allocations WHERE event_id = ? AND industry_id = ? AND item_id = ?',
                [$delivery['event_id'], $delivery['industry_id'], $itemId]
            );
            if ($alloc) {
                return max(0, (int) $alloc['qty_allocated'] - (int) $alloc['qty_withdrawn']);
            }
        }
        if (!empty($delivery['request_id'])) {
            $ri = Db::fetch(
                'SELECT qty_received, qty_requested, qty_delivered FROM request_items WHERE request_id = ? AND item_id = ?',
                [$delivery['request_id'], $itemId]
            );
            if ($ri) {
                $have = max((int) $ri['qty_received'], (int) $ri['qty_requested']);
                return max(0, $have - (int) $ri['qty_delivered']);
            }
        }
        if ($stored !== null) {
            return max(0, $stored);
        }
        try {
            return max(0, (int) (StockService::snapshot($itemId)['available'] ?? 0));
        } catch (Throwable) {
            return 0;
        }
    }

    public static function pdfPath(int $id): string
    {
        $row = Db::fetch('SELECT id FROM deliveries WHERE id = ?', [$id]);
        if ($row === null) {
            throw HttpException::notFound('Protocolo não encontrado.');
        }
        $relative = PdfService::generate($id);
        $abs = ImageService::absolute($relative);
        if (!is_file($abs)) {
            throw HttpException::notFound('Não foi possível gerar o PDF do protocolo.');
        }
        return $abs;
    }

    public static function signaturePath(int $id): string
    {
        $row = Db::fetch('SELECT signature_path, signature_png FROM deliveries WHERE id = ?', [$id]);
        if ($row === null) {
            throw HttpException::notFound('Protocolo não encontrado.');
        }
        $rel = (string) ($row['signature_path'] ?? '');
        $abs = $rel !== '' ? ImageService::absolute($rel) : '';
        if ($abs !== '' && is_file($abs)) {
            return $abs;
        }
        $b64 = (string) ($row['signature_png'] ?? '');
        if ($b64 === '') {
            throw HttpException::notFound('Assinatura não encontrada.');
        }
        $bin = base64_decode($b64, true);
        if ($bin === false || $bin === '') {
            throw HttpException::notFound('Assinatura não encontrada.');
        }
        $rel = $rel !== '' ? $rel : ('signatures/prot-' . $id . '.png');
        return ImageService::writeBytes($rel, $bin);
    }

    public static function resend(int $id): void
    {
        $d = self::find($id);
        $email = $d['received_by_email'];
        if (!$email && $d['industry']) {
            $email = Db::value('SELECT contact_email FROM industries WHERE id = ?', [$d['industry']['id']]);
        }
        NotificationService::protocolEmail(
            $d + ['pdf_path' => Db::value('SELECT pdf_path FROM deliveries WHERE id = ?', [$id]), 'user_id' => Auth::id()],
            $d['items'],
            $email ? (string) $email : null
        );
        NotificationService::flush();
    }

    public static function finalizePdfAndMail(int $deliveryId): array
    {
        try {
            PdfService::generate($deliveryId);
        } catch (Throwable $e) {
            \App\Core\Log::error('PDF protocol failed', ['id' => $deliveryId, 'error' => $e->getMessage()]);
        }
        $addresses = [];
        try {
            $d = self::find($deliveryId);
            $industryEmail = null;
            if ($d['industry']) {
                $industryEmail = Db::value('SELECT contact_email FROM industries WHERE id = ?', [$d['industry']['id']]);
            }
            $remaining = null;
            if ($d['type'] === 'evento' && $d['event'] && $d['industry']) {
                $remaining = (int) Db::value(
                    'SELECT COALESCE(SUM(qty_allocated - qty_withdrawn),0) FROM event_allocations WHERE event_id = ? AND industry_id = ?',
                    [$d['event']['id'], $d['industry']['id']]
                );
            }
            $row = Db::fetch('SELECT pdf_path FROM deliveries WHERE id = ?', [$deliveryId]);
            $addresses = NotificationService::protocolEmail($d + ['pdf_path' => $row['pdf_path'] ?? null], $d['items'], $industryEmail ? (string) $industryEmail : null, $remaining);
            NotificationService::flush();
        } catch (Throwable $e) {
            \App\Core\Log::error('Protocol e-mail failed', ['id' => $deliveryId, 'error' => $e->getMessage()]);
        }
        return [
            'addresses' => $addresses,
            'driver' => Mailer::driver(),
            'smtp' => Mailer::isSmtp(),
        ];
    }

    public static function maybeStockAlert(int $itemId): void
    {
        $item = Db::fetch('SELECT i.code, i.name, i.min_stock, s.qty_on_hand, s.qty_reserved FROM items i JOIN stock s ON s.item_id = i.id WHERE i.id = ?', [$itemId]);
        if (!$item) {
            return;
        }
        $available = (int) $item['qty_on_hand'] - (int) $item['qty_reserved'];
        $level = StockService::level($available, (int) $item['min_stock']);
        if ($level !== 'ok') {
            NotificationService::stockAlert($itemId, $item['code'], $item['name'], $level);
        }
    }
}
