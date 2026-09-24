<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Auth;
use App\Core\Db;
use App\Core\HttpException;
use App\Core\Paginator;
use App\Core\Request;
use App\Core\Validator;

final class EventService
{
    public const LABELS = [
        'planejado' => 'Planejado',
        'aberto' => 'Aberto',
        'encerrado' => 'Encerrado',
        'cancelado' => 'Cancelado',
    ];

    public static function list(Request $request): array
    {
        $where = ['deleted_at IS NULL'];
        $params = [];
        if (($q = (string) $request->query('q', '')) !== '') {
            $where[] = '(name LIKE ? OR venue LIKE ?)';
            array_push($params, Db::like($q), Db::like($q));
        }
        if (isset(self::LABELS[(string) $request->query('status', '')])) {
            $where[] = 'status = ?';
            $params[] = $request->query('status');
        }
        [$rows, $meta] = Paginator::run(
            $request,
            'SELECT *',
            'FROM events WHERE ' . implode(' AND ', $where),
            $params,
            'ORDER BY starts_on DESC, id DESC'
        );
        return [array_map([self::class, 'present'], $rows), $meta];
    }

    public static function find(int $id): array
    {
        $row = Db::fetch('SELECT * FROM events WHERE id = ? AND deleted_at IS NULL', [$id]);
        if ($row === null) {
            throw HttpException::notFound('Evento não encontrado.');
        }
        $out = self::present($row);
        $out['allocations'] = self::allocations($id);
        $out['industries'] = self::industryBalances($id);
        $out['returns'] = array_values(array_filter(
            $out['allocations'],
            static fn (array $a): bool => $a['saldo'] > 0
        ));
        return $out;
    }

    public static function create(array $input): array
    {
        $data = Validator::validate($input, [
            'name' => 'required|string|max:150',
            'description' => 'nullable|string|max:2000',
            'venue' => 'nullable|string|max:190',
            'starts_on' => 'nullable|date',
            'ends_on' => 'nullable|date',
            'notes' => 'nullable|string|max:2000',
        ]);
        $id = Db::transaction(function () use ($data) {
            $id = Db::insert('events', $data + [
                'status' => 'planejado',
                'created_by' => Auth::id(),
                'updated_by' => Auth::id(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            Audit::log('create', 'event', $id, null, $data, $data['name']);
            return $id;
        });
        return self::find($id);
    }

    public static function update(int $id, array $input): array
    {
        $data = Validator::validate($input, [
            'name' => 'sometimes|required|string|max:150',
            'description' => 'sometimes|nullable|string|max:2000',
            'venue' => 'sometimes|nullable|string|max:190',
            'starts_on' => 'sometimes|nullable|date',
            'ends_on' => 'sometimes|nullable|date',
            'notes' => 'sometimes|nullable|string|max:2000',
        ]);
        Db::transaction(function () use ($id, $data) {
            $before = self::lock($id);
            if ($before['status'] === 'encerrado') {
                throw HttpException::rule('EVENT_CLOSED', 'Evento encerrado não pode ser editado.');
            }
            if ($data !== []) {
                Db::update('events', $data + ['updated_by' => Auth::id(), 'updated_at' => now()], ['id' => $id]);
                Audit::logUpdate('event', $id, $before, $data, $data['name'] ?? $before['name']);
            }
        });
        return self::find($id);
    }

    /** Replace allocations while the event is still planejado. Body: [{industry_id, item_id, qty_allocated}] */
    public static function saveAllocations(int $id, array $rows): array
    {
        Db::transaction(function () use ($id, $rows) {
            $event = self::lock($id);
            if ($event['status'] !== 'planejado') {
                throw HttpException::rule('EVENT_NOT_PLANNED', 'As cotas só podem ser alteradas enquanto o evento está planejado.');
            }
            Db::query('DELETE FROM event_allocations WHERE event_id = ?', [$id]);
            foreach ($rows as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $d = Validator::validate($row, [
                    'industry_id' => 'required|int|exists:industries',
                    'item_id' => 'required|int|exists:items',
                    'qty_allocated' => 'required|int|min:0|max:1000000',
                ]);
                if ($d['qty_allocated'] === 0) {
                    continue;
                }
                Db::insert('event_allocations', [
                    'event_id' => $id,
                    'industry_id' => $d['industry_id'],
                    'item_id' => $d['item_id'],
                    'qty_allocated' => $d['qty_allocated'],
                    'qty_withdrawn' => 0,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
            Audit::log('update', 'event', $id, null, ['allocations' => count($rows)], $event['name']);
        });
        return self::find($id);
    }

    public static function open(int $id): array
    {
        Db::transaction(function () use ($id) {
            $event = self::lock($id);
            if ($event['status'] !== 'planejado') {
                throw HttpException::rule('EVENT_NOT_PLANNED', 'Somente eventos planejados podem ser abertos.');
            }
            $sums = Db::fetchAll(
                'SELECT item_id, SUM(qty_allocated) AS qty FROM event_allocations WHERE event_id = ? GROUP BY item_id',
                [$id]
            );
            if ($sums === []) {
                throw HttpException::rule('NO_ALLOCATIONS', 'Defina as cotas por indústria antes de abrir o evento.');
            }
            foreach ($sums as $s) {
                StockService::reserve((int) $s['item_id'], (int) $s['qty'], 'evento #' . $id);
            }
            Db::update('events', [
                'status' => 'aberto',
                'opened_at' => now(),
                'opened_by' => Auth::id(),
                'updated_at' => now(),
            ], ['id' => $id]);
            Audit::log('open', 'event', $id, ['status' => 'planejado'], ['status' => 'aberto'], $event['name']);
        });
        return self::find($id);
    }

    public static function close(int $id): array
    {
        Db::transaction(function () use ($id) {
            $event = self::lock($id);
            if ($event['status'] !== 'aberto') {
                throw HttpException::rule('EVENT_NOT_OPEN', 'Somente eventos abertos podem ser encerrados.');
            }
            $rows = Db::fetchAll('SELECT item_id, SUM(qty_allocated - qty_withdrawn) AS leftover FROM event_allocations WHERE event_id = ? GROUP BY item_id', [$id]);
            foreach ($rows as $r) {
                $left = (int) $r['leftover'];
                if ($left > 0) {
                    StockService::release((int) $r['item_id'], $left, 'encerramento evento #' . $id);
                }
            }
            Db::update('events', [
                'status' => 'encerrado',
                'closed_at' => now(),
                'closed_by' => Auth::id(),
                'updated_at' => now(),
            ], ['id' => $id]);
            Audit::log('close', 'event', $id, ['status' => 'aberto'], ['status' => 'encerrado'], $event['name']);
        });
        $found = self::find($id);
        self::sendCloseSummaries($id);
        return $found;
    }

    /**
     * Record leftover gifts going back to the CD.
     * Releases the event reservation (if still open) and, when stock was
     * physically transferred to the event location, moves it back to the CD.
     * Body: { items: [{ industry_id, item_id, qty }] }
     */
    public static function returnToCd(int $eventId, array $input): array
    {
        Auth::authorize(['events.manage', 'stock.transfer']);
        $lines = $input['items'] ?? [];
        if (!is_array($lines) || $lines === []) {
            throw HttpException::validation(['items' => 'Informe o que está voltando para o CD.']);
        }

        Db::transaction(function () use ($eventId, $lines) {
            $event = self::lock($eventId);
            if (!in_array($event['status'], ['aberto', 'encerrado'], true)) {
                throw HttpException::rule('EVENT_NOT_OPEN', 'A devolução ao CD só vale para eventos abertos ou encerrados.');
            }
            $open = $event['status'] === 'aberto';
            $from = !empty($event['location_id']) ? (int) $event['location_id'] : 0;
            $to = StockService::cdLocationId();
            $any = false;
            foreach ($lines as $line) {
                if (!is_array($line)) {
                    continue;
                }
                $d = Validator::validate($line, [
                    'industry_id' => 'required|int|exists:industries',
                    'item_id' => 'required|int|exists:items',
                    'qty' => 'required|int|min:1|max:1000000',
                ]);
                $alloc = Db::fetch(
                    'SELECT * FROM event_allocations WHERE event_id = ? AND industry_id = ? AND item_id = ? FOR UPDATE',
                    [$eventId, $d['industry_id'], $d['item_id']]
                );
                if ($alloc === null) {
                    throw HttpException::rule('ALLOCATION_EXCEEDED', 'Este brinde não está na cota desta indústria.');
                }
                $leftover = (int) $alloc['qty_allocated'] - (int) $alloc['qty_withdrawn'];
                if ((int) $d['qty'] > $leftover) {
                    throw HttpException::rule(
                        'STOCK_INSUFFICIENT',
                        "Não há essa quantidade para devolver. Saldo do evento: {$leftover}.",
                        ['available' => $leftover, 'requested' => (int) $d['qty'], 'item_id' => (int) $d['item_id']]
                    );
                }
                if ($open) {
                    StockService::release((int) $d['item_id'], (int) $d['qty'], 'devolução ao CD — evento #' . $eventId);
                }
                if ($from > 0 && $from !== $to) {
                    $atEvent = (int) Db::value(
                        'SELECT COALESCE(qty, 0) FROM stock_positions WHERE item_id = ? AND location_id = ?',
                        [$d['item_id'], $from]
                    );
                    $move = min((int) $d['qty'], $atEvent);
                    if ($move > 0) {
                        StockService::transfer((int) $d['item_id'], $move, $from, $to, [
                            'industry_id' => $d['industry_id'],
                            'event_id' => $eventId,
                            'purpose' => 'Devolução ao CD: ' . $event['name'],
                            'notes' => 'Saldo do evento voltando para o estoque do CD',
                        ]);
                    }
                }
                Db::update('event_allocations', [
                    'qty_allocated' => (int) $alloc['qty_allocated'] - (int) $d['qty'],
                    'updated_at' => now(),
                ], ['id' => (int) $alloc['id']]);
                $any = true;
            }
            if (!$any) {
                throw HttpException::validation(['items' => 'Informe o que está voltando para o CD.']);
            }
            Audit::log('return', 'event', $eventId, null, ['items' => count($lines)], $event['name']);
        });
        return self::find($eventId);
    }

    /**
     * Fast-path withdrawal. Body:
     * { industry_id, items: [{item_id, qty}], received_by_name, received_by_email?, signature, notes? }
     */
    public static function withdraw(int $eventId, array $input): array
    {
        Auth::authorize('events.withdraw');
        $data = Validator::validate($input, [
            'industry_id' => 'required|int|exists:industries',
            'received_by_name' => 'required|string|max:150',
            'received_by_email' => 'nullable|email|max:190',
            'received_by_document' => 'nullable|string|max:30',
            'signature' => 'required|string',
            'notes' => 'nullable|string|max:500',
        ]);
        $items = $input['items'] ?? [];
        if (!is_array($items) || $items === []) {
            throw HttpException::validation(['items' => 'Informe ao menos um item para retirada.']);
        }

        $deliveryId = Db::transaction(function () use ($eventId, $data, $items) {
            $event = self::lock($eventId);
            if ($event['status'] !== 'aberto') {
                throw HttpException::rule('EVENT_NOT_OPEN', 'O evento não está aberto para retiradas.');
            }
            $sig = ImageService::persistSignature((string) $data['signature']);
            $year = date('Y');
            $code = sprintf('PROT-%s-%04d', $year, Sequence::next('protocol', $year));
            $id = Db::insert('deliveries', [
                'code' => $code,
                'type' => 'evento',
                'event_id' => $eventId,
                'industry_id' => $data['industry_id'],
                'delivered_by' => Auth::id(),
                'received_by_name' => $data['received_by_name'],
                'received_by_email' => $data['received_by_email'],
                'received_by_document' => $data['received_by_document'],
                'signature_path' => $sig['path'],
                'signature_png' => $sig['png'],
                'notes' => $data['notes'] ?? null,
                'created_at' => now(),
            ]);
            foreach ($items as $it) {
                if (!is_array($it)) {
                    continue;
                }
                $itemId = (int) ($it['item_id'] ?? 0);
                $qty = (int) ($it['qty'] ?? $it['quantity'] ?? 0);
                if ($itemId < 1 || $qty < 1) {
                    continue;
                }
                $alloc = Db::fetch(
                    'SELECT * FROM event_allocations WHERE event_id = ? AND industry_id = ? AND item_id = ? FOR UPDATE',
                    [$eventId, $data['industry_id'], $itemId]
                );
                if ($alloc === null) {
                    throw HttpException::rule('ALLOCATION_EXCEEDED', 'Este brinde não está na cota desta indústria.');
                }
                $saldo = (int) $alloc['qty_allocated'] - (int) $alloc['qty_withdrawn'];
                if ($qty > $saldo) {
                    throw HttpException::rule(
                        'ALLOCATION_EXCEEDED',
                        "Cota insuficiente: saldo {$saldo}, solicitado {$qty}.",
                        ['available' => $saldo, 'requested' => $qty, 'item_id' => $itemId]
                    );
                }
                $result = StockService::consumeReserved($itemId, $qty, [
                    'purpose' => 'Evento: ' . $event['name'],
                    'recipient' => $data['received_by_name'],
                    'industry_id' => $data['industry_id'],
                    'event_id' => $eventId,
                    'delivery_id' => $id,
                    'location_id' => !empty($event['location_id']) ? (int) $event['location_id'] : StockService::cdLocationId(),
                ]);
                Db::update('event_allocations', [
                    'qty_withdrawn' => (int) $alloc['qty_withdrawn'] + $qty,
                    'updated_at' => now(),
                ], ['id' => (int) $alloc['id']]);
                Db::insert('delivery_items', [
                    'delivery_id' => $id,
                    'item_id' => $itemId,
                    'qty' => $qty,
                    'balance_after' => $saldo - $qty,
                ]);
                DeliveryService::maybeStockAlert($itemId);
            }
            Audit::log('withdraw', 'delivery', $id, null, ['code' => $code, 'event' => $event['name']], $code);
            return $id;
        });

        $mail = DeliveryService::finalizePdfAndMail($deliveryId);
        $out = DeliveryService::find($deliveryId);
        $out['mail'] = $mail;
        return $out;
    }

    public static function industryBalance(int $eventId, int $industryId): array
    {
        $rows = Db::fetchAll(
            'SELECT a.*, i.code, i.name, i.photo_path
               FROM event_allocations a JOIN items i ON i.id = a.item_id
              WHERE a.event_id = ? AND a.industry_id = ?
              ORDER BY i.name',
            [$eventId, $industryId]
        );
        return array_map(function (array $r) {
            $version = $r['photo_path'] ? substr(md5((string) $r['photo_path']), 0, 8) : null;
            return [
                'item' => [
                    'id' => (int) $r['item_id'],
                    'code' => $r['code'],
                    'name' => $r['name'],
                    'thumb_url' => $version ? url('/api/items/' . $r['item_id'] . '/photo') . '?size=thumb&v=' . $version : null,
                ],
                'qty_allocated' => (int) $r['qty_allocated'],
                'qty_withdrawn' => (int) $r['qty_withdrawn'],
                'saldo' => (int) $r['qty_allocated'] - (int) $r['qty_withdrawn'],
            ];
        }, $rows);
    }

    public static function present(array $e): array
    {
        return [
            'id' => (int) $e['id'],
            'name' => $e['name'],
            'description' => $e['description'] ?? null,
            'venue' => $e['venue'] ?? null,
            'starts_on' => $e['starts_on'] ?? null,
            'ends_on' => $e['ends_on'] ?? null,
            'status' => $e['status'],
            'status_label' => self::LABELS[$e['status']] ?? $e['status'],
            'notes' => $e['notes'] ?? null,
            'opened_at' => $e['opened_at'] ?? null,
            'closed_at' => $e['closed_at'] ?? null,
            'location_id' => !empty($e['location_id']) ? (int) $e['location_id'] : null,
            'created_at' => $e['created_at'],
        ];
    }

    private static function allocations(int $eventId): array
    {
        $rows = Db::fetchAll(
            'SELECT a.*, i.code AS item_code, i.name AS item_name, ind.name AS industry_name
               FROM event_allocations a
               JOIN items i ON i.id = a.item_id
               JOIN industries ind ON ind.id = a.industry_id
              WHERE a.event_id = ? ORDER BY ind.name, i.name',
            [$eventId]
        );
        return array_map(fn ($r) => [
            'industry' => ['id' => (int) $r['industry_id'], 'name' => $r['industry_name']],
            'item' => ['id' => (int) $r['item_id'], 'code' => $r['item_code'], 'name' => $r['item_name']],
            'qty_allocated' => (int) $r['qty_allocated'],
            'qty_withdrawn' => (int) $r['qty_withdrawn'],
            'saldo' => (int) $r['qty_allocated'] - (int) $r['qty_withdrawn'],
        ], $rows);
    }

    private static function industryBalances(int $eventId): array
    {
        $rows = Db::fetchAll(
            'SELECT industry_id, ind.name, SUM(qty_allocated) AS allocated, SUM(qty_withdrawn) AS withdrawn
               FROM event_allocations a JOIN industries ind ON ind.id = a.industry_id
              WHERE event_id = ? GROUP BY industry_id, ind.name ORDER BY ind.name',
            [$eventId]
        );
        return array_map(fn ($r) => [
            'id' => (int) $r['industry_id'],
            'name' => $r['name'],
            'allocated' => (int) $r['allocated'],
            'withdrawn' => (int) $r['withdrawn'],
            'saldo' => (int) $r['allocated'] - (int) $r['withdrawn'],
        ], $rows);
    }

    private static function lock(int $id): array
    {
        $row = Db::fetch('SELECT * FROM events WHERE id = ? AND deleted_at IS NULL FOR UPDATE', [$id]);
        if ($row === null) {
            throw HttpException::notFound('Evento não encontrado.');
        }
        return $row;
    }

    private static function sendCloseSummaries(int $eventId): void
    {
        if (SettingsService::get('event_email_mode') !== 'consolidado') {
            NotificationService::flush();
            return;
        }
        $inds = self::industryBalances($eventId);
        $event = Db::fetch('SELECT name FROM events WHERE id = ?', [$eventId]);
        foreach ($inds as $ind) {
            $email = Db::value('SELECT contact_email FROM industries WHERE id = ?', [$ind['id']]);
            if (!$email) {
                continue;
            }
            $lines = self::industryBalance($eventId, $ind['id']);
            $rows = '';
            foreach ($lines as $l) {
                $rows .= '<tr><td>' . e($l['item']['name']) . '</td><td>' . $l['qty_withdrawn'] . '</td><td>' . $l['saldo'] . '</td></tr>';
            }
            $html = Mailer::layout(
                'Resumo do evento ' . $event['name'],
                '<p>Segue o consolidado de retiradas da indústria <b>' . e($ind['name']) . '</b>.</p>'
                . '<table><tr><th>Brinde</th><th>Retirado</th><th>Saldo</th></tr>' . $rows . '</table>'
            );
            NotificationService::queueEmail(null, (string) $email, 'Resumo do evento — ' . $event['name'], $html, null, 'event-close-' . $eventId . '-' . $ind['id'], 'event', $eventId);
        }
        NotificationService::flush();
    }
}
